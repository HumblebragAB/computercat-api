<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sätts bara av computercat:staff. Migrationen ger ingen behörighet:
        // e-postadresser är overifierade och kan registreras av spelare.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('is_banned');
            $table->boolean('is_platform_admin')->default(false)->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_staff', 'is_platform_admin']);
        });
    }
};
