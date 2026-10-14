<?php

namespace App\Support;

use App\Provisioning\Http\Redactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Larm till Eriks Slack via inkommande webhook. Samma nyckel skickas högst en
// gång per dygn. Ett fel loggas men stoppar aldrig anroparen; webhook-URL:en
// är en hemlighet och loggas aldrig.
class SlackAlert
{
    public const SENT = 'sent';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    public function send(string $dedupeKey, string $text): string
    {
        $url = config('provisioning.slack_webhook_url');
        if (! is_string($url) || $url === '') {
            Log::warning('SlackAlert: SLACK_ALERT_WEBHOOK_URL saknas, larmet skickades inte', ['key' => $dedupeKey, 'text' => Redactor::limit($text)]);

            return self::NOT_CONFIGURED;
        }

        $cacheKey = 'slack-alert:'.now()->toDateString().':'.sha1($dedupeKey);
        if (! Cache::add($cacheKey, true, now()->endOfDay())) {
            return self::DUPLICATE;
        }

        try {
            $response = Http::timeout(5)->connectTimeout(5)->asJson()->post($url, ['text' => $text]);
            if ($response->successful()) {
                return self::SENT;
            }
            Log::warning('SlackAlert: webhooken svarade '.$response->status(), ['key' => $dedupeKey, 'body' => Redactor::limit($response->body(), 500)]);
        } catch (\Throwable $e) {
            Log::warning('SlackAlert: anropet misslyckades', ['key' => $dedupeKey, 'error' => get_class($e)]);
        }

        // Ett misslyckat larm ska kunna skickas igen samma dygn.
        Cache::forget($cacheKey);

        return self::FAILED;
    }

    // Slack tolkar <...> som länkar och kommandon (<!channel>); användarvärden escapas.
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
