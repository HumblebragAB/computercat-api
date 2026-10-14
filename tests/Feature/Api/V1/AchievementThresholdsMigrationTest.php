<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AchievementThresholdsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_16_100006_move_tocco_achievement_thresholds_to_settings.php');
    }

    private function thresholds(Game $game): mixed
    {
        return $game->fresh()->settings['achievement_thresholds'] ?? null;
    }

    public function test_tocco_gets_the_count_thresholds_and_nothing_for_minutes_or_board_size(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => []]);

        $this->migration()->up();

        $t = $this->thresholds($tocco);
        $this->assertSame(5, $t['solve_5']);
        $this->assertSame(30, $t['streak_30']);
        $this->assertCount(11, $t);
        foreach (['first_12x12', 'first_14x14', 'speed_3min', 'speed_1min', 'speedrun_sub5'] as $slug) {
            $this->assertArrayNotHasKey($slug, $t);
        }
    }

    public function test_existing_thresholds_and_other_settings_are_untouched(): void
    {
        $secret = Crypt::encryptString('hemligt');
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => [
            'achievement_thresholds' => ['solve_5' => 9, 'own' => 2],
            'anthropic' => ['api_key' => $secret],
            'anti_cheat_preset' => 'none',
        ]]);

        $this->migration()->up();
        $this->migration()->up();

        $settings = $tocco->fresh()->settings;
        $this->assertSame(9, $settings['achievement_thresholds']['solve_5']);
        $this->assertSame(2, $settings['achievement_thresholds']['own']);
        $this->assertCount(12, $settings['achievement_thresholds']);
        $this->assertSame($secret, $settings['anthropic']['api_key']);
        $this->assertSame('hemligt', Crypt::decryptString($settings['anthropic']['api_key']));
        $this->assertSame('none', $settings['anti_cheat_preset']);
    }

    public function test_without_tocco_nothing_changes(): void
    {
        $other = Game::create(['slug' => 'annat-spel', 'name' => 'Annat', 'is_active' => true, 'settings' => ['x' => 1]]);

        $this->migration()->up();

        $this->assertSame(['x' => 1], $other->fresh()->settings);
    }

    public function test_down_removes_only_its_own_values(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => [
            'achievement_thresholds' => ['solve_5' => 9, 'own' => 2],
        ]]);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(['solve_5' => 9, 'own' => 2], $this->thresholds($tocco));
    }

    public function test_down_on_a_game_that_had_none_removes_the_key(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => ['y' => 'z']]);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(['y' => 'z'], $tocco->fresh()->settings);
    }

    public function test_unreadable_settings_stop_the_migration_and_change_nothing(): void
    {
        $tocco = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => []]);
        DB::table('games')->where('id', $tocco->id)->update(['settings' => json_encode('"dubbelkodad"')]);

        try {
            $this->migration()->up();
            $this->fail('migrationen skulle ha stannat');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inget ändrat', $e->getMessage());
        }

        $this->assertSame(json_encode('"dubbelkodad"'), DB::table('games')->where('id', $tocco->id)->value('settings'));
    }
}
