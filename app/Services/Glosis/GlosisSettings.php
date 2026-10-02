<?php

namespace App\Services\Glosis;

use App\Models\Game;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Glosis gränser och nycklar ur games.settings, redigerbara i Filament
 * (GameResource, sektionen "Glosis: AI-kostnader"). Saknat värde ger
 * standardvärdet; ett ogiltigt värde ger standardvärdet och en varning i loggen.
 */
final class GlosisSettings
{
    public const DEFAULT_SCAN_WEEKLY_LIMIT = 15;

    public const DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT = 5;

    public const DEFAULT_MONTHLY_BUDGET_USD = 50;

    public const DEFAULT_TTS_DAILY_NEW_LIMIT = 1000;

    /** Nya ord per köp och Stockholmsdygn. Cachade ord räknas inte. */
    public const TTS_DAILY_NEW_PER_PURCHASE = 100;

    /** Glosis paketnamn på Google Play (applicationId). */
    public const DEFAULT_GOOGLE_PLAY_PACKAGE = 'se.computercat.glosis';

    /** Språk som studiorösten kan ha en egen röst för (ISO 639-1 => namn i Filament). */
    public const LANGUAGES = ['en' => 'Engelska', 'de' => 'Tyska', 'es' => 'Spanska', 'fr' => 'Franska'];

    /** Två studioröster per språk (värdet i API:ts "voice" => namn i Filament). */
    public const GENDERS = ['female' => 'Kvinnlig röst', 'male' => 'Manlig röst'];

    public const DEFAULT_GENDER = 'female';

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings, private readonly string $slug = 'glosis') {}

    public static function for(Game $game): self
    {
        return new self($game->settings ?? [], $game->slug);
    }

    /** Skanningar per köp och ISO-vecka i Stockholm. */
    public function scanWeeklyLimit(string $environment): int
    {
        return $environment === 'Production'
            ? (int) $this->number('scan.weekly_limit', self::DEFAULT_SCAN_WEEKLY_LIMIT)
            : (int) $this->number('scan.sandbox_weekly_limit', self::DEFAULT_SCAN_SANDBOX_WEEKLY_LIMIT);
    }

    /** Månadsbudget för alla betalda AI-anrop, i miljondels dollar. */
    public function monthlyBudgetMicroUsd(): int
    {
        return (int) round($this->number('ai.monthly_budget_usd', self::DEFAULT_MONTHLY_BUDGET_USD) * 1_000_000);
    }

    public function ttsDailyNewLimit(): int
    {
        return (int) $this->number('tts.daily_new_limit', self::DEFAULT_TTS_DAILY_NEW_LIMIT);
    }

    public function anthropicKey(): ?string
    {
        return $this->secret('anthropic.api_key', 'Anthropic-nyckel');
    }

    public function elevenLabsKey(): ?string
    {
        return $this->secret('elevenlabs.api_key', 'ElevenLabs-nyckel');
    }

    /**
     * Tjänstekontot som frågar Google Play Developer API om köp:
     * settings.google_play.service_account_json, krypterat. Null (med fel i
     * loggen) om det saknas eller inte går att läsa.
     *
     * @return array{client_email: string, private_key: string, private_key_id: string|null}|null
     */
    public function googlePlayServiceAccount(): ?array
    {
        $json = $this->secret('google_play.service_account_json', 'nyckel för Google Play-tjänstekontot');
        if ($json === null) {
            return null;
        }
        $account = self::parseServiceAccountJson($json);
        if ($account === null) {
            Log::error("Glosis: Google Play-tjänstekontot för {$this->slug} har fel form (ska vara JSON-nyckeln för ett service account)");
        }

        return $account;
    }

    /** settings.google_play.package_name, standard se.computercat.glosis. */
    public function googlePlayPackageName(): string
    {
        $name = data_get($this->settings, 'google_play.package_name');
        if ($name === null || $name === '') {
            return self::DEFAULT_GOOGLE_PLAY_PACKAGE;
        }
        // Namnet hamnar i adressen till Google.
        if (! self::validPackageName($name)) {
            Log::warning("Glosis: ogiltigt värde i settings.google_play.package_name för {$this->slug}, använder ".self::DEFAULT_GOOGLE_PLAY_PACKAGE);

            return self::DEFAULT_GOOGLE_PLAY_PACKAGE;
        }

        return $name;
    }

    public static function validPackageName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+\z/', $name) === 1 && strlen($name) <= 255;
    }

    /**
     * Läser JSON-nyckeln som Google Cloud ger för ett tjänstekonto.
     *
     * @return array{client_email: string, private_key: string, private_key_id: string|null}|null
     */
    public static function parseServiceAccountJson(string $json): ?array
    {
        $data = json_decode($json, true, 8);
        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account') {
            return null;
        }
        $email = $data['client_email'] ?? null;
        $key = $data['private_key'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($key) || ! str_contains($key, '-----BEGIN PRIVATE KEY-----')) {
            return null;
        }
        $keyId = $data['private_key_id'] ?? null;

        return ['client_email' => $email, 'private_key' => $key, 'private_key_id' => is_string($keyId) && $keyId !== '' ? $keyId : null];
    }

    /**
     * Den aktiva studiorösten för ett språk och kön, vald på sidan Röster:
     * settings.elevenlabs.voices.{språk}.{female|male} = {voice_id, name, category}.
     * Äldre former läses fortfarande: voices.{språk} = {voice_id, name} (en röst
     * per språk) räknas som språkets kvinnliga röst, och det äldre fältet
     * settings.elevenlabs.voice_id som engelsk kvinnlig röst. Null (med fel i
     * loggen) om rösten saknas eller har fel form.
     */
    public function elevenLabsVoiceId(string $language = 'en', string $gender = self::DEFAULT_GENDER): ?string
    {
        $voice = $this->configuredVoiceId($language, $gender);
        if ($voice === null && ! $this->hasVoiceEntry($language, $gender)) {
            Log::error("Glosis: ingen ElevenLabs-röst ({$gender}) för språket {$language} i {$this->slug}");
        }

        return $voice;
    }

    /**
     * Rösten som studiorösten använder: den begärda om den finns, annars
     * språkets andra röst. Null (med fel i loggen) om språket saknar röst.
     *
     * @return array{voice_id: string, gender: 'female'|'male'}|null
     */
    public function studioVoice(string $language, string $gender = self::DEFAULT_GENDER): ?array
    {
        $other = $gender === 'female' ? 'male' : 'female';
        foreach ([$gender, $other] as $candidate) {
            $voice = $this->configuredVoiceId($language, $candidate);
            if ($voice !== null) {
                return ['voice_id' => $voice, 'gender' => $candidate];
            }
        }
        Log::error("Glosis: ingen ElevenLabs-röst för språket {$language} i {$this->slug}");

        return null;
    }

    /**
     * Rösterna som visas på sidan Röster, per språk i LANGUAGES och kön i
     * GENDERS. source säger var rösten är sparad: 'voices' (nuvarande form),
     * 'single' (en röst per språk, äldre form) eller 'legacy' (det äldre fältet
     * elevenlabs.voice_id).
     *
     * @return array<string, array<string, array{voice_id: string, name: string|null, category: string|null, source: 'voices'|'single'|'legacy'}|null>>
     */
    public function elevenLabsVoices(): array
    {
        $voices = [];
        foreach (array_keys(self::LANGUAGES) as $language) {
            foreach (array_keys(self::GENDERS) as $gender) {
                $voices[$language][$gender] = $this->voiceEntry($language, $gender);
            }
        }

        return $voices;
    }

    /**
     * Språkets sparade röster i nuvarande form, med en röst i den äldre formen
     * flyttad till female. Används när en röst sparas, så att den andra rösten
     * finns kvar.
     *
     * @return array<string, array<string, mixed>>
     */
    public function storedVoicesFor(string $language): array
    {
        $entry = data_get($this->settings, "elevenlabs.voices.{$language}");
        if (! is_array($entry)) {
            return [];
        }
        if (array_key_exists('voice_id', $entry)) {
            return is_string($entry['voice_id']) && trim($entry['voice_id']) !== ''
                ? ['female' => array_intersect_key($entry, array_flip(['voice_id', 'name', 'category']))]
                : [];
        }

        return array_filter(
            array_intersect_key($entry, self::GENDERS),
            fn ($voice) => is_array($voice) && is_string($voice['voice_id'] ?? null) && trim($voice['voice_id']) !== '',
        );
    }

    /** @return array{voice_id: string, name: string|null, category: string|null, source: 'voices'|'single'|'legacy'}|null */
    private function voiceEntry(string $language, string $gender): ?array
    {
        $entry = data_get($this->settings, "elevenlabs.voices.{$language}");
        $voice = null;
        $source = null;
        if (is_array($entry) && is_array($entry[$gender] ?? null)) {
            [$voice, $source] = [$entry[$gender], 'voices'];
        } elseif ($gender === 'female' && is_array($entry) && array_key_exists('voice_id', $entry)) {
            [$voice, $source] = [$entry, 'single'];
        }
        if ($voice !== null && is_string($voice['voice_id'] ?? null) && trim($voice['voice_id']) !== '') {
            return [
                'voice_id' => trim($voice['voice_id']),
                'name' => is_string($voice['name'] ?? null) ? $voice['name'] : null,
                'category' => is_string($voice['category'] ?? null) ? $voice['category'] : null,
                'source' => $source,
            ];
        }
        $legacy = data_get($this->settings, 'elevenlabs.voice_id');
        if ($language === 'en' && $gender === 'female' && is_string($legacy) && trim($legacy) !== '') {
            return ['voice_id' => trim($legacy), 'name' => null, 'category' => null, 'source' => 'legacy'];
        }

        return null;
    }

    private function hasVoiceEntry(string $language, string $gender): bool
    {
        return $this->voiceEntry($language, $gender) !== null;
    }

    /** Röst-id:t för platsen, eller null om det saknas eller har fel form (fel i loggen). */
    private function configuredVoiceId(string $language, string $gender): ?string
    {
        if (! array_key_exists($gender, self::GENDERS)) {
            return null;
        }
        $entry = $this->voiceEntry($language, $gender);
        if ($entry === null) {
            return null;
        }
        // Röst-id:t hamnar i adressen till ElevenLabs.
        if (! self::validVoiceId($entry['voice_id'])) {
            $path = match ($entry['source']) {
                'voices' => "elevenlabs.voices.{$language}.{$gender}.voice_id",
                'single' => "elevenlabs.voices.{$language}.voice_id",
                'legacy' => 'elevenlabs.voice_id',
            };
            Log::error("Glosis: settings.{$path} för {$this->slug} har fel form");

            return null;
        }

        return $entry['voice_id'];
    }

    public static function validVoiceId(mixed $voiceId): bool
    {
        return is_string($voiceId) && preg_match('/^[A-Za-z0-9]{1,64}\z/', $voiceId) === 1;
    }

    private function number(string $path, int|float $default): int|float
    {
        $value = data_get($this->settings, $path);
        if ($value === null || $value === '') {
            return $default;
        }
        if (! is_numeric($value) || $value < 0) {
            Log::warning("Glosis: ogiltigt värde i settings.{$path} för {$this->slug}, använder {$default}");

            return $default;
        }

        return $value + 0;
    }

    private function secret(string $path, string $name): ?string
    {
        $encrypted = data_get($this->settings, $path);
        if (! is_string($encrypted) || $encrypted === '') {
            Log::error("Glosis: ingen {$name} för {$this->slug}");

            return null;
        }

        try {
            $key = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            Log::error("Glosis: {$name} för {$this->slug} går inte att dekryptera");

            return null;
        }

        return $key !== '' ? $key : null;
    }
}
