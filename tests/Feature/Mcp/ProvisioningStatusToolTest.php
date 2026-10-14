<?php

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class ProvisioningStatusToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    public function test_member_sees_steps_urls_and_redacted_error(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $game->id, 'github_repo' => 'HumblebragAB/kattsvep', 'demo_url' => 'https://kattsvep.apps.humblebrag.se']);
        Saga::start($run);
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::DONE]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'coolify_app: x '.ProvisioningFakes::COOLIFY_TOKEN]);

        $result = $this->callTool($member, 'get_provisioning_status', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame('failed', $json['provisioning']['status']);
        $this->assertSame(['step' => 'repo', 'status' => 'done', 'attempts' => 0], $json['provisioning']['steps'][0]);
        $this->assertSame('https://github.com/HumblebragAB/kattsvep', $json['repo_url']);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $json['demo_url']);
        $this->assertSame(['username' => 'demo', 'password' => 'demo'], $json['demo_basic_auth']);
        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $result['text']);
        $this->assertNull($json['archive']);
        // Bara en lyckad run får demo-läget (och bara då anropas Coolify).
        $this->assertNull($json['demo']);
    }

    public function test_creator_sees_run_before_game_exists_but_others_do_not(): void
    {
        $creator = $this->staffMember();
        $other = $this->staffMember();
        $this->provisioningRun(['slug' => 'kattsvep', 'user_id' => $creator->id, 'status' => ProvisioningRun::RELEASED, 'last_error' => 'repo: Slug is taken on GitHub.']);

        $mine = $this->callTool($creator, 'get_provisioning_status', ['game' => 'kattsvep']);
        $theirs = $this->callTool($other, 'get_provisioning_status', ['game' => 'kattsvep']);

        $this->assertTrue($mine['ok'], $mine['text']);
        $this->assertStringContainsString('Slug is taken on GitHub', $mine['text']);
        $this->assertFalse($theirs['ok']);
        $this->assertStringContainsString('Game not found', $theirs['text']);
        $this->assertSame(['ok', 'denied'], McpAuditLog::where('tool', 'get_provisioning_status')->orderBy('id')->pluck('outcome')->all());
    }

    private function succeededRun(): ProvisioningRun
    {
        $game = $this->game('kattsvep');
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $game->id, 'status' => ProvisioningRun::SUCCEEDED, 'github_repo' => 'HumblebragAB/kattsvep', 'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => 'https://kattsvep.apps.humblebrag.se']);
        Saga::start($run);
        $run->steps()->update(['status' => ProvisioningStep::DONE]);

        return $run;
    }

    private function demoFor(array $fakes): array
    {
        $run = $this->succeededRun();
        Http::fake($fakes);

        $result = $this->callTool($this->staffMember(Game::where('slug', 'kattsvep')->firstOrFail()), 'get_provisioning_status', ['game' => 'kattsvep']);
        $this->assertTrue($result['ok'], $result['text']);

        return json_decode($result['text'], true)['demo'];
    }

    // Redo för kod: ingen push än. Inget fel, men en tydlig nästa åtgärd.
    public function test_succeeded_run_without_any_build_is_awaiting_code(): void
    {
        $demo = $this->demoFor(['coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([]))]);

        $this->assertSame('awaiting_code', $demo['state']);
        $this->assertSame('pending', $demo['monitoring']);
        $this->assertNull($demo['latest_deployment']);
        $this->assertNull($demo['url_status']);
        $this->assertStringContainsString('get_build_guide', $demo['next']);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://kattsvep.apps.humblebrag.se'));
    }

    // Första gången Demo lever slås SiteOps-övervakningen på, en gång.
    public function test_finished_build_and_answering_demo_is_live_and_enables_monitoring_once(): void
    {
        $fakes = [
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('<html></html>'),
            'siteops.test/api/v1/sites/42/environments/7' => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated(42, 'https://kattsvep.apps.humblebrag.se')),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments('https://kattsvep.apps.humblebrag.se', monitored: false)),
        ];
        $demo = $this->demoFor($fakes);

        $this->assertSame(['state' => 'live', 'latest_deployment' => ['status' => 'finished', 'created_at' => '2026-10-14T08:00:00.000000Z'], 'url_status' => 200, 'monitoring' => 'enabled'], $demo);
        $this->assertNotNull(ProvisioningRun::where('slug', 'kattsvep')->value('monitoring_enabled_at'));

        $again = $this->callTool($this->staffMember(Game::where('slug', 'kattsvep')->firstOrFail()), 'get_provisioning_status', ['game' => 'kattsvep']);
        $this->assertSame('enabled', json_decode($again['text'], true)['demo']['monitoring']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
    }

    public function test_failed_build_is_reported_with_the_demo_status(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ]);

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertSame(502, $demo['url_status']);
        $this->assertSame('pending', $demo['monitoring']);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_finished_build_but_demo_not_answering(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ]);

        $this->assertSame('deployed_not_answering', $demo['state']);
    }

    // Fail loud: ett Coolify-fel döljs inte som "awaiting_code".
    public function test_coolify_error_is_shown_not_hidden(): void
    {
        $demo = $this->demoFor(['coolify.test/api/v1/deployments/applications/app-1*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->assertSame('unknown', $demo['state']);
        $this->assertNotEmpty($demo['error']);
        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $demo['error']);
    }

    public function test_game_without_run_answers_clearly(): void
    {
        $game = $this->game('ordjakt');

        $result = $this->callTool($this->staffMember($game), 'get_provisioning_status', ['game' => 'ordjakt']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no provisioning run', $result['text']);
    }
}
