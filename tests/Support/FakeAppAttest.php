<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Egen kedja som ser ut som Apples App Attest: rot → intermediate → credCert,
 * där credCert har nonce-tillägget 1.2.840.113635.100.8.2 = SEQUENCE { [1] { OCTET STRING nonce } }.
 * Varje fält kan bytas via $o för att testa avvisningar.
 */
final class FakeAppAttest
{
    public const AAGUID_PRODUCTION = "appattest\0\0\0\0\0\0\0";

    public OpenSSLAsymmetricKey $rootKey;

    public OpenSSLCertificate $root;

    public OpenSSLAsymmetricKey $intKey;

    public OpenSSLCertificate $intermediate;

    public function __construct()
    {
        $this->rootKey = FakeAppleChain::ecKey('secp384r1');
        $this->root = self::sign(['commonName' => 'Fake App Attestation Root CA'], $this->rootKey, null, $this->rootKey, 'v3_ca', 3650);
        $this->intKey = FakeAppleChain::ecKey('secp384r1');
        $this->intermediate = self::sign(['commonName' => 'Fake App Attestation CA 1'], $this->intKey, $this->root, $this->rootKey, 'v3_ca', 3650);
    }

    public function rootPem(): string
    {
        openssl_x509_export($this->root, $pem);

        return $pem;
    }

    /**
     * @param  array{key?: OpenSSLAsymmetricKey, appId?: string, count?: int, aaguid?: string, credentialId?: string, nonce?: string, fmt?: string, leafDays?: int, intermediate?: OpenSSLCertificate, extensions?: array<string, mixed>, x5c?: list<string>}  $o
     * @return array{keyId: string, rawKeyId: string, attestation: string, privateKey: OpenSSLAsymmetricKey}
     */
    public function attest(string $challenge, string $appId, array $o = []): array
    {
        $key = $o['key'] ?? FakeAppleChain::ecKey('prime256v1');
        $point = self::point($key);
        $keyId = hash('sha256', $point, true);
        $authData = self::authData(
            hash('sha256', $o['appId'] ?? $appId, true),
            $o['count'] ?? 0,
            $o['aaguid'] ?? self::AAGUID_PRODUCTION,
            $o['credentialId'] ?? $keyId,
            $point,
            $o['extensions'] ?? ['apple_bundle_version_01' => CborEncoder::text('1'), 'apple_validation_category_01' => "\x04\0\0\0"],
        );
        $nonce = $o['nonce'] ?? hash('sha256', $authData.hash('sha256', $challenge, true), true);
        $leaf = $this->leaf($key, $nonce, $o['leafDays'] ?? 3);
        $x5c = $o['x5c'] ?? [FakeAppleChain::der($leaf), FakeAppleChain::der($o['intermediate'] ?? $this->intermediate)];
        $obj = [
            'fmt' => CborEncoder::text($o['fmt'] ?? 'apple-appattest'),
            'attStmt' => ['x5c' => $x5c, 'receipt' => 'fake-receipt'],
            'authData' => $authData,
        ];

        return ['keyId' => base64_encode($keyId), 'rawKeyId' => $keyId, 'attestation' => base64_encode(CborEncoder::encode($obj)), 'privateKey' => $key];
    }

    /** authData enligt WebAuthn/Apple: rpIdHash, flags, counter, [aaguid, credIdLen, credId, COSE-nyckel], [extensions]. */
    public static function authData(string $rpIdHash, int $count, ?string $aaguid = null, ?string $credentialId = null, ?string $point = null, array $extensions = []): string
    {
        $out = $rpIdHash.chr($aaguid !== null ? 0x40 : 0x00).pack('N', $count);
        if ($aaguid !== null) {
            $out .= $aaguid.pack('n', strlen((string) $credentialId)).$credentialId
                .CborEncoder::encode([1 => 2, 3 => -7, -1 => 1, -2 => substr((string) $point, 1, 32), -3 => substr((string) $point, 33, 32)]);
        }
        if ($extensions !== []) {
            $out .= CborEncoder::encode($extensions);
        }

        return $out;
    }

    /**
     * Som vi antar att appen gör (se planens "Assertionens signatur"): nonce = SHA256(authData ‖ SHA256(clientData)),
     * ECDSA P-256/SHA-256 med nonce som meddelande, DER-signatur. Ändras tillsammans med verifyAssertion om Task 14 visar annat.
     *
     * @param  array{appId?: string, key?: OpenSSLAsymmetricKey, signedClientData?: string}  $o
     */
    public static function assert(OpenSSLAsymmetricKey $key, string $clientData, string $appId, int $count, array $o = []): string
    {
        $authData = self::authData(hash('sha256', $o['appId'] ?? $appId, true), $count);
        $nonce = hash('sha256', $authData.hash('sha256', $o['signedClientData'] ?? $clientData, true), true);
        openssl_sign($nonce, $sig, $o['key'] ?? $key, OPENSSL_ALGO_SHA256);

        return base64_encode(CborEncoder::encode(['signature' => $sig, 'authenticatorData' => $authData]));
    }

    public static function point(OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private function leaf(OpenSSLAsymmetricKey $key, string $nonce, int $days): OpenSSLCertificate
    {
        $ext = '1.2.840.113635.100.8.2 = DER:3024a1220420'.bin2hex($nonce);

        return self::sign(['commonName' => 'fake credCert'], $key, $this->intermediate, $this->intKey, 'v3_leaf', $days, $ext);
    }

    private static function sign(array $dn, OpenSSLAsymmetricKey $key, ?OpenSSLCertificate $issuer, OpenSSLAsymmetricKey $issuerKey, string $section, int $days, string $extra = ''): OpenSSLCertificate
    {
        $cnf = tempnam(sys_get_temp_dir(), 'fakeattest');
        file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n"
            ."[ v3_ca ]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n"
            ."[ v3_leaf ]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\n{$extra}\n");
        try {
            $opt = ['config' => $cnf, 'x509_extensions' => $section, 'digest_alg' => 'sha256'];

            return openssl_csr_sign(openssl_csr_new($dn, $key, $opt), $issuer, $issuerKey, $days, $opt, random_int(1000, PHP_INT_MAX));
        } finally {
            @unlink($cnf);
        }
    }
}
