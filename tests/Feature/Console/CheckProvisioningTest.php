<?php

namespace Tests\Feature\Console;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\ProvisioningRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\ProvisioningFakes;

class CheckProvisioningTest extends ProvisioningTestCase
{
    private const DEMO = 'https://kattsvep.apps.humblebrag.se';

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private ProvisioningRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->provisioningRun([
            'slug' => 'kattsvep', 'status' => ProvisioningRun::SUCCEEDED, 'github_repo' => 'HumblebragAB/kattsvep',
            'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => self::DEMO,
            // Demo har levt: övervakningen är på (fallet utan står i egna tester).
            'monitoring_enabled_at' => now()->subDay(),
        ]);
    }

    private function fakeHealthy(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', $this->run->uuid)),
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'), ProvisioningFakes::coolifyApp('app-x', 'ordjakt')])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')),
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished']])),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO)),
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::DEMO)),
            self::DEMO => Http::response('<html></html>'),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ], $overrides));
    }

    public function test_healthy_state_is_green_and_silent(): void
    {
        $this->fakeHealthy();

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
    }

    public function test_archived_repo_missing_basic_auth_and_unmonitored_env_are_reported_once_per_day(): void
    {
        $this->fakeHealthy([
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'x', archived: true)),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep', basicAuth: false)),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, monitored: false)),
        ]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('3 avvikelse(r).')->assertExitCode(1);
        $this->artisan('computercat:check-provisioning')->assertExitCode(1);

        $this->assertCount(3, Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK));
    }

    public function test_siteops_monitoring_off_is_a_deviation(): void
    {
        $this->fakeHealthy([
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::DEMO, monitoring: false)),
        ]);
        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('monitoring_enabled')->assertExitCode(1);
    }

    // Egen test: ett andra Http::fake i samma test läggs efter det första och vinner aldrig.
    public function test_siteops_demo_environment_without_basic_auth_is_a_deviation(): void
    {
        $this->fakeHealthy([
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO.'/', basicAuth: false)),
        ]);
        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('saknar basic auth')->assertExitCode(1);
    }

    public function test_orphan_app_and_stuck_run_are_reported(): void
    {
        $this->fakeHealthy([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'), ProvisioningFakes::coolifyApp('app-9', 'spel-okand')])),
        ]);
        $stuck = $this->provisioningRun(['slug' => 'fastnad']);
        $stuck->steps()->create(['step' => 'repo', 'status' => 'pending', 'attempts' => 1]);
        $this->travel(31)->minutes();

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('coolify:orphan:app-9')
            ->expectsOutputToContain("run:{$stuck->uuid}:stuck")
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
    }

    public function test_failed_runs_older_than_a_day_are_reported(): void
    {
        $this->fakeHealthy();
        $old = $this->provisioningRun(['slug' => 'havererad', 'status' => ProvisioningRun::FAILED]);
        $this->travel(25)->hours();

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain("run:{$old->uuid}:failed")
            ->assertExitCode(1);
    }

    public function test_api_errors_become_deviations_not_crashes(): void
    {
        $this->fakeHealthy([
            self::PROJECT_URL => Http::response(['message' => 'Unauthenticated.'], 401),
            self::DEMO => Http::response('Bad Gateway', 502),
        ]);

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('coolify:list')
            ->expectsOutputToContain("run:{$this->run->uuid}:demo")
            ->assertExitCode(1);
    }

    // Redo för kod men ingen push än: tyst i karenstiden, sedan en avvikelse.
    public function test_game_without_deployment_is_quiet_for_7_days_then_reported(): void
    {
        $this->run->update(['monitoring_enabled_at' => null]);
        $this->fakeHealthy([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([])),
            self::DEMO => Http::response('Not Found', 404),
        ]);

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('väntar på första lyckade deploy')
            ->expectsOutput('0 avvikelse(r).')
            ->assertExitCode(0);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), self::DEMO));

        $this->travel(8)->days();

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain("run:{$this->run->uuid}:not_live")
            ->assertExitCode(1);
    }

    // Första lyckade deploy: utan --dry-run slås SiteOps-övervakningen på, en gång.
    public function test_first_live_deploy_enables_monitoring_but_not_in_dry_run(): void
    {
        $this->run->update(['monitoring_enabled_at' => null]);
        $this->fakeHealthy([
            'siteops.test/api/v1/sites/42/environments/7' => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated(42, self::DEMO)),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, monitored: false)),
        ]);

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('skulle slås på')
            ->expectsOutput('0 avvikelse(r).')
            ->assertExitCode(0);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');

        $this->artisan('computercat:check-provisioning')
            ->expectsOutputToContain('övervakningen i SiteOps påslagen')
            ->assertExitCode(0);
        $this->assertNotNull($this->run->fresh()->monitoring_enabled_at);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
    }

    public function test_failed_latest_build_is_a_deviation(): void
    {
        $this->fakeHealthy([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed'], ['dep-1', 'finished']])),
        ]);

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain("run:{$this->run->uuid}:deploy_failed")
            ->assertExitCode(1);
    }

    public function test_missing_configuration_fails(): void
    {
        config(['provisioning.coolify.token' => '']);
        Http::fake();

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('COOLIFY_TOKEN')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_nightly_check_records_demo_receipts(): void
    {
        $this->run->update(['game_id' => Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []])->id]);
        $this->fakeHealthy();

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        $this->assertSame([str_repeat('a', 40)], DemoBuild::pluck('commit_sha')->all());
    }

    public function test_is_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'computercat:check-provisioning'));

        $this->assertCount(1, $events);
        $this->assertSame('15 6 * * *', $events->first()->expression);
    }
}
