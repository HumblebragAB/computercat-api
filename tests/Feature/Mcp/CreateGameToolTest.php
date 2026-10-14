<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\McpAuditLog;
use App\Models\ProvisioningRun;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class CreateGameToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    private function args(array $overrides = []): array
    {
        return array_merge(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'idea' => 'Minröj där katter letar bajs, daglig topplista för snabbaste rensning.'], $overrides);
    }

    public function test_starts_saga_and_answers_with_run(): void
    {
        $user = $this->staffMember();

        $result = $this->callTool($user, 'create_game', $this->args());

        $this->assertTrue($result['ok'], $result['text']);
        $run = ProvisioningRun::sole();
        $this->assertSame(['kattsvep', 'kattsvep', 'Kattsvep', $user->id, ProvisioningRun::KIND_PROVISION, ProvisioningRun::RUNNING], [$run->slug, $run->slug_lock, $run->name, $run->user_id, $run->kind, $run->status]);
        $this->assertSame(array_keys(Saga::PROVISION_STEPS), $run->steps()->pluck('step')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $run->id);
        $json = json_decode($result['text'], true);
        $this->assertSame($run->uuid, $json['run']);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $json['demo_url']);
        $this->assertSame('HumblebragAB/kattsvep', $json['repo']);
        $this->assertSame('ok', McpAuditLog::where('tool', 'create_game')->sole()->outcome);
    }

    public function test_missing_settings_are_named_and_nothing_is_created(): void
    {
        config(['provisioning.coolify.token' => '', 'provisioning.siteops.api_key' => null]);

        $result = $this->callTool($this->staffMember(), 'create_game', $this->args());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('COOLIFY_TOKEN', $result['text']);
        $this->assertStringContainsString('SITEOPS_API_KEY', $result['text']);
        $this->assertStringNotContainsString(ProvisioningFakes::SITEOPS_KEY, $result['text']);
        $this->assertSame(0, ProvisioningRun::count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_and_reserved_slugs_are_rejected(): void
    {
        $user = $this->staffMember();

        foreach (['Kattsvep', 'ab', 'katt--svep', 'api', 'spel', "katt\nsvep"] as $slug) {
            $this->assertFalse($this->callTool($user, 'create_game', $this->args(['slug' => $slug]))['ok'], $slug);
        }

        $this->assertSame(0, ProvisioningRun::count());
    }

    public function test_slug_used_by_game_or_held_by_run_is_rejected(): void
    {
        $user = $this->staffMember();
        $this->game('ordjakt');
        $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::FAILED]);

        $this->assertStringContainsString('already used by a game', $this->callTool($user, 'create_game', $this->args(['slug' => 'ordjakt']))['text']);
        $this->assertStringContainsString('reserved by another provisioning run', $this->callTool($user, 'create_game', $this->args())['text']);
    }

    public function test_released_slug_can_be_used_again(): void
    {
        $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::RELEASED]);

        $this->assertTrue($this->callTool($this->staffMember(), 'create_game', $this->args())['ok']);
    }

    public function test_total_cap_counts_running_succeeded_and_failed_only(): void
    {
        $user = $this->staffMember();
        foreach (range(1, 14) as $i) {
            $this->provisioningRun(['status' => [ProvisioningRun::RUNNING, ProvisioningRun::SUCCEEDED, ProvisioningRun::FAILED][$i % 3]]);
        }
        $this->provisioningRun(['status' => ProvisioningRun::RELEASED]);
        $this->provisioningRun(['status' => ProvisioningRun::ARCHIVED]);
        $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'status' => ProvisioningRun::RUNNING]);

        $this->assertTrue($this->callTool($user, 'create_game', $this->args(['slug' => 'nummer-femton']))['ok']);

        $result = $this->callTool($user, 'create_game', $this->args(['slug' => 'nummer-sexton']));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('limit of 15 Demo games', $result['text']);
    }

    public function test_daily_cap_is_per_user_and_counts_released_runs(): void
    {
        $user = $this->staffMember();
        $other = $this->staffMember();
        foreach (range(1, 4) as $i) {
            $this->provisioningRun(['user_id' => $user->id, 'status' => ProvisioningRun::RELEASED]);
        }
        $this->provisioningRun(['user_id' => $other->id]);
        $this->travel(-25)->hours();
        $this->provisioningRun(['user_id' => $user->id]);
        $this->travelBack();

        $this->assertTrue($this->callTool($user, 'create_game', $this->args(['slug' => 'femte']))['ok']);

        $result = $this->callTool($user, 'create_game', $this->args(['slug' => 'sjatte']));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('5 games in the last 24 hours', $result['text']);
        $this->assertTrue($this->callTool($other, 'create_game', $this->args(['slug' => 'annans']))['ok']);
    }

    public function test_idea_is_required_and_bounded(): void
    {
        $user = $this->staffMember();

        $this->assertFalse($this->callTool($user, 'create_game', $this->args(['idea' => '']))['ok']);
        $this->assertFalse($this->callTool($user, 'create_game', $this->args(['idea' => str_repeat('x', 4001)]))['ok']);
        $this->assertSame(0, ProvisioningRun::count());
    }
}
