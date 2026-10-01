<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Bygger en egen CA-kedja som ser ut som Apples (rot → intermediate med
 * 1.2.840.113635.100.6.2.1 → leaf med 1.2.840.113635.100.6.11.1) och signerar
 * JWS med leaf-nyckeln. Varje avvikelse kan slås på för att testa avvisningar.
 */
final class FakeAppleChain
{
    public OpenSSLAsymmetricKey $rootKey;

    public OpenSSLCertificate $root;

    public OpenSSLAsymmetricKey $intermediateKey;

    public OpenSSLCertificate $intermediate;

    public OpenSSLAsymmetricKey $leafKey;

    public OpenSSLCertificate $leaf;

    /**
     * @param  array{leafOid?: bool, intermediateOid?: bool, leafDays?: int, leafCurve?: string, leafIsCa?: bool, intermediateIsCa?: bool}  $options
     */
    public function __construct(array $options = [])
    {
        $cnf = tempnam(sys_get_temp_dir(), 'fakeapple');
        $intOid = ($options['intermediateOid'] ?? true) ? "1.2.840.113635.100.6.2.1 = ASN1:NULL\n" : '';
        $leafOid = ($options['leafOid'] ?? true) ? "1.2.840.113635.100.6.11.1 = ASN1:NULL\n" : '';
        $intCa = ($options['intermediateIsCa'] ?? true) ? 'critical,CA:TRUE,pathlen:0' : 'critical,CA:FALSE';
        $leafCa = ($options['leafIsCa'] ?? false) ? 'critical,CA:TRUE' : 'critical,CA:FALSE';
        file_put_contents($cnf, <<<CNF
[ req ]
distinguished_name = dn
[ dn ]
[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[ v3_int ]
basicConstraints = {$intCa}
keyUsage = critical,keyCertSign,cRLSign
{$intOid}
[ v3_leaf ]
basicConstraints = {$leafCa}
keyUsage = critical,digitalSignature
{$leafOid}
CNF);

        try {
            $opt = fn (string $section) => ['config' => $cnf, 'x509_extensions' => $section, 'digest_alg' => 'sha256'];

            $this->rootKey = self::ecKey('prime256v1');
            $this->root = openssl_csr_sign(openssl_csr_new(['commonName' => 'Fake Apple Root'], $this->rootKey, $opt('v3_ca')), null, $this->rootKey, 3650, $opt('v3_ca'), 1);

            $this->intermediateKey = self::ecKey('prime256v1');
            $this->intermediate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Fake WWDR'], $this->intermediateKey, $opt('v3_int')), $this->root, $this->rootKey, 3650, $opt('v3_int'), 2);

            $this->leafKey = self::ecKey($options['leafCurve'] ?? 'prime256v1');
            $this->leaf = openssl_csr_sign(openssl_csr_new(['commonName' => 'Fake Receipt Signing'], $this->leafKey, $opt('v3_leaf')), $this->intermediate, $this->intermediateKey, $options['leafDays'] ?? 365, $opt('v3_leaf'), 3);
        } finally {
            @unlink($cnf);
        }
    }

    public static function ecKey(string $curve): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    }

    public static function der(OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);

        return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
    }

    public function rootDer(): string
    {
        return self::der($this->root);
    }

    /** @return list<string> x5c som i Apples header: leaf, intermediate, rot (vanlig base64). */
    public function x5c(): array
    {
        return array_map(fn ($c) => base64_encode(self::der($c)), [$this->leaf, $this->intermediate, $this->root]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $header
     */
    public function sign(array $payload, ?array $header = null, ?OpenSSLAsymmetricKey $key = null): string
    {
        $header ??= ['alg' => 'ES256', 'x5c' => $this->x5c()];
        $signingInput = self::b64url(json_encode($header)).'.'.self::b64url(json_encode($payload));
        openssl_sign($signingInput, $der, $key ?? $this->leafKey, OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::b64url(self::derToRaw($der));
    }

    /** @return array<string, mixed> */
    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'transactionId' => '2000000123456789',
            'originalTransactionId' => '2000000111111111',
            'bundleId' => 'se.computercat.glosis',
            'productId' => 'glosis_guld_2026_27',
            'purchaseDate' => (now()->getTimestamp() - 86400) * 1000,
            'originalPurchaseDate' => (now()->getTimestamp() - 86400) * 1000,
            'quantity' => 1,
            'type' => 'Non-Consumable',
            'inAppOwnershipType' => 'PURCHASED',
            'signedDate' => now()->getTimestamp() * 1000,
            'environment' => 'Sandbox',
            'transactionReason' => 'PURCHASE',
            'storefront' => 'SWE',
            'storefrontId' => '143456',
            'price' => 99000,
            'currency' => 'SEK',
        ], $overrides);
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** DER-signatur från OpenSSL till JWS-formatet r||s (2 × 32 byte). */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }
}
