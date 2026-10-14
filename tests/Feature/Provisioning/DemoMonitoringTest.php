<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Provisioning\DemoMonitoring;
use App\Provisioning\StepFailed;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class DemoMonitoringTest extends ProvisioningTestCase
{
    private const DEMO = 'https://kattsvep.apps.humblebrag.se';

    private function liveRun(array $attrs = []): ProvisioningRun
    {
        return $this->provisioningRun($attrs + ['slug' => 'kattsvep', 'status' => ProvisioningRun::SUCCEEDED, 'siteops_site_id' => 42, 'demo_url' => self::DEMO]);
    }

    public function test_first_live_deploy_enables_monitoring_once_and_records_it(): void
    {
        $run = $this->liveRun();
        Http::fake([
            'siteops.test/api/v1/sites/42/environments/7' => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated(42, self::DEMO)),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, monitored: false)),
        ]);

        $this->assertTrue(app(DemoMonitoring::class)->enableIfLive($run, ['status' => 'finished'], 200));
        $this->assertNotNull($run->fresh()->monitoring_enabled_at);
        $this->assertFalse(app(DemoMonitoring::class)->enableIfLive($run->fresh(), ['status' => 'finished'], 200));

        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['is_monitored' => true]);
    }

    public function test_nothing_happens_before_demo_is_live(): void
    {
        $run = $this->liveRun();
        Http::fake();

        $monitoring = app(DemoMonitoring::class);
        $this->assertFalse($monitoring->enableIfLive($run, null, null));
        $this->assertFalse($monitoring->enableIfLive($run, ['status' => 'in_progress'], null));
        $this->assertFalse($monitoring->enableIfLive($run, ['status' => 'finished'], 502));
        $this->assertFalse($monitoring->enableIfLive($run, ['status' => 'failed'], 200));

        Http::assertNothingSent();
        $this->assertNull($run->fresh()->monitoring_enabled_at);
    }

    public function test_missing_demo_environment_fails_loudly(): void
    {
        $run = $this->liveRun();
        Http::fake(['siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments('https://annan.apps.humblebrag.se'))]);

        $this->expectException(StepFailed::class);
        app(DemoMonitoring::class)->enableIfLive($run, ['status' => 'finished'], 200);
    }
}
