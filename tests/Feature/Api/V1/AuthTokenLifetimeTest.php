<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// Tokenlivslängd (90 dagar). Testerna använder riktiga bearer-token i
// stället för Sanctum::actingAs, eftersom det är guardens utgångskontroll
// (created_at mot sanctum.expiration och tokenets expires_at) som prövas.
class AuthTokenLifetimeTest extends TestCase
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

    private function anonymousToken(): string
    {
        return $this->postJson('/api/v1/auth/anonymous')->assertCreated()->json('token');
    }

    // -------------------------------------------------------
    // Livslängd
    // -------------------------------------------------------

    public function test_lifetime_is_90_days(): void
    {
        $this->assertSame(129600, config('sanctum.expiration'));
    }

    public function test_issued_tokens_get_explicit_expires_at(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');

        $this->postJson('/api/v1/auth/anonymous')->assertCreated();
        $anon = PersonalAccessToken::latest('id')->first();
        $this->assertTrue($anon->expires_at->equalTo(now()->addMinutes(129600)));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', [
            'email' => 'a@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $this->bearer($this->anonymousToken()))->assertCreated();
        $registered = PersonalAccessToken::latest('id')->first();
        $this->assertSame('auth', $registered->name);
        $this->assertTrue($registered->expires_at->equalTo(now()->addMinutes(129600)));

        User::factory()->create(['email' => 'b@example.com', 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'password'])->assertOk();
        $login = PersonalAccessToken::latest('id')->first();
        $this->assertTrue($login->expires_at->equalTo(now()->addMinutes(129600)));
    }

    public function test_token_older_than_7_days_still_authenticates(): void
    {
        $token = $this->anonymousToken();

        $this->travel(8)->days();

        $this->getMe($token)->assertOk();
    }

    public function test_token_older_than_90_days_is_rejected(): void
    {
        $token = $this->anonymousToken();

        $this->travel(90)->days();
        $this->travel(1)->minutes();

        $this->getMe($token)->assertUnauthorized();
    }

    // Livslängden är hårdkodad: en tom eller felaktig miljövariabel får
    // aldrig kunna ge token som aldrig går ut (0 betyder det i Sanctum).
    public function test_lifetime_ignores_environment(): void
    {
        foreach (['0', '', 'abc', '-5'] as $value) {
            putenv("SANCTUM_EXPIRATION_MINUTES={$value}");
            $_ENV['SANCTUM_EXPIRATION_MINUTES'] = $_SERVER['SANCTUM_EXPIRATION_MINUTES'] = $value;

            $this->assertSame(129600, (require config_path('sanctum.php'))['expiration'], "värde: '{$value}'");
        }

        putenv('SANCTUM_EXPIRATION_MINUTES');
        unset($_ENV['SANCTUM_EXPIRATION_MINUTES'], $_SERVER['SANCTUM_EXPIRATION_MINUTES']);
    }

    public function test_expired_tokens_are_pruned_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'sanctum:prune-expired --hours=24'));

        $this->assertCount(1, $events);
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $events->first()->expression);
    }
}
