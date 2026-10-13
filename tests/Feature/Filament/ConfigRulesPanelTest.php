<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\GameEventsRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\LeaderboardTypesRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\RemoteConfigsRelationManager;
use App\Models\Game;
use App\Models\LeaderboardType;
use App\Models\User;
use App\Support\ConfigRules\SlugRule;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigRulesPanelTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'g', 'name' => 'G', 'is_active' => true, 'settings' => []]);
        $this->actingAs(User::factory()->staff()->create());
    }

    private function rm(string $class)
    {
        return Livewire::test($class, ['ownerRecord' => $this->game, 'pageClass' => EditGame::class]);
    }

    private function leaderboard(array $overrides = []): array
    {
        return array_merge(['slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'Score', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true], $overrides);
    }

    public function test_duplicate_slug_is_a_validation_error_not_a_500(): void
    {
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard())->assertHasNoTableActionErrors();
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard())->assertHasTableActionErrors(['slug']);
    }

    public function test_bad_slug_format_and_max_entries_are_rejected(): void
    {
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard(['slug' => 'Bad Slug']))->assertHasTableActionErrors(['slug']);
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard(['max_entries_per_period' => 0]))->assertHasTableActionErrors(['max_entries_per_period']);
    }

    public function test_existing_legacy_slug_can_still_be_edited(): void
    {
        $legacy = LeaderboardType::create(['game_id' => $this->game->id] + $this->leaderboard(['slug' => 'Daily_Time']));

        $this->rm(LeaderboardTypesRelationManager::class)
            ->callTableAction(EditAction::class, $legacy, data: $this->leaderboard(['slug' => 'Daily_Time', 'name' => 'Ny']))
            ->assertHasNoTableActionErrors();

        $this->assertSame('Ny', $legacy->fresh()->name);
    }

    public function test_remote_config_value_must_match_type(): void
    {
        foreach ([['json', '{bad'], ['int', 'abc'], ['bool', 'kanske']] as [$type, $value]) {
            $this->rm(RemoteConfigsRelationManager::class)->callTableAction(CreateAction::class, data: [
                'key' => 'k', 'value' => $value, 'value_type' => $type, 'is_active' => true,
            ])->assertHasTableActionErrors(['value']);
        }

        foreach (['99999999999999999999', "5\n", ' 5'] as $value) {
            $this->rm(RemoteConfigsRelationManager::class)->callTableAction(CreateAction::class, data: [
                'key' => 'k', 'value' => $value, 'value_type' => 'int', 'is_active' => true,
            ])->assertHasTableActionErrors(['value']);
        }

        $this->rm(RemoteConfigsRelationManager::class)->callTableAction(CreateAction::class, data: [
            'key' => 'n', 'value' => '-12', 'value_type' => 'int', 'is_active' => true,
        ])->assertHasNoTableActionErrors();
        $this->assertSame(-12, $this->game->remoteConfigs()->where('key', 'n')->first()->typedValue());

        $this->rm(RemoteConfigsRelationManager::class)->callTableAction(CreateAction::class, data: [
            'key' => 'k', 'value' => '{"a": 1}', 'value_type' => 'json', 'is_active' => true,
        ])->assertHasNoTableActionErrors();
        $this->assertSame(['a' => 1], $this->game->remoteConfigs()->where('key', 'k')->first()->typedValue());
    }

    public function test_event_must_end_after_it_starts(): void
    {
        $this->rm(GameEventsRelationManager::class)->callTableAction(CreateAction::class, data: [
            'slug' => 'e', 'name' => 'E', 'event_type' => 'challenge',
            'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-01 10:00:00', 'is_active' => true,
        ])->assertHasTableActionErrors(['ends_at']);
    }

    public function test_changing_slug_to_an_existing_one_is_rejected_but_keeping_it_is_not(): void
    {
        LeaderboardType::create(['game_id' => $this->game->id] + $this->leaderboard(['slug' => 'taken']));
        $mine = LeaderboardType::create(['game_id' => $this->game->id] + $this->leaderboard(['slug' => 'mine']));

        $this->rm(LeaderboardTypesRelationManager::class)
            ->callTableAction(EditAction::class, $mine, data: $this->leaderboard(['slug' => 'taken']))
            ->assertHasTableActionErrors(['slug']);

        $this->rm(LeaderboardTypesRelationManager::class)
            ->callTableAction(EditAction::class, $mine, data: $this->leaderboard(['slug' => 'mine', 'name' => 'Ny']))
            ->assertHasNoTableActionErrors();

        $this->assertSame('Ny', $mine->fresh()->name);
    }

    public function test_slug_with_trailing_newline_is_rejected(): void
    {
        $this->assertFalse((bool) preg_match(SlugRule::PATTERN, "ok\n"));
        $this->assertTrue((bool) preg_match(SlugRule::PATTERN, 'ok-1.a_b'));
    }
}
