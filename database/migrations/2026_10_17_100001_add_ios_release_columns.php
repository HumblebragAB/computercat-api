<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Släppflöde etapp 2 del 1 (TestFlight).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // se.computercat.<slug utan bindestreck> (Eriks beslut 2026-10-15). Unikt: ab-c och abc krockar.
            $table->string('bundle_id', 155)->nullable()->unique();
            // Senast utdelade byggnumret för iOS-appen. Stiger och återanvänds aldrig (R5).
            $table->unsignedInteger('ios_build_number')->default(0);
            // Id:n i App Store Connect som Computercat skapat eller hittat. Inga hemligheter.
            $table->json('app_store_ids')->nullable();
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->string('marketing_version', 20)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('build_guide', 8)->nullable();
            // Ett byggnummer används en gång per spel (null för steg utan bygge).
            $table->unique(['game_id', 'build_number']);
        });

        Schema::table('release_setup_steps', function (Blueprint $table) {
            // Senaste felet när Computercat försökte göra ett automatiskt steg (redigerat).
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('release_setup_steps', fn (Blueprint $table) => $table->dropColumn('last_error'));
        Schema::table('releases', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'build_number']);
            $table->dropColumn(['marketing_version', 'build_number', 'build_guide']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['bundle_id']);
            $table->dropColumn(['bundle_id', 'ios_build_number', 'app_store_ids']);
        });
    }
};
