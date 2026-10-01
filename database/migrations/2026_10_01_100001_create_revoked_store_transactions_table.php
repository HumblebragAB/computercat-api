<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spärrlista för återbetalda butiksköp, nyckel original_transaction_id.
 * Glosis har inga konton, så en återbetalning kan inte kopplas till en
 * användare; spärren gäller köpet. Fylls från RevenueCats webhook.
 * Bara additiv: en ny tabell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revoked_store_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('store', 20);
            $table->string('original_transaction_id');
            $table->string('transaction_id')->nullable();
            $table->string('reason', 50)->nullable();
            $table->timestamp('revoked_at');
            $table->timestamps();

            $table->unique(['game_id', 'original_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_store_transactions');
    }
};
