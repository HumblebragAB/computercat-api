<?php

namespace App\Provisioning\Http;

use Illuminate\Support\Facades\Log;

// Allt som lämnar klienterna som text (loggar, last_error, Slack) går hit.
// Specen 2c: Authorization, private_key, password, token, value och Coolifys
// logs tas bort; max 4 KB.
final class Redactor
{
    public const MAX_BYTES = 4096;

    private const MASK = '[REDACTED]';

    /** @var list<string> hemligheter som utfärdats under processens livstid (GitHub-tokens) */
    private static array $runtimeSecrets = [];

    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_string($key) && self::sensitiveKey($key) ? self::MASK : self::redact($value);
        }

        return $out;
    }

    public static function body(string $raw): string
    {
        $decoded = json_decode($raw, true);
        $text = is_array($decoded)
            ? (string) json_encode(self::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : $raw;

        return self::limit(self::scrubSecrets($text));
    }

    public static function scrubSecrets(string $text): string
    {
        $configured = array_filter([
            config('provisioning.coolify.token'),
            config('provisioning.siteops.api_key'),
            config('provisioning.slack_webhook_url'),
        ], fn ($secret) => is_string($secret) && strlen($secret) >= 8);

        $secrets = array_values(array_merge(self::$runtimeSecrets, $configured));
        if ($secrets !== []) {
            $text = str_replace($secrets, self::MASK, $text);
        }

        // GitHub-tokens, JWT (appens RS256-JWT bär inget hemligt men ger en
        // installationstoken i 10 min) och PEM-nycklar (appens privata nyckel).
        $scrubbed = preg_replace([
            '/\b(gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/',
            '/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|\z)/s',
        ], self::MASK, $text);

        // Fail closed: vid PCRE-fel (t.ex. backtrack-gränsen på en stor body)
        // kan texten innehålla en nyckel, så hela texten ersätts. Loggen får
        // bara felkoden, aldrig innehållet.
        if ($scrubbed === null) {
            Log::warning('Redactor: PCRE-fel vid redigering, hela texten maskerades', ['error' => preg_last_error_msg(), 'bytes' => strlen($text)]);

            return self::MASK;
        }

        return $scrubbed;
    }

    public static function limit(string $text, int $max = self::MAX_BYTES): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        // mb_strcut klipper aldrig mitt i ett tecken.
        return mb_strcut($text, 0, $max - strlen('…'), 'UTF-8').'…';
    }

    public static function registerSecret(string $secret): void
    {
        if (strlen($secret) < 8 || in_array($secret, self::$runtimeSecrets, true)) {
            return;
        }
        self::$runtimeSecrets[] = $secret;
        // Kö-workern lever länge; de senaste räcker (tokens gäller en timme).
        self::$runtimeSecrets = array_slice(self::$runtimeSecrets, -20);
    }

    private static function sensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, ['value', 'real_value', 'logs'], true)
            || preg_match('/authorization|private_key|password|secret|token|api_key|api-key/', $key) === 1;
    }
}
