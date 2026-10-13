<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource;
use App\Models\AchievementDefinition;
use App\Models\DailyContentPool;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\LeaderboardType;
use App\Models\RemoteConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDevKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_in_names_is_escaped_and_no_token_is_created(): void
    {
        $game = Game::create(['slug' => 'x', 'name' => '<img src=x onerror=alert(1)>', 'is_active' => true, 'settings' => []]);
        AchievementDefinition::create(['game_id' => $game->id, 'slug' => 'a', 'name' => '<script>alert(2)</script>', 'description' => 'd', 'icon' => '*']);
        $user = User::factory()->staff()->create();

        $html = $this->actingAs($user)->get(GameResource::getUrl('devkit', ['record' => $game]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_page_renders_for_game_with_every_feature_configured(): void
    {
        $game = Game::create(['slug' => 'full', 'name' => 'Full Game', 'is_active' => true, 'settings' => [
            'anti_cheat_preset' => 'time_ms',
            'anti_cheat' => ['min_score' => 5000, 'max_score' => 86400000, 'proof_algorithm' => 'fnv1a', 'require_proof' => true, 'min_moves' => 3],
        ]]);
        LeaderboardType::create(['game_id' => $game->id, 'slug' => 'daily-time', 'name' => 'Daily time', 'sort_direction' => 'asc', 'score_label' => 'Time', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true]);
        AchievementDefinition::create(['game_id' => $game->id, 'slug' => 'first-win', 'name' => 'First win', 'description' => 'Win once', 'icon' => '*', 'sort_order' => 1]);
        DailyContentPool::create(['game_id' => $game->id, 'pool_key' => 'puzzles', 'content' => [['a' => 1]], 'is_active' => true, 'random_access' => true]);
        RemoteConfig::create(['game_id' => $game->id, 'key' => 'maintenance_mode', 'value' => 'false', 'value_type' => 'bool', 'description' => 'Maint', 'is_active' => true]);
        GameEvent::create(['game_id' => $game->id, 'slug' => 'spring', 'name' => 'Spring', 'description' => 'd', 'event_type' => 'seasonal', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'settings' => [], 'is_active' => true]);
        $user = User::factory()->staff()->create();

        $this->actingAs($user)->get(GameResource::getUrl('devkit', ['record' => $game]))
            ->assertOk()
            ->assertSee('Score range:', false)
            ->assertSee('Scheduled Events')
            ->assertSee('There is no shared dev token.')
            ->assertDontSee('Dev token');
        $this->assertSame(0, $user->tokens()->count());
    }
}
