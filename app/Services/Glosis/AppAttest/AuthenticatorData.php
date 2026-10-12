<?php

namespace App\Services\Glosis\AppAttest;

/**
 * authData enligt WebAuthn (https://www.w3.org/TR/webauthn/#sec-authenticator-data)
 * med Apples App Attest-fält: rpIdHash(32) flags(1) counter(4) [aaguid(16)
 * credIdLen(2) credentialId(32) COSE-nyckel] [extensions].
 *
 * Apples exempel (attestation-object-validation-guide) har extensions efter
 * COSE-nyckeln utan att ED-biten (0x80) är satt. Därför läses extensions när
 * det finns bytes kvar, oavsett flaggan, och måste då vara exakt en CBOR-map.
 */
final readonly class AuthenticatorData
{
    /**
     * @param  array<int, mixed>|null  $coseKey
     * @param  array<int|string, mixed>  $extensions
     */
    private function __construct(
        public string $rpIdHash,
        public int $flags,
        public int $signCount,
        public ?string $aaguid,
        public ?string $credentialId,
        private ?array $coseKey,
        private array $extensions,
    ) {}

    public static function parse(string $data, bool $attested): self
    {
        if (strlen($data) < 37 || strlen($data) > 1024) {
            throw new InvalidAttestationException('authData har fel längd');
        }
        $rp = substr($data, 0, 32);
        $flags = ord($data[32]);
        $count = unpack('N', substr($data, 33, 4))[1];
        $offset = 37;
        $aaguid = $credentialId = $cose = null;

        if ($attested) {
            if (strlen($data) < 55) {
                throw new InvalidAttestationException('authData saknar attesterad nyckel');
            }
            $aaguid = substr($data, 37, 16);
            $len = unpack('n', substr($data, 53, 2))[1];
            if ($len !== 32 || strlen($data) < 55 + $len) {
                throw new InvalidAttestationException('credentialId har fel längd');
            }
            $credentialId = substr($data, 55, 32);
            $offset = 55 + 32;
            $cose = Cbor::decodeAt($data, $offset);
            if (! is_array($cose)) {
                throw new InvalidAttestationException('COSE-nyckeln är inte en map');
            }
        }

        $extensions = [];
        if ($offset < strlen($data)) {
            $extensions = Cbor::decodeAt($data, $offset);
            if (! is_array($extensions) || $offset !== strlen($data)) {
                throw new InvalidAttestationException('authData: extensions har fel form');
            }
        }

        return new self($rp, $flags, $count, $aaguid, $credentialId, $cose, $extensions);
    }

    /** 0x04 ‖ x ‖ y ur COSE-nyckeln (RFC 8152: kty 2 = EC2, alg -7 = ES256, crv 1 = P-256). */
    public function coseKeyPoint(): string
    {
        $k = $this->coseKey ?? [];
        if (($k[1] ?? null) !== 2 || ($k[3] ?? null) !== -7 || ($k[-1] ?? null) !== 1
            || ! is_string($k[-2] ?? null) || strlen($k[-2]) !== 32
            || ! is_string($k[-3] ?? null) || strlen($k[-3]) !== 32) {
            throw new InvalidAttestationException('COSE-nyckeln är inte en P-256-nyckel');
        }

        return "\x04".$k[-2].$k[-3];
    }

    public function bundleVersion(): ?string
    {
        $v = $this->extensions['apple_bundle_version_01'] ?? null;

        return is_string($v) && $v !== '' && strlen($v) <= 64 && mb_check_encoding($v, 'UTF-8') ? $v : null;
    }

    /** Artikeln säger UInt32; Apples exempel har 4 byte little-endian. Båda tolkas. */
    public function validationCategory(): ?int
    {
        $v = $this->extensions['apple_validation_category_01'] ?? null;
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && strlen($v) === 4 ? unpack('V', $v)[1] : null;
    }
}
