<?php

namespace App\Provisioning;

use App\Models\ProvisioningRun;
use App\Provisioning\Clients\SiteOpsClient;

// SiteOps ska inte larma för en Demo som ingen har pushat kod till än. Miljön
// skapas med is_monitored: false; första gången senaste bygget är klart och
// Demo svarar 200 slås övervakningen på, en gång, och tiden sparas på runnen.
final class DemoMonitoring
{
    public function __construct(private SiteOpsClient $siteops) {}

    /** @param array{status?: string}|null $latestDeployment */
    public function enableIfLive(ProvisioningRun $run, ?array $latestDeployment, ?int $urlStatus): bool
    {
        if ($run->monitoring_enabled_at !== null
            || $run->siteops_site_id === null
            || ($latestDeployment['status'] ?? null) !== 'finished'
            || $urlStatus !== 200) {
            return false;
        }

        $want = SiteOpsClient::normalizeUrl((string) $run->demo_url);
        foreach ($this->siteops->environments($run->siteops_site_id) as $environment) {
            if (($environment['type'] ?? null) === 'demo' && SiteOpsClient::normalizeUrl((string) ($environment['url'] ?? '')) === $want && is_int($environment['id'] ?? null)) {
                // Idempotent PATCH: två samtidiga anrop skriver samma värde.
                $this->siteops->setMonitored($run->siteops_site_id, $environment['id'], true);
                $run->forceFill(['monitoring_enabled_at' => now()])->save();

                return true;
            }
        }

        throw new StepFailed("SiteOps site {$run->siteops_site_id} has no demo environment for {$run->demo_url}; monitoring could not be enabled.");
    }
}
