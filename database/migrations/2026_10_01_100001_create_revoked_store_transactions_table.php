<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spärrlista för återbetalda butiksköp, nyckel original_transaction_id.
 * Glosis har inga konton, så en återbetalning kan inte kopplas till en
 * användare; spärren gäller köpet. Fylls från RevenueCats webhook.
 * Bara additiv: en ny tabell.
 *
 * Första deployen föll på MySQL:s gräns på 64 tecken för indexnamn efter att
 * CREATE TABLE redan körts (DDL committas direkt), så tabellen kan finnas utan
 * index och främmande nyckel. Är den tom byggs den om; har den rader stoppar vi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('revoked_store_transactions')) {
            $rows = DB::table('revoked_store_transactions')->count();
            if ($rows > 0) {
                throw new RuntimeException("revoked_store_transactions finns redan med {$rows} rader; bygg inte om den automatiskt.");
            }
            Schema::drop('revoked_store_transactions');
        }

        Schema::create('revoked_store_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('store', 20);
            $table->string('original_transaction_id');
            $table->string('transaction_id')->nullable();
            $table->string('reason', 50)->nullable();
            $table->timestamp('revoked_at');
            $table->timestamps();

            // Eget kort namn: det automatiska blir 65 tecken, MySQL tillåter 64.
            $table->unique(['game_id', 'original_transaction_id'], 'revoked_store_tx_game_original_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_store_transactions');
    }
};
