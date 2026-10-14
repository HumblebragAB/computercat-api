<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\SafeMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Skriver ett kvitto per lyckat Demo-bygge (spärr 1), eftersom Coolifys bygglista
// bara visar de senaste. Ett kvitto kräver status finished och ett fullständigt
// commit-SHA (R8, S26); allt annat ignoreras och loggas en gång per bygge.
final class DemoBuildRecorder
{
    public function __construct(private CoolifyClient $coolify) {}

    /** @return int antal nya kvitton */
    public function recordForRun(ProvisioningRun $run): int
    {
        if ($run->kind !== ProvisioningRun::KIND_PROVISION
            || $run->status !== ProvisioningRun::SUCCEEDED
            || $run->game_id === null
            || $run->coolify_app_uuid === null) {
            return 0;
        }

        $created = 0;
        foreach ($this->coolify->recentDeployments($run->coolify_app_uuid, (int) config('releases.demo_poll_take')) as $deployment) {
            if ($deployment['status'] !== 'finished') {
                continue;
            }

            $commit = is_string($deployment['commit']) ? strtolower($deployment['commit']) : null;
            if ($commit === null || ! ReleaseCatalog::isCommit($commit)) {
                if (Cache::add('demo-build-skipped:'.$deployment['deployment_uuid'], true, 7 * 86400)) {
                    Log::warning('Demo-bygge utan fullständigt commit-SHA, inget kvitto skrivs', ['game_id' => $run->game_id, 'deployment' => $deployment['deployment_uuid']]);
                }

                continue;
            }

            try {
                $build = DemoBuild::firstOrCreate(
                    ['game_id' => $run->game_id, 'deployment_uuid' => $deployment['deployment_uuid']],
                    ['commit_sha' => $commit, 'deployed_at' => Carbon::parse($deployment['created_at'])],
                );
            } catch (UniqueConstraintViolationException) {
                // En annan process (pollen, statusanropet) hann före: kvittot finns.
                continue;
            }
            $created += $build->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @return array{recorded: int, failed: array<string, string>} slug => redigerat fel */
    public function recordAll(): array
    {
        $recorded = 0;
        $failed = [];

        $runs = ProvisioningRun::provision()->where('status', ProvisioningRun::SUCCEEDED)
            ->whereNotNull('game_id')->whereNotNull('coolify_app_uuid')->orderBy('id')->get();
        foreach ($runs as $run) {
            try {
                $recorded += $this->recordForRun($run);
            } catch (Throwable $e) {
                $failed[$run->slug] = $e instanceof SafeMessage ? $e->getMessage() : get_class($e);
            }
        }

        return ['recorded' => $recorded, 'failed' => $failed];
    }
}
