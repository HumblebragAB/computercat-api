<?php

namespace Tests\Feature\Console;

use App\Models\Release;
use App\Models\ReleaseSetupStep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class CheckReleasesTest extends ProvisioningTestCase
{
    use CreatesReleases;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'coolify.test/api/v1/projects/demo-project-uuid/production' => Http::response(ProvisioningFakes::coolifyProject([])),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);
    }

    public function test_healthy_releases_are_silent(): void
    {
        $game = $this->releasableGame();
        $this->release($game, ['status' => Release::BUILDING]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_stuck_build_is_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::BUILDING]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(121)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain($release->uuid)->assertExitCode(1);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && str_contains($r['text'], 'building'));
    }

    public function test_a_build_just_inside_the_limit_is_not_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::PROCESSING]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(119)]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_expired_pending_release_is_cancelled_and_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::AWAITING_APPROVAL]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('avbröts')->assertExitCode(1);

        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_old_open_setup_steps_are_reminded_once_per_game_and_target(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'open', 'created_at' => now()->subDays(8)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('kattsvep')->assertExitCode(1);

        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK));
    }

    public function test_automatic_steps_never_remind_but_manual_steps_do(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'done']);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->update(['status' => 'open', 'created_at' => now()->subDays(10)]);
        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => 'open', 'created_at' => now()->subDays(10)]);
        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('appstore_create_app')->assertExitCode(1);
    }

    public function test_recent_open_steps_and_steps_of_removed_targets_are_ignored(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'open']); // nya
        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        ReleaseSetupStep::where('game_id', $game->id)->update(['created_at' => now()->subDays(30)]);
        $game->forceFill(['release_targets' => []])->save(); // målet är borttaget
        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_archived_game_is_not_reminded(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'open', 'created_at' => now()->subDays(30)]);
        $game->forceFill(['is_active' => false])->save(); // arkiverat

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_dry_run_reports_expired_release_without_cancelling_or_alerting(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::AWAITING_APPROVAL]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('computercat:check-provisioning', ['--dry-run' => true])->expectsOutputToContain('skulle avbrytas')->assertExitCode(1);

        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK);

        // Den riktiga körningen avbryter släppet och larmar en gång.
        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('avbröts')->assertExitCode(1);

        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK));
    }

    public function test_stuck_build_of_archived_game_is_not_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::BUILDING]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(300)]);
        $game->forceFill(['is_active' => false])->save();

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_a_failing_release_block_is_reported_and_the_other_blocks_still_run(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $stuck = $this->release($game, ['status' => Release::BUILDING]);
        Release::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(300)]);
        Schema::drop('release_setup_steps'); // engångsstegsblocket kastar; övriga block ska ändå köra

        $this->artisan('computercat:check-provisioning')
            ->expectsOutputToContain('Kunde inte kontrollera släpp (engångssteg): Illuminate\\Database\\QueryException')
            ->expectsOutputToContain($stuck->uuid)
            ->assertExitCode(1);
    }
}
