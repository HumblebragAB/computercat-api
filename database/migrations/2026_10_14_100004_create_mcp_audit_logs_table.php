<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 64);
            $table->json('arguments');
            $table->string('outcome', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
