<?php

namespace App\Provisioning\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

// CLAUDE.md: 4xx/5xx loggas med request och response, redigerat och kortat.
final class ApiCallLogger
{
    public static function log(string $service, string $method, string $url, ?array $requestBody, Response $response): void
    {
        $request = $requestBody === null
            ? null
            : Redactor::limit(Redactor::scrubSecrets((string) json_encode(Redactor::redact($requestBody), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));

        Log::warning("{$service} API svarade {$response->status()}", [
            'method' => $method,
            'url' => Redactor::scrubSecrets($url),
            'status' => $response->status(),
            'request' => $request,
            'response' => Redactor::body($response->body()),
        ]);
    }
}
