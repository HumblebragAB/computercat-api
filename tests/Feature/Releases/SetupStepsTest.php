<?php

namespace Tests\Feature\Releases;

use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseGate;
use App\Releases\SetupSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class SetupStepsTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    public function test_instructions_are_rendered_with_the_games_values(): void
    {
        $game = $this->testFlightGame('tinker-isle');
        ReleaseSetupStep::where('game_id', $game->id)->delete();
        SetupSteps::sync($game);

        $text = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('instruction');
        $this->assertStringContainsString('bundle-id se.computercat.tinkerisle', $text);
        $this->assertStringContainsString('SKU tinker-isle', $text);
        $this->assertStringContainsString('namn Tinker-isle', $text);
        $this->assertStringNotContainsString('{', $text);
    }

    public function test_privacy_step_blocks_only_the_app_store_stage(): void // R6
    {
        $this->openProductionStages();
        $game = $this->testFlightGame();
        $this->demoBuild($game);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_privacy_and_age')->update(['status' => ReleaseSetupStep::OPEN]);

        $this->assertNull(SetupSteps::openFor($game, 'app_store', 'testflight'));
        $this->assertSame('appstore_privacy_and_age', SetupSteps::openFor($game, 'app_store', 'app_store')->key);
        $this->assertNull(ReleaseGate::check($game, 'app_store', 'testflight', $this->sha()));
        $this->assertStringContainsString('integritetsuppgifter', (string) ReleaseGate::check($game, 'app_store', 'app_store', $this->sha()));
    }

    public function test_other_open_steps_block_testflight_in_definition_order(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->whereIn('key', ['appstore_testflight_group', 'appstore_profile'])->update(['status' => ReleaseSetupStep::OPEN]);

        $this->assertSame('appstore_profile', SetupSteps::openFor($game, 'app_store', 'testflight')->key);
    }

    public function test_sync_refreshes_open_instructions_and_leaves_done_steps_alone(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN, 'instruction' => 'gammal text']);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->update(['instruction' => 'klar gammal text']);

        SetupSteps::sync($game);

        $this->assertStringContainsString('Ny app', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('instruction'));
        $this->assertSame('klar gammal text', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->value('instruction'));
    }

    public function test_migration_gives_existing_app_store_games_a_bundle_id_and_the_new_steps(): void
    {
        $game = $this->releasableGame('tinker-isle', ['web', 'app_store']);
        $game->forceFill(['bundle_id' => null])->save();
        ReleaseSetupStep::where('game_id', $game->id)->whereIn('key', ['appstore_profile', 'appstore_testflight_group'])->delete();
        $web = $this->releasableGame('kattsvep', ['web']);

        (require database_path('migrations/2026_10_17_100002_assign_bundle_ids_and_sync_app_store_steps.php'))->up();

        $this->assertSame('se.computercat.tinkerisle', $game->fresh()->bundle_id);
        $this->assertSame('open', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')->value('status'));
        // R17: appstore_create_app var avbockat för hand i 1a utan app-id; det öppnas igen.
        $this->assertSame('open', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('status'));
        $this->assertSame('done', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'web_public_address')->value('status'));
        $this->assertNull($web->fresh()->bundle_id);
    }
}
