<?php

use App\Models\Game;
use App\Releases\AppStore\AppStoreSetup;
use App\Releases\AppStore\BundleId;
use App\Releases\SetupSteps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// Spel som fick app_store i etapp 1a får bundle-id och de nya engångsstegen, och steg som bockats av
// utan det id Computercat behöver öppnas igen (R17; i 1a kunde appstore_create_app bockas av för hand
// utan app-id). En krock fäller migrationen (unikt index): fail loud, Erik väljer id. Automatiska steg
// körs först när Erik trycker "Kör Computercats steg" i Filament (inga anrop mot Apple från en migration).
return new class extends Migration
{
    public function up(): void
    {
        foreach (Game::query()->orderBy('id')->get() as $game) {
            if (! in_array('app_store', $game->releaseTargets(), true)) {
                continue;
            }
            if ($game->bundle_id === null) {
                $game->forceFill(['bundle_id' => BundleId::forSlug($game->slug)])->save();
            }
            SetupSteps::sync($game);
            $reopened = AppStoreSetup::reopenStepsWithoutIds($game);
            Log::notice('Etapp 2: bundle-id och App Store-steg', ['game' => $game->slug, 'bundle_id' => $game->bundle_id, 'reopened' => $reopened]);
        }
    }

    public function down(): void
    {
        // Ingenting: bundle-id och steg tas bort med kolumnerna i 100001:s down.
    }
};
