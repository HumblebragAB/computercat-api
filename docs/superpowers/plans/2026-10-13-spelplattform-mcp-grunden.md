# Spelplattformen delprojekt 1 (grunden): implementationsplan

> **För agenter som genomför planen:** OBLIGATORISK SUB-SKILL: använd superpowers:subagent-driven-development (rekommenderas) eller superpowers:executing-plans för att genomföra planen task för task. Stegen har checkbox-syntax (`- [ ]`) för att följa upp.

**Mål:** Mattias Claude (molnsession eller chatt) ska kunna läsa och konfigurera hans spel i Computercat via en MCP-connector, utan personliga tokens och utan att nå andra spel.

**Arkitektur:** `laravel/mcp` exponerar en MCP-server på `/mcp`, skyddad av en Passport-guard (`mcp`) mot en separat modell `StaffUser`, medan Sanctum fortsätter skydda `/api/v1`. Åtkomst kräver `users.is_staff` (satt av Erik med artisan) och medlemskap per spel (`game_user`), eller `is_platform_admin`. Valideringsregler för byggstenarna flyttas till regelklasser som både Filament och MCP-verktygen använder. CORS får origins per spel via en global middleware före `HandleCors`.

**Teknik:** Laravel 12.53, PHP 8.2+, Filament 3, Sanctum 4, Passport 13, laravel/mcp 1.x, PHPUnit 11 (projektet använder PHPUnit-klasser, inte Pest-syntax), SQLite i minnet för test.

**Spec:** `docs/superpowers/specs/2026-10-13-spelplattform-mcp-design.md` (delprojekt 1)

## Globala villkor

- Arbeta i worktreen `/Users/erik/Web/computercat-api/.claude/worktrees/docs-spelplattform-mcp` med absoluta sökvägar. Huvudkatalogen rörs aldrig.
- Inga prod-åtgärder i planen. Driftsättning är ett separat steg efter completion-gate och kräver Eriks uttryckliga OK.
- `users.is_staff` och `users.is_platform_admin` får aldrig vara `$fillable` och sätts aldrig via API:t.
- MCP-verktyg returnerar aldrig `games.settings` (utom det integrationsmanualen redan skriver ut: anti-cheat), aldrig tokens.
- Inga raderingsverktyg i MCP. Inga verktyg för produkter, köp, `games.settings`, spelardata eller Glosis.
- Slug/nyckel-format (avviker medvetet från specens första utkast, specen uppdateras): `^[a-z0-9][a-z0-9_.-]*\z` med befintliga maxlängder (slug 50 för topplistor och achievements, 100 för pool_key, remote config key och event slug). Formatregeln gäller bara när värdet är nytt eller ändras, så befintliga poster med avvikande slug fortfarande går att redigera.
- Content pool: högst 5000 poster och högst 512 KB serialiserat (`strlen(json_encode($content))`).
- Event `settings`: JSON-objekt, högst 16 KB serialiserat, ersätter tidigare värde.
- `max_entries_per_period`: heltal 1–10 000.
- Origins per spel: `https://` + host (+ port), ingen path/query/fragment/userinfo/jokertecken; normaliseras till gemener utan avslutande `/`.
- MCP rate limit: namngiven limiter `mcp`, 120/min per användar-id.
- Access token 1 h, refresh token 30 dagar.
- `config('mcp.redirect_domains')` låses till Claudes domän(er), aldrig `*`.
- Kommentarer och felmeddelanden mot Erik på svenska; API- och verktygsfelmeddelanden på engelska (som resten av API:t).
- Commit-meddelanden på svenska, inga Co-Authored-By-rader.

## Granskningsfokus

Fem fall som specen innebär men som lätt missas; varje rad har ett test i den task som äger koden.

1. **Befintlig post med slug som bryter mot det nya formatet** (t.ex. `Daily_Time`) ska fortfarande gå att redigera i panelen och via upsert så länge sluggen inte ändras. Test i Task 4.
2. **Claude skickar content som en JSON-sträng i stället för en array** till `upsert_content_pool`. Förväntat: tydligt valideringsfel "content must be an array", ingenting sparas. Test i Task 8.
3. **Origin med versaler eller avslutande snedstreck** (`https://Ordjakt.apps.humblebrag.se/`) ska sparas normaliserad och sedan matcha webbläsarens `Origin`-header. Test i Task 3.
4. **Samma upsert två gånger** ska uppdatera, aldrig ge dubblett eller 500. Test i Task 8.
5. **Spelnamn eller achievement-namn med HTML** (`<img src=x onerror=…>`) skrivet via MCP ska inte köras i Dev Kit-sidan, som idag renderar markdown utan escaping. Test i Task 10.

---

## Filstruktur

**Nya filer**

| Fil | Ansvar |
|---|---|
| `app/Models/StaffUser.php` | Passport-modell för guarden `mcp` (samma tabell som `User`) |
| `app/Models/McpAuditLog.php` | Revisionslogg |
| `app/Http/Middleware/EnsureStaff.php` | Nekar inloggade som inte är `is_staff && !is_banned`; släpper gäster vidare på authorize-routes |
| `app/Http/Middleware/MergeGameCorsOrigins.php` | Slår ihop spelens origins med statisk CORS-config |
| `app/Support/GameCorsOrigins.php` | Cachad union av origins + normalisering |
| `app/Rules/GameOrigin.php` | Valideringsregel för en origin |
| `app/Support/ConfigRules/*.php` | En regelklass per byggsten |
| `app/Services/IntegrationGuide.php` | Manualen och setup-guiden, utan token |
| `app/Mcp/ComputercatServer.php` | MCP-servern och verktygslistan |
| `app/Mcp/GameAccess.php` | Slug → spel om användaren får nå det |
| `app/Mcp/AuditLogger.php` | Sammanfattar argument och skriver logg |
| `app/Mcp/Tools/GameTool.php` | Abstrakt bas: åtkomst, validering, transaktion, logg |
| `app/Mcp/Tools/*.php` | Ett verktyg per fil |
| `app/Console/Commands/StaffCommand.php` | `computercat:staff` |
| `app/Console/Commands/RevokeDevkitTokens.php` | `computercat:revoke-devkit-tokens` |
| `app/Filament/Resources/GameResource/RelationManagers/MembersRelationManager.php` | Medlemmar per spel |
| `app/Filament/Resources/McpAuditLogResource.php` (+ Pages) | Skrivskyddad logg |
| `routes/ai.php` | MCP-route |
| `database/migrations/2026_10_14_*` | Fyra egna migrationer (Task 2, 3, 5, 6) plus Passports |

**Ändrade filer**

`app/Models/User.php`, `database/factories/UserFactory.php`, `app/Models/Game.php`, `bootstrap/app.php`, `config/auth.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, de fem relation managers, `app/Filament/Resources/GameResource.php`, `app/Filament/Resources/GameResource/Pages/GameDevKit.php`, `resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php`.

---

### Task 1: Spike — paketen, Sanctum-samexistens och en riktig claude.ai-connector

Denna task avgör om resten av planen håller. Den committar riktig kod (paket, guard, StaffUser) men MCP-servern här är en minimal `ping`. Om något steg nedan faller: **stanna och fråga Erik med AskUserQuestion** innan en annan väg väljs.

**Filer:**
- Ändra: `composer.json`, `composer.lock`, `config/auth.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`
- Skapa: `app/Models/StaffUser.php`, `routes/ai.php`, `app/Mcp/ComputercatServer.php`, `app/Mcp/Tools/PingTool.php`, `config/mcp.php` (publicerad), Passport-migrationer
- Test: `tests/Feature/Mcp/SanctumPassportCoexistenceTest.php`

**Gränssnitt:**
- Producerar: guard `mcp` (driver `passport`, provider `staff`); `App\Models\StaffUser`; `App\Mcp\ComputercatServer` med `protected array $tools`; route `/mcp`; route-namn `login`.

- [ ] **Steg 1: Installera paketen**

```bash
cd /Users/erik/Web/computercat-api/.claude/worktrees/docs-spelplattform-mcp
composer install
composer require laravel/passport:^13 laravel/mcp:^1
php artisan passport:install --no-interaction   # migrationer + nycklar i storage/
php artisan vendor:publish --tag=ai-routes
php artisan vendor:publish --tag=mcp-config
git check-ignore storage/oauth-private.key storage/oauth-public.key   # båda ska listas
```

Förväntat: båda nyckelfilerna är ignorerade av git. Är de inte det: lägg till dem i `.gitignore` innan något committas.

- [ ] **Steg 2: Läs paketens faktiska API**

Läs i `vendor/laravel/mcp/src/`: `Server.php`, `Server/Tool.php`, `Request.php`, `Response.php`, `Facades/Mcp.php`, `Server/Registrar.php` och den publicerade `config/mcp.php`. Läs i `vendor/laravel/passport/src/`: `Passport.php` (authorizationView, tokensExpireIn, refreshTokensExpireIn, routes/middleware-konfiguration), `HasApiTokens.php`, `Contracts/OAuthenticatable.php`.

Kontrollera särskilt:
- att Sanctums och Passports `HasApiTokens` inte deklarerar egenskapen `$accessToken` med olika typ (det ger ett fatalt fel om egenskapskompatibilitet när `StaffUser` ärver `User`, inte ett metodfel),
- vilken middleware Passport 13 har för scopes (`CheckToken::using(...)` eller äldre `CheckScopes`, alias `scopes`/`scope`),
- hur Passport låter dig lägga middleware på sina authorize-routes (config-nyckel, eller `Passport::ignoreRoutes()` + egen registrering av `vendor/laravel/passport/routes/web.php`),
- vilken provider som laddar `routes/ai.php` (den finns inte i `bootstrap/app.php`).

Skriv ner i commit-meddelandet för steg 9 exakt: metodnamn för verktygets schema/handle, hur ett felsvar skapas, hur `$request->user()` nås i ett verktyg, om paketet levererar en authorize-vy, och hur middleware läggs på Passports authorize-route. Kodexemplen i senare tasks använder namnen nedan; **avviker paketet, använd paketets namn och notera det**:

- `Laravel\Mcp\Server` med `protected array $tools = [...]`
- `Laravel\Mcp\Server\Tool` med `schema(JsonSchema $schema): array` och `handle(Request $request): Response`
- `Laravel\Mcp\Response::text(string)` och `Response::error(string)`
- `Laravel\Mcp\Facades\Mcp::web(string $path, string $server)` och `Mcp::oauthRoutes()`

- [ ] **Steg 3: Skriv det fallerande samexistenstestet**

```php
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
        $user = User::factory()->create(['is_anonymous' => true]);
        $token = $user->createToken('anonymous')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/me')->assertSuccessful();

        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count());
    }

    public function test_staff_user_uses_passport_tokens_on_the_same_row(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::findOrFail($user->id);

        $this->assertSame('users', $staff->getTable());
        $this->assertTrue(method_exists($staff, 'createToken'));
        $this->assertInstanceOf(\Laravel\Passport\Contracts\OAuthenticatable::class, $staff);
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
}
```

Kontrollera i `AuthTest.php` vilken status `DELETE /auth/me` svarar med och vilka villkor som gäller (t.ex. om ett anonymt konto får radera sig); justera `assertSuccessful` och fabriksdatan så att testet speglar dagens beteende.

- [ ] **Steg 4: Kör och se det falla**

Kör: `php artisan test --filter=SanctumPassportCoexistenceTest`
Förväntat: FAIL, `StaffUser` finns inte.

- [ ] **Steg 5: Skapa StaffUser och guarden**

`app/Models/StaffUser.php`:

```php
<?php

namespace App\Models;

use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

// Samma rad i users som User, men med Passports tokens. User behåller
// Sanctums HasApiTokens; båda traits definierar tokens()/createToken(), så de
// kan inte leva i samma klass. Används bara av guarden mcp.
class StaffUser extends User implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    public function getMorphClass(): string
    {
        return User::class;
    }
}
```

Om PHP klagar på trait-kollision med den ärvda Sanctum-traiten: lös med `use HasApiTokens { ... insteadof ... }` är inte möjligt mellan förälder och barn; barnets trait-metoder vinner automatiskt över ärvda. Klagar den ändå, stanna och fråga.

`config/auth.php`, lägg till:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'mcp' => ['driver' => 'passport', 'provider' => 'staff'],
],

'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => env('AUTH_MODEL', App\Models\User::class)],
    'staff' => ['driver' => 'eloquent', 'model' => App\Models\StaffUser::class],
],
```

`AppServiceProvider::boot()`:

```php
\Laravel\Passport\Passport::tokensExpireIn(now()->addHour());
\Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(30));
```

- [ ] **Steg 6: Login-route och minimal MCP-server**

`routes/web.php`, lägg till:

```php
// Passports authorize-sida skickar oinloggade hit. Filament äger inloggningen.
// Route::redirect och inte redirect()->guest(): guest() skriver över
// url.intended (authorize-adressen) med /login, och då kommer man aldrig tillbaka.
Route::redirect('/login', '/admin/login')->name('login');
```

Kontrollera att Filaments inloggning skickar tillbaka till `url()->intended()` efter lyckad inloggning; om inte, lägg en `LoginResponse`-bindning i `AppServiceProvider` som gör `redirect()->intended(Filament::getUrl())`. Rundturen testas automatiskt i Task 2.

`app/Mcp/Tools/PingTool.php` (tas bort i Task 7):

```php
<?php

namespace App\Mcp\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class PingTool extends Tool
{
    protected string $description = 'Returns pong and the authenticated user id.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text('pong '.$request->user()?->id);
    }
}
```

`app/Mcp/ComputercatServer.php`:

```php
<?php

namespace App\Mcp;

use Laravel\Mcp\Server;

class ComputercatServer extends Server
{
    protected string $name = 'Computercat';

    protected string $version = '1.0.0';

    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Only games you are a member of are visible.';

    protected array $tools = [
        Tools\PingTool::class,
    ];
}
```

`routes/ai.php`:

```php
<?php

use App\Mcp\ComputercatServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', ComputercatServer::class)
    ->middleware(['auth:mcp', 'scopes:mcp:use']);   // scope-aliaset enligt steg 2
```

Verifiera med `php artisan route:list --path=mcp -v` att `/mcp` inte ligger i `web`-gruppen (ingen session, ingen CSRF).

`config/mcp.php`: sätt `redirect_domains` till `['https://claude.ai']` tills steg 8 visat exakt värde.

- [ ] **Steg 7: Kör samexistenstestet och hela sviten**

Kör: `php artisan test --filter=SanctumPassportCoexistenceTest` → PASS
Kör: `php artisan test` → hela sviten grön. Röda tester i `Api/V1` betyder att Passport har tagit över något från Sanctum: stanna och felsök innan nästa steg.

- [ ] **Steg 8: Verifiera mot en riktig claude.ai-connector**

claude.ai måste nå servern över publik HTTPS. Det kräver antingen en tillfällig tunnel från Herd (`herd share`) eller en Demo-instans. Båda exponerar en dev-miljö publikt. **Fråga Erik med AskUserQuestion** vilken väg (och att connectorn läggs till av honom i claude.ai), innan något exponeras.

Kontrollera i flödet: DCR lyckas, inloggning via `/admin/login` och tillbaka till authorize fungerar, redirect-URI:n som Claude skickar (skriv ner den och lås `redirect_domains` till exakt den domänen), att `ping` svarar med rätt användar-id. Stäng tunneln direkt efteråt och ta bort testconnectorn.

Om Passport/laravel/mcp saknar authorize-vy: skapa `resources/views/mcp/authorize.blade.php` (klientnamn, scope, godkänn/neka-formulär enligt Passports dokumenterade fält) och registrera den med `Passport::authorizationView('mcp.authorize')`.

- [ ] **Steg 9: Commit**

```bash
git add -A
git commit -m "MCP-spike: Passport för guarden mcp via StaffUser, Sanctum orörd, ping-verktyg

Verifierat mot claude.ai: <redirect-URI>, <authorize-vy från paket/egen>,
<API-namn som avviker från planen>"
```

---

### Task 2: Staff-flaggor, EnsureStaff och artisan-kommandot

**Filer:**
- Skapa: `database/migrations/2026_10_14_100001_add_staff_flags_to_users_table.php`, `app/Http/Middleware/EnsureStaff.php`, `app/Console/Commands/StaffCommand.php`
- Ändra: `app/Models/User.php:17-23` (canAccessPanel) och casts, `routes/ai.php`, Passports authorize-routes
- Test: `tests/Feature/Mcp/StaffAccessTest.php`, `tests/Feature/Console/StaffCommandTest.php`

**Gränssnitt:**
- Konsumerar: `StaffUser`, guard `mcp` (Task 1)
- Producerar: `User::isStaff(): bool` (= `is_staff && ! is_banned`), `User::isPlatformAdmin(): bool` (= `isStaff() && is_platform_admin`), middleware `EnsureStaff`

- [ ] **Steg 1: Fallerande tester**

`tests/Feature/Mcp/StaffAccessTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\StaffUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->forceFill(['is_staff' => true] + $attrs)->save();

        return $user;
    }

    private function mcpPing(User $user)
    {
        Passport::actingAs(StaffUser::find($user->id), ['mcp:use'], 'mcp');

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'ping', 'arguments' => []],
        ]);
    }

    public function test_staff_flags_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['is_staff' => true, 'is_platform_admin' => true]);

        $this->assertFalse($user->fresh()->is_staff);
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_panel_access_follows_flag_not_email(): void
    {
        $panel = Filament::getDefaultPanel();
        $player = User::factory()->create(['email' => 'mattias@humblebrag.se']);

        $this->assertFalse($player->canAccessPanel($panel));
        $this->assertTrue($this->staff()->canAccessPanel($panel));
        $this->assertFalse($this->staff(['is_banned' => true])->canAccessPanel($panel));
    }

    public function test_non_staff_and_banned_are_denied_on_mcp(): void
    {
        $this->mcpPing(User::factory()->create())->assertForbidden();
        $this->mcpPing($this->staff(['is_banned' => true]))->assertForbidden();
        $this->mcpPing($this->staff())->assertOk();
    }

    public function test_revoking_staff_denies_next_call(): void
    {
        $user = $this->staff();
        $this->mcpPing($user)->assertOk();

        $user->forceFill(['is_staff' => false])->save();

        $this->mcpPing($user)->assertForbidden();
    }

    private function authorizeUrl(): string
    {
        $client = app(\Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'Claude', redirectUris: ['https://claude.ai/api/mcp/auth_callback'], confidential: false,
        );

        return route('passport.authorizations.authorize', [
            'client_id' => $client->getKey(), 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 's',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function test_non_staff_cannot_reach_or_approve_authorize(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->authorizeUrl())->assertForbidden();
        $this->post(route('passport.authorizations.approve'))->assertForbidden();
    }

    public function test_guest_is_sent_to_login_and_back_to_authorize(): void
    {
        $url = $this->authorizeUrl();

        $this->get($url)->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/admin/login');
        $this->assertSame($url, session('url.intended'));

        // Efter inloggning via Filament ska intended leda tillbaka till authorize.
        $this->actingAs(User::factory()->staff()->create());
        $this->get(redirect()->intended('/admin')->getTargetUrl())->assertOk();
    }
}
```

Anpassa JSON-RPC-anropet till hur laravel/mcp tar emot det (Task 1 steg 2); om paketet har testhjälpare (t.ex. `ComputercatServer::actingAs(...)->tool(...)`), använd dem, men behåll HTTP-vägen i minst `test_non_staff_and_banned_are_denied_on_mcp` så att middleware-kedjan provas.

`tests/Feature/Console/StaffCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_shows_account_and_requires_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'mattias@humblebrag.se']);

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
        $user = User::factory()->create(['email' => 'erik@humblebrag.se']);

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
}
```

- [ ] **Steg 2: Kör, se dem falla**

Kör: `php artisan test --filter='StaffAccessTest|StaffCommandTest'` → FAIL.

- [ ] **Steg 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sätts bara av computercat:staff. Migrationen ger ingen behörighet:
        // e-postadresser är overifierade och kan registreras av spelare.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('is_banned');
            $table->boolean('is_platform_admin')->default(false)->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_staff', 'is_platform_admin']);
        });
    }
};
```

- [ ] **Steg 4: User**

Ersätt `canAccessPanel` och lägg till hjälpmetoder och casts (inte `$fillable`):

```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->isStaff();
}

public function isStaff(): bool
{
    return (bool) $this->is_staff && ! $this->is_banned;
}

public function isPlatformAdmin(): bool
{
    return $this->isStaff() && (bool) $this->is_platform_admin;
}
```

I `casts()`: `'is_staff' => 'boolean', 'is_platform_admin' => 'boolean'`.

`database/factories/UserFactory.php`, lägg till (factories skapar oskyddat, så flaggan sätts trots att den inte är fillable):

```php
public function staff(bool $admin = false): static
{
    return $this->state(fn () => ['is_staff' => true, 'is_platform_admin' => $admin]);
}
```

Använd `User::factory()->staff()->create()` i alla tester från och med nu i stället för `forceFill`.

- [ ] **Steg 5: EnsureStaff och kopplingen**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Grind för MCP och OAuth-godkännandet. Kontrolleras vid varje anrop, så att
// en utfärdad token slutar fungera när flaggan tas bort eller kontot bannas.
class EnsureStaff
{
    // Med parametern "guests" släpps oinloggade vidare (authorize-routes, där
    // Passport själv skickar gäster till login). Utan den nekas även gäster.
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user && $mode === 'guests') {
            return $next($request);
        }

        if (! $user || ! $user->isStaff()) {
            abort(403);
        }

        return $next($request);
    }
}
```

`routes/ai.php`: `->middleware(['auth:mcp', 'scopes:mcp:use', \App\Http\Middleware\EnsureStaff::class])`.

Passports authorize-routes (`passport.authorizations.authorize`, `.approve`, `.deny`) får `EnsureStaff:guests`. Använd Passports egen konfigurationsväg om Task 1 steg 2 hittade en. Annars: `Passport::ignoreRoutes()` i `AppServiceProvider::register()` och registrera Passports routes själv i en ny `routes/passport.php` (kopia av `vendor/laravel/passport/routes/web.php` med samma namn, prefix `oauth` och middleware, plus `EnsureStaff:guests` på de tre authorize-routes), laddad från `bootstrap/app.php` via `withRouting(then: ...)`. Ändra inte routes vid runtime i `booted()`; det tappas med `route:cache`.

Verifiera: `php artisan route:cache && php artisan route:list --name=passport.authorizations -v && php artisan route:clear`. Middleware ska synas också med cachade routes.

- [ ] **Steg 6: StaffCommand**

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class StaffCommand extends Command
{
    protected $signature = 'computercat:staff {email} {--grant} {--revoke} {--admin : Ge även plattformsadmin (alla spel)}';

    protected $description = 'Ger eller tar bort staff-behörighet (panel + MCP). Visar kontot och kräver bekräftelse.';

    public function handle(): int
    {
        if ($this->option('grant') === $this->option('revoke')) {
            $this->error('Ange exakt en av --grant och --revoke.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user || $user->is_anonymous) {
            $this->error('Inget icke-anonymt konto med den adressen.');

            return self::FAILURE;
        }

        $this->line("Konto: id {$user->id}, {$user->email}, skapat {$user->created_at}, senast sedd {$user->last_seen_at}, bannat: ".($user->is_banned ? 'ja' : 'nej'));

        if ($this->option('grant')) {
            if (! $this->confirm('Ge staff-behörighet till detta konto?')) {
                return self::FAILURE;
            }
            // Utan --admin behålls en befintlig admin-flagga; den tas bara bort med --revoke.
            $user->forceFill(['is_staff' => true, 'is_platform_admin' => $this->option('admin') || $user->is_platform_admin])->save();
        } else {
            if (! $this->confirm('Ta bort staff-behörighet från detta konto?')) {
                return self::FAILURE;
            }
            $user->forceFill(['is_staff' => false, 'is_platform_admin' => false])->save();
        }

        $this->info('Klart.');

        return self::SUCCESS;
    }
}
```

- [ ] **Steg 7: Kör testerna och hela sviten**

Kör: `php artisan test --filter='StaffAccessTest|StaffCommandTest'` → PASS
Kör: `php artisan test` → grön. Dessa befintliga tester loggar in med en e-postadress och faller nu på panelåtkomst; byt till `User::factory()->staff()->create([...])`:
- `tests/Feature/Glosis/GlosisSetupTest.php` rad 48, 76, 113, 154, 165, 180
- `tests/Feature/Glosis/GameVoicesTest.php` rad 42
- `tests/Feature/Glosis/AiUsageReportTest.php` rad 94
- `tests/Feature/Filament/DailyContentPoolsRelationManagerTest.php` rad 25

Sök efter fler: `grep -rn "actingAs" tests/ | grep -v staff`.

- [ ] **Steg 8: Commit**

```bash
git add -A
git commit -m "Staff-flaggor i stället för e-postlista för panel och MCP, computercat:staff"
```

---

### Task 3: Origins per spel och dynamisk CORS

**Filer:**
- Skapa: `database/migrations/2026_10_14_100002_add_allowed_origins_to_games_table.php`, `app/Rules/GameOrigin.php`, `app/Support/GameCorsOrigins.php`, `app/Http/Middleware/MergeGameCorsOrigins.php`
- Ändra: `app/Models/Game.php` (fillable, casts, saved/deleted-hook), `bootstrap/app.php`, `app/Filament/Resources/GameResource.php` (fält under Basic Info)
- Test: `tests/Feature/Api/V1/GameCorsTest.php`, `tests/Unit/GameOriginTest.php`

**Gränssnitt:**
- Producerar: `GameCorsOrigins::normalize(string $origin): string`, `GameCorsOrigins::all(): array<string>`, `GameCorsOrigins::forget(): void`, regel `new GameOrigin`

- [ ] **Steg 1: Fallerande tester**

`tests/Unit/GameOriginTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Rules\GameOrigin;
use App\Support\GameCorsOrigins;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class GameOriginTest extends TestCase
{
    private function passes(string $value): bool
    {
        return Validator::make(['o' => $value], ['o' => [new GameOrigin]])->passes();
    }

    public function test_accepts_https_origins(): void
    {
        foreach (['https://ordjakt.apps.humblebrag.se', 'https://ordjakt.nu', 'https://x.se:8443', 'https://Ordjakt.apps.humblebrag.se/'] as $ok) {
            $this->assertTrue($this->passes($ok), $ok);
        }
    }

    public function test_rejects_everything_else(): void
    {
        foreach ([
            'http://ordjakt.nu', 'https://*.ordjakt.nu', 'https://ordjakt.nu/spel', 'https://ordjakt.nu?a=1',
            'https://u:p@ordjakt.nu', 'ordjakt.nu', 'javascript:alert(1)', 'https://', '*', 'https://ordjakt.nu#x',
        ] as $bad) {
            $this->assertFalse($this->passes($bad), $bad);
        }
    }

    public function test_normalize_lowercases_and_strips_trailing_slash(): void
    {
        $this->assertSame('https://ordjakt.apps.humblebrag.se', GameCorsOrigins::normalize('https://Ordjakt.apps.humblebrag.se/'));
        $this->assertSame('https://ordjakt.nu', GameCorsOrigins::normalize('https://ordjakt.nu:443'));
        $this->assertFalse($this->passes("https://ordjakt.nu\n"));
    }
}
```

`tests/Feature/Api/V1/GameCorsTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCorsTest extends TestCase
{
    use RefreshDatabase;

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/auth/anonymous', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_active_and_inactive_game_origins_are_allowed_in_preflight(): void
    {
        Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://a.apps.humblebrag.se']]);
        Game::create(['slug' => 'b', 'name' => 'B', 'is_active' => false, 'settings' => [], 'allowed_origins' => ['https://b.apps.humblebrag.se']]);

        $this->preflight('https://a.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://a.apps.humblebrag.se');
        $this->preflight('https://b.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://b.apps.humblebrag.se');
    }

    public function test_unknown_origin_gets_no_allow_header(): void
    {
        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_static_origins_still_work(): void
    {
        $this->preflight('https://tocco.app')->assertHeader('Access-Control-Allow-Origin', 'https://tocco.app');
    }

    public function test_cache_is_cleared_when_game_is_saved(): void
    {
        $game = Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => []]);
        $this->preflight('https://a.apps.humblebrag.se')->assertHeaderMissing('Access-Control-Allow-Origin');

        $game->update(['allowed_origins' => ['https://a.apps.humblebrag.se']]);

        $this->preflight('https://a.apps.humblebrag.se')->assertHeader('Access-Control-Allow-Origin', 'https://a.apps.humblebrag.se');
    }

    public function test_origins_are_stored_normalized(): void
    {
        $game = Game::create(['slug' => 'a', 'name' => 'A', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://A.apps.humblebrag.se/']]);

        $this->assertSame(['https://a.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }
}
```

- [ ] **Steg 2: Kör, se dem falla**

Kör: `php artisan test --filter='GameOriginTest|GameCorsTest'` → FAIL.

- [ ] **Steg 3: Migration**

```php
Schema::table('games', function (Blueprint $table) {
    $table->json('allowed_origins')->nullable()->after('settings');
});
```

(med `down()` som droppar kolumnen.)

- [ ] **Steg 4: GameOrigin och GameCorsOrigins**

`app/Rules/GameOrigin.php`:

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// En origin per spel: https, host och ev. port. Inga jokertecken; de statiska
// posterna i config/cors.php berörs inte av den här regeln.
class GameOrigin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url(rtrim($value, '/')) : false;

        $ok = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/i', $parts['host'])
            && array_intersect_key($parts, array_flip(['path', 'query', 'fragment', 'user', 'pass'])) === [];

        if (! $ok) {
            $fail('Each origin must look like https://host or https://host:port, without path or wildcards.');
        }
    }
}
```

`app/Support/GameCorsOrigins.php`:

```php
<?php

namespace App\Support;

use App\Models\Game;
use Illuminate\Support\Facades\Cache;

class GameCorsOrigins
{
    private const CACHE_KEY = 'cors:game-origins';

    // Webbläsarens Origin-header har gemener, ingen avslutande / och ingen
    // standardport, så det sparade värdet måste ha samma form för att matcha.
    public static function normalize(string $origin): string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));

        return str_ends_with($origin, ':443') ? substr($origin, 0, -4) : $origin;
    }

    /** @return list<string> Union av alla spels origins, aktiva som inaktiva. */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Game::query()
            ->whereNotNull('allowed_origins')
            ->pluck('allowed_origins')
            ->flatten()
            ->filter(fn ($o) => is_string($o) && $o !== '')
            ->unique()
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

- [ ] **Steg 5: Game**

Lägg `'allowed_origins'` i `$fillable`, `'allowed_origins' => 'array'` i casts, och:

```php
protected static function booted(): void
{
    static::saving(function (Game $game) {
        if (is_array($game->allowed_origins)) {
            $game->allowed_origins = array_values(array_unique(array_map(
                [\App\Support\GameCorsOrigins::class, 'normalize'], $game->allowed_origins
            )));
        }
    });

    static::saved(fn () => \App\Support\GameCorsOrigins::forget());
    static::deleted(fn () => \App\Support\GameCorsOrigins::forget());
}
```

`$hidden` får inte innehålla `allowed_origins`, men kontrollera att `GameResource`/`GameController` inte börjar skicka ut fältet publikt; lägg till det i `$hidden` om `GET /games` serialiserar hela modellen.

- [ ] **Steg 6: Middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Support\GameCorsOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Måste ligga före den globala HandleCors, annars ser preflight (OPTIONS)
// aldrig spelens origins. HandleCors läser config vid varje request.
class MergeGameCorsOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && $request->headers->has('Origin')) {
            config(['cors.allowed_origins' => array_values(array_unique(array_merge(
                config('cors.allowed_origins', []),
                GameCorsOrigins::all(),
            )))]);
        }

        return $next($request);
    }
}
```

`bootstrap/app.php` i `withMiddleware`: `$middleware->prepend(\App\Http\Middleware\MergeGameCorsOrigins::class);`

- [ ] **Steg 7: Filament-fältet**

I `GameResource::form()`, sektion Basic Info, efter `is_active`:

```php
Forms\Components\TagsInput::make('allowed_origins')
    ->label('Tillåtna origins (CORS)')
    ->placeholder('https://ordjakt.apps.humblebrag.se')
    ->nestedRecursiveRules([new \App\Rules\GameOrigin])
    ->helperText('Webbadresser som spelets frontend körs på. https, utan path.'),
```

- [ ] **Steg 8: Kör testerna och hela sviten**

Kör: `php artisan test --filter='GameOriginTest|GameCorsTest'` → PASS; `php artisan test` → grön.

- [ ] **Steg 9: Commit**

```bash
git add -A
git commit -m "CORS: origins per spel, normaliserade och cachade, globalt före HandleCors"
```

---

### Task 4: Regelklasser för byggstenarna, använda av Filament

**Filer:**
- Skapa: `app/Support/ConfigRules/LeaderboardTypeRules.php`, `AchievementRules.php`, `ContentPoolRules.php`, `RemoteConfigRules.php`, `GameEventRules.php`, `app/Support/ConfigRules/SlugRule.php`
- Ändra: de fem relation managers (`form()`)
- Test: `tests/Feature/Filament/ConfigRulesPanelTest.php`

**Gränssnitt:**
- Producerar, för varje regelklass `X`:
  - `X::rules(Game $game, ?Model $existing): array<string, array>` — Laravel-regler per fält för MCP-indata (arrayer, inte JSON-strängar)
  - `X::IDENTITY` — fältnamnet som identifierar posten (`slug`, `pool_key`, `key`)
  - `X::FIELDS` — fält som får skrivas
- `RemoteConfigRules::valueError(string $type, mixed $value): ?string`
- `ContentPoolRules::contentError(mixed $content): ?string`
- `GameEventRules::settingsError(mixed $settings): ?string`
- `SlugRule::for(Game $game, string $table, string $column, int $max, ?Model $existing): array`

- [ ] **Steg 1: Fallerande panel-tester**

`tests/Feature/Filament/ConfigRulesPanelTest.php` (mönster från `DailyContentPoolsRelationManagerTest`):

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\GameEventsRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\LeaderboardTypesRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\RemoteConfigsRelationManager;
use App\Models\Game;
use App\Models\LeaderboardType;
use App\Models\User;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigRulesPanelTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'g', 'name' => 'G', 'is_active' => true, 'settings' => []]);
        $this->actingAs(User::factory()->staff()->create());
    }

    private function rm(string $class)
    {
        return Livewire::test($class, ['ownerRecord' => $this->game, 'pageClass' => EditGame::class]);
    }

    private function leaderboard(array $overrides = []): array
    {
        return array_merge(['slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'Score', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true], $overrides);
    }

    public function test_duplicate_slug_is_a_validation_error_not_a_500(): void
    {
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard())->assertHasNoTableActionErrors();
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard())->assertHasTableActionErrors(['slug']);
    }

    public function test_bad_slug_format_and_max_entries_are_rejected(): void
    {
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard(['slug' => 'Bad Slug']))->assertHasTableActionErrors(['slug']);
        $this->rm(LeaderboardTypesRelationManager::class)->callTableAction(CreateAction::class, data: $this->leaderboard(['max_entries_per_period' => 0]))->assertHasTableActionErrors(['max_entries_per_period']);
    }

    public function test_existing_legacy_slug_can_still_be_edited(): void
    {
        $legacy = LeaderboardType::create(['game_id' => $this->game->id] + $this->leaderboard(['slug' => 'Daily_Time']));

        $this->rm(LeaderboardTypesRelationManager::class)
            ->callTableAction(EditAction::class, $legacy, data: $this->leaderboard(['slug' => 'Daily_Time', 'name' => 'Ny']))
            ->assertHasNoTableActionErrors();

        $this->assertSame('Ny', $legacy->fresh()->name);
    }

    public function test_remote_config_value_must_match_type(): void
    {
        foreach ([['json', '{bad'], ['int', 'abc'], ['bool', 'kanske']] as [$type, $value]) {
            $this->rm(RemoteConfigsRelationManager::class)->callTableAction(CreateAction::class, data: [
                'key' => 'k', 'value' => $value, 'value_type' => $type, 'is_active' => true,
            ])->assertHasTableActionErrors(['value']);
        }
    }

    public function test_event_must_end_after_it_starts(): void
    {
        $this->rm(GameEventsRelationManager::class)->callTableAction(CreateAction::class, data: [
            'slug' => 'e', 'name' => 'E', 'event_type' => 'challenge',
            'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-01 10:00:00', 'is_active' => true,
        ])->assertHasTableActionErrors(['ends_at']);
    }
}
```

- [ ] **Steg 2: Kör, se dem falla**

Kör: `php artisan test --filter=ConfigRulesPanelTest` → FAIL (dubbletten ger exception, formatet släpps igenom osv.).

- [ ] **Steg 3: SlugRule**

```php
<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SlugRule
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_.-]*$/';

    /**
     * Formatet kontrolleras bara när värdet är nytt eller ändras, så att
     * befintliga poster med äldre slugs fortfarande går att redigera.
     */
    public static function for(Game $game, string $table, string $column, int $max, ?Model $existing): array
    {
        $unchanged = fn ($value) => $existing !== null && $existing->getAttribute($column) === $value;

        return [
            'required', 'string', "max:{$max}",
            function (string $attribute, mixed $value, \Closure $fail) use ($unchanged) {
                if (! $unchanged($value) && (! is_string($value) || ! preg_match(self::PATTERN, $value))) {
                    $fail('Use lowercase letters, digits, "-", "_" or "." and start with a letter or digit.');
                }
            },
            Rule::unique($table, $column)->where('game_id', $game->id)->ignore($existing?->getKey()),
        ];
    }
}
```

- [ ] **Steg 4: Regelklasserna**

`LeaderboardTypeRules.php`:

```php
<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class LeaderboardTypeRules
{
    public const IDENTITY = 'slug';

    public const FIELDS = ['slug', 'name', 'sort_direction', 'score_label', 'period', 'max_entries_per_period', 'is_active'];

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'slug' => SlugRule::for($game, 'leaderboard_types', 'slug', 50, $existing),
            'name' => ['required', 'string', 'max:100'],
            'sort_direction' => ['required', 'in:asc,desc'],
            'score_label' => ['required', 'string', 'max:50'],
            'period' => ['required', 'in:daily,weekly,monthly,all_time'],
            'max_entries_per_period' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

`AchievementRules.php`: `IDENTITY = 'slug'`, `FIELDS = ['slug','name','description','icon','sort_order','is_secret','is_active']`, regler: slug `SlugRule::for($game, 'achievement_definitions', 'slug', 50, $existing)`, name `required|string|max:100`, description `required|string|max:255`, icon `required|string|max:20`, sort_order `sometimes|integer|min:0|max:100000`, is_secret och is_active `sometimes|boolean`.

`ContentPoolRules.php`:

```php
<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class ContentPoolRules
{
    public const IDENTITY = 'pool_key';

    public const FIELDS = ['pool_key', 'content', 'starts_at', 'random_access', 'is_active'];

    public const MAX_ITEMS = 5000;

    public const MAX_BYTES = 512 * 1024;

    public static function contentError(mixed $content): ?string
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return 'content must be an array (a JSON list), not a string or object.';
        }
        if (count($content) > self::MAX_ITEMS) {
            return 'content can hold at most '.self::MAX_ITEMS.' items.';
        }
        if (strlen((string) json_encode($content)) > self::MAX_BYTES) {
            return 'content can be at most 512 KB as JSON.';
        }

        return null;
    }

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'pool_key' => SlugRule::for($game, 'daily_content_pools', 'pool_key', 100, $existing),
            'content' => ['required', function (string $a, mixed $v, \Closure $fail) {
                if ($e = self::contentError($v)) {
                    $fail($e);
                }
            }],
            'starts_at' => ['nullable', 'date'],
            'random_access' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

`RemoteConfigRules.php`:

```php
<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class RemoteConfigRules
{
    public const IDENTITY = 'key';

    public const FIELDS = ['key', 'value', 'value_type', 'description', 'is_active'];

    // Speglar RemoteConfig::typedValue(): det som sparas måste gå att läsa tillbaka.
    public static function valueError(?string $type, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'value must be a string; encode JSON values as a string.';
        }

        return match ($type) {
            'int' => preg_match('/^-?\d+$/', $value) ? null : 'value must be an integer for value_type int.',
            'bool' => in_array(strtolower($value), ['true', 'false', '1', '0'], true) ? null : 'value must be true/false/1/0 for value_type bool.',
            'json' => json_validate($value) ? null : 'value must be valid JSON for value_type json.',
            'string' => strlen($value) <= 65535 ? null : 'value is too long.',
            default => null,
        };
    }

    public static function rules(Game $game, ?Model $existing, ?string $type): array
    {
        return [
            'key' => SlugRule::for($game, 'remote_configs', 'key', 100, $existing),
            'value_type' => ['required', 'in:string,int,bool,json'],
            'value' => ['required', function (string $a, mixed $v, \Closure $fail) use ($type) {
                if ($e = self::valueError($type, $v)) {
                    $fail($e);
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

`GameEventRules.php`:

```php
<?php

namespace App\Support\ConfigRules;

use App\Models\Game;
use Illuminate\Database\Eloquent\Model;

class GameEventRules
{
    public const IDENTITY = 'slug';

    public const FIELDS = ['slug', 'name', 'description', 'event_type', 'starts_at', 'ends_at', 'settings', 'is_active'];

    public const MAX_SETTINGS_BYTES = 16 * 1024;

    public static function settingsError(mixed $settings): ?string
    {
        if ($settings === null) {
            return null;
        }
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return 'settings must be a JSON object.';
        }
        if (strlen((string) json_encode($settings)) > self::MAX_SETTINGS_BYTES) {
            return 'settings can be at most 16 KB as JSON.';
        }

        return null;
    }

    public static function rules(Game $game, ?Model $existing): array
    {
        return [
            'slug' => SlugRule::for($game, 'game_events', 'slug', 100, $existing),
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'in:leaderboard,challenge,seasonal'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'settings' => ['sometimes', function (string $a, mixed $v, \Closure $fail) {
                if ($e = self::settingsError($v)) {
                    $fail($e);
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Steg 5: Koppla Filament till regelklasserna**

Mönster för varje relation manager: identitetsfältet och fält med nya regler får `->rules(fn (?Model $record) => X::rules($this->getOwnerRecord(), $record)['fält'])`. Exempel, `LeaderboardTypesRelationManager::form()`:

```php
Forms\Components\TextInput::make('slug')->required()->maxLength(50)
    ->rules(fn (?Model $record) => LeaderboardTypeRules::rules($this->getOwnerRecord(), $record)['slug']),
// ...
Forms\Components\TextInput::make('max_entries_per_period')->numeric()->default(100)
    ->rules(LeaderboardTypeRules::rules($this->getOwnerRecord(), null)['max_entries_per_period']),
```

- Achievements: `slug`, `sort_order` via `AchievementRules`.
- Content pools: `pool_key` via `ContentPoolRules`. Behåll den befintliga JSON-avkodningen för textarean men byt closure-innehållet till `ContentPoolRules::contentError(json_decode((string) $value, true))` (felet för ogiltig JSON blir då "must be an array", vilket befintliga testet `test_invalid_json_object_and_oversized_lists_are_rejected` fortfarande godtar).
- Remote config: `key` via `RemoteConfigRules`; `value` får en enda closure-regel och `value_type` får `->live()`:

```php
Forms\Components\Textarea::make('value')->required()
    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
        if ($error = RemoteConfigRules::valueError($get('value_type'), $value)) {
            $fail($error);
        }
    }),
```
- Events: `slug` via `GameEventRules`; `ends_at` får `->after('starts_at')`. Inget settings-fält i panelen (det ändras inte).

Importera `Illuminate\Database\Eloquent\Model`, `Closure` och `Filament\Forms\Get` där de behövs.

Känt beteende, avsiktligt: med MySQL:s skiftlägesokänsliga kollation hittar en upsert av `daily_time` posten `Daily_Time` och byter dess slug. Det är ofarligt (samma post) men spelets klient måste då använda den nya sluggen.

- [ ] **Steg 6: Kör testerna och hela sviten**

Kör: `php artisan test --filter='ConfigRulesPanelTest|DailyContentPoolsRelationManagerTest'` → PASS; `php artisan test` → grön.

- [ ] **Steg 7: Commit**

```bash
git add -A
git commit -m "Regelklasser för byggstenarna: unika slugs, format, värde mot typ, eventdatum"
```

---

### Task 5: Medlemskap och GameAccess

**Filer:**
- Skapa: `database/migrations/2026_10_14_100003_create_game_user_table.php`, `app/Mcp/GameAccess.php`, `app/Filament/Resources/GameResource/RelationManagers/MembersRelationManager.php`
- Ändra: `app/Models/Game.php` (members), `app/Models/User.php` (games), `app/Filament/Resources/GameResource.php` (getRelations)
- Test: `tests/Feature/Mcp/GameAccessTest.php`

**Gränssnitt:**
- Producerar: `Game::members(): BelongsToMany` (pivot `role`), `User::games(): BelongsToMany`, `GameAccess::find(User $user, string $slug): ?Game`, `GameAccess::visible(User $user): Builder<Game>`

- [ ] **Steg 1: Fallerande test**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_reaches_own_games_including_inactive_and_nothing_else(): void
    {
        $mine = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => false, 'settings' => []]);
        Game::create(['slug' => 'glosis', 'name' => 'G', 'is_active' => true, 'settings' => []]);
        $user = User::factory()->staff()->create();
        $mine->members()->attach($user, ['role' => 'owner']);

        $this->assertTrue(GameAccess::find($user, 'mine')?->is($mine));
        $this->assertNull(GameAccess::find($user, 'glosis'));
        $this->assertNull(GameAccess::find($user, 'nope'));
        $this->assertSame(['mine'], GameAccess::visible($user)->pluck('slug')->all());
    }

    public function test_platform_admin_reaches_all_games(): void
    {
        Game::create(['slug' => 'glosis', 'name' => 'G', 'is_active' => true, 'settings' => []]);
        $admin = User::factory()->staff(admin: true)->create();

        $this->assertNotNull(GameAccess::find($admin, 'glosis'));
    }

    public function test_banned_member_reaches_nothing(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $user = User::factory()->staff()->create(['is_banned' => true]);
        $game->members()->attach($user, ['role' => 'member']);

        $this->assertNull(GameAccess::find($user, 'mine'));
    }

    public function test_members_tab_only_offers_staff_accounts(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $staff = User::factory()->staff()->create(['email' => 'mattias@humblebrag.se']);
        $player = User::factory()->create(['email' => 'spelare@x.se']);
        $this->actingAs(User::factory()->staff(admin: true)->create());

        \Livewire\Livewire::test(\App\Filament\Resources\GameResource\RelationManagers\MembersRelationManager::class, [
            'ownerRecord' => $game, 'pageClass' => \App\Filament\Resources\GameResource\Pages\EditGame::class,
        ])->callTableAction(\Filament\Tables\Actions\AttachAction::class, data: ['recordId' => $player->id, 'role' => 'member'])
          ->assertHasTableActionErrors();

        $this->assertFalse($game->members()->whereKey($player->id)->exists());
        $this->assertFalse($game->members()->whereKey($staff->id)->exists());
    }
}
```

- [ ] **Steg 2: Kör, se det falla.** `php artisan test --filter=GameAccessTest` → FAIL.

- [ ] **Steg 3: Migration**

```php
Schema::create('game_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('game_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role', 20)->default('member'); // owner | member, likvärdiga i v1
    $table->timestamps();
    $table->unique(['game_id', 'user_id']);
});
```

- [ ] **Steg 4: Relationer och GameAccess**

`Game`:

```php
public function members(): BelongsToMany
{
    return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
}
```

`User`:

```php
public function games(): BelongsToMany
{
    return $this->belongsToMany(Game::class)->withPivot('role')->withTimestamps();
}
```

`app/Mcp/GameAccess.php`:

```php
<?php

namespace App\Mcp;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

// Enda stället som avgör vilka spel en MCP-användare når. Inaktiva spel ingår,
// så att ett spel kan konfigureras innan det aktiveras.
class GameAccess
{
    public static function visible(User $user): Builder
    {
        if (! $user->isStaff()) {
            return Game::query()->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdmin()) {
            return Game::query();
        }

        return Game::query()->whereHas('members', fn ($q) => $q->whereKey($user->getKey()));
    }

    public static function find(User $user, string $slug): ?Game
    {
        return self::visible($user)->where('slug', $slug)->first();
    }
}
```

- [ ] **Steg 5: MembersRelationManager**

```php
<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Medlemmar (MCP)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('role')->label('Roll'), // pivotfält; verifiera i panelen att värdet visas
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    // Bara staff kan vara medlemmar; spelare finns i samma tabell.
                    ->recordSelectOptionsQuery(fn (Builder $q) => $q->where('is_staff', true)->where('is_banned', false))
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('role')->options(['owner' => 'Ägare', 'member' => 'Medlem'])->default('member')->required(),
                    ]),
            ])
            ->actions([Tables\Actions\DetachAction::make()]);
    }
}
```

Lägg `RelationManagers\MembersRelationManager::class` sist i `GameResource::getRelations()`.

- [ ] **Steg 6: Kör testerna och hela sviten.** `php artisan test` → grön.

- [ ] **Steg 7: Commit**

```bash
git add -A
git commit -m "Medlemskap per spel för MCP och GameAccess"
```

---

### Task 6: Revisionslogg

**Filer:**
- Skapa: `database/migrations/2026_10_14_100004_create_mcp_audit_logs_table.php`, `app/Models/McpAuditLog.php`, `app/Mcp/AuditLogger.php`, `app/Filament/Resources/McpAuditLogResource.php` + `Pages/ListMcpAuditLogs.php`
- Test: `tests/Unit/AuditLoggerTest.php`

**Gränssnitt:**
- Producerar: `AuditLogger::record(?User $user, ?Game $game, string $tool, array $arguments, string $outcome): void` där `$outcome` ∈ `ok|validation_error|denied|error`; `AuditLogger::summarize(array $arguments): array`

- [ ] **Steg 1: Fallerande test**

```php
<?php

namespace Tests\Unit;

use App\Mcp\AuditLogger;
use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_keeps_scalars_shortens_strings_and_counts_lists(): void
    {
        $summary = AuditLogger::summarize([
            'slug' => 'high-score',
            'is_active' => true,
            'name' => str_repeat('x', 500),
            'content' => range(1, 4000),
            'settings' => ['a' => ['b' => 'c']],
        ]);

        $this->assertSame('high-score', $summary['slug']);
        $this->assertTrue($summary['is_active']);
        $this->assertSame(201, mb_strlen($summary['name'])); // 200 + "…"
        $this->assertSame('[list: 4000 items]', $summary['content']);
        $this->assertSame('[object: 1 keys]', $summary['settings']);
        $this->assertJson(json_encode($summary));
    }

    public function test_record_writes_a_row(): void
    {
        $user = User::factory()->create();
        AuditLogger::record($user, null, 'list_games', [], 'ok');

        $this->assertSame(1, McpAuditLog::where('user_id', $user->id)->where('outcome', 'ok')->count());
    }
}
```

- [ ] **Steg 2: Kör, se det falla.**

- [ ] **Steg 3: Migration och modell**

```php
Schema::create('mcp_audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
    $table->string('tool', 64);
    $table->json('arguments');
    $table->string('outcome', 20);
    $table->timestamp('created_at')->useCurrent();
    $table->index(['game_id', 'created_at']);
});
```

`McpAuditLog`: `public $timestamps = false; protected $guarded = []; casts arguments => array, created_at => datetime`; relationer `user()`, `game()`.

- [ ] **Steg 4: AuditLogger**

```php
<?php

namespace App\Mcp;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\User;

class AuditLogger
{
    public static function record(?User $user, ?Game $game, string $tool, array $arguments, string $outcome): void
    {
        McpAuditLog::create([
            'user_id' => $user?->getKey(),
            'game_id' => $game?->getKey(),
            'tool' => $tool,
            'arguments' => self::summarize($arguments),
            'outcome' => $outcome,
        ]);
    }

    // Alltid giltig JSON och liten: listor och objekt blir en beskrivning.
    public static function summarize(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $key => $value) {
            $out[$key] = match (true) {
                is_string($value) => mb_strlen($value) > 200 ? mb_substr($value, 0, 200).'…' : $value,
                is_array($value) && array_is_list($value) => '[list: '.count($value).' items]',
                is_array($value) => '[object: '.count($value).' keys]',
                default => $value,
            };
        }

        return $out;
    }
}
```

- [ ] **Steg 5: Filament-resurs (skrivskyddad)**

`McpAuditLogResource` med `canCreate()`, `canEdit()`, `canDelete()` och `canDeleteAny()` som returnerar `false`; tabell med kolumner `created_at` (sorterbar, default desc), `user.email`, `game.slug`, `tool`, `outcome`, och en `ViewAction` som visar `arguments` som formaterad JSON. Navigation-grupp "MCP".

- [ ] **Steg 6: Nekade anrop före verktyget loggas också**

Anrop som stoppas av `EnsureStaff` på `/mcp` når aldrig ett verktyg. Logga dem i `EnsureStaff` när en användare finns och requesten gäller `/mcp`:

```php
if (! $user || ! $user->isStaff()) {
    if ($user && $request->is('mcp')) {
        \App\Mcp\AuditLogger::record(
            \App\Models\User::find($user->getAuthIdentifier()), null,
            (string) ($request->input('params.name') ?? $request->input('method') ?? 'unknown'),
            [], 'denied',
        );
    }
    abort(403);
}
```

Lägg till i `StaffAccessTest::test_non_staff_and_banned_are_denied_on_mcp`: `$this->assertSame(2, \App\Models\McpAuditLog::where('outcome', 'denied')->count());`. Anrop utan giltig token (401 från `auth:mcp`) har ingen användare och loggas inte; det står i Laravel-loggen via throttle/401.

Smoke-test för resursen i `tests/Unit/AuditLoggerTest.php` (flytta testet till `tests/Feature/Mcp/AuditLogTest.php` om Feature-mönstret krävs):

```php
public function test_audit_log_page_renders_for_staff(): void
{
    $this->actingAs(\App\Models\User::factory()->staff(admin: true)->create());
    $this->get(\App\Filament\Resources\McpAuditLogResource::getUrl('index'))->assertOk();
}
```

- [ ] **Steg 7: Kör testerna och hela sviten.** → grön.

- [ ] **Steg 8: Commit**

```bash
git add -A
git commit -m "Revisionslogg för MCP-anrop med sammanfattade argument, även nekade"
```

---

### Task 7: GameTool-basen, list_games och invarianttesterna

**Filer:**
- Skapa: `app/Mcp/Tools/GameTool.php`, `app/Mcp/Tools/ListGamesTool.php`
- Ta bort: `app/Mcp/Tools/PingTool.php`
- Ändra: `app/Mcp/ComputercatServer.php`, `routes/ai.php` (limiter), `app/Providers/AppServiceProvider.php` (RateLimiter `mcp`)
- Test: `tests/Feature/Mcp/McpTestCase.php`, `tests/Feature/Mcp/ToolInvariantsTest.php`, `tests/Feature/Mcp/ListGamesToolTest.php`

**Gränssnitt:**
- Konsumerar: `GameAccess`, `AuditLogger`, `User::isStaff()`
- Producerar:
  - `abstract class GameTool extends Tool` med `abstract protected function run(Game $game, array $args, User $user): Response`, `protected function gameNotFound(): Response`, `protected function validationFailed(array $errors): Response`, `protected function json(array $data): Response`
  - `tests/Feature/Mcp/McpTestCase` med `callTool(User $user, string $tool, array $args): array` som returnerar `['ok' => bool, 'text' => string]` via HTTP mot `/mcp`
  - `ComputercatServer::toolClasses(): array`

- [ ] **Steg 1: Testhjälparen**

`tests/Feature/Mcp/McpTestCase.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected function staffMember(Game ...$games): User
    {
        $user = User::factory()->staff()->create();
        foreach ($games as $game) {
            $game->members()->attach($user, ['role' => 'member']);
        }

        return $user;
    }

    protected function game(string $slug = 'ordjakt', array $attrs = []): Game
    {
        return Game::create(array_merge(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true, 'settings' => []], $attrs));
    }

    /** @return array{ok: bool, text: string} */
    protected function callTool(User $user, string $tool, array $args = []): array
    {
        Passport::actingAs(StaffUser::find($user->id), ['mcp:use'], 'mcp');

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ])->assertOk();

        $result = $response->json('result');

        return [
            'ok' => ! ($result['isError'] ?? false),
            'text' => collect($result['content'] ?? [])->pluck('text')->implode("\n"),
        ];
    }
}
```

Anpassa till laravel/mcp:s faktiska svarsform från Task 1 (fältnamn för fel och innehåll). Om paketet kräver `initialize` före `tools/call`, gör det i hjälparen.

- [ ] **Steg 2: Fallerande invariant- och list-tester**

`tests/Feature/Mcp/ToolInvariantsTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ComputercatServer;
use App\Mcp\Tools\GameTool;
use App\Models\McpAuditLog;

class ToolInvariantsTest extends McpTestCase
{
    /** Varje verktyg som tar game nekar icke-medlemmar och loggar nekandet. */
    public function test_every_game_tool_denies_non_members(): void
    {
        $this->game('glosis');
        $outsider = $this->staffMember($this->game('ordjakt'));

        foreach (ComputercatServer::toolClasses() as $class) {
            if (! is_subclass_of($class, GameTool::class)) {
                continue;
            }
            $name = (new $class)->name();

            $result = $this->callTool($outsider, $name, ['game' => 'glosis']);

            $this->assertFalse($result['ok'], "{$name} svarade ok för icke-medlem");
            $this->assertStringContainsString('Game not found', $result['text'], $name);
            $this->assertTrue(McpAuditLog::where('tool', $name)->where('outcome', 'denied')->exists(), "{$name} loggade inte nekandet");
        }
    }

    /** Inget verktyg skickar ut hemligheter ur settings. */
    public function test_no_tool_leaks_game_secrets(): void
    {
        $secret = 'SECRET-'.bin2hex(random_bytes(8));
        $game = $this->game('ordjakt', ['settings' => ['revenuecat' => ['webhook_secret' => $secret], 'anthropic' => ['api_key' => $secret]]]);
        $user = $this->staffMember($game);

        foreach (ComputercatServer::toolClasses() as $class) {
            $name = (new $class)->name();
            $result = $this->callTool($user, $name, ['game' => 'ordjakt']);
            $this->assertStringNotContainsString($secret, $result['text'], $name);
        }
    }

    /** Inga raderingsverktyg och inga verktyg för pengar eller spelardata. */
    public function test_forbidden_tool_names_do_not_exist(): void
    {
        foreach (ComputercatServer::toolClasses() as $class) {
            $name = (new $class)->name();
            $this->assertDoesNotMatchRegularExpression('/delete|remove|destroy|product|purchase|user|player|settings|token/i', $name);
        }
    }
}
```

`tests/Feature/Mcp/ListGamesToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

class ListGamesToolTest extends McpTestCase
{
    public function test_lists_only_member_games(): void
    {
        $this->game('glosis');
        $user = $this->staffMember($this->game('ordjakt', ['is_active' => false]));

        $result = $this->callTool($user, 'list_games');

        $this->assertTrue($result['ok']);
        $games = json_decode($result['text'], true)['games'];
        $this->assertSame(['ordjakt'], array_column($games, 'slug'));
        $this->assertFalse($games[0]['is_active']);
    }
}
```

- [ ] **Steg 3: Kör, se dem falla.**

- [ ] **Steg 4: GameTool**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\AuditLogger;
use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för alla verktyg som tar ett spel: åtkomst, transaktion och
// revisionslogg sker här, så att ett nytt verktyg inte kan glömma dem.
abstract class GameTool extends Tool
{
    abstract protected function run(Game $game, array $args, User $user): Response;

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());
        $args = $request->all();
        $slug = is_string($args['game'] ?? null) ? $args['game'] : '';
        $game = $user ? GameAccess::find($user, $slug) : null;

        if (! $game) {
            AuditLogger::record($user, null, $this->name(), $args, 'denied');

            return $this->gameNotFound();
        }

        try {
            $response = DB::transaction(fn () => $this->run($game, $args, $user));
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::error('MCP tool failed', ['ref' => $ref, 'tool' => $this->name(), 'user_id' => $user->id, 'game' => $game->slug, 'args' => AuditLogger::summarize($args), 'exception' => $e]);
            AuditLogger::record($user, $game, $this->name(), $args, 'error');

            return Response::error("Unexpected error (ref {$ref}).");
        }

        // Skrivs utanför transaktionen så att även misslyckade anrop loggas.
        AuditLogger::record($user, $game, $this->name(), $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }

    protected function gameNotFound(): Response
    {
        return Response::error('Game not found.');
    }

    protected function validationFailed(array $errors): Response
    {
        return Response::error("Validation failed:\n".collect($errors)->map(fn ($m, $f) => "- {$f}: ".implode(' ', (array) $m))->implode("\n"));
    }

    protected function json(array $data): Response
    {
        return Response::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
```

Varje GameTool-schema måste innehålla `game` som obligatorisk sträng; lägg det i en hjälpmetod `protected function gameSchema(JsonSchema $schema): array { return ['game' => $schema->string()->description('Game slug')->required()]; }` som subklasser slår ihop med sina fält. Om `Response` saknar `isError()`, spåra utfallet med en egenskap som `validationFailed()` sätter.

- [ ] **Steg 5: ListGamesTool, server, limiter**

`ListGamesTool` ärver `Tool` (inte `GameTool`), loggar `ok` med `game = null` och returnerar `{"games": [{slug, name, is_active, allowed_origins}]}` från `GameAccess::visible($user)->orderBy('slug')`. Kontrollera `isStaff()` först (annars nekat + logg).

`ComputercatServer`:

```php
protected array $tools = [
    Tools\ListGamesTool::class,
];

public static function toolClasses(): array
{
    return (new \ReflectionClass(static::class))->getDefaultProperties()['tools'];
}
```

Lägg till i `AppServiceProvider::boot()`:

```php
RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
```

och `'throttle:mcp'` sist i middleware-listan i `routes/ai.php`. Ta bort `PingTool` och uppdatera tester från Task 1–2 som anropar `ping` till `list_games`.

- [ ] **Steg 6: Kör testerna och hela sviten.** → grön.

- [ ] **Steg 7: Commit**

```bash
git add -A
git commit -m "MCP: GameTool-bas med åtkomst, transaktion och logg, list_games, invarianttester"
```

---

### Task 8: Upsert-verktygen

**Filer:**
- Skapa: `app/Mcp/Tools/UpsertTool.php`, `UpsertLeaderboardTypeTool.php`, `UpsertAchievementTool.php`, `UpsertContentPoolTool.php`, `UpsertRemoteConfigTool.php`, `UpsertGameEventTool.php`
- Ändra: `app/Mcp/ComputercatServer.php`
- Test: `tests/Feature/Mcp/UpsertToolsTest.php`, `tests/Feature/Mcp/PanelMcpParityTest.php`

**Gränssnitt:**
- Konsumerar: `GameTool`, regelklasserna från Task 4
- Producerar: `abstract class UpsertTool extends GameTool` med `abstract protected function rulesClass(): string`, `abstract protected function relation(Game $game): HasMany`; verktygsnamn `upsert_leaderboard_type`, `upsert_achievement`, `upsert_content_pool`, `upsert_remote_config`, `upsert_game_event`

- [ ] **Steg 1: Fallerande tester**

`tests/Feature/Mcp/UpsertToolsTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\LeaderboardType;

class UpsertToolsTest extends McpTestCase
{
    private function lb(array $o = []): array
    {
        return array_merge(['game' => 'ordjakt', 'slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'Poäng', 'period' => 'daily'], $o);
    }

    public function test_upsert_creates_then_updates_without_duplicates(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['name' => 'Högsta']))['ok']);

        $this->assertSame(1, $game->leaderboardTypes()->count());
        $this->assertSame('Högsta', $game->leaderboardTypes()->first()->name);
    }

    public function test_upsert_works_on_inactive_member_game(): void
    {
        $user = $this->staffMember($this->game('ordjakt', ['is_active' => false]));

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb())['ok']);
    }

    public function test_validation_errors_are_reported_and_nothing_is_written(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'upsert_leaderboard_type', $this->lb(['period' => 'hourly', 'max_entries_per_period' => 0]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('period', $result['text']);
        $this->assertStringContainsString('max_entries_per_period', $result['text']);
        $this->assertSame(0, $game->leaderboardTypes()->count());
    }

    public function test_legacy_slug_can_be_updated_but_not_created(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        LeaderboardType::create(['game_id' => $game->id, 'slug' => 'Daily_Time', 'name' => 'D', 'sort_direction' => 'asc', 'score_label' => 'Tid', 'period' => 'daily']);

        $this->assertTrue($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['slug' => 'Daily_Time', 'name' => 'Ny']))['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_leaderboard_type', $this->lb(['slug' => 'Other_Time']))['ok']);
    }

    public function test_content_pool_rejects_string_content_and_accepts_list(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $bad = $this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => '["a","b"]']);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('must be an array', $bad['text']);
        $this->assertSame(0, $game->dailyContentPools()->count());

        $this->assertTrue($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => ['a', 'b'], 'random_access' => true])['ok']);
        $this->assertSame(['a', 'b'], $game->dailyContentPools()->first()->content);
    }

    public function test_content_pool_byte_cap(): void
    {
        $user = $this->staffMember($this->game());
        $big = array_fill(0, 100, str_repeat('x', 6000)); // ~600 KB

        $this->assertFalse($this->callTool($user, 'upsert_content_pool', ['game' => 'ordjakt', 'pool_key' => 'ord', 'content' => $big])['ok']);
    }

    public function test_remote_config_value_checked_against_type(): void
    {
        $user = $this->staffMember($this->game());

        $this->assertFalse($this->callTool($user, 'upsert_remote_config', ['game' => 'ordjakt', 'key' => 'k', 'value' => '{bad', 'value_type' => 'json'])['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_remote_config', ['game' => 'ordjakt', 'key' => 'k', 'value' => '{"a":1}', 'value_type' => 'json'])['ok']);
    }

    public function test_event_settings_replace_and_dates_are_checked(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $base = ['game' => 'ordjakt', 'slug' => 'jul', 'name' => 'Jul', 'event_type' => 'seasonal', 'starts_at' => '2026-12-01T00:00:00+01:00', 'ends_at' => '2026-12-24T23:59:00+01:00'];

        $this->assertTrue($this->callTool($user, 'upsert_game_event', $base + ['settings' => ['a' => 1, 'b' => 2]])['ok']);
        $this->assertTrue($this->callTool($user, 'upsert_game_event', $base + ['settings' => ['c' => 3]])['ok']);
        $this->assertSame(['c' => 3], $game->gameEvents()->first()->settings);

        $this->assertFalse($this->callTool($user, 'upsert_game_event', array_merge($base, ['ends_at' => '2026-11-01T00:00:00+01:00']))['ok']);
        $this->assertFalse($this->callTool($user, 'upsert_game_event', $base + ['settings' => [1, 2, 3]])['ok']);
    }

    public function test_event_times_with_offset_are_stored_correctly(): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);

        $this->callTool($user, 'upsert_game_event', ['game' => 'ordjakt', 'slug' => 'jul', 'name' => 'Jul', 'event_type' => 'seasonal', 'starts_at' => '2026-12-01T00:00:00+01:00', 'ends_at' => '2026-12-02T00:00:00+01:00']);

        $this->assertSame('2026-11-30 23:00:00', $game->gameEvents()->first()->starts_at->utc()->format('Y-m-d H:i:s'));
    }
}
```

Appens tidszon är UTC (`config/app.php:68`), så förväntat värde är `2026-11-30 23:00:00`. Testet fäller en implementation som glömmer `prepare()`.

`tests/Feature/Mcp/PanelMcpParityTest.php` — samma ogiltiga indata ger fel på båda vägarna:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Filament\Resources\GameResource\RelationManagers\LeaderboardTypesRelationManager;
use Filament\Tables\Actions\CreateAction;
use Livewire\Livewire;

class PanelMcpParityTest extends McpTestCase
{
    public static function invalidLeaderboards(): array
    {
        return [
            'slug med mellanslag' => [['slug' => 'Bad Slug']],
            'noll poster' => [['max_entries_per_period' => 0]],
            'okänd period' => [['period' => 'hourly']],
        ];
    }

    /** @dataProvider invalidLeaderboards */
    public function test_panel_and_mcp_reject_the_same_input(array $override): void
    {
        $game = $this->game();
        $user = $this->staffMember($game);
        $data = array_merge(['slug' => 'high-score', 'name' => 'High', 'sort_direction' => 'desc', 'score_label' => 'S', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true], $override);

        $this->assertFalse($this->callTool($user, 'upsert_leaderboard_type', ['game' => 'ordjakt'] + $data)['ok']);

        $this->actingAs($user);
        Livewire::test(LeaderboardTypesRelationManager::class, ['ownerRecord' => $game, 'pageClass' => EditGame::class])
            ->callTableAction(CreateAction::class, data: $data)
            ->assertHasTableActionErrors();

        $this->assertSame(0, $game->leaderboardTypes()->count());
    }
}
```

- [ ] **Steg 2: Kör, se dem falla.**

- [ ] **Steg 3: UpsertTool**

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Upsert på identitetsfältet (slug/pool_key/key). Reglerna kommer från samma
// regelklass som Filament använder, så panelen och MCP kan inte glida isär.
abstract class UpsertTool extends GameTool
{
    /** @return class-string */
    abstract protected function rulesClass(): string;

    abstract protected function relation(Game $game): HasMany;

    protected function rulesFor(Game $game, $existing, array $input): array
    {
        return $this->rulesClass()::rules($game, $existing);
    }

    /** Sista anpassning av validerade värden före sparning. */
    protected function prepare(array $validated): array
    {
        return $validated;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $class = $this->rulesClass();
        $input = array_intersect_key($args, array_flip($class::FIELDS));
        $identity = $input[$class::IDENTITY] ?? null;

        $existing = is_string($identity)
            ? $this->relation($game)->where($class::IDENTITY, $identity)->first()
            : null;

        $validator = Validator::make($input, $this->rulesFor($game, $existing, $input));
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $record = $existing ?? $this->relation($game)->make();
        $record->fill($this->prepare($validator->validated()))->save();

        return $this->json(['saved' => true, 'created' => $existing === null, $class::IDENTITY => $identity]);
    }
}
```

Kontrollera för varje modell att alla `FIELDS` finns i `$fillable` (de gör det enligt modellerna idag) och att `starts_at`/`ends_at` castas till datetime.

- [ ] **Steg 4: De fem verktygen**

Exempel, `UpsertLeaderboardTypeTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Support\ConfigRules\LeaderboardTypeRules;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\JsonSchema;

class UpsertLeaderboardTypeTool extends UpsertTool
{
    protected string $name = 'upsert_leaderboard_type';

    protected string $description = 'Create or update a leaderboard type for a game, identified by slug. Set is_active false to disable it; there is no delete.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema) + [
            'slug' => $schema->string()->description('Lowercase identifier, e.g. "daily-time"')->required(),
            'name' => $schema->string()->required(),
            'sort_direction' => $schema->string()->enum(['asc', 'desc'])->description('asc = lower is better (time), desc = higher is better (score)')->required(),
            'score_label' => $schema->string()->description('Shown to players, e.g. "Time"')->required(),
            'period' => $schema->string()->enum(['daily', 'weekly', 'monthly', 'all_time'])->required(),
            'max_entries_per_period' => $schema->integer()->description('1-10000, default 100'),
            'is_active' => $schema->boolean(),
        ];
    }

    protected function rulesClass(): string
    {
        return LeaderboardTypeRules::class;
    }

    protected function relation(Game $game): HasMany
    {
        return $game->leaderboardTypes();
    }
}
```

Övriga fyra, med samma form:

| Klass | `$name` | Regelklass | `relation()` | Schemafält (utöver `game`) |
|---|---|---|---|---|
| `UpsertAchievementTool` | `upsert_achievement` | `AchievementRules` | `achievementDefinitions()` | slug*, name*, description*, icon* (emoji eller ikon-id), sort_order, is_secret, is_active |
| `UpsertContentPoolTool` | `upsert_content_pool` | `ContentPoolRules` | `dailyContentPools()` | pool_key*, content* (`$schema->array()`, beskriv "JSON list, max 5000 items / 512 KB"), starts_at (date), random_access (beskriv att alla spelare då kan läsa hela poolen), is_active |
| `UpsertRemoteConfigTool` | `upsert_remote_config` | `RemoteConfigRules` | `remoteConfigs()` | key*, value* (sträng; JSON kodas som sträng), value_type* (enum), description, is_active |
| `UpsertGameEventTool` | `upsert_game_event` | `GameEventRules` | `gameEvents()` | slug*, name*, description, event_type* (enum), starts_at* (ISO 8601), ends_at* (ISO 8601), settings (object, ersätter tidigare), is_active |

(* = `->required()`.) `UpsertGameEventTool` och `UpsertContentPoolTool` överskuggar `prepare()` och konverterar datumfält till appens tidszon (UTC), eftersom Eloquent sparar en ISO-tid med offset utan att konvertera den:

```php
protected function prepare(array $validated): array
{
    foreach (['starts_at', 'ends_at'] as $field) {
        if (isset($validated[$field])) {
            $validated[$field] = \Illuminate\Support\Carbon::parse($validated[$field])->setTimezone(config('app.timezone'));
        }
    }

    return $validated;
}
```

`UpsertRemoteConfigTool` överskuggar `rulesFor()`: `return RemoteConfigRules::rules($game, $existing, $input['value_type'] ?? null);`.

Lägg alla fem i `ComputercatServer::$tools`.

- [ ] **Steg 5: Kör testerna och hela sviten.** `php artisan test --filter=Mcp` → PASS (inklusive invarianttesterna från Task 7, som nu omfattar de nya verktygen); `php artisan test` → grön.

- [ ] **Steg 6: Commit**

```bash
git add -A
git commit -m "MCP: upsert för topplistor, achievements, content pools, remote config och events"
```

---

### Task 9: get_game och set_allowed_origins

**Filer:**
- Skapa: `app/Mcp/Tools/GetGameTool.php`, `app/Mcp/Tools/SetAllowedOriginsTool.php`
- Ändra: `app/Mcp/ComputercatServer.php`
- Test: `tests/Feature/Mcp/GetGameToolTest.php`, `tests/Feature/Mcp/SetAllowedOriginsToolTest.php`

**Gränssnitt:**
- Konsumerar: `GameTool`, `GameOrigin`, `GameCorsOrigins`

- [ ] **Steg 1: Fallerande tester**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\DailyContentPool;

class GetGameToolTest extends McpTestCase
{
    public function test_returns_configuration_without_settings_or_pool_content(): void
    {
        $game = $this->game('ordjakt', ['settings' => ['revenuecat' => ['webhook_secret' => 'x']], 'allowed_origins' => ['https://ordjakt.apps.humblebrag.se']]);
        DailyContentPool::create(['game_id' => $game->id, 'pool_key' => 'ord', 'content' => ['hemligt-ord'], 'is_active' => true, 'random_access' => false]);
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'get_game', ['game' => 'ordjakt']);
        $data = json_decode($result['text'], true);

        $this->assertSame('ordjakt', $data['slug']);
        $this->assertSame(['https://ordjakt.apps.humblebrag.se'], $data['allowed_origins']);
        $this->assertSame(1, $data['content_pools'][0]['items']);
        $this->assertArrayNotHasKey('settings', $data);
        $this->assertStringNotContainsString('hemligt-ord', $result['text']);
        $this->assertStringEndsWith('/api/v1', $data['base_url']);
    }
}
```

Poolens innehåll utelämnas medvetet: det kan vara svarsfacit för spelare. Claude kan skriva över poolen men inte läsa den här.

```php
<?php

namespace Tests\Feature\Mcp;

class SetAllowedOriginsToolTest extends McpTestCase
{
    public function test_replaces_list_normalized(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => ['https://Ordjakt.apps.humblebrag.se/']])['ok']);
        $this->assertSame(['https://ordjakt.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }

    public function test_one_bad_origin_rejects_the_whole_list(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => ['https://ok.se', 'http://bad.se']]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['https://old.se'], $game->fresh()->allowed_origins);
    }
}
```

- [ ] **Steg 2: Kör, se dem falla.**

- [ ] **Steg 3: GetGameTool**

`run()` returnerar `$this->json([...])` med:
- `slug`, `name`, `description`, `is_active`, `allowed_origins`, `base_url` (`rtrim(config('app.url'), '/').'/api/v1'`)
- `leaderboard_types`: `slug, name, sort_direction, score_label, period, max_entries_per_period, is_active`
- `achievements`: `slug, name, description, icon, sort_order, is_secret, is_active`
- `content_pools`: `pool_key, items (count), starts_at, random_access, is_active`
- `remote_configs`: `key, value, value_type, description, is_active`
- `events`: `slug, name, description, event_type, starts_at (ISO 8601), ends_at, settings, is_active`

Bygg varje lista med explicit `->map(fn ($r) => [...])`, aldrig `toArray()` på `Game`. Schema: bara `game`.

- [ ] **Steg 4: SetAllowedOriginsTool**

Schema: `game`, `origins` (`$schema->array()->items($schema->string())->required()`, beskrivning "Replaces the full list. https://host[:port], no path."). `run()`:

```php
$validator = Validator::make($args, [
    'origins' => ['present', 'array', 'max:20'],
    'origins.*' => ['string', new GameOrigin],
]);
if ($validator->fails()) {
    return $this->validationFailed($validator->errors()->toArray());
}
$game->update(['allowed_origins' => $validator->validated()['origins']]);

return $this->json(['saved' => true, 'allowed_origins' => $game->fresh()->allowed_origins]);
```

(Normalisering och cache-rensning sker i `Game`-hookarna från Task 3.) Lägg båda verktygen i `ComputercatServer::$tools`.

- [ ] **Steg 5: Kör testerna och hela sviten.** → grön.

- [ ] **Steg 6: Commit**

```bash
git add -A
git commit -m "MCP: get_game och set_allowed_origins"
```

---

### Task 10: IntegrationGuide-service, get_integration_guide, Dev Kit utan token och escaping

**Filer:**
- Skapa: `app/Services/IntegrationGuide.php`, `app/Mcp/Tools/GetIntegrationGuideTool.php`, `app/Console/Commands/RevokeDevkitTokens.php`
- Ändra: `app/Filament/Resources/GameResource/Pages/GameDevKit.php` (hela manual- och guide-logiken flyttas ut), `resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php`, `app/Mcp/ComputercatServer.php`
- Test: `tests/Feature/Mcp/IntegrationGuideTest.php`, `tests/Feature/Console/RevokeDevkitTokensTest.php`, `tests/Feature/Filament/GameDevKitTest.php`

**Gränssnitt:**
- Producerar: `IntegrationGuide::defaultFeatures(Game $game): array<string>`, `IntegrationGuide::manual(Game $game, array $features): string`, `IntegrationGuide::setupGuide(Game $game): string`, `IntegrationGuide::FEATURES` (nyckel → etikett)

- [ ] **Steg 1: Fallerande tester**

`tests/Feature/Mcp/IntegrationGuideTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Services\IntegrationGuide;
use Laravel\Sanctum\PersonalAccessToken;

class IntegrationGuideTest extends McpTestCase
{
    public function test_manual_has_no_token_and_creates_none(): void
    {
        $game = $this->game();

        $manual = IntegrationGuide::manual($game, IntegrationGuide::defaultFeatures($game));

        $this->assertStringContainsString('ordjakt', $manual);
        $this->assertStringNotContainsString('Dev token', $manual);
        $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{20,}/', $manual);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_tool_returns_manual_and_respects_features(): void
    {
        $user = $this->staffMember($this->game());

        $all = $this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt']);
        $auth = $this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt', 'features' => ['auth']]);

        $this->assertTrue($all['ok']);
        $this->assertStringContainsString('## Authentication', $auth['text']);
        $this->assertStringNotContainsString('## Cloud Saves', $auth['text']);
    }

    public function test_unknown_feature_is_a_validation_error(): void
    {
        $user = $this->staffMember($this->game());

        $this->assertFalse($this->callTool($user, 'get_integration_guide', ['game' => 'ordjakt', 'features' => ['bitcoin']])['ok']);
    }
}
```

Läs `GameDevKit::getManual()` för de faktiska rubrikerna (t.ex. vad saves-sektionen heter) och använd dem i assertionerna.

`tests/Feature/Filament/GameDevKitTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource;
use App\Models\AchievementDefinition;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDevKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_in_names_is_escaped_and_no_token_is_created(): void
    {
        $game = Game::create(['slug' => 'x', 'name' => '<img src=x onerror=alert(1)>', 'is_active' => true, 'settings' => []]);
        AchievementDefinition::create(['game_id' => $game->id, 'slug' => 'a', 'name' => '<script>alert(2)</script>', 'description' => 'd', 'icon' => '*']);
        $user = User::factory()->staff()->create();

        $html = $this->actingAs($user)->get(GameResource::getUrl('devkit', ['record' => $game]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertSame(0, $user->tokens()->count());
    }
}
```


`tests/Feature/Console/RevokeDevkitTokensTest.php`:

```php
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
```

- [ ] **Steg 2: Kör, se dem falla.**

- [ ] **Steg 3: Flytta logiken till IntegrationGuide**

Skapa `App\Services\IntegrationGuide` med statiska metoder. Flytta innehållet ur `GameDevKit`:
- `mount()`:s feature-härledning → `defaultFeatures(Game $game)` (laddar relationerna själv).
- options-listan i `form()` → `FEATURES`-konstanten.
- `getSetupGuide()` → `setupGuide(Game $game)`.
- `getManual()` → `manual(Game $game, array $features)`, med dessa ändringar:
  - Raden `$token = $this->getDevToken();`, `$tokenEscaped` och tabellraden "Dev token" tas bort.
  - Meningen "Use the dev token for testing…" ersätts med: "For testing, create an anonymous user exactly as the app does (see Authentication). There is no shared dev token."
  - Alla ställen som skriver ut `Authorization: Bearer {$token}` byter till `Authorization: Bearer <token>`.
- Alla privata hjälpmetoder som `getManual()` använder flyttas med.

`GameDevKit` behåller `$features` och formuläret men delegerar: `mount()` sätter `$this->features = IntegrationGuide::defaultFeatures($this->record)`, `getSetupGuide()` returnerar `IntegrationGuide::setupGuide($this->record)`, `getManual()` returnerar (cachat) `IntegrationGuide::manual($this->record, $this->features)`. `getDevToken()` tas bort helt.

- [ ] **Steg 4: Escaping i vyn**

I `game-dev-kit.blade.php`, byt båda `Str::markdown(...)`-anropen till:

```blade
{!! \Illuminate\Support\Str::markdown($this->getSetupGuide(), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
```

(och samma för `getManual()`). Textarean `{{ $this->getManual() }}` är redan escapad. Byt också beskrivningen på rad 35 från "…including a dev token." till "…For testing, the assistant creates an anonymous user the same way the game does."

- [ ] **Steg 5: GetIntegrationGuideTool**

Schema: `game`, `features` (`$schema->array()->items($schema->string()->enum(array_keys(IntegrationGuide::FEATURES)))`, beskrivning "Optional; omitted = features this game has configured"). `run()` validerar `features` mot `array_keys(IntegrationGuide::FEATURES)` med `Validator` och returnerar `Response::text(IntegrationGuide::manual($game, $features ?? IntegrationGuide::defaultFeatures($game)))`. Lägg till i `ComputercatServer::$tools`.

- [ ] **Steg 6: RevokeDevkitTokens**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeDevkitTokens extends Command
{
    protected $signature = 'computercat:revoke-devkit-tokens';

    protected $description = 'Raderar Sanctum-tokens som Dev Kit skapat (namn "devkit"). Räknar och listar först.';

    public function handle(): int
    {
        $tokens = PersonalAccessToken::where('name', 'devkit')->get(['id', 'tokenable_id', 'created_at', 'last_used_at']);

        $this->line("{$tokens->count()} devkit-token(s).");
        $this->table(['id', 'user_id', 'skapad', 'senast använd'], $tokens->map(fn ($t) => [$t->id, $t->tokenable_id, $t->created_at, $t->last_used_at ?? '-'])->all());

        if ($tokens->isEmpty() || ! $this->confirm('Radera dessa tokens?')) {
            return self::SUCCESS;
        }

        // Raderar exakt de id:n som listades ovan.
        $deleted = PersonalAccessToken::whereIn('id', $tokens->pluck('id'))->delete();
        $this->info("Raderade {$deleted}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Steg 7: Kör testerna och hela sviten.** → grön. Öppna även Dev Kit-sidan för ett befintligt spel i webbläsaren lokalt (`https://computercat-api-docs-spelplattform-mcp.test/admin`) och kontrollera att manualen ser ut som förut, minus tokenraden.

- [ ] **Steg 8: Commit**

```bash
git add -A
git commit -m "Dev Kit: manualen som service utan personlig token, escapad markdown, get_integration_guide"
```

---

### Task 11: Slutverifiering mot claude.ai

**Filer:** inga nya; eventuella rättelser i de filer som berörs.

- [ ] **Steg 1: Hela sviten och statiska kontroller**

```bash
php artisan test
php artisan route:list --path=mcp -v
php artisan route:list --name=passport.authorizations -v
```

Förväntat: grönt; `/mcp` har `auth:mcp`, `EnsureStaff`, `throttle:mcp`; authorize-routes har `EnsureStaff`.

- [ ] **Steg 2: Riktig koppling**

Samma exponeringsväg som Erik valde i Task 1 steg 8 (fråga igen med AskUserQuestion om den inte längre är given). Med en lokal kopia av ett testspel där testkontot är medlem:
1. Claude listar spel (`list_games`) och ser bara testspelet.
2. Claude läser spelet (`get_game`).
3. Claude lägger till topplistetypen `test-mcp` (`upsert_leaderboard_type`).
4. Typen syns i Filament under spelet, och revisionsloggen visar tre `ok`-rader.
5. Claude försöker läsa ett spel kontot inte är medlem i och får "Game not found".

Spara skärmdumpar eller transkriptet för slutrapporten. Stäng exponeringen och ta bort testconnectorn efteråt. Testposten `test-mcp` raderas på sitt id, som du noterar när den skapas.

- [ ] **Steg 3: Commit eventuella rättelser**

```bash
git add -A
git commit -m "MCP: rättelser efter verifiering mot claude.ai"
```

Driftsättning till prod ingår inte i planen. Den görs efter completion-gate, med Eriks OK, i denna ordning: deploy → migrate → `passport:keys` (behörighet 600) → `computercat:staff` för Erik (`--admin`) och Mattias → kontrollera panelinloggning → lägg till connectorn i claude.ai → `computercat:revoke-devkit-tokens`.
