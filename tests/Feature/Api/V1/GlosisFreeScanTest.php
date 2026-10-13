<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiUsage;
use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AiBudget;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\HomeworkScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Glosis\FreeGateTest as G;
use Tests\Support\FakeAppAttest;
use Tests\Support\FakeClaudeTransport;
use Tests\TestCase;

/**
 * Att fota läxan kräver Guld (beslut 2026-10-13). Appversioner från gratisfotots
 * tid skickar attest i stället för proof och ska få 402 guld_required (appen
 * visar då säljsidan), utan att något arbete görs.
 */
class GlosisFreeScanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/games/glosis/scan';

    private static ?FakeAppAttest $fake = null;

    private FakeClaudeTransport $claude;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));

        $this->claude = new FakeClaudeTransport;
        $this->app->instance(HomeworkScanner::class, new HomeworkScanner($this->claude));

        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], [
            'anthropic' => ['api_key' => Crypt::encryptString('sk-ant-test-not-a-real-key')],
            'free' => ['enabled' => true],
            'app_attest' => ['team_id' => GlosisAttestTest::TEAM],
        ])]);
        [$this->key, $this->priv] = G::registeredKey($this->game, self::$fake);
    }

    /** En korrekt signerad gratisförfrågan som gratisfotots appversioner skickade den. */
    private function attestScan(array $extra = []): TestResponse
    {
        $image = UploadedFile::fake()->image('laxa.jpg', 800, 600);
        $cred = G::credential($this->key, $this->priv, 'scan', ['imageSha256' => hash_file('sha256', $image->getRealPath())], 1);

        return $this->post(self::URL, ['image' => $image, 'attest' => json_encode($cred)] + $extra, ['Accept' => 'application/json']);
    }

    private function assertGuldRequired(TestResponse $res): void
    {
        $res->assertStatus(402)->assertExactJson([
            'error' => 'guld_required',
            'message' => 'Att fota läxan ingår i Guldstjärnan. Be en vuxen att titta på det.',
        ]);
    }

    private function assertNothingDone(): void
    {
        $this->assertCount(0, $this->claude->requests);
        $this->assertSame(0, AiUsage::count());
        $this->assertFalse(Cache::has(AiBudget::monthKey($this->game, now())));
        $this->assertFalse(Cache::has(AiBudget::freeMonthKey($this->game, now())));
        // App Attest prövas aldrig: räknaren står kvar.
        $this->assertSame(0, $this->key->fresh()->sign_count);
    }

    public function test_attest_skanning_ger_guld_required_utan_arbete(): void
    {
        $this->claude->push(FakeClaudeTransport::json(['title' => 'Vecka 42', 'words' => [['sv' => 'hund', 'en' => 'dog', 'guessed' => false]]]));

        $this->assertGuldRequired($this->attestScan());
        $this->assertNothingDone();
    }

    public function test_attest_och_proof_samtidigt_ger_guld_required(): void
    {
        $this->assertGuldRequired($this->attestScan(['proof' => '{}']));
        $this->assertNothingDone();
    }

    public function test_tomt_attest_falt_och_ingen_bild_ger_guld_required(): void
    {
        $this->assertGuldRequired($this->post(self::URL, ['attest' => ''], ['Accept' => 'application/json']));
        $this->assertNothingDone();
    }

    public function test_avstangd_gratisniva_ger_ocksa_guld_required(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);

        $this->assertGuldRequired($this->attestScan());
        $this->assertNothingDone();
    }
}
