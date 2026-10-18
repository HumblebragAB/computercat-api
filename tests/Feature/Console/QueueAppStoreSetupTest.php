<?php

namespace Tests\Feature\Console;

use App\Jobs\RunAppStoreSetup;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AppStoreFakes;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

// "Computercat ser själv när appen finns": schemat köar engångsstegen för spel som väntar på något
// som Computercat gör eller kontrollerar. Kommandot anropar aldrig Apple själv.
class QueueAppStoreSetupTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AppStoreFakes::configure();
        Queue::fake();
        Http::fake();
    }

    public function test_games_waiting_for_the_app_or_an_automatic_step_are_queued(): void
    {
        $waiting = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $waiting->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);
        $automatic = $this->testFlightGame('tinker-isle');
        ReleaseSetupStep::where('game_id', $automatic->id)->where('key', 'appstore_profile')->update(['status' => ReleaseSetupStep::OPEN]);

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();

        Queue::assertPushed(RunAppStoreSetup::class, 2);
        Queue::assertPushed(RunAppStoreSetup::class, fn ($job) => $job->gameId === $waiting->id);
        Queue::assertPushed(RunAppStoreSetup::class, fn ($job) => $job->gameId === $automatic->id);
        Http::assertNothingSent();
    }

    public function test_finished_games_eriks_own_steps_and_other_targets_are_not_queued(): void
    {
        $this->testFlightGame('kattsvep'); // alla steg klara
        $privacy = $this->testFlightGame('tinker-isle');
        // Integritetssteget kan Computercat inte kontrollera; det köar inget.
        ReleaseSetupStep::where('game_id', $privacy->id)->where('key', 'appstore_privacy_and_age')->update(['status' => ReleaseSetupStep::OPEN]);
        $web = $this->releasableGame('ordjakt', ['web']);
        ReleaseSetupStep::where('game_id', $web->id)->update(['status' => ReleaseSetupStep::OPEN]);

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_removed_target_inactive_game_and_missing_bundle_id_are_not_queued(): void
    {
        $removed = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $removed->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);
        $removed->forceFill(['release_targets' => ['web']])->save(); // stegen ligger kvar, målet är borta
        $inactive = $this->testFlightGame('tinker-isle');
        ReleaseSetupStep::where('game_id', $inactive->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);
        $inactive->forceFill(['is_active' => false])->save();
        $noBundle = $this->testFlightGame('ordjakt');
        ReleaseSetupStep::where('game_id', $noBundle->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);
        $noBundle->forceFill(['bundle_id' => null])->save();

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_step_in_backoff_is_not_queued_until_it_is_due(): void
    {
        $game = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')
            ->update(['status' => ReleaseSetupStep::OPEN, 'last_error' => 'App Store Connect returned 409', 'failed_attempts' => 2, 'retry_at' => now()->addMinutes(20)]);

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(21)->minutes();
        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();
        Queue::assertPushed(RunAppStoreSetup::class, fn ($job) => $job->gameId === $game->id);
    }

    public function test_a_later_step_does_not_bypass_the_backoff_of_the_step_that_runs_first(): void
    {
        $game = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')
            ->update(['status' => ReleaseSetupStep::OPEN, 'failed_attempts' => 1, 'retry_at' => now()->addMinutes(10)]);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_testers_are_queued_only_after_a_valid_build(): void
    {
        $game = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_testflight_testers')->update(['status' => ReleaseSetupStep::OPEN]);

        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED, 'marketing_version' => '1.0.0', 'build_number' => 1]);
        $this->artisan('computercat:queue-app-store-setup')->assertSuccessful();
        Queue::assertPushed(RunAppStoreSetup::class, fn ($job) => $job->gameId === $game->id);
    }

    public function test_nothing_is_queued_when_app_store_connect_is_not_configured(): void
    {
        $game = $this->testFlightGame('kattsvep');
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);
        config(['releases.app_store.key_id' => null]);

        $this->artisan('computercat:queue-app-store-setup')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_it_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'computercat:queue-app-store-setup'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
    }
}
