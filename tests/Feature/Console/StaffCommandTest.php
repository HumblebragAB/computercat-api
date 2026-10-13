<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffCommandTest extends TestCase
{
    use RefreshDatabase;

    // users.is_anonymous är true som default i databasen, och factoryn sätter den inte.

    public function test_grant_shows_account_and_requires_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'mattias@humblebrag.se', 'is_anonymous' => false]);

        $this->artisan('computercat:staff', ['email' => 'mattias@humblebrag.se', '--grant' => true])
            ->expectsOutputToContain("id {$user->id}")
            ->expectsConfirmation('Ge staff-behörighet till detta konto?', 'no')
            ->assertFailed();
        $this->assertFalse($user->fresh()->is_staff);

        $this->artisan('computercat:staff', ['email' => 'mattias@humblebrag.se', '--grant' => true])
            ->expectsConfirmation('Ge staff-behörighet till detta konto?', 'yes')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->is_staff);
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_admin_flag_and_revoke(): void
    {
        $user = User::factory()->create(['email' => 'erik@humblebrag.se', 'is_anonymous' => false]);

        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se', '--grant' => true, '--admin' => true])
            ->expectsConfirmation('Ge staff-behörighet till detta konto?', 'yes')->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_admin);

        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se', '--revoke' => true])
            ->expectsConfirmation('Ta bort staff-behörighet från detta konto?', 'yes')->assertSuccessful();
        $this->assertFalse($user->fresh()->is_staff);
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    /** Tokens får inte vakna igen om kontot senare får staff tillbaka. */
    public function test_revoke_also_revokes_oauth_tokens(): void
    {
        $user = User::factory()->staff()->create(['email' => 'erik@humblebrag.se']);
        $other = User::factory()->staff()->create();
        $mine = $this->oauthToken($user);
        $theirs = $this->oauthToken($other);

        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se', '--revoke' => true])
            ->expectsConfirmation('Ta bort staff-behörighet från detta konto?', 'yes')->assertSuccessful();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $mine)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('access_token_id', $mine)->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->where('user_id', $user->id)->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', $theirs)->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_refresh_tokens')->where('access_token_id', $theirs)->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_auth_codes')->where('user_id', $other->id)->value('revoked'));
    }

    /** Ett nekat --revoke rör inga tokens. */
    public function test_declined_revoke_keeps_oauth_tokens(): void
    {
        $user = User::factory()->staff()->create(['email' => 'erik@humblebrag.se']);
        $token = $this->oauthToken($user);

        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se', '--revoke' => true])
            ->expectsConfirmation('Ta bort staff-behörighet från detta konto?', 'no')->assertFailed();

        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', $token)->value('revoked'));
    }

    private function oauthToken(User $user): string
    {
        $client = (string) Str::uuid();
        $id = Str::random(80);
        DB::table('oauth_access_tokens')->insert(['id' => $id, 'user_id' => $user->id, 'client_id' => $client, 'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);
        DB::table('oauth_refresh_tokens')->insert(['id' => Str::random(80), 'access_token_id' => $id, 'revoked' => false, 'expires_at' => now()->addDays(30)]);
        DB::table('oauth_auth_codes')->insert(['id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $client, 'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => now()->addMinutes(10)]);

        return $id;
    }

    public function test_unknown_or_anonymous_account_is_refused(): void
    {
        User::factory()->create(['email' => 'anon@x.se', 'is_anonymous' => true]);

        $this->artisan('computercat:staff', ['email' => 'nobody@x.se', '--grant' => true])->assertFailed();
        $this->artisan('computercat:staff', ['email' => 'anon@x.se', '--grant' => true])->assertFailed();
    }

    public function test_exactly_one_of_grant_and_revoke(): void
    {
        $user = User::factory()->create(['email' => 'erik@humblebrag.se', 'is_anonymous' => false]);

        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se'])->assertFailed();
        $this->artisan('computercat:staff', ['email' => 'erik@humblebrag.se', '--grant' => true, '--revoke' => true])->assertFailed();
        $this->assertFalse($user->fresh()->is_staff);
    }
}
