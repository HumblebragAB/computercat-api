<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceRelease;
use App\Models\Release;
use App\Releases\ReleaseCatalog;
use Illuminate\Console\Command;

// Reserv (plan R11): köar AdvanceRelease för varje pågående släpp i ett steg som Computercat bygger.
// Jobbet är unikt per släpp, så ett jobb som redan väntar i kön får inget syskon.
class AdvanceReleases extends Command
{
    protected $signature = 'computercat:advance-releases';

    protected $description = 'Köar AdvanceRelease för pågående TestFlight-släpp (om ett jobb har tappats).';

    public function handle(): int
    {
        $ids = Release::whereIn('stage', ReleaseCatalog::BUILT_BY_COMPUTERCAT)
            ->whereIn('status', [Release::REQUESTED, Release::BUILDING, Release::PROCESSING])
            ->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            AdvanceRelease::dispatch($id);
        }
        $this->info('Köade '.count($ids).' släpp.');

        return self::SUCCESS;
    }
}
