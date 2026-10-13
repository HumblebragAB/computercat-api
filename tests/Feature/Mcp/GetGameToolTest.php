<?php

namespace Tests\Feature\Mcp;

use App\Models\AchievementDefinition;
use App\Models\DailyContentPool;
use App\Models\GameEvent;
use App\Models\LeaderboardType;
use App\Models\RemoteConfig;

class GetGameToolTest extends McpTestCase
{
    public function test_returns_configuration_without_settings_or_pool_content(): void
    {
        $game = $this->game('ordjakt', [
            'settings' => ['revenuecat' => ['webhook_secret' => 'hemlig-webhook-nyckel'], 'anthropic' => ['api_key' => 'sk-hemlig-api-nyckel']],
            'allowed_origins' => ['https://ordjakt.apps.humblebrag.se'],
        ]);
        DailyContentPool::create(['game_id' => $game->id, 'pool_key' => 'ord', 'content' => ['hemligt-ord'], 'is_active' => true, 'random_access' => false]);
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'get_game', ['game' => 'ordjakt']);
        $data = json_decode($result['text'], true);

        $this->assertTrue($result['ok']);
        $this->assertSame('ordjakt', $data['slug']);
        $this->assertSame(['https://ordjakt.apps.humblebrag.se'], $data['allowed_origins']);
        $this->assertSame(1, $data['content_pools'][0]['items']);
        $this->assertSame(['pool_key', 'items', 'starts_at', 'random_access', 'is_active'], array_keys($data['content_pools'][0]));
        $this->assertArrayNotHasKey('settings', $data);
        $this->assertStringNotContainsString('hemligt-ord', $result['text']);
        $this->assertStringNotContainsString('hemlig-webhook-nyckel', $result['text']);
        $this->assertStringNotContainsString('sk-hemlig-api-nyckel', $result['text']);
        $this->assertStringEndsWith('/api/v1', $data['base_url']);
    }

    public function test_lists_all_configuration_with_explicit_fields(): void
    {
        $game = $this->game('ordjakt');
        LeaderboardType::create(['game_id' => $game->id, 'slug' => 'daily', 'name' => 'Daily', 'sort_direction' => 'asc', 'score_label' => 'Tid', 'period' => 'daily', 'max_entries_per_period' => 50, 'is_active' => true]);
        AchievementDefinition::create(['game_id' => $game->id, 'slug' => 'first', 'name' => 'Första', 'description' => 'Spela', 'icon' => 'star', 'sort_order' => 1, 'is_secret' => false, 'is_active' => true]);
        RemoteConfig::create(['game_id' => $game->id, 'key' => 'lives', 'value' => '5', 'value_type' => 'int', 'description' => 'Liv', 'is_active' => true]);
        GameEvent::create(['game_id' => $game->id, 'slug' => 'jul', 'name' => 'Jul', 'description' => 'Julevent', 'event_type' => 'seasonal', 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-24 00:00:00', 'settings' => ['bonus' => 2], 'is_active' => true]);
        $user = $this->staffMember($game);

        $data = json_decode($this->callTool($user, 'get_game', ['game' => 'ordjakt'])['text'], true);

        $this->assertSame(['slug', 'name', 'sort_direction', 'score_label', 'period', 'max_entries_per_period', 'is_active'], array_keys($data['leaderboard_types'][0]));
        $this->assertSame(['slug', 'name', 'description', 'icon', 'sort_order', 'is_secret', 'is_active'], array_keys($data['achievements'][0]));
        $this->assertSame(['key', 'value', 'value_type', 'description', 'is_active'], array_keys($data['remote_configs'][0]));
        $this->assertSame(['slug', 'name', 'description', 'event_type', 'starts_at', 'ends_at', 'settings', 'is_active'], array_keys($data['events'][0]));
        $this->assertSame(['bonus' => 2], $data['events'][0]['settings']);
        $this->assertStringStartsWith('2026-12-01T00:00:00', $data['events'][0]['starts_at']);
    }

    public function test_does_not_show_other_games_configuration(): void
    {
        $game = $this->game('ordjakt');
        $other = $this->game('annat');
        RemoteConfig::create(['game_id' => $other->id, 'key' => 'annan-nyckel', 'value' => '1', 'value_type' => 'int', 'is_active' => true]);
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'get_game', ['game' => 'ordjakt']);

        $this->assertStringNotContainsString('annan-nyckel', $result['text']);
    }
}
