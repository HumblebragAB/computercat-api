<?php

namespace App\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;

final class Saga
{
    /** Ordningen är sagans ordning. */
    public const PROVISION_STEPS = [
        'repo' => Steps\RepoStep::class,
        'game' => Steps\GameStep::class,
        'coolify_app' => Steps\CoolifyAppStep::class,
        'coolify_env' => Steps\CoolifyEnvStep::class,
        'cors' => Steps\CorsStep::class,
        'siteops' => Steps\SiteOpsStep::class,
    ];

    public const ARCHIVE_STEPS = [
        'coolify_delete' => Steps\CoolifyDeleteStep::class,
        'siteops_deactivate' => Steps\SiteOpsDeactivateStep::class,
        'repo_archive' => Steps\RepoArchiveStep::class,
        'game_deactivate' => Steps\GameDeactivateStep::class,
    ];

    /** @return array<string, class-string<Steps\Step>> */
    public static function steps(string $kind): array
    {
        return match ($kind) {
            ProvisioningRun::KIND_PROVISION => self::PROVISION_STEPS,
            ProvisioningRun::KIND_ARCHIVE => self::ARCHIVE_STEPS,
            default => throw new \LogicException("Okänd run-typ: {$kind}"),
        };
    }

    public static function start(ProvisioningRun $run): void
    {
        foreach (array_keys(self::steps($run->kind)) as $name) {
            $run->steps()->firstOrCreate(['step' => $name], ['status' => ProvisioningStep::PENDING, 'attempts' => 0]);
        }

        AdvanceProvisioningRun::dispatch($run->id);
    }

    // Fortsätter från första ej klara steg. Villkorad uppdatering, så att två
    // samtidiga retry inte köar två jobb.
    public static function retry(ProvisioningRun $run): bool
    {
        $updated = ProvisioningRun::whereKey($run->id)
            ->where('status', ProvisioningRun::FAILED)
            ->update(['status' => ProvisioningRun::RUNNING, 'last_error' => null, 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        // Väntefönster (t.ex. README-väntan) startar om vid retry; annars fallerar
        // steget direkt på en tidsstämpel från förra försöket.
        foreach ($run->steps()->where('status', ProvisioningStep::FAILED)->get() as $failed) {
            $detail = $failed->detail ?? [];
            unset($detail['readme_wait_started_at']);
            $failed->update(['status' => ProvisioningStep::PENDING, 'detail' => $detail]);
        }
        // En run vars start() föll innan alla steg skapats (create_game markerar
        // den failed) får sina saknade steg här.
        foreach (array_keys(self::steps($run->kind)) as $name) {
            $run->steps()->firstOrCreate(['step' => $name], ['status' => ProvisioningStep::PENDING, 'attempts' => 0]);
        }
        AdvanceProvisioningRun::dispatch($run->id);

        return true;
    }

    public static function handler(ProvisioningRun $run, string $step): Steps\Step
    {
        $class = self::steps($run->kind)[$step] ?? throw new \LogicException("Okänt steg {$step} för {$run->kind}");

        return app($class);
    }
}
