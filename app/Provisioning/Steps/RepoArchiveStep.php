<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Repot arkiveras (PATCH archived: true). Det raderas aldrig.
final class RepoArchiveStep implements Step
{
    public function __construct(private GitHubAppClient $github) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->github_repo !== ProvisioningConfig::repoFullName($run->slug)) {
            throw new StepFailed('The archive run has no repository recorded for this game.');
        }

        $repo = $this->github->getRepository($run->slug) ?? throw new StepFailed("Repository {$run->github_repo} was not found on GitHub.");
        if (($repo['archived'] ?? false) === true) {
            return StepResult::done();
        }

        $this->github->archiveRepository($run->slug);

        return StepResult::done();
    }
}
