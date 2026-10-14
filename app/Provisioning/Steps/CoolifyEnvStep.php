<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Build-variabeln som byggguidens klientkod läser. Tokenen saknar read:sensitive, så
// värdet kan vara dolt i listningen; då skrivs det över med PATCH.
final class CoolifyEnvStep implements Step
{
    public const KEY = 'NEXT_PUBLIC_COMPUTERCAT_GAME';

    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $uuid = $run->coolify_app_uuid ?? throw new StepFailed('No Coolify application is recorded for this run.');

        $existing = null;
        foreach ($this->coolify->environmentVariables($uuid) as $variable) {
            // Preview-varianten är en egen post med samma nyckel; den är inte vår.
            if (is_array($variable) && ($variable['key'] ?? null) === self::KEY && ($variable['is_preview'] ?? false) !== true) {
                $existing = $variable;
            }
        }

        if ($existing === null) {
            $this->coolify->createBuildVariable($uuid, self::KEY, $run->slug);
        } elseif (($existing['value'] ?? null) !== $run->slug || ($existing['is_buildtime'] ?? false) !== true) {
            $this->coolify->updateBuildVariable($uuid, self::KEY, $run->slug);
        }

        return StepResult::done();
    }
}
