<?php

namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppAttest\AppAttestVerifier;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function verify(array $a, ?int $now = null, bool $dev = false, string $challenge = self::CHALLENGE): \App\Services\Glosis\AppAttest\AttestedKey
    {
        return $this->verifier()->verifyAttestation(base64_decode($a['attestation']), $a['rawKeyId'], hash('sha256', $challenge, true), self::APP_ID, $now ?? time(), $dev);
    }

    /**
     * Apples egen vektor med Apples riktiga rot. Exemplet stämmer bara om den
     * råa challengen används som clientDataHash (Apple-forumet tråd 842509);
     * i drift är clientDataHash SHA256(challenge) enligt artikeln.
     */
    public function test_apples_exempel_med_riktiga_roten(): void
    {
        $fx = json_decode(file_get_contents(base_path('tests/Fixtures/glosis-appattest-apple-sample.json')), true);
        $key = (new AppAttestVerifier)->verifyAttestation(
            base64_decode($fx['attestation'], true), base64_decode($fx['keyId'], true),
            $fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'], false);

        $this->assertSame('production', $key->environment);
        $this->assertSame('1', $key->bundleVersion);
        $this->assertSame(1, $key->validationCategory);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $key->publicKeyPem);
    }

    /** Apples vektor ska avvisas när challenge, appId eller tid inte stämmer, så att kontrollerna bevisligen biter på riktiga certifikat. */
    public function test_apples_exempel_avvisas_vid_fel_indata(): void
    {
        $fx = json_decode(file_get_contents(base_path('tests/Fixtures/glosis-appattest-apple-sample.json')), true);
        $att = base64_decode($fx['attestation'], true);
        $keyId = base64_decode($fx['keyId'], true);
        $cases = [
            'nonce' => [hash('sha256', $fx['clientDataHashRaw'], true), $fx['appId'], $fx['verifyAt']],
            'RP ID' => [$fx['clientDataHashRaw'], '1234567890.com.example.annan', $fx['verifyAt']],
            'giltig' => [$fx['clientDataHashRaw'], $fx['appId'], $fx['verifyAt'] + 7 * 86400],
        ];
        foreach ($cases as $reason => [$cdh, $appId, $now]) {
            try {
                (new AppAttestVerifier)->verifyAttestation($att, $keyId, $cdh, $appId, $now, false);
                $this->fail("Godkändes trots fel: {$reason}");
            } catch (InvalidAttestationException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    public function test_avvisar_fel_keyid(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $a['rawKeyId'] = str_repeat('', 32);
        $this->expectException(InvalidAttestationException::class);
        $this->expectExceptionMessageMatches('/keyId/');
        $this->verify($a);
    }

    public function test_godkand_attestering(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $key = $this->verify($a);
        $this->assertSame($a['rawKeyId'], $key->keyId);
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

    public function test_avvisar_annan_challenge(): void
    {
        $a = $this->fake()->attest(self::CHALLENGE, self::APP_ID);
        $this->expectExceptionMessageMatches('/nonce/');
        $this->verify($a, challenge: 'en-annan');
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

    public function test_avvisar_for_stor_eller_trasig_indata(): void
    {
        $this->expectException(InvalidAttestationException::class);
        $this->verifier()->verifyAttestation(str_repeat("\xa0", 20_000), str_repeat("\0", 32), str_repeat("\0", 32), self::APP_ID, time(), false);
    }

    public function test_fel_rotfil_vagrar_starta(): void
    {
        $this->expectException(\RuntimeException::class);
        $tmp = tempnam(sys_get_temp_dir(), 'root');
        file_put_contents($tmp, $this->fake()->rootPem());
        try {
            AppAttestVerifier::fromRootFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
