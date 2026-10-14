<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Coolify-appen spel-<slug> i Demo-projektet. uuid:t sparas direkt efter 201
// så att en omkörning aldrig skapar en andra app.
final class CoolifyAppStep implements Step
{
    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->coolify_app_uuid !== null) {
            return StepResult::done();
        }

        $detail = $step->detail ?? [];
        $existing = $this->coolify->findDemoApplicationByName(ProvisioningConfig::coolifyAppName($run->slug));
        if ($existing !== null) {
            // Bara en app som den här runnen själv bad om (avbrott mellan 201 och
            // sparningen) adopteras. En handskapad app med samma namn får aldrig
            // hamna i provisioning_runs, eftersom det är den listan Coolify-spärren
            // och archive_game litar på.
            if (! isset($detail['create_requested_at'])) {
                throw new StepFailed('A Coolify app named '.ProvisioningConfig::coolifyAppName($run->slug).' already exists and was not created by this run.');
            }
            $run->update(['coolify_app_uuid' => $existing['uuid']]);

            return StepResult::done(['adopted' => true]);
        }

        // Markören sparas före anropet, så att en omkörning efter ett avbrott vet
        // att appen kan vara vår.
        $step->update(['detail' => [...$detail, 'create_requested_at' => now()->toIso8601String()]]);

        // 409 (domänkonflikt) kastar CoolifyConflictException och stoppar sagan.
        $run->update(['coolify_app_uuid' => $this->coolify->createApplication($run->slug)]);

        return StepResult::done();
    }
}
