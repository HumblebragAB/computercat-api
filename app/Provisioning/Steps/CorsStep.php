<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Demo-adressen läggs till spelets CORS-origins. Normalisering och
// cache-rensning sker i Game-hookarna.
final class CorsStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $game = Game::find($run->game_id) ?? throw new StepFailed('The game for this run does not exist.');
        $origin = ProvisioningConfig::demoUrl($run->slug);
        $origins = $game->allowed_origins ?? [];

        if (! in_array($origin, $origins, true)) {
            $game->update(['allowed_origins' => [...$origins, $origin]]);
        }

        return StepResult::done();
    }
}
