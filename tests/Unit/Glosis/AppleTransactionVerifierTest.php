<?php

namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppleTransactionVerifier;
use App\Services\Glosis\InvalidProofException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAppleChain;
use Tests\TestCase;

class AppleTransactionVerifierTest extends TestCase
{
    /**
     * Apples riktiga kedja (rot G3 → WWDR G6 → "Prod ECC Mac App Store and
     * iTunes Store Receipt Signing") och tidpunkten den testas vid, tagna ur
     * Apples eget bibliotek: app-store-server-library-python
     * tests/test_x509_verifiction.py (REAL_APPLE_*, EFFECTIVE_DATE).
     */
    private const REAL_APPLE_INTERMEDIATE = 'MIIDFjCCApygAwIBAgIUIsGhRwp0c2nvU4YSycafPTjzbNcwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwSQXBwbGUgUm9vdCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTETMBEGA1UECgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcNMjEwMzE3MjAzNzEwWhcNMzYwMzE5MDAwMDAwWjB1MUQwQgYDVQQDDDtBcHBsZSBXb3JsZHdpZGUgRGV2ZWxvcGVyIFJlbGF0aW9ucyBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTELMAkGA1UECwwCRzYxEzARBgNVBAoMCkFwcGxlIEluYy4xCzAJBgNVBAYTAlVTMHYwEAYHKoZIzj0CAQYFK4EEACIDYgAEbsQKC94PrlWmZXnXgtxzdVJL8T0SGYngDRGpngn3N6PT8JMEb7FDi4bBmPhCnZ3/sq6PF/cGcKXWsL5vOteRhyJ45x3ASP7cOB+aao90fcpxSv/EZFbniAbNgZGhIhpIo4H6MIH3MBIGA1UdEwEB/wQIMAYBAf8CAQAwHwYDVR0jBBgwFoAUu7DeoVgziJqkipnevr3rr9rLJKswRgYIKwYBBQUHAQEEOjA4MDYGCCsGAQUFBzABhipodHRwOi8vb2NzcC5hcHBsZS5jb20vb2NzcDAzLWFwcGxlcm9vdGNhZzMwNwYDVR0fBDAwLjAsoCqgKIYmaHR0cDovL2NybC5hcHBsZS5jb20vYXBwbGVyb290Y2FnMy5jcmwwHQYDVR0OBBYEFD8vlCNR01DJmig97bB85c+lkGKZMA4GA1UdDwEB/wQEAwIBBjAQBgoqhkiG92NkBgIBBAIFADAKBggqhkjOPQQDAwNoADBlAjBAXhSq5IyKogMCPtw490BaB677CaEGJXufQB/EqZGd6CSjiCtOnuMTbXVXmxxcxfkCMQDTSPxarZXvNrkxU3TkUMI33yzvFVVRT4wxWJC994OsdcZ4+RGNsYDyR5gmdr0nDGg=';

    private const REAL_APPLE_LEAF = 'MIIEMTCCA7agAwIBAgIQR8KHzdn554Z/UoradNx9tzAKBggqhkjOPQQDAzB1MUQwQgYDVQQDDDtBcHBsZSBXb3JsZHdpZGUgRGV2ZWxvcGVyIFJlbGF0aW9ucyBDZXJ0aWZpY2F0aW9uIEF1dGhvcml0eTELMAkGA1UECwwCRzYxEzARBgNVBAoMCkFwcGxlIEluYy4xCzAJBgNVBAYTAlVTMB4XDTI1MDkxOTE5NDQ1MVoXDTI3MTAxMzE3NDcyM1owgZIxQDA+BgNVBAMMN1Byb2QgRUNDIE1hYyBBcHAgU3RvcmUgYW5kIGlUdW5lcyBTdG9yZSBSZWNlaXB0IFNpZ25pbmcxLDAqBgNVBAsMI0FwcGxlIFdvcmxkd2lkZSBEZXZlbG9wZXIgUmVsYXRpb25zMRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABNnVvhcv7iT+7Ex5tBMBgrQspHzIsXRi0Yxfek7lv8wEmj/bHiWtNwJqc2BoHzsQiEjP7KFIIKg4Y8y0/nynuAmjggIIMIICBDAMBgNVHRMBAf8EAjAAMB8GA1UdIwQYMBaAFD8vlCNR01DJmig97bB85c+lkGKZMHAGCCsGAQUFBwEBBGQwYjAtBggrBgEFBQcwAoYhaHR0cDovL2NlcnRzLmFwcGxlLmNvbS93d2RyZzYuZGVyMDEGCCsGAQUFBzABhiVodHRwOi8vb2NzcC5hcHBsZS5jb20vb2NzcDAzLXd3ZHJnNjAyMIIBHgYDVR0gBIIBFTCCAREwggENBgoqhkiG92NkBQYBMIH+MIHDBggrBgEFBQcCAjCBtgyBs1JlbGlhbmNlIG9uIHRoaXMgY2VydGlmaWNhdGUgYnkgYW55IHBhcnR5IGFzc3VtZXMgYWNjZXB0YW5jZSBvZiB0aGUgdGhlbiBhcHBsaWNhYmxlIHN0YW5kYXJkIHRlcm1zIGFuZCBjb25kaXRpb25zIG9mIHVzZSwgY2VydGlmaWNhdGUgcG9saWN5IGFuZCBjZXJ0aWZpY2F0aW9uIHByYWN0aWNlIHN0YXRlbWVudHMuMDYGCCsGAQUFBwIBFipodHRwOi8vd3d3LmFwcGxlLmNvbS9jZXJ0aWZpY2F0ZWF1dGhvcml0eS8wHQYDVR0OBBYEFIFioG4wMMVA1ku9zJmGNPAVn3eqMA4GA1UdDwEB/wQEAwIHgDAQBgoqhkiG92NkBgsBBAIFADAKBggqhkjOPQQDAwNpADBmAjEA+qXnREC7hXIWVLsLxznjRpIzPf7VHz9V/CTm8+LJlrQepnmcPvGLNcX6XPnlcgLAAjEA5IjNZKgg5pQ79knF4IbTXdKv8vutIDMXDmjPVT3dGvFtsGRwXOywR2kZCdSrfeot';

    private const REAL_APPLE_EFFECTIVE_DATE = 1761962975;

    private static ?FakeAppleChain $chain = null;

    private function chain(): FakeAppleChain
    {
        return self::$chain ??= new FakeAppleChain;
    }

    private function verifier(?FakeAppleChain $chain = null): AppleTransactionVerifier
    {
        return new AppleTransactionVerifier(($chain ?? $this->chain())->rootDer());
    }

    private function assertRejected(string $jws, ?AppleTransactionVerifier $verifier = null, ?string $reason = null): void
    {
        try {
            ($verifier ?? $this->verifier())->verify($jws);
            $this->fail('Beviset borde ha avvisats');
        } catch (InvalidProofException $e) {
            $this->addToAssertionCount(1);
            if ($reason !== null) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    public function test_valid_sandbox_transaction_is_accepted(): void
    {
        $tx = $this->verifier()->verify($this->chain()->sign(FakeAppleChain::payload()));

        $this->assertSame('2000000111111111', $tx->originalTransactionId);
        $this->assertSame('2000000123456789', $tx->transactionId);
        $this->assertSame('glosis_guld_2026_27', $tx->productId);
        $this->assertSame('Sandbox', $tx->environment);
        $this->assertFalse($tx->isRevoked());
    }

    public function test_valid_production_transaction_is_accepted(): void
    {
        $tx = $this->verifier()->verify($this->chain()->sign(FakeAppleChain::payload(['environment' => 'Production'])));

        $this->assertSame('Production', $tx->environment);
    }

    public function test_revocation_date_is_reported(): void
    {
        $tx = $this->verifier()->verify($this->chain()->sign(FakeAppleChain::payload(['revocationDate' => 1_760_000_000_000])));

        $this->assertTrue($tx->isRevoked());
    }

    public function test_default_constructor_loads_apple_root_and_accepts_real_apple_chain(): void
    {
        $verifier = new AppleTransactionVerifier;

        $leaf = $verifier->verifyCertificateChain(
            [self::REAL_APPLE_LEAF, self::REAL_APPLE_INTERMEDIATE, 'ignoreras'],
            self::REAL_APPLE_EFFECTIVE_DATE,
        );

        $this->assertStringContainsString('Prod ECC Mac App Store', openssl_x509_parse($leaf)['subject']['CN']);
    }

    public function test_real_apple_chain_is_rejected_outside_leaf_validity(): void
    {
        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('leaf');

        // Leaf gäller 2025-09-19 – 2027-10-13.
        (new AppleTransactionVerifier)->verifyCertificateChain(
            [self::REAL_APPLE_LEAF, self::REAL_APPLE_INTERMEDIATE, 'ignoreras'],
            1_700_000_000,
        );
    }

    public function test_committed_root_matches_apples_published_fingerprint(): void
    {
        $der = file_get_contents(resource_path(AppleTransactionVerifier::ROOT_CERT_PATH));

        $this->assertSame(AppleTransactionVerifier::ROOT_SHA256, hash('sha256', $der));
    }

    public function test_apple_root_rejects_a_self_made_chain(): void
    {
        $this->assertRejected($this->chain()->sign(FakeAppleChain::payload()), new AppleTransactionVerifier, 'utfärdat');
    }

    public static function malformedJws(): array
    {
        return [
            'tom' => [''],
            'två delar' => ['abc.def'],
            'fyra delar' => ['a.b.c.d'],
            'ogiltig base64url' => ['@@@.e30.AAAA'],
            'header inte JSON' => [FakeAppleChain::b64url('inte json').'.e30.AAAA'],
            'header är lista' => [FakeAppleChain::b64url('[1,2]').'.e30.AAAA'],
            'för lång' => [str_repeat('a', 20_000)],
        ];
    }

    #[DataProvider('malformedJws')]
    public function test_malformed_jws_is_rejected(string $jws): void
    {
        $this->assertRejected($jws);
    }

    public static function badHeaders(): array
    {
        return [
            'alg none' => [fn (array $x5c) => ['alg' => 'none', 'x5c' => $x5c], 'ES256'],
            'alg HS256' => [fn (array $x5c) => ['alg' => 'HS256', 'x5c' => $x5c], 'ES256'],
            'alg saknas' => [fn (array $x5c) => ['x5c' => $x5c], 'ES256'],
            'x5c saknas' => [fn (array $x5c) => ['alg' => 'ES256'], 'x5c'],
            'x5c två cert' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => array_slice($x5c, 0, 2)], 'x5c'],
            'x5c fyra cert' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => [...$x5c, $x5c[2]]], 'x5c'],
            'x5c icke-sträng' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => [$x5c[0], 42, $x5c[2]]], 'x5c'],
            'x5c skräp' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => ['!!!', $x5c[1], $x5c[2]]], 'läsa'],
            'x5c objekt' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => ['a' => $x5c[0], 'b' => $x5c[1], 'c' => $x5c[2]]], 'x5c'],
            'leaf och intermediate bytta' => [fn (array $x5c) => ['alg' => 'ES256', 'x5c' => [$x5c[1], $x5c[0], $x5c[2]]], 'utfärdat'],
        ];
    }

    #[DataProvider('badHeaders')]
    public function test_bad_header_is_rejected(\Closure $header, string $reason): void
    {
        $chain = $this->chain();

        $this->assertRejected($chain->sign(FakeAppleChain::payload(), $header($chain->x5c())), reason: $reason);
    }

    public function test_intermediate_signed_by_another_root_with_same_name_is_rejected(): void
    {
        // Alla FakeAppleChain-rötter heter likadant: namnkedjan stämmer, signaturen inte.
        $impostor = new FakeAppleChain;

        $this->assertRejected($impostor->sign(FakeAppleChain::payload()), $this->verifier(), 'signerat av roten');
    }

    public function test_leaf_without_apple_oid_is_rejected(): void
    {
        $chain = new FakeAppleChain(['leafOid' => false]);

        $this->assertRejected($chain->sign(FakeAppleChain::payload()), $this->verifier($chain), 'Leaf saknar Apples OID');
    }

    public function test_intermediate_without_apple_oid_is_rejected(): void
    {
        $chain = new FakeAppleChain(['intermediateOid' => false]);

        $this->assertRejected($chain->sign(FakeAppleChain::payload()), $this->verifier($chain), 'Intermediate saknar Apples OID');
    }

    public function test_intermediate_that_is_not_a_ca_is_rejected(): void
    {
        $chain = new FakeAppleChain(['intermediateIsCa' => false]);

        $this->assertRejected($chain->sign(FakeAppleChain::payload()), $this->verifier($chain), 'inte CA');
    }

    public function test_leaf_that_is_a_ca_is_rejected(): void
    {
        $chain = new FakeAppleChain(['leafIsCa' => true]);

        $this->assertRejected($chain->sign(FakeAppleChain::payload()), $this->verifier($chain), 'Leaf är ett CA');
    }

    public function test_expired_leaf_is_rejected(): void
    {
        $chain = new FakeAppleChain(['leafDays' => 1]);
        $verifier = $this->verifier($chain);

        Carbon::setTestNow(now()->addDays(3));
        try {
            $this->assertRejected($chain->sign(FakeAppleChain::payload()), $verifier, 'leaf var inte giltigt');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_signed_before_leaf_was_valid_is_rejected(): void
    {
        $jws = $this->chain()->sign(FakeAppleChain::payload(['signedDate' => (now()->getTimestamp() - 3 * 86400) * 1000]));

        $this->assertRejected($jws, reason: 'inte giltigt vid signedDate');
    }

    public function test_signed_date_in_the_future_is_rejected(): void
    {
        $jws = $this->chain()->sign(FakeAppleChain::payload(['signedDate' => (now()->getTimestamp() + 3600) * 1000]));

        $this->assertRejected($jws, reason: 'framtiden');
    }

    public function test_missing_signed_date_is_rejected(): void
    {
        $payload = FakeAppleChain::payload();
        unset($payload['signedDate']);

        $this->assertRejected($this->chain()->sign($payload), reason: 'signedDate');
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $jws = $this->chain()->sign(FakeAppleChain::payload());
        [$h, , $s] = explode('.', $jws);
        $forged = FakeAppleChain::b64url(json_encode(FakeAppleChain::payload(['productId' => 'glosis_guld_2099_00'])));

        $this->assertRejected("{$h}.{$forged}.{$s}", reason: 'Signaturen');
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        $jws = $this->chain()->sign(FakeAppleChain::payload(), key: FakeAppleChain::ecKey('prime256v1'));

        $this->assertRejected($jws, reason: 'Signaturen');
    }

    public function test_truncated_signature_is_rejected(): void
    {
        [$h, $p] = explode('.', $this->chain()->sign(FakeAppleChain::payload()));

        $this->assertRejected("{$h}.{$p}.".FakeAppleChain::b64url(str_repeat("\x01", 63)), reason: '64 byte');
    }

    public function test_leaf_key_on_other_curve_is_rejected(): void
    {
        $chain = new FakeAppleChain(['leafCurve' => 'secp384r1']);

        $this->assertRejected($chain->sign(FakeAppleChain::payload()), $this->verifier($chain), 'P-256');
    }

    public function test_wrong_bundle_id_is_rejected(): void
    {
        $this->assertRejected($this->chain()->sign(FakeAppleChain::payload(['bundleId' => 'se.computercat.tocco'])), reason: 'bundleId');
    }

    public static function badEnvironments(): array
    {
        return [['Xcode'], ['LocalTesting'], ['sandbox'], [null]];
    }

    #[DataProvider('badEnvironments')]
    public function test_other_environments_are_rejected(?string $environment): void
    {
        $this->assertRejected($this->chain()->sign(FakeAppleChain::payload(['environment' => $environment])), reason: 'environment');
    }

    public static function missingFields(): array
    {
        return [['originalTransactionId'], ['transactionId'], ['productId']];
    }

    #[DataProvider('missingFields')]
    public function test_missing_required_field_is_rejected(string $field): void
    {
        $this->assertRejected($this->chain()->sign(FakeAppleChain::payload([$field => ''])), reason: $field);
    }
}
