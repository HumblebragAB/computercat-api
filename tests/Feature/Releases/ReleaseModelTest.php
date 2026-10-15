<?php

namespace Tests\Feature\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseCatalog;
use App\Releases\SetupSteps;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseModelTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []]);
    }

    public function test_new_games_default_to_no_targets_and_releases_on(): void
    {
        $fresh = $this->game->fresh();

        $this->assertSame([], $fresh->releaseTargets());
        $this->assertTrue($fresh->releases_enabled);
    }

    public function test_in_memory_game_has_releases_enabled_default_without_fresh(): void
    {
        $game = Game::create(['slug' => 'minne', 'name' => 'Minne', 'is_active' => true, 'settings' => []]);

        $this->assertTrue($game->releases_enabled);
    }

    public function test_active_key_follows_status(): void
    {
        $release = $this->release($this->game);
        $this->assertSame('active', $release->active_key);

        $release->update(['status' => Release::SUCCEEDED]);
        $this->assertNull($release->fresh()->active_key);

        $release->update(['status' => Release::BUILDING]);
        $this->assertSame('active', $release->fresh()->active_key);
    }

    public function test_second_active_release_for_same_slot_violates_unique_index(): void
    {
        $this->release($this->game);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->release($this->game, ['status' => Release::AWAITING_APPROVAL]);
    }

    public function test_finished_releases_and_other_slots_do_not_conflict(): void
    {
        $this->release($this->game, ['status' => Release::SUCCEEDED]);
        $this->release($this->game, ['status' => Release::FAILED]);
        $this->release($this->game, ['status' => Release::CANCELLED]);
        $this->release($this->game);
        $this->release($this->game, ['stage' => 'testflight', 'target' => 'app_store']);

        $this->assertSame(5, Release::count());
    }

    public function test_last_error_is_redacted_on_save(): void
    {
        $release = $this->release($this->game, ['last_error' => 'upload failed ghp_'.str_repeat('a', 36)]);

        $this->assertStringNotContainsString('ghp_', $release->fresh()->last_error);
        $this->assertStringContainsString('[REDACTED]', $release->fresh()->last_error);
    }

    public function test_uuid_is_generated(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->release($this->game)->uuid);
    }

    public function test_demo_build_is_unique_per_game_and_deployment(): void
    {
        DemoBuild::create(['game_id' => $this->game->id, 'commit_sha' => $this->sha(), 'deployment_uuid' => 'dep-1', 'deployed_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);
        DemoBuild::create(['game_id' => $this->game->id, 'commit_sha' => $this->sha('b'), 'deployment_uuid' => 'dep-1', 'deployed_at' => now()]);
    }

    public function test_latest_demo_build_is_the_newest_by_time(): void
    {
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $newest = $this->demoBuild($this->game, $this->sha('b'), now());
        $this->demoBuild($this->game, $this->sha('c'), now()->subDay());

        $this->assertSame($newest->id, DemoBuild::latestFor($this->game)->id);
        $this->assertNull(DemoBuild::latestFor(Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true, 'settings' => []])));
    }

    public function test_sync_creates_steps_for_every_target_and_is_idempotent_and_keeps_done_steps(): void
    {
        $this->game->forceFill(['release_targets' => ['web', 'app_store', 'play_store']])->save();

        SetupSteps::sync($this->game);
        ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'web_public_address')->update(['status' => ReleaseSetupStep::DONE]);
        SetupSteps::sync($this->game);

        $expected = array_sum(array_map('count', SetupSteps::DEFINITIONS));
        $this->assertSame($expected, ReleaseSetupStep::where('game_id', $this->game->id)->count());
        $this->assertSame(ReleaseSetupStep::DONE, ReleaseSetupStep::where('key', 'web_public_address')->value('status'));
        $this->assertSame(ReleaseSetupStep::OPEN, ReleaseSetupStep::where('key', 'appstore_create_app')->value('status'));
    }

    public function test_catalog_knows_stages_and_commit_format(): void
    {
        $this->assertSame('demo', ReleaseCatalog::testStage('web'));
        $this->assertSame('testflight', ReleaseCatalog::testStage('app_store'));
        $this->assertSame('play_production', ReleaseCatalog::productionStage('play_store'));
        $this->assertTrue(ReleaseCatalog::isPromotable('web', 'web_production'));
        $this->assertFalse(ReleaseCatalog::isPromotable('web', 'demo'));
        $this->assertFalse(ReleaseCatalog::isPromotable('web', 'testflight'));
        $this->assertFalse(ReleaseCatalog::isPromotable('nope', 'web_production'));
        $this->assertTrue(ReleaseCatalog::isProduction('app_store', 'app_store'));
        $this->assertFalse(ReleaseCatalog::isProduction('app_store', 'testflight'));
        $this->assertFalse(ReleaseCatalog::isProduction('nope', 'app_store'));
        $this->assertTrue(ReleaseCatalog::isCommit($this->sha()));
        foreach ([substr($this->sha(), 1), $this->sha().'a', strtoupper($this->sha('f')), 'HEAD', '', str_repeat('g', 40), $this->sha()."\n"] as $bad) {
            $this->assertFalse(ReleaseCatalog::isCommit($bad), var_export($bad, true));
        }
    }

    public function test_build_number_is_unique_per_game(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $other = $this->releasableGame('tinker-isle', ['app_store']);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);
        $this->release($other, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);
    }

    public function test_two_games_cannot_share_a_bundle_id(): void
    {
        $this->releasableGame('ab-c')->forceFill(['bundle_id' => 'se.computercat.abc'])->save();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->releasableGame('abc')->forceFill(['bundle_id' => 'se.computercat.abc'])->save();
    }
}
