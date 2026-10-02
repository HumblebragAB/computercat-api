<?php

namespace App\Services\Glosis;

use App\Models\AiUsage;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Studiorösten: glosor upplästa av ElevenLabs med två röster per språk,
 * kvinnlig och manlig (sidan Röster i Filament), genererade en gång per unikt ord, hastighet, röst och språk och sedan sparade på disken glosis-tts
 * (storage/app/glosis-tts/{sha256}.mp3). Appen får signerade adresser (24 h)
 * och spelar ljudet med en vanlig <audio src>.
 *
 * Bara ord: texten normaliseras (små bokstäver, trim, skiljetecken i slutet
 * bort) och måste bestå av bokstäver, mellanslag, apostrof och bindestreck, högst
 * 60 tecken. Annat nekas utan anrop till ElevenLabs.
 *
 * Kostnaden styrs av tre gränser för nya ord (cachade ord räknas inte): per köp
 * och Stockholmsdygn (100), globalt per dygn (settings.tts.daily_new_limit) och
 * den gemensamma månadsbudgeten (AiBudget). Räknarna ökas atomiskt före anropet
 * och återförs om anropet misslyckas. Ett lås per fil gör att samtidiga
 * förfrågningar på samma nya ord bara genererar det en gång.
 */
class StudioVoice
{
    public const DISK = 'glosis-tts';

    public const AUDIO_ROUTE = 'glosis.tts.audio';

    /** ElevenLabs voice_settings.speed. 0.7 är API:ts minimum. */
    public const SPEEDS = ['normal' => 1.0, 'slow' => 0.7];

    public const MAX_WORDS = 40;

    public const MAX_LENGTH = 60;

    public const URL_TTL_HOURS = 24;

    /** Hur länge en förfrågan väntar på en annan som genererar samma ord. */
    public int $lockWaitSeconds = 10;

    /** Efter så här lång tid genereras inga fler ord i samma förfrågan. */
    public int $timeBudgetSeconds = 25;

    /** Senaste felet från ElevenLabs i prepare(), för provlyssningen i Filament. */
    public ?TtsFailedException $lastFailure = null;

    /**
     * Transaktionen som provlyssningen i Filament räknas på: egen räknare per
     * dygn, samma globala dygnsgräns och samma månadsbudget som appen.
     * Bokförs i ai_usage med environment "Admin".
     */
    public static function adminTransaction(): VerifiedTransaction
    {
        return new VerifiedTransaction('filament-preview', 'filament-preview', 'filament-preview', 'Admin', null);
    }

    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly AiBudget $budget,
    ) {}

    /** Normaliserad text, eller null om texten inte är ett ord vi läser upp. */
    public static function normalize(string $text): ?string
    {
        if (strlen($text) > 1000 || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        // Typografiska apostrofer från tangentbord och kopierad text.
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $text);
        $text = mb_strtolower($text);
        // Bara blanksteg tas bort i kanterna (inte trim(), som även tar NUL).
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = preg_replace('/^ | \z/u', '', $text) ?? '';
        // Skiljetecken i slutet ("dog?", "cat.") tas bort i stället för att nekas.
        $text = preg_replace('/ ?[?!.,]+\z/u', '', $text) ?? '';

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match("/^[\\p{L}' -]+\\z/u", $text) !== 1 || preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * Filnamnets nyckel: sha256(normaliserad text | hastighet | röst-id | modell),
     * med språket sist för andra språk än engelska. Engelska saknar språkdelen
     * så att filerna som redan genererats behåller sina namn. Byts rösten byts
     * nyckeln, så gammalt ljud serveras aldrig med en ny röst. Kvinnlig och
     * manlig röst har olika röst-id och därmed egna filer.
     */
    public static function hash(string $normalized, string $speed, string $voiceId, string $language = ElevenLabsClient::LANGUAGE): string
    {
        $parts = [$normalized, sprintf('%.2f', self::SPEEDS[$speed]), $voiceId, ElevenLabsClient::MODEL];
        if ($language !== ElevenLabsClient::LANGUAGE) {
            $parts[] = $language;
        }

        return hash('sha256', implode('|', $parts));
    }

    public static function fileName(string $hash): string
    {
        return $hash.'.mp3';
    }

    /**
     * Absolut https-adress, signerad över hela adressen (appen godtar bara
     * absoluta https-adresser). Roten tas från APP_URL, inte från förfrågans
     * Host-huvud, och schemat är alltid https. Signaturen kontrolleras av
     * middleware "signed" mot adressen förfrågan faktiskt kom på; i drift
     * terminerar nginx TLS direkt (ingen proxy), så den ser https och rätt värd.
     */
    public static function signedUrl(string $hash): string
    {
        $generator = clone app('url');
        $generator->forceRootUrl(preg_replace('#^http://#', 'https://', rtrim((string) config('app.url'), '/')));
        $generator->forceScheme('https');

        return $generator->temporarySignedRoute(self::AUDIO_ROUTE, now()->addHours(self::URL_TTL_HOURS), ['hash' => $hash]);
    }

    /**
     * I svaret är stopped 'budget' eller 'unavailable' om genereringen
     * stoppades, och voice_used rösten (female eller male) som orden lästes med.
     *
     * @param  list<string>  $words  ord som appen skickat, i originalform
     * @param  string  $language  en nyckel i GlosisSettings::LANGUAGES
     * @param  string  $gender  en nyckel i GlosisSettings::GENDERS; saknar språket den rösten används språkets andra
     * @return array{urls: array<string, string>, skipped: list<array{word: string, reason: string}>, stopped: string|null, voice_used: string}
     *
     * @throws GlosisRejection 503 tts_unavailable när språket saknar röst
     */
    public function prepare(Game $game, VerifiedTransaction $transaction, string $speed, array $words, string $language = ElevenLabsClient::LANGUAGE, string $gender = GlosisSettings::DEFAULT_GENDER): array
    {
        if (! array_key_exists($language, GlosisSettings::LANGUAGES)) {
            throw new \InvalidArgumentException("Okänt språk: {$language}");
        }
        if (! array_key_exists($gender, GlosisSettings::GENDERS)) {
            throw new \InvalidArgumentException("Okänd röst: {$gender}");
        }
        $this->lastFailure = null;
        $settings = GlosisSettings::for($game);
        $voice = $settings->studioVoice($language, $gender);
        if ($voice === null) {
            throw new GlosisRejection(503, 'tts_unavailable', 'Studiorösten fungerar inte just nu. Appen använder telefonens röst så länge.');
        }
        $voiceId = $voice['voice_id'];

        $disk = Storage::disk(self::DISK);
        $started = microtime(true);
        $apiKey = false; // hämtas först när ett nytt ord behövs
        $stopped = null;
        $limited = false;
        $stats = ['cached' => 0, 'generated' => 0];

        /** @var array<string, string> $outcome normaliserad text => hash eller orsak */
        $outcome = [];
        $urls = [];
        $skipped = [];

        foreach ($words as $original) {
            $normalized = self::normalize($original);
            if ($normalized === null) {
                $skipped[] = ['word' => $original, 'reason' => 'invalid'];

                continue;
            }

            if (! isset($outcome[$normalized])) {
                $hash = self::hash($normalized, $speed, $voiceId, $language);
                if ($disk->exists(self::fileName($hash))) {
                    $outcome[$normalized] = 'ok:'.$hash;
                    $stats['cached']++;
                } elseif ($limited) {
                    $outcome[$normalized] = 'limit';
                } elseif ($stopped !== null || microtime(true) - $started >= $this->timeBudgetSeconds) {
                    $stopped ??= 'unavailable';
                    $outcome[$normalized] = 'unavailable';
                } else {
                    if ($apiKey === false) {
                        $apiKey = $settings->elevenLabsKey();
                    }
                    if ($apiKey === null) {
                        $stopped = 'unavailable';
                        $outcome[$normalized] = 'unavailable';
                    } else {
                        $result = $this->generate($game, $transaction, $settings, $disk, $apiKey, $voiceId, $language, $speed, $normalized, $hash);
                        $outcome[$normalized] = $result;
                        if ($result === 'ok:'.$hash) {
                            $stats['generated']++;
                        } elseif ($result === 'limit') {
                            $limited = true;
                        } elseif ($result === 'budget') {
                            $stopped = 'budget';
                            $outcome[$normalized] = 'unavailable';
                        } elseif ($result === 'failed') {
                            $stopped = 'unavailable';
                            $outcome[$normalized] = 'unavailable';
                        }
                    }
                }
            }

            $result = $outcome[$normalized];
            if (str_starts_with($result, 'ok:')) {
                $urls[$original] = self::signedUrl(substr($result, 3));
            } else {
                $skipped[] = ['word' => $original, 'reason' => $result];
            }
        }

        Log::info('Glosis tts: prepare', [
            'environment' => $transaction->environment,
            'language' => $language,
            'voice' => $gender,
            'voice_used' => $voice['gender'],
            'tx' => GuldGate::txHash($transaction),
            'words' => count($words),
            'cached' => $stats['cached'],
            'generated' => $stats['generated'],
            'skipped' => count($skipped),
            'stopped' => $stopped,
            'limited' => $limited,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return ['urls' => $urls, 'skipped' => $skipped, 'stopped' => $stopped, 'voice_used' => $voice['gender']];
    }

    /**
     * Genererar ett nytt ord under lås. Returnerar 'ok:{hash}', 'limit',
     * 'budget', 'failed' eller 'unavailable' (låset gick inte att få).
     */
    private function generate(Game $game, VerifiedTransaction $transaction, GlosisSettings $settings, Filesystem $disk, string $apiKey, string $voiceId, string $language, string $speed, string $normalized, string $hash): string
    {
        $file = self::fileName($hash);

        try {
            return $this->lock($hash)->block($this->lockWaitSeconds, function () use ($game, $transaction, $settings, $disk, $apiKey, $voiceId, $language, $speed, $normalized, $hash, $file) {
                // En annan förfrågan kan ha genererat ordet medan vi väntade.
                if ($disk->exists($file)) {
                    return 'ok:'.$hash;
                }

                // Gränserna räknas först och avgörs på värdet hit() returnerar
                // (atomiskt, som skanningens veckogräns).
                $now = CarbonImmutable::now(GuldStatus::TIME_ZONE);
                $day = $now->format('Y-m-d');
                $ttl = (int) ceil($now->diffInSeconds($now->addDay()->startOfDay())) + 60;
                $txKey = 'glosis-tts:tx:'.hash('sha256', $transaction->originalTransactionId).':'.$day;
                $dayKey = 'glosis-tts:day:'.$game->id.':'.$day;
                $undoCounters = function () use ($txKey, $dayKey, $ttl) {
                    RateLimiter::decrement($txKey, $ttl);
                    RateLimiter::decrement($dayKey, $ttl);
                };

                $txHits = RateLimiter::hit($txKey, $ttl);
                $dayHits = RateLimiter::hit($dayKey, $ttl);
                if ($txHits > GlosisSettings::TTS_DAILY_NEW_PER_PURCHASE || $dayHits > $settings->ttsDailyNewLimit()) {
                    $undoCounters();
                    Log::info('Glosis tts: gränsen för nya ord nådd', [
                        'tx' => GuldGate::txHash($transaction),
                        'scope' => $txHits > GlosisSettings::TTS_DAILY_NEW_PER_PURCHASE ? 'purchase' : 'global',
                    ]);

                    return 'limit';
                }

                $characters = mb_strlen($normalized);
                $cost = AiCost::tts($characters);
                $reservation = $this->budget->reserve($game, $cost, AiUsage::FEATURE_TTS);
                if ($reservation === null) {
                    $undoCounters();

                    return 'budget';
                }

                try {
                    $audio = $this->client->synthesize($apiKey, $voiceId, $normalized, self::SPEEDS[$speed], $language);
                } catch (TtsFailedException $e) {
                    $this->lastFailure = $e;
                    $undoCounters();
                    $this->budget->release($reservation);
                    Log::warning('Glosis tts: ElevenLabs misslyckades', ['reason' => $e->getMessage()] + $e->context);

                    return 'failed';
                } catch (\Throwable $e) {
                    $undoCounters();
                    $this->budget->release($reservation);

                    throw $e;
                }

                // Anropet är debiterat: bokför innan filen skrivs.
                $this->budget->record($game, $reservation, AiUsage::FEATURE_TTS, $transaction->environment, $cost, ['characters' => $characters]);

                // Skriv till en tillfällig fil och byt namn, så att en halvskriven
                // fil aldrig serveras.
                $tmp = $file.'.'.Str::random(12).'.tmp';
                if (! $disk->put($tmp, $audio) || ! $disk->move($tmp, $file)) {
                    $disk->delete($tmp);
                    Log::error('Glosis tts: ljudfilen gick inte att spara', ['hash' => $hash]);

                    return 'failed';
                }

                return 'ok:'.$hash;
            });
        } catch (LockTimeoutException) {
            Log::warning('Glosis tts: väntade för länge på låset', ['hash' => $hash]);

            return 'unavailable';
        }
    }

    protected function lock(string $hash): Lock
    {
        return Cache::lock('glosis-tts-gen:'.$hash, $this->timeBudgetSeconds + 30);
    }
}
