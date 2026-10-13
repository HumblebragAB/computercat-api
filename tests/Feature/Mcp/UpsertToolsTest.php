<?php

namespace Tests\Feature\Mcp;

use App\Models\LeaderboardType;

class UpsertToolsTest extends McpTestCase
{
    private function lb(array $o = []): array
    {
        return array_merge(['game' => 'ordjakt', 'slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'Poäng', 'period' => 'daily'], $o);
    }

    private function event(array $o = []): array
    {
        return array_merge(['game' => 'ordjakt', 'slug' => 'jul', 'name' => 'Jul', 'event_type' => 'seasonal', 'starts_at' => '2026-12-01T00:00:00+01:00', 'ends_at' => '2026-12-24T23:59:00+01:00'], $o);
    }

    public function test_upsert_creates_then_updates_without_duplicates(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['name' => 'Högsta']))['ok']);

        $this->assertSame(1, $game->leaderboardTypes()->count());
        $this->assertSame('Högsta', $game->leaderboardTypes()->first()->name);
    }

    public function test_upsert_reports_created_and_updated(): void
    {
        $user = $this->staffMember($this->game());

        $first = json_decode($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['text'], true);
        $second = json_decode($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['text'], true);

        $this->assertSame(['saved' => true, 'created' => true, 'slug' => 'high-score'], $first);
        $this->assertSame(['saved' => true, 'created' => false, 'slug' => 'high-score'], $second);
    }

    public function test_upsert_only_touches_the_given_game(): void
    {
        $other = $this->game('glosis');
        $game = $this->game();
        $user = $this->staffMember($game, $other);
        LeaderboardType::create(['game_id' => $other->id, 'slug' => 'high-score', 'name' => 'Annan', 'sort_direction' => 'asc', 'score_label' => 'S', 'period' => 'daily']);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['name' => 'Ordjakt']))['ok']);

        $this->assertSame('Annan', $other->leaderboardTypes()->where('slug', 'high-score')->first()->name);
        $this->assertSame('Ordjakt', $game->leaderboardTypes()->first()->name);
    }

    public function test_upsert_works_on_inactive_member_game(): void
    {
        $user = $this->staffMember($this->game('ordjakt', ['is_active' => false]));

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['ok']);
    }

    public function test_validation_errors_are_reported_and_nothing_is_written(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'upsert_leaderboard_type', $this->lb(['period' => 'hourly', 'max_entries_per_period' => 0]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('period', $result['text']);
        $this->assertStringContainsString('max_entries_per_period', $result['text']);
        $this->assertSame(0, $game->leaderboardTypes()->count());
    }

    public function test_legacy_slug_can_be_updated_but_not_created(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        LeaderboardType::create(['game_id' => $game->id, 'slug' => 'Daily_Time', 'name' => 'D', 'sort_direction' => 'asc', 'score_label' => 'Tid', 'period' => 'daily']);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['slug' => 'Daily_Time', 'name' => 'Ny']))['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['slug' => 'Other_Time']))['ok']);
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['game_id' => 999999, 'id' => 999999]))['ok']);

        $this->assertSame($game->id, LeaderboardType::where('slug', 'high-score')->first()->game_id);
        $this->assertNotSame(999999, LeaderboardType::where('slug', 'high-score')->first()->id);
    }

    public function test_achievement_upsert(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_achievement', ['game' => 'ordjakt', 'slug' => 'first-win', 'name' => 'Först', 'description' => 'Vinn en gång', 'icon' => '🏆', 'is_secret' => true])['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_achievement', ['game' => 'ordjakt', 'slug' => 'second', 'name' => 'Två'])['ok']);

        $this->assertSame(1, $game->achievementDefinitions()->count());
        $this->assertTrue($game->achievementDefinitions()->first()->is_secret);
    }

    public function test_content_pool_rejects_string_content_and_accepts_list(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $bad = $this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => '["a","b"]']);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('must be an array', $bad['text']);
        $this->assertSame(0, $game->dailyContentPools()->count());

        $this->assertTrue($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a', 'b'], 'random_access' => true])['ok']);
        $this->assertSame(['a', 'b'], $game->dailyContentPools()->first()->content);
    }

    public function test_content_pool_byte_cap(): void
    {
        $user = $this->staffMember($this->game());
        $big = array_fill(0, 100, str_repeat('x', 6000)); // ~600 KB

        $this->assertFalse($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => $big])['ok']);
    }

    /** Datum sparas som datum; en tid med offset räknas om till UTC först. */
    public function test_content_pool_start_date(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a'], 'starts_at' => '2026-12-01'])['ok']);
        $this->assertSame('2026-12-01', $game->dailyContentPools()->first()->starts_at->toDateString());

        $this->assertTrue($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a'], 'starts_at' => '2026-12-01T00:30:00+01:00'])['ok']);
        $this->assertSame('2026-11-30', $game->dailyContentPools()->first()->starts_at->toDateString());

        $this->assertTrue($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a'], 'starts_at' => null])['ok']);
        $this->assertNull($game->dailyContentPools()->first()->starts_at);

        $this->assertFalse($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a'], 'starts_at' => 'snart'])['ok']);
    }

    public function test_remote_config_value_checked_against_type(): void
    {
        $user = $this->staffMember($this->game());

        $this->assertFalse($this->callTool($user, 'upsert_remote_config', ['game' => 'ordjakt', 'key' => 'k', 'value' => '{bad', 'value_type' => 'json'])['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_remote_config', ['game' => 'ordjakt', 'key' => 'k', 'value' => '{"a":1}', 'value_type' => 'json'])['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_remote_config', ['game' => 'ordjakt', 'key' => 'k', 'value' => 'abc', 'value_type' => 'int'])['ok']);
    }

    public function test_event_settings_replace_and_dates_are_checked(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $base = $this->event();

        $this->assertTrue($this->callTool($user, 'upsert_game_event', $base + ['settings' => ['a' => 1, 'b' => 2]])['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_game_event', $base + ['settings' => ['c' => 3]])['ok']);
        $this->assertSame(['c' => 3], $game->gameEvents()->first()->settings);

        $this->assertFalse($this->callTool($user, 'upsert_game_event', array_merge($base, ['ends_at' => '2026-11-01T00:00:00+01:00']))['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_game_event', $base + ['settings' => [1, 2, 3]])['ok']);
    }

    /** Utelämnad settings lämnar dem orörda, null tömmer, skalärer avvisas. */
    public function test_event_settings_absent_null_and_scalars(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_game_event', $this->event(['settings' => ['a' => 1]]))['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_game_event', $this->event(['name' => 'Jul 2']))['ok']);
        $this->assertSame(['a' => 1], $game->gameEvents()->first()->settings);

        foreach (['', 'text', 5, true] as $bad) {
            $result = $this->callTool($user, 'upsert_game_event', $this->event(['settings' => $bad]));
            $this->assertFalse($result['ok'], 'settings '.var_export($bad, true).' släpptes igenom');
            $this->assertStringContainsString('settings', $result['text']);
        }
        $this->assertSame(['a' => 1], $game->gameEvents()->first()->fresh()->settings);

        $this->assertTrue($this->callTool($user, 'upsert_game_event', $this->event(['settings' => null]))['ok']);
        $this->assertNull($game->gameEvents()->first()->settings);
    }

    public function test_event_times_with_offset_are_stored_correctly(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->callTool($user, 'upsert_game_event', $this->event(['starts_at' => '2026-12-01T00:00:00+01:00', 'ends_at' => '2026-12-02T00:00:00+01:00']));

        $this->assertSame('2026-11-30 23:00:00', $game->gameEvents()->first()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:00:00', $game->gameEvents()->first()->getRawOriginal('starts_at'));
        $this->assertSame('2026-12-01 23:00:00', $game->gameEvents()->first()->getRawOriginal('ends_at'));
    }

    /** Lyckade svar från varje upsert innehåller inget ur spelets settings. */
    public function test_successful_upserts_do_not_leak_game_settings(): void
    {
        $secret = 'SECRET-'.bin2hex(random_bytes(8));
        $user = $this->staffMember($this->game('ordjakt', ['settings' => ['revenuecat' => ['webhook_secret' => $secret]]]));

        $calls = [
            'upsert_leaderboard_type' => $this->lb(),
            'upsert_achievement' => ['game' => 'ordjakt', 'slug' => 'a', 'name' => 'A', 'description' => 'D', 'icon' => '⭐'],
            'upsert_content_pool' => ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a']],
            'upsert_remote_config' => ['game' => 'ordjakt', 'key' => 'k', 'value' => '1', 'value_type' => 'int'],
            'upsert_game_event' => $this->event(),
        ];

        foreach ($calls as $tool => $args) {
            $result = $this->callTool($user, $tool, $args);
            $this->assertTrue($result['ok'], "{$tool}: {$result['text']}");
            $this->assertStringNotContainsString($secret, $result['text'], $tool);
            $this->assertStringNotContainsString('revenuecat', $result['text'], $tool);
        }
    }
}
