<?php

namespace Tests\Support;

// Konfiguration och fejkade svar för provisioneringstesterna. Svarsformerna
// följer bilaga A (Coolify v4.4.6), bilaga B (GitHub) och SiteOps-kontraktet
// i planen. Rader märkta "spike Sx" är verifierade i Task 1.
final class ProvisioningFakes
{
    public const GITHUB = 'https://api.github.com';

    public const COOLIFY = 'https://coolify.test';

    public const SITEOPS = 'https://siteops.test';

    public const SLACK = 'https://hooks.slack.test/services/T000/B000/hemligsokvag123';

    public const PROJECT = 'demo-project-uuid';

    public const ENVIRONMENT = 'production';

    public const COOLIFY_TOKEN = 'coolify-secret-token-123';

    public const SITEOPS_KEY = 'siteops-secret-key-456';

    public const KEY_FILE = 'framework/testing/github-app-test.pem';

    private static ?array $keyPair = null;

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
        }

        return self::$keyPair;
    }

    public static function configure(): void
    {
        $path = storage_path(self::KEY_FILE);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, self::keyPair()['private']);
        chmod($path, 0600);

        config([
            'provisioning.github.client_id' => 'Iv23testclientid',
            'provisioning.github.installation_id' => '4242',
            'provisioning.github.private_key_path' => self::KEY_FILE,
            'provisioning.github.owner' => 'HumblebragAB',
            'provisioning.coolify.url' => self::COOLIFY,
            'provisioning.coolify.token' => self::COOLIFY_TOKEN,
            'provisioning.coolify.project_uuid' => self::PROJECT,
            'provisioning.coolify.environment_name' => self::ENVIRONMENT,
            'provisioning.coolify.server_uuid' => 'server-uuid',
            'provisioning.coolify.github_app_uuid' => 'coolify-github-app-uuid',
            'provisioning.siteops.url' => self::SITEOPS,
            'provisioning.siteops.api_key' => self::SITEOPS_KEY,
            'provisioning.demo.basic_auth_user' => 'demo',
            'provisioning.demo.basic_auth_password' => 'demo',
            'provisioning.slack_webhook_url' => self::SLACK,
        ]);
    }

    // --- GitHub (bilaga B) ---

    /** POST /app/installations/{id}/access_tokens, 201. */
    public static function githubToken(string $token = 'ghs_testinstallationtoken000000000001'): array
    {
        return [
            'token' => $token,
            'expires_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            'permissions' => ['administration' => 'write', 'contents' => 'write', 'metadata' => 'read'],
            'repository_selection' => 'selected',
            'repositories' => [],
        ];
    }

    /** GET /repos/{owner}/{repo} och 201 från POST /orgs/{org}/repos (spike S10). */
    public static function githubRepo(string $slug, string $runUuid, bool $archived = false, bool $private = true, ?string $description = null): array
    {
        return [
            'id' => 900000 + strlen($slug),
            'name' => $slug,
            'full_name' => "HumblebragAB/{$slug}",
            'private' => $private,
            'archived' => $archived,
            'description' => $description ?? "Computercat-spel {$slug} (run {$runUuid})",
        ];
    }

    /** 422 från POST /orgs/{org}/repos när namnet redan finns (spike S12; justeras efter den sparade bodyn). */
    public static function githubNameTaken(): array
    {
        return ['message' => 'Repository creation failed.', 'errors' => [['resource' => 'Repository', 'code' => 'custom', 'field' => 'name', 'message' => 'name already exists on this account']]];
    }

    /** README som auto_init skapar (innehållet verifieras i spike S11; steget skriver över hela filen). */
    public static function autoInitReadme(string $slug): string
    {
        return "# {$slug}\nComputercat-spel {$slug}\n";
    }

    /** GET /repos/{o}/{r}/contents/{path} (spike S11). GitHub radbryter base64. */
    public static function githubContent(string $text, string $sha = 'readme-sha-1'): array
    {
        return ['sha' => $sha, 'encoding' => 'base64', 'content' => chunk_split(base64_encode($text), 60, "\n")];
    }

    // --- Coolify (bilaga A) ---

    /** GET /projects/{uuid}/{environment_name} (spike S13). */
    public static function coolifyProject(array $applications): array
    {
        return ['id' => 1, 'name' => self::ENVIRONMENT, 'applications' => $applications];
    }

    public static function coolifyApp(string $uuid, string $name, bool $basicAuth = true): array
    {
        return ['uuid' => $uuid, 'name' => $name, 'is_http_basic_auth_enabled' => $basicAuth];
    }

    /** POST /applications/private-github-app, 201. */
    public static function coolifyCreated(string $uuid): array
    {
        return ['uuid' => $uuid];
    }

    /** 409 vid domänkonflikt. Innehållet läses inte av klienten. */
    public static function coolifyConflict(): array
    {
        return ['message' => 'Domain conflicts detected.', 'warning' => 'Using the same domain for multiple resources can cause routing conflicts.', 'conflicts' => []];
    }

    /** GET /applications/{uuid}/envs (spike S15), EnvironmentVariable-schemat. */
    public static function coolifyEnv(string $key, ?string $value, bool $buildtime = true): array
    {
        return ['uuid' => 'env-'.strtolower($key), 'key' => $key, 'value' => $value, 'is_buildtime' => $buildtime, 'is_runtime' => false, 'is_preview' => false];
    }

    /**
     * GET /deployments/applications/{uuid}?skip=&take= (spike S22): Application::deployments()
     * svarar {count, deployments}, nyast först. Varje post är en ApplicationDeploymentQueue.
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $deployments  [deployment_uuid, status, created_at]
     */
    public static function coolifyDeployments(array $deployments): array
    {
        return [
            'count' => count($deployments),
            'deployments' => array_map(fn (array $d) => [
                'deployment_uuid' => $d[0],
                'status' => $d[1],
                'created_at' => $d[2] ?? '2026-10-14T08:00:00.000000Z',
                'commit' => 'abc123',
                'logs' => 'Building... token=hemligt',
            ], $deployments),
        ];
    }

    /** DELETE /applications/{uuid}, 200. */
    public static function coolifyDeleteQueued(): array
    {
        return ['message' => 'Application deletion request queued.'];
    }

    // --- SiteOps (låst kontrakt i 2a, "Låst API-kontrakt") ---

    /** Webbdetalj (formatSiteListItem + formatSiteDetail, 2a Task 6 lägger till monitoring_enabled). */
    public static function siteopsSiteData(int $id, string $url, bool $active = true, bool $monitoring = true): array
    {
        return [
            'id' => $id, 'name' => 'Spel', 'url' => $url, 'type' => 'app', 'is_active' => $active,
            'is_online' => false, 'ops_tier' => 2, 'ops_mode' => 'supervised', 'solution_id' => 51,
            'monitoring_enabled' => $monitoring,
        ];
    }

    /** Miljö som 2a:s formatApiEnvironment serialiserar den (PATCH-svar och environment i POST-svaret). */
    public static function siteopsEnvironment(int $siteId, string $url, int $id = 7, bool $monitored = true, bool $basicAuth = true, string $type = 'demo'): array
    {
        return ['id' => $id, 'site_id' => $siteId, 'name' => 'Demo', 'type' => $type, 'url' => $url, 'is_monitored' => $monitored, 'has_basic_auth' => $basicAuth];
    }

    /** POST /sites med environment, 201. */
    public static function siteopsCreated(int $id, string $url = 'https://kattsvep.apps.humblebrag.se', int $environmentId = 7): array
    {
        return ['data' => self::siteopsSiteData($id, $url) + ['environment' => self::siteopsEnvironment($id, $url, $environmentId)]];
    }

    /** POST /sites, 409 när adressen redan finns i teamet. */
    public static function siteopsConflict(int $existingId): array
    {
        return ['error' => 'A site with this URL already exists in the team.', 'site_id' => $existingId];
    }

    /** GET /sites?url= */
    public static function siteopsList(array $sites): array
    {
        return ['data' => $sites, 'meta' => ['total' => count($sites)]];
    }

    /** GET /sites/{id}. */
    public static function siteopsSite(int $id, string $url, bool $active = true, bool $monitoring = true): array
    {
        return ['data' => self::siteopsSiteData($id, $url, $active, $monitoring)];
    }

    /** GET /sites/{id}/environments (2a Task 6 lägger till type och has_basic_auth). */
    public static function siteopsEnvironments(string $url, bool $monitored = true, bool $basicAuth = true, string $type = 'demo'): array
    {
        return ['data' => [['id' => 7, 'name' => 'Demo', 'type' => $type, 'url' => $url, 'sort_order' => 0, 'is_monitored' => $monitored, 'has_basic_auth' => $basicAuth]], 'meta' => ['total' => 1]];
    }

    /** PATCH /sites/{id}/environments/{env}, 200. */
    public static function siteopsEnvironmentUpdated(int $siteId, string $url, int $envId = 7): array
    {
        return ['data' => self::siteopsEnvironment($siteId, $url, $envId)];
    }

    /** POST /sites/{id}/deactivate, 200. */
    public static function siteopsDeactivated(int $id): array
    {
        return ['data' => ['id' => $id, 'is_active' => false, 'monitoring_enabled' => false]];
    }
}
