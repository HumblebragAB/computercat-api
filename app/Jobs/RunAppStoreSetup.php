<?php

namespace App\Jobs;

use App\Models\Game;
use App\Provisioning\SafeMessage;
use App\Releases\AppStore\AppStoreSetup;
use App\Support\SlackAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Kör Computercats engångssteg för app_store, en enhet (högst fyra anrop à 15 s) per körning, och
// släpper sig själv till nästa enhet. Köas när app_store läggs till, när Erik bockar av app-posten och
// från Filament. Ett fel stannar på steget (last_error) och larmar; ingen omförsöksloop.
// $tries = 0 med retryUntil (som AdvanceProvisioningRun): varje release() räknas som ett försök.
class RunAppStoreSetup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    // Under databasköns retry_after (90 s): fyra anrop à 15 s plus JWT och databas.
    public int $timeout = 75;

    public int $backoff = 30;

    public int $uniqueFor = 1800;

    public function __construct(public int $gameId) {}

    public function uniqueId(): string
    {
        return 'appstore-setup-'.$this->gameId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30);
    }

    public function handle(AppStoreSetup $setup, SlackAlert $slack): void
    {
        $game = Game::find($this->gameId);
        if ($game === null || ! $game->is_active) {
            return;
        }

        try {
            [$outcome] = $setup->runOne($game);
        } catch (Throwable $e) {
            $ref = (string) Str::uuid();
            Log::error('Engångssteg för App Store misslyckades', ['ref' => $ref, 'game' => $game->slug, 'exception' => get_class($e), 'message' => $e instanceof SafeMessage ? $e->getMessage() : null]);
            $why = $e instanceof SafeMessage ? $e->getMessage() : "internt fel (ref {$ref})";
            $slack->send("appstore-setup-failed:{$game->slug}", 'Engångsstegen för App Store stannade för `'.SlackAlert::escape($game->slug).'`: '.SlackAlert::escape($why).' Kör igen från Filament när orsaken är åtgärdad.');

            return;
        }

        if ($outcome === AppStoreSetup::PROGRESS) {
            $this->release(1);
        }
    }
}
