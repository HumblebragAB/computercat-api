<?php

namespace Tests\Feature\Mcp;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\GameEventsRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\LeaderboardTypesRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\RemoteConfigsRelationManager;
use Filament\Tables\Actions\CreateAction;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

// Samma ogiltiga indata ska ge fel både i panelen och via MCP.
class PanelMcpParityTest extends McpTestCase
{
    public static function invalidLeaderboards(): array
    {
        return [
            'slug med mellanslag' => [['slug' => 'Bad Slug']],
            'noll poster' => [['max_entries_per_period' => 0]],
            'okänd period' => [['period' => 'hourly']],
            'okänd sorteringsriktning' => [['sort_direction' => 'up']],
        ];
    }

    #[DataProvider('invalidLeaderboards')]
    public function test_panel_and_mcp_reject_the_same_input(array $override): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $data = array_merge(['slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'S', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true], $override);

        $this->assertFalse($this->callTool($user, 'upsert_leaderboard_type', ['game' => 'ordjakt'] + $data)['ok']);

        $this->actingAs($user);
        Livewire::test(LeaderboardTypesRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: $data)
            ->assertHasTableActionErrors();

        $this->assertSame(0, $game->leaderboardTypes()->count());
    }

    /** Kontroll av testet själv: giltig indata går igenom på båda vägarna. */
    public function test_valid_input_passes_both(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $data = ['slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'S', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true];

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', ['game' => 'ordjakt'] + $data)['ok']);

        $this->actingAs($user);
        Livewire::test(LeaderboardTypesRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: ['slug' => 'other'] + $data)
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $game->leaderboardTypes()->count());
    }

    /** Panelens Select validerar inte mot sina options; de delade reglerna gör det. */
    public function test_panel_rejects_unknown_event_type_and_value_type(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $this->actingAs($user);

        Livewire::test(GameEventsRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: ['slug' => 'jul', 'name' => 'Jul', 'event_type' => 'bogus', 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-02 00:00:00'])
            ->assertHasTableActionErrors(['event_type']);

        Livewire::test(RemoteConfigsRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: ['key' => 'k', 'value' => 'x', 'value_type' => 'bogus'])
            ->assertHasTableActionErrors(['value_type']);

        $this->assertSame(0, $game->gameEvents()->count());
        $this->assertSame(0, $game->remoteConfigs()->count());
    }
}
