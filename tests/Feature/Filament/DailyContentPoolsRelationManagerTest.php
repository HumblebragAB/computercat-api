<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\DailyContentPoolsRelationManager;
use App\Models\Game;
use App\Models\User;
use Filament\Tables\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DailyContentPoolsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::create(['slug' => 'tells', 'name' => 'Tells', 'is_active' => true, 'settings' => []]);
        $this->actingAs(User::factory()->create());
    }

    private function create(string $content)
    {
        return Livewire::test(DailyContentPoolsRelationManager::class, [
            'ownerRecord' => $this->game,
            'pageClass' => EditGame::class,
        ])->callTableAction(CreateAction::class, data: [
            'pool_key' => 'questions-sv',
            'content' => $content,
            'is_active' => true,
            'random_access' => true,
        ]);
    }

    public function test_valid_json_list_is_saved_with_random_access(): void
    {
        $this->create('[{"id": "sv-1", "question": "Fråga"}]')->assertHasNoTableActionErrors();

        $pool = $this->game->dailyContentPools()->first();
        $this->assertTrue($pool->random_access);
        $this->assertSame([['id' => 'sv-1', 'question' => 'Fråga']], $pool->content);
    }

    public function test_invalid_json_object_and_oversized_lists_are_rejected(): void
    {
        foreach (['not json', '{"a": 1}', json_encode(range(1, 5001))] as $content) {
            $this->create($content)->assertHasTableActionErrors(['content']);
        }

        $this->assertSame(0, $this->game->dailyContentPools()->count());
    }
}
