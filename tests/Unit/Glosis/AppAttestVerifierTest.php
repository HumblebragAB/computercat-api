<?php

namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\AttestedKey;
use App\Services\Glosis\AppAttest\AuthenticatorData;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CborEncoder;
use Tests\Support\FakeAppAttest;
use Tests\TestCase;

class AppAttestVerifierTest extends TestCase
{
    private const APP_ID = 'ABCDE12345.se.computercat.glosis';

    private const CHALLENGE = 'test-challenge';

    private static ?FakeAppAttest $fake = null;

    private function fake(): FakeAppAttest
    {
        return self::$fake ??= new FakeAppAttest;
    }

    private function verifier(): AppAttestVerifier
    {
        return new AppAttestVerifier($this->fake()->rootPem());
    }

    private function verify(array $a, ?int $now = null, bool $dev = false, string $challenge = self::CHALLENGE): AttestedKey
    {
        return $this->verifier()->verifyAttestation(base64_decode($a['attestation']), $a['rawKeyId'], $challenge, self::APP_ID, $now ?? time(), $dev);
    }

    /** @return array<string, mixed> */
    private static function appleSample(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/glosis-appattest-apple-sample.json')), true);
    }

    /**
     * TESTVÄG: Apples exempel stämmer bara om den råa challengen används som
     * clientDataHash (Apple-forumet tråd 842509). Produktions-API:t räknar alltid
     * SHA256(challenge), så kärnan nås här via reflection. Apples exempel har
     * validation category 1, som bara tillåts genom en explicit uppsättning i testet.
     */
    private static function verifyAppleSampleWithRawClientDataHash(string $clientDataHash, string $appId, int $now): AttestedKey
    {
        $fx = self::appleSample();
        $verifier = new AppAttestVerifier(null, productionCategories: [1]);

        return (new ReflectionMethod($verifier, 'verifyWithClientDataHash'))->invoke(
            $verifier, base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true),
            $clientDataHash, $appId, $now, false);
    }

    public function test_apples_exempel_med_riktiga_roten(): void
    {
        $fx = self::appleSample();
        $key = self::verifyAppleSampleWithRawClientDataHash($fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt']);

        $this->assertSame('production', $key->environment);
        $this->assertSame('1', $key->bundleVersion);
        $this->assertSame(1, $key->validationCategory);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $key->publicKeyPem);
    }

    /** Apples vektor ska avvisas när appId, tid eller kategori-policy inte stämmer, så att kontrollerna bevisligen biter på riktiga certifikat. */
    public function test_apples_exempel_avvisas_vid_fel_indata(): void
    {
        $fx = self::appleSample();
        $cases = [
            'RP ID' => fn () => self::verifyAppleSampleWithRawClientDataHash($fx['clientDataHashRaw'], '1234567890.com.example.annan', $fx['verifyAt']),
            'giltig' => fn () => self::verifyAppleSampleWithRawClientDataHash($fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'] + 7 * 86400),
            // Produktions-API:t hashar challengen, vilket Apples exempel inte stämmer med.
            'nonce' => fn () => (new AppAttestVerifier(null, productionCategories: [1]))->verifyAttestation(
                base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true), $fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'], false),
            // Standardpolicyn avvisar kategori 1 (OS-binär).
            'validation category' => fn () => (new ReflectionMethod(AppAttestVerifier::class, 'verifyWithClientDataHash'))->invoke(
                new AppAttestVerifier, base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true),
                $fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'], true),
        ];
        foreach ($cases as $reason => $call) {
            try {
                $call();
                $this->fail("Godkändes trots fel: {$reason}");
            } catch (InvalidAttestationException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    public function test_godkand_attestering(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $key = $this->verify($a);
        $this->assertSame($a['rawKeyId'], $key->keyId);
        $this->assertSame(4, $key->validationCategory);
    }

    public function test_avvisar_fel_keyid(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $a['rawKeyId'] = str_repeat("\x03", 32);
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/keyId/');
        $this->verify($a);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function rejected(): array
    {
        return [
            'fel rpId' => [['appId' => 'ABCDE12345.se.annan.app'], 'RP ID'],
            'räknare inte 0' => [['count' => 1], 'counter'],
            'okänd aaguid' => [['aaguid' => str_repeat("\0", 16)], 'aaguid'],
            'credentialId ≠ keyId' => [['credentialId' => str_repeat("\x01", 32)], 'credentialId'],
            'nonce stämmer inte' => [['nonce' => str_repeat("\x02", 32)], 'nonce'],
            'fel fmt' => [['fmt' => 'packed'], 'fmt'],
            'ett certifikat i x5c' => [['x5c' => ['x']], 'x5c'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_avvisar(array $o, string $reason): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, $o);
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/i');
        $this->verify($a);
    }

    /**
     * Varje kategori ur Apples tabell (validating-apps-that-connect-to-your-server, steg 10) i båda lägena.
     * null = tillägget saknas.
     *
     * @return array<string, array{int|null, bool, bool}>
     */
    public static function categories(): array
    {
        $accepted = ['production' => [2, 4], 'development' => [2, 3, 4]];
        $out = [];
        foreach ([null, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $c) {
            foreach ($accepted as $mode => $ok) {
                $out[($c ?? 'saknas')." i {$mode}"] = [$c, $mode === 'development', in_array($c, $ok, true)];
            }
        }

        return $out;
    }

    #[DataProvider('categories')]
    public function test_validation_category(?int $category, bool $dev, bool $accepted): void
    {
        $ext = ['apple_bundle_version_01' => CborEncoder::text('1')];
        if ($category !== null) {
            $ext['apple_validation_category_01'] = pack('V', $category);
        }
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['extensions' => $ext]);
        if (! $accepted) {
            $this->expectException(InvalidAttestationException::class);
            $this->expectExceptionMessageMatches('/validation category/');
        }
        $this->assertSame($category, $this->verify($a, dev: $dev)->validationCategory);
    }

    /** Artikeln kallar värdet UInt32; ett CBOR-heltal tolkas på samma sätt som Apples 4-byte-form. */
    public function test_validation_category_som_cbor_heltal(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['extensions' => ['apple_validation_category_01' => 2]]);
        $this->assertSame(2, $this->verify($a)->validationCategory);
    }

    public function test_avvisar_annan_challenge(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $this->expectExceptionMessageMatches('/nonce/');
        $this->verify($a, challenge: 'en-annan');
    }

    public function test_avvisar_tom_challenge(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $this->expectExceptionMessageMatches('/challenge/');
        $this->verify($a, challenge: '');
    }

    public function test_avvisar_kedja_fran_annan_rot(): void
    {
        $a = (new FakeAppAttest)->attest(self::CHALLENGE, self::APP_ID);
        $this->expectException(InvalidAttestationException::class);
        $this->verify($a);
    }

    public function test_avvisar_utgangen_credcert(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['leafDays' => 1]);
        $this->expectExceptionMessageMatches('/giltig/');
        $this->verify($a, time() + 3 * 86400);
    }

    public function test_development_nyckel_bara_nar_det_ar_tillatet(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['aaguid' => 'appattestdevelop']);
        $this->assertSame('development', $this->verify($a, dev: true)->environment);
        $this->expectExceptionMessageMatches('/development/');
        $this->verify($a, dev: false);
    }

    public function test_sandbox_aaguid_ar_development(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID, ['aaguid' => 'appattestsandbox']);
        $this->assertSame('development', $this->verify($a, dev: true)->environment);
        $this->expectExceptionMessageMatches('/development/');
        $this->verify($a, dev: false);
    }

    public function test_authdata_utan_at_flagga_avvisas(): void
    {
        $point = FakeAppAttest::point(\Tests\Support\FakeAppleChain::ecKey('prime256v1'));
        $data = FakeAppAttest::authData(str_repeat("\0", 32), 0, FakeAppAttest::AAGUID_PRODUCTION, hash('sha256', $point, true), $point);
        $this->assertSame(0x40, AuthenticatorData::parse($data, attested: true)->flags & 0x40);

        $data[32] = chr(ord($data[32]) & ~0x40);
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/AT-flaggan/');
        AuthenticatorData::parse($data, attested: true);
    }

    public function test_avvisar_for_stor_eller_trasig_indata(): void
    {
        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAttestation(str_repeat("\xa0", 20_000), str_repeat("\0", 32), self::CHALLENGE, self::APP_ID, time(), false);
    }

    public function test_inbaddad_rot_har_apples_fingeravtryck(): void
    {
        $cert = openssl_x509_read(AppAttestVerifier::APPLE_ROOT_PEM);
        $this->assertSame(AppAttestVerifier::ROOT_SHA256, openssl_x509_fingerprint($cert, 'sha256'));
        $this->assertSame('1cb9823ba28ba6ad2d33a006941de2ae4f513ef1d4e831b9f7e0fa7b6242c932', AppAttestVerifier::ROOT_SHA256);
        $this->assertInstanceOf(AppAttestVerifier::class, AppAttestVerifier::fromPinnedPem(AppAttestVerifier::APPLE_ROOT_PEM));
    }

    public function test_fel_rot_vagrar_starta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SHA-256/');
        AppAttestVerifier::fromPinnedPem($this->fake()->rootPem());
    }

    public function test_kategorilistor_maste_vara_heltal(): void
    {
        $this->expectException(RuntimeException::class);
        new AppAttestVerifier(null, productionCategories: ['4']);
    }

    private function attested(): array
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);

        return [$a['privateKey'], $this->verify($a)->publicKeyPem];
    }

    public function test_godkand_assertion_ger_ny_raknare(): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $count = $this->verifier()->verifyAssertion(base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 1)), $cd, $pem, 0, self::APP_ID);
        $this->assertSame(1, $count);
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function badAssertions(): array
    {
        return [
            'räknaren ökar inte' => [[], 5, 'counter'],
            'räknaren lägre än lagrad' => [[], 9, 'counter'],
            'fel rpId' => [['appId' => 'ABCDE12345.se.annan.app'], 0, 'RP ID'],
            'ändrad clientData' => [['signedClientData' => '{"action":"tts","challenge":"annan"}'], 0, 'Signaturen'],
        ];
    }

    #[DataProvider('badAssertions')]
    public function test_avvisar_assertion(array $o, int $stored, string $reason): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $assertion = base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 5, $o));
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');
        $this->verifier()->verifyAssertion($assertion, $cd, $pem, $stored, self::APP_ID);
    }

    public function test_avvisar_assertion_signerad_med_annan_nyckel(): void
    {
        [, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $other = \Tests\Support\FakeAppleChain::ecKey('prime256v1');
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/Signaturen/');
        $this->verifier()->verifyAssertion(base64_decode(FakeAppAttest::assert($other, $cd, self::APP_ID, 1)), $cd, $pem, 0, self::APP_ID);
    }

    public function test_avvisar_assertion_med_fel_form(): void
    {
        [, $pem] = $this->attested();
        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAssertion(CborEncoder::encode(['signature' => 'x']), '{}', $pem, 0, self::APP_ID);
    }

    /** @return array<string, array{callable(string, string): string, string}> */
    public static function malformedAssertions(): array
    {
        $good = fn (string $sig, string $auth) => CborEncoder::encode(['signature' => $sig, 'authenticatorData' => $auth]);

        return [
            'tom' => [fn () => '', 'saknas'],
            'för stor' => [fn () => str_repeat('a', 2049), 'för stor'],
            'avkapad' => [fn (string $sig, string $auth) => substr($good($sig, $auth), 0, -10), 'CBOR: strängen är längre än datan'],
            'trasig DER-signatur' => [fn (string $sig, string $auth) => $good(substr($sig, 0, 20).str_repeat("\xff", 10), $auth), 'Signaturen'],
            'signatur med skräp efter' => [fn (string $sig, string $auth) => $good($sig."\0", $auth), 'Signaturen'],
            'tom signatur' => [fn (string $sig, string $auth) => $good('', $auth), 'fel form'],
            'extra nyckel' => [fn (string $sig, string $auth) => CborEncoder::encode(['signature' => $sig, 'authenticatorData' => $auth, 'x' => 'y']), 'fel form'],
            'signatur som heltal' => [fn (string $sig, string $auth) => CborEncoder::encode(['signature' => 5, 'authenticatorData' => $auth]), 'fel form'],
            'authData för kort' => [fn (string $sig, string $auth) => $good($sig, substr($auth, 0, 36)), 'längd'],
            'authData för lång' => [fn (string $sig, string $auth) => $good($sig, $auth."\0"), 'längd'],
            'bara en CBOR-sträng' => [fn () => CborEncoder::encode('abc'), 'fel form'],
        ];
    }

    #[DataProvider('malformedAssertions')]
    public function test_avvisar_trasiga_assertioner(callable $build, string $reason): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $decoded = \App\Services\Glosis\AppAttest\Cbor::decode(base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 1)));
        $assertion = $build($decoded['signature'], $decoded['authenticatorData']);

        try {
            $this->verifier()->verifyAssertion($assertion, $cd, $pem, 0, self::APP_ID);
            $this->fail('Assertionen borde ha avvisats');
        } catch (InvalidAttestationException $e) {
            $this->assertMatchesRegularExpression('/'.preg_quote($reason, '/').'/', $e->getMessage());
        }
    }

    public function test_assertion_kan_inte_spelas_upp_igen(): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $assertion = base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 3));
        $count = $this->verifier()->verifyAssertion($assertion, $cd, $pem, 2, self::APP_ID);
        $this->assertSame(3, $count);

        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAssertion($assertion, $cd, $pem, $count, self::APP_ID);
    }

    public function test_avvisar_trasig_publik_nyckel(): void
    {
        [$priv] = $this->attested();
        $cd = '{}';
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/P-256/');
        $this->verifier()->verifyAssertion(base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 1)), $cd, 'inte en nyckel', 0, self::APP_ID);
    }

    public function test_avvisar_publik_nyckel_som_inte_ar_p256(): void
    {
        [$priv] = $this->attested();
        $cd = '{}';
        $assertion = base64_decode(FakeAppAttest::assert($priv, $cd, self::APP_ID, 1));
        $p384 = openssl_pkey_get_details(\Tests\Support\FakeAppleChain::ecKey('secp384r1'))['key'];
        $rsa = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]))['key'];
        foreach ([$p384, $rsa] as $pem) {
            try {
                $this->verifier()->verifyAssertion($assertion, $cd, $pem, 0, self::APP_ID);
                $this->fail('Nyckeln borde ha avvisats');
            } catch (InvalidAttestationException $e) {
                $this->assertStringContainsString('P-256', $e->getMessage());
            }
        }
    }

    /**
     * Binder dubbel hashning (se ASSUMED-markören i verifyAssertion, Task 14): en signatur över
     * authData ‖ SHA256(clientData) med enkel hashning ska avvisas. Byter enhetsprovet schema
     * ska detta test ändras medvetet tillsammans med verifyAssertion och FakeAppAttest::assert.
     */
    public function test_enkel_hashning_avvisas_tills_enhetsprovet_visar_annat(): void
    {
        [$priv, $pem] = $this->attested();
        $cd = '{"action":"tts","challenge":"c1"}';
        $authData = FakeAppAttest::authData(hash('sha256', self::APP_ID, true), 1);
        openssl_sign($authData.hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);
        $assertion = CborEncoder::encode(['signature' => $sig, 'authenticatorData' => $authData]);

        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/Signaturen/');
        $this->verifier()->verifyAssertion($assertion, $cd, $pem, 0, self::APP_ID);
    }
}
