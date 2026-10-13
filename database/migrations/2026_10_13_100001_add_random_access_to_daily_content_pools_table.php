<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pooler måste välja in för /pools/{key}/items. Slumpade uttag läser ut hela poolen,
 * så en daglig pool (ingen tittar framåt) får inte vara åtkomlig den vägen av misstag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_content_pools', function (Blueprint $table) {
            $table->boolean('random_access')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('daily_content_pools', function (Blueprint $table) {
            $table->dropColumn('random_access');
        });
    }
};
