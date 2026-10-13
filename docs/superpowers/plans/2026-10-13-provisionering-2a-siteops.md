# Provisionering 2a: SiteOps API för Demo-miljöer — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** SiteOps API:t kan skapa en webb med en enda Demo-miljö (basic auth, övervakning), slå upp en webb på exakt URL, ändra miljöns basic auth och övervakning, och avaktivera webben, så att Computercats provisioneringssaga (2c) kan registrera och arkivera Demo-spel utan att en människa klickar i SiteOps.

**Architecture:** Allt ligger i befintliga `ApiController` bakom `oauth`-middlewaren och `requireScope()`. `POST /sites` får ett valfritt `environment`-objekt som skapas i samma transaktion som webben och reservlösningen. `GET /sites?url=` blir ett exakt uppslag (webbens adress eller någon miljös adress). Två nya skrivroutes (`PATCH /sites/{id}/environments/{env}`, `POST /sites/{id}/deactivate`) får bara röra webbar som samma API-klient skapade med `environment` (ny kolumn `sites.api_client_id`, som bara sätts när `POST /sites` har `environment`, alltså för Computercats Demo-webbar). Webbar skapade utan `environment` får `api_client_id = null` och ger 403 vid PATCH/deactivate för varje API-klient. Miljöer serialiseras på ett enda ställe (`formatApiEnvironment`) som aldrig lämnar ut basic auth. Två nya artisan-kommandon skapar API-klienten med uttryckliga scopes (`api-clients:create`) och byter dess nyckel utan att byta klient (`api-clients:rotate`, så att ägarskapet i `sites.api_client_id` består).

**Tech Stack:** Laravel 12.69, PHP 8.4, PHPUnit 11 (inte Pest), SQLite in-memory i testerna, Pint.

**Spec:** `/Users/erik/Web/computercat-api/.claude/worktrees/docs-provisionering/docs/superpowers/specs/2026-10-13-spelplattform-provisionering-design.md`, avsnitt "2a. SiteOps API".

**Beroenden och ordning:** 2a beror inte på 2b eller 2c och kan köras parallellt med 2b. 2c:s spike (2c Task 1, punkt S19) kräver att 2a är mergad, **deployad till SiteOps-produktion med `migrate`** och att Computercats nyckel är skapad (se "Efter deploy" i Task 7). Den deployen är en produktionsåtgärd och kräver Eriks uttryckliga OK (AskUserQuestion) när 2a är klar; den ingår inte i denna plans tasks. Ordningen över planerna: förutsättningarna → 2a och 2b → deploy av 2a + push av spelmallen (2b Task 12), båda med Eriks OK → 2c Task 1 (spike) → resten av 2c → 2d.

---

## Global Constraints

- Repo: `/Users/erik/Web/siteops`. Allt arbete i worktreen `/Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo` (skapas i Task 0). Huvudkatalogen `/Users/erik/Web/siteops` rörs aldrig: ingen checkout, ingen stash, inga filändringar.
- Inga produktionsåtgärder. Deploy, `migrate` i prod och skapandet av den riktiga Computercat-klienten ingår inte.
- Alla routes under `/api/v1`, middleware `oauth` + `throttle:120,1` (befintlig grupp).
- Scope `sites:write` för `POST /sites`, `PATCH /sites/{id}/environments/{env}`, `POST /sites/{id}/deactivate`.
- Scope `sites:read` för `GET /sites?url=` (scopet finns redan i `OAuthController::metadata()`).
- `environment.type` måste vara en nyckel i `Environment::TYPES` (`production, staging, demo, development, worker, mobile, custom`).
- `environment.url` måste vara `https://` (`url:https`), max 500.
- `environment.name` max 50 (kolumnen `environments.name` är `string(50)`).
- `basic_auth_user` och `basic_auth_password` max 255 vardera, båda eller ingen.
- Basic auth lagras krypterat via modellens `encrypted`-cast, som i UI:t. Varken användarnamn eller lösenord visas i något API-svar; svaret har bara `has_basic_auth` (bool).
- `POST /sites` med `environment` skapar bara den miljön, ingen tom Production-miljö.
- `environment` och `credentials` i samma anrop → 422.
- `sites.api_client_id` sätts BARA när `POST /sites` innehåller `environment` (Computercat-Demo-webbar). Webbar skapade utan `environment` behåller `api_client_id = null`; PATCH och deactivate ger 403 på dem för varje API-klient.
- Avaktivering sätter `active = false` och `monitoring_enabled = false`. Ingen radering via API.
- Webben får som idag en egen reservlösning (`BelongsToSolution`).
- Anroparen skickar `Accept: application/json` (annars renderar Laravel valideringsfel som redirect).
- Ny API-klient för Computercat: scopes exakt `sites:read sites:write`, `expose_cases = false`, ingen wildcard.
- Commit-meddelanden på svenska i formen `feat(api): …`, utan Co-Authored-By.

### Låst API-kontrakt (det computercat-api 2c anropar)

Auth: header `X-Api-Key: <nyckel>` (eller `Authorization: Bearer <jwt>`), plus `Accept: application/json`.

**1. Uppslag på exakt URL** — `GET /api/v1/sites?url=<urlencoded>` (scope `sites:read`)
- Matchar webbar i nyckelns team där `sites.url` eller någon miljös `url` är exakt lika, med eller utan avslutande `/`. Ingen delsträngsmatchning. Inaktiva webbar kommer med (`is_active: false`).
- 200: `{"data":[{"id":34,"name":"Kattsvep","url":"https://kattsvep.apps.humblebrag.se","type":"app","is_active":true,"is_online":false,"ops_tier":2,"ops_mode":"supervised","cms_type":null,"cms_version":null,"plugin_update_count":null,"plugin_blocked_count":0,"last_info_at":null,"pejl_customer_id":null,"solution_id":51,"sentry_project_slug":null}],"meta":{"total":1}}` — samma objekt som listningen ger idag. Ingen träff: `{"data":[],"meta":{"total":0}}`.
- 422: `{"message":"…","errors":{"url":["…"]}}` när `url` saknar värde eller inte är en URL.
- Computercat läser `data[0].id` och `data[0].is_active`.

**2. Skapa webb med Demo-miljö** — `POST /api/v1/sites` (scope `sites:write`)
- Request:
  ```json
  {"name":"Kattsvep","url":"https://kattsvep.apps.humblebrag.se","type":"app",
   "environment":{"type":"demo","name":"Demo","url":"https://kattsvep.apps.humblebrag.se",
                  "basic_auth_user":"demo","basic_auth_password":"demo","is_monitored":true}}
  ```
  `environment.is_monitored` är valfritt (standard `false`). `environment.basic_auth_*` är valfria men båda eller ingen. Okända nycklar i `environment` → 422.
- 201: `{"data":{ <befintlig webbdetalj: id, name, url, type, is_active, …, monitoring_enabled>, "environment":{"id":12,"site_id":34,"name":"Demo","type":"demo","url":"https://kattsvep.apps.humblebrag.se","is_monitored":true,"has_basic_auth":true}}}`
- 409: `{"error":"A site with this URL already exists in the team.","site_id":34}` när en webb i teamet redan har `url` eller `environment.url` (exakt, som uppslaget). Gäller bara anrop med `environment`.
- 422: valideringsfel. 403: scope saknas.
- Utan `environment` är beteendet oförändrat.

**3. Ändra miljö** — `PATCH /api/v1/sites/{id}/environments/{env}` (scope `sites:write`)
- Request: valfri delmängd av `{"basic_auth_user":"demo","basic_auth_password":"demo","is_monitored":true}`, minst ett fält. `basic_auth_user` och `basic_auth_password` skickas alltid tillsammans; båda `null` tar bort basic auth.
- 200: `{"data":{"id":12,"site_id":34,"name":"Demo","type":"demo","url":"https://kattsvep.apps.humblebrag.se","is_monitored":true,"has_basic_auth":true}}`
- 404: `{"error":"Site not found"}` / `{"error":"Environment not found"}` (miljön måste höra till webben, webben till nyckelns team).
- 403: scope saknas, eller webben skapades inte av samma API-klient med `environment` (webbar skapade utan `environment`, i UI:t eller av annan klient har `api_client_id = null` och ger 403 för alla). Ett 403 ändrar ingenting (testat i Task 4). **2c använder detta som ägarskapsbevis:** när `POST /sites` ger 409 eller uppslaget hittar en webb, adopterar 2c den bara om `PATCH` mot dess demo-miljö ger 200; 403 betyder att webben inte är Computercats och sagan stannar med fel.
- 422: valideringsfel, ensamt basic auth-fält, tom body.

**4. Avaktivera** — `POST /api/v1/sites/{id}/deactivate` (scope `sites:write`, ingen body)
- 200: `{"data":{"id":34,"is_active":false,"monitoring_enabled":false}}`. Idempotent: ett andra anrop ger samma svar.
- 404: `{"error":"Site not found"}`. 403: scope saknas eller webben skapades inte av samma API-klient med `environment` (se kontrakt 3).

**Utökade läsytor (för 2c:s invariant):**
- `GET /api/v1/sites/{id}` får fältet `monitoring_enabled` (bool).
- `GET /api/v1/sites/{id}/environments` får per miljö `type` (string) och `has_basic_auth` (bool).

**Kommandon utöver specen:**
1. Specen säger inte hur klienten skapas eller byter nyckel. Det finns varken UI eller kommando för maskinnycklar med egna scopes idag (bara DCR utan team och desktop-grant). Planen lägger till `php artisan api-clients:create` (Task 7) och `php artisan api-clients:rotate` (Task 7b).

---

## Review Focus

De fem troligaste felmoderna som annars inte täcks, och testet som fångar var och en:

1. **Basic auth läcker i ett svar.** Något av de fyra svaren (create, patch, uppslag, miljölistan) serialiserar modellen eller ett fält för mycket. Test: `test_basic_auth_never_appears_in_any_response` i Task 3 och `test_patch_response_never_contains_basic_auth` i Task 4 och `test_environments_list_has_type_and_flag_but_no_credentials` i Task 6 söker de unika test-strängarna i rå svarstext.
2. **En tom Production-miljö skapas bredvid Demo-miljön** (t.ex. för att `credentials` och `environment` kombineras, eller att någon återanvänder `createEmptyProductionEnvironment`). Test: `test_creates_site_with_only_the_demo_environment` och `test_environment_and_credentials_together_is_rejected` i Task 3.
3. **Uppslaget matchar delsträngar eller missar avslutande snedstreck,** så att `kattsvep` hittar `kattsvep2` och 2c kopplar fel webb, eller inte hittar sin egen och skapar en dubblett. Test: `test_lookup_matches_exact_site_url_only` och `test_lookup_ignores_trailing_slash` i Task 2, `test_duplicate_url_returns_409_with_existing_site_id` i Task 3.
4. **Tvärs över team eller webbar:** PATCH på en miljö som hör till en annan webb i samma team, eller en webb i ett annat team. Test: `test_environment_of_another_site_is_404` och `test_site_of_another_team_is_404` i Task 4, `test_lookup_never_returns_other_teams_site` i Task 2.
5. **Scope eller ägarskap kontrolleras inte i den riktiga middlewarekedjan** (befintliga tester kringgår `OAuthMiddleware` och sätter attribut för hand). Alla nya tester går genom riktig `X-Api-Key`. Test: `test_requires_sites_write` i Task 3, 4 och 5, `test_site_created_by_another_client_is_403` i Task 4 och 5, `test_site_created_without_environment_by_same_client_is_403` i Task 5, `test_lookup_requires_sites_read` i Task 2.

Dessutom: rotation som byter nyckel men behåller klienten (`test_rotation_keeps_client_id_so_ownership_survives`, Task 7b), att `api_client_id` bara sätts med `environment` (`test_without_environment_client_is_not_recorded`, Task 3), transaktionen (`test_failed_environment_insert_leaves_no_site_or_solution`, Task 3) och att ingen DELETE-route finns (`test_there_is_no_delete_route`, Task 5).

---

## Filstruktur

```
/Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo/
├── app/
│   ├── Console/Commands/CreateApiClient.php                       (ny, Task 7)
│   ├── Console/Commands/RotateApiClient.php                       (ny, Task 7b)
│   ├── Http/Controllers/ApiController.php                         (ändras, Task 2–6)
│   ├── Http/Controllers/OAuthController.php                       (ändras, Task 7)
│   └── Models/OAuthClient.php                                     (ändras, Task 7)
├── database/migrations/2026_10_13_200000_add_api_client_id_to_sites.php  (ny, Task 3)
├── routes/api.php                                                 (ändras, Task 4, 5)
└── tests/
    ├── Concerns/CreatesApiClient.php                              (ny, Task 1)
    └── Feature/Api/
        ├── SiteUrlLookupApiTest.php                               (ny, Task 2)
        ├── CreateSiteWithEnvironmentApiTest.php                   (ny, Task 3)
        ├── UpdateSiteEnvironmentApiTest.php                       (ny, Task 4)
        ├── DeactivateSiteApiTest.php                              (ny, Task 5)
        ├── SiteReadSurfacesApiTest.php                            (ny, Task 6)
        ├── CreateApiClientCommandTest.php                         (ny, Task 7)
        └── RotateApiClientCommandTest.php                         (ny, Task 7b)
```

`WT=/Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo` används nedan som förkortning; skriv ut hela sökvägen i varje kommando.

---

## Task 0: Worktree

**Files:** inga.

- [ ] **Steg 1: Skapa worktreen med egen databas (branchen har en migration).**

```bash
~/.claude/bin/wt-start /Users/erik/Web/siteops feat/api-demo-miljo --db
```

Förväntat: worktreen `/Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo` på branchen `feat/api-demo-miljo`, egen `.env`, egen databas.

- [ ] **Steg 2: Baslinje.** Kör de befintliga API-testerna som rör det vi ändrar.

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='ApiCreateSiteWithCredentialsTest|SolutionAssignmentTest|TenantScopingTest|SitesEndpoint|CasesEndpointScopeDenialTest|DesktopKeyIdentityTest|SiteSubResourceApiTest'
```

Förväntat: grönt. Är något rött redan nu: stanna och fråga Erik (AskUserQuestion), bygg inte på en röd baslinje.

---

## Task 1: Testhjälp för riktiga API-nycklar

**Files:**
- Create: `tests/Concerns/CreatesApiClient.php`

**Interfaces:**
- Produces: `protected function apiClient(array $scopes, ?Team $team = null): array` → `[Team $team, string $rawKey, OAuthClient $client]`; `protected function api(string $rawKey): static` (sätter `X-Api-Key` och `Accept: application/json`).

Ingen egen testfil: hjälpen prövas av varje test i Task 2–6. Den går genom riktig `OAuthMiddleware` (till skillnad från `ApiCreateSiteWithCredentialsTest`, som kringgår den).

- [ ] **Steg 1: Skriv traiten.**

```php
<?php

namespace Tests\Concerns;

use App\Models\OAuthClient;
use App\Models\Team;
use App\Models\User;

/**
 * En maskinklient med X-Api-Key, som Computercats. Testerna går genom den
 * riktiga OAuthMiddleware, så scope och team prövas som i produktion.
 */
trait CreatesApiClient
{
    /** @return array{0: Team, 1: string, 2: OAuthClient} */
    protected function apiClient(array $scopes, ?Team $team = null): array
    {
        if ($team === null) {
            $owner = User::factory()->create();
            $team = Team::factory()->create(['owner_id' => $owner->id]);
        }

        $rawKey = 'test-' . bin2hex(random_bytes(16));
        $client = OAuthClient::create([
            'name' => 'Computercat (test)',
            'client_id' => 'api-' . bin2hex(random_bytes(8)),
            'client_secret_hash' => hash('sha256', $rawKey),
            'team_id' => $team->id,
            'scopes' => $scopes,
            'expose_cases' => false,
            'is_first_party' => false,
            'is_active' => true,
        ]);

        return [$team, $rawKey, $client];
    }

    protected function api(string $rawKey): static
    {
        return $this->withHeaders(['X-Api-Key' => $rawKey, 'Accept' => 'application/json']);
    }
}
```

- [ ] **Steg 2: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add tests/Concerns/CreatesApiClient.php && git commit -m "test(api): hjälp för tester med riktig X-Api-Key"
```

---

## Task 2: Exakt uppslag `GET /sites?url=`

Befintliga `/sites/search` gör `LIKE '%url%'` (delsträng, utan escaping av `%`/`_`) och används av andra klienter. Den semantiken är fel för idempotens och ändras inte. I stället får listningen `GET /sites` en `url`-parameter med exakt matchning. Listningen ignorerar i dag `url`; en befintlig klient som redan skickar `url` till `GET /sites` och väntar sig hela listan skulle få ett filtrerat svar. Sök i Pejl- och Desktop-klienterna (`grep -rn "v1/sites?" ` i deras repon) innan steg 3, och fråga Erik om en träff finns.

**Files:**
- Create: `tests/Feature/Api/SiteUrlLookupApiTest.php`
- Modify: `app/Http/Controllers/ApiController.php` (`listSites`, ny privat `sitesWithExactUrl`)

**Interfaces:**
- Consumes: `CreatesApiClient` (Task 1).
- Produces (HTTP): `GET /api/v1/sites?url=<url>` → 200 `{"data":[<siteListItem>],"meta":{"total":N}}`, 422 vid ogiltig `url`, 403 utan `sites:read`.
- Produces (PHP): `private function sitesWithExactUrl(int $teamId, array $urls): \Illuminate\Database\Eloquent\Builder` — teamets webbar där `sites.url` eller någon miljös `url` är exakt en av `$urls`, med eller utan avslutande `/`. Används av Task 3.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\Site;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class SiteUrlLookupApiTest extends TestCase
{
    use CreatesApiClient;

    private const URL = 'https://kattsvep.apps.humblebrag.se';

    private function lookup(string $key, string $url)
    {
        return $this->api($key)->getJson('/api/v1/sites?url=' . urlencode($url));
    }

    public function test_lookup_matches_exact_site_url_only(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        $hit = Site::factory()->create(['team_id' => $team->id, 'url' => self::URL]);
        Site::factory()->create(['team_id' => $team->id, 'url' => 'https://kattsvep2.apps.humblebrag.se']);
        Site::factory()->create(['team_id' => $team->id, 'url' => 'https://x.se/?r=' . self::URL]);

        $this->lookup($key, self::URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $hit->id);
    }

    public function test_lookup_ignores_trailing_slash(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        $hit = Site::factory()->create(['team_id' => $team->id, 'url' => self::URL . '/']);

        $this->lookup($key, self::URL)->assertOk()->assertJsonPath('data.0.id', $hit->id);

        $hit->update(['url' => self::URL]);
        $this->lookup($key, self::URL . '/')->assertOk()->assertJsonPath('data.0.id', $hit->id);
    }

    public function test_lookup_matches_environment_url(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        $site = Site::factory()->create(['team_id' => $team->id, 'url' => 'https://annan.se']);
        Environment::factory()->forSite($site)->create(['type' => 'demo', 'url' => self::URL]);

        $this->lookup($key, self::URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $site->id);
    }

    public function test_lookup_never_returns_other_teams_site(): void
    {
        [, $key] = $this->apiClient(['sites:read']);
        [$otherTeam] = $this->apiClient(['sites:read']);
        Site::factory()->create(['team_id' => $otherTeam->id, 'url' => self::URL]);

        $this->lookup($key, self::URL)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_lookup_returns_inactive_site_marked_inactive(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        Site::factory()->create(['team_id' => $team->id, 'url' => self::URL, 'active' => false]);

        $this->lookup($key, self::URL)->assertOk()->assertJsonPath('data.0.is_active', false);
    }

    public function test_lookup_without_match_is_empty_list(): void
    {
        [, $key] = $this->apiClient(['sites:read']);

        $this->lookup($key, self::URL)->assertOk()->assertExactJson(['data' => [], 'meta' => ['total' => 0]]);
    }

    public function test_lookup_rejects_empty_or_invalid_url(): void
    {
        [, $key] = $this->apiClient(['sites:read']);

        $this->api($key)->getJson('/api/v1/sites?url=')->assertStatus(422)->assertJsonValidationErrors('url');
        $this->lookup($key, 'inte-en-url')->assertStatus(422)->assertJsonValidationErrors('url');
    }

    public function test_listing_without_url_param_is_unchanged(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        Site::factory()->count(2)->create(['team_id' => $team->id]);

        $this->api($key)->getJson('/api/v1/sites')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_lookup_requires_sites_read(): void
    {
        [, $key] = $this->apiClient(['sites:write']);

        $this->lookup($key, self::URL)->assertForbidden();
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=SiteUrlLookupApiTest
```

Förväntat: FAIL i `test_lookup_matches_exact_site_url_only` (total 3, parametern ignoreras), `test_lookup_ignores_trailing_slash`, `test_lookup_matches_environment_url`, `test_lookup_rejects_empty_or_invalid_url` (200 i stället för 422), `test_lookup_returns_inactive_site_marked_inactive` kan gå igenom av en slump. `test_listing_without_url_param_is_unchanged`, `test_lookup_never_returns_other_teams_site` och `test_lookup_requires_sites_read` går redan igenom.

- [ ] **Steg 3: Implementera.** I `ApiController::listSites`, direkt efter raden `$query = Site::where('team_id', $teamId)->with('productionEnvironment');`:

```php
        // ?url= är ett exakt uppslag (Computercats provisionering slår upp sin
        // egen webb för idempotens). /sites/search är delsträngssökning och
        // duger inte till det: "kattsvep" hade hittat "kattsvep2".
        if ($request->has('url')) {
            $url = $request->validate(['url' => ['required', 'string', 'url', 'max:500']])['url'];
            $query->whereIn('id', $this->sitesWithExactUrl((int) $teamId, [$url])->select('id'));
        }
```

Lägg till under `// --- Helpers ---`, efter `findSite()`:

```php
    /**
     * Teamets webbar där webbens adress eller någon miljös adress är exakt en
     * av $urls. Avslutande snedstreck räknas inte: "https://x.se" och
     * "https://x.se/" är samma adress. Ingen delsträngsmatchning.
     *
     * @param  list<string>  $urls
     */
    private function sitesWithExactUrl(int $teamId, array $urls): \Illuminate\Database\Eloquent\Builder
    {
        $variants = collect($urls)
            ->map(fn (string $url) => rtrim($url, '/'))
            ->flatMap(fn (string $url) => [$url, $url . '/'])
            ->unique()
            ->values()
            ->all();

        return Site::where('team_id', $teamId)
            ->where(fn ($q) => $q->whereIn('url', $variants)
                ->orWhereIn('id', Environment::where('team_id', $teamId)->whereIn('url', $variants)->select('site_id')));
    }
```

- [ ] **Steg 4: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='SiteUrlLookupApiTest|SitesEndpoint|TenantScopingTest'
```

Förväntat: alla gröna.

- [ ] **Steg 5: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add app/Http/Controllers/ApiController.php tests/Feature/Api/SiteUrlLookupApiTest.php && git commit -m "feat(api): exakt uppslag av webb på URL via GET /sites?url="
```

---

## Task 3: `POST /sites` med `environment`

**Files:**
- Create: `database/migrations/2026_10_13_200000_add_api_client_id_to_sites.php`
- Create: `tests/Feature/Api/CreateSiteWithEnvironmentApiTest.php`
- Modify: `app/Http/Controllers/ApiController.php` (`createSite`, nya privata `createApiEnvironment`, `formatApiEnvironment`; `formatSiteDetail` får `monitoring_enabled` i Task 6, inte här)

**Interfaces:**
- Consumes: `sitesWithExactUrl()` (Task 2), `CreatesApiClient` (Task 1).
- Produces (HTTP): kontrakt 2 ovan: 201 `{"data":{…webbdetalj…,"environment":{"id","site_id","name","type","url","is_monitored","has_basic_auth"}}}`, 409 `{"error":"A site with this URL already exists in the team.","site_id":int}`, 422, 403.
- Produces (PHP):
  - `private function createApiEnvironment(Site $site, int $teamId, array $env): Environment`
  - `private function formatApiEnvironment(Environment $env): array` → `['id' => int, 'site_id' => int, 'name' => string, 'type' => string, 'url' => ?string, 'is_monitored' => bool, 'has_basic_auth' => bool]`. Används av Task 4.
  - Kolumn `sites.api_client_id` (string 80, nullable, index) = `oauth_clients.client_id` för klienten som skapade webben via `POST /sites` med `environment`; null för alla andra webbar. Används av Task 4 och 5.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\Site;
use App\Models\Solution;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class CreateSiteWithEnvironmentApiTest extends TestCase
{
    use CreatesApiClient;

    private const URL = 'https://kattsvep.apps.humblebrag.se';

    // Unika strängar så att en läcka syns var den än hamnar i svaret.
    private const BA_USER = 'anv-7f3a1c';
    private const BA_PASSWORD = 'losen-9c1e4b';

    private function payload(array $environment = []): array
    {
        return [
            'name' => 'Kattsvep',
            'url' => self::URL,
            'type' => 'app',
            'environment' => array_merge([
                'type' => 'demo',
                'name' => 'Demo',
                'url' => self::URL,
                'basic_auth_user' => self::BA_USER,
                'basic_auth_password' => self::BA_PASSWORD,
                'is_monitored' => true,
            ], $environment),
        ];
    }

    public function test_creates_site_with_only_the_demo_environment(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:read', 'sites:write']);

        $res = $this->api($key)->postJson('/api/v1/sites', $this->payload());

        $res->assertCreated();
        $site = Site::where('team_id', $team->id)->sole();
        $env = $site->environments()->sole();

        $this->assertSame('app', $site->type);
        $this->assertTrue($site->active);
        $this->assertTrue($site->monitoring_enabled);
        $this->assertNotNull($site->solution_id);
        $this->assertSame($client->client_id, $site->api_client_id);
        $this->assertNull($site->productionEnvironment);

        $this->assertSame('demo', $env->type);
        $this->assertSame('Demo', $env->name);
        $this->assertSame(self::URL, $env->url);
        $this->assertTrue($env->is_monitored);
        $this->assertSame($team->id, $env->team_id);
        $this->assertSame(self::BA_USER, $env->basic_auth_user);
        $this->assertSame(self::BA_PASSWORD, $env->basic_auth_password);

        $res->assertJsonPath('data.id', $site->id)
            ->assertJsonPath('data.url', self::URL)
            ->assertJsonPath('data.environment', [
                'id' => $env->id,
                'site_id' => $site->id,
                'name' => 'Demo',
                'type' => 'demo',
                'url' => self::URL,
                'is_monitored' => true,
                'has_basic_auth' => true,
            ]);
    }

    public function test_basic_auth_is_stored_encrypted(): void
    {
        [, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload())->assertCreated();

        $raw = DB::table('environments')->first();
        $this->assertStringNotContainsString(self::BA_USER, (string) $raw->basic_auth_user);
        $this->assertStringNotContainsString(self::BA_PASSWORD, (string) $raw->basic_auth_password);
    }

    public function test_basic_auth_never_appears_in_any_response(): void
    {
        [, $key] = $this->apiClient(['sites:read', 'sites:write']);

        $created = $this->api($key)->postJson('/api/v1/sites', $this->payload())->assertCreated();
        $siteId = $created->json('data.id');

        $bodies = [
            $created->getContent(),
            $this->api($key)->getJson('/api/v1/sites?url=' . urlencode(self::URL))->getContent(),
            $this->api($key)->getJson("/api/v1/sites/{$siteId}")->getContent(),
            $this->api($key)->getJson("/api/v1/sites/{$siteId}/environments")->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString(self::BA_USER, $body);
            $this->assertStringNotContainsString(self::BA_PASSWORD, $body);
            $this->assertStringNotContainsString('basic_auth_password', $body);
        }
    }

    public function test_is_monitored_defaults_to_false(): void
    {
        [, $key] = $this->apiClient(['sites:write']);
        $payload = $this->payload();
        unset($payload['environment']['is_monitored']);

        $this->api($key)->postJson('/api/v1/sites', $payload)
            ->assertCreated()
            ->assertJsonPath('data.environment.is_monitored', false);
    }

    public function test_environment_without_basic_auth_is_allowed(): void
    {
        [, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload(['basic_auth_user' => null, 'basic_auth_password' => null]))
            ->assertCreated()
            ->assertJsonPath('data.environment.has_basic_auth', false);
    }

    public function test_http_url_is_rejected(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload(['url' => 'http://kattsvep.apps.humblebrag.se']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('environment.url');
        $this->assertSame(0, Site::where('team_id', $team->id)->count());
    }

    public function test_basic_auth_requires_both_fields(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload(['basic_auth_password' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('environment.basic_auth_password');
        $this->api($key)->postJson('/api/v1/sites', $this->payload(['basic_auth_user' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('environment.basic_auth_user');
        $this->assertSame(0, Site::where('team_id', $team->id)->count());
    }

    public function test_field_lengths_and_type_are_validated(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload(['basic_auth_user' => str_repeat('a', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors('environment.basic_auth_user');
        $this->api($key)->postJson('/api/v1/sites', $this->payload(['basic_auth_password' => str_repeat('a', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors('environment.basic_auth_password');
        $this->api($key)->postJson('/api/v1/sites', $this->payload(['name' => str_repeat('a', 51)]))
            ->assertStatus(422)->assertJsonValidationErrors('environment.name');
        $this->api($key)->postJson('/api/v1/sites', $this->payload(['type' => 'preview']))
            ->assertStatus(422)->assertJsonValidationErrors('environment.type');
        $this->assertSame(0, Site::where('team_id', $team->id)->count());
    }

    public function test_unknown_or_empty_environment_is_rejected(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload(['cms_password' => 'x']))
            ->assertStatus(422)->assertJsonValidationErrors('environment');

        $payload = $this->payload();
        $payload['environment'] = [];
        $this->api($key)->postJson('/api/v1/sites', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('environment');

        $this->assertSame(0, Site::where('team_id', $team->id)->count());
    }

    public function test_environment_and_credentials_together_is_rejected(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);
        $payload = $this->payload();
        $payload['credentials'] = ['ftp' => ['host' => 'ftp.x.se', 'username' => 'u', 'password' => 'p']];

        $this->api($key)->postJson('/api/v1/sites', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('environment');
        $this->assertSame(0, Site::where('team_id', $team->id)->count());
        $this->assertSame(0, Environment::count());
    }

    public function test_duplicate_url_returns_409_with_existing_site_id(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);
        $existing = Site::factory()->create(['team_id' => $team->id, 'url' => self::URL . '/']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload())
            ->assertStatus(409)
            ->assertExactJson(['error' => 'A site with this URL already exists in the team.', 'site_id' => $existing->id]);
        $this->assertSame(1, Site::where('team_id', $team->id)->count());
    }

    public function test_same_url_in_another_team_is_not_a_duplicate(): void
    {
        [, $key] = $this->apiClient(['sites:write']);
        [$otherTeam] = $this->apiClient(['sites:write']);
        Site::factory()->create(['team_id' => $otherTeam->id, 'url' => self::URL]);

        $this->api($key)->postJson('/api/v1/sites', $this->payload())->assertCreated();
    }

    public function test_failed_environment_insert_leaves_no_site_or_solution(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);
        Environment::creating(fn () => throw new \RuntimeException('INSERT fallerade'));

        $this->api($key)->postJson('/api/v1/sites', $this->payload())->assertStatus(500);

        $this->assertSame(0, Site::where('team_id', $team->id)->count());
        $this->assertSame(0, Solution::where('team_id', $team->id)->count());
    }

    public function test_requires_sites_write(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);

        $this->api($key)->postJson('/api/v1/sites', $this->payload())->assertForbidden();
        $this->assertSame(0, Site::where('team_id', $team->id)->count());
    }

    public function test_without_environment_client_is_not_recorded(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->postJson('/api/v1/sites', ['name' => 'Vanlig', 'url' => 'https://vanlig.se'])
            ->assertCreated()
            ->assertJsonMissingPath('data.environment');

        $site = Site::where('team_id', $team->id)->sole();
        $this->assertSame(0, $site->environments()->count());
        // Utan environment är det inte ett Computercat-Demo-spel: ingen ägare,
        // så PATCH/deactivate ger 403 för varje API-klient (Task 4 och 5).
        $this->assertNull($site->api_client_id);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=CreateSiteWithEnvironmentApiTest
```

Förväntat: FAIL. Bland annat `test_creates_site_with_only_the_demo_environment` (ingen miljö skapas, `api_client_id` saknas som kolumn → SQL-fel), valideringstesterna (201 i stället för 422), `test_duplicate_url_returns_409_with_existing_site_id` (201). `test_requires_sites_write` och `test_same_url_in_another_team_is_not_a_duplicate` kan redan gå igenom.

- [ ] **Steg 3: Migrationen.** `database/migrations/2026_10_13_200000_add_api_client_id_to_sites.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Klienten som skapade webben via POST /sites MED environment
            // (oauth_clients.client_id). Null för webbar skapade i UI:t eller utan
            // environment. Bara den klienten får avaktivera webben eller ändra
            // miljöns övervakning och basic auth via API:t.
            $table->string('api_client_id', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['api_client_id']);
            $table->dropColumn('api_client_id');
        });
    }
};
```

- [ ] **Steg 4: Implementera i `createSite`.** Ersätt valideringsblocket, transaktionen och svarsbygget så att metoden blir:

```php
    public function createSite(Request $request)
    {
        $this->requireScope($request, 'sites:write');
        $teamId = $request->attributes->get('oauth_team_id');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|string|url|max:500',
            'type' => 'nullable|string|in:website,app,webapp',
            'cms_type' => 'nullable|string|max:20',
            'pejl_customer_id' => 'nullable|integer|min:1',
            'credentials.cms.url' => 'nullable|string|url|max:500',
            'credentials.cms.username' => 'nullable|string|max:255',
            'credentials.cms.password' => 'nullable|string|max:500',
            'credentials.ftp.host' => 'nullable|string|max:255',
            'credentials.ftp.username' => 'nullable|string|max:255',
            'credentials.ftp.password' => 'nullable|string|max:500',
            'credentials.ftp.port' => 'nullable|integer|min:1|max:65535',
            // En miljö från anroparen (Computercats Demo-spel). Den blir webbens
            // enda: credentials skapar en Production-miljö och kan inte kombineras.
            'environment' => ['sometimes', 'required', 'array:type,name,url,basic_auth_user,basic_auth_password,is_monitored', 'prohibits:credentials'],
            'environment.type' => ['required_with:environment', 'string', \Illuminate\Validation\Rule::in(array_keys(Environment::TYPES))],
            'environment.name' => ['required_with:environment', 'string', 'max:50'],
            'environment.url' => ['required_with:environment', 'string', 'url:https', 'max:500'],
            'environment.basic_auth_user' => ['nullable', 'string', 'max:255', 'required_with:environment.basic_auth_password'],
            'environment.basic_auth_password' => ['nullable', 'string', 'max:255', 'required_with:environment.basic_auth_user'],
            'environment.is_monitored' => ['sometimes', 'boolean'],
        ]);

        if ($denied = $this->denyPejlCustomer($request, $teamId, $data['pejl_customer_id'] ?? null)) {
            return $denied;
        }

        // Idempotensens andra halva: anroparen slår upp före skapandet, men en
        // omkörning får aldrig ge två webbar för samma Demo-adress.
        if (isset($data['environment'])) {
            $existing = $this->sitesWithExactUrl((int) $teamId, [$data['url'], $data['environment']['url']])->first();
            if ($existing) {
                return response()->json([
                    'error' => 'A site with this URL already exists in the team.',
                    'site_id' => $existing->id,
                ], 409);
            }
        }

        // Transaktion: reservlösningen (BelongsToSolution) skapas före sajtens
        // INSERT och ska inte bli kvar om INSERT fallerar. Kunden skrivs en
        // gång: reservlösningen skapas med webbens värden
        // (SolutionAssigner::createSolution), som då är lösningens
        // (spec 2026-10-07-repositories). Tiern sätts inte här: webben får
        // kolumnens standard (2) och reservlösningen samma (BelongsToSolution).
        // Anroparens miljö skapas i samma transaktion: fallerar den finns
        // varken webb eller lösning kvar.
        [$site, $apiEnvironment] = DB::transaction(function () use ($data, $teamId, $request) {
            $site = Site::create([
                'team_id' => $teamId,
                'name' => $data['name'],
                'url' => $data['url'],
                'type' => $data['type'] ?? 'website',
                'cms_type' => $data['cms_type'] ?? null,
                'pejl_customer_id' => $data['pejl_customer_id'] ?? null,
                'active' => true,
                'monitoring_enabled' => true,
                'ops_mode' => 'supervised',
                // Ägarskap bara för Computercat-Demo-webbar (med environment).
                'api_client_id' => isset($data['environment']) ? $request->attributes->get('oauth_client_id') : null,
            ]);

            $environment = isset($data['environment'])
                ? $this->createApiEnvironment($site, (int) $teamId, $data['environment'])
                : null;

            return [$site, $environment];
        });

        $credentials = $data['credentials'] ?? null;
        $verification = null;

        if ($credentials) {
            $environment = $this->createEmptyProductionEnvironment($site, $teamId);

            // FTP prövas inte från servern (ingen FTP-anslutning härifrån) och
            // lagras som SSH: oprövat, oberoende av CMS-verifieringen.
            $credentials = $this->storeFtpUnverified($environment, $credentials);
        }

        if ($credentials) {
            // Verify credentials in-memory FIRST, before any storage. We do not
            // want unverified credentials sitting in 1Password or in the local
            // encrypted columns — only confirmed-working ones get persisted.
            $verification = $this->verifyCredentials($environment, $credentials);

            if ($verification['verified']) {
                $stored = app(CredentialStorageService::class)->store($environment, $credentials);
                if (!$stored) {
                    // Storage failed (1Password down, etc). Fail loud — roll
                    // back the verified flag and surface a clear error so the
                    // caller knows the credentials are NOT in effect even
                    // though they were verified.
                    $environment->update(['credentials_verified_at' => null]);
                    $verification = [
                        'verified' => false,
                        'cms_verified' => $verification['cms_verified'],
                        'message' => 'Credentials verified but could not be stored — 1Password is unavailable. Try again later.',
                    ];
                }
            }
        }

        $response = $this->formatSiteDetail($site->fresh(), $request);

        if ($apiEnvironment !== null) {
            $response['environment'] = $this->formatApiEnvironment($apiEnvironment->fresh());
        }

        if ($verification !== null) {
            $response['credentials_verification'] = $verification;
        }

        return response()->json([
            'data' => $response,
        ], 201);
    }
```

De två `if ($credentials) { … }`-blocken är oförändrade från i dag; bara transaktionen, valideringen, 409-kontrollen och `environment` i svaret är nya. Variabeln heter `$apiEnvironment` för att inte krocka med credentials-flödets `$environment`.

Lägg till efter `createEmptyProductionEnvironment()`:

```php
    /**
     * Miljön som anroparen skickade med (Computercats Demo-spel). Basic auth
     * krypteras av modellens casts, som när miljöformuläret sparar.
     */
    private function createApiEnvironment(Site $site, int $teamId, array $env): Environment
    {
        return Environment::create([
            'site_id' => $site->id,
            'team_id' => $teamId,
            'name' => $env['name'],
            'type' => $env['type'],
            'url' => $env['url'],
            'sort_order' => 0,
            'is_monitored' => (bool) ($env['is_monitored'] ?? false),
            'basic_auth_user' => $env['basic_auth_user'] ?? null,
            'basic_auth_password' => $env['basic_auth_password'] ?? null,
        ]);
    }

    /**
     * Miljön som API:t visar den efter skapande och ändring. Basic auth lämnas
     * aldrig ut, bara att den finns.
     */
    private function formatApiEnvironment(Environment $env): array
    {
        return [
            'id' => $env->id,
            'site_id' => $env->site_id,
            'name' => $env->name,
            'type' => $env->type,
            'url' => $env->url,
            'is_monitored' => (bool) $env->is_monitored,
            'has_basic_auth' => filled($env->basic_auth_user),
        ];
    }
```

- [ ] **Steg 5: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='CreateSiteWithEnvironmentApiTest|ApiCreateSiteWithCredentialsTest|SolutionAssignmentTest|PejlCustomerApiRuleTest|NoServerSideFtpTest'
```

Förväntat: alla gröna. `test_basic_auth_never_appears_in_any_response` kan falla på `GET /sites/{id}/environments` först i Task 6 om den listan någon gång börjar visa fältet; i dag visar den inte basic auth och testet ska gå igenom redan här.

- [ ] **Steg 6: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add database/migrations/2026_10_13_200000_add_api_client_id_to_sites.php app/Http/Controllers/ApiController.php tests/Feature/Api/CreateSiteWithEnvironmentApiTest.php && git commit -m "feat(api): skapa webb med en Demo-miljö, basic auth och övervakning"
```

---

## Task 4: `PATCH /sites/{id}/environments/{env}`

**Files:**
- Create: `tests/Feature/Api/UpdateSiteEnvironmentApiTest.php`
- Modify: `routes/api.php`, `app/Http/Controllers/ApiController.php` (ny `updateSiteEnvironment`, ny privat `requireCreatedByClient`)

**Interfaces:**
- Consumes: `formatApiEnvironment()` och `sites.api_client_id` (Task 3), `CreatesApiClient` (Task 1).
- Produces (HTTP): kontrakt 3 ovan.
- Produces (PHP): `public function updateSiteEnvironment(Request $request, int $id, int $env)`; `private function requireCreatedByClient(Request $request, Site $site): void` (abort 403). Används av Task 5.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class UpdateSiteEnvironmentApiTest extends TestCase
{
    use CreatesApiClient;

    private const BA_USER = 'anv-2d8e5f';
    private const BA_PASSWORD = 'testvarde-basic-auth';

    /** @return array{0: Site, 1: Environment} */
    private function demoSite(Team $team, string $clientId): array
    {
        $site = Site::factory()->create(['team_id' => $team->id, 'type' => 'app', 'api_client_id' => $clientId]);
        $env = Environment::factory()->forSite($site)->create([
            'type' => 'demo',
            'name' => 'Demo',
            'url' => 'https://kattsvep.apps.humblebrag.se',
            'is_monitored' => false,
            'basic_auth_user' => null,
            'basic_auth_password' => null,
        ]);

        return [$site, $env];
    }

    private function url(Site $site, Environment $env): string
    {
        return "/api/v1/sites/{$site->id}/environments/{$env->id}";
    }

    public function test_sets_basic_auth_and_monitoring(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $env), [
            'basic_auth_user' => self::BA_USER,
            'basic_auth_password' => self::BA_PASSWORD,
            'is_monitored' => true,
        ])->assertOk()->assertExactJson(['data' => [
            'id' => $env->id,
            'site_id' => $site->id,
            'name' => 'Demo',
            'type' => 'demo',
            'url' => 'https://kattsvep.apps.humblebrag.se',
            'is_monitored' => true,
            'has_basic_auth' => true,
        ]]);

        $env->refresh();
        $this->assertSame(self::BA_USER, $env->basic_auth_user);
        $this->assertSame(self::BA_PASSWORD, $env->basic_auth_password);
        $this->assertTrue($env->is_monitored);
        $raw = DB::table('environments')->where('id', $env->id)->first();
        $this->assertStringNotContainsString(self::BA_PASSWORD, (string) $raw->basic_auth_password);
    }

    public function test_patch_response_never_contains_basic_auth(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);

        $body = $this->api($key)->patchJson($this->url($site, $env), [
            'basic_auth_user' => self::BA_USER,
            'basic_auth_password' => self::BA_PASSWORD,
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString(self::BA_USER, $body);
        $this->assertStringNotContainsString(self::BA_PASSWORD, $body);
    }

    public function test_both_null_clears_basic_auth(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);
        $env->update(['basic_auth_user' => 'demo', 'basic_auth_password' => 'demo']);

        $this->api($key)->patchJson($this->url($site, $env), ['basic_auth_user' => null, 'basic_auth_password' => null])
            ->assertOk()->assertJsonPath('data.has_basic_auth', false);
        $this->assertNull($env->fresh()->basic_auth_user);
        $this->assertNull($env->fresh()->basic_auth_password);
    }

    public function test_only_is_monitored_leaves_basic_auth_untouched(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);
        $env->update(['basic_auth_user' => 'demo', 'basic_auth_password' => 'demo']);

        $this->api($key)->patchJson($this->url($site, $env), ['is_monitored' => true])
            ->assertOk()->assertJsonPath('data.is_monitored', true)->assertJsonPath('data.has_basic_auth', true);
        $this->assertSame('demo', $env->fresh()->basic_auth_password);
    }

    public function test_basic_auth_fields_must_come_together(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $env), ['basic_auth_user' => 'demo'])->assertStatus(422);
        $this->api($key)->patchJson($this->url($site, $env), ['basic_auth_password' => 'demo'])->assertStatus(422);
        $this->api($key)->patchJson($this->url($site, $env), ['basic_auth_user' => 'demo', 'basic_auth_password' => null])
            ->assertStatus(422)->assertJsonValidationErrors('basic_auth_password');
        $this->api($key)->patchJson($this->url($site, $env), ['basic_auth_user' => str_repeat('a', 256), 'basic_auth_password' => 'demo'])
            ->assertStatus(422)->assertJsonValidationErrors('basic_auth_user');
        $this->assertNull($env->fresh()->basic_auth_user);
    }

    public function test_empty_body_is_rejected(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $env), [])->assertStatus(422);
    }

    public function test_environment_of_another_site_is_404(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        [$site] = $this->demoSite($team, $client->client_id);
        [, $otherEnv] = $this->demoSite($team, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $otherEnv), ['is_monitored' => true])
            ->assertNotFound()->assertExactJson(['error' => 'Environment not found']);
        $this->assertFalse($otherEnv->fresh()->is_monitored);
    }

    public function test_site_of_another_team_is_404(): void
    {
        [, $key, $client] = $this->apiClient(['sites:write']);
        [$otherTeam] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($otherTeam, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $env), ['is_monitored' => true])
            ->assertNotFound()->assertExactJson(['error' => 'Site not found']);
    }

    public function test_site_created_by_another_client_is_403(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);
        [$site, $env] = $this->demoSite($team, 'api-nagon-annan');
        // Webb utan ägare (skapad i UI:t eller via API utan environment): 403 för alla klienter.
        [$uiSite, $uiEnv] = $this->demoSite($team, 'x');
        $uiSite->update(['api_client_id' => null]);

        $this->api($key)->patchJson($this->url($site, $env), ['is_monitored' => true])->assertForbidden();
        $this->api($key)->patchJson($this->url($uiSite, $uiEnv), ['is_monitored' => true])->assertForbidden();
        $this->assertFalse($env->fresh()->is_monitored);
    }

    public function test_non_numeric_ids_are_404(): void
    {
        [, $key] = $this->apiClient(['sites:write']);

        $this->api($key)->patchJson('/api/v1/sites/abc/environments/1', ['is_monitored' => true])->assertNotFound();
    }

    public function test_requires_sites_write(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:read']);
        [$site, $env] = $this->demoSite($team, $client->client_id);

        $this->api($key)->patchJson($this->url($site, $env), ['is_monitored' => true])->assertForbidden();
        $this->assertFalse($env->fresh()->is_monitored);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=UpdateSiteEnvironmentApiTest
```

Förväntat: FAIL med 405 Method Not Allowed (routen saknas) i alla tester som väntar 200/422/403/404 med JSON-innehåll.

- [ ] **Steg 3: Route.** I `routes/api.php`, i `v1`-gruppen direkt efter `Route::get('/sites/{id}/environments', …);`:

```php
    // Computercats Demo-spel: basic auth och övervakning. Bara klienten som skapade webben.
    Route::patch('/sites/{id}/environments/{env}', [ApiController::class, 'updateSiteEnvironment'])
        ->whereNumber(['id', 'env']);
```

- [ ] **Steg 4: Controller.** Lägg till efter `siteEnvironments()`:

```php
    public function updateSiteEnvironment(Request $request, int $id, int $env)
    {
        $this->requireScope($request, 'sites:write');
        $site = $this->findSite($request, $id);
        if (!$site) {
            return $this->notFound('Site not found');
        }
        $this->requireCreatedByClient($request, $site);

        $environment = $site->environments()->whereKey($env)->first();
        if (!$environment) {
            return $this->notFound('Environment not found');
        }

        $data = $request->validate([
            'basic_auth_user' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:basic_auth_password'],
            'basic_auth_password' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:basic_auth_user'],
            'is_monitored' => ['sometimes', 'boolean'],
        ]);

        // Båda eller ingen: ett ensamt fält hade lämnat ett användarnamn utan
        // lösenord (eller tvärtom), och övervakningen hade fått 401.
        if (array_key_exists('basic_auth_user', $data) !== array_key_exists('basic_auth_password', $data)) {
            return response()->json(['error' => 'basic_auth_user and basic_auth_password must be sent together.'], 422);
        }

        if ($data === []) {
            return response()->json(['error' => 'Nothing to update. Send basic_auth_user + basic_auth_password and/or is_monitored.'], 422);
        }

        // Ändrad åtkomst → omanalys av öppna förslag: EnvironmentAccessObserver.
        $environment->update($data);

        return response()->json([
            'data' => $this->formatApiEnvironment($environment->fresh()),
        ]);
    }
```

Lägg till under `// --- Helpers ---`, efter `sitesWithExactUrl()`:

```php
    /**
     * Ändringar som kan tysta övervakningen (avaktivera, miljöns is_monitored
     * och basic auth) får bara göras av klienten som skapade webben via API:t
     * med environment (Computercats Demo-webbar). En läckt Computercat-nyckel
     * ska inte kunna stänga av bevakningen av kundernas webbar. Webbar utan
     * ägare (api_client_id null: skapade i UI:t eller utan environment) ger 403
     * för alla API-klienter och ändras i UI:t.
     */
    private function requireCreatedByClient(Request $request, Site $site): void
    {
        $clientId = (string) $request->attributes->get('oauth_client_id', '');

        if ($clientId === '' || $site->api_client_id === null || !hash_equals((string) $site->api_client_id, $clientId)) {
            abort(403, 'This site was not created by this API client.');
        }
    }
```

- [ ] **Steg 5: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='UpdateSiteEnvironmentApiTest|ReanalyzeOnAccessChangeTest'
```

Förväntat: alla gröna.

- [ ] **Steg 6: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add routes/api.php app/Http/Controllers/ApiController.php tests/Feature/Api/UpdateSiteEnvironmentApiTest.php && git commit -m "feat(api): ändra miljöns basic auth och övervakning, bara för klienten som skapade webben"
```

---

## Task 5: `POST /sites/{id}/deactivate`

**Files:**
- Create: `tests/Feature/Api/DeactivateSiteApiTest.php`
- Modify: `routes/api.php`, `app/Http/Controllers/ApiController.php` (ny `deactivateSite`)

**Interfaces:**
- Consumes: `requireCreatedByClient()` (Task 4), `sites.api_client_id` (Task 3).
- Produces (HTTP): kontrakt 4 ovan: 200 `{"data":{"id":int,"is_active":false,"monitoring_enabled":false}}`, 404, 403.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\Site;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class DeactivateSiteApiTest extends TestCase
{
    use CreatesApiClient;

    public function test_deactivates_without_deleting_anything(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        $site = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => $client->client_id]);
        $env = Environment::factory()->forSite($site)->create(['type' => 'demo', 'is_monitored' => true]);

        $this->api($key)->postJson("/api/v1/sites/{$site->id}/deactivate")
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $site->id, 'is_active' => false, 'monitoring_enabled' => false]]);

        $site->refresh();
        $this->assertFalse($site->active);
        $this->assertFalse($site->monitoring_enabled);
        $this->assertNotNull(Site::find($site->id));
        $this->assertNotNull(Environment::find($env->id));
    }

    public function test_is_idempotent(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        $site = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => $client->client_id]);

        $this->api($key)->postJson("/api/v1/sites/{$site->id}/deactivate")->assertOk();
        $this->api($key)->postJson("/api/v1/sites/{$site->id}/deactivate")
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_site_of_another_team_is_404(): void
    {
        [, $key, $client] = $this->apiClient(['sites:write']);
        [$otherTeam] = $this->apiClient(['sites:write']);
        $site = Site::factory()->create(['team_id' => $otherTeam->id, 'api_client_id' => $client->client_id]);

        $this->api($key)->postJson("/api/v1/sites/{$site->id}/deactivate")
            ->assertNotFound()->assertExactJson(['error' => 'Site not found']);
        $this->assertTrue($site->fresh()->active);
    }

    public function test_site_created_by_another_client_is_403(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);
        $customerSite = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => null]);
        $otherClientSite = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => 'api-nagon-annan']);

        $this->api($key)->postJson("/api/v1/sites/{$customerSite->id}/deactivate")->assertForbidden();
        $this->api($key)->postJson("/api/v1/sites/{$otherClientSite->id}/deactivate")->assertForbidden();
        $this->assertTrue($customerSite->fresh()->active);
        $this->assertTrue($customerSite->fresh()->monitoring_enabled);
        $this->assertTrue($otherClientSite->fresh()->active);
    }

    public function test_site_created_without_environment_by_same_client_is_403(): void
    {
        [$team, $key] = $this->apiClient(['sites:write']);

        $siteId = $this->api($key)->postJson('/api/v1/sites', ['name' => 'Vanlig', 'url' => 'https://vanlig.se'])
            ->assertCreated()->json('data.id');

        $this->api($key)->postJson("/api/v1/sites/{$siteId}/deactivate")->assertForbidden();
        $this->assertTrue(Site::findOrFail($siteId)->active);
        $this->assertTrue(Site::findOrFail($siteId)->monitoring_enabled);
    }

    public function test_requires_sites_write(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:read']);
        $site = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => $client->client_id]);

        $this->api($key)->postJson("/api/v1/sites/{$site->id}/deactivate")->assertForbidden();
        $this->assertTrue($site->fresh()->active);
    }

    public function test_there_is_no_delete_route(): void
    {
        [$team, $key, $client] = $this->apiClient(['sites:write']);
        $site = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => $client->client_id]);

        $this->api($key)->deleteJson("/api/v1/sites/{$site->id}")->assertStatus(405);
        $this->assertNotNull(Site::find($site->id));
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=DeactivateSiteApiTest
```

Förväntat: routen saknas, så varje POST ger Laravels 404. FAIL i `test_deactivates_without_deleting_anything`, `test_is_idempotent`, `test_site_created_by_another_client_is_403`, `test_site_created_without_environment_by_same_client_is_403`, `test_requires_sites_write` (404 i stället för 200/403) och `test_site_of_another_team_is_404` (kroppen är `{"message":…}`, inte `{"error":"Site not found"}`). `test_there_is_no_delete_route` går redan igenom (405, eftersom `GET /sites/{id}` finns) och ska fortsätta göra det.

- [ ] **Steg 3: Route.** I `routes/api.php`, direkt efter PATCH-routen från Task 4:

```php
    // Arkivering av ett Demo-spel: avaktivera, aldrig radera. Bara klienten som skapade webben.
    Route::post('/sites/{id}/deactivate', [ApiController::class, 'deactivateSite'])
        ->whereNumber('id');
```

- [ ] **Steg 4: Controller.** Lägg till efter `updateSiteEnvironment()`:

```php
    /**
     * Arkivera i stället för att radera, som webbens "Inaktivera" i UI:t, men
     * här stängs även övervakningen av: ett arkiverat Demo-spel ska inte larma.
     */
    public function deactivateSite(Request $request, int $id)
    {
        $this->requireScope($request, 'sites:write');
        $site = $this->findSite($request, $id);
        if (!$site) {
            return $this->notFound('Site not found');
        }
        $this->requireCreatedByClient($request, $site);

        $site->update(['active' => false, 'monitoring_enabled' => false]);

        return response()->json([
            'data' => [
                'id' => $site->id,
                'is_active' => (bool) $site->active,
                'monitoring_enabled' => (bool) $site->monitoring_enabled,
            ],
        ]);
    }
```

- [ ] **Steg 5: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=DeactivateSiteApiTest
```

Förväntat: alla gröna.

- [ ] **Steg 6: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add routes/api.php app/Http/Controllers/ApiController.php tests/Feature/Api/DeactivateSiteApiTest.php && git commit -m "feat(api): avaktivera webb via API, ingen radering"
```

---

## Task 6: Läsytor för 2c:s invariant

2c:s nattliga invariant ska se att "SiteOps-sajten är aktiv och övervakad". `monitoring_enabled` och miljöns `type` syns inte i något läs-API i dag.

**Files:**
- Create: `tests/Feature/Api/SiteReadSurfacesApiTest.php`
- Modify: `app/Http/Controllers/ApiController.php` (`siteEnvironments`, `formatSiteDetail`)

**Interfaces:**
- Produces (HTTP): `GET /api/v1/sites/{id}` → `data.monitoring_enabled: bool` (även i `POST /sites`-svaret, som använder samma detalj). `GET /api/v1/sites/{id}/environments` → varje post får `type: string` och `has_basic_auth: bool`.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\Site;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class SiteReadSurfacesApiTest extends TestCase
{
    use CreatesApiClient;

    public function test_site_detail_shows_monitoring_enabled(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        $site = Site::factory()->create(['team_id' => $team->id, 'monitoring_enabled' => false]);

        $this->api($key)->getJson("/api/v1/sites/{$site->id}")
            ->assertOk()->assertJsonPath('data.monitoring_enabled', false);
    }

    public function test_environments_list_has_type_and_flag_but_no_credentials(): void
    {
        [$team, $key] = $this->apiClient(['sites:read']);
        $site = Site::factory()->create(['team_id' => $team->id]);
        Environment::factory()->forSite($site)->create([
            'type' => 'demo',
            'basic_auth_user' => 'anv-5b6c7d',
            'basic_auth_password' => 'losen-1e2f3a',
        ]);

        $res = $this->api($key)->getJson("/api/v1/sites/{$site->id}/environments")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'demo')
            ->assertJsonPath('data.0.has_basic_auth', true);

        $this->assertStringNotContainsString('anv-5b6c7d', $res->getContent());
        $this->assertStringNotContainsString('losen-1e2f3a', $res->getContent());
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=SiteReadSurfacesApiTest
```

Förväntat: FAIL, `data.monitoring_enabled` och `data.0.type` saknas.

- [ ] **Steg 3: Implementera.** I `siteEnvironments()`, i `map`-arrayen efter `'name' => $env->name,`:

```php
                'type' => $env->type,
```

och efter `'is_monitored' => $env->is_monitored,`:

```php
                // Bara att basic auth finns, aldrig värdena (övervakningen behöver dem, API:t inte).
                'has_basic_auth' => filled($env->basic_auth_user),
```

I `formatSiteDetail()`, efter `...$this->formatSiteListItem($site, $request),`:

```php
            // Avaktivering via API:t stänger av den; 2c:s invariant läser den här.
            'monitoring_enabled' => (bool) $site->monitoring_enabled,
```

- [ ] **Steg 4: Kör och se det gå igenom, plus allt som läser samma svar.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='SiteReadSurfacesApiTest|SiteSubResourceApiTest|SiteOpsApiExpansionTest|SitesEndpoint|SiteStatusExposeCasesTest|CreateSiteWithEnvironmentApiTest'
```

Förväntat: alla gröna.

- [ ] **Steg 5: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add app/Http/Controllers/ApiController.php tests/Feature/Api/SiteReadSurfacesApiTest.php && git commit -m "feat(api): visa monitoring_enabled och miljöns typ i läs-API:t"
```

---

## Task 7: `api-clients:create` för Computercats nyckel

I dag finns inget sätt att skapa en maskinnyckel med egna scopes och team: DCR (`/oauth/register`) skapar klienter utan team, och desktop-grant är knuten till en användare. Kommandot gör det i ett steg, hashar nyckeln och visar den en gång.

**Files:**
- Create: `app/Console/Commands/CreateApiClient.php`
- Create: `tests/Feature/Api/CreateApiClientCommandTest.php`
- Modify: `app/Models/OAuthClient.php` (konstant `SUPPORTED_SCOPES`), `app/Http/Controllers/OAuthController.php` (`metadata()` läser konstanten)

**Interfaces:**
- Produces (CLI): `php artisan api-clients:create {name} --team=<id> --scope=<scope> [--scope=<scope> …]` → exit 0 och raden `API-nyckel: <64 hex>`; exit 1 vid okänt team, inget scope, `*` eller okänt scope.
- Produces (PHP): `OAuthClient::SUPPORTED_SCOPES` (`list<string>`), samma tio scopes som `metadata()` listar i dag.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\OAuthClient;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateApiClientCommandTest extends TestCase
{
    private function team(): Team
    {
        $owner = User::factory()->create();

        return Team::factory()->create(['owner_id' => $owner->id]);
    }

    public function test_creates_client_with_exact_scopes_and_shows_key_once(): void
    {
        $team = $this->team();

        $exit = Artisan::call('api-clients:create', [
            'name' => 'Computercat',
            '--team' => $team->id,
            '--scope' => ['sites:read', 'sites:write'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, preg_match('/API-nyckel: ([0-9a-f]{64})/', Artisan::output(), $m));
        $rawKey = $m[1];

        $client = OAuthClient::where('name', 'Computercat')->sole();
        $this->assertSame(['sites:read', 'sites:write'], $client->scopes);
        $this->assertSame($team->id, $client->team_id);
        $this->assertSame(hash('sha256', $rawKey), $client->client_secret_hash);
        $this->assertFalse($client->expose_cases);
        $this->assertNull($client->user_id);
        $this->assertStringStartsWith('api-', $client->client_id);
        $this->assertFalse($client->isDesktopClient());

        // Nyckeln når webbarna men inte ärendena.
        $this->withHeaders(['X-Api-Key' => $rawKey])->getJson('/api/v1/sites')->assertOk();
        $this->withHeaders(['X-Api-Key' => $rawKey])->getJson('/api/v1/cases')->assertForbidden();
    }

    public function test_rejects_wildcard_scope(): void
    {
        $exit = Artisan::call('api-clients:create', ['name' => 'X', '--team' => $this->team()->id, '--scope' => ['*']]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, OAuthClient::count());
    }

    public function test_rejects_unknown_scope(): void
    {
        $exit = Artisan::call('api-clients:create', ['name' => 'X', '--team' => $this->team()->id, '--scope' => ['sites:read', 'sites:delete']]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, OAuthClient::count());
    }

    public function test_rejects_missing_scope_or_team(): void
    {
        $this->assertSame(1, Artisan::call('api-clients:create', ['name' => 'X', '--team' => $this->team()->id]));
        $this->assertSame(1, Artisan::call('api-clients:create', ['name' => 'X', '--team' => 999999, '--scope' => ['sites:read']]));
        $this->assertSame(1, Artisan::call('api-clients:create', ['name' => 'X', '--scope' => ['sites:read']]));
        $this->assertSame(0, OAuthClient::count());
    }

    public function test_metadata_lists_the_same_scopes(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('scopes_supported', OAuthClient::SUPPORTED_SCOPES);
        $this->assertSame(
            ['sites:read', 'sites:write', 'cases:read', 'cases:write', 'summary:read', 'settings:read', 'settings:write', 'updates:read', 'updates:write', 'intake:write'],
            OAuthClient::SUPPORTED_SCOPES,
        );
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=CreateApiClientCommandTest
```

Förväntat: FAIL, `CommandNotFoundException` ("There are no commands defined in the "api-clients" namespace") och `Undefined constant App\Models\OAuthClient::SUPPORTED_SCOPES`.

- [ ] **Steg 3: Konstanten.** I `app/Models/OAuthClient.php`, efter `protected $table = 'oauth_clients';`:

```php
    /**
     * Alla scopes servern känner till. Källan för OAuth-metadatan och för
     * api-clients:create. * är inget scope här: maskinklienter får uttryckliga.
     */
    public const SUPPORTED_SCOPES = [
        'sites:read', 'sites:write',
        'cases:read', 'cases:write',
        'summary:read',
        'settings:read', 'settings:write',
        'updates:read', 'updates:write',
        'intake:write',
    ];
```

I `OAuthController::metadata()` ersätts raden `'scopes_supported' => [...]` med:

```php
            'scopes_supported' => OAuthClient::SUPPORTED_SCOPES,
```

- [ ] **Steg 4: Kommandot.** `app/Console/Commands/CreateApiClient.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\OAuthClient;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Maskinnyckel (X-Api-Key) för en tjänst, t.ex. Computercat. Nyckeln hashas
 * och visas bara här; den går inte att läsa ut i efterhand.
 */
class CreateApiClient extends Command
{
    protected $signature = 'api-clients:create
        {name : Klientens namn, t.ex. Computercat}
        {--team= : Teamets id}
        {--scope=* : Ett scope per flagga, t.ex. --scope=sites:read --scope=sites:write. * godtas inte}';

    protected $description = 'Skapar en API-klient med uttryckliga scopes och visar nyckeln en gång';

    public function handle(): int
    {
        $team = $this->option('team') !== null ? Team::find($this->option('team')) : null;
        if (!$team) {
            $this->error('Teamet finns inte. Ange --team=<id>.');

            return self::FAILURE;
        }

        $scopes = array_values(array_unique($this->option('scope')));
        if ($scopes === []) {
            $this->error('Ange minst ett --scope.');

            return self::FAILURE;
        }

        $unknown = array_diff($scopes, OAuthClient::SUPPORTED_SCOPES);
        if ($unknown !== []) {
            $this->error('Okända scopes: ' . implode(', ', $unknown) . '. * godtas inte; ange scopen uttryckligen.');

            return self::FAILURE;
        }

        $rawKey = bin2hex(random_bytes(32));
        $client = OAuthClient::create([
            'name' => $this->argument('name'),
            'client_id' => 'api-' . bin2hex(random_bytes(16)),
            'client_secret_hash' => hash('sha256', $rawKey),
            'team_id' => $team->id,
            'scopes' => $scopes,
            'expose_cases' => false,
            'is_first_party' => false,
            'is_active' => true,
        ]);

        $this->info("Klienten {$client->client_id} skapad för teamet {$team->name} med " . implode(' ', $scopes) . '.');
        $this->line("API-nyckel: {$rawKey}");
        $this->warn('Nyckeln visas bara nu. Lägg den direkt i mottagarens .env, aldrig i git, chatt eller ärenden.');

        return self::SUCCESS;
    }
}
```

- [ ] **Steg 5: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='CreateApiClientCommandTest|OAuth|DesktopKeyIdentityTest'
```

Förväntat: alla gröna.

- [ ] **Steg 6: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add app/Console/Commands/CreateApiClient.php app/Models/OAuthClient.php app/Http/Controllers/OAuthController.php tests/Feature/Api/CreateApiClientCommandTest.php && git commit -m "feat(api): api-clients:create skapar maskinnyckel med uttryckliga scopes"
```

**Efter deploy (ingår inte i planen, kräver Eriks OK enligt produktionsregeln):** på SiteOps-servern `php artisan migrate`, sedan `php artisan api-clients:create Computercat --team=<Humblebrags team-id> --scope=sites:read --scope=sites:write`. Team-id slås upp först (`php artisan tinker --execute="echo App\\Models\\Team::where('name','like','Humblebrag%')->get(['id','name'])"`). Kommandot skriver ut nyckeln en gång; därför kör **Erik** det i sin egen terminal och lägger nyckeln direkt i 1Password/Forge (Computercats `.env` som `SITEOPS_API_KEY`, spec 2c förutsättning 6-7), aldrig via Claude eller ett transkript.

## Task 7b: `api-clients:rotate` för nyckelbyte

Nyckeln får inte bytas genom att skapa en ny klient: `sites.api_client_id` binder ägarskapet till `client_id`, och en ny klient ger 403 på alla befintliga Demo-webbar. Rotationen byter därför bara `client_secret_hash` på samma rad.

**Files:**
- Create: `app/Console/Commands/RotateApiClient.php`
- Create: `tests/Feature/Api/RotateApiClientCommandTest.php`

**Interfaces:**
- Consumes: `CreatesApiClient` (Task 1), `sites.api_client_id` (Task 3).
- Produces (CLI): `php artisan api-clients:rotate {client_id} [--force]` → frågar om bekräftelse (hoppas över bara med `--force`, för tester och skript), byter `client_secret_hash` på samma rad, skriver `API-nyckel: <64 hex>` en gång; den gamla nyckeln ger 401 direkt. Exit 1 vid okänd, inaktiv eller icke-maskinklient (bara `client_id` som börjar med `api-` och utan `user_id`) och när bekräftelsen nekas.

- [ ] **Steg 1: Skriv det fallerande testet.**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Environment;
use App\Models\OAuthClient;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesApiClient;
use Tests\TestCase;

class RotateApiClientCommandTest extends TestCase
{
    use CreatesApiClient;

    private function rotate(string $clientId): string
    {
        $exit = Artisan::call('api-clients:rotate', ['client_id' => $clientId, '--force' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, preg_match('/API-nyckel: ([0-9a-f]{64})/', Artisan::output(), $m));

        return $m[1];
    }

    public function test_old_key_stops_working_and_new_key_works(): void
    {
        [, $oldKey, $client] = $this->apiClient(['sites:read', 'sites:write']);
        $this->api($oldKey)->getJson('/api/v1/sites')->assertOk();

        $newKey = $this->rotate($client->client_id);

        $this->assertNotSame($oldKey, $newKey);
        $this->api($oldKey)->getJson('/api/v1/sites')->assertUnauthorized();
        $this->api($newKey)->getJson('/api/v1/sites')->assertOk();
    }

    public function test_rotation_keeps_client_id_so_ownership_survives(): void
    {
        [$team, $oldKey, $client] = $this->apiClient(['sites:read', 'sites:write']);
        $created = $this->api($oldKey)->postJson('/api/v1/sites', [
            'name' => 'Kattsvep',
            'url' => 'https://kattsvep.apps.humblebrag.se',
            'type' => 'app',
            'environment' => ['type' => 'demo', 'name' => 'Demo', 'url' => 'https://kattsvep.apps.humblebrag.se'],
        ])->assertCreated();
        $siteId = $created->json('data.id');
        $envId = $created->json('data.environment.id');

        $newKey = $this->rotate($client->client_id);

        $this->assertSame(1, OAuthClient::count());
        $fresh = $client->fresh();
        $this->assertSame($client->client_id, $fresh->client_id);
        $this->assertSame($client->id, $fresh->id);
        $this->assertSame(['sites:read', 'sites:write'], $fresh->scopes);
        $this->assertSame($team->id, $fresh->team_id);
        $this->assertSame(hash('sha256', $newKey), $fresh->client_secret_hash);
        $this->assertFalse($fresh->expose_cases);

        // Samma klient: nya nyckeln äger fortfarande sina webbar.
        $this->api($newKey)->patchJson("/api/v1/sites/{$siteId}/environments/{$envId}", ['is_monitored' => true])->assertOk();
        $this->api($newKey)->postJson("/api/v1/sites/{$siteId}/deactivate")->assertOk();
    }

    public function test_other_clients_and_customer_sites_stay_403_after_rotation(): void
    {
        [$team, , $client] = $this->apiClient(['sites:read', 'sites:write']);
        [, $otherKey, $other] = $this->apiClient(['sites:read', 'sites:write'], $team);
        $newKey = $this->rotate($client->client_id);

        $ownedByOther = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => $other->client_id]);
        $customerSite = Site::factory()->create(['team_id' => $team->id, 'api_client_id' => null]);
        $env = Environment::factory()->forSite($customerSite)->create(['type' => 'demo']);

        $this->api($newKey)->postJson("/api/v1/sites/{$ownedByOther->id}/deactivate")->assertForbidden();
        $this->api($newKey)->postJson("/api/v1/sites/{$customerSite->id}/deactivate")->assertForbidden();
        $this->api($newKey)->patchJson("/api/v1/sites/{$customerSite->id}/environments/{$env->id}", ['is_monitored' => true])->assertForbidden();
        // Den andra klientens nyckel rördes inte av rotationen.
        $this->api($otherKey)->postJson("/api/v1/sites/{$ownedByOther->id}/deactivate")->assertOk();
        $this->assertTrue($customerSite->fresh()->active);
    }

    public function test_declined_confirmation_changes_nothing(): void
    {
        [, $oldKey, $client] = $this->apiClient(['sites:read']);
        $hash = $client->client_secret_hash;

        $this->artisan('api-clients:rotate', ['client_id' => $client->client_id])
            ->expectsConfirmation('Byta nyckel för ' . $client->client_id . '? Den gamla nyckeln slutar fungera direkt.', 'no')
            ->assertExitCode(1);

        $this->assertSame($hash, $client->fresh()->client_secret_hash);
        $this->api($oldKey)->getJson('/api/v1/sites')->assertOk();
    }

    public function test_accepted_confirmation_rotates(): void
    {
        [, $oldKey, $client] = $this->apiClient(['sites:read']);

        $this->artisan('api-clients:rotate', ['client_id' => $client->client_id])
            ->expectsConfirmation('Byta nyckel för ' . $client->client_id . '? Den gamla nyckeln slutar fungera direkt.', 'yes')
            ->expectsOutputToContain('API-nyckel: ')
            ->assertExitCode(0);

        $this->api($oldKey)->getJson('/api/v1/sites')->assertUnauthorized();
    }

    public function test_refuses_unknown_inactive_and_non_machine_clients(): void
    {
        [, $key, $inactive] = $this->apiClient(['sites:read']);
        $inactive->update(['is_active' => false]);
        $desktop = OAuthClient::create([
            'name' => 'Desktop', 'client_id' => 'desktop-abc', 'client_secret_hash' => hash('sha256', 'd'),
            'team_id' => $inactive->team_id, 'scopes' => ['sites:read'], 'is_active' => true,
        ]);

        $this->assertSame(1, Artisan::call('api-clients:rotate', ['client_id' => 'api-finns-inte', '--force' => true]));
        $this->assertSame(1, Artisan::call('api-clients:rotate', ['client_id' => $inactive->client_id, '--force' => true]));
        $this->assertSame(1, Artisan::call('api-clients:rotate', ['client_id' => $desktop->client_id, '--force' => true]));

        $this->assertSame(hash('sha256', $key), $inactive->fresh()->client_secret_hash);
        $this->assertSame(hash('sha256', 'd'), $desktop->fresh()->client_secret_hash);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter=RotateApiClientCommandTest
```

Förväntat: FAIL, alla 6 tester med `CommandNotFoundException` (kommandot `api-clients:rotate` finns inte).

- [ ] **Steg 3: Kommandot.** `app/Console/Commands/RotateApiClient.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\OAuthClient;
use Illuminate\Console\Command;

/**
 * Byter nyckeln på en befintlig maskinklient. Samma rad, samma client_id:
 * sites.api_client_id (ägarskapet till Demo-webbarna) pekar på client_id, så
 * en ny klient hade gett 403 på alla befintliga webbar. Den gamla nyckeln
 * ogiltigförklaras av samma UPDATE; det finns inget överlappsfönster.
 */
class RotateApiClient extends Command
{
    protected $signature = 'api-clients:rotate
        {client_id : Klientens client_id, t.ex. api-1a2b...}
        {--force : Hoppa över bekräftelsen (tester och skript; Erik kör utan)}';

    protected $description = 'Byter nyckel för en API-klient (samma client_id), visar den nya nyckeln en gång';

    public function handle(): int
    {
        $client = OAuthClient::where('client_id', $this->argument('client_id'))->first();

        // Bara maskinnycklar från api-clients:create. Desktop- och DCR-klienter
        // har egna flöden och ska inte kunna roteras härifrån.
        if (!$client || !str_starts_with($client->client_id, 'api-') || $client->user_id !== null) {
            $this->error('Ingen API-klient med det client_id.');

            return self::FAILURE;
        }

        if (!$client->is_active) {
            $this->error('Klienten är inaktiv. Aktivera den först om nyckeln ska bytas.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm("Byta nyckel för {$client->client_id}? Den gamla nyckeln slutar fungera direkt.", false)) {
            $this->warn('Avbrutet. Ingenting ändrades.');

            return self::FAILURE;
        }

        $rawKey = bin2hex(random_bytes(32));
        $client->update(['client_secret_hash' => hash('sha256', $rawKey)]);

        $this->info("Nyckeln för {$client->client_id} ({$client->name}) är bytt. Den gamla är ogiltig.");
        $this->line("API-nyckel: {$rawKey}");
        $this->warn('Nyckeln visas bara nu. Lägg den direkt i 1Password/Forge, aldrig i git, chatt eller ärenden.');

        return self::SUCCESS;
    }
}
```

- [ ] **Steg 4: Kör och se det gå igenom.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test --filter='RotateApiClientCommandTest|CreateApiClientCommandTest|UpdateSiteEnvironmentApiTest|DeactivateSiteApiTest'
```

Förväntat: alla gröna.

- [ ] **Steg 5: Commit.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && git add app/Console/Commands/RotateApiClient.php tests/Feature/Api/RotateApiClientCommandTest.php && git commit -m "feat(api): api-clients:rotate byter nyckel utan att byta klient"
```

**Runbook, nyckelrotation (produktion, kräver Eriks OK):** Erik kör `php artisan api-clients:rotate <client_id>` på SiteOps-servern i sin egen terminal, svarar yes, och lägger den utskrivna nyckeln direkt i 1Password och Forge (Computercats `SITEOPS_API_KEY`), aldrig via Claude eller ett transkript. Den gamla nyckeln är ogiltig i samma ögonblick, så ordningen är: kör kommandot, uppdatera Forge-miljövariabeln, deploya/starta om Computercat. Mellan stegen får Computercats SiteOps-anrop 401 och 2c:s saga stannar synligt (fail loud) tills nyckeln är på plats; kör det därför när ingen provisionering pågår. `client_id` hittas med `php artisan tinker --execute="echo App\\Models\\OAuthClient::where('name','Computercat')->value('client_id')"`.

---

## Task 8: Hela sviten, stil och grind

**Files:** inga nya.

- [ ] **Steg 1: Pint på ändrade filer.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && vendor/bin/pint --dirty
```

Commit om något ändrades: `git commit -am "style(api): pint"`.

- [ ] **Steg 2: Hela sviten.**

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && php artisan test
```

Förväntat: grönt. Ett rött test som läser `formatSiteDetail` eller miljölistan med exakt JSON: uppdatera det testet bara om det nya fältet (`monitoring_enabled`, `type`, `has_basic_auth`) är enda skillnaden; annars stanna och fråga.

- [ ] **Steg 3: Klass-svep.** Varje väg som serialiserar en `Environment` i API:t ska sakna basic auth:

```bash
cd /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo && grep -rn "basic_auth" app/Http/Controllers app/Http/Resources 2>/dev/null
```

Förväntat: träffar bara i valideringsreglerna, `createApiEnvironment`, `formatApiEnvironment` (`filled(...)`), `siteEnvironments` (`filled(...)`) och `updateSiteEnvironment`. Ingen `->toArray()`/`response()->json($environment)` på en miljö.

- [ ] **Steg 4: Säkerhetsgranskning och grind.** Invokera `security-first-mindset` (rör auth, scopes, credentials) och läs diffen `git diff main...HEAD` med en angripares ögon: kan en `sites:write`-nyckel som inte skapade webben ändra den, läcker något svar basic auth, kan `environment` smuggla in andra kolumner (nej: `array:`-regeln och explicita fält i `createApiEnvironment`). Kör sedan `completion-gate`.

- [ ] **Steg 5: Avslut efter Eriks OK.** Fråga Erik (AskUserQuestion) om branchen ska mergas lokalt och om worktreens databas ska tas bort. Efter OK:

```bash
~/.claude/bin/wt-finish /Users/erik/Web/siteops/.claude/worktrees/feat-api-demo-miljo --drop-db
```

Ingen push, ingen deploy: `wt-finish` pushar aldrig, och live-gång kräver Eriks uttryckliga order.
