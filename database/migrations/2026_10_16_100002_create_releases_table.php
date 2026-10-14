<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('target', 16);
            $table->string('stage', 24);
            $table->string('commit_sha', 40);
            $table->string('status', 20);
            // 'active' medan släppet är pågående, annars null. Det unika indexet nedan
            // tillåter flera null (även i MySQL) men bara ett pågående släpp per plats.
            $table->string('active_key', 8)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('self_approved')->default(false);
            // GitHub run-id, Apple build-id och version, Play version code, Coolify deployment-uuid (etapp 1b och framåt).
            $table->json('external')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'target', 'stage', 'active_key']);
            $table->index(['game_id', 'target', 'stage', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
