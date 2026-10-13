<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kopplar ett token som skapats av POST /api/v1/auth/refresh till tokenet det
// ersätter. Det gamla tokenet kortas först när det nya används första gången.
// Ingen främmande nyckel: sanctum:prune-expired raderar föräldrar fritt, och
// en kvarhängande koppling gör ingen skada. Inget index: föräldern läses
// via primärnyckeln, och utan index kan MySQL 8 lägga till kolumnen direkt.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('refreshed_from_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('refreshed_from_id');
        });
    }
};
