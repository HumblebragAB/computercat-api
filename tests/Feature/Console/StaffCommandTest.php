<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
