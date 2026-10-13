<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\ApiTokenCookieFactory;
use Laravel\Passport\Passport;
use Tests\TestCase;

// Passports routes registreras i routes/passport.php. Bara de som OAuth-flödet
// för MCP behöver finns kvar.
class PassportRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_code_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('passport.device'));
        $this->assertFalse(Route::has('passport.device.code'));
        $this->assertFalse(Route::has('passport.device.authorizations.authorize'));
        $this->assertFalse(Passport::$deviceCodeGrantEnabled);

        $this->actingAs(User::factory()->staff()->create());
        $this->get('/oauth/device')->assertNotFound();
        $this->post('/oauth/device/code')->assertNotFound();
        $this->get('/oauth/device/authorize')->assertNotFound();
    }

    public function test_transient_token_refresh_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('passport.token.refresh'));

        $this->actingAs(User::factory()->staff()->create());
        $this->post('/oauth/token/refresh')->assertNotFound();
    }

    public function test_oauth_flow_routes_are_registered(): void
    {
        foreach (['passport.token', 'passport.authorizations.authorize', 'passport.authorizations.approve', 'passport.authorizations.deny'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_transient_cookie_token_cannot_reach_mcp(): void
    {
        // En laravel_token-cookie ger en TransientToken som klarar alla scopes.
        // Den får aldrig ersätta en OAuth-token, inte ens för staff.
        $user = User::factory()->staff()->create();
        $cookie = app(ApiTokenCookieFactory::class)->make($user->id, 'csrf-test');

        // withCredentials: postJson skickar annars inga cookies.
        $response = $this->withCredentials()->withCookie(Passport::cookie(), $cookie->getValue())
            ->withHeader('X-CSRF-TOKEN', 'csrf-test')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'ping', 'arguments' => (object) []]]);

        $this->assertContains($response->status(), [401, 403]);
        $this->assertNull($response->json('result'));
    }
}
