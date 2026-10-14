<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepResult;

// Kör ett steg som motorn gör: detail slås ihop efter varje körning.
trait StepTestHelpers
{
    protected function stepRow(ProvisioningRun $run, string $name): ProvisioningStep
    {
        return $run->steps()->firstOrCreate(['step' => $name], ['status' => ProvisioningStep::PENDING, 'attempts' => 0]);
    }

    protected function runStep(string $class, ProvisioningRun $run, string $name): StepResult
    {
        $step = $this->stepRow($run, $name);
        $result = app($class)->run($run, $step);
        $step->update(['detail' => array_merge($step->fresh()->detail ?? [], $result->detail)]);

        return $result;
    }
}
