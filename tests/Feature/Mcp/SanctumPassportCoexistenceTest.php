<?php

namespace Tests\Feature\Mcp;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumPassportCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_still_uses_sanctum_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('anonymous');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertInstanceOf(\Laravel\Sanctum\PersonalAccessToken::class, $user->tokens()->first());
    }

    public function test_delete_me_still_removes_sanctum_tokens(): void
    {
        // DELETE /auth/me svarar 200 för alla inloggade konton (se AuthTest).
        $user = User::factory()->create(['is_anonymous' => true]);
        $token = $user->createToken('anonymous')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/me')->assertOk();

        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count());
    }

    public function test_staff_user_uses_passport_tokens_on_the_same_row(): void
    {
        $user = User::factory()->create();
        $user->createToken('anonymous');

        $staff = StaffUser::find($user->id);

        $this->assertNotNull($staff);
        $this->assertSame($user->id, $staff->id);
        $this->assertSame($user->email, $staff->email);
        $this->assertSame('users', $staff->getTable());
        $this->assertSame(User::class, $staff->getMorphClass());
        $this->assertInstanceOf(\Laravel\Passport\Contracts\OAuthenticatable::class, $staff);
        // tokens() ska peka på Passports tabell, inte Sanctums.
        $this->assertInstanceOf(\Laravel\Passport\Token::class, $staff->tokens()->getRelated());

        // User är orörd: Sanctums tokens på samma rad.
        $this->assertInstanceOf(\Laravel\Sanctum\PersonalAccessToken::class, $user->fresh()->tokens()->first());
    }

    public function test_mcp_guard_resolves_staff_user(): void
    {
        $this->assertSame('passport', config('auth.guards.mcp.driver'));
        $this->assertSame(StaffUser::class, config('auth.providers.staff.model'));
    }

    /** /mcp ska svara 401 med WWW-Authenticate, aldrig redirecta till login och aldrig kräva CSRF. */
    public function test_unauthenticated_mcp_call_gets_401_with_challenge(): void
    {
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertStatus(401);
        $this->assertNotEmpty($response->headers->get('WWW-Authenticate'));
    }

    /**
     * Klienter som inte skickar Accept: application/json (claude.ai skickar
     * text/event-stream) får inte heller redirectas till login.
     *
     * @dataProvider acceptHeaders
     */
    public function test_unauthenticated_mcp_call_without_json_accept_gets_401(?string $accept): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($accept !== null) {
            $headers['Accept'] = $accept;
        }

        $response = $this->call('POST', '/mcp', [], [], [], $this->transformHeadersToServerVars($headers),
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        $response->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $response->headers->get('WWW-Authenticate'));
    }

    public static function acceptHeaders(): array
    {
        return [
            'ingen Accept' => [null],
            'bara event-stream' => ['text/event-stream'],
            'html' => ['text/html'],
        ];
    }
}
