<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Rimlighetskontrollen för achievements läser bara trösklar ur settings.achievement_thresholds
// (plan 2026-10-14-achievements-svar). Tidigare tog den första talet i slugen. Toccos slugs där
// talet är ett antal (lösta pussel, dagar i rad, veckoutmaningar) behåller exakt samma krav här.
// De där talet är minuter eller brädstorlek (first_12x12, speed_3min m.fl.) får inget: det är rättelsen.
// settings är ren JSON; krypterade värden är strängar i den och lämnas orörda.
return new class extends Migration
{
    private const THRESHOLDS = [
        'solve_5' => 5,
        'solve_25' => 25,
        'solve_100' => 100,
        'daily_30' => 30,
        'daily_100' => 100,
        'streak_3' => 3,
        'streak_7' => 7,
        'streak_30' => 30,
        'weekly_4' => 4,
        'weekly_12' => 12,
        'zen_10' => 10,
    ];

    public function up(): void
    {
        $this->change(function (array $current): array {
            // Befintliga trösklar vinner; migrationen skriver aldrig över ett satt värde.
            return $current + self::THRESHOLDS;
        });
    }

    public function down(): void
    {
        $this->change(function (array $current): array {
            foreach (self::THRESHOLDS as $slug => $value) {
                if (($current[$slug] ?? null) === $value) {
                    unset($current[$slug]);
                }
            }

            return $current;
        });
    }

    private function change(callable $fn): void
    {
        $game = DB::table('games')->where('slug', 'tocco')->first(['id', 'settings']);
        if ($game === null) {
            return;
        }
        $settings = json_decode((string) $game->settings, true);
        $settings = is_array($settings) ? $settings : [];
        $current = $settings['achievement_thresholds'] ?? [];
        $settings['achievement_thresholds'] = $fn(is_array($current) ? $current : []);
        if ($settings['achievement_thresholds'] === []) {
            unset($settings['achievement_thresholds']);
        }
        DB::table('games')->where('id', $game->id)->update(['settings' => json_encode($settings)]);
    }
};
