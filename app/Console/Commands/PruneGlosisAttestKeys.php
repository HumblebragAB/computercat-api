<?php

namespace App\Console\Commands;

use App\Models\AppAttestKey;
use Illuminate\Console\Command;

/** Gallrar App Attest-nycklar som inte använts på --months månader (integritetspolicyn). Räknar först. */
class PruneGlosisAttestKeys extends Command
{
    protected $signature = 'glosis:prune-attest-keys {--months=13} {--dry-run}';

    protected $description = 'Raderar Glosis App Attest-nycklar som inte använts på länge';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);
        $ids = AppAttestKey::where(fn ($q) => $q->where('last_used_at', '<', $cutoff)
            ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $cutoff)))->pluck('id');
        $total = AppAttestKey::count();
        $this->info(sprintf('%d nyckel/nycklar av %d oanvända sedan %s.', $ids->count(), $total, $cutoff->toDateString()));
        if ($this->option('dry-run') || $ids->isEmpty()) {
            return self::SUCCESS;
        }
        // Raderar exakt de id:n som räknades ovan.
        $deleted = AppAttestKey::whereIn('id', $ids)->delete();
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }
}
