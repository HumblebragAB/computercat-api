<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Default true: befintliga spel syns som förut i GET /api/v1/games.
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_listed')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('is_listed');
        });
    }
};
