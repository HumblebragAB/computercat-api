<?php

namespace App\Provisioning\Clients;

use App\Models\ProvisioningRun;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\SendsApiRequests;
use App\Provisioning\ProvisioningConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// Coolify v4.4.6 (bilaga A). Token når hela teamet, därför två spärrar:
// varje app-uuid måste finnas i provisioning_runs, och allt som ändrar eller
// raderar kräver dessutom att appen ligger i Demo-projektet.
class CoolifyClient
{
    use SendsApiRequests;

    public const DEPLOYMENT_STATUSES = ['queued', 'in_progress', 'finished', 'failed', 'cancelled-by-user'];

    protected function serviceName(): string
    {
        return 'Coolify';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('provisioning.coolify.url'), '/').'/api/v1';
    }

    /** @return list<array{uuid: string, name: string}> */
    public function demoApplications(): array
    {
        // Verifierat i spike 2026-10-14 (S13): GET /projects/{uuid}/{environment_name}
        // svarar med miljön och dess applications[] med uuid och name.
        $path = '/projects/'.rawurlencode((string) config('provisioning.coolify.project_uuid')).'/'.rawurlencode((string) config('provisioning.coolify.environment_name'));
        $applications = $this->coolify('GET', $path)->json('applications');
        if (! is_array($applications)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'response lacks applications');
        }

        $out = [];
        foreach ($applications as $app) {
            if (is_array($app) && is_string($app['uuid'] ?? null) && is_string($app['name'] ?? null)) {
                $out[] = ['uuid' => $app['uuid'], 'name' => $app['name']];
            }
        }

        return $out;
    }

    /** @return array{uuid: string, name: string}|null */
    public function findDemoApplicationByName(string $name): ?array
    {
        $matches = array_values(array_filter($this->demoApplications(), fn (array $app) => $app['name'] === $name));
        if (count($matches) > 1) {
            throw new CoolifyGuardException("More than one Coolify application is named {$name} in the Demo project.");
        }

        return $matches[0] ?? null;
    }

    public function createApplication(string $slug): string
    {
        $path = '/applications/private-github-app';
        $response = $this->coolify('POST', $path, [
            'project_uuid' => (string) config('provisioning.coolify.project_uuid'),
            'server_uuid' => (string) config('provisioning.coolify.server_uuid'),
            'environment_name' => (string) config('provisioning.coolify.environment_name'),
            'github_app_uuid' => (string) config('provisioning.coolify.github_app_uuid'),
            // Verifierat i spike 2026-10-14: S1 (git_repository som owner/repo) och S2 (ports_exposes '80').
            // publish_directory måste börja med snedstreck (Coolify 4.4.6 DIRECTORY_PATH_PATTERN).
            'git_repository' => ProvisioningConfig::repoFullName($slug),
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'name' => ProvisioningConfig::coolifyAppName($slug),
            'domains' => ProvisioningConfig::demoUrl($slug),
            'ports_exposes' => '80',
            'is_static' => true,
            'static_image' => 'nginx:alpine',
            'publish_directory' => '/out',
            // Bilaga A S:793. Samma installation som byggguiden (BuildGuideTest och
            // CoolifyClientTest vaktar): installationsskript körs aldrig i Demo-bygget.
            'install_command' => 'npm ci --ignore-scripts',
            'is_auto_deploy_enabled' => true,
            'instant_deploy' => false,
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => (string) config('provisioning.demo.basic_auth_user'),
            'http_basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
        ], [409]);

        if ($response->status() === 409) {
            throw new CoolifyConflictException('Coolify reports a domain conflict for '.ProvisioningConfig::demoUrl($slug).'.');
        }

        $uuid = $response->json('uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new ExternalApiException('Coolify', 'POST', $path, $response->status(), 'response lacks uuid');
        }

        return $uuid;
    }

    public function application(string $uuid): array
    {
        $this->assertKnown($uuid);

        return (array) $this->coolify('GET', '/applications/'.$uuid)->json();
    }

    public function environmentVariables(string $uuid): array
    {
        $this->assertKnown($uuid);
        $json = $this->coolify('GET', '/applications/'.$uuid.'/envs')->json();
        if (! is_array($json) || ! array_is_list($json)) {
            throw new ExternalApiException('Coolify', 'GET', '/applications/'.$uuid.'/envs', 200, 'expected a list of environment variables');
        }

        return $json;
    }

    public function createBuildVariable(string $uuid, string $key, string $value): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('POST', '/applications/'.$uuid.'/envs', $this->buildVariable($key, $value));
    }

    public function updateBuildVariable(string $uuid, string $key, string $value): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('PATCH', '/applications/'.$uuid.'/envs', $this->buildVariable($key, $value));
    }

    // Computercat startar aldrig ett bygge (byggguiden: första bygget kommer
    // från auto-deploy när spelets Claude pushar). Bara läsning av senaste bygget.
    // Svarsformen {count, deployments[]} nyast först verifieras i spike S22.
    /** @return array{deployment_uuid: string, status: string, created_at: string}|null */
    public function latestDeployment(string $uuid): ?array
    {
        $this->assertKnown($uuid);
        $path = '/deployments/applications/'.$uuid.'?'.http_build_query(['skip' => 0, 'take' => 1]);
        $deployments = $this->coolify('GET', $path)->json('deployments');
        if (! is_array($deployments) || ! array_is_list($deployments)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'response lacks deployments[] (expected a list)');
        }
        if ($deployments === []) {
            return null;
        }

        $latest = $deployments[0];
        if (! is_array($latest)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'deployment entry is not an object');
        }
        $status = $latest['status'] ?? null;
        if (! in_array($status, self::DEPLOYMENT_STATUSES, true)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'unknown deployment status: '.(is_scalar($status) ? (string) $status : gettype($status)));
        }

        // Fail loud: ett fält som saknas blir ett fel, aldrig en tom sträng.
        foreach (['deployment_uuid', 'created_at'] as $field) {
            if (! is_string($latest[$field] ?? null) || $latest[$field] === '') {
                throw new ExternalApiException('Coolify', 'GET', $path, 200, "deployment lacks {$field}");
            }
        }

        return [
            'deployment_uuid' => $latest['deployment_uuid'],
            'status' => $status,
            'created_at' => $latest['created_at'],
        ];
    }

    // Asynkron i Coolify (DeleteResourceJob); standardvärdena på query-flaggorna (alla true) används.
    public function deleteApplication(string $uuid): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('DELETE', '/applications/'.$uuid);
    }

    private function buildVariable(string $key, string $value): array
    {
        // is_buildtime/is_runtime finns i controllern men inte i OpenAPI (bilaga A §3, spike S4/S15).
        return ['key' => $key, 'value' => $value, 'is_preview' => false, 'is_buildtime' => true, 'is_runtime' => false];
    }

    private function assertKnown(string $uuid): void
    {
        $this->assertUuidFormat($uuid);
        if (! ProvisioningRun::where('coolify_app_uuid', $uuid)->exists()) {
            throw new CoolifyGuardException("Coolify application {$uuid} is not recorded in any provisioning run.");
        }
    }

    private function assertInDemoProject(string $uuid): void
    {
        $this->assertKnown($uuid);
        foreach ($this->demoApplications() as $app) {
            if ($app['uuid'] === $uuid) {
                return;
            }
        }

        throw new CoolifyGuardException("Coolify application {$uuid} is not in the Demo project.");
    }

    private function assertUuidFormat(string $uuid): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $uuid) !== 1) {
            throw new CoolifyGuardException('Invalid Coolify uuid.');
        }
    }

    private function coolify(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken((string) config('provisioning.coolify.token'))
            ->acceptJson()
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }
}
