<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Matcher: turordningsbaserade tvåspelarmatcher med dolt läge per spelare.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('ruleset', 40);
            // JSON-kolumner får ingen literal default (MySQL). Koden sätter alltid värdena.
            $table->json('options');
            $table->string('status', 16)->index();
            // Bara sha256 av inbjudningstoken lagras, aldrig token själv.
            $table->char('join_token_hash', 64)->nullable()->unique();
            $table->json('state');
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->ulid('rematch_of')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'status']);
        });

        Schema::create('match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->string('name', 20);
            // Krypterad. Heter inte "hidden": det krockar med Eloquents $hidden.
            $table->text('hidden_state')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('rematch_requested')->default(false);
            $table->timestamps();

            $table->unique(['match_id', 'seat']);
            $table->unique(['match_id', 'user_id']);
        });

        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('type', 32);
            $table->unsignedTinyInteger('seat')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['match_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
        Schema::dropIfExists('match_players');
        Schema::dropIfExists('matches');
    }
};
