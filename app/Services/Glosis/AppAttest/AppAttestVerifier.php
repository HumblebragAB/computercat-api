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
    public const ROOT_CERT_PATH = 'certs/Apple_App_Attestation_Root_CA.pem';

    /** SHA-256 (DER) för https://www.apple.com/certificateauthority/Apple_App_Attestation_Root_CA.pem, hämtad 2026-10-12. */
    public const ROOT_SHA256 = '1cb9823ba28ba6ad2d33a006941de2ae4f513ef1d4e831b9f7e0fa7b6242c932';

    public const NONCE_OID = '1.2.840.113635.100.8.2';

    /** DER: SEQUENCE(36) { [1](34) { OCTET STRING(32) nonce } }. */
    private const NONCE_PREFIX = "\x30\x24\xa1\x22\x04\x20";

    public const AAGUID_PRODUCTION = "appattest\0\0\0\0\0\0\0";

    /** "appattestdevelop" i valideringsartikeln, "appattestsandbox" i preparing-to-use. Båda är development. */
    public const AAGUIDS_DEVELOPMENT = ['appattestdevelop', 'appattestsandbox'];

    private const MAX_ATTESTATION_BYTES = 16_384;

    private const MAX_ASSERTION_BYTES = 2_048;

    private readonly OpenSSLCertificate $root;

    public function __construct(?string $rootPem = null)
    {
        $root = $rootPem === null ? self::loadPinnedRoot(resource_path(self::ROOT_CERT_PATH)) : @openssl_x509_read($rootPem);
        if (! $root instanceof OpenSSLCertificate) {
            throw new RuntimeException('App Attest-roten går inte att läsa');
        }
        $this->root = $root;
    }

    /** Läser roten och kontrollerar att det är Apples (för test av kontrollen). */
    public static function fromRootFile(string $path): self
    {
        $root = self::loadPinnedRoot($path);
        openssl_x509_export($root, $pem);

        return new self($pem);
    }

    private static function loadPinnedRoot(string $path): OpenSSLCertificate
    {
        $pem = @file_get_contents($path);
        $cert = $pem === false ? false : @openssl_x509_read($pem);
        if (! $cert instanceof OpenSSLCertificate) {
            throw new RuntimeException('Apple App Attestation Root CA saknas i resources/'.self::ROOT_CERT_PATH);
        }
        if (! hash_equals(self::ROOT_SHA256, (string) openssl_x509_fingerprint($cert, 'sha256'))) {
            throw new RuntimeException('Apple App Attestation Root CA har fel SHA-256, filen är utbytt eller trasig');
        }

        return $cert;
    }

    /**
     * @param  string  $keyId  rå keyId (32 byte) från appen
     * @param  string  $clientDataHash  SHA256 av challengen som servern gav ut
     * @param  string  $appId  Team ID + "." + bundle-id
     *
     * @throws InvalidAttestationException
     */
    public function verifyAttestation(string $attestation, string $keyId, string $clientDataHash, string $appId, int $now, bool $allowDevelopment): AttestedKey
    {
        if ($attestation === '' || strlen($attestation) > self::MAX_ATTESTATION_BYTES) {
            throw new InvalidAttestationException('Attesteringen saknas eller är för stor');
        }
        if (strlen($keyId) !== 32 || strlen($clientDataHash) === 0) {
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

        // 10–11. Extensions tolkas och sparas men avgör inte än (talvärdena för
        // App Store och TestFlight är odokumenterade; se beslut 2026-10-12).
        // Kvittot (receipt) krävs i formen men sparas inte: det behövs bara för Apples riskbedömning
        // (assessing-fraud-risk), som inte används i v1. Dataminimering; läggs till om riskbedömningen behövs.
        return new AttestedKey($keyId, $details['key'], $environment, $auth->bundleVersion(), $auth->validationCategory());
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
