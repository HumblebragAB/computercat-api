<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // JSON kan inte ha ett litteralt standardvärde i MySQL; modellen sätter [] för nya spel.
            $table->json('release_targets')->nullable()->after('is_listed');
            $table->boolean('releases_enabled')->default(true)->after('release_targets');
        });

        // Befintliga spel får en tom lista, aldrig null, så att koden inte behöver skilja på de två.
        DB::table('games')->update(['release_targets' => '[]']);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['release_targets', 'releases_enabled']);
        });
    }
};
