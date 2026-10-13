<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// Förnyelse av token via POST /api/v1/auth/refresh. Testerna använder riktiga bearer-token i
// stället för Sanctum::actingAs, eftersom det är guardens utgångskontroll
// (created_at mot sanctum.expiration och tokenets expires_at) som prövas.
class AuthTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // Guarden minns användaren mellan anrop i samma test. Glöm den så att
    // varje anrop slår upp sin token på nytt, som i produktion.
    private function getMe(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/auth/me', $this->bearer($token));
    }

    private function refresh(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/refresh', [], $this->bearer($token));
    }

    private function anonymousToken(): string
    {
        return $this->postJson('/api/v1/auth/anonymous')->assertCreated()->json('token');
    }

    // -------------------------------------------------------
    // Förnyelse
    // -------------------------------------------------------

    public function test_refresh_returns_new_working_token_user_and_expires_at(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');
        $old = $this->anonymousToken();
        $oldModel = PersonalAccessToken::findToken($old);
        $oldModel->forceFill(['abilities' => ['spel:läsa']])->save();

        $this->travel(30)->days();

        $response = $this->refresh($old);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'display_name', 'is_anonymous'], 'expires_at']);

        $new = $response->json('token');
        $this->assertNotSame($old, $new);
        $this->assertSame($this->getMe($old)->json('user'), $response->json('user'));

        $newModel = PersonalAccessToken::findToken($new);
        $this->assertSame($oldModel->tokenable_id, $newModel->tokenable_id);
        $this->assertSame('anonymous', $newModel->name);
        $this->assertSame(['spel:läsa'], $newModel->abilities);
        $this->assertTrue($newModel->expires_at->equalTo(now()->addMinutes(129600)));
        $this->assertSame($newModel->expires_at->toIso8601String(), $response->json('expires_at'));

        // Det nya tokenet bär hela livslängden: 89 dagar senare fungerar det.
        $this->travel(89)->days();
        $this->getMe($new)->assertOk();
    }

    public function test_old_token_works_for_10_minutes_after_refresh_then_fails(): void
    {
        $old = $this->anonymousToken();
        $new = $this->refresh($old)->assertOk()->json('token');

        $this->travel(9)->minutes();
        $this->getMe($old)->assertOk();

        $this->travel(2)->minutes();
        $this->getMe($old)->assertUnauthorized();
        $this->getMe($new)->assertOk();
    }

    public function test_refresh_does_not_extend_an_old_token_that_expires_sooner(): void
    {
        $old = $this->anonymousToken();
        $model = PersonalAccessToken::findToken($old);
        $soon = now()->addMinutes(3)->startOfSecond();
        $model->forceFill(['expires_at' => $soon])->save();

        $this->refresh($old)->assertOk();

        $this->assertTrue($model->fresh()->expires_at->equalTo($soon));
    }

    public function test_grace_limited_token_can_refresh_once_more(): void
    {
        $old = $this->anonymousToken();
        $first = $this->refresh($old)->assertOk()->json('token');
        $second = $this->refresh($old)->assertOk()->json('token');

        $this->assertNotSame($first, $second);
        $this->getMe($second)->assertOk();
    }

    public function test_refresh_without_token_returns_401(): void
    {
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_refresh_with_expired_token_returns_401(): void
    {
        $token = $this->anonymousToken();
        $this->travel(91)->days();

        $this->refresh($token)->assertUnauthorized();
    }

    // Bannade stoppas redan i guarden (se AuthBanTest), så refresh ger 401
    // och inget nytt token skapas.
    public function test_banned_user_cannot_refresh(): void
    {
        $token = $this->anonymousToken();
        PersonalAccessToken::findToken($token)->tokenable->forceFill(['is_banned' => true])->saveQuietly();
        $before = PersonalAccessToken::count();

        $this->refresh($token)->assertUnauthorized();

        $this->assertSame($before, PersonalAccessToken::count());
    }

    public function test_session_login_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $before = PersonalAccessToken::count();

        // Sanctum släpper in webbsessionen med ett TransientToken.
        $this->actingAs($user, 'web')->postJson('/api/v1/auth/refresh')->assertUnauthorized();

        $this->assertSame($before, PersonalAccessToken::count());
    }

    // Token utfärdade före den här ändringen saknar expires_at.
    public function test_legacy_token_without_expires_at_is_clamped_on_refresh(): void
    {
        $user = User::factory()->create();
        $legacy = $user->createToken('anonymous')->plainTextToken;
        $this->assertNull(PersonalAccessToken::findToken($legacy)->expires_at);

        Carbon::setTestNow(now()->addDays(30)->startOfSecond());
        $this->refresh($legacy)->assertOk();

        $this->assertTrue(PersonalAccessToken::findToken($legacy)->expires_at->equalTo(now()->addMinutes(10)));
    }

    public function test_legacy_token_without_expires_at_expires_after_90_days(): void
    {
        $user = User::factory()->create();
        $legacy = $user->createToken('anonymous')->plainTextToken;

        $this->travel(89)->days();
        $this->getMe($legacy)->assertOk();

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->getMe($legacy)->assertUnauthorized();
    }

    // Egen gräns per användare, så att anonyma konton som skapas från samma
    // IP (en skolklass bakom en NAT) inte stoppar förnyelsen.
    public function test_refresh_is_throttled_per_user(): void
    {
        $token = $this->anonymousToken();

        for ($i = 0; $i < 30; $i++) {
            $this->refresh($token)->assertOk();
        }

        $this->refresh($token)->assertStatus(429);

        // En annan användare från samma IP påverkas inte.
        $other = User::factory()->create()->createToken('anonymous')->plainTextToken;
        $this->refresh($other)->assertOk();
    }

    public function test_exhausted_auth_limit_does_not_block_refresh(): void
    {
        $token = $this->anonymousToken();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/anonymous');
        }
        $this->postJson('/api/v1/auth/anonymous')->assertStatus(429);

        $this->refresh($token)->assertOk();
    }
}
