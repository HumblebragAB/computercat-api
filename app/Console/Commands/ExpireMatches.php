<?php

namespace App\Console\Commands;

use App\Matches\MatchService;
use App\Models\GameMatch;
use Illuminate\Console\Command;

/**
 * Överger öppna matcher vars tid gått ut (waiting 15 min, active 30 min sedan senaste drag).
 * Själva utgången ligger i MatchService::expire(), som läser om under lås och prövar villkoret
 * igen, så ett drag som hann flytta expires_at vinner. Kommandot väljer bara kandidater.
 */
class ExpireMatches extends Command
{
    protected $signature = 'matches:expire';

    protected $description = 'Överger öppna matcher vars tid gått ut';

    public function handle(MatchService $service): int
    {
        $ids = GameMatch::open()->where('expires_at', '<', now())->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $match = GameMatch::find($id);
            if ($match !== null && $service->expire($match)) {
                $expired++;
            }
        }

        $this->info("Överger {$expired} matcher.");

        return self::SUCCESS;
    }
}
