<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class PruneOAuthClientsTest extends TestCase
{
    use RefreshDatabase;

    /** Registrerar en klient via riktiga DCR-endpointen och backdaterar den. */
    private function dcrClient(int $hoursOld): string
    {
        $id = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()->json('client_id');

        $this->backdate($id, $hoursOld);

        return $id;
    }

    private function backdate(string $id, int $hoursOld): void
    {
        DB::table('oauth_clients')->where('id', $id)->update(['created_at' => now()->subHours($hoursOld)]);
    }

    /** @return array<string, string> */
    private function mix(): array
    {
        $repo = app(ClientRepository::class);
        $user = User::factory()->create();

        $withToken = $this->dcrClient(48);
        DB::table('oauth_access_tokens')->insert([
            'id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $withToken,
            'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $withCode = $this->dcrClient(48);
        DB::table('oauth_auth_codes')->insert([
            'id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $withCode,
            'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => now()->addMinutes(10),
        ]);

        $confidential = $repo->createAuthorizationCodeGrantClient('Intern', ['https://computercat.se/cb'])->getKey();
        $personal = $repo->createPersonalAccessGrantClient('Personal')->getKey();
        $password = $repo->createPasswordGrantClient('Password', 'users')->getKey();
        // Publik authorization_code-klient med ägare: ser ut som DCR men har owner satt.
        $owned = $repo->createAuthorizationCodeGrantClient('Ägd', ['https://claude.ai/api/mcp/auth_callback'], false)->getKey();
        DB::table('oauth_clients')->where('id', $owned)->update(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        foreach ([$confidential, $personal, $password, $owned] as $id) {
            $this->backdate($id, 24 * 30);
        }

        return [
            'old_unused' => $this->dcrClient(25),
            'recent_unused' => $this->dcrClient(23),
            'with_token' => $withToken,
            'with_code' => $withCode,
            'confidential' => $confidential,
            'personal' => $personal,
            'password' => $password,
            'owned' => $owned,
        ];
    }

    public function test_deletes_only_old_unused_dcr_clients(): void
    {
        $clients = $this->mix();

        $this->artisan('computercat:prune-oauth-clients')
            ->expectsOutputToContain('1 oanvänd(a) DCR-klient(er)')
            ->expectsOutputToContain('Raderade 1.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('oauth_clients', ['id' => $clients['old_unused']]);
        unset($clients['old_unused']);
        foreach ($clients as $label => $id) {
            $this->assertTrue(Client::whereKey($id)->exists(), "{$label} ska vara kvar");
        }
        $this->assertSame(count($clients), Client::count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $clients = $this->mix();

        $this->artisan('computercat:prune-oauth-clients --dry-run')
            ->expectsOutputToContain('1 oanvänd(a) DCR-klient(er)')
            ->doesntExpectOutputToContain('Raderade')
            ->assertSuccessful();

        $this->assertSame(count($clients), Client::count());
    }

    public function test_command_is_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'computercat:prune-oauth-clients'));

        $this->assertCount(1, $events);
        // En gång per natt: fast minut och timme, varje dag.
        $this->assertMatchesRegularExpression('/^\d+ [0-5] \* \* \*$/', $events->first()->expression);
    }
}
