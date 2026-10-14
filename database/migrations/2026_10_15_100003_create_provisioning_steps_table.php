<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('provisioning_runs')->cascadeOnDelete();
            $table->string('step', 32);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_steps');
    }
};
