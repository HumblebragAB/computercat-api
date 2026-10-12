<?php

namespace Tests\Feature\Glosis;

use App\Models\AppAttestKey;
use App\Models\Game;
use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestChallenges;
use App\Services\Glosis\FreeGate;
use App\Services\Glosis\GlosisCaller;
use App\Services\Glosis\GlosisRejection;
use App\Services\Glosis\GuldGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\GlosisAttestTest as A;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class FreeGateTest extends TestCase
{
    use RefreshDatabase;

    private static ?FakeAppAttest $fake = null;

    private Game $game;

    private AppAttestKey $key;

    private \OpenSSLAsymmetricKey $priv;

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake ??= new FakeAppAttest;
        $this->app->instance(AppAttestVerifier::class, new AppAttestVerifier(self::$fake->rootPem()));
        $this->game = Game::where('slug', 'glosis')->firstOrFail();
        $this->game->update(['settings' => array_merge($this->game->settings ?? [], ['free' => ['enabled' => true], 'app_attest' => ['team_id' => A::TEAM]])]);
        [$this->key, $this->priv] = self::registeredKey($this->game, self::$fake);
    }

    /** Registrerar en nyckel via verifieraren (som controllern gör). @return array{AppAttestKey, \OpenSSLAsymmetricKey} */
    public static function registeredKey(Game $game, FakeAppAttest $fake): array
    {
        $a = $fake->attest('ch', A::APP_ID);
        $att = app(AppAttestVerifier::class)->verifyAttestation(base64_decode($a['attestation']), $a['rawKeyId'], 'ch', A::APP_ID, time(), false);
        $key = AppAttestKey::create(['game_id' => $game->id, 'key_id' => $a['keyId'], 'public_key' => $att->publicKeyPem, 'sign_count' => 0, 'environment' => 'production']);

        return [$key, $a['privateKey']];
    }

    /** @return array{keyId: string, assertion: string, clientData: string} */
    public static function credential(AppAttestKey $key, \OpenSSLAsymmetricKey $priv, string $action, array $fields, int $count, ?string $challenge = null): array
    {
        $cd = json_encode($fields + ['action' => $action, 'challenge' => $challenge ?? app(AttestChallenges::class)->issue()]);

        return ['keyId' => $key->key_id, 'assertion' => FakeAppAttest::assert($priv, $cd, A::APP_ID, $count), 'clientData' => $cd];
    }

    private function gate(): FreeGate
    {
        return app(FreeGate::class);
    }

    public function test_godkand_forfragan_ger_gratis_caller(): void
    {
        $req = $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', ['words' => ['dog']], 1), $this->game, GuldGate::FEATURE_TTS, '1.2.3.4');
        $this->assertSame(GlosisCaller::FREE, $req->caller->tier);
        $this->assertSame($this->key->id, $req->caller->attestKeyId);
        $this->assertSame(['dog'], $req->clientData['words']);
        $this->assertSame(1, $this->key->fresh()->sign_count);
    }

    public function test_samma_assertion_tva_ganger_nekas(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null);
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_ny_challenge_men_gammal_raknare_nekas(): void
    {
        $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 5), $this->game, GuldGate::FEATURE_TTS, null);
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 5), $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_paddad_challenge_nekas_och_raknaren_rors_inte(): void
    {
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 1, str_repeat('b', 54)), $this->game, GuldGate::FEATURE_TTS, null));
        $this->assertSame(0, $this->key->fresh()->sign_count);
    }

    public function test_utgangen_challenge_nekas(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->travel(301)->seconds();
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_assertion_for_annan_funktion_nekas(): void
    {
        $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'scan', [], 1), $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_okand_nyckel_ger_401(): void
    {
        $cred = self::credential($this->key, $this->priv, 'tts', [], 1);
        $this->assertRejected(401, 'unknown_key', fn () => $this->gate()->verify(['keyId' => base64_encode(str_repeat("\x07", 32))] + $cred, $this->game, GuldGate::FEATURE_TTS, null));
    }

    public function test_fel_form_ger_422(): void
    {
        foreach ([null, 'x', [], ['keyId' => 'a', 'assertion' => 'b', 'clientData' => 'c'], ['keyId' => $this->key->key_id, 'assertion' => 'AA==', 'clientData' => str_repeat('a', 9000)]] as $bad) {
            $this->assertRejected(422, 'invalid_attest', fn () => $this->gate()->verify($bad, $this->game, GuldGate::FEATURE_TTS, null));
        }
    }

    public function test_avstangd_flagga_ger_503(): void
    {
        $this->game->update(['settings' => array_merge($this->game->settings, ['free' => ['enabled' => false]])]);
        $this->assertRejected(503, 'free_unavailable', fn () => $this->gate()->verify(self::credential($this->key, $this->priv, 'tts', [], 1), $this->game, GuldGate::FEATURE_TTS, null));
    }

    private function assertRejected(int $status, string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Ingen avvisning');
        } catch (GlosisRejection $e) {
            $this->assertSame([$status, $code], [$e->status, $e->error]);
            $this->assertStringNotContainsString('—', $e->userMessage);
        }
    }
}
