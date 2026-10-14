<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Spelens frontends kör `npm run dev` på http://localhost:3000 och skickar
// bearer-token, aldrig cookies. Räknas localhost som "stateful" lägger
// statefulApi() på session och CSRF, och varje POST från lokal utveckling
// får 419 i stället för ett vanligt svar. Bara API:ts egen värd ska räknas.
class SanctumStatefulDomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Läs om sanctum.php med en känd app.url, så att testet prövar
        // filens standardvärde och inte den lokala .env:ens APP_URL.
        config(['app.url' => 'https://api.computercat.co']);
        config(['sanctum.stateful' => (require config_path('sanctum.php'))['stateful']]);

        // ValidateCsrfToken hoppar över kontrollen i enhetstester. Utan det
        // här skulle 419 aldrig synas och anropstesterna inte bevisa något.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public static function localDevOrigins(): array
    {
        return [
            'next dev' => ['http://localhost:3000'],
            'artisan serve' => ['http://127.0.0.1:8000'],
            'localhost' => ['http://localhost'],
        ];
    }

    #[DataProvider('localDevOrigins')]
    public function test_post_from_local_dev_origin_is_not_csrf_checked(string $origin): void
    {
        $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'])
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized();
    }

    #[DataProvider('localDevOrigins')]
    public function test_local_dev_origin_is_not_stateful(string $origin): void
    {
        $this->assertFalse($this->fromFrontend($origin.'/'));
    }

    public function test_api_own_host_is_still_stateful(): void
    {
        $this->assertTrue($this->fromFrontend('https://api.computercat.co/api/v1/interest/x/unsubscribe'));

        // Stateful betyder CSRF-kontroll: utan token blir det 419. Visar
        // också att kontrollen faktiskt är på i de här testerna.
        $this->withHeaders(['Origin' => 'https://api.computercat.co', 'Referer' => 'https://api.computercat.co/'])
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(419);
    }

    public function test_default_list_is_only_the_api_host(): void
    {
        $this->assertSame(['api.computercat.co'], config('sanctum.stateful'));
    }

    // Utan app.url blir listan tom, inte [''] (som skulle matcha "/*").
    public function test_empty_app_url_gives_empty_list(): void
    {
        config(['app.url' => null]);

        $this->assertSame([], (require config_path('sanctum.php'))['stateful']);
    }

    private function fromFrontend(string $referer): bool
    {
        $request = Request::create('/api/v1/auth/refresh', 'POST');
        $request->headers->set('Referer', $referer);

        return EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }
}
