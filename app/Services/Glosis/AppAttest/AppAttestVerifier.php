<?php

namespace App\Services\Glosis\AppAttest;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Apple App Attest på servern, enligt "Validating apps that connect to your
 * server" (developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server).
 * Stegnumren i kommentarerna är artikelns. Testat mot Apples exempelvektor
 * (attestation-object-validation-guide) med Apples riktiga rot.
 */
final class AppAttestVerifier
{
    /**
     * https://www.apple.com/certificateauthority/Apple_App_Attestation_Root_CA.pem, hämtad 2026-10-12.
     * Inbäddad i koden (publikt certifikat, ingen hemlighet) och kontrollerad mot ROOT_SHA256 vid varje konstruktion.
     */
    public const APPLE_ROOT_PEM = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICITCCAaegAwIBAgIQC/O+DvHN0uD7jG5yH2IXmDAKBggqhkjOPQQDAzBSMSYw
JAYDVQQDDB1BcHBsZSBBcHAgQXR0ZXN0YXRpb24gUm9vdCBDQTETMBEGA1UECgwK
QXBwbGUgSW5jLjETMBEGA1UECAwKQ2FsaWZvcm5pYTAeFw0yMDAzMTgxODMyNTNa
Fw00NTAzMTUwMDAwMDBaMFIxJjAkBgNVBAMMHUFwcGxlIEFwcCBBdHRlc3RhdGlv
biBSb290IENBMRMwEQYDVQQKDApBcHBsZSBJbmMuMRMwEQYDVQQIDApDYWxpZm9y
bmlhMHYwEAYHKoZIzj0CAQYFK4EEACIDYgAERTHhmLW07ATaFQIEVwTtT4dyctdh
NbJhFs/Ii2FdCgAHGbpphY3+d8qjuDngIN3WVhQUBHAoMeQ/cLiP1sOUtgjqK9au
Yen1mMEvRq9Sk3Jm5X8U62H+xTD3FE9TgS41o0IwQDAPBgNVHRMBAf8EBTADAQH/
MB0GA1UdDgQWBBSskRBTM72+aEH/pwyp5frq5eWKoTAOBgNVHQ8BAf8EBAMCAQYw
CgYIKoZIzj0EAwMDaAAwZQIwQgFGnByvsiVbpTKwSga0kP0e8EeDS4+sQmTvb7vn
53O5+FRXgeLhpJ06ysC5PrOyAjEAp5U4xDgEgllF7En3VcE3iexZZtKeYnpqtijV
oyFraWVIyd/dganmrduC1bmTBGwD
-----END CERTIFICATE-----
PEM;

    /** SHA-256 (DER) för APPLE_ROOT_PEM. */
    public const ROOT_SHA256 = '1cb9823ba28ba6ad2d33a006941de2ae4f513ef1d4e831b9f7e0fa7b6242c932';

    public const NONCE_OID = '1.2.840.113635.100.8.2';

    /** DER: SEQUENCE(36) { [1](34) { OCTET STRING(32) nonce } }. */
    private const NONCE_PREFIX = "\x30\x24\xa1\x22\x04\x20";

    public const AAGUID_PRODUCTION = "appattest\0\0\0\0\0\0\0";

    /**
     * "appattestdevelop": validating-apps-that-connect-to-your-server, steg 8.
     * "appattestsandbox": preparing-to-use-the-app-attest-service ("When sending a sandbox-generated
     * attestation object to your server ... expect to find the value appattestsandbox in the
     * authenticator data's aaguid field"). Båda räknas som development.
     */
    public const AAGUIDS_DEVELOPMENT = ['appattestdevelop', 'appattestsandbox'];

    private const MAX_ATTESTATION_BYTES = 16_384;

    private const MAX_ASSERTION_BYTES = 2_048;

    private const ASSERTION_AUTHDATA_BYTES = 37;

    /**
     * apple_validation_category_01 (validating-apps-that-connect-to-your-server, steg 10):
     * 2 = TestFlight, 4 = App Store. 3 = development-signerad, bara när development är tillåtet.
     * Allt annat (saknas, 0 ogiltig, 1 OS-binär, 5 Enterprise/ad hoc, 6 Developer ID,
     * 7–9 olämpliga, 10 övrigt, okända) avvisas.
     */
    public const PRODUCTION_CATEGORIES = [2, 4];

    public const DEVELOPMENT_CATEGORIES = [3];

    private readonly OpenSSLCertificate $root;

    /** @var list<int> */
    private readonly array $productionCategories;

    /** @var list<int> */
    private readonly array $developmentCategories;

    /**
     * @param  string|null  $rootPem  null = Apples inbäddade rot (pinnad). Annan rot bara i tester.
     * @param  list<int>  $productionCategories  tillåtna validation categories alltid
     * @param  list<int>  $developmentCategories  tillåtna dessutom när $allowDevelopment
     */
    public function __construct(?string $rootPem = null, array $productionCategories = self::PRODUCTION_CATEGORIES, array $developmentCategories = self::DEVELOPMENT_CATEGORIES)
    {
        $root = $rootPem === null ? self::pinnedRoot(self::APPLE_ROOT_PEM) : @openssl_x509_read($rootPem);
        if (! $root instanceof OpenSSLCertificate) {
            throw new RuntimeException('App Attest-roten går inte att läsa');
        }
        foreach ([$productionCategories, $developmentCategories] as $set) {
            if (! array_is_list($set) || array_filter($set, fn ($c) => ! is_int($c)) !== []) {
                throw new RuntimeException('Validation categories måste vara en lista med heltal');
            }
        }
        $this->root = $root;
        $this->productionCategories = $productionCategories;
        $this->developmentCategories = $developmentCategories;
    }

    /** Verifier med en rot som måste ha Apples fingeravtryck (för test av pinningen). */
    public static function fromPinnedPem(string $pem): self
    {
        $root = self::pinnedRoot($pem);
        openssl_x509_export($root, $out);

        return new self($out);
    }

    private static function pinnedRoot(string $pem): OpenSSLCertificate
    {
        $cert = @openssl_x509_read($pem);
        if (! $cert instanceof OpenSSLCertificate) {
            throw new RuntimeException('Apple App Attestation Root CA går inte att läsa');
        }
        if (! hash_equals(self::ROOT_SHA256, (string) openssl_x509_fingerprint($cert, 'sha256'))) {
            throw new RuntimeException('Apple App Attestation Root CA har fel SHA-256');
        }

        return $cert;
    }

    /**
     * @param  string  $attestation  rått attesteringsobjekt (CBOR)
     * @param  string  $keyId  rå keyId (32 byte) från appen
     * @param  string  $challenge  den engångs-challenge servern gav ut; clientDataHash = SHA256(challenge) räknas här
     * @param  string  $appId  Team ID + "." + bundle-id
     *
     * @throws InvalidAttestationException
     */
    public function verifyAttestation(string $attestation, string $keyId, string $challenge, string $appId, int $now, bool $allowDevelopment): AttestedKey
    {
        if ($challenge === '') {
            throw new InvalidAttestationException('challenge saknas');
        }

        return $this->verifyWithClientDataHash($attestation, $keyId, hash('sha256', $challenge, true), $appId, $now, $allowDevelopment);
    }

    /**
     * Kärnan. Privat: i drift är clientDataHash alltid SHA256(challenge) via verifyAttestation.
     * Bara testet för Apples exempelvektor (som kräver den råa challengen, forumtråd 842509)
     * anropar den direkt via reflection.
     */
    private function verifyWithClientDataHash(string $attestation, string $keyId, string $clientDataHash, string $appId, int $now, bool $allowDevelopment): AttestedKey
    {
        if ($attestation === '' || strlen($attestation) > self::MAX_ATTESTATION_BYTES) {
            throw new InvalidAttestationException('Attesteringen saknas eller är för stor');
        }
        if (strlen($keyId) !== 32 || $clientDataHash === '') {
            throw new InvalidAttestationException('keyId eller clientDataHash har fel längd');
        }

        $obj = Cbor::decode($attestation);
        if (! is_array($obj) || ($obj['fmt'] ?? null) !== 'apple-appattest') {
            throw new InvalidAttestationException('fmt är inte apple-appattest');
        }
        $stmt = $obj['attStmt'] ?? null;
        $authData = $obj['authData'] ?? null;
        $x5c = is_array($stmt) ? ($stmt['x5c'] ?? null) : null;
        $receipt = is_array($stmt) ? ($stmt['receipt'] ?? null) : null;
        if (! is_string($authData) || ! is_array($x5c) || ! array_is_list($x5c) || count($x5c) !== 2
            || ! is_string($receipt) || $receipt === '') {
            throw new InvalidAttestationException('attStmt har fel form (x5c med två certifikat, receipt, authData)');
        }

        // 1. Kedjan credCert → intermediate → Apples rot, giltig nu.
        $credCert = $this->verifyChain($x5c, $now);

        // 2–3. nonce = SHA256(authData ‖ clientDataHash)
        $nonce = hash('sha256', $authData.$clientDataHash, true);

        // 4. credCert-tillägget 1.2.840.113635.100.8.2 innehåller nonce.
        $ext = openssl_x509_parse($credCert)['extensions'][self::NONCE_OID] ?? null;
        if (! is_string($ext) || ! hash_equals(self::NONCE_PREFIX.$nonce, $ext)) {
            throw new InvalidAttestationException('nonce stämmer inte');
        }

        // 5. SHA256 av publika nyckeln (X9.62 okomprimerad) = keyId
        $public = openssl_pkey_get_public($credCert);
        $details = $public instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($public) : false;
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidAttestationException('credCert har ingen P-256-nyckel');
        }
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        if (! hash_equals(hash('sha256', $point, true), $keyId)) {
            throw new InvalidAttestationException('Nyckelns hash är inte keyId');
        }

        $auth = AuthenticatorData::parse($authData, attested: true);
        // 6. RP ID
        if (! hash_equals(hash('sha256', $appId, true), $auth->rpIdHash)) {
            throw new InvalidAttestationException('RP ID stämmer inte');
        }
        // 7. counter
        if ($auth->signCount !== 0) {
            throw new InvalidAttestationException('counter är inte 0');
        }
        // 8. aaguid
        $environment = match (true) {
            $auth->aaguid === self::AAGUID_PRODUCTION => 'production',
            in_array($auth->aaguid, self::AAGUIDS_DEVELOPMENT, true) => 'development',
            default => throw new InvalidAttestationException('Okänd aaguid'),
        };
        if ($environment === 'development' && ! $allowDevelopment) {
            throw new InvalidAttestationException('development-nyckel i produktion');
        }
        // 9. credentialId
        if (! hash_equals($keyId, (string) $auth->credentialId)) {
            throw new InvalidAttestationException('credentialId är inte keyId');
        }
        // Nyckeln i authData är samma som i credCert.
        if (! hash_equals($point, $auth->coseKeyPoint())) {
            throw new InvalidAttestationException('COSE-nyckeln är inte credCerts nyckel');
        }

        // 10. apple_validation_category_01 ska vara en tillåten kategori (se PRODUCTION_CATEGORIES).
        $category = $auth->validationCategory();
        $allowed = $allowDevelopment ? [...$this->productionCategories, ...$this->developmentCategories] : $this->productionCategories;
        if ($category === null || ! in_array($category, $allowed, true)) {
            throw new InvalidAttestationException('validation category '.($category ?? 'saknas').' är inte tillåten');
        }

        // 11. apple_bundle_version_01 tolkas och sparas men avgör inte.
        // Kvittot (receipt) krävs i formen men sparas inte: det behövs bara för Apples riskbedömning
        // (assessing-fraud-risk), som inte används i v1. Dataminimering; läggs till om riskbedömningen behövs.
        return new AttestedKey($keyId, $details['key'], $environment, $auth->bundleVersion(), $category);
    }

    /**
     * Assertion enligt artikelns "Verify the assertion".
     *
     * @return int den nya räknaren, som anroparen sparar atomiskt
     *
     * @throws InvalidAttestationException
     */
    public function verifyAssertion(string $assertion, string $clientData, string $publicKeyPem, int $storedSignCount, string $appId): int
    {
        if ($assertion === '' || strlen($assertion) > self::MAX_ASSERTION_BYTES) {
            throw new InvalidAttestationException('Assertionen saknas eller är för stor');
        }
        $obj = Cbor::decode($assertion);
        $signature = is_array($obj) ? ($obj['signature'] ?? null) : null;
        $authData = is_array($obj) ? ($obj['authenticatorData'] ?? null) : null;
        if (! is_string($signature) || $signature === '' || ! is_string($authData) || count($obj) !== 2) {
            throw new InvalidAttestationException('Assertionen har fel form');
        }
        // Assertionens authenticatorData är exakt rpIdHash(32) flags(1) counter(4), inga tillägg.
        if (strlen($authData) !== self::ASSERTION_AUTHDATA_BYTES) {
            throw new InvalidAttestationException('Assertionens authData har fel längd');
        }

        // 1–3. nonce = SHA256(authenticatorData ‖ SHA256(clientData)); signaturen är ECDSA P-256/SHA-256 med
        // nonce som meddelande (dubbel hashning), som node-app-attest (createVerify('SHA256').update(nonce)) och
        // devicecheck-appattest (SHA256withECDSA, update(nonce)).
        // ASSUMED — verifieras mot en riktig enhet (planens Task 14) innan gratisflaggan slås på; Apple saknar assertion-testvektor.
        // Reserv om enhetsprovet visar enkel hashning: openssl_verify($authData.hash('sha256', $clientData, true), …).
        $nonce = hash('sha256', $authData.hash('sha256', $clientData, true), true);
        $key = @openssl_pkey_get_public($publicKeyPem);
        $details = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidAttestationException('Den publika nyckeln är inte en P-256-nyckel');
        }
        if (openssl_verify($nonce, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidAttestationException('Signaturen stämmer inte');
        }

        $auth = AuthenticatorData::parse($authData, attested: false);
        // 4. RP ID
        if (! hash_equals(hash('sha256', $appId, true), $auth->rpIdHash)) {
            throw new InvalidAttestationException('RP ID stämmer inte');
        }
        // 5. counter > förra (> 0 första gången, eftersom attesteringen hade 0)
        if ($auth->signCount <= $storedSignCount) {
            throw new InvalidAttestationException('counter ökar inte (replay?)');
        }

        // 6. Challengen kontrolleras av FreeGate. 7–8: extensions loggas men avgör inte (se attesteringen).
        return $auth->signCount;
    }

    /** @param list<mixed> $x5c */
    private function verifyChain(array $x5c, int $now): OpenSSLCertificate
    {
        $certs = [];
        foreach ($x5c as $der) {
            $cert = is_string($der) && $der !== '' ? @openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n") : false;
            if (! $cert instanceof OpenSSLCertificate) {
                throw new InvalidAttestationException('x5c-certifikat går inte att läsa');
            }
            $certs[] = $cert;
        }
        [$leaf, $intermediate] = $certs;
        $info = ['root' => openssl_x509_parse($this->root), 'intermediate' => openssl_x509_parse($intermediate), 'credCert' => openssl_x509_parse($leaf)];
        if (in_array(false, $info, true)) {
            throw new InvalidAttestationException('Certifikat går inte att tolka');
        }
        if ($info['intermediate']['issuer'] !== $info['root']['subject'] || $info['credCert']['issuer'] !== $info['intermediate']['subject']) {
            throw new InvalidAttestationException('Namnkedjan stämmer inte');
        }
        $rootKey = openssl_pkey_get_public($this->root);
        $intKey = openssl_pkey_get_public($intermediate);
        if (! $rootKey || ! $intKey || openssl_x509_verify($intermediate, $rootKey) !== 1 || openssl_x509_verify($leaf, $intKey) !== 1) {
            throw new InvalidAttestationException('Signaturerna i kedjan stämmer inte');
        }
        $isCa = fn (array $i) => preg_match('/(^|,\s*)CA:TRUE(\s*,|$)/', (string) ($i['extensions']['basicConstraints'] ?? '')) === 1;
        if (! $isCa($info['intermediate']) || $isCa($info['credCert'])) {
            throw new InvalidAttestationException('CA-flaggorna i kedjan stämmer inte');
        }
        foreach ($info as $name => $i) {
            if (! is_int($i['validFrom_time_t'] ?? null) || ! is_int($i['validTo_time_t'] ?? null)
                || $now < $i['validFrom_time_t'] || $now > $i['validTo_time_t']) {
                throw new InvalidAttestationException("Certifikatet {$name} är inte giltigt nu");
            }
        }

        return $leaf;
    }
}
