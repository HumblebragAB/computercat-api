<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\StepResult;

// SiteOps har ingen radering via API: sajten avaktiveras och övervakningen stängs.
final class SiteOpsDeactivateStep implements Step
{
    public function __construct(private SiteOpsClient $siteops) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->siteops_site_id === null) {
            return StepResult::done(['skipped' => 'no SiteOps site recorded']);
        }

        try {
            $this->siteops->deactivate($run->siteops_site_id);
        } catch (ExternalApiException $e) {
            // 404: sajten är redan borttagen i SiteOps.
            if ($e->status === 404) {
                return StepResult::done(['skipped' => 'site already removed in SiteOps']);
            }

            throw $e;
        }

        return StepResult::done();
    }
}
