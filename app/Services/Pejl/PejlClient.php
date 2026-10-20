<?php

namespace App\Services\Pejl;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Pejls API för källintäkter (samma mönster som laravel-shops PejlService). Nyckeln går bara i
// Authorization-headern och ersätts med [redacted] i allt som lämnar klassen (logg, svar, fel).
class PejlClient
{
    public const MAX_BYTES = 4096;

    // Vilka miljövariabler som saknas; tom lista = går att anropa.
    /** @return list<string> */
    public function missingConfig(): array
    {
        return array_keys(array_filter([
            'PEJL_API_URL' => $this->url() === '',
            'PEJL_API_KEY' => $this->key() === '',
        ]));
    }

    /**
     * POST /api/revenue-items. Undantag (Pejl onåbar) kastas vidare med maskad text.
     *
     * @return array{ok: bool, status: ?int, body: ?string}
     */
    public function postRevenue(array $payload): array
    {
        if ($missing = $this->missingConfig()) {
            throw new \LogicException('Pejl är inte konfigurerat: '.implode(', ', $missing).' saknas');
        }

        $url = rtrim($this->url(), '/').'/api/revenue-items';

        try {
            $response = Http::withToken($this->key())->acceptJson()->timeout(10)->post($url, $payload);
        } catch (\Throwable $e) {
            $error = $this->redact(get_class($e).': '.$e->getMessage());
            Log::warning('Pejl: anropet misslyckades', ['url' => $url, 'request' => $this->requestText($payload), 'error' => $error]);

            throw new \RuntimeException($error);
        }

        $body = $this->redact($response->body());
        if (! $response->successful()) {
            Log::warning('Pejl svarade HTTP '.$response->status(), [
                'url' => $url,
                'request' => $this->requestText($payload),
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $body];
    }

    // Nyckeln maskad och texten kapad till 4 KB (utan att dela ett tecken).
    public function redact(string $text): string
    {
        $key = $this->key();

        return mb_strcut($key === '' ? $text : str_replace($key, '[redacted]', $text), 0, self::MAX_BYTES);
    }

    private function requestText(array $data): string
    {
        return $this->redact((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function url(): string
    {
        return is_string(config('pejl.api_url')) ? trim(config('pejl.api_url')) : '';
    }

    private function key(): string
    {
        return is_string(config('pejl.api_key')) ? trim(config('pejl.api_key')) : '';
    }
}
