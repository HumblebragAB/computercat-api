<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\CoolifyConflictException;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Services\BuildGuide;

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

        // Samma guideversion som repo-steget frös (spec delprojekt 3, "Versioner"). Kommandot
        // räknas fram före markören, så att en olåst version eller en trasig guide stoppar
        // steget utan att Coolify anropas och utan en markör som senare kunde adoptera en app (R13).
        $version = self::buildGuideVersion($run);
        try {
            BuildGuide::coolifyBuildCommand($version);
        } catch (\RuntimeException $e) {
            throw new StepFailed("Build guide {$version} cannot produce the Coolify build command; nothing was created in Coolify.", 0, $e);
        }

        // Markören sparas före anropet, så att en omkörning efter ett avbrott vet
        // att appen kan vara vår.
        $step->update(['detail' => [...$detail, 'create_requested_at' => now()->toIso8601String()]]);

        // 409 (domänkonflikt) kastar CoolifyConflictException och stoppar sagan.
        try {
            $run->update(['coolify_app_uuid' => $this->coolify->createApplication($run->slug, $version)]);
        } catch (CoolifyConflictException|ExternalApiException $e) {
            // Ett definitivt avslag (4xx) betyder att ingen app skapades: markören
            // rensas, så att en app någon skapar för hand med samma namn aldrig
            // adopteras vid retry. Timeout, 5xx och anslutningsfel behåller den.
            if ($e instanceof CoolifyConflictException || self::isDefiniteRejection($e->status)) {
                unset($detail['create_requested_at']);
                $step->update(['detail' => $detail]);
            }

            throw $e;
        }

        return StepResult::done();
    }

    // Alla runs sedan delprojekt 2 har värdet; saknas det är något fel, och ingen version gissas.
    private static function buildGuideVersion(ProvisioningRun $run): string
    {
        $version = $run->steps()->where('step', 'repo')->first()?->detail['build_guide'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new StepFailed('The repo step has not recorded a build guide version, so the Coolify build command cannot be chosen.');
        }

        return $version;
    }

    private static function isDefiniteRejection(int $status): bool
    {
        return $status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true);
    }
}
