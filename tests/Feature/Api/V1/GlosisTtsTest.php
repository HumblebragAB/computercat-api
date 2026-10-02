<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiUsage;
use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AppleTransactionVerifier;
use App\Services\Glosis\ElevenLabsClient;
use App\Services\Glosis\StudioVoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAppleChain;
use Tests\Support\FakeGooglePlay;
use Tests\TestCase;

class GlosisTtsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/tts/prepare';

    private const API_KEY = 'xi-test-not-a-real-key';

    private const VOICE = 'TestVoice123';

    /** En minimal MPEG-ram: synk 0xFFF, sedan nollor. */
    private const MP3 = "\xFF\xFB\x90\x64".'ljud';

    private static ?FakeAppleChain $chain = null;

    private Game $game;

    private string $product;

    /** @var list<HttpRequest> */
    private array $elevenLabs = [];

    private \Closure $elevenLabsAnswer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'elevenlabs' => ['api_key' => Crypt::encryptString(self::API_KEY), 'voice_id' => self::VOICE],
        ])]);

        self::$chain ??= new FakeAppleChain;
        $this->app->instance(AppleTransactionVerifier::class, new AppleTransactionVerifier(self::$chain->rootDer()));

        // Som i drift. Adresserna ska byggas från APP_URL, alltid https.
        config(['app.url' => 'https://api.computercat.co']);
        Storage::fake(StudioVoice::DISK);
        Http::preventStrayRequests();
        $this->elevenLabsAnswer = fn () => Http::response(self::MP3, 200, ['Content-Type' => 'audio/mpeg']);
        Http::fake(['api.elevenlabs.io/*' => function (HttpRequest $request) {
            $this->elevenLabs[] = $request;

            return ($this->elevenLabsAnswer)($request);
        }]);

        $base = CarbonImmutable::createFromTimestamp(time(), 'Europe/Stockholm')->addDay()->setTime(14, 0);
        Carbon::setTestNow($base->utc());
        $startYear = $base->month >= 7 ? $base->year : $base->year - 1;
        $this->product = sprintf('glosis_guld_%d_%02d', $startYear, ($startYear + 1) % 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function elevenLabsAnswers(\Closure $answer): void
    {
        $this->elevenLabsAnswer = $answer;
    }

    private function proof(array $payload = []): array
    {
        return ['platform' => 'ios', 'jws' => self::$chain->sign(FakeAppleChain::payload(array_merge([
            'productId' => $this->product,
            'signedDate' => time() * 1000,
        ], $payload)))];
    }

    private function prepare(array $words, string $speed = 'normal', ?array $proof = null, array $headers = []): TestResponse
    {
        return $this->postJson(self::URL, ['proof' => $proof ?? $this->proof(), 'speed' => $speed, 'words' => $words], $headers);
    }

    private function hashFor(string $normalized, string $speed = 'normal'): string
    {
        return StudioVoice::hash($normalized, $speed, self::VOICE);
    }

    private function cache(string $normalized, string $speed = 'normal'): void
    {
        Storage::disk(StudioVoice::DISK)->put($this->hashFor($normalized, $speed).'.mp3', self::MP3);
    }

    private function setSettings(array $settings): void
    {
        $this->game->refresh();
        $this->game->update(['settings' => array_replace_recursive($this->game->settings ?? [], $settings)]);
    }

    // ---- 200 -----------------------------------------------------------

    public function test_request_to_elevenlabs_follows_the_documented_contract(): void
    {
        $this->prepare(['Dog'])->assertOk();

        $this->assertCount(1, $this->elevenLabs);
        $request = $this->elevenLabs[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/'.self::VOICE.'?output_format=mp3_44100_64', $request->url());
        $this->assertSame(self::API_KEY, $request->header('xi-api-key')[0]);
        $this->assertSame([
            'text' => 'dog',
            'model_id' => 'eleven_flash_v2_5',
            'language_code' => 'en',
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75, 'speed' => 1.0],
        ], $request->data());
    }

    // ---- Röst per språk ------------------------------------------------

    public function test_language_picks_that_languages_voice_and_language_code(): void
    {
        $this->setSettings(['elevenlabs' => ['voices' => [
            'en' => ['voice_id' => 'EnglishVoice1', 'name' => 'Eldrin'],
            'de' => ['voice_id' => 'GermanVoice1', 'name' => 'Greta'],
        ]]]);

        $url = $this->postJson(self::URL, ['proof' => $this->proof(), 'speed' => 'normal', 'words' => ['Hund'], 'language' => 'de'])
            ->assertOk()->json('urls.Hund');

        $this->assertCount(1, $this->elevenLabs);
        $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/GermanVoice1?output_format=mp3_44100_64', $this->elevenLabs[0]->url());
        $this->assertSame('de', $this->elevenLabs[0]->data()['language_code']);
        $this->assertSame('hund', $this->elevenLabs[0]->data()['text']);
        $this->assertStringContainsString(StudioVoice::hash('hund', 'normal', 'GermanVoice1', 'de'), $url);

        // Utan language: engelska och den engelska rösten (inte det äldre fältet).
        $this->prepare(['dog'])->assertOk();
        $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/EnglishVoice1?output_format=mp3_44100_64', $this->elevenLabs[1]->url());
        $this->assertSame('en', $this->elevenLabs[1]->data()['language_code']);
    }

    public function test_legacy_voice_id_is_the_fallback_for_english_only(): void
    {
        // Bara det äldre settings.elevenlabs.voice_id (som i setUp).
        $this->prepare(['dog'])->assertOk();
        $this->assertStringContainsString('/text-to-speech/'.self::VOICE.'?', $this->elevenLabs[0]->url());

        // Inget språk utom engelska faller tillbaka på det.
        foreach (['de', 'es', 'fr'] as $language) {
            $this->postJson(self::URL, ['proof' => $this->proof(), 'speed' => 'normal', 'words' => ['dog'], 'language' => $language])
                ->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        }
        $this->assertCount(1, $this->elevenLabs);
    }

    public function test_changing_voice_never_serves_audio_from_the_old_voice(): void
    {
        $this->cache('dog');
        $this->prepare(['dog'])->assertOk();
        $this->assertCount(0, $this->elevenLabs, 'Med samma röst används den sparade filen');

        $this->setSettings(['elevenlabs' => ['voices' => ['en' => ['voice_id' => 'NewVoice42', 'name' => 'Ny']]]]);
        $url = $this->prepare(['dog'])->assertOk()->json('urls.dog');

        $this->assertCount(1, $this->elevenLabs, 'Ny röst genererar ordet på nytt');
        $this->assertStringContainsString('/text-to-speech/NewVoice42?', $this->elevenLabs[0]->url());
        $newHash = StudioVoice::hash('dog', 'normal', 'NewVoice42');
        $this->assertNotSame($this->hashFor('dog'), $newHash);
        $this->assertStringContainsString($newHash, $url);
        $this->assertStringNotContainsString($this->hashFor('dog'), $url);
    }

    public function test_hash_includes_voice_and_language_but_english_keeps_its_old_name(): void
    {
        // Samma formel som före språkfältet, så att redan genererade engelska filer används.
        $this->assertSame(
            hash('sha256', 'dog|1.00|'.self::VOICE.'|eleven_flash_v2_5'),
            StudioVoice::hash('dog', 'normal', self::VOICE),
        );
        $this->assertSame(StudioVoice::hash('dog', 'normal', self::VOICE), StudioVoice::hash('dog', 'normal', self::VOICE, 'en'));
        $this->assertNotSame(StudioVoice::hash('dog', 'normal', self::VOICE), StudioVoice::hash('dog', 'normal', 'OtherVoice'));
        $this->assertNotSame(StudioVoice::hash('dog', 'normal', self::VOICE), StudioVoice::hash('dog', 'normal', self::VOICE, 'de'));
        $this->assertNotSame(StudioVoice::hash('dog', 'normal', self::VOICE, 'de'), StudioVoice::hash('dog', 'normal', self::VOICE, 'fr'));
    }

    public function test_slow_speed_uses_0_7_and_its_own_file(): void
    {
        $this->cache('dog', 'normal');

        $url = $this->prepare(['dog'], 'slow')->assertOk()->json('urls.dog');

        $this->assertCount(1, $this->elevenLabs);
        $this->assertSame(0.7, $this->elevenLabs[0]->data()['voice_settings']['speed']);
        $this->assertStringContainsString($this->hashFor('dog', 'slow'), $url);
        $this->assertNotSame($this->hashFor('dog', 'slow'), $this->hashFor('dog', 'normal'));
    }

    public function test_generated_word_is_stored_recorded_and_playable(): void
    {
        $url = $this->prepare(['Dog'])->assertOk()->assertJsonPath('skipped', [])->json('urls.Dog');

        $hash = $this->hashFor('dog');
        Storage::disk(StudioVoice::DISK)->assertExists($hash.'.mp3');
        $this->assertSame([$hash.'.mp3'], Storage::disk(StudioVoice::DISK)->allFiles(), 'Inga tillfälliga filer ska ligga kvar');

        $usage = AiUsage::sole();
        $this->assertSame('tts', $usage->feature);
        $this->assertSame('Sandbox', $usage->environment);
        $this->assertSame(3, $usage->characters);
        $this->assertSame('0.000120', $usage->est_cost_usd); // 3 tecken × $0.04/1000
        $this->assertSame(120, (int) Cache::get(AiBudget::monthKey($this->game, now())));

        // Absolut https-adress från APP_URL, signerad.
        $this->assertStringStartsWith('https://api.computercat.co/api/v1/games/glosis/tts/audio/'.$hash.'?expires=', $url);
        $this->assertStringContainsString('&signature=', $url);

        $audio = $this->get($url);
        $audio->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame(self::MP3, file_get_contents($audio->baseResponse->getFile()->getPathname()));
        $cacheControl = $audio->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_audio_supports_byte_ranges_for_ios_media_playback(): void
    {
        $this->cache('dog');
        $url = $this->prepare(['dog'])->json('urls.dog');

        $this->get($url, ['Range' => 'bytes=0-1'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-1/'.strlen(self::MP3));
    }

    public function test_cached_word_is_served_without_calling_elevenlabs_or_counting(): void
    {
        $this->cache('dog');

        $this->prepare(['dog'])->assertOk()->assertJsonStructure(['urls' => ['dog'], 'skipped']);

        $this->assertCount(0, $this->elevenLabs);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_urls_are_keyed_by_the_word_as_sent_and_duplicates_generate_once(): void
    {
        $response = $this->prepare(['Dog', 'dog ', 'DOG?', 'ice-cream', 'don’t'])->assertOk();

        $urls = $response->json('urls');
        $this->assertSame(['Dog', 'dog ', 'DOG?', 'ice-cream', 'don’t'], array_keys($urls));
        $this->assertSame($urls['Dog'], $urls['dog ']);
        $this->assertSame($urls['Dog'], $urls['DOG?']);
        $this->assertSame(['dog', 'ice-cream', "don't"], array_map(fn (HttpRequest $r) => $r->data()['text'], $this->elevenLabs));
    }

    public function test_empty_result_is_an_object_not_a_list(): void
    {
        $this->prepare(['http://x.se'])->assertOk();
        $this->assertSame('{"urls":{},"skipped":[{"word":"http:\/\/x.se","reason":"invalid"}]}', $this->prepare(['http://x.se'])->getContent());
    }

    // ---- Normalisering -------------------------------------------------

    public static function normalized(): array
    {
        return [
            'gemener och trim' => ['  Dog  ', 'dog'],
            'frågetecken i slutet' => ['dog?', 'dog'],
            'flera skiljetecken' => ['Hello!!.', 'hello'],
            'mellanslag inuti' => ['ice   cream', 'ice cream'],
            'bindestreck' => ['T-shirt', 't-shirt'],
            'typografisk apostrof' => ['don’t', "don't"],
            'svenska bokstäver' => ['Smörgås', 'smörgås'],
            'exakt 60 tecken' => [str_repeat('a', 60), str_repeat('a', 60)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('normalized')]
    public function test_normalization(string $input, string $expected): void
    {
        $this->assertSame($expected, StudioVoice::normalize($input));
    }

    public static function notWords(): array
    {
        return [
            'tom' => [''],
            'bara mellanslag' => ['   '],
            'bara skiljetecken' => ['?!'],
            'webbadress' => ['https://example.com'],
            'mejladress' => ['a@b.se'],
            'siffror' => ['dog2'],
            'emoji' => ['dog 🐶'],
            'html' => ['<b>dog</b>'],
            '61 tecken' => [str_repeat('a', 61)],
            '500 tecken' => [str_repeat('ab ', 166)],
            'mening med kommatecken inuti' => ['hello, my name is'],
            'bara apostrof' => ["'"],
            'radbrytning' => ["dog\ncat\n<script>"],
            'ogiltig UTF-8' => ["do\xC3g"],
            'kontrolltecken' => ["dog\x00"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notWords')]
    public function test_text_that_is_not_a_word_is_rejected_without_calling_elevenlabs(string $text): void
    {
        $this->assertNull(StudioVoice::normalize($text));

        if (mb_check_encoding($text, 'UTF-8')) {
            $this->prepare([$text])->assertOk()->assertExactJson(['urls' => [], 'skipped' => [['word' => $text, 'reason' => 'invalid']]]);
        }
        $this->assertCount(0, $this->elevenLabs);
        $this->assertSame(0, AiUsage::count());
    }

    // ---- 402 / 422 / 501 -----------------------------------------------

    public function test_without_active_guld_returns_402(): void
    {
        $this->prepare(['dog'], proof: $this->proof(['productId' => 'glosis_guld_2025_26']))
            ->assertStatus(402)
            ->assertJsonPath('error', 'guld_required')
            ->assertJsonStructure(['error', 'message']);
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_refunded_purchase_returns_402_even_for_cached_words(): void
    {
        $this->cache('dog');
        RevokedStoreTransaction::create([
            'game_id' => $this->game->id, 'store' => 'apple', 'original_transaction_id' => '2000000111111111',
            'transaction_id' => '2000000111111111', 'reason' => 'CUSTOMER_SUPPORT', 'revoked_at' => now(),
        ]);

        $this->prepare(['dog'])->assertStatus(402)->assertJsonPath('error', 'guld_required');
    }

    public static function badProofs(): array
    {
        return [
            'saknas' => [null],
            'sträng' => ['{"platform":"ios","jws":"x"}'],
            'lista' => [[]],
            'okänd plattform' => [['platform' => 'web', 'jws' => 'x']],
            'ios utan jws' => [['platform' => 'ios']],
            'trasig jws' => [['platform' => 'ios', 'jws' => 'a.b.c']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badProofs')]
    public function test_invalid_proof_returns_422(mixed $proof): void
    {
        $this->postJson(self::URL, ['proof' => $proof, 'speed' => 'normal', 'words' => ['dog']])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_proof')->assertJsonStructure(['error', 'message']);
    }

    public static function badRequests(): array
    {
        return [
            'okänd hastighet' => [['speed' => 'fast', 'words' => ['dog']]],
            'hastighet saknas' => [['words' => ['dog']]],
            'inga ord' => [['speed' => 'normal', 'words' => []]],
            'ord saknas' => [['speed' => 'normal']],
            'ord som sträng' => [['speed' => 'normal', 'words' => 'dog']],
            'ord som objekt' => [['speed' => 'normal', 'words' => ['a' => 'dog']]],
            'ord som inte är sträng' => [['speed' => 'normal', 'words' => ['dog', 5]]],
            'nästlad lista' => [['speed' => 'normal', 'words' => [['dog']]]],
            '41 ord' => [['speed' => 'normal', 'words' => array_fill(0, 41, 'dog')]],
            'okänt språk' => [['speed' => 'normal', 'words' => ['dog'], 'language' => 'sv']],
            'språk med stor bokstav' => [['speed' => 'normal', 'words' => ['dog'], 'language' => 'EN']],
            'språk som lista' => [['speed' => 'normal', 'words' => ['dog'], 'language' => ['en']]],
            'språk null' => [['speed' => 'normal', 'words' => ['dog'], 'language' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function test_invalid_request_returns_422(array $body): void
    {
        $this->postJson(self::URL, ['proof' => $this->proof()] + $body)
            ->assertStatus(422)->assertJsonPath('error', 'invalid_request')->assertJsonStructure(['error', 'message']);
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_forty_words_are_accepted(): void
    {
        $words = array_map(fn ($i) => 'word'.str_repeat('a', $i), range(1, 40));

        $this->assertCount(40, $this->prepare($words)->assertOk()->json('urls'));
    }

    private function androidProof(): array
    {
        return ['platform' => 'android', 'productId' => $this->product, 'purchaseToken' => FakeGooglePlay::PURCHASE_TOKEN];
    }

    private function enableGooglePlay(): FakeGooglePlay
    {
        $this->setSettings(['google_play' => ['service_account_json' => Crypt::encryptString(FakeGooglePlay::serviceAccountJson())]]);
        $google = new FakeGooglePlay;
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase($this->product));
        $google->fake();

        return $google;
    }

    public function test_android_without_google_play_settings_returns_501(): void
    {
        $this->prepare(['dog'], proof: $this->androidProof())
            ->assertStatus(501)->assertJsonPath('error', 'platform_not_supported')->assertJsonStructure(['error', 'message']);
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_android_purchase_gets_studio_voice(): void
    {
        $google = $this->enableGooglePlay();

        $this->prepare(['dog'], proof: $this->androidProof())->assertOk()->assertJsonStructure(['urls' => ['dog']]);
        $this->assertCount(1, $google->purchaseRequests);
        $this->assertSame('Production', AiUsage::sole()->environment);
    }

    public function test_cancelled_android_purchase_returns_402(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase($this->product, ['purchaseStateContext' => ['purchaseState' => 'CANCELLED']]));

        $this->prepare(['dog'], proof: $this->androidProof())->assertStatus(402)->assertJsonPath('error', 'guld_required');
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_google_permission_error_returns_503_tts_unavailable(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(['error' => ['code' => 403]], 403);

        $this->prepare(['dog'], proof: $this->androidProof())->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_other_games_have_no_tts_route(): void
    {
        Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);

        $this->postJson('/api/v1/games/tocco/tts/prepare', ['proof' => $this->proof(), 'speed' => 'normal', 'words' => ['dog']])->assertNotFound();
    }

    // ---- Gränser -------------------------------------------------------

    private function purchaseDayKey(string $originalTransactionId = '2000000111111111'): string
    {
        return 'glosis-tts:tx:'.hash('sha256', $originalTransactionId).':'.CarbonImmutable::now('Europe/Stockholm')->format('Y-m-d');
    }

    public function test_hundred_new_words_per_purchase_and_day_but_cached_words_still_work(): void
    {
        RateLimiter::increment($this->purchaseDayKey(), 3600, 99);
        $this->cache('cat');

        $response = $this->prepare(['dog', 'horse', 'cat', 'cow'])->assertOk()
            ->assertJsonPath('skipped', [['word' => 'horse', 'reason' => 'limit'], ['word' => 'cow', 'reason' => 'limit']]);
        $this->assertSame(['dog', 'cat'], array_keys($response->json('urls')));
        $this->assertSame(['dog'], array_map(fn (HttpRequest $r) => $r->data()['text'], $this->elevenLabs));
        $this->assertSame(100, RateLimiter::attempts($this->purchaseDayKey()));

        // Ett annat köp har sin egen gräns.
        $this->prepare(['horse'], proof: $this->proof(['originalTransactionId' => '2000000999999999']))
            ->assertOk()->assertJsonPath('skipped', []);
    }

    public function test_global_daily_new_word_limit_comes_from_settings(): void
    {
        $this->setSettings(['tts' => ['daily_new_limit' => 2]]);

        $this->prepare(['dog', 'cat', 'cow'])->assertOk()
            ->assertJsonPath('skipped', [['word' => 'cow', 'reason' => 'limit']]);
        $this->prepare(['horse'], proof: $this->proof(['originalTransactionId' => '2000000999999999']))
            ->assertOk()->assertJsonPath('skipped', [['word' => 'horse', 'reason' => 'limit']]);
        $this->assertCount(2, $this->elevenLabs);

        // Nytt dygn i Stockholm.
        Carbon::setTestNow(CarbonImmutable::now('Europe/Stockholm')->addDay()->startOfDay()->utc());
        $this->prepare(['horse'], proof: $this->proof(['originalTransactionId' => '2000000999999999']))
            ->assertOk()->assertJsonPath('skipped', []);
    }

    public function test_exhausted_budget_returns_503_when_nothing_can_be_served(): void
    {
        $this->setSettings(['ai' => ['monthly_budget_usd' => '0.0001']]); // 100 µ$; "dog" kostar 120
        $logged = $this->captureLogs();

        $this->prepare(['dog', 'cat'])->assertStatus(503)->assertExactJson([
            'error' => 'budget_exhausted',
            'message' => 'Studiorösten har tagit paus för den här månaden. Appen använder telefonens röst så länge.',
        ]);
        $this->assertCount(0, $this->elevenLabs);
        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => str_contains($m->message, 'månadsbudgeten')));
        // Räknarna för nya ord återförs.
        $this->assertSame(0, RateLimiter::attempts($this->purchaseDayKey()));

        // Cachade ord kostar inget och serveras ändå.
        $this->cache('cat');
        $this->prepare(['dog', 'cat'])->assertOk()
            ->assertJsonPath('skipped', [['word' => 'dog', 'reason' => 'unavailable']])
            ->assertJsonStructure(['urls' => ['cat']]);
    }

    public function test_budget_is_shared_with_the_photo_scan(): void
    {
        $this->setSettings(['ai' => ['monthly_budget_usd' => 1]]);
        AiUsage::create(['game_id' => $this->game->id, 'feature' => 'scan', 'environment' => 'Production', 'est_cost_usd' => '0.999900']);

        $this->prepare(['dog'])->assertStatus(503)->assertJsonPath('error', 'budget_exhausted');
        $this->prepare(['ox'])->assertOk(); // 2 tecken = 80 µ$ ryms
    }

    public static function elevenLabsFailures(): array
    {
        return [
            '500' => [fn () => Http::response(['detail' => ['status' => 'internal']], 500)],
            '401 fel nyckel' => [fn () => Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401)],
            'kvoten slut' => [fn () => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'This request exceeds your quota']], 401)],
            '422' => [fn () => Http::response(['detail' => [['loc' => ['body', 'text'], 'msg' => 'field required']]], 422)],
            'json i stället för ljud' => [fn () => Http::response(['ok' => true], 200)],
            'tom kropp' => [fn () => Http::response('', 200, ['Content-Type' => 'audio/mpeg'])],
            'nätverksfel' => [fn () => throw new ConnectionException('cURL error 28: timeout')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('elevenLabsFailures')]
    public function test_elevenlabs_failure_returns_503_and_releases_counters_and_budget(\Closure $failure): void
    {
        $this->elevenLabsAnswers($failure);
        $logged = $this->captureLogs();

        $this->prepare(['dog', 'cat', 'cow'])->assertStatus(503)
            ->assertJsonPath('error', 'tts_unavailable')->assertJsonStructure(['error', 'message']);

        // Stoppar efter första felet i stället för att hamra på ElevenLabs.
        $this->assertCount(1, $this->elevenLabs);
        $this->assertSame(0, AiUsage::count());
        $this->assertSame(0, RateLimiter::attempts($this->purchaseDayKey()));
        $this->assertSame(0, (int) Cache::get(AiBudget::monthKey($this->game, now())));
        Storage::disk(StudioVoice::DISK)->assertDirectoryEmpty('/');

        $warning = collect($logged())->first(fn (MessageLogged $m) => str_contains($m->message, 'ElevenLabs misslyckades'));
        $this->assertNotNull($warning);
        $this->assertStringNotContainsString(self::API_KEY, json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())));
    }

    public function test_elevenlabs_error_is_logged_with_status_request_and_response(): void
    {
        $this->elevenLabsAnswers(fn () => Http::response(['detail' => ['status' => 'quota_exceeded']], 401));
        $logged = $this->captureLogs();

        $this->prepare(['dog'])->assertStatus(503);

        $warning = collect($logged())->first(fn (MessageLogged $m) => str_contains($m->message, 'ElevenLabs misslyckades'));
        $this->assertSame(401, $warning->context['status']);
        $this->assertSame('dog', $warning->context['request']['text']);
        $this->assertStringContainsString('quota_exceeded', $warning->context['response']);
    }

    public function test_failure_after_some_words_keeps_what_worked(): void
    {
        $calls = 0;
        $this->elevenLabsAnswers(function () use (&$calls) {
            return ++$calls === 1 ? Http::response(self::MP3, 200) : Http::response('boom', 500);
        });

        $this->prepare(['dog', 'cat', 'cow'])->assertOk()
            ->assertJsonStructure(['urls' => ['dog']])
            ->assertJsonPath('skipped', [['word' => 'cat', 'reason' => 'unavailable'], ['word' => 'cow', 'reason' => 'unavailable']]);
        $this->assertSame(1, RateLimiter::attempts($this->purchaseDayKey()));
        $this->assertSame(1, AiUsage::count());
    }

    public function test_missing_key_or_voice_returns_503_but_cached_words_need_no_key(): void
    {
        $this->cache('cat');
        $this->setSettings(['elevenlabs' => ['api_key' => null]]);

        $this->prepare(['dog'])->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        $this->prepare(['cat', 'dog'])->assertOk()->assertJsonPath('skipped', [['word' => 'dog', 'reason' => 'unavailable']]);

        $this->setSettings(['elevenlabs' => ['api_key' => 'inte-krypterad']]);
        $this->prepare(['dog'])->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');

        $this->setSettings(['elevenlabs' => ['voice_id' => null]]);
        $this->prepare(['cat'])->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        $this->assertCount(0, $this->elevenLabs);
    }

    public function test_time_budget_stops_generating_more_words(): void
    {
        $voice = $this->app->make(StudioVoice::class);
        $voice->timeBudgetSeconds = 0;
        $this->app->instance(StudioVoice::class, $voice);
        $this->cache('cat');

        $this->prepare(['cat', 'dog'])->assertOk()->assertJsonPath('skipped', [['word' => 'dog', 'reason' => 'unavailable']]);
        $this->prepare(['dog'])->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        $this->assertCount(0, $this->elevenLabs);
    }

    // ---- Lås -----------------------------------------------------------

    public function test_word_being_generated_by_another_request_is_not_generated_again(): void
    {
        // Den andra förfrågan håller låset och hinner inte bli klar.
        $other = Cache::lock('glosis-tts-gen:'.$this->hashFor('dog'), 60);
        $this->assertTrue($other->get());
        $voice = $this->app->make(StudioVoice::class);
        $voice->lockWaitSeconds = 0;
        $this->app->instance(StudioVoice::class, $voice);

        $this->prepare(['dog'])->assertStatus(503)->assertJsonPath('error', 'tts_unavailable');
        $this->assertCount(0, $this->elevenLabs);
        $this->assertSame(0, RateLimiter::attempts($this->purchaseDayKey()));
        $other->release();
    }

    public function test_word_finished_by_another_request_while_waiting_is_reused(): void
    {
        // Låset släpps först när den andra förfrågan sparat filen.
        $file = $this->hashFor('dog').'.mp3';
        $voice = new class(app(ElevenLabsClient::class), app(AiBudget::class), $file) extends StudioVoice
        {
            public function __construct(ElevenLabsClient $client, AiBudget $budget, private string $file)
            {
                parent::__construct($client, $budget);
            }

            protected function lock(string $hash): Lock
            {
                $file = $this->file;

                return new class($file) implements Lock
                {
                    public function __construct(private string $file) {}

                    public function get($callback = null)
                    {
                        return true;
                    }

                    public function block($seconds, $callback = null)
                    {
                        Storage::disk(StudioVoice::DISK)->put($this->file, "\xFF\xFBannan");

                        return $callback();
                    }

                    public function release()
                    {
                        return true;
                    }

                    public function owner()
                    {
                        return 'test';
                    }

                    public function forceRelease() {}
                };
            }
        };
        $this->app->instance(StudioVoice::class, $voice);

        $this->prepare(['dog'])->assertOk()->assertJsonPath('skipped', []);
        $this->assertCount(0, $this->elevenLabs);
        $this->assertSame(0, AiUsage::count());
        $this->assertSame("\xFF\xFBannan", Storage::disk(StudioVoice::DISK)->get($file));
    }

    // ---- Ljudadressen --------------------------------------------------

    public function test_audio_requires_a_valid_unexpired_signature(): void
    {
        $this->cache('dog');
        $url = $this->prepare(['dog'])->json('urls.dog');
        $path = parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        // Signaturen gäller bara sin egen fil.
        $this->cache('cat');
        $this->get(str_replace($this->hashFor('dog'), $this->hashFor('cat'), $url))->assertForbidden();

        $this->get($url)->assertOk();
        Carbon::setTestNow(now()->addHours(24)->addSecond());
        $this->get($url)->assertForbidden();
    }

    public function test_urls_use_app_url_and_https_even_if_request_host_or_scheme_differ(): void
    {
        $this->cache('dog');

        // Förfrågan kommer på http och med ett annat Host-huvud.
        $url = $this->prepare(['dog'], headers: ['Host' => 'evil.example'])->assertOk()->json('urls.dog');
        $this->assertStringStartsWith('https://api.computercat.co/', $url);

        config(['app.url' => 'http://api.computercat.co/']);
        $this->assertStringStartsWith('https://api.computercat.co/api/v1/', $this->prepare(['dog'])->json('urls.dog'));
    }

    public function test_signature_covers_host_and_scheme(): void
    {
        $this->cache('dog');
        $url = $this->prepare(['dog'])->json('urls.dog');

        $this->get($url)->assertOk();
        $this->get(str_replace('https://api.computercat.co', 'https://evil.example', $url))->assertForbidden();
        $this->get(str_replace('https://', 'http://', $url))->assertForbidden();
    }

    public function test_audio_for_missing_file_is_404(): void
    {
        $hash = $this->hashFor('dog');
        $this->get(StudioVoice::signedUrl($hash))->assertNotFound();
        $this->get('/api/v1/games/glosis/tts/audio/../../.env')->assertNotFound();
        $this->get('/api/v1/games/glosis/tts/audio/'.strtoupper($hash))->assertNotFound();
    }

    public function test_audio_is_not_held_back_by_the_global_api_limit(): void
    {
        $this->cache('dog');
        $url = $this->prepare(['dog'])->json('urls.dog');

        for ($i = 0; $i < 70; $i++) {
            $this->get($url)->assertOk();
        }
    }

    // ---- 429, CSRF, CORS, loggar ---------------------------------------

    public function test_thirty_prepare_requests_per_minute_and_ip(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(self::URL, ['proof' => 'x'])->assertStatus(422);
        }

        $this->postJson(self::URL, ['proof' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertJsonStructure(['error', 'message', 'retry_after']);
        $this->postJson(self::URL, ['proof' => 'x'], ['REMOTE_ADDR' => '10.0.0.2'])->assertStatus(422);
    }

    public function test_prepare_route_is_exempt_from_csrf(): void
    {
        $csrf = new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $request = \Illuminate\Http\Request::create(self::URL, 'POST');
        $request->setLaravelSession(app('session.store'));

        $this->assertSame('ok', $csrf->handle($request, fn () => response('ok'))->getContent());
    }

    public static function capacitorOrigins(): array
    {
        return [['capacitor://localhost'], ['https://localhost']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('capacitorOrigins')]
    public function test_cors_for_capacitor_origins(string $origin): void
    {
        $this->call('OPTIONS', self::URL, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertHeader('Access-Control-Allow-Origin', $origin);

        $this->prepare(['dog'], headers: ['Origin' => $origin, 'Referer' => $origin.'/'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_logs_never_contain_proof_purchase_id_or_key(): void
    {
        $logged = $this->captureLogs();
        $proof = $this->proof();

        $this->prepare(['dog'], proof: $proof)->assertOk();

        $done = collect($logged())->first(fn (MessageLogged $m) => str_contains($m->message, 'Glosis tts: prepare'));
        $this->assertSame(1, $done->context['generated']);
        $all = json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged()));
        $this->assertStringNotContainsString(explode('.', $proof['jws'])[2], $all);
        $this->assertStringNotContainsString('2000000111111111', $all);
        $this->assertStringNotContainsString(self::API_KEY, $all);
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m;
        });

        return function () use (&$logged) {
            return $logged;
        };
    }
}
