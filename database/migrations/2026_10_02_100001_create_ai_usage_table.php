<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En rad per betalt AI-anrop (Glosis fotoskanning och studioröst). Underlag
 * för månadsbudgeten och sidan "AI-användning" i Filament. Ingen persondata:
 * varken bild, ord, köp-id eller IP sparas här. Bara additiv: en ny tabell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 16);
            $table->string('environment', 16);
            $table->unsignedInteger('units')->default(1);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('characters')->nullable();
            $table->decimal('est_cost_usd', 12, 6);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature', 'created_at'], 'ai_usage_feature_created_at_index');
            $table->index(['game_id', 'created_at'], 'ai_usage_game_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
