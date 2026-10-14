<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource\Pages\CreateGame;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GameSlugPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->staff()->create());
    }

    public function test_new_game_must_follow_slug_rule(): void
    {
        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'Bad_Slug', 'name' => 'X'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'api', 'name' => 'X'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'kattsvep', 'name' => 'Kattsvep'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_existing_game_with_legacy_slug_can_always_be_edited(): void
    {
        $legacy = Game::create(['slug' => 'Gammal_Slug', 'name' => 'Gammal', 'is_active' => true, 'settings' => []]);

        Livewire::test(EditGame::class, ['record' => $legacy->getRouteKey()])
            ->fillForm(['name' => 'Nytt namn'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nytt namn', $legacy->fresh()->name);
    }
}
