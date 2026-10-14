<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\CoolifyConflictException;
use App\Provisioning\Clients\CoolifyGuardException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class CoolifyClientTest extends ProvisioningTestCase
{
    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private function client(): CoolifyClient
    {
        return app(CoolifyClient::class);
    }

    public function test_create_sends_exact_body_and_returns_uuid(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201)]);

        $this->assertSame('app-1', $this->client()->createApplication('kattsvep'));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->header('Authorization')[0] === 'Bearer '.ProvisioningFakes::COOLIFY_TOKEN
            && $r->data() === [
                'project_uuid' => 'demo-project-uuid',
                'server_uuid' => 'server-uuid',
                'environment_name' => 'production',
                'github_app_uuid' => 'coolify-github-app-uuid',
                'git_repository' => 'HumblebragAB/kattsvep',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'name' => 'spel-kattsvep',
                'domains' => 'https://kattsvep.apps.humblebrag.se',
                'ports_exposes' => '80',
                'is_static' => true,
                'static_image' => 'nginx:alpine',
                'publish_directory' => 'out',
                // Bilaga A S:793. Demo-bygget kör inga installationsskript (byggguide v1).
                'install_command' => 'npm ci --ignore-scripts',
                'is_auto_deploy_enabled' => true,
                'instant_deploy' => false,
                'is_http_basic_auth_enabled' => true,
                'http_basic_auth_username' => 'demo',
                'http_basic_auth_password' => 'demo',
            ]);
    }

    public function test_create_409_is_a_conflict(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyConflict(), 409)]);

        $this->expectException(CoolifyConflictException::class);
        $this->client()->createApplication('kattsvep');
    }

    public function test_failed_create_logs_redacted_request_and_response(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(['message' => 'Validation failed', 'errors' => ['http_basic_auth_password' => ['demo']]], 422)]);
        config(['provisioning.demo.basic_auth_password' => 'losenord-som-inte-far-synas']);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m;
        });

        try {
            $this->client()->createApplication('kattsvep');
            $this->fail('422 kastade inte.');
        } catch (\App\Provisioning\Http\ExternalApiException $e) {
            $this->assertStringContainsString('Coolify POST /applications/private-github-app returned 422', $e->getMessage());
        }

        $all = json_encode(array_map(fn ($m) => [$m->message, $m->context], $logged));
        $this->assertStringContainsString('"status":422', $all);
        $this->assertStringNotContainsString('losenord-som-inte-far-synas', $all);
        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $all);
    }

    public function test_demo_applications_and_lookup_by_name(): void
    {
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([
            ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'),
            ProvisioningFakes::coolifyApp('app-2', 'ordjakt'),
        ]))]);

        $this->assertSame([['uuid' => 'app-1', 'name' => 'spel-kattsvep'], ['uuid' => 'app-2', 'name' => 'ordjakt']], $this->client()->demoApplications());
        $this->assertSame('app-1', $this->client()->findDemoApplicationByName('spel-kattsvep')['uuid']);
        $this->assertNull($this->client()->findDemoApplicationByName('spel-annat'));
    }

    public function test_delete_refuses_unknown_uuid_without_any_request(): void
    {
        Http::fake();

        try {
            $this->client()->deleteApplication('okand-app');
            $this->fail('Spärren släppte igenom en okänd uuid.');
        } catch (CoolifyGuardException) {
            // väntat
        }

        Http::assertNothingSent();
    }

    public function test_delete_refuses_app_outside_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-9', 'spel-annat')]))]);

        try {
            $this->client()->deleteApplication('app-1');
            $this->fail('Spärren släppte igenom en app utanför Demo-projektet.');
        } catch (CoolifyGuardException) {
            // väntat
        }

        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_delete_known_app_in_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
        ]);

        $this->client()->deleteApplication('app-1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://coolify.test/api/v1/applications/app-1');
    }

    public function test_build_variable_is_guarded_and_uses_post(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::response(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201),
        ]);

        $this->client()->createBuildVariable('app-1', 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/applications/app-1/envs') && $r->data() === [
            'key' => 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'value' => 'kattsvep', 'is_preview' => false, 'is_buildtime' => true, 'is_runtime' => false,
        ]);
    }

    public function test_latest_deployment_reads_the_newest_and_validates_status(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        $this->provisioningRun(['coolify_app_uuid' => 'app-2']);
        $this->provisioningRun(['coolify_app_uuid' => 'app-3']);
        Http::fake([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'in_progress', '2026-10-14T09:00:00.000000Z'], ['dep-1', 'finished']])),
            'coolify.test/api/v1/deployments/applications/app-2*' => Http::response(ProvisioningFakes::coolifyDeployments([])),
            'coolify.test/api/v1/deployments/applications/app-3*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-9', 'weird']])),
        ]);

        $this->assertSame(['deployment_uuid' => 'dep-2', 'status' => 'in_progress', 'created_at' => '2026-10-14T09:00:00.000000Z'], $this->client()->latestDeployment('app-1'));
        $this->assertNull($this->client()->latestDeployment('app-2'));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://coolify.test/api/v1/deployments/applications/app-1?skip=0&take=1');

        $this->expectException(\App\Provisioning\Http\ExternalApiException::class);
        $this->client()->latestDeployment('app-3');
    }

    // Läsning kräver också att uuid:t kommer från en run (spärren gäller alla anrop med app-uuid).
    public function test_latest_deployment_refuses_unknown_uuid(): void
    {
        Http::fake();

        $this->expectException(CoolifyGuardException::class);
        try {
            $this->client()->latestDeployment('okand');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_client_cannot_start_a_build(): void
    {
        foreach ((new \ReflectionClass(CoolifyClient::class))->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression('/^(deploy|start|restart|redeploy)/i', $method->getName());
        }
    }

    // Invariant: Demo-bygget installerar exakt som byggguiden säger.
    public function test_install_command_matches_the_build_guide(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201)]);

        $this->client()->createApplication('kattsvep');

        $install = \App\Services\BuildGuide::manifest(\App\Services\BuildGuide::LATEST)['commands']['install'];
        Http::assertSent(fn ($r) => ($r->data()['install_command'] ?? null) === $install);
    }

    public function test_build_variables_refuse_unknown_uuid_without_any_request(): void
    {
        Http::fake();

        foreach (['createBuildVariable', 'updateBuildVariable'] as $method) {
            try {
                $this->client()->{$method}('okand-app', 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep');
                $this->fail("{$method} släppte igenom en okänd uuid.");
            } catch (CoolifyGuardException) {
                // väntat
            }
        }

        Http::assertNothingSent();
    }

    public function test_build_variables_refuse_app_outside_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-9', 'spel-annat')]))]);

        foreach (['createBuildVariable', 'updateBuildVariable'] as $method) {
            try {
                $this->client()->{$method}('app-1', 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep');
                $this->fail("{$method} släppte igenom en app utanför Demo-projektet.");
            } catch (CoolifyGuardException) {
                // väntat
            }
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/envs'));
        // Spärren läste den konfigurerade miljön, inte någon annan.
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://coolify.test/api/v1/projects/demo-project-uuid/production');
    }

    public function test_update_build_variable_patches_in_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::response(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201),
        ]);

        $this->client()->updateBuildVariable('app-1', 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://coolify.test/api/v1/applications/app-1/envs' && $r->data() === [
            'key' => 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'value' => 'kattsvep', 'is_preview' => false, 'is_buildtime' => true, 'is_runtime' => false,
        ]);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function malformedDeployments(): array
    {
        $valid = ['deployment_uuid' => 'dep-1', 'status' => 'finished', 'created_at' => '2026-10-14T08:00:00.000000Z'];

        return [
            'inte en lista' => [['count' => 1, 'deployments' => ['senaste' => $valid]], 'expected a list'],
            'saknar deployment_uuid' => [['count' => 1, 'deployments' => [array_diff_key($valid, ['deployment_uuid' => 1])]], 'deployment_uuid'],
            'tom deployment_uuid' => [['count' => 1, 'deployments' => [['deployment_uuid' => ''] + $valid]], 'deployment_uuid'],
            'saknar created_at' => [['count' => 1, 'deployments' => [array_diff_key($valid, ['created_at' => 1])]], 'created_at'],
            'post är inte ett objekt' => [['count' => 1, 'deployments' => ['finished']], 'deployment'],
        ];
    }

    // Fail loud: ett svar av oväntad form blir ett fel, aldrig tomma strängar.
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDeployments')]
    public function test_latest_deployment_fails_loud_on_malformed_response(array $body, string $expected): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake(['coolify.test/api/v1/deployments/applications/app-1*' => Http::response($body)]);

        try {
            $this->client()->latestDeployment('app-1');
            $this->fail('Ett felformat svar godtogs.');
        } catch (\App\Provisioning\Http\ExternalApiException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }
}
