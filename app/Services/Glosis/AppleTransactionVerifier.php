<?php

namespace App\Services\Glosis;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Verifierar en StoreKit 2-transaktion (JWSTransaction) offline, utan anrop
 * till Apple.
 *
 * Algoritmen följer Apples App Store Server Library (SignedDataVerifier,
 * offline-läget): https://github.com/apple/app-store-server-library-python
 * appstoreserverlibrary/signed_data_verifier.py
 *
 *  1. Header: alg måste vara ES256 och x5c en kedja med exakt tre certifikat.
 *  2. Kedjan: x5c[0] (leaf) signerat av x5c[1] (intermediate) som är signerat
 *     av vår betrodda Apple Root CA - G3. x5c[2] i headern används inte, vi
 *     litar bara på roten vi själva har (som Apples bibliotek).
 *  3. Apples OID:er: leaf 1.2.840.113635.100.6.11.1, intermediate
 *     1.2.840.113635.100.6.2.1.
 *  4. Giltighetstid för alla tre certifikaten, räknad vid payloadens
 *     signedDate (Apples offline-läge gör likadant).
 *  5. ES256-signaturen över "header.payload" med leaf-nyckeln. JWS lagrar
 *     signaturen som rå r||s (RFC 7518 3.4); OpenSSL vill ha DER.
 *  6. Payload: bundleId, environment Sandbox eller Production.
 *
 * Format: https://developer.apple.com/documentation/appstoreserverapi/jwstransaction
 */
final class AppleTransactionVerifier
{
    public const BUNDLE_ID = 'se.computercat.glosis';

    public const ROOT_CERT_PATH = 'certs/AppleRootCA-G3.cer';

    /**
     * SHA-256 för Apple Root CA - G3 enligt Apples egen lista:
     * https://support.apple.com/en-us/126047 (63 34 3A BF … 3E 91 79).
     * Certifikatet hämtat från https://www.apple.com/certificateauthority/AppleRootCA-G3.cer
     */
    public const ROOT_SHA256 = '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179';

    public const LEAF_OID = '1.2.840.113635.100.6.11.1';

    public const INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    public const ENVIRONMENTS = ['Sandbox', 'Production'];

    /** En riktig JWSTransaction är några kB. Taket skyddar parsningen. */
    private const MAX_JWS_LENGTH = 16_384;

    /** Tillåten klockskillnad för signedDate framåt i tiden. */
    private const MAX_FUTURE_SKEW_SECONDS = 300;

    private readonly OpenSSLCertificate $root;

    /**
     * @param  string|null  $rootCertDer  DER-kodat rotcertifikat. Null = Apples
     *                                    rot från resources/, kontrollerad mot ROOT_SHA256.
     *                                    Tester skickar in en egen rot.
     */
    public function __construct(?string $rootCertDer = null, private readonly string $bundleId = self::BUNDLE_ID)
    {
        if ($rootCertDer === null) {
            $rootCertDer = @file_get_contents(resource_path(self::ROOT_CERT_PATH));
            if ($rootCertDer === false) {
                throw new RuntimeException('Apple Root CA - G3 saknas i resources/'.self::ROOT_CERT_PATH);
            }
            if (! hash_equals(self::ROOT_SHA256, hash('sha256', $rootCertDer))) {
                throw new RuntimeException('Apple Root CA - G3 har fel SHA-256, filen är utbytt eller trasig');
            }
        }

        $root = self::certFromDer($rootCertDer);
        if ($root === null) {
            throw new RuntimeException('Rotcertifikatet gick inte att läsa');
        }
        $this->root = $root;
    }

    /**
     * @throws InvalidProofException
     */
    public function verify(string $jws, ?int $now = null): VerifiedTransaction
    {
        $now ??= now()->getTimestamp();

        if ($jws === '' || strlen($jws) > self::MAX_JWS_LENGTH) {
            throw new InvalidProofException('JWS saknas eller är för lång');
        }

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new InvalidProofException('JWS har inte tre delar');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = self::jsonObject(self::base64UrlDecode($headerB64), 'header');
        $payload = self::jsonObject(self::base64UrlDecode($payloadB64), 'payload');
        $signature = self::base64UrlDecode($signatureB64);

        // 1. Header
        if (($header['alg'] ?? null) !== 'ES256') {
            throw new InvalidProofException('alg är inte ES256');
        }
        // Tidpunkten kedjan ska vara giltig vid (Apples offline-läge).
        $signedDate = $payload['signedDate'] ?? null;
        if (! is_int($signedDate) || $signedDate <= 0) {
            throw new InvalidProofException('signedDate saknas');
        }
        $effective = intdiv($signedDate, 1000);
        if ($effective > $now + self::MAX_FUTURE_SKEW_SECONDS) {
            throw new InvalidProofException('signedDate ligger i framtiden');
        }

        // 2–4. Kedjan
        $leaf = $this->verifyCertificateChain($header['x5c'] ?? null, $effective);

        // 5. Signaturen
        $leafKey = openssl_pkey_get_public($leaf);
        if (! $leafKey instanceof OpenSSLAsymmetricKey) {
            throw new InvalidProofException('Leaf-nyckeln går inte att läsa');
        }
        $details = openssl_pkey_get_details($leafKey);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidProofException('Leaf-nyckeln är inte P-256');
        }
        if (strlen($signature) !== 64) {
            throw new InvalidProofException('ES256-signaturen är inte 64 byte');
        }
        $ok = openssl_verify($headerB64.'.'.$payloadB64, self::rawSignatureToDer($signature), $leafKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new InvalidProofException('Signaturen stämmer inte');
        }

        // 6. Payload
        if (($payload['bundleId'] ?? null) !== $this->bundleId) {
            throw new InvalidProofException('Fel bundleId');
        }
        $environment = $payload['environment'] ?? null;
        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidProofException('Okänd environment');
        }
        foreach (['originalTransactionId', 'transactionId', 'productId'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw new InvalidProofException("{$field} saknas");
            }
        }
        $revocationDate = $payload['revocationDate'] ?? null;
        if ($revocationDate !== null && ! is_int($revocationDate)) {
            throw new InvalidProofException('revocationDate har fel typ');
        }

        return new VerifiedTransaction(
            originalTransactionId: $payload['originalTransactionId'],
            transactionId: $payload['transactionId'],
            productId: $payload['productId'],
            environment: $environment,
            revocationDate: $revocationDate,
            purchaseDate: is_int($payload['purchaseDate'] ?? null) ? $payload['purchaseDate'] : null,
        );
    }

    /**
     * Steg 2–4. Publik för att kunna testas mot Apples riktiga kedja.
     *
     * @return OpenSSLCertificate leaf-certifikatet, betrott
     *
     * @throws InvalidProofException
     */
    public function verifyCertificateChain(mixed $x5c, int $at): OpenSSLCertificate
    {
        if (! is_array($x5c) || count($x5c) !== 3 || ! array_is_list($x5c)) {
            throw new InvalidProofException('x5c är inte en kedja med tre certifikat');
        }
        foreach ($x5c as $entry) {
            if (! is_string($entry)) {
                throw new InvalidProofException('x5c innehåller annat än strängar');
            }
        }

        // x5c använder vanlig base64 (RFC 7515 4.1.6), inte base64url.
        $leaf = self::certFromDer(base64_decode($x5c[0], true) ?: '');
        $intermediate = self::certFromDer(base64_decode($x5c[1], true) ?: '');
        if ($leaf === null || $intermediate === null) {
            throw new InvalidProofException('x5c-certifikat går inte att läsa');
        }

        $rootInfo = openssl_x509_parse($this->root);
        $intInfo = openssl_x509_parse($intermediate);
        $leafInfo = openssl_x509_parse($leaf);
        if (! is_array($rootInfo) || ! is_array($intInfo) || ! is_array($leafInfo)) {
            throw new InvalidProofException('Certifikat går inte att tolka');
        }

        // Namnkedjan
        if (($intInfo['issuer'] ?? null) !== ($rootInfo['subject'] ?? false)) {
            throw new InvalidProofException('Intermediate är inte utfärdat av roten');
        }
        if (($leafInfo['issuer'] ?? null) !== ($intInfo['subject'] ?? false)) {
            throw new InvalidProofException('Leaf är inte utfärdat av intermediate');
        }

        // Signaturerna i kedjan
        $rootKey = openssl_pkey_get_public($this->root);
        $intKey = openssl_pkey_get_public($intermediate);
        if (! $rootKey instanceof OpenSSLAsymmetricKey || ! $intKey instanceof OpenSSLAsymmetricKey) {
            throw new InvalidProofException('Nyckel i kedjan går inte att läsa');
        }
        if (openssl_x509_verify($intermediate, $rootKey) !== 1) {
            throw new InvalidProofException('Intermediate är inte signerat av roten');
        }
        if (openssl_x509_verify($leaf, $intKey) !== 1) {
            throw new InvalidProofException('Leaf är inte signerat av intermediate');
        }

        // Bara CA-certifikat får utfärda (X509_STRICT i Apples bibliotek).
        if (! self::isCa($rootInfo) || ! self::isCa($intInfo)) {
            throw new InvalidProofException('Utfärdare i kedjan är inte CA');
        }
        if (self::isCa($leafInfo)) {
            throw new InvalidProofException('Leaf är ett CA-certifikat');
        }

        // Apples OID:er
        if (! array_key_exists(self::LEAF_OID, $leafInfo['extensions'] ?? [])) {
            throw new InvalidProofException('Leaf saknar Apples OID');
        }
        if (! array_key_exists(self::INTERMEDIATE_OID, $intInfo['extensions'] ?? [])) {
            throw new InvalidProofException('Intermediate saknar Apples OID');
        }

        // Giltighetstid
        foreach (['root' => $rootInfo, 'intermediate' => $intInfo, 'leaf' => $leafInfo] as $name => $info) {
            $from = $info['validFrom_time_t'] ?? null;
            $to = $info['validTo_time_t'] ?? null;
            if (! is_int($from) || ! is_int($to) || $at < $from || $at > $to) {
                throw new InvalidProofException("Certifikatet {$name} var inte giltigt vid signedDate");
            }
        }

        return $leaf;
    }

    /** @param array<string, mixed> $info */
    private static function isCa(array $info): bool
    {
        $bc = $info['extensions']['basicConstraints'] ?? '';

        return is_string($bc) && preg_match('/(^|,\s*)CA:TRUE(\s*,|$)/', $bc) === 1;
    }

    private static function certFromDer(string $der): ?OpenSSLCertificate
    {
        if ($der === '') {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
        $cert = @openssl_x509_read($pem);

        return $cert instanceof OpenSSLCertificate ? $cert : null;
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+\z/', $value) !== 1) {
            throw new InvalidProofException('Ogiltig base64url');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidProofException('Ogiltig base64url');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function jsonObject(string $json, string $what): array
    {
        $data = json_decode($json, true, 16);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidProofException("JWS {$what} är inte ett JSON-objekt");
        }

        return $data;
    }

    /** Rå r||s (2 × 32 byte) till DER: SEQUENCE { INTEGER r, INTEGER s }. */
    private static function rawSignatureToDer(string $raw): string
    {
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };

        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
