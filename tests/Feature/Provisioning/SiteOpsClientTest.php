<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class SiteOpsClientTest extends ProvisioningTestCase
{
    private const URL = 'https://kattsvep.apps.humblebrag.se';

    private function client(): SiteOpsClient
    {
        return app(SiteOpsClient::class);
    }

    public function test_create_demo_site_sends_contract_body_and_headers(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsCreated(42, self::URL), 201)]);

        $this->assertSame(['created' => true, 'id' => 42], $this->client()->createDemoSite('Kattsvep', self::URL));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->header('X-Api-Key')[0] === ProvisioningFakes::SITEOPS_KEY
            && $r->header('Accept')[0] === 'application/json'
            && $r->data() === [
                'name' => 'Kattsvep',
                'url' => self::URL,
                'type' => 'app',
                'environment' => [
                    'type' => 'demo',
                    'name' => 'Demo',
                    'url' => self::URL,
                    'basic_auth_user' => 'demo',
                    'basic_auth_password' => 'demo',
                    // Övervakningen slås på först när Demo lever (DemoMonitoring).
                    'is_monitored' => false,
                ],
            ]);
    }

    public function test_409_returns_existing_site_id_without_throwing(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409)]);

        $this->assertSame(['created' => false, 'id' => 34], $this->client()->createDemoSite('Kattsvep', self::URL));
    }

    public function test_409_without_site_id_throws(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['error' => 'A site with this URL already exists in the team.'], 409)]);

        $this->expectException(ExternalApiException::class);
        $this->client()->createDemoSite('Kattsvep', self::URL);
    }

    public function test_find_by_exact_url_returns_all_ids(): void
    {
        Http::fake(['siteops.test/api/v1/sites?*' => Http::sequence()
            ->push(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::URL)]))
            ->push(ProvisioningFakes::siteopsList([]))
            ->push(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::URL), ProvisioningFakes::siteopsSiteData(43, self::URL.'/')]))]);

        $this->assertSame([42], $this->client()->findIdsByUrl(self::URL));
        $this->assertSame([], $this->client()->findIdsByUrl(self::URL));
        $this->assertSame([42, 43], $this->client()->findIdsByUrl(self::URL));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://siteops.test/api/v1/sites?url='.urlencode(self::URL));
    }

    public function test_claim_patches_demo_values_and_maps_403_to_false(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/environments/7' => Http::sequence()
            ->push(ProvisioningFakes::siteopsEnvironmentUpdated(42, self::URL))
            ->push(['message' => 'This site was not created by this API client.'], 403)]);

        $this->assertTrue($this->client()->claimDemoEnvironment(42, 7));
        $this->assertFalse($this->client()->claimDemoEnvironment(42, 7));

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && $r->data() === ['basic_auth_user' => 'demo', 'basic_auth_password' => 'demo', 'is_monitored' => false]);
    }

    public function test_set_monitored_patches_only_the_flag_and_checks_the_answer(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/environments/7' => Http::sequence()
            ->push(ProvisioningFakes::siteopsEnvironmentUpdated(42, self::URL))
            ->push(['data' => ProvisioningFakes::siteopsEnvironment(42, self::URL, monitored: false)])]);

        $this->client()->setMonitored(42, 7, true);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['is_monitored' => true]);

        $this->expectException(ExternalApiException::class);
        $this->client()->setMonitored(42, 7, true);
    }

    public function test_site_environments_and_deactivate(): void
    {
        Http::fake([
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::URL)),
            'siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42)),
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::URL)),
        ]);

        $this->assertTrue($this->client()->site(42)['monitoring_enabled']);
        $this->assertSame('demo', $this->client()->environments(42)[0]['type']);
        $this->client()->deactivate(42);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://siteops.test/api/v1/sites/42/deactivate');
    }

    public function test_deactivate_that_leaves_monitoring_on_throws(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/deactivate' => Http::response(['data' => ['id' => 42, 'is_active' => false, 'monitoring_enabled' => true]])]);

        $this->expectException(ExternalApiException::class);
        $this->client()->deactivate(42);
    }

    public function test_403_on_create_throws_with_path(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['message' => 'Missing scope demo-sites:write'], 403)]);

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('SiteOps POST /sites returned 403');
        $this->client()->createDemoSite('Kattsvep', self::URL);
    }

    public function test_password_never_reaches_the_log_on_error(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['provisioning.demo.basic_auth_password' => 'markor-'.str_repeat('q', 8)]);
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['message' => 'boom'], 500)]);

        try {
            $this->client()->createDemoSite('Kattsvep', self::URL);
        } catch (ExternalApiException) {
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => ! str_contains(json_encode($context), 'markor-'.str_repeat('q', 8))
        );
    }
}
