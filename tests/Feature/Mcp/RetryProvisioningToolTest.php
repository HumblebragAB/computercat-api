<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class RetryProvisioningToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    private function failedRun(array $attrs): ProvisioningRun
    {
        $run = $this->provisioningRun($attrs + ['status' => ProvisioningRun::FAILED]);
        Saga::start($run);
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::FAILED]);

        return $run;
    }

    public function test_owner_retries_failed_run(): void
    {
        $game = $this->game('kattsvep');
        $owner = $this->staffMember();
        $game->members()->attach($owner, ['role' => 'owner']);
        $run = $this->failedRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $result = $this->callTool($owner, 'retry_provisioning', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame(ProvisioningRun::RUNNING, $run->fresh()->status);
        $this->assertSame('repo', json_decode($result['text'], true)['resumes_at']);
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_member_who_is_not_owner_cannot_retry(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);
        $run = $this->failedRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $result = $this->callTool($member, 'retry_provisioning', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only the game owner', $result['text']);
        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_running_and_released_runs_are_not_retried(): void
    {
        $creator = $this->staffMember();
        $this->provisioningRun(['slug' => 'kattsvep', 'user_id' => $creator->id, 'status' => ProvisioningRun::RUNNING]);
        $this->provisioningRun(['slug' => 'annat', 'user_id' => $creator->id, 'status' => ProvisioningRun::RELEASED]);

        $this->assertStringContainsString('Nothing to retry', $this->callTool($creator, 'retry_provisioning', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('slug was released', $this->callTool($creator, 'retry_provisioning', ['game' => 'annat'])['text']);
        Queue::assertNothingPushed();
    }

    public function test_creator_can_retry_run_without_game(): void
    {
        $creator = $this->staffMember();
        $other = $this->staffMember();
        $run = $this->failedRun(['slug' => 'kattsvep', 'user_id' => $creator->id]);

        $this->assertFalse($this->callTool($other, 'retry_provisioning', ['game' => 'kattsvep'])['ok']);
        $this->assertTrue($this->callTool($creator, 'retry_provisioning', ['game' => 'kattsvep'])['ok']);
        $this->assertSame(ProvisioningRun::RUNNING, $run->fresh()->status);
    }
}
