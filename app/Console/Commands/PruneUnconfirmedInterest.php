<?php

namespace App\Console\Commands;

use App\Models\InterestSignup;
use Illuminate\Console\Command;

class PruneUnconfirmedInterest extends Command
{
    protected $signature = 'interest:prune-unconfirmed {--days=30 : Dagar innan en obekräftad anmälan tas bort}';

    protected $description = 'Tar bort intresseanmälningar som inte bekräftats inom N dagar efter bekräftelsemailet';

    public function handle(): int
    {
        // Räknas från när bekräftelsemailet gick iväg, inte från anmälan. En
        // anmälan som aldrig fått något mail (t.ex. innan mailen var
        // uppsatta) har inte kunnat bekräftas och får inte gallras bort.
        $antal = InterestSignup::whereNull('confirmed_at')
            ->whereNotNull('confirmation_sent_at')
            ->where('confirmation_sent_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Tog bort {$antal} obekräftade anmälningar.");

        return self::SUCCESS;
    }
}
