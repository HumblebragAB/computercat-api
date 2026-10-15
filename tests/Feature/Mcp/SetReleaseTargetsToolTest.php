<?php

namespace Tests\Feature\Mcp;

use App\Jobs\RunAppStoreSetup;
use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesReleases;

class SetReleaseTargetsToolTest extends McpTestCase
{
    use CreatesReleases;

    private Game $game;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = $this->game('kattsvep');
        $this->admin = $this->adminUser();
        Queue::fake();
    }

    private function set(string $targets, ?User $user = null, string $slug = 'kattsvep'): array
    {
        return $this->callTool($user ?? $this->admin, 'set_release_targets', ['game' => $slug, 'targets' => $targets]);
    }

    public function test_admin_sets_targets_and_gets_setup_steps(): void
    {
        $result = $this->set('web,app_store');

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(['web', 'app_store'], $json['release_targets']);
        $this->assertContains('web_public_address', array_column($json['open_setup_steps'], 'key'));
        $this->assertContains('appstore_create_app', array_column($json['open_setup_steps'], 'key'));
        $this->assertStringContainsString('Väntar på ett engångssteg', $json['next']);
        $this->assertSame(['web', 'app_store'], $this->game->fresh()->releaseTargets());
    }

    public function test_audit_log_keeps_the_targets(): void
    {
        $this->set('web, app_store');

        $log = McpAuditLog::where('tool', 'set_release_targets')->latest('id')->first();
        $this->assertSame('web, app_store', $log->arguments['targets']);
    }

    public function test_only_platform_admin_may_set_targets(): void
    {
        $owner = $this->ownerOf($this->game);

        $result = $this->set('web', $owner);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only a platform admin', $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
    }

    public function test_invalid_input_is_a_validation_error(): void
    {
        foreach (['ios', 'web,nonsense', 'web;app_store', str_repeat('web,', 40)] as $bad) {
            $result = $this->set($bad);
            $this->assertFalse($result['ok'], $bad);
            $this->assertStringContainsString('Validation failed', $result['text'], $bad);
        }
        $result = $this->callTool($this->admin, 'set_release_targets', ['game' => 'kattsvep', 'targets' => ['web']]);
        $this->assertStringContainsString('Validation failed', $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
    }

    public function test_duplicates_collapse_and_empty_string_clears(): void
    {
        $this->set('web,web');
        $this->assertSame(['web'], $this->game->fresh()->releaseTargets());

        $result = $this->set('');

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
        $this->assertStringContainsString('inga produktionsmål', json_decode($result['text'], true)['next']);
    }

    public function test_calling_twice_keeps_done_steps(): void
    {
        $this->set('web');
        ReleaseSetupStep::where('key', 'web_public_address')->update(['status' => 'done']);

        $this->set('web,play_store');

        $this->assertSame('done', ReleaseSetupStep::where('key', 'web_public_address')->value('status'));
        $this->assertSame(1, ReleaseSetupStep::where('key', 'web_public_address')->count());
        $this->assertTrue(ReleaseSetupStep::where('key', 'play_create_app')->exists());
    }

    public function test_removing_a_target_with_a_pending_release_is_refused(): void
    {
        $this->set('web,app_store');
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::REQUESTED]);

        $result = $this->set('web');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pending release', $result['text']);
        $this->assertSame(['web', 'app_store'], $this->game->fresh()->releaseTargets());

        // En avslutad release hindrar inte. Status ändras alltid via modellen (active_key sätts i saving-kroken).
        Release::where('game_id', $this->game->id)->get()->each->update(['status' => Release::CANCELLED]);
        $this->assertTrue($this->set('web')['ok']);
    }

    public function test_app_store_gets_a_bundle_id_and_queues_the_setup(): void
    {
        $result = $this->set('app_store');

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame('se.computercat.kattsvep', $this->game->fresh()->bundle_id);
        Queue::assertPushed(RunAppStoreSetup::class, fn (RunAppStoreSetup $job) => $job->gameId === $this->game->id);
        $this->assertStringContainsString('bundle-id se.computercat.kattsvep', $result['text']);
    }

    public function test_colliding_bundle_id_is_refused(): void
    {
        $other = $this->game('ab-c');
        $other->forceFill(['bundle_id' => 'se.computercat.abc'])->save();
        $this->game('abc');

        $result = $this->set('app_store', slug: 'abc');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('se.computercat.abc', $result['text']);
        $this->assertStringNotContainsString('ab-c', $result['text']);
        $this->assertSame([], Game::where('slug', 'abc')->first()->releaseTargets());
        Queue::assertNothingPushed();
    }

    public function test_web_only_queues_nothing(): void
    {
        $this->set('web');

        Queue::assertNothingPushed();
        $this->assertNull($this->game->fresh()->bundle_id);
    }
}
