<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kvitto per lyckat Demo-bygge. Coolifys bygglista visar bara de senaste byggena.
        Schema::create('demo_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('deployment_uuid', 64);
            $table->timestamp('deployed_at');
            $table->timestamps();
            $table->unique(['game_id', 'deployment_uuid']);
            $table->index(['game_id', 'commit_sha']);
            $table->index(['game_id', 'deployed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_builds');
    }
};
