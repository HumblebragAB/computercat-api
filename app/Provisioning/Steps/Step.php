<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepResult;

// Varje steg är idempotent: körs det igen efter ett avbrott ska det känna igen
// det som redan är gjort och inte skapa något två gånger. Inget HTTP-anrop
// får ske inne i en databastransaktion.
interface Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult;
}
