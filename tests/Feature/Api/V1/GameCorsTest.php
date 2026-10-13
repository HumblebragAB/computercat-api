<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCorsTest extends TestCase
{
    use RefreshDatabase;

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/auth/anonymous', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_active_and_inactive_game_origins_are_allowed_in_preflight(): void
    {
        Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://a.apps.humblebrag.se']]);
        Game::create(['slug' => 'b', 'name' => 'B', 'is_active' => false, 'settings' => [], 'allowed_origins' => ['https://b.apps.humblebrag.se']]);

        $this->preflight('https://a.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://a.apps.humblebrag.se');
        $this->preflight('https://b.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://b.apps.humblebrag.se');
    }

    public function test_unknown_origin_gets_no_allow_header(): void
    {
        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_static_origins_still_work(): void
    {
        $this->preflight('https://tocco.app')->assertHeader('Access-Control-Allow-Origin', 'https://tocco.app');
    }

    public function test_cache_is_cleared_when_game_is_saved(): void
    {
        $game = Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => []]);
        $this->preflight('https://a.apps.humblebrag.se')->assertHeaderMissing('Access-Control-Allow-Origin');

        $game->update(['allowed_origins' => ['https://a.apps.humblebrag.se']]);

        $this->preflight('https://a.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://a.apps.humblebrag.se');
    }

    public function test_origins_are_stored_normalized(): void
    {
        $game = Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://A.apps.humblebrag.se/']]);

        $this->assertSame(['https://a.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }

    public function test_invalid_stored_origins_never_become_cors_wildcards(): void
    {
        Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['*', 'https://*.x.se', 'http://y.se', 'https://ok.apps.humblebrag.se']]);

        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->preflight('https://sub.x.se')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->preflight('https://ok.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://ok.apps.humblebrag.se');
    }
}
