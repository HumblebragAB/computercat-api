<?php

namespace App\Console\Commands;

use App\Jobs\RunAppStoreSetup;
use App\Models\Game;
use App\Models\ReleaseSetupStep;
use App\Releases\AppStore\AppStoreConfig;
use App\Releases\AppStore\AppStoreSetup;
use Illuminate\Console\Command;

// "Computercat ser själv när appen finns" (appstore_create_app): köar engångsstegen för varje aktivt
// spel med målet app_store och ett öppet steg som Computercat gör eller kontrollerar mot Apple.
// Kommandot anropar inte Apple; jobbet gör en enhet per körning, och för ett spel som väntar på
// app-posten är det ett enda findApp. Jobbet är unikt per spel, så en körning som pågår köas inte igen.
// Ett fel larmar högst en gång per dygn och spel (SlackAlert). Ett steg som föll köas först när dess
// retry_at passerat (backoff, AppStoreSetup::isDue), och testarna först när ett giltigt bygge finns.
class QueueAppStoreSetup extends Command
{
    protected $signature = 'computercat:queue-app-store-setup';

    protected $description = 'Köar Computercats engångssteg för App Store för spel som väntar på något som Computercat gör eller kontrollerar';

    public function handle(): int
    {
        $missing = AppStoreConfig::missing();
        if ($missing !== []) {
            $this->error('App Store Connect är inte konfigurerat, saknas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $gameIds = ReleaseSetupStep::where('target', 'app_store')->where('status', ReleaseSetupStep::OPEN)
            ->whereIn('key', AppStoreSetup::automaticKeys())->distinct()->pluck('game_id');

        $queued = 0;
        foreach (Game::whereKey($gameIds)->where('is_active', true)->whereNotNull('bundle_id')->orderBy('id')->get() as $game) {
            if (! in_array('app_store', $game->releaseTargets(), true) || ! AppStoreSetup::isDue($game)) {
                continue;
            }
            RunAppStoreSetup::dispatch($game->id);
            $queued++;
        }
        $this->info("{$queued} spel köade.");

        return self::SUCCESS;
    }
}
