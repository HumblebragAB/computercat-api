<?php

namespace Tests\Feature\Api\V1;

use Anthropic\Core\Exceptions\APIConnectionException;
use App\Models\Game;
use App\Services\Glosis\AppleTransactionVerifier;
use App\Services\Glosis\GuldStatus;
use App\Services\Glosis\HomeworkScanner;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAppleChain;
use Tests\Support\FakeClaudeTransport;
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

    public function test_thirty_scans_per_purchase_and_stockholm_day(): void
    {
        $this->claude->always(FakeClaudeTransport::json($this->goodWords()));
        $proof = $this->iosProof();

        for ($i = 0; $i < 30; $i++) {
            $this->scan($proof)->assertOk();
        }

        // 14:00 i Stockholm: 10 h kvar till midnatt.
        $this->scan($proof)
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertJsonPath('retry_after', 10 * 3600)
            ->assertHeader('Retry-After', (string) (10 * 3600));
        $this->assertCount(30, $this->claude->requests);

        // Ett annat köp har sin egen gräns.
        $this->scan($this->iosProof(['originalTransactionId' => '2000000999999999']))->assertOk();

        // Nytt dygn i Stockholm.
        Carbon::setTestNow(now()->addHours(10));
        $this->scan($this->iosProof())->assertOk();
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

    public function test_android_is_explicitly_not_supported_yet(): void
    {
        $proof = json_encode(['platform' => 'android', 'productId' => 'glosis_guld_2026_27', 'purchaseToken' => 'tok']);

        $this->scan($proof)->assertStatus(501)->assertJsonPath('error', 'platform_not_supported')->assertJsonStructure(['error', 'message']);
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
