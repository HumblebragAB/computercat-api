<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Facades\DB;

// Spelet skapas aktivt (spelar-API:t ska fungera på Demo) men olistat, med
// den som körde create_game som ägare. Bara databas, inget HTTP.
final class GameStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->game_id !== null && Game::whereKey($run->game_id)->exists()) {
            return StepResult::done();
        }

        if (Game::where('slug', $run->slug)->exists()) {
            throw new StepFailed('A game with this slug already exists in Computercat and does not belong to this run.');
        }

        DB::transaction(function () use ($run) {
            $game = Game::create([
                'slug' => $run->slug,
                'name' => $run->name,
                'is_active' => true,
                'is_listed' => false,
                'settings' => [],
                'allowed_origins' => [],
            ]);

            if ($run->user_id !== null) {
                $game->members()->attach($run->user_id, ['role' => 'owner']);
            }

            $run->update(['game_id' => $game->id, 'demo_url' => ProvisioningConfig::demoUrl($run->slug)]);
        });

        return StepResult::done();
    }
}
