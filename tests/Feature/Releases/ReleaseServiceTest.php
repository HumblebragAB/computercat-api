<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseServiceTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store', 'play_store']);
        $this->owner = $this->ownerOf($this->game);
    }

    private function service(): ReleaseService
    {
        return app(ReleaseService::class);
    }

    private function promote(string $target, string $stage, ?string $commit = null, ?User $user = null): Release
    {
        return $this->service()->promote($this->game->fresh(), $user ?? $this->owner, $target, $stage, $commit);
    }

    private function assertRefused(callable $call, string $needle): void
    {
        try {
            $call();
            $this->fail('Förväntade ReleaseRefused.');
        } catch (ReleaseRefused $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_test_stage_defaults_to_the_latest_receipt_and_is_requested(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $this->demoBuild($this->game, $this->sha('b'), now());

        $release = $this->promote('play_store', 'play_internal');

        $this->assertSame(Release::REQUESTED, $release->status);
        $this->assertSame($this->sha('b'), $release->commit_sha);
        $this->assertSame($this->owner->id, $release->requested_by);
        $this->assertNull($release->approved_at);
    }

    public function test_an_explicit_commit_wins_over_the_default(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $this->demoBuild($this->game, $this->sha('b'), now());

        $this->assertSame($this->sha('a'), $this->promote('play_store', 'play_internal', $this->sha('a'))->commit_sha);
    }

    public function test_only_the_owner_may_promote(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $member = User::factory()->staff()->create();
        $this->game->members()->attach($member, ['role' => 'member']);

        $this->assertRefused(fn () => $this->promote('play_store', 'play_internal', null, $member), 'Only the game owner');
        $this->assertSame(0, Release::count());
        // Platform admin räknas som ägare (GameAccess::isOwner).
        $this->assertSame(Release::REQUESTED, $this->promote('play_store', 'play_internal', null, $this->adminUser())->status);
    }

    public function test_first_production_release_awaits_approval_and_later_ones_do_not(): void // spärr 3
    {
        $this->demoBuild($this->game);

        $first = $this->promote('web', 'web_production', $this->sha());
        $this->assertSame(Release::AWAITING_APPROVAL, $first->status);

        $first->update(['status' => Release::SUCCEEDED]);
        $second = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::REQUESTED, $second->status);
    }

    public function test_failed_or_cancelled_production_release_does_not_end_the_approval_requirement(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['status' => Release::FAILED]);
        $this->release($this->game, ['status' => Release::CANCELLED]);

        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_closed_production_stage_creates_no_release(): void // R1
    {
        $this->demoBuild($this->game);
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);

        $this->assertRefused(fn () => $this->promote('web', 'web_production', $this->sha()), 'Produktion byggs i etapp 1b');
        $this->assertSame(0, Release::count());
    }

    public function test_a_succeeded_release_on_another_target_or_stage_does_not_count_as_first(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED]);
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'app_store', 'status' => Release::SUCCEEDED]);

        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_second_promote_while_one_is_pending_is_refused(): void // spärr 4
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $this->promote('play_store', 'play_internal');

        $this->assertRefused(fn () => $this->promote('play_store', 'play_internal'), 'already pending');
        $this->assertSame(1, Release::count());
    }

    public function test_different_slots_can_be_pending_at_once(): void
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $this->demoBuild($this->game);
        $this->promote('play_store', 'play_internal');
        $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(2, Release::active()->count());
    }

    public function test_stale_pending_release_is_cancelled_and_frees_the_slot(): void // spärr 7, TTL
    {
        $this->demoBuild($this->game);
        $old = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($old->id)->update(['updated_at' => now()->subDays(8)]);

        $new = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::CANCELLED, $old->fresh()->status);
        $this->assertNull($old->fresh()->active_key);
        $this->assertStringContainsString('within 7 days', $old->fresh()->last_error);
        $this->assertSame(Release::AWAITING_APPROVAL, $new->status);
    }

    public function test_ttl_does_not_touch_a_release_that_is_exactly_inside_the_window(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(6)->subHours(23)]);

        $this->assertSame(0, Release::expireStale()->count());
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_only_platform_admin_can_approve(): void // spärr 3
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->approve($release, $this->owner), 'Only a platform admin');
        $this->assertRefused(fn () => $this->service()->approve($release, User::factory()->staff()->create()), 'Only a platform admin');
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);

        $admin = $this->adminUser();
        $approved = $this->service()->approve($release, $admin);

        $this->assertSame(Release::REQUESTED, $approved->status);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertFalse($approved->self_approved);
    }

    public function test_self_approval_is_flagged(): void
    {
        $this->demoBuild($this->game);
        $admin = $this->adminUser();
        $this->game->members()->attach($admin, ['role' => 'member']);
        $release = $this->promote('web', 'web_production', $this->sha(), $admin);

        $approved = $this->service()->approve($release, $admin);

        $this->assertTrue($approved->self_approved);
        $this->assertTrue($approved->fresh()->self_approved);
    }

    public function test_approval_rechecks_the_receipt(): void // spärr 7
    {
        $build = $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        $build->delete();

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'no successful Demo build');
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_approval_rechecks_the_switch_and_open_setup_steps(): void // spärr 7, 9, 6
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'switched off');

        $this->game->forceFill(['releases_enabled' => true])->save();
        ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'web_public_address')->update(['status' => 'open']);
        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'Setup is not finished');

        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_approval_after_ttl_cancels_the_release(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(8)]);

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'not awaiting approval');
        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_only_an_awaiting_release_can_be_approved_or_rejected(): void
    {
        $this->demoBuild($this->game);
        $release = $this->release($this->game, ['status' => Release::REQUESTED]);

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'not awaiting approval');
        $this->assertRefused(fn () => $this->service()->reject($release, $this->adminUser()), 'not awaiting approval');
    }

    public function test_reject_cancels_and_frees_the_slot(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->reject($release, $this->owner), 'Only a platform admin');
        $rejected = $this->service()->reject($release, $this->adminUser());

        $this->assertSame(Release::CANCELLED, $rejected->status);
        $this->assertNull($rejected->active_key);
        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_start_rechecks_everything(): void // spärr 7: bygget startar
    {
        // play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).
        $build = $this->demoBuild($this->game);
        $release = $this->promote('play_store', 'play_internal');

        $this->service()->assertCanStart($release);

        config(['releases.enabled' => false]);
        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'switched off');
        config(['releases.enabled' => true]);

        $build->delete();
        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'no successful Demo build');
    }

    public function test_start_requires_requested_status_and_an_approved_first_production_release(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'not in requested state');

        // Ett requested-släpp utan godkännande och utan tidigare lyckat produktionssläpp får aldrig starta.
        $release->forceFill(['status' => Release::REQUESTED])->save();
        $this->assertRefused(fn () => $this->service()->assertCanStart($release->fresh()), 'has not been approved');

        $release->forceFill(['status' => Release::AWAITING_APPROVAL])->save();
        $this->service()->approve($release->fresh(), $this->adminUser());
        $this->service()->assertCanStart($release->fresh());
    }

    public function test_start_of_a_later_production_release_needs_no_new_approval(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['status' => Release::SUCCEEDED]);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::REQUESTED, $release->status);
        $this->service()->assertCanStart($release);
    }

    public function test_unexpected_target_or_stage_values_are_refused_not_fatal(): void
    {
        $this->demoBuild($this->game);

        $this->assertRefused(fn () => $this->promote('nope', 'web_production', $this->sha()), 'not enabled');
        $this->assertRefused(fn () => $this->promote('web', 'nope', $this->sha()), 'cannot be promoted');
        $this->assertSame(0, Release::count());
    }
}
