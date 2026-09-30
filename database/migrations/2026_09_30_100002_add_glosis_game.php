<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spelet glosis (beslut i Glosis docs/beslut.md: "Glosis blir spelet glosis
 * i den befintliga Laravel-tjänsten"). Behövs för intresseanmälan redan före
 * appen. Rör inte en befintlig rad.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('games')->where('slug', 'glosis')->exists()) {
            return;
        }

        DB::table('games')->insert([
            'slug' => 'glosis',
            'name' => 'Glosis',
            'description' => 'Engelska glosor som ett spel.',
            'settings' => json_encode(['site_url' => 'https://glosis.se']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Spelet kan ha fått data knuten till sig; tas bort för hand om alls.
    }
};
