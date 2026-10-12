<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage får nivån (guld, free, admin) och, för gratis, nyckeln. Befintliga
 * rader: environment Admin → tier admin, övriga guld (allt före gratisnivån var Guld).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('tier', 8)->default('guld')->after('environment');
            $table->foreignId('app_attest_key_id')->nullable()->after('tier')->constrained('glosis_app_attest_keys')->nullOnDelete();
            // Samma kolumner som AiBudget::spentMicroUsd() filtrerar på.
            $table->index(['game_id', 'tier', 'created_at'], 'ai_usage_game_tier_created_at_index');
        });
        $admin = DB::table('ai_usage')->where('environment', 'Admin')->update(['tier' => 'admin']);
        Log::info('ai_usage: tier satt', ['admin' => $admin, 'guld' => DB::table('ai_usage')->where('tier', 'guld')->count()]);
    }

    public function down(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->dropIndex('ai_usage_game_tier_created_at_index');
            $table->dropConstrainedForeignId('app_attest_key_id');
            $table->dropColumn('tier');
        });
    }
};
