<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseGate;
use App\Releases\SetupSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

// En test per spärr (spec Del 1). Numren i metodnamnen är specens.
class ReleaseGateTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store']);
    }

    private function check(string $target, string $stage, ?string $commit): ?string
    {
        return ReleaseGate::check($this->game->fresh(), $target, $stage, $commit);
    }

    public function test_switch_off_blocks_everything(): void // spärr 9
    {
        $this->demoBuild($this->game);

        config(['releases.enabled' => false]);
        $this->assertStringContainsString('switched off globally', (string) $this->check('app_store', 'testflight', null));

        config(['releases.enabled' => true]);
        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->assertStringContainsString('switched off for this game', (string) $this->check('app_store', 'testflight', $this->sha()));

        $this->game->forceFill(['releases_enabled' => true])->save();
        $this->assertNull($this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_global_switch_fails_closed_on_a_non_boolean_value(): void // spärr 9, felstavad miljövariabel
    {
        $this->demoBuild($this->game);
        config(['releases.enabled' => 'yes']);

        $this->assertNotNull($this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_target_must_be_in_release_targets(): void // spärr 5
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('not enabled', (string) $this->check('play_store', 'play_internal', $this->sha()));
        $this->assertStringContainsString('not enabled', (string) $this->check('nonsense', 'testflight', $this->sha()));
    }

    public function test_production_stages_are_closed_by_default(): void // R1
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED]);
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);

        $this->assertStringContainsString('Produktion byggs i etapp 1b', (string) $this->check('web', 'web_production', $this->sha()));
        $this->assertStringContainsString('Produktion byggs i etapp 2', (string) $this->check('app_store', 'app_store', $this->sha()));
        // Teststegen är öppna.
        $this->assertNull($this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_stage_must_belong_to_the_target(): void
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('cannot be promoted', (string) $this->check('web', 'testflight', $this->sha()));
        $this->assertStringContainsString('cannot be promoted', (string) $this->check('web', 'demo', $this->sha()));
        $this->assertStringContainsString('cannot be promoted', (string) $this->check('app_store', 'web_production', $this->sha()));
    }

    public function test_open_setup_steps_block_the_target(): void // spärr 6
    {
        $this->demoBuild($this->game);
        ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);

        $reason = (string) $this->check('app_store', 'testflight', $this->sha());

        $this->assertStringContainsString('Setup is not finished for app_store', $reason);
        $this->assertStringContainsString('App Store Connect', $reason);
        // Ett annat mål påverkas inte.
        $this->assertNull($this->check('web', 'web_production', $this->sha()));
    }

    public function test_test_stage_needs_a_demo_receipt_for_the_commit(): void // spärr 1
    {
        $this->assertStringContainsString('No successful Demo build exists yet', (string) $this->check('app_store', 'testflight', null));
        $this->assertStringContainsString('has no successful Demo build', (string) $this->check('app_store', 'testflight', $this->sha('b')));

        $this->demoBuild($this->game, $this->sha('b'));

        $this->assertNull($this->check('app_store', 'testflight', $this->sha('b')));
    }

    public function test_receipt_of_another_game_does_not_count(): void // spärr 1
    {
        $other = $this->releasableGame('ordjakt', ['web']);
        $this->demoBuild($other, $this->sha('c'));

        $this->assertStringContainsString('has no successful Demo build', (string) $this->check('app_store', 'testflight', $this->sha('c')));
    }

    public function test_production_stage_needs_a_succeeded_test_stage_for_the_same_commit(): void // spärr 2
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::BUILDING]);
        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED]);
        $this->assertNull($this->check('app_store', 'app_store', $this->sha()));
    }

    public function test_web_production_uses_the_demo_receipt_as_its_test_step(): void // spärr 2
    {
        $this->assertStringContainsString('no successful Demo build', (string) $this->check('web', 'web_production', $this->sha()));

        $this->demoBuild($this->game);

        $this->assertNull($this->check('web', 'web_production', $this->sha()));
    }

    public function test_production_requires_an_explicit_commit(): void // spärr 8
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('commit_sha is required for production stages', (string) $this->check('web', 'web_production', null));
        $this->assertStringContainsString('commit_sha is required for production stages', (string) $this->check('app_store', 'app_store', null));
    }

    public function test_commit_must_be_a_full_lowercase_sha(): void
    {
        $this->demoBuild($this->game);

        foreach ([substr($this->sha(), 1), $this->sha().'a', strtoupper($this->sha('f')), 'HEAD', 'abc123', $this->sha()."\n"] as $bad) {
            $this->assertStringContainsString('40-character', (string) $this->check('app_store', 'testflight', $bad), var_export($bad, true));
        }
    }

    public function test_archived_game_is_refused(): void
    {
        $this->demoBuild($this->game);
        $this->game->update(['is_active' => false]);

        $this->assertStringContainsString('not active', (string) $this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_setup_steps_are_created_open_for_new_targets(): void
    {
        $game = Game::create(['slug' => 'nytt', 'name' => 'Nytt', 'is_active' => true, 'settings' => []]);
        $game->forceFill(['release_targets' => ['web']])->save();
        SetupSteps::sync($game);
        $this->demoBuild($game);

        $this->assertStringContainsString('Setup is not finished for web', (string) ReleaseGate::check($game->fresh(), 'web', 'web_production', $this->sha()));
    }
}
