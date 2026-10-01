<?php

namespace Tests\Feature\Glosis;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\User;
use Database\Seeders\GlosisGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class GlosisSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_keeps_settings(): void
    {
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update([
            'is_active' => false,
            'settings' => ['site_url' => 'https://glosis.se', 'anthropic' => ['api_key' => Crypt::encryptString('nyckel')]],
        ]);

        $this->seed(GlosisGameSeeder::class);
        $this->seed(GlosisGameSeeder::class);

        $this->assertSame(1, Game::where('slug', 'glosis')->count());
        $game->refresh();
        $this->assertSame('Glosis', $game->name);
        $this->assertTrue($game->is_active);
        $this->assertSame('nyckel', Crypt::decryptString($game->settings['anthropic']['api_key']));
    }

    public function test_seeder_creates_the_game_when_missing(): void
    {
        Game::where('slug', 'glosis')->delete();

        $this->seed(GlosisGameSeeder::class);

        $this->assertTrue(Game::where('slug', 'glosis')->where('is_active', true)->where('name', 'Glosis')->exists());
    }

    public function test_filament_stores_anthropic_key_encrypted_and_never_shows_it(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'erik@humblebrag.se']));
        $game = Game::where('slug', 'glosis')->firstOrFail();

        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.anthropic.api_key' => null])
            ->fillForm(['settings.anthropic.api_key' => '  sk-ant-hemlig  '])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $game->refresh()->settings['anthropic']['api_key'];
        $this->assertNotSame('sk-ant-hemlig', $stored);
        $this->assertSame('sk-ant-hemlig', Crypt::decryptString($stored));

        // Laddas om: fältet är tomt, och sparas tomt behålls nyckeln.
        Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet(['settings.anthropic.api_key' => null])
            ->assertDontSee('sk-ant-hemlig')
            ->assertDontSee($stored)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-ant-hemlig', Crypt::decryptString($game->refresh()->settings['anthropic']['api_key']));
        // Nycklar utan fält i formuläret får inte försvinna när nyckeln sparas.
        $this->assertSame('https://glosis.se', $game->settings['site_url']);
    }
}
