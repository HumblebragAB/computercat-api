<?php

namespace Tests\Feature\Api\V1;

use App\Http\Controllers\Api\V1\GlosisScanController;
use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\HomeworkScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Glosis\FreeGateTest as G;
use Tests\Support\FakeAppAttest;
use Tests\Support\FakeClaudeTransport;
use Tests\TestCase;

class GlosisFreeScanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/scan';

    private static ?FakeAppAttest $fake = null;

    private FakeClaudeTransport $claude;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    private int $count = 0;

    /** Testets onsdag 12:00 i Stockholm (nästa vecka, räknat från den riktiga klockan, som GlosisScanTest). */
    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));

        // Claude fejkas som i GlosisScanTest.
        $this->claude = new FakeClaudeTransport;
        $this->app->instance(HomeworkScanner::class, new HomeworkScanner($this->claude));

        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'anthropic' => ['api_key' => Crypt::encryptString('sk-ant-test-not-a-real-key')],
            'free' => ['enabled' => true],
            'app_attest' => ['team_id' => GlosisAttestTest::TEAM],
        ])]);
        [$this->key, $this->priv] = G::registeredKey($this->game, self::$fake);

        $this->base = CarbonImmutable::createFromTimestamp(time(), 'Europe/Stockholm')
            ->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->addDays(2)->setTime(12, 0);
        Carbon::setTestNow($this->base->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function image(int $w = 800, int $h = 600): UploadedFile
    {
        return UploadedFile::fake()->image('laxa.jpg', $w, $h);
    }

    /** Signerar $signed (standard: den uppladdade bilden) och laddar upp $image. */
    private function scan(?string $hashOverride = null, ?AppAttestKey $key = null, ?\OpenSSLAsymmetricKey $priv = null, ?UploadedFile $image = null, ?UploadedFile $signed = null): TestResponse
    {
        $image ??= $this->image();
        $hash = $hashOverride ?? hash_file('sha256', ($signed ?? $image)->getRealPath());
        $cred = G::credential($key ?? $this->key, $priv ?? $this->priv, 'scan', ['imageSha256' => $hash], ++$this->count);

        return $this->post(self::URL, ['image' => $image, 'attest' => json_encode($cred)], ['Accept' => 'application/json']);
    }

    private function words(): FakeClaudeTransport
    {
        return $this->claude->push(FakeClaudeTransport::json(['title' => 'Vecka 42', 'words' => [['sv' => 'hund', 'en' => 'dog', 'guessed' => false]]]));
    }

    private function settings(array $free): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => true] + $free])]);
    }

    public function test_en_gratisskanning_per_vecka(): void
    {
        $this->words();
        $this->scan()->assertOk()->assertJsonPath('words.0.en', 'dog');

        $res = $this->scan()->assertStatus(429)->assertJson(['error' => 'free_scan_used']);
        $this->assertSame((string) $res->json('retry_after'), $res->headers->get('Retry-After'));
        $toMonday = (int) ceil($this->base->diffInSeconds($this->base->startOfWeek(CarbonImmutable::MONDAY)->addWeek()));
        $this->assertSame($toMonday, $res->json('retry_after'));
        $this->assertStringContainsString('måndag', $res->json('message'));

        $row = AiUsage::where('tier', 'free')->where('feature', 'scan')->sole();
        $this->assertSame($this->key->id, $row->app_attest_key_id);
        $this->assertSame('Production', $row->environment);
        $this->assertCount(1, $this->claude->requests);
    }

    public function test_ny_vecka_ger_ny_skanning(): void
    {
        $this->claude->always(FakeClaudeTransport::json(['title' => 'x', 'words' => [['sv' => 'hund', 'en' => 'dog', 'guessed' => false]]]));
        $this->scan()->assertOk();
        Carbon::setTestNow($this->base->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->setTime(0, 0, 30)->utc());
        $this->scan()->assertOk();
    }

    public function test_bildens_hash_maste_stamma(): void
    {
        $this->scan(str_repeat('0', 64))->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
        $this->assertCount(0, $this->claude->requests);

        $this->words();
        $this->scan()->assertOk(); // veckans skanning är inte förbrukad
    }

    public function test_bilden_kan_inte_bytas_efter_signeringen(): void
    {
        $this->scan(image: $this->image(640, 480), signed: $this->image(800, 600))
            ->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
        $this->scan(hashOverride: 'inte-hex')->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_hash_med_versaler_godtas(): void
    {
        $image = $this->image();
        $this->words();
        $this->scan(hashOverride: strtoupper(hash_file('sha256', $image->getRealPath())), image: $image)->assertOk();
    }

    public function test_globalt_gratistak(): void
    {
        $this->settings(['scan_daily_limit' => 1]);
        $this->words();
        $this->scan()->assertOk();
        [$key2, $priv2] = G::registeredKey($this->game, self::$fake);
        $this->scan(key: $key2, priv: $priv2)->assertStatus(429)->assertJson(['error' => 'free_busy']);
        $this->assertCount(1, $this->claude->requests);

        // Avslaget förbrukade inte nyckel 2:s vecka: i morgon går det.
        Carbon::setTestNow($this->base->addDay()->utc());
        $this->words();
        $this->scan(key: $key2, priv: $priv2)->assertOk();
    }

    public function test_ip_tak(): void
    {
        $this->settings(['scan_ip_daily_limit' => 1]);
        $this->words();
        $this->scan()->assertOk();
        [$key2, $priv2] = G::registeredKey($this->game, self::$fake);
        $this->scan(key: $key2, priv: $priv2)->assertStatus(429)->assertJson(['error' => 'free_busy']);
        $this->assertCount(1, $this->claude->requests);
    }

    public function test_olasbar_bild_forbrukar_inte_veckans_foto(): void
    {
        $this->claude->push(FakeClaudeTransport::message([], 'refusal', ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => null]));
        $this->scan()->assertStatus(502)->assertJson(['error' => 'scan_failed']);
        $this->words();
        $this->scan()->assertOk();
    }

    public function test_inga_ord_forbrukar_inte_veckans_foto(): void
    {
        $this->claude->push(FakeClaudeTransport::json(['title' => '', 'words' => []]));
        $this->scan()->assertOk()->assertJsonPath('words', []);
        $this->words();
        $this->scan()->assertOk()->assertJsonPath('words.0.en', 'dog');
        $this->scan()->assertStatus(429)->assertJson(['error' => 'free_scan_used']);
        $this->assertSame(2, AiUsage::where('tier', 'free')->count());
    }

    public function test_ovantat_fel_aterfor_veckans_foto_och_budgeten(): void
    {
        $this->mock(HomeworkScanner::class, fn ($m) => $m->shouldReceive('scan')->once()->andThrow(new \RuntimeException('boom')));
        $this->scan()->assertStatus(500);
        $this->assertSame(0, (int) RateLimiter::attempts(GlosisScanController::freeWeekKey($this->caller(), now())));
        $this->assertSame(0, (int) cache()->get(AiBudget::freeMonthKey($this->game, now())));
        $this->assertSame(0, (int) cache()->get(AiBudget::monthKey($this->game, now())));
    }

    public function test_aterforingarna_ar_begransade_per_vecka(): void
    {
        $this->claude->always(FakeClaudeTransport::json(['title' => '', 'words' => []]));
        for ($i = 0; $i <= GlosisScanController::FREE_SCAN_WEEKLY_REFUNDS; $i++) {
            $this->scan()->assertOk();
        }
        $this->scan()->assertStatus(429)->assertJson(['error' => 'free_scan_used']);
        $this->assertCount(GlosisScanController::FREE_SCAN_WEEKLY_REFUNDS + 1, $this->claude->requests);
    }

    public function test_aterforda_anrop_raknas_mot_ip_taket(): void
    {
        $this->settings(['scan_ip_daily_limit' => 2]);
        $this->claude->always(FakeClaudeTransport::json(['title' => '', 'words' => []]));
        $this->scan()->assertOk();
        $this->scan()->assertOk();
        $this->scan()->assertStatus(429)->assertJson(['error' => 'free_busy']);
        $this->assertCount(2, $this->claude->requests);
    }

    public function test_avstangd_gratisniva_ger_503_innan_nagot_arbete(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->scan()->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(0, (int) RateLimiter::attempts(GlosisScanController::freeWeekKey($this->caller(), now())));
        $this->assertSame(0, $this->key->fresh()->sign_count);
    }

    public function test_slut_pa_gratisbudgeten_ger_503(): void
    {
        $this->settings(['monthly_budget_usd' => 0]);
        $this->scan()->assertStatus(503)->assertJson(['error' => 'budget_exhausted']);
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(0, (int) RateLimiter::attempts(GlosisScanController::freeWeekKey($this->caller(), now())));
    }

    public function test_bade_proof_och_attest_ger_422(): void
    {
        $this->post(self::URL, ['image' => $this->image(), 'proof' => '{}', 'attest' => '{}'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['error' => 'invalid_request']);
    }

    public function test_attest_med_fel_form_ger_422(): void
    {
        $this->post(self::URL, ['image' => $this->image(), 'attest' => 'inte json'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
        $this->post(self::URL, ['image' => $this->image(), 'attest' => ''], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['error' => 'invalid_attest']);
    }

    private function caller(): GlosisCaller
    {
        return GlosisCaller::free($this->key, '127.0.0.1');
    }
}
