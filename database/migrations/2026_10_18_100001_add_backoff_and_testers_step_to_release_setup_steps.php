<?php

use App\Models\Game;
use App\Releases\SetupSteps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Eriks beslut 2026-10-18: TestFlight-gruppen räcker för första bygget, och testarna läggs till efter
// första giltiga bygget i ett eget steg (appstore_testflight_testers) som inte stoppar något släpp.
// Ett steg som faller väntar allt längre mellan försöken (failed_attempts, retry_at) i stället för att
// köas om var tionde minut. Spel som redan har app_store får det nya steget här (bara databasen, inga
// anrop mot Apple); ett öppet gruppsteg med sparat beta_group_id blir klart vid nästa körning.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_setup_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('last_error');
            $table->timestamp('retry_at')->nullable()->after('failed_attempts');
        });

        $this->syncAppStoreSteps();
    }

    public function syncAppStoreSteps(): void
    {
        foreach (Game::query()->orderBy('id')->get() as $game) {
            if (! in_array('app_store', $game->releaseTargets(), true)) {
                continue;
            }
            SetupSteps::sync($game);
            Log::notice('App Store-steg synkade (testarsteget)', ['game' => $game->slug]);
        }
    }

    public function down(): void
    {
        // Den gamla koden känner inte steget, och ett okänt öppet steg stoppar alla målets släpp (SetupSteps::blocks).
        DB::table('release_setup_steps')->where('key', 'appstore_testflight_testers')->delete();
        Schema::table('release_setup_steps', function (Blueprint $table) {
            $table->dropColumn(['failed_attempts', 'retry_at']);
        });
    }
};
