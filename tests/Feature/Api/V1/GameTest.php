<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use App\Models\LeaderboardType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migreringen add_glosis_game lägger in spelet glosis. Testerna här
        // räknar spelen de själva skapar och börjar därför från tom tabell.
        Game::query()->delete();
    }

    // -------------------------------------------------------
    // List games — returns active games
    // -------------------------------------------------------

    public function test_list_games_returns_active_games(): void
    {
        Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
        ]);

        Game::create([
            'slug' => 'puzzle-rush',
            'name' => 'Puzzle Rush',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/games');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    // -------------------------------------------------------
    // List games — excludes inactive games
    // -------------------------------------------------------

    public function test_list_games_excludes_inactive_games(): void
    {
        Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
        ]);

        Game::create([
            'slug' => 'retired-game',
            'name' => 'Retired Game',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/games');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'tocco');
    }

    // -------------------------------------------------------
    // Show game — returns game with leaderboard types
    // -------------------------------------------------------

    public function test_show_game_returns_game_with_leaderboard_types(): void
    {
        $game = Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
        ]);

        LeaderboardType::create([
            'game_id' => $game->id,
            'slug' => 'daily-best',
            'name' => 'Daily Best',
            'sort_direction' => 'asc',
            'score_label' => 'Time',
            'period' => 'daily',
            'is_active' => true,
        ]);

        LeaderboardType::create([
            'game_id' => $game->id,
            'slug' => 'weekly-best',
            'name' => 'Weekly Best',
            'sort_direction' => 'asc',
            'score_label' => 'Time',
            'period' => 'weekly',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/games/tocco');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'tocco')
            ->assertJsonPath('data.name', 'Tocco')
            ->assertJsonCount(2, 'data.leaderboard_types');
    }

    // -------------------------------------------------------
    // Show game — excludes inactive leaderboard types
    // -------------------------------------------------------

    public function test_show_game_excludes_inactive_leaderboard_types(): void
    {
        $game = Game::create([
            'slug' => 'tocco',
            'name' => 'Tocco',
            'is_active' => true,
        ]);

        LeaderboardType::create([
            'game_id' => $game->id,
            'slug' => 'daily-best',
            'name' => 'Daily Best',
            'sort_direction' => 'asc',
            'score_label' => 'Time',
            'period' => 'daily',
            'is_active' => true,
        ]);

        LeaderboardType::create([
            'game_id' => $game->id,
            'slug' => 'deprecated',
            'name' => 'Deprecated Board',
            'sort_direction' => 'asc',
            'score_label' => 'Score',
            'period' => 'daily',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/games/tocco');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.leaderboard_types')
            ->assertJsonPath('data.leaderboard_types.0.slug', 'daily-best');
    }

    // -------------------------------------------------------
    // Show non-existent game — 404
    // -------------------------------------------------------

    public function test_show_nonexistent_game_returns_404(): void
    {
        $response = $this->getJson('/api/v1/games/nonexistent');

        $response->assertStatus(404);
    }
}
