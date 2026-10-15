<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseNext;
use App\Releases\ReleaseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseStatusTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
    }

    private function statusOf(Game $game): array
    {
        return ReleaseStatus::forGame($game->fresh());
    }

    public function test_game_without_targets_says_so(): void
    {
        $game = Game::create(['slug' => 'g', 'name' => 'G', 'is_active' => true, 'settings' => []]);

        $status = $this->statusOf($game);

        $this->assertSame([], $status['release_targets']);
        $this->assertSame(ReleaseNext::for('no_targets'), $status['next']);
        $this->assertNull($status['demo']);
    }

    public function test_switch_off_overrides_everything(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $game->forceFill(['releases_enabled' => false])->save();

        $status = $this->statusOf($game);

        $this->assertFalse($status['releases_enabled']);
        $this->assertSame(ReleaseNext::for('releases_disabled'), $status['next']);
        $this->assertSame('releases_disabled', $status['targets']['web']['state']);
    }

    public function test_open_setup_step_is_next(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => 'open']);
        $this->demoBuild($game);

        $target = $this->statusOf($game)['targets']['app_store'];

        $this->assertSame('setup_open', $target['state']);
        $this->assertStringContainsString('skapar appen i App Store Connect', $target['next']);
        $this->assertSame('open', collect($target['setup_steps'])->firstWhere('key', 'appstore_create_app')['status']);
    }

    public function test_no_demo_build_yet(): void
    {
        $game = $this->releasableGame();

        $this->assertSame('no_demo_build', $this->statusOf($game)['targets']['web']['state']);
    }

    public function test_web_goes_straight_from_demo_to_production(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game, $this->sha('b'));

        $target = $this->statusOf($game)['targets']['web'];

        $this->assertSame('ready_for_production', $target['state']);
        $this->assertStringContainsString('Commit '.$this->sha('b').' är testad i Demo', $target['next']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production, commit_sha: '.$this->sha('b').')', $target['next']);
    }

    public function test_app_store_flow_ready_for_test_then_production_then_done(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $this->demoBuild($game, $this->sha('b'));

        $this->assertSame('ready_for_testflight', $this->statusOf($game)['targets']['app_store']['state']);
        $this->assertStringContainsString('promote_release(target: app_store, stage: testflight, commit_sha: <commiten>)', $this->statusOf($game)['targets']['app_store']['next']);

        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $target = $this->statusOf($game)['targets']['app_store'];
        $this->assertSame('ready_for_production', $target['state']);
        $this->assertStringContainsString('testad i testflight', $target['next']);

        $this->release($game, ['target' => 'app_store', 'stage' => 'app_store', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $this->assertSame('succeeded', $this->statusOf($game)['targets']['app_store']['state']);
    }

    public function test_closed_production_says_so_instead_of_offering_it(): void // R1
    {
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);
        $game = $this->releasableGame();
        $this->demoBuild($game, $this->sha('b'));

        $target = $this->statusOf($game)['targets']['web'];

        $this->assertSame('production_not_open', $target['state']);
        $this->assertStringContainsString('etapp 1b', $target['next']);
        $this->assertStringNotContainsString('promote_release', $target['next']);
    }

    public function test_a_newer_demo_commit_restarts_the_store_flow(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $this->demoBuild($game, $this->sha('a'), now()->subHour());
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => $this->sha('a'), 'status' => Release::SUCCEEDED]);
        $this->release($game, ['target' => 'app_store', 'stage' => 'app_store', 'commit_sha' => $this->sha('a'), 'status' => Release::SUCCEEDED]);
        $this->assertSame('succeeded', $this->statusOf($game)['targets']['app_store']['state']);

        $this->demoBuild($game, $this->sha('b'), now());

        $target = $this->statusOf($game)['targets']['app_store'];
        $this->assertSame('ready_for_testflight', $target['state']);
        $this->assertStringContainsString('Commit '.$this->sha('b').' är testad i Demo', $target['next']);
    }

    public function test_release_states_map_to_texts(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $cases = [
            [Release::AWAITING_APPROVAL, 'awaiting_approval', 'godkännas i Filament'],
            [Release::REQUESTED, 'queued_for_build', 'etapp 1b'],
            [Release::BUILDING, 'in_progress', 'pågår (building)'],
            [Release::PROCESSING, 'in_progress', 'pågår (processing)'],
        ];
        foreach ($cases as [$status, $state, $text]) {
            $release = $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => $status]);
            $target = $this->statusOf($game)['targets']['web'];
            $this->assertSame($state, $target['state'], $status);
            $this->assertStringContainsString($text, $target['next'], $status);
            $this->assertStringContainsString($release->uuid, $target['next']);
            $release->update(['status' => Release::CANCELLED]);
        }
    }

    public function test_failed_and_cancelled_latest_release_show_the_reason(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::FAILED, 'last_error' => 'build exploded']);

        $target = $this->statusOf($game)['targets']['web'];
        $this->assertSame('failed', $target['state']);
        $this->assertStringContainsString('build exploded.', $target['next']);

        $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::CANCELLED]);
        $this->assertSame('cancelled', $this->statusOf($game)['targets']['web']['state']);
    }

    public function test_status_exposes_demo_and_releases_without_internal_ids(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game, $this->sha('b'));
        $release = $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::AWAITING_APPROVAL]);

        $status = $this->statusOf($game);

        $this->assertSame($this->sha('b'), $status['demo']['commit_sha']);
        $latest = $status['targets']['web']['stages']['web_production']['latest_release'];
        $this->assertSame($release->uuid, $latest['release']);
        $this->assertFalse($latest['approved']);
        $this->assertArrayNotHasKey('id', $latest);
        $this->assertArrayNotHasKey('requested_by', $latest);
    }

    private function tf(Game $game, string $status, array $attrs = []): Release
    {
        return $this->release($game, $attrs + ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => '1.2.0', 'build_number' => 5, 'build_guide' => 'v3']);
    }

    public function test_app_store_ready_state_gives_the_testflight_flow(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('b'));
        $this->tf($game, Release::SUCCEEDED, ['commit_sha' => $this->sha('c'), 'marketing_version' => '1.1.0', 'build_number' => 4]);

        $target = $this->statusOf($game)['targets']['app_store'];

        $this->assertSame('ready_for_testflight', $target['state']);
        $this->assertStringContainsString('Senast släppta version till TestFlight: 1.1.0.', $target['next']);
        $this->assertStringContainsString(ReleaseNext::TESTFLIGHT_FLOW, $target['next']);
        $this->assertSame(['bundle_id' => 'se.computercat.kattsvep', 'last_released_version' => '1.1.0', 'last_build_number' => 0], $target['ios']);
    }

    public function test_first_testflight_release_says_none_is_released(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game);

        $this->assertStringContainsString('Senast släppta version till TestFlight: ingen.', $this->statusOf($game)['targets']['app_store']['next']);
    }

    public function test_testflight_phases_have_their_own_texts(): void
    {
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]); // App Store stängt, som i drift
        $game = $this->testFlightGame();
        $this->demoBuild($game);
        $release = $this->tf($game, Release::REQUESTED);

        foreach ([
            Release::REQUESTED => ['starting', 'startar'],
            Release::BUILDING => ['building_testflight', 'GitHub Actions bygger'],
            Release::PROCESSING => ['building_testflight', 'Apple bearbetar'],
            Release::SUCCEEDED => ['succeeded_testflight', 'finns i TestFlight för gruppen ComputerCat intern'],
        ] as $status => [$state, $needle]) {
            $release->update(['status' => $status]);
            $target = $this->statusOf($game)['targets']['app_store'];
            $this->assertSame($state, $target['state'], $status);
            $this->assertStringContainsString($needle, $target['next'], $status);
            $this->assertStringContainsString('version 1.2.0', $target['next'], $status);
        }

        $release->update(['status' => Release::FAILED, 'last_error' => 'The GitHub run ended with failure in job build.']);
        $target = $this->statusOf($game)['targets']['app_store'];
        $this->assertSame('failed_testflight', $target['state']);
        $this->assertStringContainsString('ended with failure in job build. Samma commit kan skickas igen', $target['next']);
        $this->assertSame(['1.2.0', 5], [$target['stages']['testflight']['latest_release']['marketing_version'], $target['stages']['testflight']['latest_release']['build_number']]);
    }

    public function test_succeeded_testflight_is_the_end_while_app_store_is_closed(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('a'));
        $this->tf($game, Release::SUCCEEDED, ['commit_sha' => $this->sha('a')]);
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);

        $this->assertSame('succeeded_testflight', $this->statusOf($game)['targets']['app_store']['state']);
    }

    public function test_next_templates_fail_loud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReleaseNext::for('no_such_state');
    }

    public function test_next_template_with_a_missing_variable_fails_loud(): void
    {
        $this->expectException(\LogicException::class);
        ReleaseNext::for('ready_for_test', ['commit' => 'x']);
    }

    public function test_every_template_placeholder_is_a_known_variable(): void
    {
        $known = ['commit', 'stage', 'target', 'release', 'status', 'error', 'instruction', 'etapp', 'test_stage', 'last_version', 'version', 'build', 'phase', 'flow'];
        foreach (ReleaseNext::TEMPLATES as $state => $template) {
            preg_match_all('/\{([a-z_]+)\}/', $template, $m);
            $this->assertEmpty(array_diff($m[1], $known), $state);
        }
    }
}
