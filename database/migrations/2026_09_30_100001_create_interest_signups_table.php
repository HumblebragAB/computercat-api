<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            // de, es, fr eller other. Vilket språk familjen helst vill ha härnäst.
            $table->string('language', 10);
            // sha256 av token i mailets länkar. Själva token sparas aldrig.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'email']);
            $table->index(['game_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_signups');
    }
};
