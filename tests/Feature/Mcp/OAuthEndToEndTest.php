<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hela OAuth-kedjan som claude.ai går igenom, utan Passport::actingAs:
 * dynamisk registrering, godkännande som inloggad staff, kodbyte med PKCE och
 * sedan /mcp med den riktiga bearer-tokenen genom Passports TokenGuard.
 *
 * Nycklarna genereras i minnet per test och sätts via config, så att inga
 * nyckelfiler skrivs eller checkas in.
 */
class OAuthEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config([
            'passport.private_key' => $private,
            'passport.public_key' => openssl_pkey_get_details($key)['key'],
        ]);
    }

    public function test_real_token_from_the_oauth_flow_reaches_mcp_and_is_cut_off(): void
    {
        $user = User::factory()->staff()->create();

        // 1. Dynamisk klientregistrering, som claude.ai gör den.
        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ])->assertCreated()->json('client_id');
        $this->assertIsString($clientId);

        // 2. Authorize med PKCE S256 och state, inloggad som staff i panelen.
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->actingAs($user, 'web');
        $page = $this->get(route('passport.authorizations.authorize', [
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'mcp:use', 'state' => 'state-123',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]))->assertOk();
        $this->assertSame(1, preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m));

        // 3. Godkänn med auth_token från sidan. Sessionen lever kvar mellan
        //    anropen i testet, så authRequest som authorize sparade finns kvar.
        $approve = $this->post(route('passport.authorizations.approve'), [
            'state' => '', 'client_id' => $clientId, 'auth_token' => $m[1],
        ])->assertRedirect();
        $location = $approve->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('state-123', $query['state'] ?? null);
        $this->assertNotEmpty($query['code'] ?? null);

        // 4. Byt koden mot tokens med verifieraren. Ingen session på /oauth/token.
        $this->logoutWeb();
        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier, 'code' => $query['code'],
        ])->assertOk()->json();
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertEqualsWithDelta(3600, $tokens['expires_in'], 5);
        $this->assertNotEmpty($tokens['refresh_token'] ?? null);
        $bearer = $tokens['access_token'];

        // 5. /mcp med den riktiga tokenen.
        $list = $this->mcp($bearer, 'tools/list')->assertOk();
        $this->assertContains('list_games', collect($list->json('result.tools'))->pluck('name')->all());

        // 6. Utan staff: samma token nekas (EnsureStaff, 403).
        $user->forceFill(['is_staff' => false])->save();
        $this->mcp($bearer, 'tools/list')->assertForbidden();

        // 7. Staff igen men tokenen återkallad: TokenGuard släpper den inte (401).
        $user->forceFill(['is_staff' => true])->save();
        $this->mcp($bearer, 'tools/list')->assertOk();
        DB::table('oauth_access_tokens')->where('user_id', $user->id)->update(['revoked' => true]);
        $this->mcp($bearer, 'tools/list')->assertUnauthorized();
    }

    /** En kod får bara bytas en gång, och fel verifierare avvisas. */
    public function test_code_requires_matching_verifier_and_works_once(): void
    {
        $user = User::factory()->staff()->create();
        $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->json('client_id');
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($user, 'web');
        $page = $this->get(route('passport.authorizations.authorize', [
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]))->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $m);
        $location = $this->post(route('passport.authorizations.approve'), ['state' => '', 'client_id' => $clientId, 'auth_token' => $m[1]])
            ->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->logoutWeb();

        $exchange = fn (string $v) => $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $v, 'code' => $query['code'],
        ]);

        $exchange(Str::random(64))->assertStatus(400);
        $exchange($verifier)->assertOk();
        $exchange($verifier)->assertStatus(400);
    }

    /**
     * Varje anrop ska autentiseras på nytt, som i en ny PHP-process. Guards
     * cachar användaren mellan anrop i samma test, så de glöms före varje anrop.
     */
    private function mcp(string $bearer, string $method): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method]);
    }

    private function logoutWeb(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
    }
}
