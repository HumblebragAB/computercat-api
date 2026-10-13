<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RevokeDevkitTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_first_and_deletes_only_devkit_tokens_after_confirmation(): void
    {
        $user = User::factory()->create();
        $user->createToken('devkit');
        $user->createToken('anonymous');

        $this->artisan('computercat:revoke-devkit-tokens')
            ->expectsOutputToContain('1 devkit-token')
            ->expectsConfirmation('Radera dessa tokens?', 'no')
            ->assertSuccessful();
        $this->assertSame(2, PersonalAccessToken::count());

        $this->artisan('computercat:revoke-devkit-tokens')
            ->expectsConfirmation('Radera dessa tokens?', 'yes')
            ->assertSuccessful();
        $this->assertSame(['anonymous'], PersonalAccessToken::pluck('name')->all());
    }
}
