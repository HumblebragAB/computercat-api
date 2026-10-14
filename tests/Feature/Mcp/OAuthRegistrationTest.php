<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_with_claude_redirect_succeeds(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()->assertJsonPath('scope', 'mcp:use');
    }

    /** Claude Code (CLI och VS Code) tar emot callbacken på en loopback-port (RFC 8252). */
    public function test_registration_with_loopback_redirect_succeeds(): void
    {
        foreach (['http://localhost:54321/callback', 'http://127.0.0.1:54321/callback', 'http://[::1]:54321/callback'] as $uri) {
            $this->postJson('/oauth/register', [
                'client_name' => 'Claude Code',
                'redirect_uris' => [$uri],
            ])->assertCreated();
        }
    }

    public function test_registration_with_foreign_redirect_domain_is_rejected(): void
    {
        foreach ([
            'https://evil.example/callback',
            'https://claude.ai.evil.example/callback',
            'http://localhost.evil.example/callback',
            'http://localhost@evil.example/callback',
            'http://user@localhost:54321/callback',
            'http://evil.example/localhost/callback',
        ] as $uri) {
            $this->postJson('/oauth/register', [
                'client_name' => 'Evil',
                'redirect_uris' => [$uri],
            ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    /** DCR är oautentiserad: högst 10 registreringar per minut och IP. */
    public function test_registration_is_rate_limited_per_ip(): void
    {
        $payload = ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/oauth/register', $payload)->assertCreated();
        }

        $this->postJson('/oauth/register', $payload)->assertStatus(429);
    }
}
