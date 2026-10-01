<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;

/**
 * Spelet glosis. Idempotent: skapar raden om den saknas och ser annars bara
 * till att namn och is_active stämmer. settings rörs aldrig på en befintlig
 * rad, där ligger t.ex. den krypterade Anthropic-nyckeln och site_url.
 *
 * Migrationen 2026_09_30_100002_add_glosis_game skapar samma rad; seedern
 * finns för miljöer där den behöver återställas.
 */
class GlosisGameSeeder extends Seeder
{
    public function run(): void
    {
        $game = Game::firstOrCreate(
            ['slug' => 'glosis'],
            [
                'name' => 'Glosis',
                'description' => 'Engelska glosor som ett spel.',
                'settings' => ['site_url' => 'https://glosis.se'],
                'is_active' => true,
            ],
        );

        $game->fill(['name' => 'Glosis', 'is_active' => true]);
        if ($game->isDirty()) {
            $game->save();
        }
    }
}
