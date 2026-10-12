<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlosisRemoteConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_gratisflaggan_syns_i_config(): void
    {
        $this->getJson('/api/v1/games/glosis/config')->assertOk()->assertJsonPath('data.glosis_free_enabled', false);
        $game = Game::where('slug', 'glosis')->firstOrFail();
        $game->update(['settings' => array_merge($game->settings ?? [], ['free' => ['enabled' => true]])]);
        $this->getJson('/api/v1/games/glosis/config')->assertOk()->assertJsonPath('data.glosis_free_enabled', true);
    }

    public function test_andra_spel_far_inte_flaggan(): void
    {
        $other = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true, 'settings' => ['free' => ['enabled' => true]]]);
        $this->getJson("/api/v1/games/{$other->slug}/config")->assertOk()->assertJsonMissingPath('data.glosis_free_enabled');
    }
}
