<?php

namespace App\Console\Commands;

use App\Models\InterestSignup;
use Illuminate\Console\Command;

class PruneUnconfirmedInterest extends Command
{
    protected $signature = 'interest:prune-unconfirmed {--days=30 : Dagar innan en obekräftad anmälan tas bort}';

    protected $description = 'Tar bort intresseanmälningar som inte bekräftats inom N dagar';

    public function handle(): int
    {
        $antal = InterestSignup::whereNull('confirmed_at')
            ->where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Tog bort {$antal} obekräftade anmälningar.");

        return self::SUCCESS;
    }
}
