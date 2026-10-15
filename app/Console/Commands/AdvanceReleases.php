<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceRelease;
use App\Models\Release;
use App\Releases\ReleaseCatalog;
use App\Releases\TestFlight\TestFlightBuilder;
use Illuminate\Console\Command;

// Reserv (plan R11): köar AdvanceRelease för varje pågående släpp i ett steg som Computercat bygger,
// och försöker igen att avbryta körningen för fällda släpp där det inte gick direkt.
// Jobbet är unikt per släpp, så ett jobb som redan väntar i kön får inget syskon.
class AdvanceReleases extends Command
{
    protected $signature = 'computercat:advance-releases';

    protected $description = 'Köar AdvanceRelease för pågående TestFlight-släpp (om ett jobb har tappats).';

    public function handle(TestFlightBuilder $builder): int
    {
        $ids = Release::whereIn('stage', ReleaseCatalog::BUILT_BY_COMPUTERCAT)
            ->whereIn('status', [Release::REQUESTED, Release::BUILDING, Release::PROCESSING])
            ->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            AdvanceRelease::dispatch($id);
        }
        $this->info('Köade '.count($ids).' släpp.');

        // Fällda släpp vars körning inte gick att avbryta när de fälldes (R15).
        $pending = Release::with('game')->whereIn('stage', ReleaseCatalog::BUILT_BY_COMPUTERCAT)
            ->whereIn('status', [Release::FAILED, Release::CANCELLED])
            ->where('external->cancel_pending', true)->orderBy('id')->get();
        foreach ($pending as $release) {
            $builder->retryCancel($release);
        }
        if ($pending->isNotEmpty()) {
            $this->info('Försökte avbryta '.$pending->count().' körning(ar) för fällda släpp.');
        }

        return self::SUCCESS;
    }
}
