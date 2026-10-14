<?php

namespace App\Provisioning\Clients;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\SendsApiRequests;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// SiteOps API v1 enligt det låsta kontraktet i delprojekt 2a. Nyckeln har
// sites:read och det smala demo-sites:write: bara Demo-webbar, aldrig
// credentials, pejl_customer_id eller cms_type (422). API:t har ingen radering. PATCH och deactivate
// fungerar bara på webbar som Computercats egen klient skapade (403 annars).
class SiteOpsClient
{
    use SendsApiRequests;

    protected function serviceName(): string
    {
        return 'SiteOps';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('provisioning.siteops.url'), '/').'/api/v1';
    }

    /** Samma likhet som 2a:s uppslag: avslutande snedstreck räknas inte. */
    public static function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    /** @return list<int> */
    public function findIdsByUrl(string $url): array
    {
        $path = '/sites?'.http_build_query(['url' => $url]);
        $data = $this->siteops('GET', $path)->json('data');
        if (! is_array($data)) {
            throw new ExternalApiException('SiteOps', 'GET', '/sites', 200, 'response lacks data');
        }

        $ids = [];
        foreach ($data as $site) {
            if (! is_int($site['id'] ?? null)) {
                throw new ExternalApiException('SiteOps', 'GET', '/sites', 200, 'site without integer id');
            }
            $ids[] = $site['id'];
        }

        return $ids;
    }

    /** @return array{created: bool, id: int} */
    public function createDemoSite(string $name, string $url): array
    {
        $response = $this->siteops('POST', '/sites', [
            'name' => $name,
            'url' => $url,
            'type' => 'app',
            'environment' => [
                'type' => 'demo',
                'name' => 'Demo',
                'url' => $url,
                'basic_auth_user' => (string) config('provisioning.demo.basic_auth_user'),
                'basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
                // Inga larm innan spelets Claude har pushat: DemoMonitoring slår på övervakningen.
                'is_monitored' => false,
            ],
        ], [409]);

        if ($response->status() === 409) {
            $existing = $response->json('site_id');
            if (! is_int($existing)) {
                throw new ExternalApiException('SiteOps', 'POST', '/sites', 409, 'conflict without site_id');
            }

            return ['created' => false, 'id' => $existing];
        }

        $id = $response->json('data.id');
        if (! is_int($id)) {
            throw new ExternalApiException('SiteOps', 'POST', '/sites', $response->status(), 'response lacks data.id');
        }

        return ['created' => true, 'id' => $id];
    }

    public function site(int $id): array
    {
        return (array) $this->siteops('GET', "/sites/{$id}")->json('data');
    }

    public function environments(int $id): array
    {
        return (array) $this->siteops('GET', "/sites/{$id}/environments")->json('data');
    }

    /**
     * Sätter Demo-värdena på miljön. 200 bevisar att Computercats klient skapade
     * webben; 403 betyder att den inte gjorde det (och att inget ändrades).
     */
    public function claimDemoEnvironment(int $siteId, int $environmentId): bool
    {
        $response = $this->siteops('PATCH', "/sites/{$siteId}/environments/{$environmentId}", [
            'basic_auth_user' => (string) config('provisioning.demo.basic_auth_user'),
            'basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
            'is_monitored' => false,
        ], [403]);

        return $response->status() !== 403;
    }

    public function setMonitored(int $siteId, int $environmentId, bool $monitored): void
    {
        $path = "/sites/{$siteId}/environments/{$environmentId}";
        $response = $this->siteops('PATCH', $path, ['is_monitored' => $monitored]);
        if ($response->json('data.is_monitored') !== $monitored) {
            throw new ExternalApiException('SiteOps', 'PATCH', $path, $response->status(), 'is_monitored was not updated');
        }
    }

    public function deactivate(int $id): void
    {
        $response = $this->siteops('POST', "/sites/{$id}/deactivate");
        if ($response->json('data.is_active') !== false || $response->json('data.monitoring_enabled') !== false) {
            throw new ExternalApiException('SiteOps', 'POST', "/sites/{$id}/deactivate", $response->status(), 'site is still active or monitored after deactivate');
        }
    }

    private function siteops(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['X-Api-Key' => (string) config('provisioning.siteops.api_key')])
            ->acceptJson()
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }
}
