<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class ArchiveGameToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    private \App\Models\Game $kattsvep;

    private \App\Models\User $owner;

    private ProvisioningRun $provision;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
        $this->kattsvep = $this->game('kattsvep', ['is_listed' => false]);
        $this->owner = $this->staffMember();
        $this->kattsvep->members()->attach($this->owner, ['role' => 'owner']);
        $this->provision = $this->provisioningRun([
            'slug' => 'kattsvep', 'game_id' => $this->kattsvep->id, 'status' => ProvisioningRun::SUCCEEDED,
            'github_repo' => 'HumblebragAB/kattsvep', 'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => 'https://kattsvep.apps.humblebrag.se',
        ]);
    }

    public function test_owner_starts_archive_run_with_copied_ids(): void
    {
        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $archive = ProvisioningRun::where('kind', ProvisioningRun::KIND_ARCHIVE)->sole();
        $this->assertSame(['kattsvep', null, $this->kattsvep->id, 'HumblebragAB/kattsvep', 'app-1', 42, ProvisioningRun::RUNNING],
            [$archive->slug, $archive->slug_lock, $archive->game_id, $archive->github_repo, $archive->coolify_app_uuid, $archive->siteops_site_id, $archive->status]);
        $this->assertSame(array_keys(Saga::ARCHIVE_STEPS), $archive->steps()->pluck('step')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $archive->id);
    }

    public function test_listed_game_is_not_archived(): void
    {
        $this->kattsvep->update(['is_listed' => true]);

        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('only allowed in Demo', $result['text']);
        Queue::assertNothingPushed();
    }

    public function test_non_owner_and_game_without_successful_run_are_refused(): void
    {
        $member = $this->staffMember($this->kattsvep);
        $this->game('ordjakt', ['is_listed' => false])->members()->attach($this->owner, ['role' => 'owner']);

        $this->assertStringContainsString('Only the game owner', $this->callTool($member, 'archive_game', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('successful provisioning run', $this->callTool($this->owner, 'archive_game', ['game' => 'ordjakt'])['text']);
        Queue::assertNothingPushed();
    }

    public function test_second_archive_is_refused_while_one_exists(): void
    {
        $this->assertTrue($this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep'])['ok']);

        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('archive run already exists', $result['text']);
    }
}
