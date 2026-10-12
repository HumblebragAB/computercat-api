<?php

namespace App\Services\Glosis\AppAttest;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Engångs-challenges för App Attest ("Provide a challenge" i Apples artikel).
 *
 * Tillståndslösa: challengen är base64url(16 slumpbyte ‖ utfärdad tid (8 byte) ‖
 * HMAC-SHA256(nyckel, de 24 första)[0..16]), 54 tecken. Nyckeln härleds ur
 * APP_KEY. Utfärdandet skriver inget i cachen. I produktion är cachen
 * databas-store, och appen hämtar en challenge per gratisförfrågan, så varje
 * utfärdad challenge hade annars blivit en rad. Bara inlösen skriver: en
 * ":used"-markör med Cache::add (insertOrIgnore, atomisk), så av två
 * samtidiga inlösningar lyckas en. Utgångna markörer gallras av
 * glosis:prune-cache. Challengen loggas aldrig.
 */
final class AttestChallenges
{
    public const TTL_SECONDS = 300;

    /** Tillåten klockskillnad framåt (flera webbservrar). */
    private const FUTURE_SKEW_SECONDS = 30;

    public function issue(): string
    {
        $body = random_bytes(16).pack('J', now()->getTimestamp());

        return rtrim(strtr(base64_encode($body.self::mac($body)), '+/', '-_'), '=');
    }

    /**
     * Sant en gång per giltig, outgången challenge. Kontrollerar form, MAC och
     * tid innan något skrivs, så en förfalskad challenge kostar ingen cacherad.
     */
    public function consume(mixed $challenge): bool
    {
        if (! is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{54}\z/', $challenge) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($challenge, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 40) {
            return false;
        }
        $body = substr($raw, 0, 24);
        if (! hash_equals(self::mac($body), substr($raw, 24))) {
            return false;
        }
        $issued = unpack('J', substr($body, 16, 8))[1];
        $now = now()->getTimestamp();
        if ($issued > $now + self::FUTURE_SKEW_SECONDS || $now - $issued > self::TTL_SECONDS) {
            return false;
        }

        return Cache::add('glosis-attest-used:'.hash('sha256', $challenge), 1, self::TTL_SECONDS + self::FUTURE_SKEW_SECONDS + 60);
    }

    private static function mac(string $body): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY saknas; App Attest-challenges kan inte signeras');
        }
        $key = hash_hmac('sha256', 'glosis-attest-challenge', $appKey, true);

        return substr(hash_hmac('sha256', $body, $key, true), 0, 16);
    }
}
