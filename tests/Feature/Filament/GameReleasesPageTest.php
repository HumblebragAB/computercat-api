<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource;
use App\Filament\Resources\GameResource\Pages\GameReleases;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class GameReleasesPageTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
        ProvisioningFakes::configure();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
        $this->game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($this->game);
    }

    private function awaiting(?User $requester = null): Release
    {
        return $this->release($this->game, ['status' => Release::AWAITING_APPROVAL, 'requested_by' => $requester?->id]);
    }

    private function page(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(GameReleases::class, ['record' => $this->game->getKey()]);
    }

    public function test_non_admin_staff_gets_403(): void
    {
        $staff = User::factory()->staff()->create();
        $this->game->members()->attach($staff, ['role' => 'owner']);

        $this->actingAs($staff)->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertForbidden();
        $this->actingAs($staff);
        Livewire::test(GameReleases::class, ['record' => $this->game->getKey()])->assertForbidden();
    }

    public function test_admin_sees_releases_steps_and_the_switch(): void
    {
        ReleaseSetupStep::where('key', 'web_public_address')->update(['status' => 'open']);
        $release = $this->awaiting();

        $html = $this->actingAs($this->adminUser())->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertOk()->getContent();

        $this->assertStringContainsString($release->commit_sha, $html);
        $this->assertStringContainsString('awaiting_approval', $html);
        $this->assertStringContainsString('web_public_address', $html);
    }

    public function test_html_in_stored_values_is_escaped(): void
    {
        $this->release($this->game, ['status' => Release::FAILED, 'last_error' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->adminUser())->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_admin_approves_and_it_is_recorded(): void
    {
        $admin = $this->adminUser();
        $release = $this->awaiting($this->ownerOf($this->game));

        $this->page($admin)->call('approve', $release->id);

        $fresh = $release->fresh();
        $this->assertSame(Release::REQUESTED, $fresh->status);
        $this->assertSame($admin->id, $fresh->approved_by);
        $this->assertFalse($fresh->self_approved);
        Http::assertNothingSent();
    }

    public function test_approving_your_own_request_is_flagged_and_logged_without_slack(): void
    {
        $admin = $this->adminUser();
        $release = $this->awaiting($admin);

        $this->page($admin)->call('approve', $release->id);

        $this->assertTrue($release->fresh()->self_approved);
        Http::assertNothingSent();
    }

    public function test_a_refused_approval_changes_nothing_and_does_not_crash(): void
    {
        $release = $this->awaiting();
        $this->game->forceFill(['releases_enabled' => false])->save();

        $this->page($this->adminUser())->call('approve', $release->id);

        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_release_of_another_game_cannot_be_approved_from_this_page(): void
    {
        $other = $this->releasableGame('ordjakt', ['web']);
        $this->demoBuild($other);
        $foreign = $this->release($other, ['status' => Release::AWAITING_APPROVAL]);

        $this->page($this->adminUser())->call('approve', $foreign->id);

        $this->assertSame(Release::AWAITING_APPROVAL, $foreign->fresh()->status);
    }

    public function test_admin_rejects(): void
    {
        $release = $this->awaiting();

        $this->page($this->adminUser())->call('reject', $release->id);

        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_admin_cancels_a_requested_release_and_it_is_logged(): void
    {
        $admin = $this->adminUser();
        $release = $this->release($this->game, ['status' => Release::REQUESTED]);
        Log::spy();

        $this->page($admin)->call('cancel', $release->id);

        $fresh = $release->fresh();
        $this->assertSame(Release::CANCELLED, $fresh->status);
        $this->assertSame('Avbrutet av en platform admin.', $fresh->last_error);
        Log::shouldHaveReceived('notice')->with('Släpp avbrutet', ['release_uuid' => $release->uuid, 'admin_id' => $admin->id])->once();
    }

    public function test_non_admin_cannot_cancel(): void
    {
        $staff = User::factory()->staff()->create();
        $release = $this->release($this->game, ['status' => Release::REQUESTED]);
        $this->actingAs($staff);

        Livewire::test(GameReleases::class, ['record' => $this->game->getKey()])->assertForbidden();
        $this->assertSame(Release::REQUESTED, $release->fresh()->status);
    }

    public function test_release_of_another_game_cannot_be_cancelled_from_this_page(): void
    {
        $other = $this->releasableGame('ordjakt', ['web']);
        $foreign = $this->release($other, ['status' => Release::REQUESTED]);

        $this->page($this->adminUser())->call('cancel', $foreign->id);

        $this->assertSame(Release::REQUESTED, $foreign->fresh()->status);
    }

    public function test_a_building_release_cannot_be_cancelled(): void
    {
        $release = $this->release($this->game, ['status' => Release::BUILDING]);

        $this->page($this->adminUser())->call('cancel', $release->id);

        $this->assertSame(Release::BUILDING, $release->fresh()->status);
    }

    public function test_admin_marks_a_step_done_but_only_for_this_game(): void
    {
        $admin = $this->adminUser();
        $mine = ReleaseSetupStep::where('game_id', $this->game->id)->first();
        $mine->update(['status' => 'open']);
        $other = $this->releasableGame('ordjakt', ['web']);
        $theirs = ReleaseSetupStep::where('game_id', $other->id)->first();
        $theirs->update(['status' => 'open']);

        $page = $this->page($admin);
        $page->call('markStepDone', $mine->id);
        $page->call('markStepDone', $theirs->id);

        $this->assertSame('done', $mine->fresh()->status);
        $this->assertSame($admin->id, $mine->fresh()->done_by);
        $this->assertNotNull($mine->fresh()->done_at);
        $this->assertSame('open', $theirs->fresh()->status);
    }

    public function test_a_step_that_is_already_done_keeps_who_and_when(): void
    {
        $first = $this->adminUser();
        $second = $this->adminUser();
        $step = ReleaseSetupStep::where('game_id', $this->game->id)->first();
        $at = now()->subDay()->startOfSecond();
        $step->update(['status' => 'done', 'done_by' => $first->id, 'done_at' => $at]);

        $this->page($second)->call('markStepDone', $step->id);

        $this->assertSame($first->id, $step->fresh()->done_by);
        $this->assertTrue($at->equalTo($step->fresh()->done_at));
    }

    public function test_the_automatic_bundle_id_step_cannot_be_checked_off_by_hand(): void
    {
        $game = $this->releasableGame('ordjakt', ['app_store']);
        $step = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->first();
        $step->update(['status' => 'open']);

        $this->actingAs($this->adminUser());
        Livewire::test(GameReleases::class, ['record' => $game->getKey()])->call('markStepDone', $step->id);

        $this->assertSame('open', $step->fresh()->status);
    }

    public function test_admin_disables_releases_for_all_games(): void
    {
        $other = $this->releasableGame('ordjakt', ['web']);

        $this->page($this->adminUser())->call('disableAllGames');

        $this->assertFalse($this->game->fresh()->releases_enabled);
        $this->assertFalse($other->fresh()->releases_enabled);
    }

    public function test_admin_toggles_the_game_switch(): void
    {
        $admin = $this->adminUser();
        $page = $this->page($admin);
        Log::spy();

        $page->call('toggleGameSwitch');
        Log::shouldHaveReceived('notice')->once()->with(\Mockery::type('string'), ['user_id' => $admin->id, 'game' => 'kattsvep', 'releases_enabled' => false]);
        $this->assertFalse($this->game->fresh()->releases_enabled);

        $page->call('toggleGameSwitch');
        $this->assertTrue($this->game->fresh()->releases_enabled);
    }
}
