<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Spelet stängs av; data raderas inte.
final class GameDeactivateStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $game = Game::find($run->game_id) ?? throw new StepFailed('The game for this run does not exist.');
        $game->update(['is_active' => false, 'is_listed' => false]);

        return StepResult::done();
    }
}
