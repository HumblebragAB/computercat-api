<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\GlosisSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class GlosisAttestTest extends TestCase
{
    use RefreshDatabase;

    public const TEAM = 'ABCDE12345';

    public const APP_ID = self::TEAM.'.se.computercat.glosis';

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], ['free' => ['enabled' => true], 'app_attest' => ['team_id' => self::TEAM]])]);
    }

    private function challenge(): string
    {
        return $this->postJson('/api/v1/games/glosis/attest/challenge')->assertOk()->json('challenge');
    }

    private function register(array $a, string $challenge)
    {
        return $this->postJson('/api/v1/games/glosis/attest/register', ['keyId' => $a['keyId'], 'attestation' => $a['attestation'], 'challenge' => $challenge]);
    }

    public function test_challenge_har_ratt_form(): void
    {
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertOk()
            ->assertJson(['expires_in' => 300])
            ->assertJsonPath('challenge', fn ($c) => is_string($c) && preg_match('/^[A-Za-z0-9_-]{54}\z/', $c) === 1);
    }

    public function test_registrerar_en_ny_nyckel(): void
    {
        $ch = $this->challenge();
        $a = self::$fake->attest($ch, self::APP_ID);
        $this->register($a, $ch)->assertCreated()->assertJson(['status' => 'registered']);

        $key = AppAttestKey::sole();
        $this->assertSame($a['keyId'], $key->key_id);
        $this->assertSame(0, $key->sign_count);
        $this->assertSame('production', $key->environment);
        $this->assertSame('1', $key->bundle_version);
        $this->assertSame(4, $key->validation_category);
        $this->assertSame($this->game->id, $key->game_id);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $key->public_key);
        $this->assertArrayNotHasKey('public_key', $key->toArray());
    }

    public function test_challenge_galler_bara_en_gang(): void
    {
        $ch = $this->challenge();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422)->assertJson(['error' => 'invalid_attestation']);
        $this->assertSame(1, AppAttestKey::count());
    }

    public function test_paddad_eller_okand_challenge_nekas(): void
    {
        $this->register(self::$fake->attest('egen', self::APP_ID), 'egen')->assertStatus(422);
        $this->register(self::$fake->attest(str_repeat('a', 54), self::APP_ID), str_repeat('a', 54))->assertStatus(422);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_manipulerad_challenge_nekas(): void
    {
        $ch = $this->challenge();
        // Byt ett tecken i MAC-delen.
        $tampered = substr($ch, 0, -2).($ch[-2] === 'A' ? 'B' : 'A').$ch[-1];
        $this->register(self::$fake->attest($tampered, self::APP_ID), $tampered)->assertStatus(422);
        // Originalet förbrukades inte av försöket.
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated();
    }

    public function test_challenge_fran_annan_app_key_nekas(): void
    {
        $ch = $this->challenge();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422);
    }

    public function test_utgangen_challenge_nekas(): void
    {
        $ch = $this->challenge();
        $this->travel(301)->seconds();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422);
    }

    public function test_challenge_fran_framtiden_nekas(): void
    {
        $this->travel(5)->minutes();
        $ch = $this->challenge();
        $this->travelBack();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertStatus(422);
    }

    public function test_challenge_strax_innan_utgang_godtas(): void
    {
        $ch = $this->challenge();
        $this->travel(299)->seconds();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated();
    }

    public function test_utfardad_challenge_skriver_inget_i_cachen(): void
    {
        $challenges = app(AttestChallenges::class);
        $ch = $challenges->issue();
        $this->assertNull(cache()->get('glosis-attest-used:'.hash('sha256', $ch)));
        $this->assertTrue($challenges->consume($ch));
        $this->assertFalse($challenges->consume($ch));
    }

    public function test_samma_nyckel_igen_ar_ok_och_nollstaller_inte_raknaren(): void
    {
        $ch1 = $this->challenge();
        $a = self::$fake->attest($ch1, self::APP_ID);
        $this->register($a, $ch1)->assertCreated();
        AppAttestKey::sole()->update(['sign_count' => 7]);

        // Appen fick inget svar och attesterar samma nyckel igen med en ny challenge: 200, räknaren orörd.
        $ch2 = $this->challenge();
        $this->register(self::$fake->attest($ch2, self::APP_ID, ['key' => $a['privateKey']]), $ch2)->assertOk()->assertJson(['status' => 'registered']);
        $this->assertSame(7, AppAttestKey::sole()->sign_count);

        // Det gamla objektet med en ny challenge nekas (nonce).
        $ch3 = $this->challenge();
        $this->register($a, $ch3)->assertStatus(422);
    }

    public function test_keyid_som_inte_matchar_attesteringen_nekas(): void
    {
        // En angripare kan inte registrera någon annans keyId med sin egen nyckel.
        $victim = self::$fake->attest('x', self::APP_ID);
        $ch = $this->challenge();
        $mine = self::$fake->attest($ch, self::APP_ID);
        $this->postJson('/api/v1/games/glosis/attest/register', ['keyId' => $victim['keyId'], 'attestation' => $mine['attestation'], 'challenge' => $ch])
            ->assertStatus(422);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_forfalskad_attestering_nekas_och_inget_sparas(): void
    {
        $ch = $this->challenge();
        $this->register((new FakeAppAttest)->attest($ch, self::APP_ID), $ch)->assertStatus(422);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_fel_app_id_nekas(): void
    {
        $ch = $this->challenge();
        $this->register(self::$fake->attest($ch, 'ZZZZZ99999.se.computercat.glosis'), $ch)->assertStatus(422);
    }

    public function test_avvisning_loggas_utan_hemligheter(): void
    {
        Log::spy();
        $ch = $this->challenge();
        $a = (new FakeAppAttest)->attest($ch, self::APP_ID);
        $this->register($a, $ch)->assertStatus(422)
            ->assertJsonPath('message', 'Appen kunde inte kontrolleras. Försök igen om en stund.');

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($a, $ch) {
            $all = $message.json_encode($context);

            return str_contains($message, 'avvisad')
                && ($context['key'] ?? null) === AppAttestKey::hashForLog($a['keyId'])
                && ! str_contains($all, $ch) && ! str_contains($all, $a['keyId']) && ! str_contains($all, substr($a['attestation'], 0, 40));
        })->once();
    }

    public function test_fel_form_ger_422(): void
    {
        $ch = $this->challenge();
        $bodies = [
            [],
            ['keyId' => 'x', 'attestation' => 'y', 'challenge' => $ch],
            ['keyId' => [], 'attestation' => 1, 'challenge' => null],
            ['keyId' => base64_encode(random_bytes(32)), 'attestation' => 'inte base64!', 'challenge' => $ch],
            ['keyId' => base64_encode(random_bytes(32)), 'attestation' => str_repeat('A', 22_004), 'challenge' => $ch],
        ];
        foreach ($bodies as $body) {
            $this->postJson('/api/v1/games/glosis/attest/register', $body)->assertStatus(422)->assertJson(['error' => 'invalid_attestation']);
        }
        $this->call('POST', '/api/v1/games/glosis/attest/register', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], 'inte json')
            ->assertStatus(422);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_avstangd_gratisniva_ger_503(): void
    {
        $ch = $this->challenge();
        $a = self::$fake->attest($ch, self::APP_ID);
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
        $this->register($a, $ch)->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
        $this->assertSame(0, AppAttestKey::count());
    }

    public function test_saknad_flagga_ar_avstangd(): void
    {
        $settings = $this->game->settings;
        unset($settings['free']);
        $this->game->update(['settings' => $settings]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503);
    }

    public function test_saknat_team_id_ger_503(): void
    {
        $settings = $this->game->settings;
        unset($settings['app_attest']);
        $this->game->update(['settings' => $settings]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503)->assertJson(['error' => 'free_unavailable']);
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(503);
    }

    public function test_saknat_team_id_loggas_en_gang_per_anrop(): void
    {
        $settings = $this->game->settings;
        unset($settings['app_attest']);
        $this->game->update(['settings' => $settings]);
        Log::spy();
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(503);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'team_id'))->once();
    }

    public function test_team_id_med_fel_form_ger_503(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['app_attest' => ['team_id' => 'abc.evil']])]);
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(503);
    }

    public function test_andra_spel_har_inga_attest_routes(): void
    {
        $this->postJson('/api/v1/games/annat/attest/challenge')->assertNotFound();
    }

    public function test_registrering_ar_begransad_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(429);
    }

    public function test_challenge_har_egen_hogre_grans_an_den_globala(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/v1/games/glosis/attest/challenge')->assertOk();
        }
        $this->postJson('/api/v1/games/glosis/attest/challenge')->assertStatus(429)->assertJson(['error' => 'rate_limited']);
    }

    public function test_429_har_appens_form(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/games/glosis/attest/register', []);
        }
        $this->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(429)
            ->assertJson(['error' => 'rate_limited'])->assertJsonStructure(['retry_after']);
    }

    public function test_csrf_galler_inte_for_appens_origin(): void
    {
        $this->withHeaders(['Origin' => 'https://localhost', 'Referer' => 'https://localhost/'])
            ->postJson('/api/v1/games/glosis/attest/challenge')->assertOk();
        $this->withHeaders(['Origin' => 'https://localhost', 'Referer' => 'https://localhost/'])
            ->postJson('/api/v1/games/glosis/attest/register', [])->assertStatus(422);
    }

    public function test_gratisinstallningar_har_standardvarden_och_kan_andras(): void
    {
        $defaults = new GlosisSettings([]);
        $this->assertFalse($defaults->freeEnabled());
        $this->assertSame(40, $defaults->freeScanDailyLimit());
        $this->assertSame(10, $defaults->freeScanIpDailyLimit());
        $this->assertSame(800, $defaults->freeTtsDailyNewLimit());
        $this->assertSame(300, $defaults->freeTtsIpDailyNewLimit());
        $this->assertSame(20_000_000, $defaults->freeMonthlyBudgetMicroUsd());
        $this->assertNull($defaults->appAttestAppId());
        $this->assertFalse((new GlosisSettings(['free' => ['enabled' => 'true']]))->freeEnabled());

        $set = new GlosisSettings(['free' => ['enabled' => true, 'scan_daily_limit' => 5, 'scan_ip_daily_limit' => 2, 'tts_daily_new_limit' => 50,
            'tts_ip_daily_new_limit' => 20, 'monthly_budget_usd' => 1.5], 'app_attest' => ['team_id' => self::TEAM]]);
        $this->assertTrue($set->freeEnabled());
        $this->assertSame([5, 2, 50, 20, 1_500_000], [$set->freeScanDailyLimit(), $set->freeScanIpDailyLimit(), $set->freeTtsDailyNewLimit(), $set->freeTtsIpDailyNewLimit(), $set->freeMonthlyBudgetMicroUsd()]);
        $this->assertSame(self::APP_ID, $set->appAttestAppId());
        $this->assertSame(1, GlosisSettings::FREE_SCAN_WEEKLY_PER_KEY);
        $this->assertSame(100, GlosisSettings::TTS_DAILY_NEW_PER_KEY);
    }

    public function test_raknaren_flyttas_bara_framat(): void
    {
        $ch = $this->challenge();
        $this->register(self::$fake->attest($ch, self::APP_ID), $ch)->assertCreated();
        $key = AppAttestKey::sole();

        $this->assertTrue($key->advanceSignCount(3));
        $this->assertSame(3, $key->sign_count);
        $this->assertNotNull($key->fresh()->last_used_at);
        $this->assertFalse($key->advanceSignCount(3));
        $this->assertFalse(AppAttestKey::sole()->advanceSignCount(2));
        $this->assertSame(3, AppAttestKey::sole()->sign_count);
        $this->assertSame(16, strlen($key->logHash()));
        $this->assertSame(AppAttestKey::hashForLog($key->key_id), $key->logHash());
    }
}
