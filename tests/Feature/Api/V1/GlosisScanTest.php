<?php

namespace Tests\Feature\Api\V1;

use Anthropic\Core\Exceptions\APIConnectionException;
use App\Http\Controllers\Api\V1\GlosisScanController;
use App\Models\AiUsage;
use App\Models\Game;
use App\Models\RevokedStoreTransaction;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AiCost;
use App\Services\Glosis\AppleTransactionVerifier;
use App\Services\Glosis\GooglePurchaseVerifier;
use App\Services\Glosis\GuldStatus;
use App\Services\Glosis\HomeworkScanner;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
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
use Tests\Support\FakeClaudeTransport;
use Tests\Support\FakeGooglePlay;
use Tests\TestCase;

class GlosisScanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/scan';

    private const API_KEY = 'sk-ant-test-not-a-real-key';

    private static ?FakeAppleChain $chain = null;

    private FakeClaudeTransport $claude;

    private Game $game;

    /** Guld-läsåret som gäller vid testets "nu". */
    private string $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Glosis skapas av migrationen 2026_09_30_100002_add_glosis_game.
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'anthropic' => ['api_key' => Crypt::encryptString(self::API_KEY)],
        ])]);

        self::$chain ??= new FakeAppleChain;
        $this->app->instance(AppleTransactionVerifier::class, new AppleTransactionVerifier(self::$chain->rootDer()));

        $this->claude = new FakeClaudeTransport;
        $this->app->instance(HomeworkScanner::class, new HomeworkScanner($this->claude));

        // Testets klocka: i morgon 14:00 i Stockholm, räknat från den riktiga
        // klockan. Certifikaten i FakeAppleChain gäller från det riktiga nu och
        // signedDate sätts till riktig tid, så testet håller oavsett när det körs.
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

    private function iosProof(array $payload = []): string
    {
        return json_encode(['platform' => 'ios', 'jws' => self::$chain->sign(FakeAppleChain::payload(array_merge([
            'productId' => $this->product,
            'signedDate' => time() * 1000,
        ], $payload)))]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('laxa.jpg', 800, 600);
    }

    private function scan(?string $proof = null, ?UploadedFile $image = null, array $server = []): TestResponse
    {
        return $this->call('POST', self::URL, ['proof' => $proof ?? $this->iosProof()], [], ['image' => $image ?? $this->image()], array_merge(['HTTP_ACCEPT' => 'application/json'], $server));
    }

    private function goodWords(): array
    {
        return ['title' => 'Vecka 40', 'words' => [
            ['sv' => 'hund', 'en' => 'dog', 'guessed' => false],
            ['sv' => ' katt ', 'en' => 'cat ', 'guessed' => true],
        ]];
    }

    // ---- 200 -----------------------------------------------------------

    public function test_valid_guld_proof_and_image_returns_words(): void
    {
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan()->assertOk()->assertExactJson([
            'title' => 'Vecka 40',
            'words' => [
                ['sv' => 'hund', 'en' => 'dog', 'guessed' => false],
                ['sv' => 'katt', 'en' => 'cat', 'guessed' => true],
            ],
        ]);
    }

    public function test_request_to_claude_follows_the_skill_contract(): void
    {
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));
        $image = $this->image();
        $bytes = file_get_contents($image->getRealPath());

        $this->scan(image: $image)->assertOk();

        $request = $this->claude->requests[0];
        $this->assertSame('https://api.anthropic.com/v1/messages?beta=true', (string) $request->getUri());
        $this->assertSame(self::API_KEY, $request->getHeaderLine('x-api-key'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));

        $body = $this->claude->lastBody();
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(4000, $body['max_tokens']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(HomeworkScanner::SCHEMA, $body['output_config']['format']['schema']);
        $this->assertArrayNotHasKey('thinking', $body);

        $content = $body['messages'][0]['content'];
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame('image', $content[0]['type'], 'Bilden ska komma före texten');
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)], $content[0]['source']);
        $this->assertSame(['type' => 'text', 'text' => HomeworkScanner::PROMPT], $content[1]);
    }

    public function test_prompt_is_verbatim_from_glosis(): void
    {
        $this->assertStringStartsWith('Bilden visar en glosläxa (svenska och engelska ord) för ett barn i årskurs 3–5 i Sverige.', HomeworkScanner::PROMPT);
        $this->assertStringEndsWith('Om bilden inte innehåller några glosor, svara {"title":"","words":[]}.', HomeworkScanner::PROMPT);
        $this->assertSame(9, substr_count(HomeworkScanner::PROMPT, "\n") + 1);
    }

    public function test_production_environment_is_accepted_and_png_and_webp_work(): void
    {
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->iosProof(['environment' => 'Production']))->assertOk();
        $this->scan(image: UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
        $this->scan(image: UploadedFile::fake()->image('a.webp', 300, 300))->assertOk();

        $this->assertSame('image/png', $this->mediaTypeOf(1));
        $this->assertSame('image/webp', $this->mediaTypeOf(2));
    }

    private function mediaTypeOf(int $i): string
    {
        return json_decode((string) $this->claude->requests[$i]->getBody(), true)['messages'][0]['content'][0]['source']['media_type'];
    }

    public function test_empty_title_defaults_and_blank_pairs_are_dropped(): void
    {
        $this->claude->push(FakeClaudeTransport::json(['title' => '  ', 'words' => [
            ['sv' => 'hund', 'en' => '', 'guessed' => false],
            ['sv' => 'häst', 'en' => 'horse', 'guessed' => false],
        ]]));

        $this->scan()->assertOk()->assertExactJson(['title' => 'Ny lista', 'words' => [['sv' => 'häst', 'en' => 'horse', 'guessed' => false]]]);
    }

    public function test_no_words_is_a_valid_answer(): void
    {
        $this->claude->push(FakeClaudeTransport::json(['title' => '', 'words' => []]));

        $this->scan()->assertOk()->assertExactJson(['title' => 'Ny lista', 'words' => []]);
    }

    public function test_long_title_is_shortened(): void
    {
        $this->claude->push(FakeClaudeTransport::json(['title' => str_repeat('å', 70), 'words' => []]));

        $this->assertSame(str_repeat('å', 60), $this->scan()->assertOk()->json('title'));
    }

    public function test_answer_after_server_side_fallback_is_used(): void
    {
        // Med fallbacks: "default" kommer reservmodellens svar efter ett fallback-block.
        $this->claude->push(FakeClaudeTransport::message([
            ['type' => 'fallback', 'from' => ['model' => 'claude-sonnet-5-5'], 'to' => ['model' => 'claude-sonnet-5'], 'trigger' => ['type' => 'refusal', 'category' => 'cyber']],
            ['type' => 'text', 'text' => json_encode($this->goodWords())],
        ]));

        $this->scan()->assertOk()->assertJsonPath('words.0.en', 'dog');
    }

    // ---- 402 -----------------------------------------------------------

    public static function notGuld(): array
    {
        return [
            'utgånget läsår' => [['productId' => 'glosis_guld_2025_26']],
            'fas 1-produkt' => [['productId' => 'glosis_guldstjarnan_lasar']],
            'åren hänger inte ihop' => [['productId' => 'glosis_guld_2026_28']],
            'återbetalt' => [['revocationDate' => 1_759_000_000_000, 'revocationReason' => 1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notGuld')]
    public function test_valid_proof_without_active_guld_returns_402(array $payload): void
    {
        $this->scan($this->iosProof($payload))
            ->assertStatus(402)
            ->assertJsonPath('error', 'guld_required')
            ->assertJsonStructure(['error', 'message']);

        $this->assertCount(0, $this->claude->requests);
    }

    public function test_guld_expires_after_june_30_stockholm(): void
    {
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));

        $until = GuldStatus::until($this->product);
        Carbon::setTestNow($until); // 30 juni 23:59:59 i Stockholm
        $this->scan()->assertOk();

        Carbon::setTestNow($until->addSecond()); // midnatt 1 juli i Stockholm
        $this->scan()->assertStatus(402)->assertJsonPath('error', 'guld_required');
    }

    // ---- 422 -----------------------------------------------------------

    public static function badProofs(): array
    {
        return [
            'saknas' => [null],
            'inte JSON' => ['inte json'],
            'lista' => ['[]'],
            'okänd plattform' => ['{"platform":"web","jws":"x"}'],
            'ios utan jws' => ['{"platform":"ios"}'],
            'ios jws inte sträng' => ['{"platform":"ios","jws":123}'],
            'ios trasig jws' => ['{"platform":"ios","jws":"a.b.c"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badProofs')]
    public function test_invalid_proof_returns_422(?string $proof): void
    {
        $params = $proof === null ? [] : ['proof' => $proof];

        $this->call('POST', self::URL, $params, [], ['image' => $this->image()], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_proof');
    }

    public function test_proof_from_untrusted_chain_returns_422(): void
    {
        $other = new FakeAppleChain;
        $proof = json_encode(['platform' => 'ios', 'jws' => $other->sign(FakeAppleChain::payload(['productId' => $this->product, 'signedDate' => time() * 1000]))]);

        $this->scan($proof)->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
    }

    public function test_proof_for_another_app_returns_422(): void
    {
        $this->scan($this->iosProof(['bundleId' => 'se.computercat.tocco']))
            ->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
    }

    public function test_proof_as_array_returns_422(): void
    {
        $this->call('POST', self::URL, ['proof' => ['platform' => 'ios']], [], ['image' => $this->image()], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
    }

    public static function badImages(): array
    {
        return [
            'text med .jpg-ändelse' => [fn () => UploadedFile::fake()->createWithContent('laxa.jpg', 'inte en bild')],
            'gif' => [fn () => UploadedFile::fake()->image('laxa.gif', 100, 100)],
            'pdf som .png' => [fn () => UploadedFile::fake()->createWithContent('laxa.png', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n")],
            'för stor' => [fn () => UploadedFile::fake()->createWithContent('laxa.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 5 * 1024 * 1024))],
            'tom' => [fn () => UploadedFile::fake()->createWithContent('laxa.jpg', '')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badImages')]
    public function test_invalid_image_returns_422(\Closure $make): void
    {
        $this->scan(image: $make())->assertStatus(422)->assertJsonPath('error', 'invalid_image');

        $this->assertCount(0, $this->claude->requests);
    }

    public function test_missing_image_returns_422(): void
    {
        $this->call('POST', self::URL, ['proof' => $this->iosProof()], [], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_image');
    }

    public function test_image_larger_than_claude_allows_returns_422(): void
    {
        $this->scan(image: UploadedFile::fake()->image('stor.png', 8001, 10))
            ->assertStatus(422)->assertJsonPath('error', 'invalid_image');
    }

    // ---- 429 -----------------------------------------------------------

    private function secondsToNextMonday(): int
    {
        $now = CarbonImmutable::now('Europe/Stockholm');

        return $now->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->getTimestamp() - $now->getTimestamp();
    }

    public function test_fifteen_scans_per_production_purchase_and_iso_week(): void
    {
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof(['environment' => 'Production']);

        for ($i = 0; $i < 15; $i++) {
            $this->scan($proof)->assertOk();
        }

        $retry = $this->secondsToNextMonday();
        $this->scan($proof)
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertJsonPath('retry_after', $retry)
            ->assertHeader('Retry-After', (string) $retry);
        $this->assertCount(15, $this->claude->requests);

        // Ett annat köp har sin egen gräns.
        $this->scan($this->iosProof(['environment' => 'Production', 'originalTransactionId' => '2000000999999999']))->assertOk();

        // Samma vecka, ett dygn senare: fortfarande stopp (gränsen är per vecka, inte dygn).
        if (CarbonImmutable::now('Europe/Stockholm')->addDay()->isoWeek() === CarbonImmutable::now('Europe/Stockholm')->isoWeek()) {
            Carbon::setTestNow(now()->addDay());
            $this->scan($this->iosProof(['environment' => 'Production']))->assertStatus(429);
        }

        // Ny ISO-vecka i Stockholm (måndag 00:00).
        Carbon::setTestNow(CarbonImmutable::now('Europe/Stockholm')->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->utc());
        $this->scan($this->iosProof(['environment' => 'Production']))->assertOk();
    }

    public function test_sandbox_purchases_get_five_scans_per_week_by_default(): void
    {
        // Sandbox-köp (TestFlight) kostar inget och gäller hela läsåret.
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof(['environment' => 'Sandbox']);

        for ($i = 0; $i < 5; $i++) {
            $this->scan($proof)->assertOk();
        }
        $this->scan($proof)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        $this->assertCount(5, $this->claude->requests);
    }

    public function test_weekly_limits_come_from_game_settings(): void
    {
        $this->setSettings(['scan' => ['weekly_limit' => '3', 'sandbox_weekly_limit' => 1]]);
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));

        $sandbox = $this->iosProof(['environment' => 'Sandbox']);
        $this->scan($sandbox)->assertOk();
        $this->scan($sandbox)->assertStatus(429);

        $production = $this->iosProof(['environment' => 'Production', 'originalTransactionId' => '2000000777777777']);
        for ($i = 0; $i < 3; $i++) {
            $this->scan($production)->assertOk();
        }
        $this->scan($production)->assertStatus(429);
    }

    public function test_invalid_weekly_limit_setting_falls_back_to_default_and_logs(): void
    {
        $this->setSettings(['scan' => ['sandbox_weekly_limit' => 'många']]);
        $logged = $this->captureLogs();
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof(['environment' => 'Sandbox']);

        for ($i = 0; $i < 5; $i++) {
            $this->scan($proof)->assertOk();
        }
        $this->scan($proof)->assertStatus(429);
        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => $m->level === 'warning' && str_contains($m->message, 'settings.scan.sandbox_weekly_limit')));
    }

    public function test_week_key_uses_the_iso_year_in_stockholm(): void
    {
        $key = fn (string $time) => GlosisScanController::weeklyLimitKey('2000000111111111', CarbonImmutable::parse($time, 'Europe/Stockholm'));

        // Måndag 30 december 2024 tillhör ISO-vecka 2025-W01 (format "Y-W" gav 2024-W01).
        $this->assertSame($key('2024-12-30 08:00'), $key('2025-01-05 23:59'));
        $this->assertStringEndsWith(':2025-W01', $key('2024-12-30 08:00'));
        // 2026 har 53 ISO-veckor; nyårsafton och nyårsdagen är samma vecka.
        $this->assertSame($key('2026-12-31 12:00'), $key('2027-01-01 12:00'));
        $this->assertNotSame($key('2027-01-03 23:59'), $key('2027-01-04 00:00'));
        // Söndag 23:30 i Stockholm är fortfarande samma vecka trots att UTC-tiden är 22:30.
        $this->assertSame($key('2026-10-04 23:30'), $key('2026-09-28 00:00'));
        // Köp-id:t står aldrig i klartext i nyckeln.
        $this->assertStringNotContainsString('2000000111111111', $key('2026-10-04 23:30'));
    }

    public function test_weekly_cap_counts_before_deciding_so_parallel_requests_cannot_slip_through(): void
    {
        // Räknaren står redan på gränsen när anropet kommer, och en
        // kontroll-före-räkning skulle se en äldre siffra (som vid parallella
        // anrop). Bara värdet som hit() returnerar får avgöra.
        $limiter = new class(app('cache')->store()) extends \Illuminate\Cache\RateLimiter
        {
            public function tooManyAttempts($key, $maxAttempts)
            {
                return str_starts_with($key, 'glosis-scan:tx:') ? false : parent::tooManyAttempts($key, $maxAttempts);
            }
        };
        $limiter->for('glosis-scan', app(\Illuminate\Cache\RateLimiter::class)->limiter('glosis-scan'));
        $this->app->instance(\Illuminate\Cache\RateLimiter::class, $limiter);
        RateLimiter::swap($limiter);
        RateLimiter::increment(GlosisScanController::weeklyLimitKey('2000000111111111', now()), 3600, 15);
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->iosProof(['environment' => 'Production']))
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');
        $this->assertCount(0, $this->claude->requests);
        // Den nekade skanningen lämnar ingen reservation kvar i budgeten.
        $this->assertSame(0, (int) Cache::get(AiBudget::monthKey($this->game, now())));
    }

    public function test_rejected_input_does_not_use_up_the_weekly_cap(): void
    {
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof(['environment' => 'Sandbox']);

        for ($i = 0; $i < 10; $i++) {
            $this->scan($proof, UploadedFile::fake()->createWithContent('x.jpg', 'inte en bild'))->assertStatus(422);
        }

        $this->scan($proof)->assertOk();
    }

    // ---- Användning och månadsbudget -----------------------------------

    public function test_successful_scan_is_recorded_with_tokens_and_cost(): void
    {
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->iosProof(['environment' => 'Production']))->assertOk();

        $usage = AiUsage::sole();
        $this->assertSame($this->game->id, $usage->game_id);
        $this->assertSame('scan', $usage->feature);
        $this->assertSame('Production', $usage->environment);
        $this->assertSame(1, $usage->units);
        $this->assertSame(1800, $usage->input_tokens);
        $this->assertSame(240, $usage->output_tokens);
        $this->assertNull($usage->characters);
        // 1800 × $2/MTok + 240 × $10/MTok = $0.0036 + $0.0024
        $this->assertSame('0.006000', $usage->est_cost_usd);
        // Invariant: budgeträknaren = summan i ai_usage när inget anrop pågår.
        $this->assertSame(6000, (int) Cache::get(AiBudget::monthKey($this->game, now())));
        $this->assertSame(6000, AiBudget::spentMicroUsd($this->game, now()));
    }

    public function test_billed_failure_is_recorded_and_network_failure_is_not(): void
    {
        $this->claude->push(FakeClaudeTransport::message([], 'refusal', ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null]));
        $this->scan()->assertStatus(502);

        $this->claude->push(new APIConnectionException(new Psr7Request('POST', 'https://api.anthropic.com/v1/messages'), new \RuntimeException('timeout')));
        $this->scan()->assertStatus(502);

        $this->claude->push(FakeClaudeTransport::message([['type' => 'text', 'text' => 'inte json']]));
        $this->scan()->assertStatus(502);

        $this->assertSame(2, AiUsage::count());
        $this->assertSame(12000, AiBudget::spentMicroUsd($this->game, now()));
        $this->assertSame(12000, (int) Cache::get(AiBudget::monthKey($this->game, now())));
    }

    public function test_exhausted_monthly_budget_returns_503_without_calling_claude(): void
    {
        // Reservationen per skanning är $0.06, verklig kostnad $0.006.
        $this->setSettings(['ai' => ['monthly_budget_usd' => '0.07']]);
        $logged = $this->captureLogs();
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof(['environment' => 'Production']);

        $this->scan($proof)->assertOk();   // 0 + 0.06 ≤ 0.07, bokförs 0.006
        $this->scan($proof)->assertOk();   // 0.006 + 0.06 ≤ 0.07, bokförs 0.006
        $this->scan($proof)                // 0.012 + 0.06 > 0.07
            ->assertStatus(503)
            ->assertExactJson(['error' => 'budget_exhausted', 'message' => 'Fota läxan har tagit paus för den här månaden. Skriv in orden så länge.']);

        $this->assertCount(2, $this->claude->requests);
        $this->assertSame(2, AiUsage::count());
        $this->assertSame(12000, (int) Cache::get(AiBudget::monthKey($this->game, now())));
        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => $m->level === 'warning' && str_contains($m->message, 'månadsbudgeten')));

        // Budgetstoppet tar inte av veckogränsen: med höjd budget går det igen.
        $this->setSettings(['ai' => ['monthly_budget_usd' => 50]]);
        for ($i = 0; $i < 13; $i++) {
            $this->scan($proof)->assertOk();
        }
        $this->scan($proof)->assertStatus(429);
    }

    public function test_budget_counts_what_is_already_in_ai_usage_when_the_cache_is_empty(): void
    {
        $this->setSettings(['ai' => ['monthly_budget_usd' => 1]]);
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        AiUsage::create(['game_id' => $this->game->id, 'feature' => 'tts', 'environment' => 'Production', 'est_cost_usd' => '0.950000']);
        // Förra månaden (Stockholm) räknas inte.
        $lastMonth = AiUsage::create(['game_id' => $this->game->id, 'feature' => 'scan', 'environment' => 'Production', 'est_cost_usd' => '40.000000']);
        $lastMonth->forceFill(['created_at' => CarbonImmutable::now('Europe/Stockholm')->startOfMonth()->subSecond()->utc()])->save();
        // Ett annat spel räknas inte.
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
        AiUsage::create(['game_id' => $tocco->id, 'feature' => 'scan', 'environment' => 'Production', 'est_cost_usd' => '40.000000']);

        $this->scan()->assertStatus(503)->assertJsonPath('error', 'budget_exhausted');
        $this->assertCount(0, $this->claude->requests);

        $this->setSettings(['ai' => ['monthly_budget_usd' => 1.02]]);
        $this->scan()->assertOk();
    }

    public function test_last_scans_under_parallel_load_are_decided_on_the_incremented_value(): void
    {
        // Räknaren står strax under budgeten, som om andra anrop just reserverat:
        // bara det värde increment returnerar får avgöra.
        $this->setSettings(['ai' => ['monthly_budget_usd' => 1]]);
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        Cache::put(AiBudget::monthKey($this->game, now()), 1_000_000 - AiCost::SCAN_RESERVE_MICRO_USD + 1, 3600);

        $this->scan()->assertStatus(503)->assertJsonPath('error', 'budget_exhausted');
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(1_000_000 - AiCost::SCAN_RESERVE_MICRO_USD + 1, (int) Cache::get(AiBudget::monthKey($this->game, now())));

        Cache::put(AiBudget::monthKey($this->game, now()), 1_000_000 - AiCost::SCAN_RESERVE_MICRO_USD, 3600);
        $this->scan()->assertOk();
        $this->assertSame(1_000_000 - AiCost::SCAN_RESERVE_MICRO_USD + 6000, (int) Cache::get(AiBudget::monthKey($this->game, now())));
    }

    public function test_budget_month_follows_stockholm(): void
    {
        $key = fn (string $time) => AiBudget::monthKey($this->game, CarbonImmutable::parse($time, 'Europe/Stockholm'));

        // 00:30 den 1 november i Stockholm är 23:30 den 31 oktober i UTC.
        $this->assertStringEndsWith(':2026-11', $key('2026-11-01 00:30'));
        $this->assertNotSame($key('2026-10-31 23:59'), $key('2026-11-01 00:00'));
    }

    private function setSettings(array $settings): void
    {
        $this->game->refresh();
        $this->game->update(['settings' => array_replace_recursive($this->game->settings ?? [], $settings)]);
    }

    public function test_max_upload_stays_within_anthropics_base64_limit(): void
    {
        // Claude API: högst 10 MB per bild, base64-kodad
        // (https://platform.claude.com/docs/en/build-with-claude/vision#request-limits).
        $base64 = 4 * (int) ceil(GlosisScanController::MAX_IMAGE_BYTES / 3);

        $this->assertLessThanOrEqual(GlosisScanController::MAX_IMAGE_BASE64_BYTES, $base64);
        $this->assertSame(10 * 1024 * 1024, GlosisScanController::MAX_IMAGE_BASE64_BYTES);
    }

    // ---- Återbetalda köp -----------------------------------------------

    public function test_refunded_transaction_on_denylist_returns_402(): void
    {
        RevokedStoreTransaction::create([
            'game_id' => $this->game->id,
            'store' => 'apple',
            'original_transaction_id' => '2000000111111111',
            'transaction_id' => '2000000123456789',
            'reason' => 'CUSTOMER_SUPPORT',
            'revoked_at' => now(),
        ]);

        $this->scan($this->iosProof(['environment' => 'Production']))
            ->assertStatus(402)->assertJsonPath('error', 'guld_required');
        $this->assertCount(0, $this->claude->requests);

        // Ett annat köp påverkas inte.
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));
        $this->scan($this->iosProof(['environment' => 'Production', 'originalTransactionId' => '2000000999999999']))->assertOk();
    }

    public function test_denylist_is_per_game(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
        RevokedStoreTransaction::create([
            'game_id' => $tocco->id,
            'store' => 'apple',
            'original_transaction_id' => '2000000111111111',
            'transaction_id' => '2000000111111111',
            'reason' => 'CUSTOMER_SUPPORT',
            'revoked_at' => now(),
        ]);
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->iosProof(['environment' => 'Production']))->assertOk();
    }

    public function test_sixty_requests_per_hour_and_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->call('POST', self::URL, ['proof' => 'x'], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(422);
        }

        $response = $this->call('POST', self::URL, ['proof' => 'x'], [], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertJsonStructure(['error', 'message', 'retry_after']);
        $this->assertGreaterThan(0, $response->json('retry_after'));

        // En annan IP påverkas inte.
        $this->call('POST', self::URL, ['proof' => 'x'], [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.0.0.2'])->assertStatus(422);
    }

    // ---- 501 / 502 / 503 ----------------------------------------------

    public function test_android_without_google_play_settings_returns_501_and_logs(): void
    {
        $logged = $this->captureLogs();

        $this->scan($this->androidProof())->assertStatus(501)->assertJsonPath('error', 'platform_not_supported')->assertJsonStructure(['error', 'message']);
        $this->assertCount(0, $this->claude->requests);
        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => $m->level === 'error' && str_contains($m->message, 'Google Play')));
    }

    // ---- Android (Google Play) -------------------------------------------

    private function enableGooglePlay(): FakeGooglePlay
    {
        $this->setSettings(['google_play' => ['service_account_json' => Crypt::encryptString(FakeGooglePlay::serviceAccountJson())]]);
        Http::preventStrayRequests();
        $google = new FakeGooglePlay;
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase($this->product));
        $google->fake();

        return $google;
    }

    private function androidProof(?string $product = null, string $token = FakeGooglePlay::PURCHASE_TOKEN): string
    {
        return json_encode(['platform' => 'android', 'productId' => $product ?? $this->product, 'purchaseToken' => $token]);
    }

    public function test_android_purchase_is_verified_with_google_play(): void
    {
        $google = $this->enableGooglePlay();
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->androidProof())->assertOk()->assertJsonPath('title', 'Vecka 40');

        $this->assertSame(FakeGooglePlay::purchaseUrl(), $google->purchaseRequests[0]->url());
        $this->assertSame('Production', AiUsage::sole()->environment);
        // Veckogränsen räknas per orderId, samma id som RevenueCat spärrar på.
        $this->assertSame(1, RateLimiter::attempts(GlosisScanController::weeklyLimitKey(FakeGooglePlay::ORDER_ID, CarbonImmutable::now('Europe/Stockholm'))));
    }

    public function test_android_package_name_comes_from_settings(): void
    {
        $google = $this->enableGooglePlay();
        $this->setSettings(['google_play' => ['package_name' => 'se.computercat.glosis.beta']]);
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->androidProof())->assertOk();
        $this->assertSame(FakeGooglePlay::purchaseUrl('se.computercat.glosis.beta'), $google->purchaseRequests[0]->url());
    }

    public function test_android_test_purchase_gets_the_sandbox_cap(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase($this->product, ['testPurchaseContext' => ['fopType' => 'TEST']]));
        $this->setSettings(['scan' => ['weekly_limit' => 10, 'sandbox_weekly_limit' => 1]]);
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));

        $this->scan($this->androidProof())->assertOk();
        $this->scan($this->androidProof())->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        $this->assertSame('Sandbox', AiUsage::sole()->environment);
        // Åtkomsttoken hämtades en gång.
        $this->assertCount(1, $google->tokenRequests);
    }

    public static function androidWithoutGuld(): array
    {
        return [
            'makulerat' => [['purchaseStateContext' => ['purchaseState' => 'CANCELLED']], []],
            'väntande' => [['purchaseStateContext' => ['purchaseState' => 'PENDING']], []],
            'förbrukat' => [[], ['consumptionState' => 'CONSUMPTION_STATE_CONSUMED']],
            'återbetalt' => [[], ['refundableQuantity' => 0]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('androidWithoutGuld')]
    public function test_android_purchase_that_gives_nothing_returns_402(array $overrides, array $offer): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase($this->product, $overrides, $offer));

        $this->scan($this->androidProof())->assertStatus(402)->assertJsonPath('error', 'guld_required');
        $this->assertCount(0, $this->claude->requests);
    }

    public function test_android_expired_school_year_returns_402(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase('glosis_guld_2020_21'));

        $this->scan($this->androidProof('glosis_guld_2020_21'))->assertStatus(402)->assertJsonPath('error', 'guld_required');
    }

    public function test_android_proof_for_other_product_than_google_says_returns_422(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(FakeGooglePlay::purchase('glosis_guld_2020_21'));

        $this->scan($this->androidProof())->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
    }

    public function test_android_unknown_token_returns_422(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(['error' => ['code' => 404, 'message' => 'not found']], 404);

        $this->scan($this->androidProof())->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
    }

    public static function malformedAndroidProofs(): array
    {
        return [
            'utan token' => ['{"platform":"android","productId":"glosis_guld_2026_27"}'],
            'tom token' => ['{"platform":"android","productId":"glosis_guld_2026_27","purchaseToken":""}'],
            'utan produkt' => ['{"platform":"android","purchaseToken":"abc"}'],
            'token som tal' => ['{"platform":"android","productId":"glosis_guld_2026_27","purchaseToken":123}'],
            'token med snedstreck' => ['{"platform":"android","productId":"glosis_guld_2026_27","purchaseToken":"a/../b"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAndroidProofs')]
    public function test_malformed_android_proof_returns_422(string $proof): void
    {
        $google = $this->enableGooglePlay();

        $this->scan($proof)->assertStatus(422)->assertJsonPath('error', 'invalid_proof');
        $this->assertSame([], $google->purchaseRequests);
    }

    public function test_android_purchase_refunded_through_revenuecat_returns_402(): void
    {
        $this->enableGooglePlay();
        $this->setSettings(['revenuecat' => ['webhook_secret' => Crypt::encryptString('rc-secret')]]);

        // RevenueCat skickar Googles orderId som transaction_id/original_transaction_id.
        $this->postJson('/api/v1/webhooks/revenuecat/glosis', ['event' => [
            'id' => 'evt-1', 'type' => 'CANCELLATION', 'cancel_reason' => 'CUSTOMER_SUPPORT',
            'app_user_id' => '$RCAnonymousID:abc', 'product_id' => $this->product,
            'transaction_id' => FakeGooglePlay::ORDER_ID, 'original_transaction_id' => FakeGooglePlay::ORDER_ID,
            'expiration_at_ms' => null, 'store' => 'PLAY_STORE', 'environment' => 'PRODUCTION',
        ]], ['Authorization' => 'Bearer rc-secret'])->assertOk();

        $this->scan($this->androidProof())->assertStatus(402)->assertJsonPath('error', 'guld_required');
        $this->assertCount(0, $this->claude->requests);
    }

    public function test_google_permission_error_returns_503_and_logs_the_permission(): void
    {
        $logged = $this->captureLogs();
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => Http::response(['error' => ['code' => 403, 'message' => 'The current user has insufficient permissions']], 403);

        $this->scan($this->androidProof())->assertStatus(503)->assertJsonPath('error', 'scan_unavailable')->assertJsonStructure(['error', 'message']);
        $this->assertCount(0, $this->claude->requests);
        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => $m->level === 'error' && str_contains($m->message, GooglePurchaseVerifier::REQUIRED_PERMISSION)));
        $this->assertStringNotContainsString(FakeGooglePlay::PURCHASE_TOKEN, json_encode(collect($logged())->map(fn ($m) => [$m->message, $m->context])));
    }

    public function test_google_network_error_returns_503(): void
    {
        $google = $this->enableGooglePlay();
        $google->purchaseAnswer = fn () => throw new ConnectionException('timeout');

        $this->scan($this->androidProof())->assertStatus(503)->assertJsonPath('error', 'scan_unavailable');
    }

    public function test_refusal_returns_502(): void
    {
        $this->claude->push(FakeClaudeTransport::message([], 'refusal', ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null]));

        $this->scan()->assertStatus(502)->assertJsonPath('error', 'scan_failed');
    }

    public static function badClaudeAnswers(): array
    {
        $word = fn ($sv = 'hund', $en = 'dog') => ['sv' => $sv, 'en' => $en, 'guessed' => false];

        return [
            'max_tokens' => [fn () => FakeClaudeTransport::message([['type' => 'text', 'text' => '{"title":"x","wor']], 'max_tokens')],
            'inte JSON' => [fn () => FakeClaudeTransport::message([['type' => 'text', 'text' => 'Här är glosorna!']])],
            'inget textblock' => [fn () => FakeClaudeTransport::message([])],
            'words saknas' => [fn () => FakeClaudeTransport::json(['title' => 'x'])],
            'title fel typ' => [fn () => FakeClaudeTransport::json(['title' => 5, 'words' => []])],
            'guessed fel typ' => [fn () => FakeClaudeTransport::json(['title' => 'x', 'words' => [['sv' => 'a', 'en' => 'b', 'guessed' => 'ja']]])],
            'för många ord' => [fn () => FakeClaudeTransport::json(['title' => 'x', 'words' => array_fill(0, 61, $word())])],
            'för långt ord' => [fn () => FakeClaudeTransport::json(['title' => 'x', 'words' => [$word(str_repeat('a', 81))]])],
            'API-fel 500' => [fn () => new Response(500, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"api_error","message":"boom"}}')],
            'API-fel 400' => [fn () => new Response(400, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"invalid_request_error","message":"bad"}}')],
            'nätverksfel' => [fn () => new APIConnectionException(new Psr7Request('POST', 'https://api.anthropic.com/v1/messages'), new \RuntimeException('timeout'))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badClaudeAnswers')]
    public function test_bad_claude_answer_returns_502(\Closure $answer): void
    {
        $this->claude->always($answer());

        $this->scan()->assertStatus(502)->assertJsonPath('error', 'scan_failed')->assertJsonStructure(['error', 'message']);
    }

    public function test_exactly_sixty_words_of_eighty_chars_are_accepted(): void
    {
        $words = array_fill(0, 60, ['sv' => str_repeat('ö', 80), 'en' => str_repeat('e', 80), 'guessed' => false]);
        $this->claude->push(FakeClaudeTransport::json(['title' => 'x', 'words' => $words]));

        $this->assertCount(60, $this->scan()->assertOk()->json('words'));
    }

    public function test_missing_api_key_returns_503_and_logs_error(): void
    {
        $this->game->update(['settings' => ['site_url' => 'https://glosis.se']]);
        $logged = $this->captureLogs();

        $this->scan()->assertStatus(503)->assertJsonPath('error', 'scan_unavailable');

        $this->assertTrue(collect($logged())->contains(fn (MessageLogged $m) => $m->level === 'error' && str_contains($m->message, 'Anthropic-nyckel')));
        $this->assertCount(0, $this->claude->requests);
    }

    public function test_undecryptable_api_key_returns_503(): void
    {
        $this->game->update(['settings' => ['anthropic' => ['api_key' => 'inte-krypterad']]]);

        $this->scan()->assertStatus(503)->assertJsonPath('error', 'scan_unavailable');
    }

    public function test_other_games_have_no_scan_route(): void
    {
        Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);

        $this->call('POST', '/api/v1/games/tocco/scan', ['proof' => $this->iosProof()], [], ['image' => $this->image()], ['HTTP_ACCEPT' => 'application/json'])
            ->assertNotFound();
    }

    // ---- Integritet ----------------------------------------------------

    public function test_image_is_never_written_to_any_storage_disk(): void
    {
        $disks = array_keys(config('filesystems.disks'));
        foreach ($disks as $disk) {
            Storage::fake($disk);
        }
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan()->assertOk();

        foreach ($disks as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(), "Disken {$disk} fick filer");
        }
    }

    public function test_logs_contain_metadata_but_never_image_proof_or_key(): void
    {
        $logged = $this->captureLogs();
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));
        $image = $this->image();
        $base64 = base64_encode(file_get_contents($image->getRealPath()));
        $proof = $this->iosProof();
        $jws = json_decode($proof, true)['jws'];

        $this->scan($proof, $image)->assertOk();

        $done = collect($logged())->first(fn (MessageLogged $m) => str_contains($m->message, 'klar'));
        $this->assertNotNull($done);
        $this->assertSame('Sandbox', $done->context['environment']);
        $this->assertSame(2, $done->context['words']);
        $this->assertSame(1800, $done->context['input_tokens']);
        $this->assertSame(240, $done->context['output_tokens']);
        $this->assertArrayHasKey('duration_ms', $done->context);
        $this->assertNotSame('2000000111111111', $done->context['tx']);
        $this->assertSame(16, strlen($done->context['tx']));

        $all = json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged()));
        $this->assertStringNotContainsString(substr($base64, 0, 200), $all);
        $this->assertStringNotContainsString(explode('.', $jws)[2], $all);
        $this->assertStringNotContainsString(self::API_KEY, $all);
        $this->assertStringNotContainsString('2000000111111111', $all);
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

    // ---- CORS ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('capacitorOrigins')]
    public function test_post_from_capacitor_origin_gets_cors_headers(string $origin): void
    {
        $this->claude->push(FakeClaudeTransport::json($this->goodWords()));

        $this->scan(server: ['HTTP_ORIGIN' => $origin, 'HTTP_REFERER' => $origin.'/'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    /**
     * Sanctums statefulApi() räknar origin https://localhost (Capacitor på
     * Android) som en SPA och kräver då CSRF-token. Laravel hoppar över
     * CSRF-kontrollen i tester, så den slås på här för hand.
     */
    public function test_scan_route_is_exempt_from_csrf_but_other_posts_are_not(): void
    {
        $csrf = new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $next = fn () => response('ok');

        $scan = \Illuminate\Http\Request::create(self::URL, 'POST');
        $scan->setLaravelSession(app('session.store'));
        $this->assertSame('ok', $csrf->handle($scan, $next)->getContent());

        $other = \Illuminate\Http\Request::create('/api/v1/games/glosis/interest', 'POST');
        $other->setLaravelSession(app('session.store'));
        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $csrf->handle($other, $next);
    }

    public static function capacitorOrigins(): array
    {
        return [['capacitor://localhost'], ['https://localhost']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('capacitorOrigins')]
    public function test_cors_preflight_allows_capacitor_origins(string $origin): void
    {
        $this->call('OPTIONS', self::URL, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertHeader('Access-Control-Allow-Origin', $origin);
    }
}
