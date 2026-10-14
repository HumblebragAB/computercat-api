<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\DemoProbe;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\SiteOpsStep;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class SiteOpsStepTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const DEMO = 'https://kattsvep.apps.humblebrag.se';

    private function siteopsRun(): \App\Models\ProvisioningRun
    {
        return $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'demo_url' => self::DEMO]);
    }

    /** En befintlig webb som uppslaget eller 409 pekar på, i önskat (friskt) läge. */
    private function fakeExistingSite(int $id, array $overrides = []): void
    {
        Http::fake(array_merge([
            "siteops.test/api/v1/sites/{$id}/environments/7" => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated($id, self::DEMO)),
            "siteops.test/api/v1/sites/{$id}/environments" => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO)),
            "siteops.test/api/v1/sites/{$id}" => Http::response(ProvisioningFakes::siteopsSite($id, self::DEMO)),
        ], $overrides));
    }

    public function test_siteops_creates_site_when_lookup_is_empty(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsCreated(42, self::DEMO), 201),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);
        $this->assertSame(42, $run->fresh()->siteops_site_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['url'] === self::DEMO && $r['environment']['is_monitored'] === false);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_own_site_found_by_url_is_adopted_after_claim(): void
    {
        $run = $this->siteopsRun();
        Http::fake(['siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::DEMO)]))]);
        $this->fakeExistingSite(42);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);

        $this->assertSame(42, $run->fresh()->siteops_site_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://siteops.test/api/v1/sites/42/environments/7');
    }

    public function test_409_adopts_own_site_after_claim(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $this->fakeExistingSite(34);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);
        $this->assertSame(34, $run->fresh()->siteops_site_id);
    }

    public function test_409_on_a_site_created_by_someone_else_fails_loudly(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $this->fakeExistingSite(34, [
            'siteops.test/api/v1/sites/34/environments/7' => Http::response(['message' => 'This site was not created by this API client.'], 403),
        ]);

        try {
            $this->runStep(SiteOpsStep::class, $run, 'siteops');
            $this->fail('En främmande webb adopterades.');
        } catch (StepFailed $e) {
            $this->assertStringContainsString('was not created by Computercat', $e->getMessage());
        }
        $this->assertNull($run->fresh()->siteops_site_id);
    }

    /** @return array<string, array{string}> Data providers körs före setUp, så svaren byggs i testet. */
    public static function unadoptableSites(): array
    {
        return [
            'annan adress på webben' => ['other_url'],
            'inaktiv webb' => ['inactive'],
            'ingen demo-miljö med adressen' => ['no_demo_env'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unadoptableSites')]
    public function test_409_on_a_site_that_does_not_match_the_run_fails_without_patch(string $case): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $override = match ($case) {
            'other_url' => ['siteops.test/api/v1/sites/34' => Http::response(ProvisioningFakes::siteopsSite(34, 'https://kund.se'))],
            'inactive' => ['siteops.test/api/v1/sites/34' => Http::response(ProvisioningFakes::siteopsSite(34, self::DEMO, active: false))],
            'no_demo_env' => ['siteops.test/api/v1/sites/34/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, type: 'production'))],
        };
        $this->fakeExistingSite(34, $override);

        try {
            $this->runStep(SiteOpsStep::class, $run, 'siteops');
            $this->fail('En webb som inte matchar runnen adopterades.');
        } catch (StepFailed) {
        }
        $this->assertNull($run->fresh()->siteops_site_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_several_sites_for_the_url_fail_loudly(): void
    {
        $run = $this->siteopsRun();
        Http::fake(['siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([
            ProvisioningFakes::siteopsSiteData(42, self::DEMO), ProvisioningFakes::siteopsSiteData(43, self::DEMO.'/'),
        ]))]);

        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('2 SiteOps sites');
        $this->runStep(SiteOpsStep::class, $run, 'siteops');
    }

    public function test_demo_probe_returns_zero_on_connection_error_and_refuses_foreign_hosts(): void
    {
        Http::fake([self::DEMO => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame(0, DemoProbe::status(self::DEMO));

        $this->expectException(\InvalidArgumentException::class);
        DemoProbe::status('https://example.com');
    }
}
