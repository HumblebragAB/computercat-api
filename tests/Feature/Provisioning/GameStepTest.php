<?php

namespace Tests\Feature\Provisioning;

use App\Models\Game;
use App\Models\User;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\GameStep;

class GameStepTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    public function test_creates_active_unlisted_game_with_caller_as_owner(): void
    {
        $user = User::factory()->staff()->create();
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'user_id' => $user->id]);

        $this->assertSame(StepResult::DONE, $this->runStep(GameStep::class, $run, 'game')->outcome);

        $game = Game::where('slug', 'kattsvep')->firstOrFail();
        $this->assertTrue($game->is_active);
        $this->assertFalse($game->is_listed);
        $this->assertSame('Kattsvep', $game->name);
        $this->assertSame('owner', $game->members()->whereKey($user->id)->first()->pivot->role);
        $this->assertSame($game->id, $run->fresh()->game_id);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $run->fresh()->demo_url);
    }

    public function test_is_idempotent(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->runStep(GameStep::class, $run, 'game');
        $this->runStep(GameStep::class, $run->fresh(), 'game');

        $this->assertSame(1, Game::where('slug', 'kattsvep')->count());
    }

    public function test_existing_foreign_game_fails(): void
    {
        Game::create(['slug' => 'kattsvep', 'name' => 'Annan', 'is_active' => true, 'settings' => []]);
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->expectException(StepFailed::class);
        $this->runStep(GameStep::class, $run, 'game');
    }

    // Nuvarande spec: ett främmande spel stoppar steget utan att röra repot eller slugspärren.
    public function test_foreign_game_leaves_repo_and_slug_lock_untouched(): void
    {
        Game::create(['slug' => 'kattsvep', 'name' => 'Annan', 'is_active' => true, 'settings' => []]);
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'github_repo' => 'HumblebragAB/kattsvep']);
        $lock = $run->slug_lock;

        try {
            $this->runStep(GameStep::class, $run, 'game');
            $this->fail('Steget borde ha fallerat.');
        } catch (StepFailed) {
        }

        $run = $run->fresh();
        $this->assertSame('HumblebragAB/kattsvep', $run->github_repo);
        $this->assertSame($lock, $run->slug_lock);
        $this->assertNull($run->game_id);
        $this->assertSame(1, Game::where('slug', 'kattsvep')->count());
    }
}
