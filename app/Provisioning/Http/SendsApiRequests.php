<?php

namespace App\Provisioning\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

// Gemensam anropsväg för GitHub-, Coolify- och SiteOps-klienterna: allt som
// inte är 2xx loggas; det som inte uttryckligen accepteras kastas.
trait SendsApiRequests
{
    abstract protected function serviceName(): string;

    abstract protected function baseUrl(): string;

    /** @param list<int> $accept statusar utöver 2xx som returneras i stället för att kastas */
    protected function sendRequest(PendingRequest $request, string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        try {
            $response = $request->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            Log::warning($this->serviceName().' API: anslutningen misslyckades', ['method' => $method, 'url' => Redactor::scrubSecrets($this->baseUrl().$path)]);

            throw new ExternalApiException($this->serviceName(), $method, $path, 0, 'connection failed');
        }

        if (! $response->successful()) {
            ApiCallLogger::log($this->serviceName(), $method, $this->baseUrl().$path, $body, $response);
        }

        if ($response->successful() || in_array($response->status(), $accept, true)) {
            return $response;
        }

        $rateLimited = $response->header('Retry-After') !== '' || $response->header('X-RateLimit-Remaining') === '0';

        throw new ExternalApiException($this->serviceName(), $method, $path, $response->status(), Redactor::body($response->body()), $rateLimited);
    }
}
