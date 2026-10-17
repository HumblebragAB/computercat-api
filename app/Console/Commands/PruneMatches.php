<?php

namespace App\Console\Commands;

use App\Models\GameMatch;
use Illuminate\Console\Command;

/**
 * Raderar avslutade och övergivna matcher som legat orörda i mer än --days dagar.
 * Väljer id:n med statusvillkoret och raderar exakt de id:na, i omgångar om 500.
 * Spelare och händelser följer med via främmande nycklar (cascade).
 */
class PruneMatches extends Command
{
    protected $signature = 'matches:prune {--days=7 : Dagar innan en avslutad eller övergiven match raderas}';

    protected $description = 'Raderar avslutade och övergivna matcher äldre än N dagar';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            $this->error('--days måste vara ett heltal på minst 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $statuses = [GameMatch::STATUS_FINISHED, GameMatch::STATUS_ABANDONED];

        $ids = GameMatch::whereIn('status', $statuses)->where('updated_at', '<', $cutoff)->pluck('id');

        $deleted = 0;
        foreach ($ids->chunk(500) as $chunk) {
            // Statusvillkoret upprepas så en match som återupptagits under tiden inte raderas.
            $deleted += GameMatch::whereIn('id', $chunk->all())->whereIn('status', $statuses)->delete();
        }

        $this->info("Raderade {$deleted} matcher.");

        return self::SUCCESS;
    }
}
