<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// SiteOps-sajt av typ app med en demo-miljö som övervakas först när Demo lever
// (DemoMonitoring). Ett avbrott mellan
// skapandet och sparningen fångas av uppslaget på exakt URL (eller 409) vid
// omkörning. En befintlig webb adopteras bara om den bevisligen är vår: rätt
// adress, aktiv, en demo-miljö med samma adress, och PATCH av miljön ger 200
// (2a ger 403 för webbar som en annan klient eller UI:t skapade).
final class SiteOpsStep implements Step
{
    public function __construct(private SiteOpsClient $siteops) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->siteops_site_id !== null) {
            return StepResult::done();
        }

        $url = ProvisioningConfig::demoUrl($run->slug);
        $found = $this->siteops->findIdsByUrl($url);

        if (count($found) > 1) {
            throw new StepFailed(count($found).' SiteOps sites match '.$url.' (ids '.implode(', ', $found).'). Resolve in SiteOps, then retry_provisioning.');
        }

        if ($found === []) {
            $created = $this->siteops->createDemoSite($run->name, $url);
            if ($created['created']) {
                $run->update(['siteops_site_id' => $created['id']]);

                return StepResult::done(['siteops' => 'created']);
            }
            $found = [$created['id']];
        }

        $this->adopt($found[0], $url);
        $run->update(['siteops_site_id' => $found[0]]);

        return StepResult::done(['siteops' => 'adopted']);
    }

    private function adopt(int $id, string $url): void
    {
        $want = SiteOpsClient::normalizeUrl($url);
        $site = $this->siteops->site($id);

        if (SiteOpsClient::normalizeUrl((string) ($site['url'] ?? '')) !== $want) {
            throw new StepFailed("SiteOps site {$id} has the Demo URL on an environment but its own URL is different; it is not a Computercat Demo site. Resolve in SiteOps.");
        }
        if (($site['is_active'] ?? null) !== true) {
            throw new StepFailed("SiteOps site {$id} for {$url} exists but is inactive. Resolve in SiteOps (no new site is created next to it).");
        }

        $environment = null;
        foreach ($this->siteops->environments($id) as $candidate) {
            if (($candidate['type'] ?? null) === 'demo' && SiteOpsClient::normalizeUrl((string) ($candidate['url'] ?? '')) === $want) {
                $environment = $candidate;
            }
        }
        if (! is_int($environment['id'] ?? null)) {
            throw new StepFailed("SiteOps site {$id} has no demo environment for {$url}.");
        }

        if (! $this->siteops->claimDemoEnvironment($id, $environment['id'])) {
            throw new StepFailed("SiteOps site {$id} for {$url} was not created by Computercat and is not adopted. Resolve in SiteOps.");
        }
    }
}
