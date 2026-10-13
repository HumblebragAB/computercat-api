# Spelplattformen delprojekt 2c: provisionering i Computercat — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `create_game(slug, name, idea)` i Computercats MCP-server skapar på egen hand ett privat repo från `HumblebragAB/spelmall`, ett aktivt men olistat spel, en Coolify-app med basic auth på `https://<slug>.apps.humblebrag.se`, en övervakad SiteOps-sajt och en första deploy, och Kattsvep byggs från idé till spelbar Demo av två Claude Code-routines utan att någon människa klickar i GitHub, Coolify eller SiteOps.

**Architecture:** Ett MCP-verktyg reserverar slugen i en `provisioning_runs`-rad och köar jobbet `AdvanceProvisioningRun`. Jobbet kör ett steg i taget ur en fast lista (`Saga::PROVISION_STEPS` eller `Saga::ARCHIVE_STEPS`); varje steg är idempotent, svarar `done`, `wait(n)` (jobbet släpps med `release(n)`) eller `releaseSlug`, och ett fel stoppar sagan med `failed`, redigerat `last_error` och Slack-larm. Tre tunna HTTP-klienter (`GitHubAppClient`, `CoolifyClient`, `SiteOpsClient`) delar en anropsmetod som loggar 4xx/5xx redigerat och kastar `ExternalApiException`. En nattlig invariant (`computercat:check-provisioning`) jämför lyckade runs mot GitHub, Coolify, SiteOps och Demo-adressen.

**Tech Stack:** Laravel 12, PHP 8.4 i prod (8.2+ i composer), laravel/mcp 1.0.2, Filament 3, lcobucci/jwt 5.6 (RS256), databaskö (`QUEUE_CONNECTION=database`), databascache, PHPUnit 11 (PHPUnit-klasser, inte Pest), SQLite i minnet för test.

**Spec:** `docs/superpowers/specs/2026-10-13-spelplattform-provisionering-design.md`, avsnitten "Förutsättningar", "Ej verifierat … spike", "2c. Provisionering i Computercat" och "Acceptans: Kattsvep (2d)", med bilagorna `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (A) och `docs/superpowers/specs/bilagor/2026-10-13-github-app.md` (B). Syskonplanerna för 2a (SiteOps API) och 2b (spelmall) är beroenden; deras kontrakt återges under Global Constraints.

**Beroenden och ordning:** Task 0 (förutsättningar) först. Task 1 (spike) får inte påbörjas förrän (1) 2a (`docs/superpowers/plans/2026-10-13-provisionering-2a-siteops.md`) är mergad och **deployad till SiteOps-produktion med migrationen**, och Computercats SiteOps-nyckel är skapad med `api-clients:create`, och (2) 2b (`…-2b-spelmall.md`) är klar och dess Task 12 gjord: `HumblebragAB/spelmall` finns, privat, `is_template: true`, med idé-markörerna, och är tillagd i GitHub App-installationen. Båda kräver Eriks OK i respektive plan. Task 2 och framåt bygger på spikens resultat. Ordningen över planerna: förutsättningarna → 2a och 2b (parallellt) → deploy av 2a + push av mallen → 2c Task 1 → 2c Task 2–23 → 2d (Task 24).

---

## Global Constraints

**Arbetssätt**
- Implementationen görs i en ny worktree: `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/provisionering --db`. Sista raden på stdout är sökvägen, nedan `$WT` (väntat: `/Users/erik/Web/computercat-api/.claude/worktrees/feat-provisionering`). Absoluta sökvägar, huvudkatalogen rörs aldrig, `EnterWorktree` används inte.
- Alla kommandon som kör testsviten körs som `cd $WT && php artisan test ...` (phpunit.xml läses från arbetskatalogen).
- Commit-meddelanden på svenska, utan Co-Authored-By-rader. Kommentarer och artisan-utskrifter på svenska; MCP-verktygens svar och felmeddelanden på engelska (som resten av API:t).
- Ingen åtgärd mot GitHub, Coolify, SiteOps, Slack, Forge eller prod-servern utan Eriks uttryckliga OK via AskUserQuestion, med undantag för det Erik redan godkänt i Task 0 och Task 1. Deploy (Task 23) kräver eget OK.
- `security-first-mindset` invokeras i början av Task 2 (hotmodell) och i Task 22 (review gate). `completion-gate` passeras i Task 22 innan något kallas klart.
- Stor lansering enligt CLAUDE.md: nytt subsystem plus första data-muterande operation mot riktiga tjänster. Ordningen är deploy (Task 23) → Kattsvep-acceptans med invarianten grön (Task 24) → först därefter informeras Mattias, och bara på Eriks beslut.

**Externa API:er (exakt så här, inget gissat)**
- GitHub, bas `https://api.github.com`, headers `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, `Authorization: Bearer <token>`.
  - JWT: RS256, `iss` = `GITHUB_APP_CLIENT_ID`, `iat` = nu − 60 s, `exp` = nu + 9 min (krav ≤ 10 min). Heltalstidsstämplar.
  - `POST /app/installations/{GITHUB_APP_INSTALLATION_ID}/access_tokens` med JWT, utan body. Svar 201 `{token, expires_at, permissions, repository_selection, repositories}`. Token giltig 1 h; cachas krypterad till `expires_at − 5 min`, glöms efter varje `/generate`.
  - `POST /repos/HumblebragAB/spelmall/generate` body `{owner: "HumblebragAB", name: <slug>, description: "Computercat-spel <slug> (run <uuid>)", private: true, include_all_branches: false}`. 201 vid lyckat, 422 hanteras.
  - `GET /repos/HumblebragAB/<slug>` (fälten `id`, `description`, `archived`, `template_repository.full_name` verifieras i spiken, S10).
  - `GET /repos/HumblebragAB/<slug>/contents/README.md` → `{sha, content, encoding: "base64"}`; `PUT` samma sökväg med `{message, content (base64), sha}` (verifieras i spiken, S11).
  - `PATCH /repos/HumblebragAB/<slug>` body `{archived: true}`.
  - **Aldrig `DELETE` mot `api.github.com`.** `GitHubAppClient::ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH']`.
- Coolify v4.4.6, bas `{COOLIFY_URL}/api/v1`, `Authorization: Bearer {COOLIFY_TOKEN}`.
  - `POST /applications/private-github-app` body exakt: `project_uuid`, `server_uuid`, `environment_name`, `github_app_uuid`, `git_repository` (`HumblebragAB/<slug>`, format verifieras i spiken S1), `git_branch: "main"`, `build_pack: "nixpacks"`, `name: "spel-<slug>"`, `domains: "https://<slug>.apps.humblebrag.se"`, `ports_exposes: "80"` (S2), `is_static: true`, `static_image: "nginx:alpine"`, `publish_directory: "out"`, `is_auto_deploy_enabled: true`, `instant_deploy: false`, `is_http_basic_auth_enabled: true`, `http_basic_auth_username`, `http_basic_auth_password`. Svar 201 `{uuid}`, 409 `{message, warning, conflicts[]}`.
  - `GET /projects/{COOLIFY_PROJECT_UUID}/{COOLIFY_ENVIRONMENT_NAME}` → miljön med `applications[]` (`uuid`, `name`). Används för idempotens, projektspärr och invariant. **Finns inte i bilaga A**; verifieras i spiken (S13).
  - `GET /applications/{uuid}` → `is_http_basic_auth_enabled` (S14).
  - `GET /applications/{uuid}/envs` (S15), `POST /applications/{uuid}/envs` och `PATCH /applications/{uuid}/envs` med `{key, value, is_preview: false, is_buildtime: true, is_runtime: false}` (`is_buildtime`/`is_runtime` finns i controllern, inte i OpenAPI; S4/S15).
  - `POST /deploy?uuid=<app>` → `{deployments: [{message, resource_uuid, deployment_uuid}]}`. Aldrig `GET /deploy`.
  - `GET /deployments/{deployment_uuid}` → `status` ∈ `queued | in_progress | finished | failed | cancelled-by-user`, `logs` (redigeras alltid).
  - `DELETE /applications/{uuid}` med standardvärden → 200 `{message: "Application deletion request queued."}`, asynkront.
  - Varje anrop med ett app-uuid kräver att uuid:t finns i `provisioning_runs.coolify_app_uuid`; `DELETE`, `PATCH`, `POST envs` och `POST /deploy` kräver dessutom att appen finns i Demo-projektets `applications`.
- SiteOps, kontraktet som 2a låser (`2026-10-13-provisionering-2a-siteops.md`, "Låst API-kontrakt"), bas `{SITEOPS_URL}/api/v1`, headers `X-Api-Key: {SITEOPS_API_KEY}` och `Accept: application/json`. Nyckeln har exakt `sites:read` + `sites:write`.
  - `GET /sites?url=<urlencoded exakt URL>` (`sites:read`) → 200 `{"data":[<webblistpost med bl.a. id, url, is_active>],"meta":{"total":N}}`. Matchar webbens `url` eller någon miljös `url`, exakt, med eller utan avslutande `/`; inaktiva webbar kommer med. Ingen träff: `{"data":[],"meta":{"total":0}}`.
  - `POST /sites` (`sites:write`) body `{name, url, type: "app", environment: {type: "demo", name: "Demo", url, basic_auth_user, basic_auth_password, is_monitored: true}}` → 201 `{"data":{<webbdetalj: id, url, is_active, monitoring_enabled, …>,"environment":{"id","site_id","name","type","url","is_monitored","has_basic_auth"}}}`. 409 `{"error":"A site with this URL already exists in the team.","site_id":N}` när en webb i teamet redan har adressen.
  - `PATCH /sites/{id}/environments/{env}` (`sites:write`) body delmängd av `{basic_auth_user, basic_auth_password, is_monitored}` (basic auth alltid parvis) → 200 `{"data":{"id","site_id","name","type","url","is_monitored","has_basic_auth"}}`; 403 om webben inte skapades av samma API-klient (inget ändras), 404 om webb/miljö saknas.
  - `POST /sites/{id}/deactivate` (`sites:write`, ingen body) → 200 `{"data":{"id":N,"is_active":false,"monitoring_enabled":false}}`, idempotent; 403 om webben inte skapades av samma API-klient.
  - `GET /sites/{id}` (`sites:read`) → `{"data":{id, name, url, type, is_active, monitoring_enabled, …}}`. `GET /sites/{id}/environments` (`sites:read`) → `{"data":[{id, name, type, url, is_monitored, has_basic_auth, …}],"meta":{"total":N}}`. Basic auth-värden finns aldrig i något svar.
  - **Ägarskap:** PATCH och deactivate fungerar bara på webbar som Computercats egen API-klient skapade (`sites.api_client_id` i 2a). En webb som hittas via uppslaget eller 409 adopteras bara om (a) dess `url` är exakt runnens `demo_url` (avslutande `/` ignoreras), (b) den har en miljö av typ `demo` med samma URL, (c) den är aktiv, och (d) `PATCH` av den miljön till Demo-värdena ger 200. 403 på (d), eller att (a)–(c) inte håller → `StepFailed`, sagan stannar och Erik larmas. Ingen gissning, ingen ny webb bredvid.
- Slack: inkommande webhook, `POST {SLACK_ALERT_WEBHOOK_URL}` JSON `{text}`, timeout 5 s, `&`, `<`, `>` escapas i användarvärden.
- Mallkontrakt (2b, "Gränssnitt mot 2c: idé-markören i `README.md`"): `README.md` i repots rot på `main` har startmarkören `<!-- computercat:idea:start -->` och slutmarkören `<!-- computercat:idea:end -->`, var och en exakt en gång, på en egen rad, start före slut. Repo-steget ersätter allt mellan markörerna med `"\n" + idé + "\n"` och behåller markörerna (idempotent). I idén ersätts `<!--` med `&lt;!--` och `-->` med `--&gt;` före skrivning. Saknas en markör, förekommer den mer än en gång, står den inte på egen rad eller kommer slut före start: steget fallerar och skriver ingenting. `/generate` fyller repot asynkront, så `GET …/contents/README.md` som ger 404 försöks igen (var 10:e sekund, högst `repo_ready_timeout_minutes`) innan steget fallerar. Build-variabeln heter `NEXT_PUBLIC_COMPUTERCAT_GAME`. Mallen kräver Node 22 (`engines.node: "22.x"`), se spikpunkt S20.

**Värden**
- Slugregel: `/^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){2,39}\z/` (3–40 tecken). Reserverade: `www`, `api`, `admin`, `app`, `apps`, `demo`, `mail`, `spel` plus `PROVISIONING_RESERVED_SUBDOMAINS` (kommaseparerad lista från Erik, Task 0 punkt 11).
- Demo-URL: `https://<slug>.apps.humblebrag.se`. Coolify-namn: `spel-<slug>`. Repo: `HumblebragAB/<slug>`.
- Tak: 15 = provisioneringsruns (`kind = provision`) med status `running`, `succeeded` eller `failed`. 5 per dygn = provisioneringsruns skapade av användaren de senaste 24 timmarna, oavsett status (även `released`).
- Deploy: poll var 30:e sekund, högst 15 minuter från deploystart. README efter `/generate`: poll var 10:e sekund, högst 3 minuter. Coolify-radering: poll var 30:e sekund, högst 10 minuter.
- Jobb: `AdvanceProvisioningRun::$tries = 0`, `$timeout = 60`, `retryUntil()` = nu + 25 min, `WithoutOverlapping` per run. HTTP-timeout 20 s, connect-timeout 5 s.
- Redigering: nycklar som innehåller `authorization`, `private_key`, `password`, `token`, `api_key`, eller som heter exakt `value` eller `logs`, ersätts med `[REDACTED]`; kända hemligheter (Coolify-token, SiteOps-nyckel, Slack-URL, utfärdade GitHub-tokens) och mönstren `gh[pousr]_…`/`github_pat_…` byts mot `[REDACTED]` i fri text; max 4096 byte, avkortat med `…` på teckengräns.
- `last_error` visas för spelets medlemmar och innehåller bara meddelanden från `SafeMessage`-undantag; övriga fel blir `Internal error (ref <uuid>)` och loggas i sin helhet.
- Inget HTTP-anrop sker inne i en databastransaktion. Vaktat av ett test i varje provisioneringstest (Task 3).

---

## Review Focus

Fem fel som specen innebär men som lätt passerar oupptäckta; varje rad har ett test i den task som äger koden.

1. **Köns försöksräkning dödar pollingen eller loopar för evigt.** Forge-workern körs med `--tries=1`. Ett jobb som anropar `release(30)` räknas som ett nytt försök och skulle då misslyckas med `MaxAttemptsExceededException` vid andra pollningen; ett jobb med obegränsade försök som kastar ett undantag skulle i stället köras om för evigt. Dessutom är databasköns `retry_after` 90 s medan specen anger `--timeout=120`. Test: `AdvanceProvisioningRunTest::test_job_queue_settings_survive_release_and_never_retry_on_exceptions` (Task 12) kontrollerar `$tries = 0`, `retryUntil()`, `$timeout < retry_after` och att ett undantag i ett steg aldrig når kön.
2. **Slugen låses eller släpps fel.** "Unik bland runs som inte är `released`" går inte att uttrycka som partiellt index i MySQL; utan kolumnen `slug_lock` kan två samtidiga `create_game` reservera samma slug, och ett repo som någon annan äger kan av misstag adopteras. Test: `ProvisioningRunTest::test_slug_lock_is_unique_until_released` (Task 8) och `RepoStepTest::test_foreign_repository_releases_the_slug` / `test_own_repository_is_adopted_on_retry` (Task 13).
3. **Hemligheter läcker via loggar eller `last_error`.** `http_basic_auth_password` (Coolify), `basic_auth_password` (SiteOps), Coolifys `logs`, GitHub-tokens och okända undantag med SQL eller URL:er visas annars för spelets medlemmar. Test: `AdvanceProvisioningRunTest::test_failure_redacts_last_error_and_hides_internal_exceptions` (Task 12) och `CoolifyClientTest::test_failed_create_logs_redacted_request_and_response` (Task 10).
4. **Coolify-token når hela teamet.** En uuid som inte kommer från en run, eller en app utanför Demo-projektet, får aldrig raderas eller ändras. Test: `CoolifyClientTest::test_delete_refuses_unknown_uuid_without_any_request` och `test_delete_refuses_app_outside_demo_project` (Task 10), `ArchiveStepsTest::test_guard_failure_stops_archive_without_delete` (Task 16).
5. **Ett tidigt fel syns inte för den som skapade spelet.** Fallerar `repo`-steget finns inget spel ännu, så `get_provisioning_status(game)` och `retry_provisioning(game)` skulle svara "Game not found" och Mattias Claude får aldrig veta varför. Test: `ProvisioningStatusToolTest::test_creator_sees_run_before_game_exists_but_others_do_not` och `RetryProvisioningToolTest::test_creator_can_retry_run_without_game` (Task 19).

---

## Filstruktur

**Nya filer**

| Fil | Ansvar |
|---|---|
| `config/provisioning.php` | Alla inställningar för provisioneringen |
| `app/Provisioning/ProvisioningConfig.php` | Saknade inställningar, nyckelfil, härledda namn (Demo-URL, Coolify-namn, ägare) |
| `app/Provisioning/SafeMessage.php` | Markörgränssnitt: undantagets meddelande får visas för medlemmar |
| `app/Provisioning/Http/Redactor.php` | Redigering och 4 KB-gräns |
| `app/Provisioning/Http/ExternalApiException.php` | Fel från GitHub/Coolify/SiteOps, redigerat meddelande |
| `app/Provisioning/Http/ApiCallLogger.php` | Loggar 4xx/5xx med metod, URL, status, request och response |
| `app/Provisioning/Http/SendsApiRequests.php` | Gemensam anropsmetod för klienterna |
| `app/Provisioning/Clients/GitHubAppClient.php` | JWT, installationstoken, generate, repo, contents, arkivering |
| `app/Provisioning/Clients/CoolifyClient.php` | Demo-projektets appar, skapa, envs, deploy, status, radera, spärrar |
| `app/Provisioning/Clients/CoolifyConflictException.php` | 409 domänkonflikt |
| `app/Provisioning/Clients/CoolifyGuardException.php` | Spärren slog till |
| `app/Provisioning/Clients/SiteOpsClient.php` | Skapa demo-sajt, uppslag, läsa, avaktivera |
| `app/Support/SlackAlert.php` | Webhook med dedupe per nyckel och dygn |
| `app/Rules/GameSlug.php` | Slugregel och reserverade namn |
| `app/Models/ProvisioningRun.php`, `app/Models/ProvisioningStep.php` | Datamodell |
| `app/Provisioning/Saga.php` | Steglistor, start och retry |
| `app/Provisioning/StepResult.php`, `app/Provisioning/StepFailed.php` | Stegens utfall |
| `app/Provisioning/ReadmeIdea.php` | Ersätter idé-blocket mellan `computercat:idea`-markörerna i README (kontrakt med 2b) |
| `app/Provisioning/DemoProbe.php` | `GET` Demo-URL med basic auth |
| `app/Provisioning/Steps/Step.php` | Steggränssnitt |
| `app/Provisioning/Steps/{Repo,Game,CoolifyApp,CoolifyEnv,Cors,SiteOps,Deploy}Step.php` | Provisioneringsstegen |
| `app/Provisioning/Steps/{CoolifyDelete,SiteOpsDeactivate,RepoArchive,GameDeactivate}Step.php` | Arkiveringsstegen |
| `app/Jobs/AdvanceProvisioningRun.php` | Kör nästa steg, release, fel, avslut |
| `app/Mcp/Tools/Concerns/ToolHelpers.php` | `json()`, `validationFailed()`, `audit()` delat av verktygsbaserna |
| `app/Mcp/Tools/PlatformTool.php` | Bas för verktyg utan spel |
| `app/Mcp/Tools/{CreateGame,GetProvisioningStatus,RetryProvisioning,RequestLive,ArchiveGame}Tool.php` | Nya verktyg |
| `app/Console/Commands/CheckProvisioning.php` | Nattlig invariant |
| `database/migrations/2026_10_15_100001_add_is_listed_to_games_table.php` | `games.is_listed` |
| `database/migrations/2026_10_15_100002_create_provisioning_runs_table.php` | Runs |
| `database/migrations/2026_10_15_100003_create_provisioning_steps_table.php` | Steg |
| `tests/Support/ProvisioningFakes.php` | Konfiguration, testnyckel och svar byggda efter bilagorna |
| `tests/Support/CreatesProvisioningRuns.php` | Hjälpare för runs i test |
| `tests/Support/AssertsNoHttpInTransaction.php` | Vakt: inget HTTP-anrop inne i en transaktion |
| `tests/Feature/Provisioning/ProvisioningTestCase.php` | Bas för provisioneringstester |
| `tests/Unit/Provisioning/RedactorTest.php`, `tests/Unit/GameSlugTest.php` | Enhetstester |
| `tests/Feature/Provisioning/*Test.php`, `tests/Feature/Mcp/*ToolTest.php`, `tests/Feature/Console/CheckProvisioningTest.php` | Feature-tester |

**Ändrade filer**

| Fil | Ändring |
|---|---|
| `composer.json`, `composer.lock` | `lcobucci/jwt` uttryckligen |
| `phpunit.xml` | Tomma provisioneringsvariabler i test |
| `.env.example` | Nya variabler |
| `tests/TestCase.php` | `Http::preventStrayRequests()` och vakten mot `DELETE` till `api.github.com` |
| `app/Models/Game.php` | `is_listed` |
| `app/Http/Controllers/Api/V1/GameController.php` | `index` visar bara listade spel |
| `app/Filament/Resources/GameResource.php` | Slugregel för nya spel, `is_listed`-toggle |
| `app/Services/IntegrationGuide.php` | Spelets och topplistans slug genom `code()` |
| `app/Mcp/GameAccess.php` | `isOwner()` |
| `app/Mcp/Tools/GameTool.php` | `ToolHelpers`, krokarna `whenGameMissing()` och `transactional()` |
| `app/Mcp/Tools/ListGamesTool.php` | Ärver `PlatformTool` |
| `app/Mcp/ComputercatServer.php` | Nya verktyg, instruktioner |
| `tests/Feature/Mcp/ToolInvariantsTest.php` | Varje verktyg ärver en loggande bas |
| `routes/console.php` | Schemaläggning av invarianten |
| `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md`, `docs/superpowers/specs/bilagor/2026-10-13-github-app.md` | Spikens resultat ersätter UNCONFIRMED |

---

## Task 0: Förutsättningar (Erik)

Ingen kod. Varje punkt är en egen AskUserQuestion till Erik, med alternativen "Klart (Erik har gjort det)", "Gör det åt mig (OK)" där det går, och "Inte än". Ingen punkt bockas av utan svar. Allt som rör prod utförs först efter "Gör det åt mig (OK)" för just den punkten. Stannar en punkt på "Inte än" stannar planen.

**Files:** inga.

**Interfaces:** Produces: ifyllda `.env`-värden på Forge (namnen nedan), Forge-daemon, GitHub App, Coolify-token, SiteOps-nyckel, 1Password-poster i valvet `Claude` för spiken.

- [ ] **0.1 Spec och plan på main.** Fråga: "docs/provisionering (spec, bilagor, denna plan) är inte på main. Ska jag köra `~/.claude/bin/wt-finish /Users/erik/Web/computercat-api/.claude/worktrees/docs-provisionering` (lokal ff-merge, ingen push)?" Efter OK: kör det och kontrollera `git -C /Users/erik/Web/computercat-api log main --oneline -3` visar `Spec delprojekt 2: granskningens fynd inarbetade`.
- [ ] **0.2 2a och 2b klara.** Fråga om SiteOps-API:t från 2a är deployat (`POST /sites` med `environment`, `GET /sites?url=`, `POST /sites/{id}/deactivate`) och om `HumblebragAB/spelmall` finns med `is_template: true` och idé-markörerna `<!-- computercat:idea:start -->`/`<!-- computercat:idea:end -->` i README (2b Task 12 gjord). Se "Beroenden och ordning" överst: spiken får inte starta innan båda är på plats. Spiken (Task 1) verifierar båda; här räcker Eriks besked.
- [ ] **0.3 Kö-worker i prod.** Fråga: "Får jag läsa Forge-daemons för computercat-servern via skillen `laravel-forge` (bara läsning, endpoints enligt skillen)?" Efter OK och om ingen `queue:work` finns: fråga separat "Skapa Forge-daemon `php8.4 artisan queue:work --tries=1 --timeout=60` som användaren `computercat` i sajtkatalogen?" Specen anger `--timeout=120`; databasköns `retry_after` är 90 s (`config/queue.php`), och Laravel kör då om ett jobb som fortfarande pågår. Föreslå `--timeout=60` (jobbet sätter själv 60 s) eller `DB_QUEUE_RETRY_AFTER=180` i punkt 0.10. Bekräfta även att prod-`.env` har `QUEUE_CONNECTION=database`.
- [ ] **0.4 Slack-webhook.** Erik skapar en inkommande webhook till sin larmkanal och lägger URL:en i 1Password-valvet `Claude` som `op://Claude/computercat-slack-alert/url`.
- [ ] **0.5 GitHub App "Humblebrag Spelstudio".** Erik skapar appen i organisationen HumblebragAB: Repository permissions Administration: write, Contents: write, Metadata: read; inga organisationsrättigheter; ingen webhook. Installation på "Only select repositories" med bara `spelmall`. Erik sparar client id, installation id och privat nyckel (.pem) i `op://Claude/computercat-github-app/{client_id,installation_id,private_key}`.
- [ ] **0.6 Coolify-token.** Erik byter till Humblebrags team i Coolify-UI:t och skapar en token med `read`, `write` och `deploy` (inte `root`, inte `read:sensitive`/`write:sensitive`). Specens Förutsättningar 4 är rättad till samma tre: bilaga A §7 säger att alla GET kräver `read` (status, listning, invariant). UI-egenhet enligt bilaga A: när `deploy` slås på nollställs urvalet, så `read` och `write` väljs efter `deploy`. Erik sparar i 1Password-objektet `op://Claude/computercat-coolify/` fälten `token`, `url` (utan `/api/v1`), `project_uuid` (Demo-projektet), `environment_name` (miljön i Demo-projektet, Coolifys standard är `production`), `server_uuid` och `github_app_uuid` (Coolifys GitHub App-källa).
- [ ] **0.7 Coolifys och Claudes GitHub App-installationer.** Fråga om Erik vill att jag läser `gh api /orgs/HumblebragAB/installations --jq '.installations[] | {app_slug, repository_selection}'` (läsning). Båda (Coolifys källa och Claude) ska ha `repository_selection: "all"`. Står någon på `selected` byter Erik den till "All repositories" i org-inställningarna.
- [ ] **0.8 SiteOps-nyckel.** Skapas med 2a:s kommando efter att 2a deployats (2a Task 7, "Efter deploy", kräver Eriks OK): `php artisan api-clients:create Computercat --team=<Humblebrags team-id> --scope=sites:read --scope=sites:write`. Exakt dessa två scopes (specens Förutsättningar 6): uppslaget `GET /sites?url=` och invarianten (`GET /sites/{id}`, `/environments`) kräver `sites:read`; skapa, PATCH och avaktivera kräver `sites:write`. Nyckeln visas en gång och går direkt in i 1Password, aldrig via chatten. Sparas som `op://Claude/computercat-siteops/api_key`, URL:en som `op://Claude/computercat-siteops/url`.
- [ ] **0.9 Molnmiljön.** Erik bekräftar att Claude Code-molnmiljön som routines använder tillåter `api.computercat.co` och `*.apps.humblebrag.se`, och att Computercat-connectorn är tillagd i claude.ai på hans konto.
- [ ] **0.10 `.env` på Forge.** Görs efter spiken (Task 1) och före deploy (Task 23), men frågas här så att Erik vet vad som kommer: `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH=app/private/github-app.pem` (fil i `storage/app/private/`, ägare `computercat`, läge 600), `GITHUB_TEMPLATE_REPO=HumblebragAB/spelmall`, `COOLIFY_URL`, `COOLIFY_TOKEN`, `COOLIFY_PROJECT_UUID`, `COOLIFY_ENVIRONMENT_NAME`, `COOLIFY_SERVER_UUID`, `COOLIFY_GITHUB_APP_UUID`, `SITEOPS_URL`, `SITEOPS_API_KEY`, `DEMO_BASIC_AUTH_USER=demo`, `DEMO_BASIC_AUTH_PASSWORD=demo`, `SLACK_ALERT_WEBHOOK_URL`, `PROVISIONING_RESERVED_SUBDOMAINS`, och vid behov `DB_QUEUE_RETRY_AFTER=180`. Fråga: "Lägger du in dem i Forge själv, eller ska jag göra det via Forge-API:t med värden ur 1Password (claude-secret run, värdena passerar aldrig min kontext)?" Utförs i Task 23 steg 2.
- [ ] **0.11 Reserverade underdomäner.** Fråga Erik efter listan över befintliga underdomäner under `apps.humblebrag.se` (finns i Coolify-UI:t). Svaret blir `PROVISIONING_RESERVED_SUBDOMAINS` (kommaseparerat) i 0.10.
- [ ] **0.12 Coolify-serverns host för spiken.** Fråga efter hostnamn/IP för Coolify-servern (för RAM-mätning i S7 och etikettjämförelse i S3) och om `ssh-claude <host>` når den. Fråga även var `robots-sync` och `coolify-health` körs och hur deras utfall läses.

---

## Task 1: Spike (riktiga anrop)

Verifierar specens punkter (S1–S7 och S20) plus de fakta planen bygger på som bilagorna markerar UNCONFIRMED eller inte nämner. Körs med en engångsapp: slug `e2e-spike`, repo `HumblebragAB/e2e-spike`, Coolify-namn `spel-e2e-spike`, Demo-URL `https://e2e-spike.apps.humblebrag.se`. Allt städas på de id:n som spiken själv sparat; repot arkiveras, raderas aldrig. **Faller en punkt: stanna och fråga Erik (AskUserQuestion) innan något annat görs.**

**Files:**
- Create (scratchpad, committas inte): `$SCRATCH/spike/.env.op`, `$SCRATCH/spike/jwt.php`, `$SCRATCH/spike/ids.env`, `$SCRATCH/spike/log/` (`$SCRATCH` = sessionens scratchpad-katalog)
- Modify: `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md`, `docs/superpowers/specs/bilagor/2026-10-13-github-app.md` (i `$WT`)

**Interfaces:** Consumes: Task 0. Produces: verifierade fakta S1–S19 i bilagorna; beslut om `git_repository`-format som Task 10 använder.

Punkter (S1–S7 och S20 är specens, S8–S19 är planens beroenden):

| # | Verifieras |
|---|---|
| S1 | `git_repository`-format för GitHub App-källa |
| S2 | `ports_exposes: "80"` för `nginx:alpine` |
| S3 | Basic auth via API ger samma Traefik-labels som UI:t; `robots-sync`/`coolify-health` larmar inte |
| S4 | Build-variabel med `is_buildtime: true` når `next build` |
| S5 | Wildcard-DNS `*.apps.humblebrag.se` pekar på Coolify-servern |
| S6 | En molnsession som instrueras att pusha till `main` gör det |
| S7 | RAM på Coolify-servern under `next build` |
| S8 | Installationstoken får anropa `/generate` i organisationen; mallen har `is_template: true` |
| S9 | Token utfärdad före `/generate` når det nya repot (planen tar ny token ändå) |
| S10 | `GET /repos/{o}/{r}` har `id`, `description`, `archived`, `template_repository.full_name` |
| S11 | Tid tills README finns efter `/generate`; Contents `GET` ger `sha`, `content`, `encoding: base64`; `PUT` tar `message`, `content`, `sha` |
| S12 | Andra `/generate` med samma namn ger 422 och vilken body |
| S13 | `GET /projects/{uuid}/{environment_name}` ger `applications[]` med `uuid` och `name` |
| S14 | `GET /applications/{uuid}` ger `is_http_basic_auth_enabled` med token utan `read:sensitive` |
| S15 | `GET /applications/{uuid}/envs` ger lista med `key` (och om `value` döljs); `PATCH /envs` uppdaterar |
| S16 | Token med `read`+`write`+`deploy` räcker för alla anrop ovan |
| S17 | `PATCH archived: true` fungerar med installationstoken; Coolify-`DELETE` tar bort appen ur projektlistan |
| S18 | Coolifys GitHub App når det nya repot (klonen i bygget lyckas) |
| S19 | SiteOps 2a-kontraktet: skapa, 409 vid dubblett, uppslag på URL, läsa (`monitoring_enabled`, `type`, `has_basic_auth`), PATCH av miljön, avaktivera |
| S20 | Coolifys nixpacks bygger mallen med Node 22 (`engines.node: "22.x"`); annars hur Node 22 tvingas fram |

- [ ] **Steg 1: Worktree och OK.** Kör `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/provisionering --db` och spara sökvägen som `$WT`. Fråga Erik med AskUserQuestion: "Spiken skapar på riktigt: repot HumblebragAB/e2e-spike (privat, arkiveras efteråt), Coolify-appen spel-e2e-spike i Demo-projektet (raderas på sitt uuid), en SiteOps-sajt (avaktiveras), en engångs-routine som pushar till e2e-spike, samt läser RAM på Coolify-servern via ssh-claude. Hemligheter läses med claude-secret run och skrivs aldrig ut. OK?"

- [ ] **Steg 2: Referensfil utan hemligheter.** Skriv `$SCRATCH/spike/.env.op` (bara op-referenser och icke-hemliga värden):

```bash
GITHUB_APP_CLIENT_ID=op://Claude/computercat-github-app/client_id
GITHUB_APP_INSTALLATION_ID=op://Claude/computercat-github-app/installation_id
GITHUB_APP_PRIVATE_KEY=op://Claude/computercat-github-app/private_key
COOLIFY_URL=op://Claude/computercat-coolify/url
COOLIFY_TOKEN=op://Claude/computercat-coolify/token
COOLIFY_PROJECT_UUID=op://Claude/computercat-coolify/project_uuid
COOLIFY_ENVIRONMENT_NAME=op://Claude/computercat-coolify/environment_name
COOLIFY_SERVER_UUID=op://Claude/computercat-coolify/server_uuid
COOLIFY_GITHUB_APP_UUID=op://Claude/computercat-coolify/github_app_uuid
SITEOPS_URL=op://Claude/computercat-siteops/url
SITEOPS_API_KEY=op://Claude/computercat-siteops/api_key
```

och `$SCRATCH/spike/jwt.php` (kör med `php -I`, läser nyckeln ur miljön, inget på disk):

```php
<?php
// Användning: php jwt.php <sökväg till worktree>
require $argv[1].'/vendor/autoload.php';

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

$now = new DateTimeImmutable();
echo Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
    ->issuedBy(getenv('GITHUB_APP_CLIENT_ID'))
    ->issuedAt($now->modify('-60 seconds'))
    ->expiresAt($now->modify('+9 minutes'))
    ->getToken(new Sha256(), InMemory::plainText(getenv('GITHUB_APP_PRIVATE_KEY')))
    ->toString();
```

Exportera först `WT` och `SCRATCH` (`export WT=… SCRATCH=…`) så att de syns i underskalet. Alla anrop nedan körs inuti `~/.claude/bin/claude-secret run --env-file=$SCRATCH/spike/.env.op -- bash -c '…'`. Svar skrivs till `$SCRATCH/spike/log/<punkt>.json` efter detta jq-filter, så att inget hemligt hamnar i loggen eller i transkriptet:

```bash
REDACT='walk(if type=="object" then with_entries(if (.key|ascii_downcase|test("authorization|private_key|password|token|api_key|^value$|^logs$")) then .value="[REDACTED]" else . end) else . end)'
```

Hjälpare som definieras först i varje `bash -c`:

```bash
gh_token() { JWT=$(php -I "$SCRATCH/spike/jwt.php" "$WT"); curl -s -X POST -H "Authorization: Bearer $JWT" -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' "https://api.github.com/app/installations/$GITHUB_APP_INSTALLATION_ID/access_tokens" | jq -r .token; }
gh() { curl -s -w '\n%{http_code}\n' -X "$1" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' "https://api.github.com$2" ${3:+-d "$3"}; }
cf() { curl -s -w '\n%{http_code}\n' -X "$1" -H "Authorization: Bearer $COOLIFY_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' "$COOLIFY_URL/api/v1$2" ${3:+-d "$3"}; }
so() { curl -s -w '\n%{http_code}\n' -X "$1" -H "X-Api-Key: $SITEOPS_API_KEY" -H 'Accept: application/json' -H 'Content-Type: application/json' "$SITEOPS_URL/api/v1$2" ${3:+-d "$3"}; }
```

(Svarets sista rad är HTTP-statusen; resten pipas genom `jq "$REDACT"`.)

- [ ] **Steg 3: GitHub, bara läsning (S8, S11 läsdel).** `TOKEN=$(gh_token)`; `gh GET /repos/HumblebragAB/spelmall` → kontrollera `.is_template == true` och `.private`. `gh GET /repos/HumblebragAB/spelmall/contents/README.md` → kontrollera att `.sha`, `.content`, `.encoding == "base64"` finns och att avkodad text har exakt en rad `<!-- computercat:idea:start -->` och exakt en rad `<!-- computercat:idea:end -->`, start före slut. Kontrollera också att mallens `package.json` har `"engines": {"node": "22.x"}` (`gh GET /repos/HumblebragAB/spelmall/contents/package.json`, avkoda). Förväntat: 200, alla fält finns. Annars: stanna och fråga.

- [ ] **Steg 4: Skapa repot (S8, S9, S11, S10).** Spara `OLDTOKEN=$TOKEN`. `gh POST /repos/HumblebragAB/spelmall/generate '{"owner":"HumblebragAB","name":"e2e-spike","description":"Computercat-spel e2e-spike (run spike-2026-10-13)","private":true,"include_all_branches":false}'`. Förväntat: 201 och `.private == true`. **Är `.private` false: stanna omedelbart och fråga Erik** (repot är publikt). Skriv `SPIKE_REPO_ID=<.id>` till `$SCRATCH/spike/ids.env`. Poll `gh GET /repos/HumblebragAB/e2e-spike/contents/README.md` med `OLDTOKEN` var 5:e sekund i högst 3 minuter och notera sekunder till 200 (S11) och om den gamla token fick 200 eller 404 (S9). Ta ny token och gör samma GET. Kör sedan `gh GET /repos/HumblebragAB/e2e-spike` och notera `.id`, `.description`, `.archived`, `.template_repository.full_name` (S10; förväntat `HumblebragAB/spelmall`).

- [ ] **Steg 5: README-PUT (S11).** Avkoda README, ersätt blocket mellan markörerna med `Spike: idétext`, och `gh PUT /repos/HumblebragAB/e2e-spike/contents/README.md '{"message":"Spike: idé","content":"<base64>","sha":"<sha>"}'`. Förväntat 200. Kontrollera med ny GET att texten finns.

- [ ] **Steg 6: Namnkrock (S12).** Upprepa POST `/generate` från steg 4 oförändrad. Förväntat 422; spara body i `log/S12.json`. Kontrollera med `gh GET /repos/HumblebragAB/e2e-spike` att inget nytt repo skapades (samma `id`).

- [ ] **Steg 7: Coolify-listning före (S13, S16).** `cf GET "/projects/$COOLIFY_PROJECT_UUID/$COOLIFY_ENVIRONMENT_NAME"` → spara; kontrollera att `.applications` är en lista med `uuid` och `name`. Faller formen: prova inte andra endpoints på eget initiativ, stanna och fråga (Task 10 och 21 bygger på detta).

- [ ] **Steg 8: Skapa Coolify-appen (S1, S2, S16).** `cf POST /applications/private-github-app` med exakt body:

```json
{"project_uuid":"$COOLIFY_PROJECT_UUID","server_uuid":"$COOLIFY_SERVER_UUID","environment_name":"$COOLIFY_ENVIRONMENT_NAME","github_app_uuid":"$COOLIFY_GITHUB_APP_UUID","git_repository":"HumblebragAB/e2e-spike","git_branch":"main","build_pack":"nixpacks","name":"spel-e2e-spike","domains":"https://e2e-spike.apps.humblebrag.se","ports_exposes":"80","is_static":true,"static_image":"nginx:alpine","publish_directory":"out","is_auto_deploy_enabled":true,"instant_deploy":false,"is_http_basic_auth_enabled":true,"http_basic_auth_username":"demo","http_basic_auth_password":"demo"}
```

(variablerna expanderas med `jq -n --arg` så att JSON blir korrekt.) Förväntat 201 `{uuid}`. Skriv `SPIKE_APP_UUID=<uuid>` till `ids.env` direkt. Får du 422 som pekar på `git_repository`: stanna och fråga Erik om ett nytt försök med `https://github.com/HumblebragAB/e2e-spike` (S1).

- [ ] **Steg 9: Läs appen (S13, S14).** `cf GET "/projects/$COOLIFY_PROJECT_UUID/$COOLIFY_ENVIRONMENT_NAME"` → appen finns med `uuid == SPIKE_APP_UUID` och `name == "spel-e2e-spike"`. `cf GET /applications/$SPIKE_APP_UUID` → notera `.is_http_basic_auth_enabled` (förväntat `true`), om `http_basic_auth_password` syns (ska inte med denna token), och om något fält anger projekt.

- [ ] **Steg 10: Build-variabel (S4, S15).** `cf GET /applications/$SPIKE_APP_UUID/envs` (notera form). `cf POST /applications/$SPIKE_APP_UUID/envs '{"key":"NEXT_PUBLIC_COMPUTERCAT_GAME","value":"e2e-spike","is_preview":false,"is_buildtime":true,"is_runtime":false}'` → förväntat 201. `cf GET .../envs` → posten finns med `key` och `is_buildtime: true`; notera om `value` visas. `cf PATCH /applications/$SPIKE_APP_UUID/envs` med samma body → förväntat 201/200.

- [ ] **Steg 11: Deploy, status och RAM (S7, S18).** `cf POST "/deploy?uuid=$SPIKE_APP_UUID"` → spara `.deployments[0].deployment_uuid` som `SPIKE_DEPLOYMENT_UUID` i `ids.env`. Poll `cf GET /deployments/$SPIKE_DEPLOYMENT_UUID` var 30:e sekund (spara bara `.status`) tills `finished` eller `failed`, högst 20 minuter. Parallellt, efter Eriks OK i steg 1: `~/.claude/bin/ssh-claude <coolify-host> 'free -m; docker stats --no-stream --format "{{.Name}} {{.MemUsage}}"'` var 30:e sekund, spara toppvärdet för använt minne och swap. Förväntat: `finished` (klonen lyckades = S18) och minst 512 MB ledigt minne under bygget. `failed` eller mindre marginal: stanna och fråga.

  **S20 (Node 22):** läs byggloggen ur `cf GET /deployments/$SPIKE_DEPLOYMENT_UUID` (fältet `logs`, filtrera lokalt med `jq -r .logs | grep -iE 'nodejs|node_?version|setup.*node|v2[0-9]\.'` och spara bara de raderna, inget annat ur loggen) och notera vilken Node-version nixpacks valde. Förväntat: Node 22 (nixpacks-planen visar `nodejs_22` eller `node --version` v22.x). Nixpacks standard är Node 18 och versionen kan sättas med miljövariabeln `NIXPACKS_NODE_VERSION`, med `engines.node` i `package.json` eller med `.nvmrc` (sidan anger inte inbördes prioritet); bara major-version stöds, och 16, 18, 20, 22 och 23 finns (nixpacks.com/docs/providers/node, läst 2026-10-13). Byggs det med Node 18 (eller faller `next build` på "Node.js version >= 20.9 is required"): stanna och fråga Erik om alternativet att sätta build-variabeln `NIXPACKS_NODE_VERSION=22` per app (samma `POST /applications/{uuid}/envs` som S4, `is_buildtime: true`). Väljer Erik det läggs variabeln till i steget `coolify_env` (Task 14) och i fakes, innan Task 2 påbörjas.

- [ ] **Steg 12: DNS och svar (S5, S2, S4).** `dig +short e2e-spike.apps.humblebrag.se` och `dig +short x$(date +%s).apps.humblebrag.se` → båda ger Coolify-serverns IP (S5). `curl -s -o /dev/null -w '%{http_code}' https://e2e-spike.apps.humblebrag.se/` → `401`; med `-u demo:demo` → `200` (S2). Hämta HTML med basic auth, plocka ut `/_next/static/…js`-sökvägarna och grep:a varje fil efter `e2e-spike` → minst en träff (S4).

- [ ] **Steg 13: Labels och övervakning (S3).** Med Eriks OK: `ssh-claude <coolify-host> 'docker inspect $(docker ps -q --filter name=<SPIKE_APP_UUID>) --format "{{json .Config.Labels}}"'` och samma för en befintlig app som Erik konfigurerat med basic auth i UI:t (Erik namnger den). Jämför de `traefik.http.middlewares.*basicauth*`- och `routers.*.middlewares`-labels som finns. Läs därefter `robots-sync` och `coolify-health` på det sätt Erik angav i 0.12 och kontrollera att `spel-e2e-spike` inte flaggas. Avvikelse: stanna och fråga.

- [ ] **Steg 14: Molnsessionen pushar till main (S6).** Med Eriks OK: invokera skillen `schedule` och skapa en engångs-routine på Eriks konto med repot `HumblebragAB/e2e-spike` och prompten: "Ändra första rubriken i README.md till 'Spike OK'. Committa och pusha direkt till grenen main, skapa ingen ny gren. Svara med commit-sha." Efter körningen: `gh GET /repos/HumblebragAB/e2e-spike/commits/main` → senaste commit har rubrikändringen. `cf GET "/deployments/applications/$SPIKE_APP_UUID?skip=0&take=5"` → en ny deployment startades av pushen (auto-deploy). Notera grenen routinen faktiskt använde.

- [ ] **Steg 15: SiteOps-kontraktet (S19).** Mot 2a:s låsta kontrakt (2a måste vara deployad, se "Beroenden och ordning").
  1. `so GET "/sites?url=https%3A%2F%2Fe2e-spike.apps.humblebrag.se"` → 200 `{"data":[],"meta":{"total":0}}`. Finns redan en träff: stanna och fråga (någon annan har adressen).
  2. `so POST /sites '{"name":"e2e-spike","url":"https://e2e-spike.apps.humblebrag.se","type":"app","environment":{"type":"demo","name":"Demo","url":"https://e2e-spike.apps.humblebrag.se","basic_auth_user":"demo","basic_auth_password":"demo","is_monitored":true}}'` → 201; skriv `SPIKE_SITE_ID=.data.id` och `SPIKE_ENV_ID=.data.environment.id` i `ids.env` direkt. Kontrollera `.data.environment.has_basic_auth == true`, `.data.environment.type == "demo"`, `.data.monitoring_enabled == true` och att svaret inte innehåller `basic_auth_password`.
  3. Samma POST igen → 409 med `.site_id == SPIKE_SITE_ID`, och uppslaget i punkt 1 ger nu `.meta.total == 1` och `.data[0].id == SPIKE_SITE_ID`.
  4. `so GET /sites/$SPIKE_SITE_ID` → `.data.is_active == true`, `.data.monitoring_enabled == true`. `so GET /sites/$SPIKE_SITE_ID/environments` → en post med `type == "demo"`, `url` = Demo-URL, `is_monitored == true`, `has_basic_auth == true`; inget lösenord i svaret.
  5. `so PATCH /sites/$SPIKE_SITE_ID/environments/$SPIKE_ENV_ID '{"basic_auth_user":"demo","basic_auth_password":"demo","is_monitored":true}'` → 200 (ägarskapsbeviset som SiteOpsStep använder vid adoption).
  6. `so POST /sites/$SPIKE_SITE_ID/deactivate` → 200 `{"data":{"id":…,"is_active":false,"monitoring_enabled":false}}`; `so GET /sites/$SPIKE_SITE_ID` → `.data.is_active == false`.
  Ett 403 på PATCH mot en webb som Erik pekar ut och som inte skapades av nyckeln testas inte i prod; det täcks av 2a:s tester.

- [ ] **Steg 16: Städning på sparade id:n (S17).** Läs `ids.env`. Coolify: `cf GET "/projects/$COOLIFY_PROJECT_UUID/$COOLIFY_ENVIRONMENT_NAME"` → bekräfta att `SPIKE_APP_UUID` finns med namnet `spel-e2e-spike`; först då `cf DELETE /applications/$SPIKE_APP_UUID` → 200 `{message: "Application deletion request queued."}`; poll listningen var 30:e sekund tills uuid:t är borta (högst 10 min). GitHub: `gh GET /repos/HumblebragAB/e2e-spike` → `.id == SPIKE_REPO_ID`; först då `gh PATCH /repos/HumblebragAB/e2e-spike '{"archived":true}'` → 200 och `.archived == true`. SiteOps: redan avaktiverad i steg 15. Routinen från steg 14 tas bort med `schedule`-skillen om den inte är engångs. Inget raderas på något annat id än de sparade.

- [ ] **Steg 17: Skriv resultatet i bilagorna.** I `$WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` och `…-github-app.md`: ersätt varje "UNCONFIRMED" som spiken besvarade med "VERIFIERAT 2026-10-xx (spike): <faktum>", och lägg till ett avsnitt "## Spikresultat" med S1–S19, en rad var, inklusive tid till README (S11), byggtid och RAM-topp (S7), 422-body (S12) och formen på projekt- och env-listningen (S13, S15). Inga hemligheter, inga tokens.

- [ ] **Steg 18: Stäm av mot planen.** Om något resultat avviker från Global Constraints (t.ex. `git_repository` måste vara full URL, envs-listningen saknar `key`, projekt-endpointen har annan form): stanna, fråga Erik med AskUserQuestion och uppdatera berörda tasks (Task 3 fixturer, Task 10, Task 14, Task 21) innan Task 2 påbörjas.

- [ ] **Steg 19: Commit.**

```bash
cd $WT && git add docs/superpowers/specs/bilagor && git commit -m "Spike: verifierade fakta för provisioneringen i bilagorna"
```

---

## Task 2: Beroende, konfiguration och saknade inställningar

**Files:**
- Modify: `composer.json`, `composer.lock`, `phpunit.xml`, `.env.example`
- Create: `config/provisioning.php`, `app/Provisioning/ProvisioningConfig.php`, `app/Provisioning/SafeMessage.php`
- Test: `tests/Feature/Provisioning/ProvisioningConfigTest.php`

**Interfaces:**
- Produces:
  - `config('provisioning.*')` med nycklarna nedan.
  - `App\Provisioning\ProvisioningConfig::missing(): array` (lista med env-namn), `::privateKeyFile(): string`, `::demoUrl(string $slug): string`, `::coolifyAppName(string $slug): string`, `::githubOwner(): string`, `::templateRepo(): string`, `::repoFullName(string $slug): string`.
  - `interface App\Provisioning\SafeMessage` (markör).

- [ ] **Steg 1: Hotmodell.** Invokera `security-first-mindset` och gå igenom ytan: privat nyckel på disk, tre API-nycklar, Slack-URL, MCP-argument från ett staff-konto, loggar, `last_error` som visas för medlemmar, Coolify-token som når hela teamet. Inga fynd utöver Review Focus → fortsätt; nya fynd → AskUserQuestion.

- [ ] **Steg 2: Gör lcobucci/jwt till ett uttryckligt beroende.**

```bash
cd $WT && composer require lcobucci/jwt:^5.6
```

Förväntat: `composer.json` får `"lcobucci/jwt": "^5.6"` under `require`; låsfilen behåller 5.6.1 ("Nothing to install, update or remove" eller bara en uppdaterad content-hash).

- [ ] **Steg 3: Skriv det fallerande testet.** `tests/Feature/Provisioning/ProvisioningConfigTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\ProvisioningConfig;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class ProvisioningConfigTest extends TestCase
{
    public function test_nothing_missing_when_configured(): void
    {
        ProvisioningFakes::configure();

        $this->assertSame([], ProvisioningConfig::missing());
    }

    public function test_missing_settings_are_named_by_env_variable(): void
    {
        ProvisioningFakes::configure();
        config(['provisioning.coolify.token' => '', 'provisioning.slack_webhook_url' => null]);

        $missing = ProvisioningConfig::missing();

        $this->assertContains('COOLIFY_TOKEN', $missing);
        $this->assertContains('SLACK_ALERT_WEBHOOK_URL', $missing);
        $this->assertNotContains('SITEOPS_API_KEY', $missing);
    }

    public function test_private_key_must_exist_with_mode_600(): void
    {
        ProvisioningFakes::configure();
        chmod(ProvisioningConfig::privateKeyFile(), 0644);

        $this->assertContains('GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)', ProvisioningConfig::missing());

        chmod(ProvisioningConfig::privateKeyFile(), 0600);
        config(['provisioning.github.private_key_path' => 'framework/testing/finns-inte.pem']);

        $this->assertContains('GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)', ProvisioningConfig::missing());
    }

    public function test_derived_names(): void
    {
        ProvisioningFakes::configure();

        $this->assertSame('https://kattsvep.apps.humblebrag.se', ProvisioningConfig::demoUrl('kattsvep'));
        $this->assertSame('spel-kattsvep', ProvisioningConfig::coolifyAppName('kattsvep'));
        $this->assertSame('HumblebragAB', ProvisioningConfig::githubOwner());
        $this->assertSame('HumblebragAB/kattsvep', ProvisioningConfig::repoFullName('kattsvep'));
    }

    public function test_reserved_slugs_include_base_list_and_env_list(): void
    {
        $this->assertContains('www', config('provisioning.reserved_slugs'));
        $this->assertContains('spel', config('provisioning.reserved_slugs'));
        $this->assertNotContains('', config('provisioning.reserved_slugs'));
    }
}
```

Testet kräver `ProvisioningFakes` från Task 3; skriv därför Task 3 steg 1 (`tests/Support/ProvisioningFakes.php`) innan testet körs första gången, eller kör testet efter Task 3. Ordningen inom Task 2–3 är: Task 2 steg 2–4, Task 3 steg 1, Task 2 steg 5–9.

- [ ] **Steg 4: Kör testet och se det falla.**

```bash
cd $WT && php artisan test --filter=ProvisioningConfigTest
```

Förväntat: FAIL, `Class "App\Provisioning\ProvisioningConfig" not found`.

- [ ] **Steg 5: `config/provisioning.php`.**

```php
<?php

// Provisionering av Demo-spel (spec 2026-10-13, delprojekt 2c). Klienterna
// läser bara härifrån. Värdena hamnar aldrig i verktygssvar eller loggar.
return [
    'github' => [
        'api_url' => 'https://api.github.com',
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        // Relativ till storage/, t.ex. app/private/github-app.pem. Filen ska ha läge 600.
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
        'template_repo' => env('GITHUB_TEMPLATE_REPO', 'HumblebragAB/spelmall'),
    ],

    'coolify' => [
        // Utan /api/v1; klienten lägger till det.
        'url' => env('COOLIFY_URL'),
        'token' => env('COOLIFY_TOKEN'),
        'project_uuid' => env('COOLIFY_PROJECT_UUID'),
        'environment_name' => env('COOLIFY_ENVIRONMENT_NAME', 'production'),
        'server_uuid' => env('COOLIFY_SERVER_UUID'),
        'github_app_uuid' => env('COOLIFY_GITHUB_APP_UUID'),
    ],

    'siteops' => [
        // Utan /api/v1; klienten lägger till det.
        'url' => env('SITEOPS_URL'),
        'api_key' => env('SITEOPS_API_KEY'),
    ],

    'demo' => [
        'domain_suffix' => 'apps.humblebrag.se',
        // Konvention (demo/demo), inte en hemlighet. Visas för spelets medlemmar.
        'basic_auth_user' => env('DEMO_BASIC_AUTH_USER'),
        'basic_auth_password' => env('DEMO_BASIC_AUTH_PASSWORD'),
    ],

    'slack_webhook_url' => env('SLACK_ALERT_WEBHOOK_URL'),

    // Slugs som aldrig får bli spel: fasta namn plus befintliga underdomäner
    // under apps.humblebrag.se (uppdateras när Coolify-appar läggs till för hand).
    'reserved_slugs' => array_values(array_unique(array_filter(array_merge(
        ['www', 'api', 'admin', 'app', 'apps', 'demo', 'mail', 'spel'],
        array_map('trim', explode(',', (string) env('PROVISIONING_RESERVED_SUBDOMAINS', ''))),
    ), fn ($slug) => $slug !== ''))),

    'max_demo_games' => 15,
    'max_new_per_day' => 5,
    'deploy_timeout_minutes' => 15,
    'repo_ready_timeout_minutes' => 3,
    'coolify_delete_timeout_minutes' => 10,
    'http_timeout_seconds' => 20,
];
```

- [ ] **Steg 6: `app/Provisioning/SafeMessage.php` och `app/Provisioning/ProvisioningConfig.php`.**

```php
<?php

namespace App\Provisioning;

// Markör för undantag vars meddelande är redigerat och får visas för spelets
// medlemmar (last_error, get_provisioning_status). Alla andra undantag visas
// bara som en referens.
interface SafeMessage
{
}
```

```php
<?php

namespace App\Provisioning;

final class ProvisioningConfig
{
    /** env-namn => config-nyckel. Allt här krävs innan create_game skapar något. */
    private const REQUIRED = [
        'GITHUB_APP_CLIENT_ID' => 'provisioning.github.client_id',
        'GITHUB_APP_INSTALLATION_ID' => 'provisioning.github.installation_id',
        'GITHUB_APP_PRIVATE_KEY_PATH' => 'provisioning.github.private_key_path',
        'GITHUB_TEMPLATE_REPO' => 'provisioning.github.template_repo',
        'COOLIFY_URL' => 'provisioning.coolify.url',
        'COOLIFY_TOKEN' => 'provisioning.coolify.token',
        'COOLIFY_PROJECT_UUID' => 'provisioning.coolify.project_uuid',
        'COOLIFY_ENVIRONMENT_NAME' => 'provisioning.coolify.environment_name',
        'COOLIFY_SERVER_UUID' => 'provisioning.coolify.server_uuid',
        'COOLIFY_GITHUB_APP_UUID' => 'provisioning.coolify.github_app_uuid',
        'SITEOPS_URL' => 'provisioning.siteops.url',
        'SITEOPS_API_KEY' => 'provisioning.siteops.api_key',
        'DEMO_BASIC_AUTH_USER' => 'provisioning.demo.basic_auth_user',
        'DEMO_BASIC_AUTH_PASSWORD' => 'provisioning.demo.basic_auth_password',
        'SLACK_ALERT_WEBHOOK_URL' => 'provisioning.slack_webhook_url',
    ];

    public const KEY_FILE_PROBLEM = 'GITHUB_APP_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)';

    /** @return list<string> env-namnen som saknas; aldrig några värden. */
    public static function missing(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $env => $key) {
            $value = config($key);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $env;
            }
        }

        if (! in_array('GITHUB_APP_PRIVATE_KEY_PATH', $missing, true) && ! self::privateKeyUsable()) {
            $missing[] = self::KEY_FILE_PROBLEM;
        }

        return $missing;
    }

    public static function privateKeyFile(): string
    {
        return storage_path((string) config('provisioning.github.private_key_path'));
    }

    public static function demoUrl(string $slug): string
    {
        return 'https://'.$slug.'.'.config('provisioning.demo.domain_suffix');
    }

    public static function coolifyAppName(string $slug): string
    {
        return 'spel-'.$slug;
    }

    public static function templateRepo(): string
    {
        return (string) config('provisioning.github.template_repo');
    }

    public static function githubOwner(): string
    {
        return explode('/', self::templateRepo(), 2)[0];
    }

    public static function repoFullName(string $slug): string
    {
        return self::githubOwner().'/'.$slug;
    }

    // Nyckeln får inte vara läsbar för grupp eller andra.
    private static function privateKeyUsable(): bool
    {
        $file = self::privateKeyFile();

        return is_file($file) && is_readable($file) && (fileperms($file) & 0077) === 0;
    }
}
```

- [ ] **Steg 7: `phpunit.xml`.** Lägg till efter `<env name="NIGHTWATCH_ENABLED" value="false"/>`, så att en lokal `.env` med riktiga värden aldrig når testerna:

```xml
        <env name="GITHUB_APP_CLIENT_ID" value=""/>
        <env name="GITHUB_APP_INSTALLATION_ID" value=""/>
        <env name="GITHUB_APP_PRIVATE_KEY_PATH" value=""/>
        <env name="COOLIFY_URL" value=""/>
        <env name="COOLIFY_TOKEN" value=""/>
        <env name="COOLIFY_PROJECT_UUID" value=""/>
        <env name="COOLIFY_SERVER_UUID" value=""/>
        <env name="COOLIFY_GITHUB_APP_UUID" value=""/>
        <env name="SITEOPS_URL" value=""/>
        <env name="SITEOPS_API_KEY" value=""/>
        <env name="DEMO_BASIC_AUTH_USER" value=""/>
        <env name="DEMO_BASIC_AUTH_PASSWORD" value=""/>
        <env name="SLACK_ALERT_WEBHOOK_URL" value=""/>
        <env name="PROVISIONING_RESERVED_SUBDOMAINS" value=""/>
```

- [ ] **Steg 8: `.env.example`.** Lägg till sist:

```bash
# Provisionering av Demo-spel (config/provisioning.php). Värdena finns bara på Forge.
GITHUB_APP_CLIENT_ID=
GITHUB_APP_INSTALLATION_ID=
# Relativ till storage/, filen ska ha läge 600
GITHUB_APP_PRIVATE_KEY_PATH=app/private/github-app.pem
GITHUB_TEMPLATE_REPO=HumblebragAB/spelmall
COOLIFY_URL=
COOLIFY_TOKEN=
COOLIFY_PROJECT_UUID=
COOLIFY_ENVIRONMENT_NAME=production
COOLIFY_SERVER_UUID=
COOLIFY_GITHUB_APP_UUID=
SITEOPS_URL=
SITEOPS_API_KEY=
DEMO_BASIC_AUTH_USER=demo
DEMO_BASIC_AUTH_PASSWORD=demo
SLACK_ALERT_WEBHOOK_URL=
# Kommaseparerade befintliga underdomäner under apps.humblebrag.se
PROVISIONING_RESERVED_SUBDOMAINS=
```

- [ ] **Steg 9: Kör testet.**

```bash
cd $WT && php artisan test --filter=ProvisioningConfigTest
```

Förväntat: `Tests: 5 passed`.

- [ ] **Steg 10: Commit.**

```bash
cd $WT && git add composer.json composer.lock phpunit.xml .env.example config/provisioning.php app/Provisioning tests/Feature/Provisioning/ProvisioningConfigTest.php && git commit -m "Provisionering: konfiguration, lcobucci/jwt och kontroll av saknade inställningar"
```

---

## Task 3: Testvakter och fejkade svar efter bilagorna

**Files:**
- Create: `tests/Support/ProvisioningFakes.php`, `tests/Support/CreatesProvisioningRuns.php`, `tests/Support/AssertsNoHttpInTransaction.php`, `tests/Feature/Provisioning/ProvisioningTestCase.php`, `tests/Feature/Provisioning/GlobalHttpGuardTest.php`
- Modify: `tests/TestCase.php`

**Interfaces:**
- Produces:
  - `Tests\TestCase::$githubDeletes` (static list) och vakten i `setUp()`/`tearDown()`.
  - `Tests\Support\ProvisioningFakes::configure(): void`, `::keyPair(): array{private: string, public: string}` och svarsbyggarna nedan.
  - `Tests\Support\CreatesProvisioningRuns::provisioningRun(array $attrs = []): ProvisioningRun` (används från Task 8).
  - `Tests\Support\AssertsNoHttpInTransaction` (trait med `startHttpTransactionGuard()` och `assertNoHttpInTransaction()`).
  - `Tests\Feature\Provisioning\ProvisioningTestCase` (RefreshDatabase, konfigurerad, vakten aktiv).

- [ ] **Steg 1: `tests/Support/ProvisioningFakes.php`.** Varje svar är byggt efter bilaga A/B eller SiteOps-kontraktet; former som spiken verifierade (S10–S15) är markerade och justeras om Task 1 steg 18 sa något annat.

```php
<?php

namespace Tests\Support;

// Konfiguration och fejkade svar för provisioneringstesterna. Svarsformerna
// följer bilaga A (Coolify v4.4.6), bilaga B (GitHub) och SiteOps-kontraktet
// i planen. Rader märkta "spike Sx" är verifierade i Task 1.
final class ProvisioningFakes
{
    public const GITHUB = 'https://api.github.com';

    public const COOLIFY = 'https://coolify.test';

    public const SITEOPS = 'https://siteops.test';

    public const SLACK = 'https://hooks.slack.test/services/T000/B000/hemligsokvag123';

    public const PROJECT = 'demo-project-uuid';

    public const ENVIRONMENT = 'production';

    public const COOLIFY_TOKEN = 'coolify-secret-token-123';

    public const SITEOPS_KEY = 'siteops-secret-key-456';

    public const KEY_FILE = 'framework/testing/github-app-test.pem';

    private static ?array $keyPair = null;

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
        }

        return self::$keyPair;
    }

    public static function configure(): void
    {
        $path = storage_path(self::KEY_FILE);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, self::keyPair()['private']);
        chmod($path, 0600);

        config([
            'provisioning.github.client_id' => 'Iv23testclientid',
            'provisioning.github.installation_id' => '4242',
            'provisioning.github.private_key_path' => self::KEY_FILE,
            'provisioning.github.template_repo' => 'HumblebragAB/spelmall',
            'provisioning.coolify.url' => self::COOLIFY,
            'provisioning.coolify.token' => self::COOLIFY_TOKEN,
            'provisioning.coolify.project_uuid' => self::PROJECT,
            'provisioning.coolify.environment_name' => self::ENVIRONMENT,
            'provisioning.coolify.server_uuid' => 'server-uuid',
            'provisioning.coolify.github_app_uuid' => 'coolify-github-app-uuid',
            'provisioning.siteops.url' => self::SITEOPS,
            'provisioning.siteops.api_key' => self::SITEOPS_KEY,
            'provisioning.demo.basic_auth_user' => 'demo',
            'provisioning.demo.basic_auth_password' => 'demo',
            'provisioning.slack_webhook_url' => self::SLACK,
        ]);
    }

    // --- GitHub (bilaga B) ---

    /** POST /app/installations/{id}/access_tokens, 201. */
    public static function githubToken(string $token = 'ghs_testinstallationtoken000000000001'): array
    {
        return [
            'token' => $token,
            'expires_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            'permissions' => ['administration' => 'write', 'contents' => 'write', 'metadata' => 'read'],
            'repository_selection' => 'selected',
            'repositories' => [],
        ];
    }

    /** GET /repos/{owner}/{repo} och 201 från /generate (spike S10). */
    public static function githubRepo(string $slug, string $runUuid, bool $archived = false, ?string $template = 'HumblebragAB/spelmall', ?string $description = null): array
    {
        return [
            'id' => 900000 + strlen($slug),
            'name' => $slug,
            'full_name' => "HumblebragAB/{$slug}",
            'private' => true,
            'archived' => $archived,
            'description' => $description ?? "Computercat-spel {$slug} (run {$runUuid})",
            'template_repository' => $template === null ? null : ['full_name' => $template],
        ];
    }

    public static function templateReadme(): string
    {
        return "# Spel\n\nBeskrivning.\n\n<!-- computercat:idea:start -->\n_Idén fylls i av create_game när spelet skapas._\n<!-- computercat:idea:end -->\n\nMer text.\n";
    }

    /** GET /repos/{o}/{r}/contents/{path} (spike S11). GitHub radbryter base64. */
    public static function githubContent(string $text, string $sha = 'readme-sha-1'): array
    {
        return ['sha' => $sha, 'encoding' => 'base64', 'content' => chunk_split(base64_encode($text), 60, "\n")];
    }

    // --- Coolify (bilaga A) ---

    /** GET /projects/{uuid}/{environment_name} (spike S13). */
    public static function coolifyProject(array $applications): array
    {
        return ['id' => 1, 'name' => self::ENVIRONMENT, 'applications' => $applications];
    }

    public static function coolifyApp(string $uuid, string $name, bool $basicAuth = true): array
    {
        return ['uuid' => $uuid, 'name' => $name, 'is_http_basic_auth_enabled' => $basicAuth];
    }

    /** POST /applications/private-github-app, 201. */
    public static function coolifyCreated(string $uuid): array
    {
        return ['uuid' => $uuid];
    }

    /** 409 vid domänkonflikt. Innehållet läses inte av klienten. */
    public static function coolifyConflict(): array
    {
        return ['message' => 'Domain conflicts detected.', 'warning' => 'Using the same domain for multiple resources can cause routing conflicts.', 'conflicts' => []];
    }

    /** GET /applications/{uuid}/envs (spike S15), EnvironmentVariable-schemat. */
    public static function coolifyEnv(string $key, ?string $value, bool $buildtime = true): array
    {
        return ['uuid' => 'env-'.strtolower($key), 'key' => $key, 'value' => $value, 'is_buildtime' => $buildtime, 'is_runtime' => false, 'is_preview' => false];
    }

    /** POST /deploy?uuid=. */
    public static function coolifyDeployQueued(string $appUuid, string $deploymentUuid): array
    {
        return ['deployments' => [['message' => 'Deployment request queued.', 'resource_uuid' => $appUuid, 'deployment_uuid' => $deploymentUuid]]];
    }

    /** GET /deployments/{uuid}, ApplicationDeploymentQueue. */
    public static function coolifyDeployment(string $status, string $logs = 'Building... token=hemligt'): array
    {
        return ['status' => $status, 'logs' => $logs];
    }

    /** DELETE /applications/{uuid}, 200. */
    public static function coolifyDeleteQueued(): array
    {
        return ['message' => 'Application deletion request queued.'];
    }

    // --- SiteOps (låst kontrakt i 2a, "Låst API-kontrakt") ---

    /** Webbdetalj (formatSiteListItem + formatSiteDetail, 2a Task 6 lägger till monitoring_enabled). */
    public static function siteopsSiteData(int $id, string $url, bool $active = true, bool $monitoring = true): array
    {
        return [
            'id' => $id, 'name' => 'Spel', 'url' => $url, 'type' => 'app', 'is_active' => $active,
            'is_online' => false, 'ops_tier' => 2, 'ops_mode' => 'supervised', 'solution_id' => 51,
            'monitoring_enabled' => $monitoring,
        ];
    }

    /** Miljö som 2a:s formatApiEnvironment serialiserar den (PATCH-svar och environment i POST-svaret). */
    public static function siteopsEnvironment(int $siteId, string $url, int $id = 7, bool $monitored = true, bool $basicAuth = true, string $type = 'demo'): array
    {
        return ['id' => $id, 'site_id' => $siteId, 'name' => 'Demo', 'type' => $type, 'url' => $url, 'is_monitored' => $monitored, 'has_basic_auth' => $basicAuth];
    }

    /** POST /sites med environment, 201. */
    public static function siteopsCreated(int $id, string $url = 'https://kattsvep.apps.humblebrag.se', int $environmentId = 7): array
    {
        return ['data' => self::siteopsSiteData($id, $url) + ['environment' => self::siteopsEnvironment($id, $url, $environmentId)]];
    }

    /** POST /sites, 409 när adressen redan finns i teamet. */
    public static function siteopsConflict(int $existingId): array
    {
        return ['error' => 'A site with this URL already exists in the team.', 'site_id' => $existingId];
    }

    /** GET /sites?url= */
    public static function siteopsList(array $sites): array
    {
        return ['data' => $sites, 'meta' => ['total' => count($sites)]];
    }

    /** GET /sites/{id}. */
    public static function siteopsSite(int $id, string $url, bool $active = true, bool $monitoring = true): array
    {
        return ['data' => self::siteopsSiteData($id, $url, $active, $monitoring)];
    }

    /** GET /sites/{id}/environments (2a Task 6 lägger till type och has_basic_auth). */
    public static function siteopsEnvironments(string $url, bool $monitored = true, bool $basicAuth = true, string $type = 'demo'): array
    {
        return ['data' => [['id' => 7, 'name' => 'Demo', 'type' => $type, 'url' => $url, 'sort_order' => 0, 'is_monitored' => $monitored, 'has_basic_auth' => $basicAuth]], 'meta' => ['total' => 1]];
    }

    /** PATCH /sites/{id}/environments/{env}, 200. */
    public static function siteopsEnvironmentUpdated(int $siteId, string $url, int $envId = 7): array
    {
        return ['data' => self::siteopsEnvironment($siteId, $url, $envId)];
    }

    /** POST /sites/{id}/deactivate, 200. */
    public static function siteopsDeactivated(int $id): array
    {
        return ['data' => ['id' => $id, 'is_active' => false, 'monitoring_enabled' => false]];
    }
}
```

- [ ] **Steg 2: Skriv det fallerande vakttestet.** `tests/Feature/Provisioning/GlobalHttpGuardTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalHttpGuardTest extends TestCase
{
    public function test_delete_to_github_is_stopped_and_recorded_even_when_faked(): void
    {
        Http::fake();

        try {
            Http::delete('https://api.github.com/repos/HumblebragAB/kattsvep');
            $this->fail('DELETE mot GitHub släpptes igenom.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('DELETE mot api.github.com', $e->getMessage());
        }

        $this->assertSame(['https://api.github.com/repos/HumblebragAB/kattsvep'], TestCase::$githubDeletes);
        // Nollställs så att tearDown inte fäller testet; vakten är bevisad.
        TestCase::$githubDeletes = [];
    }

    public function test_other_methods_and_hosts_pass(): void
    {
        Http::fake();

        Http::get('https://api.github.com/repos/HumblebragAB/kattsvep');
        Http::delete('https://coolify.test/api/v1/applications/x');

        Http::assertSentCount(2);
    }

    public function test_unfaked_requests_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        Http::get('https://example.invalid/');
    }
}
```

- [ ] **Steg 3: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=GlobalHttpGuardTest
```

Förväntat: FAIL, `Access to undeclared static property Tests\TestCase::$githubDeletes`.

- [ ] **Steg 4: `tests/TestCase.php`.**

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> DELETE-anrop mot api.github.com under testet. */
    public static array $githubDeletes = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$githubDeletes = [];

        // Inget test får nå nätet; ett ofejkat anrop kastar.
        Http::preventStrayRequests();

        // Spec 2c: koden skickar aldrig DELETE till GitHub. Vakten ligger före
        // fejken och gäller hela sviten. Anropet registreras också statiskt,
        // eftersom sagan fångar alla undantag och annars kunde dölja det.
        Http::globalRequestMiddleware(function (RequestInterface $request) {
            if (strtoupper($request->getMethod()) === 'DELETE' && strtolower($request->getUri()->getHost()) === 'api.github.com') {
                self::$githubDeletes[] = (string) $request->getUri();

                throw new \LogicException('DELETE mot api.github.com är förbjudet.');
            }

            return $request;
        });
    }

    protected function tearDown(): void
    {
        $deletes = self::$githubDeletes;

        parent::tearDown();

        $this->assertSame([], $deletes, 'Koden skickade DELETE till api.github.com: '.implode(', ', $deletes));
    }
}
```

- [ ] **Steg 5: Kör vakttestet och hela sviten.**

```bash
cd $WT && php artisan test --filter=GlobalHttpGuardTest && php artisan test
```

Förväntat: `GlobalHttpGuardTest` 3 passed; hela sviten grön. Fäller `preventStrayRequests()` ett befintligt test (det gjorde då ett riktigt HTTP-anrop): fejka inte på eget initiativ, lista testen och fråga Erik med AskUserQuestion.

- [ ] **Steg 6: `tests/Support/AssertsNoHttpInTransaction.php`.**

```php
<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Spec 2c: "Ingen HTTP-anrop sker inne i en databastransaktion." Varje
// HTTP-anrop under testet registrerar transaktionsnivån; den ska vara
// densamma som när testet började (RefreshDatabase har då öppnat sin egen).
trait AssertsNoHttpInTransaction
{
    /** @var list<array{level: int, url: string}> */
    private array $httpTransactionLevels = [];

    private int $baseTransactionLevel = 0;

    protected function startHttpTransactionGuard(): void
    {
        $this->baseTransactionLevel = DB::transactionLevel();
        Http::globalRequestMiddleware(function ($request) {
            $this->httpTransactionLevels[] = ['level' => DB::transactionLevel(), 'url' => (string) $request->getUri()];

            return $request;
        });
    }

    protected function assertNoHttpInTransaction(): void
    {
        foreach ($this->httpTransactionLevels as $call) {
            $this->assertSame($this->baseTransactionLevel, $call['level'], "HTTP-anrop inne i en databastransaktion: {$call['url']}");
        }
    }
}
```

- [ ] **Steg 7: `tests/Feature/Provisioning/ProvisioningTestCase.php` och `tests/Support/CreatesProvisioningRuns.php`.** `CreatesProvisioningRuns` refererar `ProvisioningRun` som skapas i Task 8; filen skrivs här och används först där.

```php
<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

abstract class ProvisioningTestCase extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesProvisioningRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }
}
```

```php
<?php

namespace Tests\Support;

use App\Models\ProvisioningRun;
use Illuminate\Support\Str;

trait CreatesProvisioningRuns
{
    protected function provisioningRun(array $attrs = []): ProvisioningRun
    {
        $slug = $attrs['slug'] ?? 's'.Str::lower(Str::random(8));
        $status = $attrs['status'] ?? ProvisioningRun::RUNNING;
        $kind = $attrs['kind'] ?? ProvisioningRun::KIND_PROVISION;

        return ProvisioningRun::create(array_merge([
            'slug' => $slug,
            // Bara provisioneringsruns som inte är released håller slugen.
            'slug_lock' => $kind === ProvisioningRun::KIND_PROVISION && $status !== ProvisioningRun::RELEASED ? $slug : null,
            'name' => ucfirst($slug),
            'user_id' => null,
            'game_id' => null,
            'kind' => $kind,
            'status' => $status,
            'idea' => 'En idé',
        ], $attrs));
    }
}
```

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add tests/TestCase.php tests/Support tests/Feature/Provisioning && git commit -m "Test: vakt mot DELETE till GitHub, inga ofejkade anrop och fejkade svar efter bilagorna"
```

---

## Task 4: Redigering, loggning av API-fel och gemensam anropsmetod

**Files:**
- Create: `app/Provisioning/Http/Redactor.php`, `app/Provisioning/Http/ExternalApiException.php`, `app/Provisioning/Http/ApiCallLogger.php`, `app/Provisioning/Http/SendsApiRequests.php`
- Test: `tests/Unit/Provisioning/RedactorTest.php`, `tests/Feature/Provisioning/ApiCallLoggerTest.php`

**Interfaces:**
- Consumes: `config('provisioning.*')`.
- Produces:
  - `Redactor::redact(mixed $data): mixed`, `::body(string $raw): string`, `::scrubSecrets(string $text): string`, `::limit(string $text, int $max = Redactor::MAX_BYTES): string`, `::registerSecret(string $secret): void`, `Redactor::MAX_BYTES = 4096`.
  - `ExternalApiException(string $service, string $method, string $path, int $status, string $redactedBody)` med publika `readonly` `service`, `method`, `path`, `status`; implementerar `SafeMessage`.
  - `ApiCallLogger::log(string $service, string $method, string $url, ?array $requestBody, Response $response): void`.
  - Trait `SendsApiRequests` med abstrakta `serviceName(): string`, `baseUrl(): string` och `sendRequest(PendingRequest $request, string $method, string $path, ?array $body = null, array $accept = []): Response`.

- [ ] **Steg 1: Fallerande enhetstest.** `tests/Unit/Provisioning/RedactorTest.php`:

```php
<?php

namespace Tests\Unit\Provisioning;

use App\Provisioning\Http\Redactor;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    public function test_sensitive_keys_are_redacted_recursively(): void
    {
        $out = Redactor::redact([
            'name' => 'spel-kattsvep',
            'http_basic_auth_password' => 'x1',
            'environment' => ['basic_auth_password' => 'x2', 'url' => 'https://k.apps.humblebrag.se'],
            'Authorization' => 'Bearer x3',
            'private_key' => 'x4',
            'token' => 'x5',
            'value' => 'x6',
            'logs' => 'x7',
            'X-Api-Key' => 'x8',
            'list' => [['value' => 'x9', 'key' => 'NEXT_PUBLIC_COMPUTERCAT_GAME']],
        ]);

        $flat = json_encode($out);
        foreach (['x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7', 'x8', 'x9'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}", $flat, $secret);
        }
        $this->assertSame('spel-kattsvep', $out['name']);
        $this->assertSame('https://k.apps.humblebrag.se', $out['environment']['url']);
        $this->assertSame('NEXT_PUBLIC_COMPUTERCAT_GAME', $out['list'][0]['key']);
    }

    public function test_known_secrets_and_github_tokens_are_scrubbed_from_text(): void
    {
        ProvisioningFakes::configure();
        Redactor::registerSecret('ghs_issuedtoken1234567890abcdefXYZ');

        $text = Redactor::scrubSecrets('a '.ProvisioningFakes::COOLIFY_TOKEN.' b '.ProvisioningFakes::SITEOPS_KEY.' c '.ProvisioningFakes::SLACK.' d ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ12 e ghs_issuedtoken1234567890abcdefXYZ');

        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $text);
        $this->assertStringNotContainsString(ProvisioningFakes::SITEOPS_KEY, $text);
        $this->assertStringNotContainsString('hemligsokvag123', $text);
        $this->assertStringNotContainsString('ghp_ABCDEFGHIJ', $text);
        $this->assertStringNotContainsString('ghs_issuedtoken', $text);
    }

    public function test_body_redacts_json_and_limits_size(): void
    {
        $body = Redactor::body(json_encode(['message' => 'fel', 'logs' => str_repeat('L', 10000)]));
        $this->assertStringNotContainsString('LLLL', $body);
        $this->assertStringContainsString('fel', $body);

        $long = Redactor::body(str_repeat('å', 5000));
        $this->assertLessThanOrEqual(Redactor::MAX_BYTES, strlen($long));
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));
        $this->assertStringEndsWith('…', $long);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=RedactorTest
```

Förväntat: FAIL, `Class "App\Provisioning\Http\Redactor" not found`.

- [ ] **Steg 3: `app/Provisioning/Http/Redactor.php`.**

```php
<?php

namespace App\Provisioning\Http;

// Allt som lämnar klienterna som text (loggar, last_error, Slack) går hit.
// Specen 2c: Authorization, private_key, password, token, value och Coolifys
// logs tas bort; max 4 KB.
final class Redactor
{
    public const MAX_BYTES = 4096;

    private const MASK = '[REDACTED]';

    /** @var list<string> hemligheter som utfärdats under processens livstid (GitHub-tokens) */
    private static array $runtimeSecrets = [];

    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_string($key) && self::sensitiveKey($key) ? self::MASK : self::redact($value);
        }

        return $out;
    }

    public static function body(string $raw): string
    {
        $decoded = json_decode($raw, true);
        $text = is_array($decoded)
            ? (string) json_encode(self::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            : $raw;

        return self::limit(self::scrubSecrets($text));
    }

    public static function scrubSecrets(string $text): string
    {
        $configured = array_filter([
            config('provisioning.coolify.token'),
            config('provisioning.siteops.api_key'),
            config('provisioning.slack_webhook_url'),
        ], fn ($secret) => is_string($secret) && strlen($secret) >= 8);

        $secrets = array_values(array_merge(self::$runtimeSecrets, $configured));
        if ($secrets !== []) {
            $text = str_replace($secrets, self::MASK, $text);
        }

        return preg_replace('/\b(gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/', self::MASK, $text) ?? $text;
    }

    public static function limit(string $text, int $max = self::MAX_BYTES): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        // mb_strcut klipper aldrig mitt i ett tecken.
        return mb_strcut($text, 0, $max - strlen('…'), 'UTF-8').'…';
    }

    public static function registerSecret(string $secret): void
    {
        if (strlen($secret) < 8 || in_array($secret, self::$runtimeSecrets, true)) {
            return;
        }
        self::$runtimeSecrets[] = $secret;
        // Kö-workern lever länge; de senaste räcker (tokens gäller en timme).
        self::$runtimeSecrets = array_slice(self::$runtimeSecrets, -20);
    }

    private static function sensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, ['value', 'logs'], true)
            || preg_match('/authorization|private_key|password|token|api_key|api-key/', $key) === 1;
    }
}
```

- [ ] **Steg 4: `ExternalApiException`, `ApiCallLogger`, `SendsApiRequests`.**

```php
<?php

namespace App\Provisioning\Http;

use App\Provisioning\SafeMessage;

// Meddelandet visas för spelets medlemmar: bara tjänst, metod, sökväg (utan
// värd), status och redigerad body.
final class ExternalApiException extends \RuntimeException implements SafeMessage
{
    public function __construct(
        public readonly string $service,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        string $redactedBody,
    ) {
        $what = $status === 0 ? 'failed to connect' : "returned {$status}";
        parent::__construct(Redactor::limit(Redactor::scrubSecrets("{$service} {$method} {$path} {$what}: {$redactedBody}")));
    }
}
```

```php
<?php

namespace App\Provisioning\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

// CLAUDE.md: 4xx/5xx loggas med request och response, redigerat och kortat.
final class ApiCallLogger
{
    public static function log(string $service, string $method, string $url, ?array $requestBody, Response $response): void
    {
        $request = $requestBody === null
            ? null
            : Redactor::limit(Redactor::scrubSecrets((string) json_encode(Redactor::redact($requestBody), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));

        Log::warning("{$service} API svarade {$response->status()}", [
            'method' => $method,
            'url' => Redactor::scrubSecrets($url),
            'status' => $response->status(),
            'request' => $request,
            'response' => Redactor::body($response->body()),
        ]);
    }
}
```

```php
<?php

namespace App\Provisioning\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

// Gemensam anropsväg för GitHub-, Coolify- och SiteOps-klienterna: allt som
// inte är 2xx loggas; det som inte uttryckligen accepteras kastas.
trait SendsApiRequests
{
    abstract protected function serviceName(): string;

    abstract protected function baseUrl(): string;

    /** @param list<int> $accept statusar utöver 2xx som returneras i stället för att kastas */
    protected function sendRequest(PendingRequest $request, string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        try {
            $response = $request->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            Log::warning($this->serviceName().' API: anslutningen misslyckades', ['method' => $method, 'url' => Redactor::scrubSecrets($this->baseUrl().$path)]);

            throw new ExternalApiException($this->serviceName(), $method, $path, 0, 'connection failed');
        }

        if (! $response->successful()) {
            ApiCallLogger::log($this->serviceName(), $method, $this->baseUrl().$path, $body, $response);
        }

        if ($response->successful() || in_array($response->status(), $accept, true)) {
            return $response;
        }

        throw new ExternalApiException($this->serviceName(), $method, $path, $response->status(), Redactor::body($response->body()));
    }
}
```

- [ ] **Steg 5: Feature-test för loggningen.** `tests/Feature/Provisioning/ApiCallLoggerTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Http\ApiCallLogger;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class ApiCallLoggerTest extends ProvisioningTestCase
{
    public function test_log_has_method_url_status_and_redacted_bodies(): void
    {
        Http::fake(['*' => Http::response(['message' => 'bad', 'token' => 'svar-token-hemlig'], 422)]);
        $response = Http::post('https://coolify.test/api/v1/applications/private-github-app');
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message;
        });

        ApiCallLogger::log('Coolify', 'POST', 'https://coolify.test/api/v1/applications/private-github-app', ['name' => 'spel-x', 'http_basic_auth_password' => 'losen-hemligt'], $response);

        $this->assertCount(1, $logged);
        $context = json_encode($logged[0]->context);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertStringContainsString('"method":"POST"', $context);
        $this->assertStringContainsString('"status":422', $context);
        $this->assertStringContainsString('spel-x', $context);
        $this->assertStringNotContainsString('losen-hemligt', $context);
        $this->assertStringNotContainsString('svar-token-hemlig', $context);
    }

    public function test_exception_message_has_path_not_host_and_is_safe(): void
    {
        $e = new ExternalApiException('Coolify', 'GET', '/applications/abc', 500, '{"logs":"[REDACTED]"} '.ProvisioningFakes::COOLIFY_TOKEN);

        $this->assertSame('Coolify GET /applications/abc returned 500: {"logs":"[REDACTED]"} [REDACTED]', $e->getMessage());
        $this->assertStringNotContainsString('coolify.test', $e->getMessage());
    }
}
```

`Log::listen` tar emot `Illuminate\Log\Events\MessageLogged` med `level`, `message` och `context`.

- [ ] **Steg 6: Kör testen.**

```bash
cd $WT && php artisan test --filter='RedactorTest|ApiCallLoggerTest'
```

Förväntat: `Tests: 5 passed`.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Provisioning/Http tests/Unit/Provisioning tests/Feature/Provisioning/ApiCallLoggerTest.php && git commit -m "Provisionering: redigering, loggning av API-fel och gemensam anropsväg"
```

---

## Task 5: SlackAlert med dedupe per dygn

**Files:**
- Create: `app/Support/SlackAlert.php`
- Test: `tests/Feature/Provisioning/SlackAlertTest.php`

**Interfaces:**
- Consumes: `config('provisioning.slack_webhook_url')`, `Redactor::limit()`.
- Produces: `App\Support\SlackAlert::send(string $dedupeKey, string $text): string` som returnerar `SlackAlert::SENT | DUPLICATE | FAILED | NOT_CONFIGURED`; `SlackAlert::escape(string $text): string`. Kastar aldrig.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/SlackAlertTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Support\SlackAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class SlackAlertTest extends ProvisioningTestCase
{
    public function test_sends_text_to_webhook(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);

        $this->assertSame(SlackAlert::SENT, app(SlackAlert::class)->send('k1', 'Hej'));

        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && $r->method() === 'POST' && $r->data() === ['text' => 'Hej']);
    }

    public function test_same_key_is_sent_once_per_day(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
        $slack = app(SlackAlert::class);

        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'A'));
        $this->assertSame(SlackAlert::DUPLICATE, $slack->send('k1', 'B'));
        $this->assertSame(SlackAlert::SENT, $slack->send('k2', 'C'));
        $this->travel(1)->days();
        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'D'));

        Http::assertSentCount(3);
    }

    public function test_failure_is_logged_without_url_and_not_deduped(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::sequence()->push('nope', 500)->push('ok', 200)]);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m;
        });
        $slack = app(SlackAlert::class);

        $this->assertSame(SlackAlert::FAILED, $slack->send('k1', 'A'));
        $this->assertSame(SlackAlert::SENT, $slack->send('k1', 'A'));
        $this->assertStringNotContainsString('hemligsokvag123', json_encode(array_map(fn ($m) => [$m->message, $m->context], $logged)));
    }

    public function test_connection_error_never_throws(): void
    {
        Http::fake([ProvisioningFakes::SLACK => fn () => throw new ConnectionException('cURL error 28 '.ProvisioningFakes::SLACK)]);

        $this->assertSame(SlackAlert::FAILED, app(SlackAlert::class)->send('k1', 'A'));
    }

    public function test_not_configured_sends_nothing(): void
    {
        config(['provisioning.slack_webhook_url' => '']);
        Http::fake();

        $this->assertSame(SlackAlert::NOT_CONFIGURED, app(SlackAlert::class)->send('k1', 'A'));
        Http::assertNothingSent();
    }

    public function test_escape(): void
    {
        $this->assertSame('&lt;!channel&gt; &amp; x', SlackAlert::escape('<!channel> & x'));
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=SlackAlertTest
```

Förväntat: FAIL, `Class "App\Support\SlackAlert" not found`.

- [ ] **Steg 3: `app/Support/SlackAlert.php`.**

```php
<?php

namespace App\Support;

use App\Provisioning\Http\Redactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Larm till Eriks Slack via inkommande webhook. Samma nyckel skickas högst en
// gång per dygn. Ett fel loggas men stoppar aldrig anroparen; webhook-URL:en
// är en hemlighet och loggas aldrig.
class SlackAlert
{
    public const SENT = 'sent';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    public function send(string $dedupeKey, string $text): string
    {
        $url = config('provisioning.slack_webhook_url');
        if (! is_string($url) || $url === '') {
            Log::warning('SlackAlert: SLACK_ALERT_WEBHOOK_URL saknas, larmet skickades inte', ['key' => $dedupeKey, 'text' => Redactor::limit($text)]);

            return self::NOT_CONFIGURED;
        }

        $cacheKey = 'slack-alert:'.now()->toDateString().':'.sha1($dedupeKey);
        if (! Cache::add($cacheKey, true, now()->endOfDay())) {
            return self::DUPLICATE;
        }

        try {
            $response = Http::timeout(5)->connectTimeout(5)->asJson()->post($url, ['text' => $text]);
            if ($response->successful()) {
                return self::SENT;
            }
            Log::warning('SlackAlert: webhooken svarade '.$response->status(), ['key' => $dedupeKey, 'body' => Redactor::limit($response->body(), 500)]);
        } catch (\Throwable $e) {
            Log::warning('SlackAlert: anropet misslyckades', ['key' => $dedupeKey, 'error' => get_class($e)]);
        }

        // Ett misslyckat larm ska kunna skickas igen samma dygn.
        Cache::forget($cacheKey);

        return self::FAILED;
    }

    // Slack tolkar <...> som länkar och kommandon (<!channel>); användarvärden escapas.
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
```

- [ ] **Steg 4: Kör testet.**

```bash
cd $WT && php artisan test --filter=SlackAlertTest
```

Förväntat: `Tests: 6 passed`.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Support/SlackAlert.php tests/Feature/Provisioning/SlackAlertTest.php && git commit -m "SlackAlert: larm via webhook med dedupe per nyckel och dygn"
```

---

## Task 6: Slugregel, reserverade namn, panelen och integrationsmanualen

**Files:**
- Create: `app/Rules/GameSlug.php`
- Modify: `app/Filament/Resources/GameResource.php` (slugfältet), `app/Services/IntegrationGuide.php`
- Test: `tests/Unit/GameSlugTest.php`, `tests/Feature/Filament/GameSlugPanelTest.php`, `tests/Feature/Mcp/IntegrationGuideTest.php` (nytt testfall)

**Interfaces:**
- Consumes: `config('provisioning.reserved_slugs')`.
- Produces: `App\Rules\GameSlug` (ValidationRule), `GameSlug::PATTERN`, `GameSlug::isValid(mixed $value): bool`, `GameSlug::isReserved(string $slug): bool`.

- [ ] **Steg 1: Fallerande enhetstest.** `tests/Unit/GameSlugTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Rules\GameSlug;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameSlugTest extends TestCase
{
    public static function valid(): array
    {
        return [['abc'], ['kattsvep'], ['a-b'], ['a1b2'], ['tocco'], ['glosis'], ['tells'], [str_repeat('a', 40)], ['e2e-spike']];
    }

    public static function invalid(): array
    {
        return [['ab'], [str_repeat('a', 41)], ['Abc'], ['1abc'], ['-abc'], ['abc-'], ['a--b'], ['a_b'], ['a.b'], ["abc\n"], ['åäö'], [''], [123], [null]];
    }

    #[DataProvider('valid')]
    public function test_valid(string $slug): void
    {
        $this->assertTrue(GameSlug::isValid($slug), $slug);
        $this->assertTrue(Validator::make(['slug' => $slug], ['slug' => [new GameSlug]])->passes(), $slug);
    }

    #[DataProvider('invalid')]
    public function test_invalid(mixed $slug): void
    {
        $this->assertFalse(GameSlug::isValid($slug));
        $this->assertTrue(Validator::make(['slug' => $slug], ['slug' => [new GameSlug]])->fails());
    }

    public function test_reserved_names_are_rejected(): void
    {
        config(['provisioning.reserved_slugs' => ['www', 'api', 'ordjakt']]);

        $this->assertTrue(Validator::make(['slug' => 'api'], ['slug' => [new GameSlug]])->fails());
        $this->assertTrue(Validator::make(['slug' => 'ordjakt'], ['slug' => [new GameSlug]])->fails());
        $this->assertTrue(Validator::make(['slug' => 'kattsvep'], ['slug' => [new GameSlug]])->passes());
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=GameSlugTest
```

Förväntat: FAIL, `Class "App\Rules\GameSlug" not found`.

- [ ] **Steg 3: `app/Rules/GameSlug.php`.**

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Slug för ett nytt spel: blir GitHub-reponamn, Coolify-namn och DNS-etikett
// (<slug>.apps.humblebrag.se). 3–40 tecken, gemener, siffror och enkla
// bindestreck, börjar med bokstav, slutar inte med bindestreck.
class GameSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){2,39}\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('The slug must be 3-40 characters: lowercase letters, digits and single hyphens, starting with a letter and not ending with a hyphen.');

            return;
        }

        if (self::isReserved($value)) {
            $fail('The slug is reserved.');
        }
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, (array) config('provisioning.reserved_slugs', []), true);
    }
}
```

- [ ] **Steg 4: Kör enhetstestet.**

```bash
cd $WT && php artisan test --filter=GameSlugTest
```

Förväntat: `Tests: 24 passed` (9 + 14 dataset-fall + 1).

- [ ] **Steg 5: Fallerande paneltest.** `tests/Feature/Filament/GameSlugPanelTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource\Pages\CreateGame;
use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GameSlugPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->staff()->create());
    }

    public function test_new_game_must_follow_slug_rule(): void
    {
        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'Bad_Slug', 'name' => 'X'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'api', 'name' => 'X'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateGame::class)
            ->fillForm(['slug' => 'kattsvep', 'name' => 'Kattsvep'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_existing_game_with_legacy_slug_can_always_be_edited(): void
    {
        $legacy = Game::create(['slug' => 'Gammal_Slug', 'name' => 'Gammal', 'is_active' => true, 'settings' => []]);

        Livewire::test(EditGame::class, ['record' => $legacy->getRouteKey()])
            ->fillForm(['name' => 'Nytt namn'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nytt namn', $legacy->fresh()->name);
    }
}
```

- [ ] **Steg 6: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=GameSlugPanelTest
```

Förväntat: FAIL i `test_new_game_must_follow_slug_rule` (`Bad_Slug` godkänns).

- [ ] **Steg 7: Slugfältet i `app/Filament/Resources/GameResource.php`.** Ersätt

```php
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('URL-friendly identifier (e.g., "tocco", "wordcraft")'),
```

med

```php
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        // Provisioneringens slugregel gäller bara nya spel; redigering
                        // av ett befintligt spel (tocco, tells, glosis) blockeras aldrig.
                        ->rule(new \App\Rules\GameSlug, fn (?Game $record) => $record === null)
                        ->helperText('Nya spel: 3-40 tecken, gemener, siffror och enkla bindestreck (t.ex. "kattsvep"). Blir också reponamn och underdomän.'),
```

- [ ] **Steg 8: Kör paneltestet och befintliga paneltester.**

```bash
cd $WT && php artisan test --filter='GameSlugPanelTest|ConfigRulesPanelTest|PanelValidationTest|GameDevKitTest'
```

Förväntat: alla gröna.

- [ ] **Steg 9: Fallerande test för integrationsmanualen.** Lägg till i `tests/Feature/Mcp/IntegrationGuideTest.php`:

```php
    // Spec 2c: spelets och topplistans slug går genom code(), även i kodblocken.
    // Ett äldre spel kan ha en slug som aldrig validerats av GameSlug.
    public function test_slugs_in_manual_cannot_break_out_of_code(): void
    {
        $game = $this->game('evil`slug|x');
        $game->leaderboardTypes()->create(['slug' => 'lb`x', 'name' => 'Time', 'sort_direction' => 'asc', 'score_label' => 'ms', 'period' => 'daily', 'max_entries_per_period' => 100, 'is_active' => true]);

        $manual = IntegrationGuide::manual($game, array_keys(IntegrationGuide::FEATURES));

        $this->assertStringNotContainsString('evil`slug', $manual);
        $this->assertStringNotContainsString('lb`x', $manual);
        $this->assertStringContainsString("/games/evil'slug\\|x/leaderboards/lb'x", $manual);
    }
```

- [ ] **Steg 10: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=test_slugs_in_manual_cannot_break_out_of_code
```

Förväntat: FAIL (`evil`slug` finns i URL-raderna).

- [ ] **Steg 11: Skicka alla slug-interpolationer genom `code()`.** Raderna 213, 280, 286, 309, 319, 340, 352, 372, 419, 446, 456, 482, 502, 512, 538, 576, 597 och 609 interpolerar `{$game->slug}` (och 213/280/286 `{$ex->slug}`) i dubbelciterade strängar. Byt mekaniskt och städa tomma avslutande konkateneringar:

```bash
cd $WT && perl -pi -e 's/\{\$game->slug\}/".self::code(\$game->slug)."/g; s/\{\$ex->slug\}/".self::code(\$ex->slug)."/g; s/\.""(?=;)//g' app/Services/IntegrationGuide.php
grep -n '{\$game->slug}\|{\$ex->slug}' app/Services/IntegrationGuide.php
```

Förväntat: `grep` skriver ingenting. Kontrollera rad 213 som exempel: `$lines[] = "POST {$baseUrl}/games/".self::code($game->slug)."/leaderboards/".self::code($ex->slug);`. Eventens slug skrivs redan via `self::code($event->slug)` (rad 109); rad 609 har bokstavligt `{slug}` och påverkas inte.

- [ ] **Steg 12: Kör manual-testerna och pint.**

```bash
cd $WT && php artisan test --filter=IntegrationGuideTest && vendor/bin/pint app/Services/IntegrationGuide.php app/Rules/GameSlug.php app/Filament/Resources/GameResource.php
```

Förväntat: alla IntegrationGuideTest gröna.

- [ ] **Steg 13: Commit.**

```bash
cd $WT && git add app/Rules/GameSlug.php app/Filament/Resources/GameResource.php app/Services/IntegrationGuide.php tests/Unit/GameSlugTest.php tests/Feature/Filament/GameSlugPanelTest.php tests/Feature/Mcp/IntegrationGuideTest.php && git commit -m "Slugregel för nya spel i panelen och slug genom code() i integrationsmanualen"
```

---

## Task 7: `games.is_listed` och den publika spellistan

**Files:**
- Create: `database/migrations/2026_10_15_100001_add_is_listed_to_games_table.php`
- Modify: `app/Models/Game.php`, `app/Http/Controllers/Api/V1/GameController.php`, `app/Filament/Resources/GameResource.php`
- Test: `tests/Feature/Api/V1/GameTest.php` (nya testfall)

**Interfaces:**
- Produces: kolumnen `games.is_listed` (boolean, default true), `Game::$fillable` + cast `is_listed`.

- [ ] **Steg 1: Fallerande test.** Lägg till i `tests/Feature/Api/V1/GameTest.php`:

```php
    // Spec 2c: Demo-spel är aktiva men olistade tills Erik listar dem vid Live.
    public function test_list_games_hides_unlisted_games(): void
    {
        Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);
        Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'is_listed' => false]);

        $this->getJson('/api/v1/games')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'tocco');
    }

    public function test_existing_games_are_listed_by_default(): void
    {
        $game = Game::create(['slug' => 'tocco', 'name' => 'Tocco', 'is_active' => true]);

        $this->assertTrue($game->fresh()->is_listed);
    }

    public function test_unlisted_game_still_works_for_players(): void
    {
        Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'is_listed' => false]);

        $this->getJson('/api/v1/games/kattsvep')->assertOk()->assertJsonPath('data.slug', 'kattsvep');
        $this->getJson('/api/v1/games/kattsvep/config')->assertOk();
    }
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=GameTest
```

Förväntat: FAIL, `table games has no column named is_listed`.

- [ ] **Steg 3: Migration.** `database/migrations/2026_10_15_100001_add_is_listed_to_games_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Default true: befintliga spel syns som förut i GET /api/v1/games.
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_listed')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('is_listed');
        });
    }
};
```

- [ ] **Steg 4: Modellen, controllern och panelen.**

`app/Models/Game.php`: lägg till `'is_listed',` efter `'is_active',` i `$fillable` och `'is_listed' => 'boolean',` i `casts()`.

`app/Http/Controllers/Api/V1/GameController.php`, `index()`:

```php
        // Olistade spel (Demo) är aktiva för spelar-API:t men syns inte här.
        $games = Game::where('is_active', true)->where('is_listed', true)->get();
```

`app/Filament/Resources/GameResource.php`, efter `Toggle::make('is_active')->default(true),`:

```php
                    Forms\Components\Toggle::make('is_listed')
                        ->label('Listad i publika spellistan')
                        ->helperText('Demo-spel från create_game skapas olistade. Slå på vid Live.')
                        ->default(true),
```

och i `table()` efter `IconColumn::make('is_active')->boolean(),`:

```php
                Tables\Columns\IconColumn::make('is_listed')->boolean()->label('Listad'),
```

- [ ] **Steg 5: Kör testen.**

```bash
cd $WT && php artisan test --filter='GameTest|GameCorsTest'
```

Förväntat: alla gröna.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add database/migrations/2026_10_15_100001_add_is_listed_to_games_table.php app/Models/Game.php app/Http/Controllers/Api/V1/GameController.php app/Filament/Resources/GameResource.php tests/Feature/Api/V1/GameTest.php && git commit -m "games.is_listed: Demo-spel syns inte i GET /games förrän de listas"
```

---

## Task 8: `provisioning_runs` och `provisioning_steps`

**Files:**
- Create: `database/migrations/2026_10_15_100002_create_provisioning_runs_table.php`, `database/migrations/2026_10_15_100003_create_provisioning_steps_table.php`, `app/Models/ProvisioningRun.php`, `app/Models/ProvisioningStep.php`
- Test: `tests/Feature/Provisioning/ProvisioningRunTest.php`

**Interfaces:**
- Produces:
  - `ProvisioningRun` med konstanterna `KIND_PROVISION`, `KIND_ARCHIVE`, `RUNNING`, `SUCCEEDED`, `FAILED`, `RELEASED`, `ARCHIVED`; relationerna `steps(): HasMany` (sorterad på id), `game(): BelongsTo`, `user(): BelongsTo`; scopes `provision()`, `countingTowardsCap()`; fält enligt migrationen.
  - `ProvisioningStep` med `PENDING`, `DONE`, `FAILED`; `run(): BelongsTo`; `detail` castad till array.

Avvikelser från specens datamodell, motiverade: `slug_lock` (nullable, unik) uttrycker "unik bland runs som inte är `released`" även i MySQL; `name` behövs eftersom spelet skapas först i steget `game`; `idea` är nullable eftersom arkiveringsruns saknar idé.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/ProvisioningRunTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\ProvisioningFakes;

class ProvisioningRunTest extends ProvisioningTestCase
{
    public function test_uuid_is_generated(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $run->uuid);
    }

    public function test_slug_lock_is_unique_until_released(): void
    {
        $first = $this->provisioningRun(['slug' => 'kattsvep']);

        try {
            $this->provisioningRun(['slug' => 'kattsvep']);
            $this->fail('Två runs fick hålla samma slug.');
        } catch (UniqueConstraintViolationException) {
            // väntat
        }

        $first->update(['status' => ProvisioningRun::RELEASED, 'slug_lock' => null]);
        $second = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->assertSame('kattsvep', $second->slug_lock);
    }

    public function test_last_error_is_scrubbed_and_limited(): void
    {
        $run = $this->provisioningRun();
        $run->update(['last_error' => 'x '.ProvisioningFakes::COOLIFY_TOKEN.' '.str_repeat('a', 6000)]);

        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $run->fresh()->last_error);
        $this->assertLessThanOrEqual(4096, strlen($run->fresh()->last_error));
    }

    public function test_cap_scope_counts_running_succeeded_failed_provision_runs_only(): void
    {
        foreach ([ProvisioningRun::RUNNING, ProvisioningRun::SUCCEEDED, ProvisioningRun::FAILED, ProvisioningRun::RELEASED, ProvisioningRun::ARCHIVED] as $status) {
            $this->provisioningRun(['status' => $status]);
        }
        $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'status' => ProvisioningRun::RUNNING]);

        $this->assertSame(3, ProvisioningRun::countingTowardsCap()->count());
    }

    public function test_steps_are_unique_per_run_and_ordered(): void
    {
        $run = $this->provisioningRun();
        $run->steps()->create(['step' => 'repo', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);
        $run->steps()->create(['step' => 'game', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);

        $this->assertSame(['repo', 'game'], $run->steps()->pluck('step')->all());

        $this->expectException(UniqueConstraintViolationException::class);
        $run->steps()->create(['step' => 'repo', 'status' => ProvisioningStep::PENDING, 'attempts' => 0]);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=ProvisioningRunTest
```

Förväntat: FAIL, `Class "App\Models\ProvisioningRun" not found`.

- [ ] **Steg 3: Migrationerna.**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 40)->index();
            // = slug medan en provisioneringsrun håller namnet, annars null.
            // Unikt index med null tillåter flera släppta runs (även i MySQL).
            $table->string('slug_lock', 40)->nullable()->unique();
            $table->string('name', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->text('idea')->nullable();
            $table->string('github_repo')->nullable();
            $table->string('coolify_app_uuid', 64)->nullable()->index();
            $table->unsignedBigInteger('siteops_site_id')->nullable();
            $table->string('demo_url')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_runs');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('provisioning_runs')->cascadeOnDelete();
            $table->string('step', 32);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_steps');
    }
};
```

- [ ] **Steg 4: Modellerna.**

```php
<?php

namespace App\Models;

use App\Provisioning\Http\Redactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProvisioningRun extends Model
{
    public const KIND_PROVISION = 'provision';

    public const KIND_ARCHIVE = 'archive';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const RELEASED = 'released';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'uuid', 'slug', 'slug_lock', 'name', 'user_id', 'game_id', 'kind', 'status', 'idea',
        'github_repo', 'coolify_app_uuid', 'siteops_site_id', 'demo_url', 'last_error',
    ];

    protected function casts(): array
    {
        return ['siteops_site_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProvisioningRun $run) {
            $run->uuid ??= (string) Str::uuid();
        });

        // last_error visas för spelets medlemmar. Sista skyddet: kända
        // hemligheter bort och högst 4 KB, oavsett vem som skrev värdet.
        static::saving(function (ProvisioningRun $run) {
            if (is_string($run->last_error)) {
                $run->last_error = Redactor::limit(Redactor::scrubSecrets($run->last_error));
            }
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningStep::class, 'run_id')->orderBy('id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeProvision(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PROVISION);
    }

    // Spec 2c, taket på 15: spel som finns eller kan finnas på Coolify.
    public function scopeCountingTowardsCap(Builder $query): Builder
    {
        return $query->provision()->whereIn('status', [self::RUNNING, self::SUCCEEDED, self::FAILED]);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningStep extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['run_id', 'step', 'status', 'attempts', 'detail'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'attempts' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class, 'run_id');
    }
}
```

- [ ] **Steg 5: Kör testet.**

```bash
cd $WT && php artisan test --filter=ProvisioningRunTest
```

Förväntat: `Tests: 5 passed`.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add database/migrations/2026_10_15_100002_create_provisioning_runs_table.php database/migrations/2026_10_15_100003_create_provisioning_steps_table.php app/Models/ProvisioningRun.php app/Models/ProvisioningStep.php tests/Feature/Provisioning/ProvisioningRunTest.php && git commit -m "provisioning_runs och provisioning_steps med slug-lås"
```

---

## Task 9: GitHubAppClient

**Files:**
- Create: `app/Provisioning/Clients/GitHubAppClient.php`
- Test: `tests/Feature/Provisioning/GitHubAppClientTest.php`

**Interfaces:**
- Consumes: `ProvisioningConfig`, `SendsApiRequests`, `Redactor::registerSecret()`, `GameSlug::PATTERN`.
- Produces (`App\Provisioning\Clients\GitHubAppClient`):
  - `public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH']`
  - `jwt(?DateTimeImmutable $now = null): string`
  - `installationToken(): string`, `forgetInstallationToken(): void`
  - `getRepository(string $name): ?array` (null vid 404)
  - `generateFromTemplate(string $name, string $description): ?array` (null vid 422)
  - `getFile(string $name, string $path): ?array` → `array{sha: string, text: string}` eller null vid 404
  - `putFile(string $name, string $path, string $text, string $sha, string $message): void`
  - `archiveRepository(string $name): array`

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/GitHubAppClientTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\Support\ProvisioningFakes;

class GitHubAppClientTest extends ProvisioningTestCase
{
    private const TOKEN_URL = 'api.github.com/app/installations/4242/access_tokens';

    private function client(): GitHubAppClient
    {
        return app(GitHubAppClient::class);
    }

    public function test_jwt_is_rs256_with_client_id_and_short_lifetime(): void
    {
        $now = new DateTimeImmutable('2026-10-13 12:00:00');

        $token = (new Parser(new JoseEncoder))->parse($this->client()->jwt($now));

        $this->assertSame('RS256', $token->headers()->get('alg'));
        $this->assertTrue((new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText(ProvisioningFakes::keyPair()['public']))));
        $this->assertTrue($token->hasBeenIssuedBy('Iv23testclientid'));
        $this->assertSame($now->getTimestamp() - 60, $token->claims()->get('iat')->getTimestamp());
        $this->assertSame($now->getTimestamp() + 540, $token->claims()->get('exp')->getTimestamp());
        $this->assertFalse($token->claims()->has('nbf'));
    }

    public function test_installation_token_is_requested_with_jwt_and_cached_encrypted(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201)]);

        $first = $this->client()->installationToken();
        $second = $this->client()->installationToken();

        $this->assertSame('ghs_testinstallationtoken000000000001', $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_starts_with($r->header('Authorization')[0], 'Bearer ey')
            && $r->header('X-GitHub-Api-Version')[0] === '2022-11-28'
            && $r->body() === '');
        $this->assertStringNotContainsString($first, (string) Cache::get('provisioning:github:installation-token'));
    }

    public function test_generate_sends_private_true_and_mints_new_token_afterwards(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/spelmall/generate' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'run-1'), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'run-1')),
        ]);

        $repo = $this->client()->generateFromTemplate('kattsvep', 'Computercat-spel kattsvep (run run-1)');
        $this->client()->getRepository('kattsvep');

        $this->assertSame('HumblebragAB/kattsvep', $repo['full_name']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/generate') && $r->data() === [
            'owner' => 'HumblebragAB',
            'name' => 'kattsvep',
            'description' => 'Computercat-spel kattsvep (run run-1)',
            'private' => true,
            'include_all_branches' => false,
        ]);
        // En token före generate, en ny efter.
        $this->assertCount(2, Http::recorded(fn ($r) => str_ends_with($r->url(), '/access_tokens')));
    }

    public function test_generate_returns_null_on_422(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/spelmall/generate' => Http::response(['message' => 'Unprocessable Entity'], 422),
        ]);

        $this->assertNull($this->client()->generateFromTemplate('kattsvep', 'd'));
    }

    public function test_get_repository_returns_null_on_404_and_throws_on_500(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/finnsinte' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/HumblebragAB/trasig' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->assertNull($this->client()->getRepository('finnsinte'));

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('GitHub GET /repos/HumblebragAB/trasig returned 500');
        $this->client()->getRepository('trasig');
    }

    public function test_get_and_put_file(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md' => Http::sequence()
                ->push(ProvisioningFakes::githubContent('# Hej å'), 200)
                ->push(['content' => ['sha' => 'ny']], 200),
        ]);

        $file = $this->client()->getFile('kattsvep', 'README.md');
        $this->client()->putFile('kattsvep', 'README.md', 'Ny text', $file['sha'], 'Meddelande');

        $this->assertSame(['sha' => 'readme-sha-1', 'text' => '# Hej å'], $file);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['message' => 'Meddelande', 'content' => base64_encode('Ny text'), 'sha' => 'readme-sha-1']);
    }

    public function test_get_file_returns_null_on_404(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertNull($this->client()->getFile('kattsvep', 'README.md'));
    }

    public function test_archive_patches_archived_true(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'r', archived: true)),
        ]);

        $this->assertTrue($this->client()->archiveRepository('kattsvep')['archived']);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['archived' => true]);
    }

    public function test_invalid_names_are_refused_before_any_request(): void
    {
        Http::fake();

        foreach (['../x', 'HumblebragAB/x', 'a b', 'Kattsvep'] as $name) {
            try {
                $this->client()->getRepository($name);
                $this->fail("Ogiltigt namn släpptes igenom: {$name}");
            } catch (\InvalidArgumentException) {
                // väntat
            }
        }

        Http::assertNothingSent();
    }

    public function test_client_has_no_delete_capability(): void
    {
        $this->assertNotContains('DELETE', GitHubAppClient::ALLOWED_METHODS);
        foreach ((new \ReflectionClass(GitHubAppClient::class))->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression('/delete|remove|destroy/i', $method->getName());
        }
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=GitHubAppClientTest
```

Förväntat: FAIL, `Class "App\Provisioning\Clients\GitHubAppClient" not found`.

- [ ] **Steg 3: `app/Provisioning/Clients/GitHubAppClient.php`.**

```php
<?php

namespace App\Provisioning\Clients;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\Redactor;
use App\Provisioning\Http\SendsApiRequests;
use App\Provisioning\ProvisioningConfig;
use App\Rules\GameSlug;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

// GitHub App "Humblebrag Spelstudio" (bilaga B). Appen har Administration:
// write och kan alltså radera repon. Klienten har därför ingen metod för
// DELETE och vägrar metoden i github(); TestCase vaktar hela sviten.
class GitHubAppClient
{
    use SendsApiRequests;

    public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH'];

    private const TOKEN_CACHE_KEY = 'provisioning:github:installation-token';

    protected function serviceName(): string
    {
        return 'GitHub';
    }

    protected function baseUrl(): string
    {
        return (string) config('provisioning.github.api_url');
    }

    // RS256, iss = client id, iat 60 s bakåt (klockdrift), exp 9 min (max 10).
    public function jwt(?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable;

        return Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->issuedBy((string) config('provisioning.github.client_id'))
            ->issuedAt($now->modify('-60 seconds'))
            ->expiresAt($now->modify('+9 minutes'))
            ->getToken(new Sha256, InMemory::file(ProvisioningConfig::privateKeyFile()))
            ->toString();
    }

    public function installationToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached)) {
            try {
                $token = Crypt::decryptString($cached);
                Redactor::registerSecret($token);

                return $token;
            } catch (DecryptException) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }
        }

        $installation = (string) config('provisioning.github.installation_id');
        if (preg_match('/^\d+\z/', $installation) !== 1) {
            throw new \InvalidArgumentException('GITHUB_APP_INSTALLATION_ID måste vara ett heltal.');
        }

        $path = "/app/installations/{$installation}/access_tokens";
        // Ingen body: utan repositories/permissions gäller token installationens
        // alla repon och rättigheter (bilaga B §2).
        $response = $this->sendRequest($this->request($this->jwt()), 'POST', $path);
        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');
        if (! is_string($token) || $token === '' || ! is_string($expiresAt)) {
            throw new ExternalApiException('GitHub', 'POST', $path, $response->status(), 'response lacks token or expires_at');
        }

        Redactor::registerSecret($token);
        $until = Carbon::parse($expiresAt)->subMinutes(5);
        if ($until->isFuture()) {
            // Krypterad i cachen: cachetabellen är inte en hemlighetslagring.
            Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), $until);
        }

        return $token;
    }

    public function forgetInstallationToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    public function getRepository(string $name): ?array
    {
        $response = $this->github('GET', '/repos/'.$this->owner().'/'.$this->repoName($name), null, [404]);

        return $response->status() === 404 ? null : $response->json();
    }

    public function generateFromTemplate(string $name, string $description): ?array
    {
        [$templateOwner, $templateName] = explode('/', ProvisioningConfig::templateRepo(), 2);
        $response = $this->github('POST', "/repos/{$templateOwner}/{$this->repoName($templateName)}/generate", [
            'owner' => $this->owner(),
            'name' => $this->repoName($name),
            'description' => $description,
            // Standardvärdet är false (publikt repo); skickas alltid uttryckligen.
            'private' => true,
            'include_all_branches' => false,
        ], [422]);

        // Bilaga B §2: det är inte dokumenterat att en redan utfärdad token
        // täcker ett repo som skapats efteråt. Nästa anrop tar därför en ny.
        $this->forgetInstallationToken();

        return $response->status() === 422 ? null : $response->json();
    }

    /** @return array{sha: string, text: string}|null */
    public function getFile(string $name, string $path): ?array
    {
        $apiPath = '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path);
        $response = $this->github('GET', $apiPath, null, [404]);
        if ($response->status() === 404) {
            return null;
        }

        $sha = $response->json('sha');
        $content = $response->json('content');
        if (! is_string($sha) || ! is_string($content) || $response->json('encoding') !== 'base64') {
            throw new ExternalApiException('GitHub', 'GET', $apiPath, $response->status(), 'unexpected contents response (expected sha, content, encoding=base64)');
        }

        $text = base64_decode(str_replace(["\n", "\r"], '', $content), true);
        if ($text === false) {
            throw new ExternalApiException('GitHub', 'GET', $apiPath, $response->status(), 'content is not valid base64');
        }

        return ['sha' => $sha, 'text' => $text];
    }

    public function putFile(string $name, string $path, string $text, string $sha, string $message): void
    {
        $this->github('PUT', '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path), [
            'message' => $message,
            'content' => base64_encode($text),
            'sha' => $sha,
        ]);
    }

    public function archiveRepository(string $name): array
    {
        return $this->github('PATCH', '/repos/'.$this->owner().'/'.$this->repoName($name), ['archived' => true])->json();
    }

    private function github(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \LogicException("GitHubAppClient skickar aldrig {$method}.");
        }

        return $this->sendRequest($this->request($this->installationToken()), $method, $path, $body, $accept);
    }

    private function request(string $bearer): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($bearer)
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }

    private function owner(): string
    {
        return ProvisioningConfig::githubOwner();
    }

    // Reponamn hamnar i URL:er; bara slugformatet släpps igenom.
    private function repoName(string $name): string
    {
        if (preg_match(GameSlug::PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Ogiltigt reponamn: {$name}");
        }

        return $name;
    }

    private function filePath(string $path): string
    {
        if (preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*\z#', $path) !== 1 || str_contains($path, '..')) {
            throw new \InvalidArgumentException("Ogiltig sökväg: {$path}");
        }

        return $path;
    }
}
```

- [ ] **Steg 4: Kör testet.**

```bash
cd $WT && php artisan test --filter=GitHubAppClientTest
```

Förväntat: `Tests: 10 passed`. Om `test_generate_sends_private_true_and_mints_new_token_afterwards` räknar fel antal token-anrop: kontrollera att `forgetInstallationToken()` anropas även vid 422.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Provisioning/Clients/GitHubAppClient.php tests/Feature/Provisioning/GitHubAppClientTest.php && git commit -m "GitHubAppClient: JWT, installationstoken, generate, contents och arkivering utan DELETE"
```

---

## Task 10: CoolifyClient med uuid- och projektspärr

**Files:**
- Create: `app/Provisioning/Clients/CoolifyClient.php`, `app/Provisioning/Clients/CoolifyConflictException.php`, `app/Provisioning/Clients/CoolifyGuardException.php`
- Test: `tests/Feature/Provisioning/CoolifyClientTest.php`

**Interfaces:**
- Consumes: `ProvisioningRun` (spärren), `ProvisioningConfig`, `SendsApiRequests`.
- Produces (`App\Provisioning\Clients\CoolifyClient`):
  - `public const DEPLOYMENT_STATUSES = ['queued', 'in_progress', 'finished', 'failed', 'cancelled-by-user']`
  - `demoApplications(): array` → `list<array{uuid: string, name: string}>`
  - `findDemoApplicationByName(string $name): ?array`
  - `createApplication(string $slug): string` (uuid; `CoolifyConflictException` vid 409)
  - `application(string $uuid): array`
  - `environmentVariables(string $uuid): array`
  - `createBuildVariable(string $uuid, string $key, string $value): void`, `updateBuildVariable(string $uuid, string $key, string $value): void`
  - `deploy(string $uuid): string` (deployment-uuid), `deploymentStatus(string $deploymentUuid): string`
  - `deleteApplication(string $uuid): void`
  - `CoolifyConflictException`, `CoolifyGuardException` (båda `SafeMessage`).

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/CoolifyClientTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\CoolifyConflictException;
use App\Provisioning\Clients\CoolifyGuardException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProvisioningFakes;

class CoolifyClientTest extends ProvisioningTestCase
{
    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private function client(): CoolifyClient
    {
        return app(CoolifyClient::class);
    }

    public function test_create_sends_exact_body_and_returns_uuid(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201)]);

        $this->assertSame('app-1', $this->client()->createApplication('kattsvep'));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->header('Authorization')[0] === 'Bearer '.ProvisioningFakes::COOLIFY_TOKEN
            && $r->data() === [
                'project_uuid' => 'demo-project-uuid',
                'server_uuid' => 'server-uuid',
                'environment_name' => 'production',
                'github_app_uuid' => 'coolify-github-app-uuid',
                'git_repository' => 'HumblebragAB/kattsvep',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'name' => 'spel-kattsvep',
                'domains' => 'https://kattsvep.apps.humblebrag.se',
                'ports_exposes' => '80',
                'is_static' => true,
                'static_image' => 'nginx:alpine',
                'publish_directory' => 'out',
                'is_auto_deploy_enabled' => true,
                'instant_deploy' => false,
                'is_http_basic_auth_enabled' => true,
                'http_basic_auth_username' => 'demo',
                'http_basic_auth_password' => 'demo',
            ]);
    }

    public function test_create_409_is_a_conflict(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyConflict(), 409)]);

        $this->expectException(CoolifyConflictException::class);
        $this->client()->createApplication('kattsvep');
    }

    public function test_failed_create_logs_redacted_request_and_response(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(['message' => 'Validation failed', 'errors' => ['http_basic_auth_password' => ['demo']]], 422)]);
        config(['provisioning.demo.basic_auth_password' => 'losenord-som-inte-far-synas']);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m;
        });

        try {
            $this->client()->createApplication('kattsvep');
            $this->fail('422 kastade inte.');
        } catch (\App\Provisioning\Http\ExternalApiException $e) {
            $this->assertStringContainsString('Coolify POST /applications/private-github-app returned 422', $e->getMessage());
        }

        $all = json_encode(array_map(fn ($m) => [$m->message, $m->context], $logged));
        $this->assertStringContainsString('"status":422', $all);
        $this->assertStringNotContainsString('losenord-som-inte-far-synas', $all);
        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $all);
    }

    public function test_demo_applications_and_lookup_by_name(): void
    {
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([
            ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'),
            ProvisioningFakes::coolifyApp('app-2', 'ordjakt'),
        ]))]);

        $this->assertSame([['uuid' => 'app-1', 'name' => 'spel-kattsvep'], ['uuid' => 'app-2', 'name' => 'ordjakt']], $this->client()->demoApplications());
        $this->assertSame('app-1', $this->client()->findDemoApplicationByName('spel-kattsvep')['uuid']);
        $this->assertNull($this->client()->findDemoApplicationByName('spel-annat'));
    }

    public function test_delete_refuses_unknown_uuid_without_any_request(): void
    {
        Http::fake();

        try {
            $this->client()->deleteApplication('okand-app');
            $this->fail('Spärren släppte igenom en okänd uuid.');
        } catch (CoolifyGuardException) {
            // väntat
        }

        Http::assertNothingSent();
    }

    public function test_delete_refuses_app_outside_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-9', 'spel-annat')]))]);

        try {
            $this->client()->deleteApplication('app-1');
            $this->fail('Spärren släppte igenom en app utanför Demo-projektet.');
        } catch (CoolifyGuardException) {
            // väntat
        }

        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_delete_known_app_in_demo_project(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
        ]);

        $this->client()->deleteApplication('app-1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://coolify.test/api/v1/applications/app-1');
    }

    public function test_build_variable_and_deploy_are_guarded_and_use_post(): void
    {
        $this->provisioningRun(['coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::response(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201),
            'coolify.test/api/v1/deploy*' => Http::response(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-1')),
        ]);

        $this->client()->createBuildVariable('app-1', 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep');
        $deployment = $this->client()->deploy('app-1');

        $this->assertSame('dep-1', $deployment);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/applications/app-1/envs') && $r->data() === [
            'key' => 'NEXT_PUBLIC_COMPUTERCAT_GAME', 'value' => 'kattsvep', 'is_preview' => false, 'is_buildtime' => true, 'is_runtime' => false,
        ]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://coolify.test/api/v1/deploy?uuid=app-1');
    }

    public function test_deployment_status_is_validated(): void
    {
        Http::fake([
            'coolify.test/api/v1/deployments/dep-1' => Http::response(ProvisioningFakes::coolifyDeployment('in_progress')),
            'coolify.test/api/v1/deployments/dep-2' => Http::response(ProvisioningFakes::coolifyDeployment('weird')),
        ]);

        $this->assertSame('in_progress', $this->client()->deploymentStatus('dep-1'));

        $this->expectException(\App\Provisioning\Http\ExternalApiException::class);
        $this->client()->deploymentStatus('dep-2');
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=CoolifyClientTest
```

Förväntat: FAIL, `Class "App\Provisioning\Clients\CoolifyClient" not found`.

- [ ] **Steg 3: Undantagen.**

```php
<?php

namespace App\Provisioning\Clients;

use App\Provisioning\SafeMessage;

// 409 från POST /applications/private-github-app: domänen används redan.
final class CoolifyConflictException extends \RuntimeException implements SafeMessage
{
}
```

```php
<?php

namespace App\Provisioning\Clients;

use App\Provisioning\SafeMessage;

// Spärren i CoolifyClient: uuid:t kommer inte från en run, eller appen ligger
// inte i Demo-projektet. Ingen begäran har skickats.
final class CoolifyGuardException extends \RuntimeException implements SafeMessage
{
}
```

- [ ] **Steg 4: `app/Provisioning/Clients/CoolifyClient.php`.** `git_repository` skrivs i formatet som spiken bekräftade (S1); planen förutsätter `owner/repo`.

```php
<?php

namespace App\Provisioning\Clients;

use App\Models\ProvisioningRun;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\SendsApiRequests;
use App\Provisioning\ProvisioningConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// Coolify v4.4.6 (bilaga A). Token når hela teamet, därför två spärrar:
// varje app-uuid måste finnas i provisioning_runs, och allt som ändrar eller
// raderar kräver dessutom att appen ligger i Demo-projektet.
class CoolifyClient
{
    use SendsApiRequests;

    public const DEPLOYMENT_STATUSES = ['queued', 'in_progress', 'finished', 'failed', 'cancelled-by-user'];

    protected function serviceName(): string
    {
        return 'Coolify';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('provisioning.coolify.url'), '/').'/api/v1';
    }

    /** @return list<array{uuid: string, name: string}> */
    public function demoApplications(): array
    {
        $path = '/projects/'.rawurlencode((string) config('provisioning.coolify.project_uuid')).'/'.rawurlencode((string) config('provisioning.coolify.environment_name'));
        $applications = $this->coolify('GET', $path)->json('applications');
        if (! is_array($applications)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'response lacks applications');
        }

        $out = [];
        foreach ($applications as $app) {
            if (is_array($app) && is_string($app['uuid'] ?? null) && is_string($app['name'] ?? null)) {
                $out[] = ['uuid' => $app['uuid'], 'name' => $app['name']];
            }
        }

        return $out;
    }

    /** @return array{uuid: string, name: string}|null */
    public function findDemoApplicationByName(string $name): ?array
    {
        $matches = array_values(array_filter($this->demoApplications(), fn (array $app) => $app['name'] === $name));
        if (count($matches) > 1) {
            throw new CoolifyGuardException("More than one Coolify application is named {$name} in the Demo project.");
        }

        return $matches[0] ?? null;
    }

    public function createApplication(string $slug): string
    {
        $path = '/applications/private-github-app';
        $response = $this->coolify('POST', $path, [
            'project_uuid' => (string) config('provisioning.coolify.project_uuid'),
            'server_uuid' => (string) config('provisioning.coolify.server_uuid'),
            'environment_name' => (string) config('provisioning.coolify.environment_name'),
            'github_app_uuid' => (string) config('provisioning.coolify.github_app_uuid'),
            'git_repository' => ProvisioningConfig::repoFullName($slug),
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'name' => ProvisioningConfig::coolifyAppName($slug),
            'domains' => ProvisioningConfig::demoUrl($slug),
            'ports_exposes' => '80',
            'is_static' => true,
            'static_image' => 'nginx:alpine',
            'publish_directory' => 'out',
            'is_auto_deploy_enabled' => true,
            'instant_deploy' => false,
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => (string) config('provisioning.demo.basic_auth_user'),
            'http_basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
        ], [409]);

        if ($response->status() === 409) {
            throw new CoolifyConflictException('Coolify reports a domain conflict for '.ProvisioningConfig::demoUrl($slug).'.');
        }

        $uuid = $response->json('uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new ExternalApiException('Coolify', 'POST', $path, $response->status(), 'response lacks uuid');
        }

        return $uuid;
    }

    public function application(string $uuid): array
    {
        $this->assertKnown($uuid);

        return (array) $this->coolify('GET', '/applications/'.$uuid)->json();
    }

    public function environmentVariables(string $uuid): array
    {
        $this->assertKnown($uuid);
        $json = $this->coolify('GET', '/applications/'.$uuid.'/envs')->json();
        if (! is_array($json) || ! array_is_list($json)) {
            throw new ExternalApiException('Coolify', 'GET', '/applications/'.$uuid.'/envs', 200, 'expected a list of environment variables');
        }

        return $json;
    }

    public function createBuildVariable(string $uuid, string $key, string $value): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('POST', '/applications/'.$uuid.'/envs', $this->buildVariable($key, $value));
    }

    public function updateBuildVariable(string $uuid, string $key, string $value): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('PATCH', '/applications/'.$uuid.'/envs', $this->buildVariable($key, $value));
    }

    public function deploy(string $uuid): string
    {
        $this->assertInDemoProject($uuid);
        $path = '/deploy?'.http_build_query(['uuid' => $uuid]);
        $response = $this->coolify('POST', $path);
        $deployment = $response->json('deployments.0.deployment_uuid');
        if (! is_string($deployment) || $deployment === '') {
            throw new ExternalApiException('Coolify', 'POST', $path, $response->status(), 'response lacks deployments[0].deployment_uuid');
        }

        return $deployment;
    }

    public function deploymentStatus(string $deploymentUuid): string
    {
        $this->assertUuidFormat($deploymentUuid);
        $path = '/deployments/'.$deploymentUuid;
        $status = $this->coolify('GET', $path)->json('status');
        if (! in_array($status, self::DEPLOYMENT_STATUSES, true)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'unknown deployment status: '.(is_scalar($status) ? (string) $status : gettype($status)));
        }

        return $status;
    }

    // Asynkron i Coolify (DeleteResourceJob); standardvärdena på query-flaggorna (alla true) används.
    public function deleteApplication(string $uuid): void
    {
        $this->assertInDemoProject($uuid);
        $this->coolify('DELETE', '/applications/'.$uuid);
    }

    private function buildVariable(string $key, string $value): array
    {
        // is_buildtime/is_runtime finns i controllern men inte i OpenAPI (bilaga A §3, spike S4/S15).
        return ['key' => $key, 'value' => $value, 'is_preview' => false, 'is_buildtime' => true, 'is_runtime' => false];
    }

    private function assertKnown(string $uuid): void
    {
        $this->assertUuidFormat($uuid);
        if (! ProvisioningRun::where('coolify_app_uuid', $uuid)->exists()) {
            throw new CoolifyGuardException("Coolify application {$uuid} is not recorded in any provisioning run.");
        }
    }

    private function assertInDemoProject(string $uuid): void
    {
        $this->assertKnown($uuid);
        foreach ($this->demoApplications() as $app) {
            if ($app['uuid'] === $uuid) {
                return;
            }
        }

        throw new CoolifyGuardException("Coolify application {$uuid} is not in the Demo project.");
    }

    private function assertUuidFormat(string $uuid): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $uuid) !== 1) {
            throw new CoolifyGuardException('Invalid Coolify uuid.');
        }
    }

    private function coolify(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken((string) config('provisioning.coolify.token'))
            ->acceptJson()
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }
}
```

- [ ] **Steg 5: Kör testet.**

```bash
cd $WT && php artisan test --filter=CoolifyClientTest
```

Förväntat: `Tests: 9 passed`.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Provisioning/Clients/CoolifyClient.php app/Provisioning/Clients/CoolifyConflictException.php app/Provisioning/Clients/CoolifyGuardException.php tests/Feature/Provisioning/CoolifyClientTest.php && git commit -m "CoolifyClient: Demo-appar, skapa, build-variabel, deploy och radering bakom uuid- och projektspärr"
```

---

## Task 11: SiteOpsClient

**Files:**
- Create: `app/Provisioning/Clients/SiteOpsClient.php`
- Test: `tests/Feature/Provisioning/SiteOpsClientTest.php`

**Interfaces:**
- Consumes: `SendsApiRequests` (med `$accept` för statusar som inte ska kasta), `config('provisioning.siteops.*')`, `config('provisioning.demo.*')`, 2a:s låsta kontrakt (Global Constraints).
- Produces (`App\Provisioning\Clients\SiteOpsClient`):
  - `findIdsByUrl(string $url): list<int>` — alla träffar på exakt URL (flera = anroparen fallerar).
  - `createDemoSite(string $name, string $url): array{created: bool, id: int}` — 201 → `created: true`; 409 → `created: false` och `id` = svarets `site_id` (anroparen måste bevisa ägarskap innan id:t används).
  - `site(int $id): array`, `environments(int $id): array`.
  - `claimDemoEnvironment(int $siteId, int $environmentId): bool` — `PATCH` med Demo-värdena (basic auth + `is_monitored: true`); `true` vid 200, `false` vid 403 (webben skapades inte av Computercats klient; 2a ändrar då ingenting).
  - `deactivate(int $id): void` — kastar om svaret inte är `is_active: false` och `monitoring_enabled: false`.
  - `static normalizeUrl(string $url): string` — tar bort avslutande `/` (samma likhet som 2a:s uppslag).

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/SiteOpsClientTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class SiteOpsClientTest extends ProvisioningTestCase
{
    private const URL = 'https://kattsvep.apps.humblebrag.se';

    private function client(): SiteOpsClient
    {
        return app(SiteOpsClient::class);
    }

    public function test_create_demo_site_sends_contract_body_and_headers(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsCreated(42, self::URL), 201)]);

        $this->assertSame(['created' => true, 'id' => 42], $this->client()->createDemoSite('Kattsvep', self::URL));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->header('X-Api-Key')[0] === ProvisioningFakes::SITEOPS_KEY
            && $r->header('Accept')[0] === 'application/json'
            && $r->data() === [
                'name' => 'Kattsvep',
                'url' => self::URL,
                'type' => 'app',
                'environment' => [
                    'type' => 'demo',
                    'name' => 'Demo',
                    'url' => self::URL,
                    'basic_auth_user' => 'demo',
                    'basic_auth_password' => 'demo',
                    'is_monitored' => true,
                ],
            ]);
    }

    public function test_409_returns_existing_site_id_without_throwing(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409)]);

        $this->assertSame(['created' => false, 'id' => 34], $this->client()->createDemoSite('Kattsvep', self::URL));
    }

    public function test_409_without_site_id_throws(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['error' => 'A site with this URL already exists in the team.'], 409)]);

        $this->expectException(ExternalApiException::class);
        $this->client()->createDemoSite('Kattsvep', self::URL);
    }

    public function test_find_by_exact_url_returns_all_ids(): void
    {
        Http::fake(['siteops.test/api/v1/sites?*' => Http::sequence()
            ->push(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::URL)]))
            ->push(ProvisioningFakes::siteopsList([]))
            ->push(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::URL), ProvisioningFakes::siteopsSiteData(43, self::URL.'/')]))]);

        $this->assertSame([42], $this->client()->findIdsByUrl(self::URL));
        $this->assertSame([], $this->client()->findIdsByUrl(self::URL));
        $this->assertSame([42, 43], $this->client()->findIdsByUrl(self::URL));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://siteops.test/api/v1/sites?url='.urlencode(self::URL));
    }

    public function test_claim_patches_demo_values_and_maps_403_to_false(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/environments/7' => Http::sequence()
            ->push(ProvisioningFakes::siteopsEnvironmentUpdated(42, self::URL))
            ->push(['message' => 'This site was not created by this API client.'], 403)]);

        $this->assertTrue($this->client()->claimDemoEnvironment(42, 7));
        $this->assertFalse($this->client()->claimDemoEnvironment(42, 7));

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && $r->data() === ['basic_auth_user' => 'demo', 'basic_auth_password' => 'demo', 'is_monitored' => true]);
    }

    public function test_site_environments_and_deactivate(): void
    {
        Http::fake([
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::URL)),
            'siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42)),
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::URL)),
        ]);

        $this->assertTrue($this->client()->site(42)['monitoring_enabled']);
        $this->assertSame('demo', $this->client()->environments(42)[0]['type']);
        $this->client()->deactivate(42);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://siteops.test/api/v1/sites/42/deactivate');
    }

    public function test_deactivate_that_leaves_monitoring_on_throws(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/deactivate' => Http::response(['data' => ['id' => 42, 'is_active' => false, 'monitoring_enabled' => true]])]);

        $this->expectException(ExternalApiException::class);
        $this->client()->deactivate(42);
    }

    public function test_403_on_create_throws_with_path(): void
    {
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['message' => 'Missing scope sites:write'], 403)]);

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('SiteOps POST /sites returned 403');
        $this->client()->createDemoSite('Kattsvep', self::URL);
    }

    public function test_password_never_reaches_the_log_on_error(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['provisioning.demo.basic_auth_password' => 'markor-'.str_repeat('q', 8)]);
        Http::fake(['siteops.test/api/v1/sites' => Http::response(['message' => 'boom'], 500)]);

        try {
            $this->client()->createDemoSite('Kattsvep', self::URL);
        } catch (ExternalApiException) {
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => ! str_contains(json_encode($context), 'markor-'.str_repeat('q', 8))
        );
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=SiteOpsClientTest
```

Förväntat: FAIL, `Class "App\Provisioning\Clients\SiteOpsClient" not found`.

- [ ] **Steg 3: `app/Provisioning/Clients/SiteOpsClient.php`.**

```php
<?php

namespace App\Provisioning\Clients;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\SendsApiRequests;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

// SiteOps API v1 enligt det låsta kontraktet i delprojekt 2a. Nyckeln har
// sites:read och sites:write; API:t har ingen radering. PATCH och deactivate
// fungerar bara på webbar som Computercats egen klient skapade (403 annars).
class SiteOpsClient
{
    use SendsApiRequests;

    protected function serviceName(): string
    {
        return 'SiteOps';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('provisioning.siteops.url'), '/').'/api/v1';
    }

    /** Samma likhet som 2a:s uppslag: avslutande snedstreck räknas inte. */
    public static function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    /** @return list<int> */
    public function findIdsByUrl(string $url): array
    {
        $path = '/sites?'.http_build_query(['url' => $url]);
        $data = $this->siteops('GET', $path)->json('data');
        if (! is_array($data)) {
            throw new ExternalApiException('SiteOps', 'GET', '/sites', 200, 'response lacks data');
        }

        $ids = [];
        foreach ($data as $site) {
            if (! is_int($site['id'] ?? null)) {
                throw new ExternalApiException('SiteOps', 'GET', '/sites', 200, 'site without integer id');
            }
            $ids[] = $site['id'];
        }

        return $ids;
    }

    /** @return array{created: bool, id: int} */
    public function createDemoSite(string $name, string $url): array
    {
        $response = $this->siteops('POST', '/sites', [
            'name' => $name,
            'url' => $url,
            'type' => 'app',
            'environment' => [
                'type' => 'demo',
                'name' => 'Demo',
                'url' => $url,
                'basic_auth_user' => (string) config('provisioning.demo.basic_auth_user'),
                'basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
                'is_monitored' => true,
            ],
        ], [409]);

        if ($response->status() === 409) {
            $existing = $response->json('site_id');
            if (! is_int($existing)) {
                throw new ExternalApiException('SiteOps', 'POST', '/sites', 409, 'conflict without site_id');
            }

            return ['created' => false, 'id' => $existing];
        }

        $id = $response->json('data.id');
        if (! is_int($id)) {
            throw new ExternalApiException('SiteOps', 'POST', '/sites', $response->status(), 'response lacks data.id');
        }

        return ['created' => true, 'id' => $id];
    }

    public function site(int $id): array
    {
        return (array) $this->siteops('GET', "/sites/{$id}")->json('data');
    }

    public function environments(int $id): array
    {
        return (array) $this->siteops('GET', "/sites/{$id}/environments")->json('data');
    }

    /**
     * Sätter Demo-värdena på miljön. 200 bevisar att Computercats klient skapade
     * webben; 403 betyder att den inte gjorde det (och att inget ändrades).
     */
    public function claimDemoEnvironment(int $siteId, int $environmentId): bool
    {
        $response = $this->siteops('PATCH', "/sites/{$siteId}/environments/{$environmentId}", [
            'basic_auth_user' => (string) config('provisioning.demo.basic_auth_user'),
            'basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
            'is_monitored' => true,
        ], [403]);

        return $response->status() !== 403;
    }

    public function deactivate(int $id): void
    {
        $response = $this->siteops('POST', "/sites/{$id}/deactivate");
        if ($response->json('data.is_active') !== false || $response->json('data.monitoring_enabled') !== false) {
            throw new ExternalApiException('SiteOps', 'POST', "/sites/{$id}/deactivate", $response->status(), 'site is still active or monitored after deactivate');
        }
    }

    private function siteops(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['X-Api-Key' => (string) config('provisioning.siteops.api_key')])
            ->acceptJson()
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }
}
```

`SendsApiRequests::sendRequest` loggar 409 och 403 redigerat (de är inte `successful()`) men kastar inte för statusar i `$accept`. Det är avsiktligt: en adoption eller ett avvisat ägarskap ska synas i loggen.

- [ ] **Steg 4: Kör testet.**

```bash
cd $WT && php artisan test --filter=SiteOpsClientTest
```

Förväntat: `Tests: 9 passed`.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Provisioning/Clients/SiteOpsClient.php tests/Feature/Provisioning/SiteOpsClientTest.php && git commit -m "SiteOpsClient: demo-sajt, 409, ägarskap via PATCH, uppslag, läsning och avaktivering"
```

---

## Task 12: Sagamotorn

**Files:**
- Create: `app/Provisioning/Saga.php`, `app/Provisioning/StepResult.php`, `app/Provisioning/StepFailed.php`, `app/Provisioning/Steps/Step.php`, `app/Jobs/AdvanceProvisioningRun.php`
- Test: `tests/Feature/Provisioning/AdvanceProvisioningRunTest.php`

**Interfaces:**
- Consumes: `ProvisioningRun`, `ProvisioningStep`, `SlackAlert`, `SafeMessage`.
- Produces:
  - `interface App\Provisioning\Steps\Step { public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult; }`
  - `StepResult::done(array $detail = []): StepResult`, `::wait(int $seconds, array $detail = []): StepResult`, `::releaseSlug(string $reason): StepResult`; publika `readonly` `outcome`, `detail`, `seconds`, `reason`; konstanterna `DONE`, `WAIT`, `RELEASE`.
  - `StepFailed extends RuntimeException implements SafeMessage`.
  - `Saga::PROVISION_STEPS`, `Saga::ARCHIVE_STEPS` (stegnamn => klass), `Saga::steps(string $kind): array`, `Saga::start(ProvisioningRun $run): void`, `Saga::retry(ProvisioningRun $run): bool`, `Saga::handler(ProvisioningRun $run, string $step): Step`.
  - `App\Jobs\AdvanceProvisioningRun(int $runId)` med `handle(SlackAlert $slack): void` och `failed(?Throwable $e): void`.

Stegklasserna finns först från Task 13. Testerna här binder fejkade steg i containern på klassnamnen, så motorn testas utan dem.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/AdvanceProvisioningRunTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\Redactor;
use App\Provisioning\Saga;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyDeleteStep;
use App\Provisioning\Steps\GameStep;
use App\Provisioning\Steps\RepoStep;
use App\Provisioning\Steps\Step;
use App\Support\SlackAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProvisioningFakes;

class AdvanceProvisioningRunTest extends ProvisioningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
    }

    private function fakeStep(string $class, \Closure $behaviour): void
    {
        $this->app->bind($class, fn () => new class($behaviour) implements Step
        {
            public function __construct(private \Closure $behaviour) {}

            public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
            {
                return ($this->behaviour)($run, $step);
            }
        });
    }

    private function startedRun(array $attrs = []): ProvisioningRun
    {
        $run = $this->provisioningRun($attrs);
        Saga::start($run);

        return $run;
    }

    private function runJob(ProvisioningRun $run): AdvanceProvisioningRun
    {
        $job = (new AdvanceProvisioningRun($run->id))->withFakeQueueInteractions();
        $job->handle(app(SlackAlert::class));

        return $job;
    }

    private function stepStatus(ProvisioningRun $run, string $step): string
    {
        return $run->steps()->where('step', $step)->value('status');
    }

    public function test_start_creates_pending_steps_in_order_and_queues_job(): void
    {
        $run = $this->startedRun();

        $this->assertSame(array_keys(Saga::PROVISION_STEPS), $run->steps()->pluck('step')->all());
        $this->assertSame([ProvisioningStep::PENDING], $run->steps()->distinct()->pluck('status')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_done_marks_step_merges_detail_and_queues_next_job(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => StepResult::done(['repo_ok' => true]));

        $job = $this->runJob($run);

        $job->assertNotReleased();
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'repo'));
        $this->assertSame(['repo_ok' => true], $run->steps()->where('step', 'repo')->first()->detail);
        $this->assertSame(1, $run->steps()->where('step', 'repo')->value('attempts'));
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_wait_releases_job_and_keeps_step_pending(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => StepResult::wait(30, ['deployment_uuid' => 'dep-1']));

        $job = $this->runJob($run);

        $job->assertReleased(delay: 30);
        $this->assertSame(ProvisioningStep::PENDING, $this->stepStatus($run, 'repo'));
        $this->assertSame('dep-1', $run->steps()->where('step', 'repo')->first()->detail['deployment_uuid']);
        Queue::assertPushed(AdvanceProvisioningRun::class, 1);
    }

    public function test_failure_redacts_last_error_and_hides_internal_exceptions(): void
    {
        $run = $this->startedRun();
        $body = Redactor::body(json_encode(['message' => 'bad', 'http_basic_auth_password' => 'losen-x', 'logs' => 'token=abc']));
        $this->fakeStep(RepoStep::class, fn () => throw new ExternalApiException('Coolify', 'POST', '/applications/private-github-app', 422, $body));

        $job = $this->runJob($run);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $run->refresh();
        $this->assertSame(ProvisioningRun::FAILED, $run->status);
        $this->assertSame(ProvisioningStep::FAILED, $this->stepStatus($run, 'repo'));
        $this->assertStringStartsWith('repo: Coolify POST /applications/private-github-app returned 422', $run->last_error);
        $this->assertStringNotContainsString('losen-x', $run->last_error);
        $this->assertStringNotContainsString('token=abc', $run->last_error);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && str_contains($r['text'], $run->slug));

        // Ett internt fel visas bara som referens.
        $other = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new \RuntimeException('SQLSTATE[23000] hemlig detalj'));
        $this->runJob($other);

        $other->refresh();
        $this->assertMatchesRegularExpression('/^repo: Internal error \(ref [0-9a-f-]{36}\)\.$/', $other->last_error);
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_step_failed_message_is_shown(): void
    {
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new StepFailed('README.md lacks the idea markers.'));

        $this->runJob($run);

        $this->assertSame('repo: README.md lacks the idea markers.', $run->fresh()->last_error);
    }

    public function test_release_slug_frees_the_slug(): void
    {
        $run = $this->startedRun(['slug' => 'kattsvep']);
        $this->fakeStep(RepoStep::class, fn () => StepResult::releaseSlug('Slug is taken on GitHub.'));

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(ProvisioningRun::RELEASED, $run->status);
        $this->assertNull($run->slug_lock);
        $this->assertSame('repo: Slug is taken on GitHub.', $run->last_error);
        $this->provisioningRun(['slug' => 'kattsvep']);
    }

    public function test_run_that_is_not_running_is_left_alone(): void
    {
        $run = $this->startedRun(['status' => ProvisioningRun::FAILED]);
        $this->fakeStep(RepoStep::class, fn () => $this->fail('Steget kördes för en run som inte är running.'));

        $this->runJob($run);

        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_all_steps_done_marks_succeeded(): void
    {
        $run = $this->startedRun();
        $run->steps()->update(['status' => ProvisioningStep::DONE]);

        $this->runJob($run);

        $this->assertSame(ProvisioningRun::SUCCEEDED, $run->fresh()->status);
    }

    public function test_finished_archive_run_marks_provision_run_archived(): void
    {
        $provision = $this->provisioningRun(['status' => ProvisioningRun::SUCCEEDED, 'game_id' => null, 'slug' => 'kattsvep']);
        $game = \App\Models\Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'settings' => []]);
        $provision->update(['game_id' => $game->id]);
        $archive = $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'slug' => 'kattsvep', 'game_id' => $game->id]);
        Saga::start($archive);
        $archive->steps()->update(['status' => ProvisioningStep::DONE]);

        $this->runJob($archive);

        $this->assertSame(ProvisioningRun::SUCCEEDED, $archive->fresh()->status);
        $this->assertSame(ProvisioningRun::ARCHIVED, $provision->fresh()->status);
        $this->assertSame(array_keys(Saga::ARCHIVE_STEPS), $archive->steps()->pluck('step')->all());
    }

    public function test_job_queue_settings_survive_release_and_never_retry_on_exceptions(): void
    {
        $job = new AdvanceProvisioningRun(1);

        // --tries=1 på workern skulle annars fälla jobbet vid andra release().
        $this->assertSame(0, $job->tries);
        $this->assertNotNull($job->retryUntil());
        $this->assertTrue($job->retryUntil()->greaterThan(now()->addMinutes(config('provisioning.deploy_timeout_minutes'))));
        // Ett jobb som pågår längre än retry_after körs annars en gång till parallellt.
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);

        // Ett undantag i ett steg når aldrig kön (som med tries = 0 skulle köra om för evigt).
        $run = $this->startedRun();
        $this->fakeStep(RepoStep::class, fn () => throw new \Error('trasigt'));
        $this->runJob($run);
        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_failed_hook_marks_run_failed(): void
    {
        $run = $this->startedRun();

        (new AdvanceProvisioningRun($run->id))->failed(new \RuntimeException('timeout'));

        $run->refresh();
        $this->assertSame(ProvisioningRun::FAILED, $run->status);
        $this->assertSame('repo: the step did not finish in time (queue timeout or retry window).', $run->last_error);
        $this->assertSame(ProvisioningStep::FAILED, $this->stepStatus($run, 'repo'));
    }

    public function test_retry_resumes_failed_run_once(): void
    {
        $run = $this->startedRun();
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::DONE]);
        $run->steps()->where('step', 'game')->update(['status' => ProvisioningStep::FAILED]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'game: x']);

        $this->assertTrue(Saga::retry($run));
        $this->assertFalse(Saga::retry($run->fresh()));

        $run->refresh();
        $this->assertSame(ProvisioningRun::RUNNING, $run->status);
        $this->assertNull($run->last_error);
        $this->assertSame(ProvisioningStep::PENDING, $this->stepStatus($run, 'game'));
        $this->assertSame(ProvisioningStep::DONE, $this->stepStatus($run, 'repo'));
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_handlers_resolve_by_kind(): void
    {
        $this->assertSame(GameStep::class, Saga::PROVISION_STEPS['game']);
        $this->assertSame(CoolifyDeleteStep::class, Saga::ARCHIVE_STEPS['coolify_delete']);
        $this->assertSame(['repo', 'game', 'coolify_app', 'coolify_env', 'cors', 'siteops', 'deploy'], array_keys(Saga::PROVISION_STEPS));
        $this->assertSame(['coolify_delete', 'siteops_deactivate', 'repo_archive', 'game_deactivate'], array_keys(Saga::ARCHIVE_STEPS));
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=AdvanceProvisioningRunTest
```

Förväntat: FAIL, `Class "App\Provisioning\Saga" not found`.

- [ ] **Steg 3: `StepResult`, `StepFailed`, `Step`.**

```php
<?php

namespace App\Provisioning;

final class StepResult
{
    public const DONE = 'done';

    public const WAIT = 'wait';

    public const RELEASE = 'release';

    private function __construct(
        public readonly string $outcome,
        public readonly array $detail = [],
        public readonly int $seconds = 0,
        public readonly ?string $reason = null,
    ) {}

    public static function done(array $detail = []): self
    {
        return new self(self::DONE, $detail);
    }

    // Steget är inte klart än (bygge pågår, README inte skapad): jobbet släpps och körs igen.
    public static function wait(int $seconds, array $detail = []): self
    {
        return new self(self::WAIT, $detail, $seconds);
    }

    // Slugen kan inte användas (t.ex. upptagen på GitHub av någon annan): runnen släpper den.
    public static function releaseSlug(string $reason): self
    {
        return new self(self::RELEASE, reason: $reason);
    }
}
```

```php
<?php

namespace App\Provisioning;

// Ett steg som inte kan fortsätta. Meddelandet visas för spelets medlemmar
// och får aldrig innehålla hemligheter.
final class StepFailed extends \RuntimeException implements SafeMessage
{
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepResult;

// Varje steg är idempotent: körs det igen efter ett avbrott ska det känna igen
// det som redan är gjort och inte skapa något två gånger. Inget HTTP-anrop
// får ske inne i en databastransaktion.
interface Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult;
}
```

- [ ] **Steg 4: `app/Provisioning/Saga.php`.**

```php
<?php

namespace App\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Steps;

final class Saga
{
    /** Ordningen är sagans ordning. */
    public const PROVISION_STEPS = [
        'repo' => Steps\RepoStep::class,
        'game' => Steps\GameStep::class,
        'coolify_app' => Steps\CoolifyAppStep::class,
        'coolify_env' => Steps\CoolifyEnvStep::class,
        'cors' => Steps\CorsStep::class,
        'siteops' => Steps\SiteOpsStep::class,
        'deploy' => Steps\DeployStep::class,
    ];

    public const ARCHIVE_STEPS = [
        'coolify_delete' => Steps\CoolifyDeleteStep::class,
        'siteops_deactivate' => Steps\SiteOpsDeactivateStep::class,
        'repo_archive' => Steps\RepoArchiveStep::class,
        'game_deactivate' => Steps\GameDeactivateStep::class,
    ];

    /** @return array<string, class-string<Steps\Step>> */
    public static function steps(string $kind): array
    {
        return match ($kind) {
            ProvisioningRun::KIND_PROVISION => self::PROVISION_STEPS,
            ProvisioningRun::KIND_ARCHIVE => self::ARCHIVE_STEPS,
            default => throw new \LogicException("Okänd run-typ: {$kind}"),
        };
    }

    public static function start(ProvisioningRun $run): void
    {
        foreach (array_keys(self::steps($run->kind)) as $name) {
            $run->steps()->firstOrCreate(['step' => $name], ['status' => ProvisioningStep::PENDING, 'attempts' => 0]);
        }

        AdvanceProvisioningRun::dispatch($run->id);
    }

    // Fortsätter från första ej klara steg. Villkorad uppdatering, så att två
    // samtidiga retry inte köar två jobb.
    public static function retry(ProvisioningRun $run): bool
    {
        $updated = ProvisioningRun::whereKey($run->id)
            ->where('status', ProvisioningRun::FAILED)
            ->update(['status' => ProvisioningRun::RUNNING, 'last_error' => null, 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        $run->steps()->where('status', ProvisioningStep::FAILED)->update(['status' => ProvisioningStep::PENDING]);
        AdvanceProvisioningRun::dispatch($run->id);

        return true;
    }

    public static function handler(ProvisioningRun $run, string $step): Steps\Step
    {
        $class = self::steps($run->kind)[$step] ?? throw new \LogicException("Okänt steg {$step} för {$run->kind}");

        return app($class);
    }
}
```

- [ ] **Steg 5: `app/Jobs/AdvanceProvisioningRun.php`.**

```php
<?php

namespace App\Jobs;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use App\Provisioning\SafeMessage;
use App\Provisioning\StepResult;
use App\Support\SlackAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Kör nästa ej klara steg i en run. Ett klart steg köar ett nytt jobb för
// nästa; ett väntande steg släpper jobbet (release) och körs igen.
//
// Köinställningarna är medvetna (Review Focus 1): workern körs med --tries=1,
// och varje release() räknas som ett försök. $tries = 0 tar bort taket,
// retryUntil() sätter gränsen i tid i stället. Med obegränsade försök får ett
// undantag aldrig nå kön; handle() fångar därför allt och markerar runnen.
class AdvanceProvisioningRun implements ShouldQueue
{
    // Ingen SerializesModels: jobbet bär bara run-id:t och läser runnen färskt.
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    // Under databasköns retry_after (90 s), annars körs jobbet två gånger.
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function retryUntil(): \Illuminate\Support\Carbon
    {
        // Ett nytt jobb köas per steg, så fönstret gäller ett steg i taget.
        return now()->addMinutes((int) config('provisioning.deploy_timeout_minutes') + 10);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('provisioning-run-'.$this->runId))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(SlackAlert $slack): void
    {
        $run = ProvisioningRun::find($this->runId);
        if ($run === null || $run->status !== ProvisioningRun::RUNNING) {
            return;
        }

        $step = $run->steps()->where('status', '!=', ProvisioningStep::DONE)->first();
        if ($step === null) {
            $this->complete($run);

            return;
        }

        $step->increment('attempts');

        try {
            $result = Saga::handler($run, $step->step)->run($run, $step);
        } catch (Throwable $e) {
            $this->markFailed($run, $step, $e, $slack);

            return;
        }

        $step->update(['detail' => array_merge($step->detail ?? [], $result->detail)]);

        match ($result->outcome) {
            StepResult::DONE => $this->advance($step),
            StepResult::WAIT => $this->release($result->seconds),
            StepResult::RELEASE => $this->releaseSlug($run, $step, (string) $result->reason, $slack),
        };
    }

    // Timeout eller passerat retryUntil(): jobbet dör utan att handle() hann markera något.
    public function failed(?Throwable $e): void
    {
        $run = ProvisioningRun::find($this->runId);
        if ($run === null || $run->status !== ProvisioningRun::RUNNING) {
            return;
        }

        $step = $run->steps()->where('status', '!=', ProvisioningStep::DONE)->first();
        $step?->update(['status' => ProvisioningStep::FAILED]);
        $run->update([
            'status' => ProvisioningRun::FAILED,
            'last_error' => ($step?->step ?? 'saga').': the step did not finish in time (queue timeout or retry window).',
        ]);
        Log::error('Provisioneringsjobbet dog', ['run' => $run->uuid, 'exception' => $e]);
        app(SlackAlert::class)->send("provisioning-failed:{$run->uuid}:".($step?->step ?? 'saga'), "Provisioneringen av `{$run->slug}` dog i kön i steget ".($step?->step ?? '?')." (run {$run->uuid}).");
    }

    private function advance(ProvisioningStep $step): void
    {
        $step->update(['status' => ProvisioningStep::DONE]);
        self::dispatch($this->runId);
    }

    private function complete(ProvisioningRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run->update(['status' => ProvisioningRun::SUCCEEDED]);

            if ($run->kind === ProvisioningRun::KIND_ARCHIVE && $run->game_id !== null) {
                ProvisioningRun::provision()
                    ->where('game_id', $run->game_id)
                    ->where('status', ProvisioningRun::SUCCEEDED)
                    ->update(['status' => ProvisioningRun::ARCHIVED]);
            }
        });
    }

    // Inte fail(): namnet tillhör InteractsWithQueue och skulle markera jobbet som misslyckat i kön.
    private function markFailed(ProvisioningRun $run, ProvisioningStep $step, Throwable $e, SlackAlert $slack): void
    {
        $ref = (string) Str::uuid();
        // Bara undantag som lovar ett redigerat meddelande visas för medlemmarna.
        $message = $e instanceof SafeMessage ? $e->getMessage() : "Internal error (ref {$ref}).";
        Log::error('Provisioneringssteg misslyckades', ['ref' => $ref, 'run' => $run->uuid, 'step' => $step->step, 'exception' => $e]);

        $step->update(['status' => ProvisioningStep::FAILED, 'detail' => array_merge($step->detail ?? [], ['error_ref' => $ref])]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => "{$step->step}: {$message}"]);

        $slack->send(
            "provisioning-failed:{$run->uuid}:{$step->step}",
            "Provisioneringen av `{$run->slug}` stannade i steget {$step->step}: ".SlackAlert::escape($run->last_error)." (run {$run->uuid}, ref {$ref}). Fortsätt med retry_provisioning när orsaken är åtgärdad.",
        );
    }

    private function releaseSlug(ProvisioningRun $run, ProvisioningStep $step, string $reason, SlackAlert $slack): void
    {
        $step->update(['status' => ProvisioningStep::FAILED]);
        $run->update(['status' => ProvisioningRun::RELEASED, 'slug_lock' => null, 'last_error' => "{$step->step}: {$reason}"]);
        $slack->send("provisioning-released:{$run->uuid}", "Slugen `{$run->slug}` släpptes: ".SlackAlert::escape($reason)." (run {$run->uuid}).");
    }
}
```

- [ ] **Steg 6: Kör testet.**

```bash
cd $WT && php artisan test --filter=AdvanceProvisioningRunTest
```

Förväntat: `Tests: 13 passed`. `test_job_queue_settings_survive_release_and_never_retry_on_exceptions` faller om `DB_QUEUE_RETRY_AFTER` sänkts under 60 i test-env; kontrollera då `config/queue.php`.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Provisioning/Saga.php app/Provisioning/StepResult.php app/Provisioning/StepFailed.php app/Provisioning/Steps/Step.php app/Jobs/AdvanceProvisioningRun.php tests/Feature/Provisioning/AdvanceProvisioningRunTest.php && git commit -m "Sagamotor: ett steg per jobb, release vid väntan, redigerat fel och Slack-larm"
```

---

## Task 13: Stegen `repo` och `game`

**Files:**
- Create: `app/Provisioning/ReadmeIdea.php`, `app/Provisioning/Steps/RepoStep.php`, `app/Provisioning/Steps/GameStep.php`
- Test: `tests/Unit/Provisioning/ReadmeIdeaTest.php`, `tests/Feature/Provisioning/RepoStepTest.php`, `tests/Feature/Provisioning/GameStepTest.php`, `tests/Feature/Provisioning/StepTestHelpers.php`

**Interfaces:**
- Consumes: `GitHubAppClient`, `ProvisioningConfig`, `StepResult`, `StepFailed`.
- Produces: `ReadmeIdea::insert(string $readme, string $idea): string`, `ReadmeIdea::START`, `ReadmeIdea::END`; `RepoStep`, `GameStep` (implementerar `Step`). Sätter `provisioning_runs.github_repo`, `game_id`, `demo_url`.

- [ ] **Steg 1: Testhjälpare.** `tests/Feature/Provisioning/StepTestHelpers.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepResult;

// Kör ett steg som motorn gör: detail slås ihop efter varje körning.
trait StepTestHelpers
{
    protected function stepRow(ProvisioningRun $run, string $name): ProvisioningStep
    {
        return $run->steps()->firstOrCreate(['step' => $name], ['status' => ProvisioningStep::PENDING, 'attempts' => 0]);
    }

    protected function runStep(string $class, ProvisioningRun $run, string $name): StepResult
    {
        $step = $this->stepRow($run, $name);
        $result = app($class)->run($run, $step);
        $step->update(['detail' => array_merge($step->fresh()->detail ?? [], $result->detail)]);

        return $result;
    }
}
```

- [ ] **Steg 2: Fallerande tester.**

`tests/Unit/Provisioning/ReadmeIdeaTest.php`:

```php
<?php

namespace Tests\Unit\Provisioning;

use App\Provisioning\ReadmeIdea;
use App\Provisioning\StepFailed;
use PHPUnit\Framework\TestCase;

class ReadmeIdeaTest extends TestCase
{
    // Kontraktet med spelmallen (2b, "Gränssnitt mot 2c"). Ändras här bara samtidigt som där.
    private const README = "# Spel\n\n<!-- computercat:idea:start -->\n_Idén fylls i av create_game när spelet skapas._\n<!-- computercat:idea:end -->\n\nSlut.\n";

    public function test_markers_match_the_template_contract(): void
    {
        $this->assertSame('<!-- computercat:idea:start -->', ReadmeIdea::START);
        $this->assertSame('<!-- computercat:idea:end -->', ReadmeIdea::END);
    }

    public function test_replaces_block_and_keeps_the_rest(): void
    {
        $out = ReadmeIdea::insert(self::README, 'Minröj med katter.');

        $this->assertSame("# Spel\n\n<!-- computercat:idea:start -->\nMinröj med katter.\n<!-- computercat:idea:end -->\n\nSlut.\n", $out);
    }

    public function test_is_idempotent(): void
    {
        $once = ReadmeIdea::insert(self::README, 'Idé');

        $this->assertSame($once, ReadmeIdea::insert($once, 'Idé'));
    }

    public function test_html_comments_in_the_idea_are_escaped(): void
    {
        $out = ReadmeIdea::insert(self::README, 'A <!-- computercat:idea:end --> B <!-- x -->');

        $this->assertStringContainsString("\nA &lt;!-- computercat:idea:end --&gt; B &lt;!-- x --&gt;\n", $out);
        $this->assertSame(1, substr_count($out, ReadmeIdea::START));
        $this->assertSame(1, substr_count($out, ReadmeIdea::END));
    }

    /** @return array<string, array{string}> */
    public static function brokenReadmes(): array
    {
        return [
            'saknar markörer' => ["# Ingen markör\n"],
            'saknar slut' => ["<!-- computercat:idea:start -->\nx\n"],
            'dubbel start' => ["<!-- computercat:idea:start -->\n<!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n"],
            'dubbelt slut' => ["<!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n<!-- computercat:idea:end -->\n"],
            'slut före start' => ["<!-- computercat:idea:end -->\nx\n<!-- computercat:idea:start -->\n"],
            'inte på egen rad' => ["Idé: <!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenReadmes')]
    public function test_broken_markers_fail_loudly(string $readme): void
    {
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('README.md lacks the idea markers');
        ReadmeIdea::insert($readme, 'Idé');
    }
}```

`tests/Feature/Provisioning/RepoStepTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Models\ProvisioningRun;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\RepoStep;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class RepoStepTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const TOKEN = 'api.github.com/app/installations/4242/access_tokens';

    private const REPO = 'api.github.com/repos/HumblebragAB/kattsvep';

    private const GENERATE = 'api.github.com/repos/HumblebragAB/spelmall/generate';

    private const README = 'api.github.com/repos/HumblebragAB/kattsvep/contents/README.md';

    private function run_(): ProvisioningRun
    {
        return $this->provisioningRun(['slug' => 'kattsvep', 'idea' => 'Minröj där katter letar bajs.']);
    }

    public function test_creates_repo_waits_for_readme_then_writes_idea(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(['message' => 'Not Found'], 404),
            self::GENERATE => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid), 201),
            self::README => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(ProvisioningFakes::githubContent(ProvisioningFakes::templateReadme()))
                ->push(['content' => ['sha' => 'ny']]),
        ]);

        $first = $this->runStep(RepoStep::class, $run, 'repo');
        $second = $this->runStep(RepoStep::class, $run, 'repo');

        $this->assertSame(StepResult::WAIT, $first->outcome);
        $this->assertSame(10, $first->seconds);
        $this->assertSame(StepResult::DONE, $second->outcome);
        $this->assertSame('HumblebragAB/kattsvep', $run->fresh()->github_repo);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/generate') && $r['private'] === true && $r['description'] === "Computercat-spel kattsvep (run {$run->uuid})");
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains(base64_decode($r['content']), "<!-- computercat:idea:start -->\nMinröj där katter letar bajs.\n<!-- computercat:idea:end -->") && $r['sha'] === 'readme-sha-1');
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/generate')));
    }

    public function test_own_repository_is_adopted_on_retry(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::sequence()
                ->push(ProvisioningFakes::githubContent(ProvisioningFakes::templateReadme()))
                ->push(['content' => ['sha' => 'ny']]),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/generate'));
    }

    public function test_foreign_repository_releases_the_slug(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'annan-run', template: null, description: 'Mattias gamla repo')),
        ]);

        $result = $this->runStep(RepoStep::class, $run, 'repo');

        $this->assertSame(StepResult::RELEASE, $result->outcome);
        $this->assertStringContainsString('Slug is taken on GitHub', $result->reason);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/generate') || $r->method() === 'PUT');
    }

    public function test_repo_from_other_template_with_our_uuid_is_not_adopted(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid, template: 'HumblebragAB/annan-mall')),
        ]);

        $this->assertSame(StepResult::RELEASE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
    }

    public function test_422_with_foreign_repo_releases_and_without_repo_fails(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(ProvisioningFakes::githubRepo('kattsvep', 'annan-run', template: null))
                ->push(['message' => 'Not Found'], 404)
                ->push(['message' => 'Not Found'], 404),
            self::GENERATE => Http::response(['message' => 'Repository creation failed.'], 422),
        ]);

        $this->assertSame(StepResult::RELEASE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);

        $this->expectException(StepFailed::class);
        $this->runStep(RepoStep::class, $run, 'repo');
    }

    public function test_readme_without_markers_fails(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(ProvisioningFakes::githubContent("# Utan markörer\n")),
        ]);

        $this->expectException(StepFailed::class);
        $this->runStep(RepoStep::class, $run, 'repo');
    }

    public function test_readme_that_already_has_the_idea_is_not_rewritten(): void
    {
        $run = $this->run_();
        $written = \App\Provisioning\ReadmeIdea::insert(ProvisioningFakes::templateReadme(), 'Minröj där katter letar bajs.');
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(ProvisioningFakes::githubContent($written)),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_readme_that_never_appears_fails_after_timeout(): void
    {
        $run = $this->run_();
        Http::fake([
            self::TOKEN => Http::response(ProvisioningFakes::githubToken(), 201),
            self::REPO => Http::response(ProvisioningFakes::githubRepo('kattsvep', $run->uuid)),
            self::README => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(StepResult::WAIT, $this->runStep(RepoStep::class, $run, 'repo')->outcome);
        $this->travel(4)->minutes();

        $this->expectException(StepFailed::class);
        $this->runStep(RepoStep::class, $run, 'repo');
    }
}
```

`tests/Feature/Provisioning/GameStepTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Models\Game;
use App\Models\User;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\GameStep;

class GameStepTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    public function test_creates_active_unlisted_game_with_caller_as_owner(): void
    {
        $user = User::factory()->staff()->create();
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'user_id' => $user->id]);

        $this->assertSame(StepResult::DONE, $this->runStep(GameStep::class, $run, 'game')->outcome);

        $game = Game::where('slug', 'kattsvep')->firstOrFail();
        $this->assertTrue($game->is_active);
        $this->assertFalse($game->is_listed);
        $this->assertSame('Kattsvep', $game->name);
        $this->assertSame('owner', $game->members()->whereKey($user->id)->first()->pivot->role);
        $this->assertSame($game->id, $run->fresh()->game_id);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $run->fresh()->demo_url);
    }

    public function test_is_idempotent(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->runStep(GameStep::class, $run, 'game');
        $this->runStep(GameStep::class, $run->fresh(), 'game');

        $this->assertSame(1, Game::where('slug', 'kattsvep')->count());
    }

    public function test_existing_foreign_game_fails(): void
    {
        Game::create(['slug' => 'kattsvep', 'name' => 'Annan', 'is_active' => true, 'settings' => []]);
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->expectException(StepFailed::class);
        $this->runStep(GameStep::class, $run, 'game');
    }
}
```

- [ ] **Steg 3: Kör och se dem falla.**

```bash
cd $WT && php artisan test --filter='ReadmeIdeaTest|RepoStepTest|GameStepTest'
```

Förväntat: FAIL, `Class "App\Provisioning\ReadmeIdea" not found` m.fl.

- [ ] **Steg 4: `app/Provisioning/ReadmeIdea.php`.**

```php
<?php

namespace App\Provisioning;

// Mallens README har ett block mellan två markörrader som create_game fyller
// med spelets idé. Kontraktet ägs av spelmallen (2b, "Gränssnitt mot 2c:
// idé-markören i README.md") och vaktas där av test/docs.test.ts.
final class ReadmeIdea
{
    public const START = '<!-- computercat:idea:start -->';

    public const END = '<!-- computercat:idea:end -->';

    public static function insert(string $readme, string $idea): string
    {
        $start = strpos($readme, self::START);
        $end = strpos($readme, self::END);

        // Exakt en av varje, på egen rad, start före slut. Annars skrivs ingenting:
        // aldrig en tilläggning någon annanstans i filen.
        if (substr_count($readme, self::START) !== 1
            || substr_count($readme, self::END) !== 1
            || $end < $start
            || ! self::ownLine($readme, $start, self::START)
            || ! self::ownLine($readme, $end, self::END)) {
            throw new StepFailed('README.md lacks the idea markers (exactly one start and one end marker, each on its own line, start before end).');
        }

        // Idén får varken öppna eller stänga en HTML-kommentar (och därmed en markör).
        $safe = str_replace(['<!--', '-->'], ['&lt;!--', '--&gt;'], $idea);

        return substr($readme, 0, $start + strlen(self::START))."\n".$safe."\n".substr($readme, $end);
    }

    private static function ownLine(string $text, int $pos, string $marker): bool
    {
        $before = $pos === 0 || $text[$pos - 1] === "\n";
        $afterPos = $pos + strlen($marker);
        $after = $afterPos === strlen($text) || $text[$afterPos] === "\n";

        return $before && $after;
    }
}
```

- [ ] **Steg 5: `app/Provisioning/Steps/RepoStep.php`.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\ReadmeIdea;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Carbon;

// Repot skapas från mallen och idén skrivs i README. Ett repo med samma namn
// adopteras bara om det bevisligen skapats av just den här runnen (run-uuid i
// beskrivningen och mallen som källa); annars släpps slugen.
final class RepoStep implements Step
{
    public function __construct(private GitHubAppClient $github) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $detail = $step->detail ?? [];

        if (! ($detail['repo_ok'] ?? false)) {
            $release = $this->ensureRepository($run);
            if ($release !== null) {
                return $release;
            }
            $run->update(['github_repo' => ProvisioningConfig::repoFullName($run->slug)]);
            $detail['repo_ok'] = true;
            $detail['readme_wait_started_at'] = now()->toIso8601String();
        }

        // /generate är asynkront i praktiken: innehållet kan saknas en stund.
        $file = $this->github->getFile($run->slug, 'README.md');
        if ($file === null) {
            $deadline = Carbon::parse($detail['readme_wait_started_at'])->addMinutes((int) config('provisioning.repo_ready_timeout_minutes'));
            if ($deadline->isPast()) {
                throw new StepFailed('README.md did not appear in the new repository within '.config('provisioning.repo_ready_timeout_minutes').' minutes.');
            }

            return StepResult::wait(10, $detail);
        }

        $updated = ReadmeIdea::insert($file['text'], (string) $run->idea);
        if ($updated !== $file['text']) {
            $this->github->putFile($run->slug, 'README.md', $updated, $file['sha'], "Spelidé från create_game (run {$run->uuid})");
        }

        return StepResult::done($detail + ['readme' => 'written']);
    }

    private function ensureRepository(ProvisioningRun $run): ?StepResult
    {
        $existing = $this->github->getRepository($run->slug);

        if ($existing === null) {
            $created = $this->github->generateFromTemplate($run->slug, "Computercat-spel {$run->slug} (run {$run->uuid})");
            if ($created !== null) {
                return null;
            }

            // 422: namnet finns, eller GitHub vägrade av annat skäl.
            $existing = $this->github->getRepository($run->slug);
            if ($existing === null) {
                throw new StepFailed('GitHub refused to create the repository (422) and no repository with that name exists.');
            }
        }

        if ($this->belongsToRun($existing, $run)) {
            return null;
        }

        return StepResult::releaseSlug('Slug is taken on GitHub: '.ProvisioningConfig::repoFullName($run->slug).' exists and was not created by this run. Choose another slug.');
    }

    private function belongsToRun(array $repo, ProvisioningRun $run): bool
    {
        return is_string($repo['description'] ?? null)
            && str_contains($repo['description'], $run->uuid)
            && ($repo['template_repository']['full_name'] ?? null) === ProvisioningConfig::templateRepo();
    }
}
```

- [ ] **Steg 6: `app/Provisioning/Steps/GameStep.php`.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Facades\DB;

// Spelet skapas aktivt (spelar-API:t ska fungera på Demo) men olistat, med
// den som körde create_game som ägare. Bara databas, inget HTTP.
final class GameStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->game_id !== null && Game::whereKey($run->game_id)->exists()) {
            return StepResult::done();
        }

        if (Game::where('slug', $run->slug)->exists()) {
            throw new StepFailed('A game with this slug already exists in Computercat and does not belong to this run.');
        }

        DB::transaction(function () use ($run) {
            $game = Game::create([
                'slug' => $run->slug,
                'name' => $run->name,
                'is_active' => true,
                'is_listed' => false,
                'settings' => [],
                'allowed_origins' => [],
            ]);

            if ($run->user_id !== null) {
                $game->members()->attach($run->user_id, ['role' => 'owner']);
            }

            $run->update(['game_id' => $game->id, 'demo_url' => ProvisioningConfig::demoUrl($run->slug)]);
        });

        return StepResult::done();
    }
}
```

- [ ] **Steg 7: Kör testen.**

```bash
cd $WT && php artisan test --filter='ReadmeIdeaTest|RepoStepTest|GameStepTest'
```

Förväntat: `Tests: 21 passed` (ReadmeIdeaTest 10 inkl. 6 dataset, RepoStepTest 8, GameStepTest 3).

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add app/Provisioning/ReadmeIdea.php app/Provisioning/Steps/RepoStep.php app/Provisioning/Steps/GameStep.php tests/Unit/Provisioning/ReadmeIdeaTest.php tests/Feature/Provisioning/RepoStepTest.php tests/Feature/Provisioning/GameStepTest.php tests/Feature/Provisioning/StepTestHelpers.php && git commit -m "Provisioneringssteg repo och game: mall, idé i README, slug släpps vid främmande repo"
```

---

## Task 14: Stegen `coolify_app`, `coolify_env` och `cors`

**Files:**
- Create: `app/Provisioning/Steps/CoolifyAppStep.php`, `app/Provisioning/Steps/CoolifyEnvStep.php`, `app/Provisioning/Steps/CorsStep.php`
- Test: `tests/Feature/Provisioning/CoolifyStepsTest.php`

**Interfaces:**
- Consumes: `CoolifyClient`, `ProvisioningConfig`, `Game`.
- Produces: tre `Step`-klasser. Sätter `provisioning_runs.coolify_app_uuid` och spelets `allowed_origins`.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/CoolifyStepsTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Models\Game;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyAppStep;
use App\Provisioning\Steps\CoolifyEnvStep;
use App\Provisioning\Steps\CorsStep;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class CoolifyStepsTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    public function test_app_is_created_and_uuid_saved(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyAppStep::class, $run, 'coolify_app')->outcome);
        $this->assertSame('app-1', $run->fresh()->coolify_app_uuid);
    }

    public function test_existing_app_with_exact_name_is_adopted(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-7', 'spel-kattsvep')]))]);

        $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');

        $this->assertSame('app-7', $run->fresh()->coolify_app_uuid);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_saved_uuid_means_done_without_requests(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'coolify_app_uuid' => 'app-1']);
        Http::fake();

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyAppStep::class, $run, 'coolify_app')->outcome);
        Http::assertNothingSent();
    }

    public function test_domain_conflict_fails(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyConflict(), 409),
        ]);

        $this->expectException(\App\Provisioning\Clients\CoolifyConflictException::class);
        $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
    }

    public function test_build_variable_is_created_or_updated(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'coolify_app_uuid' => 'app-1']);
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/applications/app-1/envs' => Http::sequence()
                ->push([])
                ->push(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201)
                ->push([ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', null)])
                ->push(ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep'), 201)
                ->push([ProvisioningFakes::coolifyEnv('NEXT_PUBLIC_COMPUTERCAT_GAME', 'kattsvep')]),
        ]);

        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');

        // 1: saknas → POST. 2: finns, värdet dolt → PATCH. 3: finns med samma värde → inget.
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH'));
    }

    public function test_env_step_without_app_fails(): void
    {
        $run = $this->provisioningRun(['slug' => 'kattsvep']);

        $this->expectException(StepFailed::class);
        $this->runStep(CoolifyEnvStep::class, $run, 'coolify_env');
    }

    public function test_cors_adds_demo_origin_once(): void
    {
        $game = Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'settings' => [], 'allowed_origins' => ['https://annan.se']]);
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $this->runStep(CorsStep::class, $run, 'cors');
        $this->runStep(CorsStep::class, $run, 'cors');

        $this->assertSame(['https://annan.se', 'https://kattsvep.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=CoolifyStepsTest
```

Förväntat: FAIL, `Class "App\Provisioning\Steps\CoolifyAppStep" not found`.

- [ ] **Steg 3: Stegen.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepResult;

// Coolify-appen spel-<slug> i Demo-projektet. uuid:t sparas direkt efter 201
// så att en omkörning aldrig skapar en andra app.
final class CoolifyAppStep implements Step
{
    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->coolify_app_uuid !== null) {
            return StepResult::done();
        }

        $existing = $this->coolify->findDemoApplicationByName(ProvisioningConfig::coolifyAppName($run->slug));
        if ($existing !== null) {
            $run->update(['coolify_app_uuid' => $existing['uuid']]);

            return StepResult::done(['adopted' => true]);
        }

        // 409 (domänkonflikt) kastar CoolifyConflictException och stoppar sagan.
        $run->update(['coolify_app_uuid' => $this->coolify->createApplication($run->slug)]);

        return StepResult::done();
    }
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Build-variabeln som spelmallen läser. Tokenen saknar read:sensitive, så
// värdet kan vara dolt i listningen; då skrivs det över med PATCH.
final class CoolifyEnvStep implements Step
{
    public const KEY = 'NEXT_PUBLIC_COMPUTERCAT_GAME';

    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $uuid = $run->coolify_app_uuid ?? throw new StepFailed('No Coolify application is recorded for this run.');

        $existing = null;
        foreach ($this->coolify->environmentVariables($uuid) as $variable) {
            if (is_array($variable) && ($variable['key'] ?? null) === self::KEY) {
                $existing = $variable;
            }
        }

        if ($existing === null) {
            $this->coolify->createBuildVariable($uuid, self::KEY, $run->slug);
        } elseif (($existing['value'] ?? null) !== $run->slug || ($existing['is_buildtime'] ?? false) !== true) {
            $this->coolify->updateBuildVariable($uuid, self::KEY, $run->slug);
        }

        return StepResult::done();
    }
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Demo-adressen läggs till spelets CORS-origins. Normalisering och
// cache-rensning sker i Game-hookarna.
final class CorsStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $game = Game::find($run->game_id) ?? throw new StepFailed('The game for this run does not exist.');
        $origin = ProvisioningConfig::demoUrl($run->slug);
        $origins = $game->allowed_origins ?? [];

        if (! in_array($origin, $origins, true)) {
            $game->update(['allowed_origins' => [...$origins, $origin]]);
        }

        return StepResult::done();
    }
}
```

- [ ] **Steg 4: Kör testet.**

```bash
cd $WT && php artisan test --filter=CoolifyStepsTest
```

Förväntat: `Tests: 7 passed`.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Provisioning/Steps/CoolifyAppStep.php app/Provisioning/Steps/CoolifyEnvStep.php app/Provisioning/Steps/CorsStep.php tests/Feature/Provisioning/CoolifyStepsTest.php && git commit -m "Provisioneringssteg coolify_app, coolify_env och cors"
```

---

## Task 15: Stegen `siteops` och `deploy`

**Files:**
- Create: `app/Provisioning/DemoProbe.php`, `app/Provisioning/Steps/SiteOpsStep.php`, `app/Provisioning/Steps/DeployStep.php`
- Test: `tests/Feature/Provisioning/SiteOpsAndDeployStepsTest.php`

**Interfaces:**
- Consumes: `SiteOpsClient`, `CoolifyClient`, `config('provisioning.demo.*')`, `config('provisioning.deploy_timeout_minutes')`.
- Produces: `DemoProbe::status(string $url): int` (0 vid anslutningsfel), `SiteOpsStep` (skapar, eller adopterar enligt ägarskapsregeln i Global Constraints), `DeployStep`. Sätter `provisioning_runs.siteops_site_id`; stegets `detail` får `deployment_uuid`, `deploy_started_at`, `previous_deployments`, `demo_status`.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/SiteOpsAndDeployStepsTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\DemoProbe;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\DeployStep;
use App\Provisioning\Steps\SiteOpsStep;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

class SiteOpsAndDeployStepsTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const DEMO = 'https://kattsvep.apps.humblebrag.se';

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private function deployRun(): \App\Models\ProvisioningRun
    {
        return $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'coolify_app_uuid' => 'app-1', 'demo_url' => self::DEMO]);
    }

    private function siteopsRun(): \App\Models\ProvisioningRun
    {
        return $this->provisioningRun(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'demo_url' => self::DEMO]);
    }

    /** En befintlig webb som uppslaget eller 409 pekar på, i önskat (friskt) läge. */
    private function fakeExistingSite(int $id, array $overrides = []): void
    {
        Http::fake(array_merge([
            "siteops.test/api/v1/sites/{$id}/environments/7" => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated($id, self::DEMO)),
            "siteops.test/api/v1/sites/{$id}/environments" => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO)),
            "siteops.test/api/v1/sites/{$id}" => Http::response(ProvisioningFakes::siteopsSite($id, self::DEMO)),
        ], $overrides));
    }

    public function test_siteops_creates_site_when_lookup_is_empty(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsCreated(42, self::DEMO), 201),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);
        $this->assertSame(42, $run->fresh()->siteops_site_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['url'] === self::DEMO && $r['environment']['is_monitored'] === true);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_own_site_found_by_url_is_adopted_after_claim(): void
    {
        $run = $this->siteopsRun();
        Http::fake(['siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([ProvisioningFakes::siteopsSiteData(42, self::DEMO)]))]);
        $this->fakeExistingSite(42);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);

        $this->assertSame(42, $run->fresh()->siteops_site_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://siteops.test/api/v1/sites/42/environments/7');
    }

    public function test_409_adopts_own_site_after_claim(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $this->fakeExistingSite(34);

        $this->assertSame(StepResult::DONE, $this->runStep(SiteOpsStep::class, $run, 'siteops')->outcome);
        $this->assertSame(34, $run->fresh()->siteops_site_id);
    }

    public function test_409_on_a_site_created_by_someone_else_fails_loudly(): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $this->fakeExistingSite(34, [
            'siteops.test/api/v1/sites/34/environments/7' => Http::response(['message' => 'This site was not created by this API client.'], 403),
        ]);

        try {
            $this->runStep(SiteOpsStep::class, $run, 'siteops');
            $this->fail('En främmande webb adopterades.');
        } catch (StepFailed $e) {
            $this->assertStringContainsString('was not created by Computercat', $e->getMessage());
        }
        $this->assertNull($run->fresh()->siteops_site_id);
    }

    /** @return array<string, array{string}> Data providers körs före setUp, så svaren byggs i testet. */
    public static function unadoptableSites(): array
    {
        return [
            'annan adress på webben' => ['other_url'],
            'inaktiv webb' => ['inactive'],
            'ingen demo-miljö med adressen' => ['no_demo_env'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unadoptableSites')]
    public function test_409_on_a_site_that_does_not_match_the_run_fails_without_patch(string $case): void
    {
        $run = $this->siteopsRun();
        Http::fake([
            'siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([])),
            'siteops.test/api/v1/sites' => Http::response(ProvisioningFakes::siteopsConflict(34), 409),
        ]);
        $override = match ($case) {
            'other_url' => ['siteops.test/api/v1/sites/34' => Http::response(ProvisioningFakes::siteopsSite(34, 'https://kund.se'))],
            'inactive' => ['siteops.test/api/v1/sites/34' => Http::response(ProvisioningFakes::siteopsSite(34, self::DEMO, active: false))],
            'no_demo_env' => ['siteops.test/api/v1/sites/34/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, type: 'production'))],
        };
        $this->fakeExistingSite(34, $override);

        try {
            $this->runStep(SiteOpsStep::class, $run, 'siteops');
            $this->fail('En webb som inte matchar runnen adopterades.');
        } catch (StepFailed) {
        }
        $this->assertNull($run->fresh()->siteops_site_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_several_sites_for_the_url_fail_loudly(): void
    {
        $run = $this->siteopsRun();
        Http::fake(['siteops.test/api/v1/sites?*' => Http::response(ProvisioningFakes::siteopsList([
            ProvisioningFakes::siteopsSiteData(42, self::DEMO), ProvisioningFakes::siteopsSiteData(43, self::DEMO.'/'),
        ]))]);

        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('2 SiteOps sites');
        $this->runStep(SiteOpsStep::class, $run, 'siteops');
    }

    public function test_deploy_starts_polls_and_checks_demo_url(): void
    {
        $run = $this->deployRun();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/deploy*' => Http::response(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-1')),
            'coolify.test/api/v1/deployments/dep-1' => Http::sequence()
                ->push(ProvisioningFakes::coolifyDeployment('queued'))
                ->push(ProvisioningFakes::coolifyDeployment('in_progress'))
                ->push(ProvisioningFakes::coolifyDeployment('finished')),
            self::DEMO => Http::response('<html></html>'),
        ]);

        $start = $this->runStep(DeployStep::class, $run, 'deploy');
        $queued = $this->runStep(DeployStep::class, $run, 'deploy');
        $building = $this->runStep(DeployStep::class, $run, 'deploy');
        $finished = $this->runStep(DeployStep::class, $run, 'deploy');

        $this->assertSame([StepResult::WAIT, 30], [$start->outcome, $start->seconds]);
        $this->assertSame([StepResult::WAIT, 30], [$queued->outcome, $queued->seconds]);
        $this->assertSame(StepResult::WAIT, $building->outcome);
        $this->assertSame(StepResult::DONE, $finished->outcome);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/deploy?')));
        Http::assertSent(fn ($r) => str_starts_with($r->url(), self::DEMO)
            && ($r->header('Authorization')[0] ?? null) === 'Basic '.base64_encode('demo:demo'));
    }

    public function test_failed_deployment_fails_and_next_retry_deploys_again(): void
    {
        $run = $this->deployRun();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/deploy*' => Http::sequence()
                ->push(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-1'))
                ->push(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-2')),
            'coolify.test/api/v1/deployments/dep-1' => Http::response(ProvisioningFakes::coolifyDeployment('failed')),
        ]);

        $this->runStep(DeployStep::class, $run, 'deploy');
        try {
            $this->runStep(DeployStep::class, $run, 'deploy');
            $this->fail('Ett misslyckat bygge gav inget fel.');
        } catch (StepFailed $e) {
            $this->assertSame('Coolify deployment dep-1 ended with status failed.', $e->getMessage());
        }

        $detail = $run->steps()->where('step', 'deploy')->first()->detail;
        $this->assertArrayNotHasKey('deployment_uuid', $detail);
        $this->assertSame(['dep-1'], $detail['previous_deployments']);

        $again = $this->runStep(DeployStep::class, $run, 'deploy');
        $this->assertSame(StepResult::WAIT, $again->outcome);
        $this->assertSame('dep-2', $run->steps()->where('step', 'deploy')->first()->detail['deployment_uuid']);
    }

    public function test_deploy_times_out_after_15_minutes(): void
    {
        $run = $this->deployRun();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/deploy*' => Http::response(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-1')),
            'coolify.test/api/v1/deployments/dep-1' => Http::response(ProvisioningFakes::coolifyDeployment('in_progress')),
        ]);

        $this->runStep(DeployStep::class, $run, 'deploy');
        $this->travel(16)->minutes();

        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('did not finish within 15 minutes');
        $this->runStep(DeployStep::class, $run, 'deploy');
    }

    public function test_finished_but_demo_not_200_waits_then_fails(): void
    {
        $run = $this->deployRun();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')])),
            'coolify.test/api/v1/deploy*' => Http::response(ProvisioningFakes::coolifyDeployQueued('app-1', 'dep-1')),
            'coolify.test/api/v1/deployments/dep-1' => Http::response(ProvisioningFakes::coolifyDeployment('finished')),
            self::DEMO => Http::response('Bad Gateway', 502),
        ]);

        $this->runStep(DeployStep::class, $run, 'deploy');
        $this->assertSame([StepResult::WAIT, 15], [($r = $this->runStep(DeployStep::class, $run, 'deploy'))->outcome, $r->seconds]);
        $this->travel(16)->minutes();

        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('the Demo URL answered 502');
        $this->runStep(DeployStep::class, $run, 'deploy');
    }

    public function test_demo_probe_returns_zero_on_connection_error_and_refuses_foreign_hosts(): void
    {
        Http::fake([self::DEMO => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame(0, DemoProbe::status(self::DEMO));

        $this->expectException(\InvalidArgumentException::class);
        DemoProbe::status('https://example.com');
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=SiteOpsAndDeployStepsTest
```

Förväntat: FAIL, `Class "App\Provisioning\Steps\SiteOpsStep" not found`.

- [ ] **Steg 3: `app/Provisioning/DemoProbe.php`.**

```php
<?php

namespace App\Provisioning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// GET mot en Demo-adress med basic auth. Bara adresser under Demo-domänen.
final class DemoProbe
{
    public static function status(string $url): int
    {
        $host = parse_url($url, PHP_URL_HOST);
        $suffix = '.'.config('provisioning.demo.domain_suffix');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || ! str_ends_with($host, $suffix)) {
            throw new \InvalidArgumentException("Inte en Demo-adress: {$url}");
        }

        try {
            return Http::withBasicAuth((string) config('provisioning.demo.basic_auth_user'), (string) config('provisioning.demo.basic_auth_password'))
                ->timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->get($url)
                ->status();
        } catch (ConnectionException) {
            return 0;
        }
    }
}
```

- [ ] **Steg 4: `app/Provisioning/Steps/SiteOpsStep.php`.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// SiteOps-sajt av typ app med en övervakad demo-miljö. Ett avbrott mellan
// skapandet och sparningen fångas av uppslaget på exakt URL (eller 409) vid
// omkörning. En befintlig webb adopteras bara om den bevisligen är vår: rätt
// adress, aktiv, en demo-miljö med samma adress, och PATCH av miljön ger 200
// (2a ger 403 för webbar som en annan klient eller UI:t skapade).
final class SiteOpsStep implements Step
{
    public function __construct(private SiteOpsClient $siteops) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->siteops_site_id !== null) {
            return StepResult::done();
        }

        $url = ProvisioningConfig::demoUrl($run->slug);
        $found = $this->siteops->findIdsByUrl($url);

        if (count($found) > 1) {
            throw new StepFailed(count($found).' SiteOps sites match '.$url.' (ids '.implode(', ', $found).'). Resolve in SiteOps, then retry_provisioning.');
        }

        if ($found === []) {
            $created = $this->siteops->createDemoSite($run->name, $url);
            if ($created['created']) {
                $run->update(['siteops_site_id' => $created['id']]);

                return StepResult::done(['siteops' => 'created']);
            }
            $found = [$created['id']];
        }

        $this->adopt($found[0], $url);
        $run->update(['siteops_site_id' => $found[0]]);

        return StepResult::done(['siteops' => 'adopted']);
    }

    private function adopt(int $id, string $url): void
    {
        $want = SiteOpsClient::normalizeUrl($url);
        $site = $this->siteops->site($id);

        if (SiteOpsClient::normalizeUrl((string) ($site['url'] ?? '')) !== $want) {
            throw new StepFailed("SiteOps site {$id} has the Demo URL on an environment but its own URL is different; it is not a Computercat Demo site. Resolve in SiteOps.");
        }
        if (($site['is_active'] ?? null) !== true) {
            throw new StepFailed("SiteOps site {$id} for {$url} exists but is inactive. Resolve in SiteOps (no new site is created next to it).");
        }

        $environment = null;
        foreach ($this->siteops->environments($id) as $candidate) {
            if (($candidate['type'] ?? null) === 'demo' && SiteOpsClient::normalizeUrl((string) ($candidate['url'] ?? '')) === $want) {
                $environment = $candidate;
            }
        }
        if (! is_int($environment['id'] ?? null)) {
            throw new StepFailed("SiteOps site {$id} has no demo environment for {$url}.");
        }

        if (! $this->siteops->claimDemoEnvironment($id, $environment['id'])) {
            throw new StepFailed("SiteOps site {$id} for {$url} was not created by Computercat and is not adopted. Resolve in SiteOps.");
        }
    }
}
```

- [ ] **Steg 5: `app/Provisioning/Steps/DeployStep.php`.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\DemoProbe;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

// Första deployen: POST /deploy, sedan poll var 30:e sekund tills finished
// (högst 15 min från start) och till sist GET Demo-URL med basic auth → 200.
// Ett misslyckat eller för långsamt bygge flyttas till previous_deployments,
// så att retry_provisioning startar ett nytt bygge i stället för att polla det gamla.
final class DeployStep implements Step
{
    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $detail = $step->detail ?? [];
        $uuid = $run->coolify_app_uuid ?? throw new StepFailed('No Coolify application is recorded for this run.');

        if (! isset($detail['deployment_uuid'])) {
            return StepResult::wait(30, [
                'deployment_uuid' => $this->coolify->deploy($uuid),
                'deploy_started_at' => now()->toIso8601String(),
            ]);
        }

        $deployment = $detail['deployment_uuid'];
        $deadline = Carbon::parse($detail['deploy_started_at'])->addMinutes((int) config('provisioning.deploy_timeout_minutes'));
        $status = $this->coolify->deploymentStatus($deployment);

        if (in_array($status, ['failed', 'cancelled-by-user'], true)) {
            $this->forget($step, $detail);

            throw new StepFailed("Coolify deployment {$deployment} ended with status {$status}.");
        }

        if ($status === 'finished') {
            $code = DemoProbe::status((string) $run->demo_url);
            if ($code === 200) {
                return StepResult::done(['demo_status' => 200]);
            }
            if ($deadline->isPast()) {
                throw new StepFailed("Deployment {$deployment} finished but the Demo URL answered {$code}.");
            }

            return StepResult::wait(15, ['demo_status' => $code]);
        }

        if ($deadline->isPast()) {
            $this->forget($step, $detail);

            throw new StepFailed("Coolify deployment {$deployment} did not finish within ".config('provisioning.deploy_timeout_minutes').' minutes.');
        }

        return StepResult::wait(30);
    }

    private function forget(ProvisioningStep $step, array $detail): void
    {
        $step->update(['detail' => array_merge(Arr::except($detail, ['deployment_uuid', 'deploy_started_at']), [
            'previous_deployments' => [...($detail['previous_deployments'] ?? []), $detail['deployment_uuid']],
        ])]);
    }
}
```

`array_merge` i motorn och i `StepTestHelpers` lägger aldrig tillbaka `deployment_uuid` eftersom undantaget kastas innan något `detail` returneras; motorn läser `$step->detail` från samma instans som `forget()` uppdaterat.

- [ ] **Steg 6: Kör testet.**

```bash
cd $WT && php artisan test --filter=SiteOpsAndDeployStepsTest
```

Förväntat: `Tests: 13 passed` (8 för siteops inkl. 3 dataset, 5 för deploy och DemoProbe).

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Provisioning/DemoProbe.php app/Provisioning/Steps/SiteOpsStep.php app/Provisioning/Steps/DeployStep.php tests/Feature/Provisioning/SiteOpsAndDeployStepsTest.php && git commit -m "Provisioneringssteg siteops och deploy med polling, tidsgräns och kontroll av Demo-adressen"
```

---

## Task 16: Arkiveringssagan

**Files:**
- Create: `app/Provisioning/Steps/CoolifyDeleteStep.php`, `app/Provisioning/Steps/SiteOpsDeactivateStep.php`, `app/Provisioning/Steps/RepoArchiveStep.php`, `app/Provisioning/Steps/GameDeactivateStep.php`
- Test: `tests/Feature/Provisioning/ArchiveStepsTest.php`

**Interfaces:**
- Consumes: `CoolifyClient::deleteApplication()`, `::demoApplications()`, `SiteOpsClient::deactivate()`, `GitHubAppClient::getRepository()`, `::archiveRepository()`.
- Produces: fyra `Step`-klasser. Arkiveringsrunnen bär kopior av provisioneringsrunnens `github_repo`, `coolify_app_uuid`, `siteops_site_id`, `demo_url` (sätts av `archive_game`, Task 20).

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Provisioning/ArchiveStepsTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use App\Provisioning\Steps\CoolifyDeleteStep;
use App\Provisioning\Steps\GameDeactivateStep;
use App\Provisioning\Steps\RepoArchiveStep;
use App\Provisioning\Steps\SiteOpsDeactivateStep;
use App\Support\SlackAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProvisioningFakes;

class ArchiveStepsTest extends ProvisioningTestCase
{
    use StepTestHelpers;

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private Game $game;

    private ProvisioningRun $provision;

    private ProvisioningRun $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'K', 'is_active' => true, 'is_listed' => false, 'settings' => []]);
        $copy = ['game_id' => $this->game->id, 'github_repo' => 'HumblebragAB/kattsvep', 'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => 'https://kattsvep.apps.humblebrag.se'];
        $this->provision = $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::SUCCEEDED] + $copy);
        $this->archive = $this->provisioningRun(['slug' => 'kattsvep', 'kind' => ProvisioningRun::KIND_ARCHIVE, 'idea' => null] + $copy);
    }

    public function test_coolify_delete_requests_then_waits_until_gone(): void
    {
        Http::fake([
            self::PROJECT_URL => Http::sequence()
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
        ]);

        $this->assertSame([StepResult::WAIT, 30], [($a = $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete'))->outcome, $a->seconds]);
        $this->assertSame(StepResult::WAIT, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'DELETE'));
    }

    public function test_guard_failure_stops_archive_without_delete(): void
    {
        Queue::fake();
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-9', 'annan-app')])),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);
        Saga::start($this->archive);

        (new AdvanceProvisioningRun($this->archive->id))->withFakeQueueInteractions()->handle(app(SlackAlert::class));

        $this->assertSame(ProvisioningRun::FAILED, $this->archive->fresh()->status);
        $this->assertStringContainsString('is not in the Demo project', $this->archive->fresh()->last_error);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_coolify_delete_without_recorded_app_is_skipped(): void
    {
        $this->archive->update(['coolify_app_uuid' => null]);
        Http::fake();

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyDeleteStep::class, $this->archive, 'coolify_delete')->outcome);
        Http::assertNothingSent();
    }

    public function test_siteops_is_deactivated(): void
    {
        Http::fake(['siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42))]);

        $this->runStep(SiteOpsDeactivateStep::class, $this->archive, 'siteops_deactivate');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://siteops.test/api/v1/sites/42/deactivate');
    }

    public function test_repo_is_archived_never_deleted(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::sequence()
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid))
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid, archived: true))
                ->push(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid, archived: true)),
        ]);

        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');
        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');

        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PATCH' && $r->data() === ['archived' => true]));
    }

    public function test_missing_repo_fails_loudly(): void
    {
        Http::fake([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->expectException(StepFailed::class);
        $this->runStep(RepoArchiveStep::class, $this->archive, 'repo_archive');
    }

    public function test_game_is_deactivated_and_unlisted(): void
    {
        $this->runStep(GameDeactivateStep::class, $this->archive, 'game_deactivate');

        $this->assertFalse($this->game->fresh()->is_active);
        $this->assertFalse($this->game->fresh()->is_listed);
    }

    public function test_whole_archive_saga_marks_provision_run_archived(): void
    {
        Queue::fake();
        Http::fake([
            self::PROJECT_URL => Http::sequence()
                ->push(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')]))
                ->push(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyDeleteQueued()),
            'siteops.test/api/v1/sites/42/deactivate' => Http::response(ProvisioningFakes::siteopsDeactivated(42)),
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', $this->provision->uuid)),
        ]);
        Saga::start($this->archive);

        for ($i = 0; $i < 8 && $this->archive->fresh()->status === ProvisioningRun::RUNNING; $i++) {
            (new AdvanceProvisioningRun($this->archive->id))->withFakeQueueInteractions()->handle(app(SlackAlert::class));
        }

        $this->assertSame(ProvisioningRun::SUCCEEDED, $this->archive->fresh()->status);
        $this->assertSame(ProvisioningRun::ARCHIVED, $this->provision->fresh()->status);
        $this->assertSame([ProvisioningStep::DONE], $this->archive->steps()->distinct()->pluck('status')->all());
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=ArchiveStepsTest
```

Förväntat: FAIL, `Class "App\Provisioning\Steps\CoolifyDeleteStep" not found`.

- [ ] **Steg 3: Stegen.**

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;
use Illuminate\Support\Carbon;

// Raderar bara det sparade uuid:t (spärren i klienten kontrollerar run och
// Demo-projekt). Radering är asynkron i Coolify; steget väntar tills appen
// försvunnit ur projektet.
final class CoolifyDeleteStep implements Step
{
    public function __construct(private CoolifyClient $coolify) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $uuid = $run->coolify_app_uuid;
        if ($uuid === null) {
            return StepResult::done(['skipped' => 'no Coolify application recorded']);
        }

        $detail = $step->detail ?? [];
        if (! ($detail['delete_requested'] ?? false)) {
            $this->coolify->deleteApplication($uuid);

            return StepResult::wait(30, ['delete_requested' => true, 'delete_requested_at' => now()->toIso8601String()]);
        }

        foreach ($this->coolify->demoApplications() as $app) {
            if ($app['uuid'] === $uuid) {
                if (Carbon::parse($detail['delete_requested_at'])->addMinutes((int) config('provisioning.coolify_delete_timeout_minutes'))->isPast()) {
                    throw new StepFailed("Coolify still lists application {$uuid} ".config('provisioning.coolify_delete_timeout_minutes').' minutes after the delete request.');
                }

                return StepResult::wait(30);
            }
        }

        return StepResult::done();
    }
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\StepResult;

// SiteOps har ingen radering via API: sajten avaktiveras och övervakningen stängs.
final class SiteOpsDeactivateStep implements Step
{
    public function __construct(private SiteOpsClient $siteops) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->siteops_site_id === null) {
            return StepResult::done(['skipped' => 'no SiteOps site recorded']);
        }

        $this->siteops->deactivate($run->siteops_site_id);

        return StepResult::done();
    }
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Repot arkiveras (PATCH archived: true). Det raderas aldrig.
final class RepoArchiveStep implements Step
{
    public function __construct(private GitHubAppClient $github) {}

    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        if ($run->github_repo !== ProvisioningConfig::repoFullName($run->slug)) {
            throw new StepFailed('The archive run has no repository recorded for this game.');
        }

        $repo = $this->github->getRepository($run->slug) ?? throw new StepFailed("Repository {$run->github_repo} was not found on GitHub.");
        if (($repo['archived'] ?? false) === true) {
            return StepResult::done();
        }

        $this->github->archiveRepository($run->slug);

        return StepResult::done();
    }
}
```

```php
<?php

namespace App\Provisioning\Steps;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\StepFailed;
use App\Provisioning\StepResult;

// Spelet stängs av; data raderas inte.
final class GameDeactivateStep implements Step
{
    public function run(ProvisioningRun $run, ProvisioningStep $step): StepResult
    {
        $game = Game::find($run->game_id) ?? throw new StepFailed('The game for this run does not exist.');
        $game->update(['is_active' => false, 'is_listed' => false]);

        return StepResult::done();
    }
}
```

- [ ] **Steg 4: Kör testet och motortestet.**

```bash
cd $WT && php artisan test --filter='ArchiveStepsTest|AdvanceProvisioningRunTest'
```

Förväntat: alla gröna (8 + 13).

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Provisioning/Steps/CoolifyDeleteStep.php app/Provisioning/Steps/SiteOpsDeactivateStep.php app/Provisioning/Steps/RepoArchiveStep.php app/Provisioning/Steps/GameDeactivateStep.php tests/Feature/Provisioning/ArchiveStepsTest.php && git commit -m "Arkiveringssaga: Coolify-app på sparat uuid, SiteOps avaktiveras, repo arkiveras, spelet stängs av"
```

---

## Task 17: Ägarbehörighet, bas för verktyg utan spel och krokar i GameTool

**Files:**
- Create: `app/Mcp/Tools/Concerns/ToolHelpers.php`, `app/Mcp/Tools/PlatformTool.php`, `tests/Feature/Mcp/Fixtures/FixturePlatformTool.php`, `tests/Feature/Mcp/PlatformToolTest.php`
- Modify: `app/Mcp/GameAccess.php`, `app/Mcp/Tools/GameTool.php`, `app/Mcp/Tools/ListGamesTool.php`, `tests/Feature/Mcp/Fixtures/FixtureServer.php`, `tests/Feature/Mcp/GameAccessTest.php`, `tests/Feature/Mcp/ToolInvariantsTest.php`

**Interfaces:**
- Produces:
  - `GameAccess::isOwner(User $user, Game $game): bool`
  - Trait `ToolHelpers`: `json(array $data): Response`, `validationFailed(array $errors): Response`, `audit(?User $user, ?Game $game, array $args, string $outcome): void`, `unexpected(?User $user, ?Game $game, array $args, Throwable $e): Response`
  - `abstract class PlatformTool extends Tool` med `abstract protected function run(array $args, User $user): Response`
  - `GameTool::whenGameMissing(User $user, string $slug, array $args): ?Response` (default null) och `GameTool::transactional(): bool` (default true)

- [ ] **Steg 1: Fallerande tester.**

Lägg till i `tests/Feature/Mcp/GameAccessTest.php`:

```php
    public function test_is_owner_follows_pivot_role_or_platform_admin(): void
    {
        $game = Game::create(['slug' => 'mine', 'name' => 'M', 'is_active' => true, 'settings' => []]);
        $owner = User::factory()->staff()->create();
        $member = User::factory()->staff()->create();
        $bannedOwner = User::factory()->staff()->create(['is_banned' => true]);
        $admin = User::factory()->staff(admin: true)->create();
        $game->members()->attach($owner, ['role' => 'owner']);
        $game->members()->attach($member, ['role' => 'member']);
        $game->members()->attach($bannedOwner, ['role' => 'owner']);

        $this->assertTrue(GameAccess::isOwner($owner, $game));
        $this->assertFalse(GameAccess::isOwner($member, $game));
        $this->assertFalse(GameAccess::isOwner($bannedOwner, $game));
        $this->assertTrue(GameAccess::isOwner($admin, $game));
    }
```

`tests/Feature/Mcp/Fixtures/FixturePlatformTool.php`:

```php
<?php

namespace Tests\Feature\Mcp\Fixtures;

use App\Mcp\Tools\PlatformTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

class FixturePlatformTool extends PlatformTool
{
    protected string $name = 'fixture_platform_tool';

    protected string $description = 'Test fixture.';

    public function schema(JsonSchema $schema): array
    {
        return ['mode' => $schema->string()];
    }

    protected function run(array $args, User $user): Response
    {
        return match ($args['mode'] ?? 'ok') {
            'invalid' => $this->validationFailed(['slug' => ['The slug is wrong.']]),
            'throw_validation' => throw ValidationException::withMessages(['slug' => 'The slug is wrong.']),
            'boom' => throw new \RuntimeException('internal detail'),
            default => $this->json(['user' => $user->id]),
        };
    }
}
```

`tests/Feature/Mcp/Fixtures/FixtureServer.php`: `protected array $tools = [FixtureGameTool::class, FixturePlatformTool::class];`

`tests/Feature/Mcp/PlatformToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;
use Tests\Feature\Mcp\Fixtures\FixturePlatformTool;
use Tests\Feature\Mcp\Fixtures\FixtureServer;

class PlatformToolTest extends McpTestCase
{
    private function call(User $user, array $args = [])
    {
        return FixtureServer::actingAs(StaffUser::findOrFail($user->id), 'mcp')->tool(FixturePlatformTool::class, $args);
    }

    private function outcomes(): array
    {
        return McpAuditLog::where('tool', 'fixture_platform_tool')->orderBy('id')->pluck('outcome')->all();
    }

    public function test_outcomes_are_logged_with_arguments_and_without_game(): void
    {
        $user = $this->staffMember();

        $this->call($user, ['mode' => 'ok'])->assertHasNoErrors();
        $this->call($user, ['mode' => 'invalid'])->assertHasErrors(['The slug is wrong.']);
        $this->call($user, ['mode' => 'throw_validation'])->assertHasErrors(['The slug is wrong.']);
        $this->call($user, ['mode' => 'boom'])->assertHasErrors(['Unexpected error (ref '])->assertDontSee('internal detail');

        $this->assertSame(['ok', 'validation_error', 'validation_error', 'error'], $this->outcomes());
        $this->assertNull(McpAuditLog::where('tool', 'fixture_platform_tool')->first()->game_id);
        $this->assertSame(['mode' => 'ok'], McpAuditLog::where('tool', 'fixture_platform_tool')->first()->arguments);
    }

    public function test_non_staff_is_denied_and_logged(): void
    {
        $player = User::factory()->create();

        $this->call($player)->assertHasErrors(['Not allowed.']);

        $this->assertSame(['denied'], $this->outcomes());
    }
}
```

Lägg till i `tests/Feature/Mcp/ToolInvariantsTest.php` (med `use App\Mcp\Tools\PlatformTool;`):

```php
    /** Varje verktyg ärver en bas som loggar och fångar fel. */
    public function test_every_tool_extends_a_logging_base(): void
    {
        foreach (ComputercatServer::toolClasses() as $class) {
            $this->assertTrue(
                is_subclass_of($class, GameTool::class) || is_subclass_of($class, PlatformTool::class),
                "{$class} ärver varken GameTool eller PlatformTool",
            );
        }
    }
```

- [ ] **Steg 2: Kör och se dem falla.**

```bash
cd $WT && php artisan test --filter='GameAccessTest|PlatformToolTest|ToolInvariantsTest'
```

Förväntat: FAIL (`isOwner` saknas, `PlatformTool` saknas, `ListGamesTool` ärver ingen bas).

- [ ] **Steg 3: `GameAccess::isOwner`.** Lägg till i `app/Mcp/GameAccess.php`:

```php
    // Ägare enligt pivot (role = owner) eller plattformsadmin. Avstängda
    // användare är aldrig ägare.
    public static function isOwner(User $user, Game $game): bool
    {
        if (! $user->isStaff()) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $game->members()->whereKey($user->getKey())->wherePivot('role', 'owner')->exists();
    }
```

- [ ] **Steg 4: `app/Mcp/Tools/Concerns/ToolHelpers.php`.** Flyttar `json()`, `validationFailed()` och `audit()` ur `GameTool` oförändrade, plus det gemensamma felsvaret:

```php
<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\AuditLogger;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Throwable;

// Delas av GameTool och PlatformTool, så att svar, fel och revisionslogg ser
// likadana ut oavsett om verktyget tar ett spel.
trait ToolHelpers
{
    /** @param array<string, string|array<int, string>> $errors */
    protected function validationFailed(array $errors): Response
    {
        return Response::error("Validation failed:\n".collect($errors)->map(fn ($m, $f) => "- {$f}: ".implode(' ', (array) $m))->implode("\n"));
    }

    protected function json(array $data): Response
    {
        return Response::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // Undantagets text kan innehålla interna detaljer; klienten får bara referensen.
    protected function unexpected(?User $user, ?Game $game, array $args, Throwable $e): Response
    {
        $ref = (string) Str::uuid();
        Log::error('MCP tool failed', ['ref' => $ref, 'tool' => $this->name(), 'user_id' => $user?->id, 'game' => $game?->slug, 'args' => AuditLogger::summarize($args), 'exception' => $e]);
        $this->audit($user, $game, $args, 'error');

        return Response::error("Unexpected error (ref {$ref}).");
    }

    // En trasig loggning får inte ändra svaret: ändringen är redan gjord
    // (eller nekad). Felet rapporteras i stället.
    protected function audit(?User $user, ?Game $game, array $args, string $outcome): void
    {
        try {
            AuditLogger::record($user, $game, $this->name(), $args, $outcome);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

- [ ] **Steg 5: `app/Mcp/Tools/GameTool.php`.** Ersätt hela filen:

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Mcp\Tools\Concerns\ToolHelpers;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för alla verktyg som tar ett spel: åtkomst, transaktion och
// revisionslogg sker här, så att ett nytt verktyg inte kan glömma dem.
abstract class GameTool extends Tool
{
    use ToolHelpers;

    // Körs i en transaktion om transactional() är sant. Ett felsvar (isError)
    // rullar tillbaka allt som run() hunnit skriva, likaså ett undantag.
    abstract protected function run(Game $game, array $args, User $user): Response;

    // Subklassens schema() ska börja med [...$this->gameSchema($schema), ...].
    protected function gameSchema(JsonSchema $schema): array
    {
        return ['game' => $schema->string()->description('Game slug')->required()];
    }

    // Verktyg som anropar externa tjänster (Slack) eller köar jobb svarar
    // false: inget HTTP-anrop får ske inne i en databastransaktion.
    protected function transactional(): bool
    {
        return true;
    }

    // Anropas när spelet inte hittas. Ett verktyg kan då svara om något som
    // finns utan spel (en provisionering som stannade innan spelet skapades).
    // Null ger samma "Game not found" som för icke-medlemmar.
    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        return null;
    }

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());
        $args = $request->all();
        $slug = is_string($args['game'] ?? null) ? $args['game'] : '';
        // Okänt spel och spel man inte är medlem i ger samma svar, så att
        // verktyget inte avslöjar vilka spel som finns.
        $game = $user && $slug !== '' ? GameAccess::find($user, $slug) : null;

        if (! $game) {
            try {
                $fallback = $user && $user->isStaff() && $slug !== '' ? $this->whenGameMissing($user, $slug, $args) : null;
            } catch (\Throwable $e) {
                return $this->unexpected($user, null, $args, $e);
            }

            if ($fallback !== null) {
                $this->audit($user, null, $args, $fallback->isError() ? 'validation_error' : 'ok');

                return $fallback;
            }

            $this->audit($user, null, $args, 'denied');

            return $this->gameNotFound();
        }

        try {
            $response = $this->transactional()
                ? $this->runInTransaction($game, $args, $user)
                : $this->run($game, $args, $user);
        } catch (ValidationException $e) {
            $response = $this->validationFailed($e->errors());
        } catch (\Throwable $e) {
            return $this->unexpected($user, $game, $args, $e);
        }

        // Skrivs utanför transaktionen så att även misslyckade anrop loggas.
        $this->audit($user, $game, $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }

    protected function gameNotFound(): Response
    {
        return Response::error('Game not found.');
    }

    private function runInTransaction(Game $game, array $args, User $user): Response
    {
        DB::beginTransaction();

        try {
            $response = $this->run($game, $args, $user);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $response->isError() ? DB::rollBack() : DB::commit();

        return $response;
    }
}
```

- [ ] **Steg 6: `app/Mcp/Tools/PlatformTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ToolHelpers;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

// Bas för verktyg som inte tar ett spel (list_games, create_game): samma
// staff-kontroll, felhantering och revisionslogg som GameTool, utan spel.
// Ingen transaktion: verktygen sköter sina egna skrivningar.
abstract class PlatformTool extends Tool
{
    use ToolHelpers;

    abstract protected function run(array $args, User $user): Response;

    public function handle(Request $request): Response
    {
        $user = User::find($request->user()?->getKey());
        $args = $request->all();

        if (! $user || ! $user->isStaff()) {
            $this->audit($user, null, $args, 'denied');

            return Response::error('Not allowed.');
        }

        try {
            $response = $this->run($args, $user);
        } catch (ValidationException $e) {
            $response = $this->validationFailed($e->errors());
        } catch (\Throwable $e) {
            return $this->unexpected($user, null, $args, $e);
        }

        $this->audit($user, null, $args, $response->isError() ? 'validation_error' : 'ok');

        return $response;
    }
}
```

- [ ] **Steg 7: `app/Mcp/Tools/ListGamesTool.php`.** Ersätt hela filen:

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

// Listar spelen användaren når. Tar inget spel; loggas via PlatformTool.
class ListGamesTool extends PlatformTool
{
    protected string $name = 'list_games';

    protected string $description = 'Lists the games you can configure (also inactive ones), with slug, name, is_active and allowed_origins. Use the slug as "game" in the other tools.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $args, User $user): Response
    {
        // Fälten väljs uttryckligen: settings bär hemligheter och får aldrig följa med.
        $games = GameAccess::visible($user)->orderBy('slug')->get()
            ->map(fn (Game $game) => [
                'slug' => $game->slug,
                'name' => $game->name,
                'is_active' => (bool) $game->is_active,
                'allowed_origins' => $game->allowed_origins ?? [],
            ])->values()->all();

        return $this->json(['games' => $games]);
    }
}
```

- [ ] **Steg 8: Kör hela MCP-sviten.**

```bash
cd $WT && php artisan test tests/Feature/Mcp
```

Förväntat: alla gröna, inklusive `GameToolTest::test_exception_rolls_back_hides_details_and_logs_reference` (samma loggmeddelande och kontextnycklar) och `ListGamesToolTest`.

- [ ] **Steg 9: Commit.**

```bash
cd $WT && git add app/Mcp tests/Feature/Mcp && git commit -m "MCP: GameAccess::isOwner, PlatformTool för verktyg utan spel och krokar i GameTool"
```

---

## Task 18: `create_game`

**Files:**
- Create: `app/Mcp/Tools/CreateGameTool.php`, `tests/Feature/Mcp/CreateGameToolTest.php`
- Modify: `app/Mcp/ComputercatServer.php`

**Interfaces:**
- Consumes: `PlatformTool`, `ProvisioningConfig::missing()`, `GameSlug`, `ProvisioningRun`, `Saga::start()`.
- Produces: MCP-verktyget `create_game(slug, name, idea)` → JSON `{run, slug, status: "running", repo, demo_url, next}`.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Mcp/CreateGameToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\McpAuditLog;
use App\Models\ProvisioningRun;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class CreateGameToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    private function args(array $overrides = []): array
    {
        return array_merge(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'idea' => 'Minröj där katter letar bajs, daglig topplista för snabbaste rensning.'], $overrides);
    }

    public function test_starts_saga_and_answers_with_run(): void
    {
        $user = $this->staffMember();

        $result = $this->callTool($user, 'create_game', $this->args());

        $this->assertTrue($result['ok'], $result['text']);
        $run = ProvisioningRun::sole();
        $this->assertSame(['kattsvep', 'kattsvep', 'Kattsvep', $user->id, ProvisioningRun::KIND_PROVISION, ProvisioningRun::RUNNING], [$run->slug, $run->slug_lock, $run->name, $run->user_id, $run->kind, $run->status]);
        $this->assertSame(array_keys(Saga::PROVISION_STEPS), $run->steps()->pluck('step')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $run->id);
        $json = json_decode($result['text'], true);
        $this->assertSame($run->uuid, $json['run']);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $json['demo_url']);
        $this->assertSame('HumblebragAB/kattsvep', $json['repo']);
        $this->assertSame('ok', McpAuditLog::where('tool', 'create_game')->sole()->outcome);
    }

    public function test_missing_settings_are_named_and_nothing_is_created(): void
    {
        config(['provisioning.coolify.token' => '', 'provisioning.siteops.api_key' => null]);

        $result = $this->callTool($this->staffMember(), 'create_game', $this->args());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('COOLIFY_TOKEN', $result['text']);
        $this->assertStringContainsString('SITEOPS_API_KEY', $result['text']);
        $this->assertStringNotContainsString(ProvisioningFakes::SITEOPS_KEY, $result['text']);
        $this->assertSame(0, ProvisioningRun::count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_and_reserved_slugs_are_rejected(): void
    {
        $user = $this->staffMember();

        foreach (['Kattsvep', 'ab', 'katt--svep', 'api', 'spel', "katt\nsvep"] as $slug) {
            $this->assertFalse($this->callTool($user, 'create_game', $this->args(['slug' => $slug]))['ok'], $slug);
        }

        $this->assertSame(0, ProvisioningRun::count());
    }

    public function test_slug_used_by_game_or_held_by_run_is_rejected(): void
    {
        $user = $this->staffMember();
        $this->game('ordjakt');
        $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::FAILED]);

        $this->assertStringContainsString('already used by a game', $this->callTool($user, 'create_game', $this->args(['slug' => 'ordjakt']))['text']);
        $this->assertStringContainsString('reserved by another provisioning run', $this->callTool($user, 'create_game', $this->args())['text']);
    }

    public function test_released_slug_can_be_used_again(): void
    {
        $this->provisioningRun(['slug' => 'kattsvep', 'status' => ProvisioningRun::RELEASED]);

        $this->assertTrue($this->callTool($this->staffMember(), 'create_game', $this->args())['ok']);
    }

    public function test_total_cap_counts_running_succeeded_and_failed_only(): void
    {
        $user = $this->staffMember();
        foreach (range(1, 14) as $i) {
            $this->provisioningRun(['status' => [ProvisioningRun::RUNNING, ProvisioningRun::SUCCEEDED, ProvisioningRun::FAILED][$i % 3]]);
        }
        $this->provisioningRun(['status' => ProvisioningRun::RELEASED]);
        $this->provisioningRun(['status' => ProvisioningRun::ARCHIVED]);
        $this->provisioningRun(['kind' => ProvisioningRun::KIND_ARCHIVE, 'status' => ProvisioningRun::RUNNING]);

        $this->assertTrue($this->callTool($user, 'create_game', $this->args(['slug' => 'nummer-femton']))['ok']);

        $result = $this->callTool($user, 'create_game', $this->args(['slug' => 'nummer-sexton']));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('limit of 15 Demo games', $result['text']);
    }

    public function test_daily_cap_is_per_user_and_counts_released_runs(): void
    {
        $user = $this->staffMember();
        $other = $this->staffMember();
        foreach (range(1, 4) as $i) {
            $this->provisioningRun(['user_id' => $user->id, 'status' => ProvisioningRun::RELEASED]);
        }
        $this->provisioningRun(['user_id' => $other->id]);
        $this->travel(-25)->hours();
        $this->provisioningRun(['user_id' => $user->id]);
        $this->travelBack();

        $this->assertTrue($this->callTool($user, 'create_game', $this->args(['slug' => 'femte']))['ok']);

        $result = $this->callTool($user, 'create_game', $this->args(['slug' => 'sjatte']));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('5 games in the last 24 hours', $result['text']);
        $this->assertTrue($this->callTool($other, 'create_game', $this->args(['slug' => 'annans']))['ok']);
    }

    public function test_idea_is_required_and_bounded(): void
    {
        $user = $this->staffMember();

        $this->assertFalse($this->callTool($user, 'create_game', $this->args(['idea' => '']))['ok']);
        $this->assertFalse($this->callTool($user, 'create_game', $this->args(['idea' => str_repeat('x', 4001)]))['ok']);
        $this->assertSame(0, ProvisioningRun::count());
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=CreateGameToolTest
```

Förväntat: FAIL, `create_game` finns inte (JSON-RPC-fel "Tool [create_game] not found" eller tomt result).

- [ ] **Steg 3: `app/Mcp/Tools/CreateGameTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\Saga;
use App\Rules\GameSlug;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Startar provisioneringssagan och svarar direkt. Slugen reserveras i runnen;
// spelet skapas först när repot finns (steget game).
class CreateGameTool extends PlatformTool
{
    protected string $name = 'create_game';

    protected string $description = 'Create a new game: a private GitHub repo HumblebragAB/<slug> from the game template (the idea is written to its README), the game in Computercat (active, not publicly listed, you as owner), a Demo site at https://<slug>.apps.humblebrag.se behind basic auth, monitoring, and a first deploy. Returns immediately with a run id; follow up with get_provisioning_status. Limits: 15 Demo games in total and 5 new games per user per 24 hours.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('3-40 characters: lowercase letters, digits and single hyphens, starting with a letter. Becomes repo name and subdomain.')->required(),
            'name' => $schema->string()->description('Display name of the game, max 100 characters.')->required(),
            'idea' => $schema->string()->description('The game idea, max 4000 characters. Written to the README of the new repo.')->required(),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            return Response::error('Provisioning is not configured on the server. Missing: '.implode(', ', $missing).'. Nothing was created.');
        }

        $validator = Validator::make($args, [
            'slug' => ['required', 'string', new GameSlug],
            'name' => ['required', 'string', 'max:100'],
            'idea' => ['required', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $data = $validator->validated();

        // Kontroll och reservation under ett lås, så att två samtidiga anrop
        // inte båda ryms under taket. slug_lock är dessutom unik i databasen.
        $run = Cache::lock('provisioning:create-game', 10)->block(5, fn () => $this->reserve($data, $user));
        if ($run instanceof Response) {
            return $run;
        }

        Saga::start($run);

        return $this->json([
            'run' => $run->uuid,
            'slug' => $run->slug,
            'status' => $run->status,
            'repo' => ProvisioningConfig::repoFullName($run->slug),
            'demo_url' => ProvisioningConfig::demoUrl($run->slug),
            'next' => "Call get_provisioning_status with game \"{$run->slug}\" until status is \"succeeded\" (usually 5-15 minutes). The game appears in list_games once the step \"game\" is done.",
        ]);
    }

    private function reserve(array $data, User $user): ProvisioningRun|Response
    {
        if (Game::where('slug', $data['slug'])->exists()) {
            return Response::error('Slug is already used by a game in Computercat. Choose another slug.');
        }

        if (ProvisioningRun::where('slug_lock', $data['slug'])->exists()) {
            return Response::error('Slug is reserved by another provisioning run. Choose another slug.');
        }

        $max = (int) config('provisioning.max_demo_games');
        if (ProvisioningRun::countingTowardsCap()->count() >= $max) {
            return Response::error("The limit of {$max} Demo games is reached. Archive a Demo game first (archive_game) or ask Erik.");
        }

        $perDay = (int) config('provisioning.max_new_per_day');
        if (ProvisioningRun::provision()->where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
            return Response::error("You have created {$perDay} games in the last 24 hours. Try again later.");
        }

        try {
            return ProvisioningRun::create([
                'slug' => $data['slug'],
                'slug_lock' => $data['slug'],
                'name' => $data['name'],
                'user_id' => $user->id,
                'kind' => ProvisioningRun::KIND_PROVISION,
                'status' => ProvisioningRun::RUNNING,
                'idea' => $data['idea'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Response::error('Slug is reserved by another provisioning run. Choose another slug.');
        }
    }
}
```

- [ ] **Steg 4: Registrera verktyget.** I `app/Mcp/ComputercatServer.php`: lägg `Tools\CreateGameTool::class,` efter `Tools\ListGamesTool::class,` och ersätt `$instructions` med:

```php
    protected string $instructions = 'Configure Computercat games: leaderboards, achievements, content pools, remote config, events and allowed origins. Create new Demo games with create_game and follow them with get_provisioning_status. Only games you are a member of are visible.';
```

- [ ] **Steg 5: Kör testet och invarianterna.**

```bash
cd $WT && php artisan test --filter='CreateGameToolTest|ToolInvariantsTest'
```

Förväntat: alla gröna. `test_no_tool_leaks_game_secrets` anropar `create_game` med bara `game`; testmiljön är konfigurerad via `phpunit.xml` med tomma värden, så svaret blir "not configured" utan någon run.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Mcp/Tools/CreateGameTool.php app/Mcp/ComputercatServer.php tests/Feature/Mcp/CreateGameToolTest.php && git commit -m "MCP: create_game reserverar slug, kontrollerar tak och startar provisioneringen"
```

---

## Task 19: `get_provisioning_status` och `retry_provisioning`

**Files:**
- Create: `app/Mcp/Tools/GetProvisioningStatusTool.php`, `app/Mcp/Tools/RetryProvisioningTool.php`, `tests/Feature/Mcp/ProvisioningStatusToolTest.php`, `tests/Feature/Mcp/RetryProvisioningToolTest.php`
- Modify: `app/Mcp/ComputercatServer.php`

**Interfaces:**
- Consumes: `GameTool::whenGameMissing()`, `GameTool::transactional()`, `GameAccess::isOwner()`, `Saga::retry()`, `Redactor`.
- Produces: `get_provisioning_status(game)` → JSON `{slug, provisioning: {run, status, steps[], last_error, updated_at}, archive: {...}|null, repo_url, demo_url, demo_basic_auth: {username, password}}`; `retry_provisioning(game)` → JSON `{run, status: "running", resumes_at}`.

- [ ] **Steg 1: Fallerande tester.**

`tests/Feature/Mcp/ProvisioningStatusToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class ProvisioningStatusToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    public function test_member_sees_steps_urls_and_redacted_error(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);
        $run = $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $game->id, 'github_repo' => 'HumblebragAB/kattsvep', 'demo_url' => 'https://kattsvep.apps.humblebrag.se']);
        Saga::start($run);
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::DONE]);
        $run->update(['status' => ProvisioningRun::FAILED, 'last_error' => 'coolify_app: x '.ProvisioningFakes::COOLIFY_TOKEN]);

        $result = $this->callTool($member, 'get_provisioning_status', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame('failed', $json['provisioning']['status']);
        $this->assertSame(['step' => 'repo', 'status' => 'done', 'attempts' => 0], $json['provisioning']['steps'][0]);
        $this->assertSame('https://github.com/HumblebragAB/kattsvep', $json['repo_url']);
        $this->assertSame('https://kattsvep.apps.humblebrag.se', $json['demo_url']);
        $this->assertSame(['username' => 'demo', 'password' => 'demo'], $json['demo_basic_auth']);
        $this->assertStringNotContainsString(ProvisioningFakes::COOLIFY_TOKEN, $result['text']);
        $this->assertNull($json['archive']);
    }

    public function test_creator_sees_run_before_game_exists_but_others_do_not(): void
    {
        $creator = $this->staffMember();
        $other = $this->staffMember();
        $this->provisioningRun(['slug' => 'kattsvep', 'user_id' => $creator->id, 'status' => ProvisioningRun::RELEASED, 'last_error' => 'repo: Slug is taken on GitHub.']);

        $mine = $this->callTool($creator, 'get_provisioning_status', ['game' => 'kattsvep']);
        $theirs = $this->callTool($other, 'get_provisioning_status', ['game' => 'kattsvep']);

        $this->assertTrue($mine['ok'], $mine['text']);
        $this->assertStringContainsString('Slug is taken on GitHub', $mine['text']);
        $this->assertFalse($theirs['ok']);
        $this->assertStringContainsString('Game not found', $theirs['text']);
        $this->assertSame(['ok', 'denied'], McpAuditLog::where('tool', 'get_provisioning_status')->orderBy('id')->pluck('outcome')->all());
    }

    public function test_game_without_run_answers_clearly(): void
    {
        $game = $this->game('ordjakt');

        $result = $this->callTool($this->staffMember($game), 'get_provisioning_status', ['game' => 'ordjakt']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no provisioning run', $result['text']);
    }
}
```

`tests/Feature/Mcp/RetryProvisioningToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class RetryProvisioningToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
    }

    private function failedRun(array $attrs): ProvisioningRun
    {
        $run = $this->provisioningRun($attrs + ['status' => ProvisioningRun::FAILED]);
        Saga::start($run);
        $run->steps()->where('step', 'repo')->update(['status' => ProvisioningStep::FAILED]);

        return $run;
    }

    public function test_owner_retries_failed_run(): void
    {
        $game = $this->game('kattsvep');
        $owner = $this->staffMember();
        $game->members()->attach($owner, ['role' => 'owner']);
        $run = $this->failedRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $result = $this->callTool($owner, 'retry_provisioning', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame(ProvisioningRun::RUNNING, $run->fresh()->status);
        $this->assertSame('repo', json_decode($result['text'], true)['resumes_at']);
        Queue::assertPushed(AdvanceProvisioningRun::class, 2);
    }

    public function test_member_who_is_not_owner_cannot_retry(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);
        $run = $this->failedRun(['slug' => 'kattsvep', 'game_id' => $game->id]);

        $result = $this->callTool($member, 'retry_provisioning', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only the game owner', $result['text']);
        $this->assertSame(ProvisioningRun::FAILED, $run->fresh()->status);
    }

    public function test_running_and_released_runs_are_not_retried(): void
    {
        $creator = $this->staffMember();
        $this->provisioningRun(['slug' => 'kattsvep', 'user_id' => $creator->id, 'status' => ProvisioningRun::RUNNING]);
        $this->provisioningRun(['slug' => 'annat', 'user_id' => $creator->id, 'status' => ProvisioningRun::RELEASED]);

        $this->assertStringContainsString('Nothing to retry', $this->callTool($creator, 'retry_provisioning', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('slug was released', $this->callTool($creator, 'retry_provisioning', ['game' => 'annat'])['text']);
        Queue::assertNothingPushed();
    }

    public function test_creator_can_retry_run_without_game(): void
    {
        $creator = $this->staffMember();
        $other = $this->staffMember();
        $run = $this->failedRun(['slug' => 'kattsvep', 'user_id' => $creator->id]);

        $this->assertFalse($this->callTool($other, 'retry_provisioning', ['game' => 'kattsvep'])['ok']);
        $this->assertTrue($this->callTool($creator, 'retry_provisioning', ['game' => 'kattsvep'])['ok']);
        $this->assertSame(ProvisioningRun::RUNNING, $run->fresh()->status);
    }
}
```

- [ ] **Steg 2: Kör och se dem falla.**

```bash
cd $WT && php artisan test --filter='ProvisioningStatusToolTest|RetryProvisioningToolTest'
```

Förväntat: FAIL, verktygen finns inte.

- [ ] **Steg 3: `app/Mcp/Tools/GetProvisioningStatusTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\User;
use App\Provisioning\Http\Redactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Response;

class GetProvisioningStatusTool extends GameTool
{
    protected string $name = 'get_provisioning_status';

    protected string $description = 'Show how provisioning (or archiving) of a game is going: each step with status, the repo URL, the Demo URL with its basic auth login, and the latest error. Works for the creator even if the game has not been created yet.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $runs = ProvisioningRun::where('game_id', $game->id)->with('steps')->orderByDesc('id')->get();
        if ($runs->isEmpty()) {
            return Response::error('This game has no provisioning run.');
        }

        return $this->json($this->present($game->slug, $runs));
    }

    // Repo-steget kan falla innan spelet finns. Den som skapade runnen ska
    // ändå se varför; ingen annan får veta att runnen finns.
    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        $run = ProvisioningRun::provision()->where('slug', $slug)->where('user_id', $user->id)->whereNull('game_id')->with('steps')->orderByDesc('id')->first();

        return $run === null ? null : $this->json($this->present($slug, collect([$run])));
    }

    /** @param Collection<int, ProvisioningRun> $runs nyast först */
    private function present(string $slug, Collection $runs): array
    {
        $provision = $runs->firstWhere('kind', ProvisioningRun::KIND_PROVISION);
        $archive = $runs->firstWhere('kind', ProvisioningRun::KIND_ARCHIVE);

        return [
            'slug' => $slug,
            'provisioning' => $this->runData($provision),
            'archive' => $this->runData($archive),
            'repo_url' => $provision?->github_repo ? 'https://github.com/'.$provision->github_repo : null,
            'demo_url' => $provision?->demo_url,
            // Konvention (demo/demo), inte en hemlighet; spelets Claude behöver den för att kontrollera Demo.
            'demo_basic_auth' => [
                'username' => (string) config('provisioning.demo.basic_auth_user'),
                'password' => (string) config('provisioning.demo.basic_auth_password'),
            ],
        ];
    }

    private function runData(?ProvisioningRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'run' => $run->uuid,
            'status' => $run->status,
            'steps' => $run->steps->map(fn (ProvisioningStep $step) => [
                'step' => $step->step,
                'status' => $step->status,
                'attempts' => $step->attempts,
            ])->values()->all(),
            'last_error' => $run->last_error === null ? null : Redactor::limit(Redactor::scrubSecrets($run->last_error)),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Steg 4: `app/Mcp/Tools/RetryProvisioningTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\User;
use App\Provisioning\Saga;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class RetryProvisioningTool extends GameTool
{
    protected string $name = 'retry_provisioning';

    protected string $description = 'Resume a failed provisioning or archive run from its first unfinished step. Only the game owner (or the creator, if the game was never created). Fix the cause first; get_provisioning_status shows the error.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    // Köar ett jobb; ingen transaktion runt dispatch.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can retry provisioning.');
        }

        return $this->retry(ProvisioningRun::where('game_id', $game->id)->orderByDesc('id')->first());
    }

    protected function whenGameMissing(User $user, string $slug, array $args): ?Response
    {
        $run = ProvisioningRun::provision()->where('slug', $slug)->where('user_id', $user->id)->whereNull('game_id')->orderByDesc('id')->first();

        return $run === null ? null : $this->retry($run);
    }

    private function retry(?ProvisioningRun $run): Response
    {
        if ($run === null) {
            return Response::error('This game has no provisioning run.');
        }

        if ($run->status === ProvisioningRun::RELEASED) {
            return Response::error('The slug was released ('.$run->last_error.'). Start again with create_game and another slug.');
        }

        if ($run->status !== ProvisioningRun::FAILED || ! Saga::retry($run)) {
            return Response::error("Nothing to retry: the run is {$run->fresh()->status}.");
        }

        return $this->json([
            'run' => $run->uuid,
            'status' => ProvisioningRun::RUNNING,
            'resumes_at' => $run->steps()->where('status', '!=', ProvisioningStep::DONE)->value('step'),
        ]);
    }
}
```

- [ ] **Steg 5: Registrera verktygen** i `ComputercatServer::$tools` efter `Tools\CreateGameTool::class,`: `Tools\GetProvisioningStatusTool::class,` och `Tools\RetryProvisioningTool::class,`.

- [ ] **Steg 6: Kör testen och invarianterna.**

```bash
cd $WT && php artisan test --filter='ProvisioningStatusToolTest|RetryProvisioningToolTest|ToolInvariantsTest'
```

Förväntat: alla gröna. `test_every_game_tool_denies_non_members` omfattar nu båda verktygen; utomstående har ingen run för `glosis` och får "Game not found".

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Mcp/Tools/GetProvisioningStatusTool.php app/Mcp/Tools/RetryProvisioningTool.php app/Mcp/ComputercatServer.php tests/Feature/Mcp/ProvisioningStatusToolTest.php tests/Feature/Mcp/RetryProvisioningToolTest.php && git commit -m "MCP: get_provisioning_status och retry_provisioning, även för runs utan spel"
```

---

## Task 20: `request_live` och `archive_game`

**Files:**
- Create: `app/Mcp/Tools/RequestLiveTool.php`, `app/Mcp/Tools/ArchiveGameTool.php`, `tests/Feature/Mcp/RequestLiveToolTest.php`, `tests/Feature/Mcp/ArchiveGameToolTest.php`
- Modify: `app/Mcp/ComputercatServer.php`

**Interfaces:**
- Consumes: `GameTool::transactional()`, `GameAccess::isOwner()`, `SlackAlert`, `GameOrigin::isValid()`, `Saga::start()`.
- Produces: `request_live(game, domain?)` → JSON `{requested: true, message}`; `archive_game(game)` → JSON `{archive_run, status: "running"}`.

"Bara i Demo" tolkas som: spelet är olistat (`is_listed = false`). Erik listar spelet när det går Live, och därefter vägrar `archive_game`.

- [ ] **Steg 1: Fallerande tester.**

`tests/Feature/Mcp/RequestLiveToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\ProvisioningRun;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class RequestLiveToolTest extends McpTestCase
{
    use AssertsNoHttpInTransaction, CreatesProvisioningRuns;

    private \App\Models\Game $kattsvep;

    private \App\Models\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        $this->kattsvep = $this->game('kattsvep', ['is_listed' => false]);
        $this->owner = $this->staffMember();
        $this->kattsvep->members()->attach($this->owner, ['role' => 'owner']);
        $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $this->kattsvep->id, 'status' => ProvisioningRun::SUCCEEDED, 'demo_url' => 'https://kattsvep.apps.humblebrag.se']);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    public function test_owner_notifies_erik_with_demo_url_and_domain_and_nothing_changes(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);

        $result = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se']);
        $again = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertTrue($again['ok']);
        $this->assertStringContainsString('already', $again['text']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'https://kattsvep.apps.humblebrag.se') && str_contains($r['text'], 'kattsvep.se'));
        $this->assertFalse($this->kattsvep->fresh()->is_listed);
    }

    public function test_invalid_domain_is_rejected_without_slack(): void
    {
        Http::fake();

        $this->assertFalse($this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'https://x.se/path'])['ok']);
        $this->assertFalse($this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => '<!channel>'])['ok']);
        Http::assertNothingSent();
    }

    public function test_non_owner_and_game_without_demo_are_refused(): void
    {
        Http::fake();
        $member = $this->staffMember($this->kattsvep);
        $this->game('ordjakt')->members()->attach($this->owner, ['role' => 'owner']);

        $this->assertStringContainsString('Only the game owner', $this->callTool($member, 'request_live', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('finished Demo', $this->callTool($this->owner, 'request_live', ['game' => 'ordjakt'])['text']);
        Http::assertNothingSent();
    }

    public function test_slack_failure_is_an_error(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('nope', 500)]);

        $result = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not notify Erik', $result['text']);
    }
}
```

`tests/Feature/Mcp/ArchiveGameToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Jobs\AdvanceProvisioningRun;
use App\Models\ProvisioningRun;
use App\Provisioning\Saga;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class ArchiveGameToolTest extends McpTestCase
{
    use CreatesProvisioningRuns;

    private \App\Models\Game $kattsvep;

    private \App\Models\User $owner;

    private ProvisioningRun $provision;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Queue::fake();
        $this->kattsvep = $this->game('kattsvep', ['is_listed' => false]);
        $this->owner = $this->staffMember();
        $this->kattsvep->members()->attach($this->owner, ['role' => 'owner']);
        $this->provision = $this->provisioningRun([
            'slug' => 'kattsvep', 'game_id' => $this->kattsvep->id, 'status' => ProvisioningRun::SUCCEEDED,
            'github_repo' => 'HumblebragAB/kattsvep', 'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => 'https://kattsvep.apps.humblebrag.se',
        ]);
    }

    public function test_owner_starts_archive_run_with_copied_ids(): void
    {
        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $archive = ProvisioningRun::where('kind', ProvisioningRun::KIND_ARCHIVE)->sole();
        $this->assertSame(['kattsvep', null, $this->kattsvep->id, 'HumblebragAB/kattsvep', 'app-1', 42, ProvisioningRun::RUNNING],
            [$archive->slug, $archive->slug_lock, $archive->game_id, $archive->github_repo, $archive->coolify_app_uuid, $archive->siteops_site_id, $archive->status]);
        $this->assertSame(array_keys(Saga::ARCHIVE_STEPS), $archive->steps()->pluck('step')->all());
        Queue::assertPushed(AdvanceProvisioningRun::class, fn ($job) => $job->runId === $archive->id);
    }

    public function test_listed_game_is_not_archived(): void
    {
        $this->kattsvep->update(['is_listed' => true]);

        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('only allowed in Demo', $result['text']);
        Queue::assertNothingPushed();
    }

    public function test_non_owner_and_game_without_successful_run_are_refused(): void
    {
        $member = $this->staffMember($this->kattsvep);
        $this->game('ordjakt', ['is_listed' => false])->members()->attach($this->owner, ['role' => 'owner']);

        $this->assertStringContainsString('Only the game owner', $this->callTool($member, 'archive_game', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('successful provisioning run', $this->callTool($this->owner, 'archive_game', ['game' => 'ordjakt'])['text']);
        Queue::assertNothingPushed();
    }

    public function test_second_archive_is_refused_while_one_exists(): void
    {
        $this->assertTrue($this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep'])['ok']);

        $result = $this->callTool($this->owner, 'archive_game', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('archive run already exists', $result['text']);
    }
}
```

- [ ] **Steg 2: Kör och se dem falla.**

```bash
cd $WT && php artisan test --filter='RequestLiveToolTest|ArchiveGameToolTest'
```

Förväntat: FAIL, verktygen finns inte.

- [ ] **Steg 3: `app/Mcp/Tools/RequestLiveTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Rules\GameOrigin;
use App\Support\SlackAlert;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// Ber Erik om Live-gång. Ändrar ingenting; Live görs för hand.
class RequestLiveTool extends GameTool
{
    protected string $name = 'request_live';

    protected string $description = 'Ask Erik to take a finished Demo game live, optionally with a desired production domain. Sends a notification only; nothing is changed. Only the game owner.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'domain' => $schema->string()->description('Optional desired domain, e.g. kattsvep.se (no scheme, no path).'),
        ];
    }

    // Slack-anropet får inte ske inne i en databastransaktion.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can request Live.');
        }

        $validator = Validator::make($args, ['domain' => ['nullable', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail) {
            if (! GameOrigin::isValid('https://'.$value)) {
                $fail('The domain must be a plain host name such as kattsvep.se.');
            }
        }]]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $domain = $validator->validated()['domain'] ?? null;

        $run = ProvisioningRun::provision()->where('game_id', $game->id)->where('status', ProvisioningRun::SUCCEEDED)->orderByDesc('id')->first();
        if ($run === null) {
            return Response::error('Only games with a finished Demo can request Live.');
        }

        $text = 'Live-förfrågan för `'.SlackAlert::escape($game->slug).'` ('.SlackAlert::escape($game->name).') från '.SlackAlert::escape((string) ($user->email ?? $user->name))
            .". Demo: {$run->demo_url}. Önskad domän: ".($domain === null ? 'ingen angiven' : SlackAlert::escape($domain)).'.';

        return match (app(SlackAlert::class)->send("request_live:{$game->slug}:".($domain ?? '-'), $text)) {
            SlackAlert::SENT => $this->json(['requested' => true, 'message' => 'Erik has been notified. Nothing was changed.']),
            SlackAlert::DUPLICATE => $this->json(['requested' => true, 'message' => 'Erik was already notified about this today. Nothing was changed.']),
            default => Response::error('Could not notify Erik. Nothing was changed; try again later.'),
        };
    }
}
```

- [ ] **Steg 4: `app/Mcp/Tools/ArchiveGameTool.php`.**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\GameAccess;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Models\User;
use App\Provisioning\Saga;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

// Startar arkiveringssagan: Coolify-appen tas bort på sitt sparade uuid,
// SiteOps-sajten avaktiveras, repot arkiveras (raderas aldrig) och spelet
// stängs av. Bara ägaren, bara Demo-spel som provisionerats lyckat.
class ArchiveGameTool extends GameTool
{
    protected string $name = 'archive_game';

    protected string $description = 'Archive a Demo game that was created with create_game: removes its Demo site in Coolify, deactivates monitoring, archives (never deletes) the GitHub repo and deactivates the game. Only the game owner, only Demo games (not listed). Follow up with get_provisioning_status.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! GameAccess::isOwner($user, $game)) {
            return Response::error('Only the game owner can archive it.');
        }

        $provision = ProvisioningRun::provision()->where('game_id', $game->id)->where('status', ProvisioningRun::SUCCEEDED)->orderByDesc('id')->first();
        if ($provision === null) {
            return Response::error('Only games with a successful provisioning run can be archived.');
        }

        if ($game->is_listed) {
            return Response::error('The game is listed (Live). Archiving is only allowed in Demo; ask Erik.');
        }

        $existing = ProvisioningRun::where('kind', ProvisioningRun::KIND_ARCHIVE)->where('game_id', $game->id)
            ->whereIn('status', [ProvisioningRun::RUNNING, ProvisioningRun::FAILED])->first();
        if ($existing !== null) {
            return Response::error("An archive run already exists (status {$existing->status}). Use get_provisioning_status, or retry_provisioning if it failed.");
        }

        $archive = ProvisioningRun::create([
            'slug' => $game->slug,
            'slug_lock' => null,
            'name' => $game->name,
            'user_id' => $user->id,
            'game_id' => $game->id,
            'kind' => ProvisioningRun::KIND_ARCHIVE,
            'status' => ProvisioningRun::RUNNING,
            'idea' => null,
            // Bara det provisioneringen sparade får röras.
            'github_repo' => $provision->github_repo,
            'coolify_app_uuid' => $provision->coolify_app_uuid,
            'siteops_site_id' => $provision->siteops_site_id,
            'demo_url' => $provision->demo_url,
        ]);
        Saga::start($archive);

        return $this->json(['archive_run' => $archive->uuid, 'status' => ProvisioningRun::RUNNING]);
    }
}
```

- [ ] **Steg 5: Registrera verktygen** i `ComputercatServer::$tools` efter `Tools\RetryProvisioningTool::class,`: `Tools\RequestLiveTool::class,` och `Tools\ArchiveGameTool::class,`.

- [ ] **Steg 6: Kör testen, invarianterna och hela MCP-sviten.**

```bash
cd $WT && php artisan test tests/Feature/Mcp
```

Förväntat: alla gröna. `test_forbidden_tool_names_do_not_exist` godkänner `create_game`, `get_provisioning_status`, `retry_provisioning`, `request_live`, `archive_game` (inget matchar `delete|remove|destroy|product|purchase|user|player|settings|token`).

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Mcp/Tools/RequestLiveTool.php app/Mcp/Tools/ArchiveGameTool.php app/Mcp/ComputercatServer.php tests/Feature/Mcp/RequestLiveToolTest.php tests/Feature/Mcp/ArchiveGameToolTest.php && git commit -m "MCP: request_live larmar Erik, archive_game startar arkivering av egna Demo-spel"
```

---

## Task 21: Nattlig invariant `computercat:check-provisioning`

**Files:**
- Create: `app/Console/Commands/CheckProvisioning.php`, `tests/Feature/Console/CheckProvisioningTest.php`
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: `GitHubAppClient::getRepository()`, `CoolifyClient::demoApplications()`, `::application()`, `SiteOpsClient::site()`, `::environments()`, `DemoProbe::status()`, `SlackAlert`.
- Produces: `php artisan computercat:check-provisioning [--dry-run]`; exitkod 0 vid 0 avvikelser, 1 annars; sista raden `N avvikelse(r).`

Kontroller (spec 2c "Invariant" plus en för fastnade sagor):

| Nyckel | Avvikelse |
|---|---|
| `run:<uuid>:repo` | Repot saknas eller är arkiverat |
| `run:<uuid>:coolify` | Appen `spel-<slug>` med sparat uuid saknas i Demo-projektet |
| `run:<uuid>:basic_auth` | `is_http_basic_auth_enabled` är inte `true` |
| `run:<uuid>:siteops` | Sajten är inte aktiv eller har `monitoring_enabled: false`, eller demo-miljön (`type: demo`, url = Demo-URL) saknas, är inte övervakad eller saknar basic auth (`has_basic_auth`) |
| `run:<uuid>:demo` | Demo-URL svarar inte 200 med basic auth |
| `coolify:orphan:<uuid>` | En app `spel-*` i Demo-projektet saknar run |
| `run:<uuid>:stuck` | En run har stått på `running` utan stegaktivitet i 30 minuter (kö-workern) |
| `coolify:list` | Demo-projektet gick inte att läsa |

Varje kontroll som kastar blir en avvikelse ("kunde inte kontrollera"), aldrig ett avbrott.

- [ ] **Steg 1: Fallerande test.** `tests/Feature/Console/CheckProvisioningTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\ProvisioningRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\ProvisioningFakes;

class CheckProvisioningTest extends ProvisioningTestCase
{
    private const DEMO = 'https://kattsvep.apps.humblebrag.se';

    private const PROJECT_URL = 'coolify.test/api/v1/projects/demo-project-uuid/production';

    private ProvisioningRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->provisioningRun([
            'slug' => 'kattsvep', 'status' => ProvisioningRun::SUCCEEDED, 'github_repo' => 'HumblebragAB/kattsvep',
            'coolify_app_uuid' => 'app-1', 'siteops_site_id' => 42, 'demo_url' => self::DEMO,
        ]);
    }

    private function fakeHealthy(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.github.com/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', $this->run->uuid)),
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'), ProvisioningFakes::coolifyApp('app-x', 'ordjakt')])),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep')),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO)),
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::DEMO)),
            self::DEMO => Http::response('<html></html>'),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ], $overrides));
    }

    public function test_healthy_state_is_green_and_silent(): void
    {
        $this->fakeHealthy();

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
    }

    public function test_archived_repo_missing_basic_auth_and_unmonitored_env_are_reported_once_per_day(): void
    {
        $this->fakeHealthy([
            'api.github.com/repos/HumblebragAB/kattsvep' => Http::response(ProvisioningFakes::githubRepo('kattsvep', 'x', archived: true)),
            'coolify.test/api/v1/applications/app-1' => Http::response(ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep', basicAuth: false)),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO, monitored: false)),
        ]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('3 avvikelse(r).')->assertExitCode(1);
        $this->artisan('computercat:check-provisioning')->assertExitCode(1);

        $this->assertCount(3, Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK));
    }

    public function test_siteops_monitoring_off_is_a_deviation(): void
    {
        $this->fakeHealthy([
            'siteops.test/api/v1/sites/42' => Http::response(ProvisioningFakes::siteopsSite(42, self::DEMO, monitoring: false)),
        ]);
        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('monitoring_enabled')->assertExitCode(1);
    }

    // Egen test: ett andra Http::fake i samma test läggs efter det första och vinner aldrig.
    public function test_siteops_demo_environment_without_basic_auth_is_a_deviation(): void
    {
        $this->fakeHealthy([
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments(self::DEMO.'/', basicAuth: false)),
        ]);
        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('saknar basic auth')->assertExitCode(1);
    }

    public function test_orphan_app_and_stuck_run_are_reported(): void
    {
        $this->fakeHealthy([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([ProvisioningFakes::coolifyApp('app-1', 'spel-kattsvep'), ProvisioningFakes::coolifyApp('app-9', 'spel-okand')])),
        ]);
        $stuck = $this->provisioningRun(['slug' => 'fastnad']);
        $stuck->steps()->create(['step' => 'repo', 'status' => 'pending', 'attempts' => 1]);
        $this->travel(31)->minutes();

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('coolify:orphan:app-9')
            ->expectsOutputToContain("run:{$stuck->uuid}:stuck")
            ->assertExitCode(1);

        Http::assertNotSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
    }

    public function test_api_errors_become_deviations_not_crashes(): void
    {
        $this->fakeHealthy([
            self::PROJECT_URL => Http::response(['message' => 'Unauthenticated.'], 401),
            self::DEMO => Http::response('Bad Gateway', 502),
        ]);

        $this->artisan('computercat:check-provisioning --dry-run')
            ->expectsOutputToContain('coolify:list')
            ->expectsOutputToContain("run:{$this->run->uuid}:demo")
            ->assertExitCode(1);
    }

    public function test_missing_configuration_fails(): void
    {
        config(['provisioning.coolify.token' => '']);
        Http::fake();

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('COOLIFY_TOKEN')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_is_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'computercat:check-provisioning'));

        $this->assertCount(1, $events);
        $this->assertSame('15 6 * * *', $events->first()->expression);
    }
}
```

- [ ] **Steg 2: Kör och se det falla.**

```bash
cd $WT && php artisan test --filter=CheckProvisioningTest
```

Förväntat: FAIL, `The command "computercat:check-provisioning" does not exist.`

- [ ] **Steg 3: `app/Console/Commands/CheckProvisioning.php`.**

```php
<?php

namespace App\Console\Commands;

use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Clients\SiteOpsClient;
use App\Provisioning\DemoProbe;
use App\Provisioning\ProvisioningConfig;
use App\Provisioning\SafeMessage;
use App\Support\SlackAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Invarianten för provisioneringen (spec 2c): varje lyckad run har ett
 * oarkiverat repo, en Coolify-app spel-<slug> med basic auth i Demo-projektet,
 * en aktiv och övervakad SiteOps-sajt och en Demo-URL som svarar 200. Omvänt
 * har varje spel-*-app i Demo-projektet en run. Fastnade sagor larmar också,
 * eftersom en död kö-worker annars aldrig syns.
 */
class CheckProvisioning extends Command
{
    protected $signature = 'computercat:check-provisioning {--dry-run : Skriv ut avvikelser utan Slack-larm}';

    protected $description = 'Jämför lyckade provisioneringar mot GitHub, Coolify, SiteOps och Demo-adressen';

    /** @var array<string, string> nyckel => beskrivning */
    private array $deviations = [];

    public function handle(GitHubAppClient $github, CoolifyClient $coolify, SiteOpsClient $siteops, SlackAlert $slack): int
    {
        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            $this->error('Provisioneringen är inte konfigurerad, saknas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $apps = null;
        try {
            $apps = $coolify->demoApplications();
        } catch (Throwable $e) {
            $this->deviations['coolify:list'] = 'Kunde inte läsa Demo-projektet i Coolify: '.$this->describe($e);
        }

        foreach (ProvisioningRun::provision()->where('status', ProvisioningRun::SUCCEEDED)->orderBy('id')->get() as $run) {
            $this->checkRun($run, $apps, $github, $coolify, $siteops);
        }

        if ($apps !== null) {
            foreach ($apps as $app) {
                if (str_starts_with($app['name'], 'spel-') && ! ProvisioningRun::where('coolify_app_uuid', $app['uuid'])->exists()) {
                    $this->deviations["coolify:orphan:{$app['uuid']}"] = "Coolify-appen {$app['name']} ({$app['uuid']}) i Demo-projektet saknar provisioneringsrun.";
                }
            }
        }

        $this->checkStuck();

        foreach ($this->deviations as $key => $text) {
            $this->line("AVVIKELSE {$key}: {$text}");
            if (! $this->option('dry-run')) {
                $slack->send("check-provisioning:{$key}", 'computercat:check-provisioning: '.SlackAlert::escape($text));
            }
        }

        $this->info(count($this->deviations).' avvikelse(r).');

        return $this->deviations === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array{uuid: string, name: string}>|null $apps */
    private function checkRun(ProvisioningRun $run, ?array $apps, GitHubAppClient $github, CoolifyClient $coolify, SiteOpsClient $siteops): void
    {
        $key = "run:{$run->uuid}";

        $this->check("{$key}:repo", "repot för {$run->slug}", function () use ($github, $run) {
            $repo = $github->getRepository($run->slug);
            if ($repo === null) {
                return "Repot {$run->github_repo} finns inte.";
            }

            return ($repo['archived'] ?? null) === false ? null : "Repot {$run->github_repo} är arkiverat men runnen är succeeded.";
        });

        if ($apps !== null) {
            $listed = false;
            foreach ($apps as $app) {
                $listed = $listed || ($app['uuid'] === $run->coolify_app_uuid && $app['name'] === ProvisioningConfig::coolifyAppName($run->slug));
            }
            if (! $listed) {
                $this->deviations["{$key}:coolify"] = 'Coolify-appen '.ProvisioningConfig::coolifyAppName($run->slug)." ({$run->coolify_app_uuid}) finns inte i Demo-projektet.";
            } else {
                $this->check("{$key}:basic_auth", "basic auth för {$run->slug}", fn () => ($coolify->application((string) $run->coolify_app_uuid)['is_http_basic_auth_enabled'] ?? null) === true
                    ? null
                    : 'Coolify-appen '.ProvisioningConfig::coolifyAppName($run->slug).' har inte basic auth.');
            }
        }

        $this->check("{$key}:siteops", "SiteOps för {$run->slug}", function () use ($siteops, $run) {
            if ($run->siteops_site_id === null) {
                return 'Runnen har ingen SiteOps-sajt.';
            }
            $site = $siteops->site($run->siteops_site_id);
            if (($site['is_active'] ?? null) !== true) {
                return "SiteOps-sajten {$run->siteops_site_id} är inte aktiv.";
            }
            if (($site['monitoring_enabled'] ?? null) !== true) {
                return "SiteOps-sajten {$run->siteops_site_id} har övervakningen avstängd (monitoring_enabled).";
            }
            $want = SiteOpsClient::normalizeUrl((string) $run->demo_url);
            foreach ($siteops->environments($run->siteops_site_id) as $environment) {
                if (($environment['type'] ?? null) === 'demo' && SiteOpsClient::normalizeUrl((string) ($environment['url'] ?? '')) === $want) {
                    if (($environment['is_monitored'] ?? null) !== true) {
                        return "Demo-miljön i SiteOps-sajten {$run->siteops_site_id} övervakas inte.";
                    }

                    return ($environment['has_basic_auth'] ?? null) === true ? null : "Demo-miljön i SiteOps-sajten {$run->siteops_site_id} saknar basic auth (övervakningen får 401).";
                }
            }

            return "SiteOps-sajten {$run->siteops_site_id} har ingen miljö med {$run->demo_url}.";
        });

        $this->check("{$key}:demo", "Demo-adressen för {$run->slug}", function () use ($run) {
            $status = DemoProbe::status((string) $run->demo_url);

            return $status === 200 ? null : "{$run->demo_url} svarade {$status} med basic auth.";
        });
    }

    private function checkStuck(): void
    {
        $cutoff = now()->subMinutes(30);
        foreach (ProvisioningRun::where('status', ProvisioningRun::RUNNING)->get() as $run) {
            $last = $run->steps()->max('updated_at') ?? $run->updated_at;
            if (Carbon::parse($last)->lt($cutoff)) {
                $this->deviations["run:{$run->uuid}:stuck"] = "Runnen för {$run->slug} ({$run->kind}) har stått still sedan {$last}. Kör kö-workern?";
            }
        }
    }

    /** @param \Closure(): ?string $check returnerar en avvikelse eller null */
    private function check(string $key, string $what, \Closure $check): void
    {
        try {
            $deviation = $check();
        } catch (Throwable $e) {
            $deviation = "Kunde inte kontrollera {$what}: ".$this->describe($e);
        }

        if ($deviation !== null) {
            $this->deviations[$key] = $deviation;
        }
    }

    private function describe(Throwable $e): string
    {
        return $e instanceof SafeMessage ? $e->getMessage() : get_class($e);
    }
}
```

- [ ] **Steg 4: Schemaläggning.** Lägg till sist i `routes/console.php`:

```php
// Provisioneringens invariant (spec 2c): lyckade Demo-spel mot GitHub, Coolify, SiteOps och Demo-adressen.
Schedule::command('computercat:check-provisioning')->dailyAt('06:15')->withoutOverlapping(30);
```

- [ ] **Steg 5: Kör testet.**

```bash
cd $WT && php artisan test --filter=CheckProvisioningTest
```

Förväntat: `Tests: 8 passed`.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Console/Commands/CheckProvisioning.php routes/console.php tests/Feature/Console/CheckProvisioningTest.php && git commit -m "computercat:check-provisioning: nattlig invariant för Demo-spelen med Slack-larm"
```

---

## Task 22: Hela sviten, säkerhetsgranskning och completion-gate

**Files:** inga nya; eventuella fixar i berörda filer.

**Interfaces:** Consumes: allt ovan. Produces: grön svit, passerad `completion-gate`.

- [ ] **Steg 1: Hela sviten och pint.**

```bash
cd $WT && vendor/bin/pint --test && php artisan test
```

Förväntat: pint utan anmärkningar; `Tests: N passed` utan fel eller varningar. Fäller pint: kör `vendor/bin/pint` på de nya filerna och committa separat ("Pint").

- [ ] **Steg 2: Svep efter syskon (klass, inte instans).** Kör och läs varje träff:

```bash
cd $WT && grep -rn "Http::" app | grep -v "app/Provisioning\|app/Support/SlackAlert.php" ; grep -rn "DB::transaction\|beginTransaction" app/Provisioning app/Jobs app/Mcp ; grep -rn "->delete(\|'DELETE'\|\"DELETE\"" app/Provisioning ; grep -rn "{\$game->slug}\|{\$ex->slug}" app/Services
```

Förväntat: inga nya HTTP-anrop utanför klienterna/SlackAlert/DemoProbe; `DB::transaction` bara i `GameStep` och `AdvanceProvisioningRun::complete()` (utan HTTP inuti); `DELETE` bara i `CoolifyClient::deleteApplication()`; ingen oescapad slug i IntegrationGuide. En avvikande träff som inte kan fixas direkt: AskUserQuestion.

- [ ] **Steg 3: Säkerhetsgranskning.** Invokera `security-first-mindset` och kör dess review gate mot `git diff main...HEAD`. Läs särskilt: att inga nycklar når `AuditLogger` (verktygsargumenten är slug, name, idea, domain), att `last_error` och tool-svar bara bär `SafeMessage`-text, att GitHub-klienten saknar DELETE, att Coolify-spärren gäller alla muterande anrop, att SlackAlert aldrig loggar URL:en, att privat nyckel kräver läge 600, och att en kapad staff-session högst når 5 nya spel per dygn och bara arkiverar egna olistade spel.

- [ ] **Steg 4: completion-gate.** Invokera `completion-gate`. Block 1 ("fungerar det") kan inte bevisas med testsviten ensam; det bevisas av Task 24 mot riktiga tjänster. Gaten passeras här för koden ("redo för deploy"), och körs igen efter Task 24 innan något kallas klart. Icke-blockerande fynd buntas till en AskUserQuestion.

- [ ] **Steg 5: Commit eventuella fixar.**

```bash
cd $WT && git status --short && git add -A app tests routes config database && git commit -m "Provisionering: fynd från säkerhetsgranskning och completion-gate"
```

(Hoppa över commit om `git status` är tomt.)

---

## Task 23: Deploy till prod (kräver Eriks OK)

Computercat deployas av Forge med quick deploy vid push till `main` i `HumblebragAB/computercat-api`. Artisan i prod körs via `~/.claude/bin/ssh-claude --via-home forge@46.246.48.182` med `sudo -n -u computercat`. Sajtens katalog (nedan `$SITE`) läses ur Forge via skillen `laravel-forge` i steg 2.

**Files:** inga.

**Interfaces:** Consumes: Task 0 (alla punkter klara), Task 22. Produces: koden live, migrationerna körda, nycklarna i prod-`.env`, kö-workern startad om.

- [ ] **Steg 1: Fråga om OK (AskUserQuestion), med risk och rollback.** Text: "Deploy av provisioneringen: (1) wt-finish ff-mergar feat/provisionering till lokal main, (2) jag lägger in .env-värdena från Task 0.10 via Forge-API:t (värdena injiceras med claude-secret run och passerar aldrig min kontext) och nyckelfilen storage/app/private/github-app.pem (ägare computercat, 600), (3) push av main → Forge quick deploy, (4) `php artisan migrate --force` (lägger till games.is_listed default true och två nya tabeller; befintliga spel påverkas inte), (5) `php artisan config:cache` (bara om sajten redan cachar config; kontrollera först att `bootstrap/cache/config.php` finns, annars hoppas steget över) och `php artisan queue:restart`, (6) `computercat:check-provisioning --dry-run`, (7) worktreens lokala databas tas bort (`wt-finish --drop-db`) eller behålls. Risk: en trasig deploy tar ner API:t för alla spel. Rollback: `git revert` av merge-commiten på main + push (Forge deployar om); migrationerna rullas bara tillbaka med `migrate:rollback --step=3` efter separat OK (tabellerna är tomma tills första create_game). OK?" Utan OK: stanna.

- [ ] **Steg 2: Läs sajtkatalogen och lägg in .env och nyckeln.** Invokera skillen `laravel-forge` och använd endpoints exakt som den dokumenterar (gissa inga sökvägar): hitta servern med IP `46.246.48.182` och sajten för `api.computercat.co`, notera katalog (`$SITE`) och användare. Om Erik i Task 0.10 valde att jag lägger in variablerna: läs sajtens nuvarande `.env` via skillens env-endpoint till en fil i scratchpad med läge 600, lägg till raderna med `claude-secret run` (värdena passerar aldrig kontexten), skicka tillbaka via samma skill och skriv aldrig ut filen. Annars bekräftar Erik att han lagt in dem. Nyckelfilen: `claude-secret run --env-file=$SCRATCH/spike/.env.op -- bash -c 'printf %s "$GITHUB_APP_PRIVATE_KEY" | ~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "sudo -n -u computercat tee $SITE/storage/app/private/github-app.pem >/dev/null && sudo -n -u computercat chmod 600 $SITE/storage/app/private/github-app.pem"'`. Ta bort scratchpad-kopian av `.env` direkt efteråt.

- [ ] **Steg 3: Merge och push.**

```bash
~/.claude/bin/wt-finish $WT --drop-db
git -C /Users/erik/Web/computercat-api log main --oneline -5
git -C /Users/erik/Web/computercat-api push origin main
```

(`--drop-db` bara om Erik godkänt det i steg 1; annars `--keep-db`.) Förväntat: main innehåller alla commits från Task 1–22; push lyckas.

- [ ] **Steg 4: Vänta på Forge-deployen.** Läs sajtens senaste deployment via `laravel-forge`-skillens endpoint tills den är klar och lyckad. `curl -s -o /dev/null -w '%{http_code}' https://api.computercat.co/api/v1/games` → `200`.

- [ ] **Steg 5: Migrera, starta om workern, kör invarianten.**

```bash
~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd $SITE && sudo -n -u computercat php8.4 artisan migrate --force"
~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd $SITE && if [ -f bootstrap/cache/config.php ]; then sudo -n -u computercat php8.4 artisan config:cache; fi && sudo -n -u computercat php8.4 artisan queue:restart"
~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd $SITE && sudo -n -u computercat php8.4 artisan computercat:check-provisioning --dry-run"
```

Förväntat: tre migrationer körda (`add_is_listed_to_games_table`, `create_provisioning_runs_table`, `create_provisioning_steps_table`); invarianten skriver `0 avvikelse(r).` och listar inga `coolify:orphan` (spike-appen är raderad). En `coolify:orphan` eller ett konfigurationsfel: stanna och fråga.

- [ ] **Steg 6: Rök-test utan dataändring.** `curl -s https://api.computercat.co/api/v1/games | jq '.data | length'` → samma antal som före deployen (notera talet i steg 1). Erik kontrollerar i claude.ai att connectorn listar `create_game`, `get_provisioning_status`, `retry_provisioning`, `request_live` och `archive_game` (AskUserQuestion). Mattias informeras inte än.

---

## Task 24: Acceptans, Kattsvep (2d)

Första data-muterande körningen mot riktiga tjänster efter deploy. Ingen kund informeras förrän alla verifieringar är gröna och Erik har bestämt.

**Files:** inga.

**Interfaces:** Consumes: Task 23. Produces: `HumblebragAB/kattsvep`, Demo på `https://kattsvep.apps.humblebrag.se`, spelet `kattsvep` i Computercat, verifieringsresultat.

- [ ] **Steg 1: OK för routine A (AskUserQuestion).** "Skapa routine A på ditt konto (Computercat-connectorn påslagen, inget repo) som kör create_game för kattsvep? Den skapar riktiga resurser: repo, Coolify-app, SiteOps-sajt, spel i Computercat. Under körningen läser jag `computercat:check-provisioning --dry-run` på prod via ssh-claude var femte minut (bara läsning)." Utan OK: stanna.

- [ ] **Steg 2: Routine A.** Invokera skillen `schedule` och skapa en engångs-routine på Eriks konto, connectorn Computercat påslagen, inget repo, med exakt prompten: "Skapa ett nytt spel i Computercat: Kattsvep (slug `kattsvep`), ett minröj där katter letar bajs, med daglig topplista för snabbaste rensning. Följ upp med get_provisioning_status tills Demo-adressen svarar." Följ körningen. Under tiden, var femte minut: `ssh-claude --via-home forge@46.246.48.182 "cd $SITE && sudo -n -u computercat php8.4 artisan computercat:check-provisioning --dry-run"` (läsning; ingår i Eriks OK i steg 1). Fastnar sagan (`stuck`) eller blir `failed`: läs `last_error` via routinens get_provisioning_status, stanna och fråga Erik innan `retry_provisioning`.

- [ ] **Steg 3: Routine B.** När routine A rapporterat `succeeded`: skapa med `schedule`-skillen en routine B på Eriks konto med repot `HumblebragAB/kattsvep` och connectorn påslagen. Kan skillen inte koppla repot: AskUserQuestion till Erik att koppla det i routinens inställningar (ett klick). Prompt: "Bygg spelet enligt README och CLAUDE.md, konfigurera topplistan via Computercat, pusha till main och verifiera på Demo-adressen."

- [ ] **Steg 4: Verifiera (efter routine B).** Varje punkt med kommando och förväntat utfall; inga tokens skrivs ut (spara i skalvariabler):

1. Demo svarar och spelet laddar:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://kattsvep.apps.humblebrag.se/          # 401
curl -s -o /dev/null -w '%{http_code}\n' -u "$DEMO_BASIC_AUTH" https://kattsvep.apps.humblebrag.se/   # 200
curl -s -u "$DEMO_BASIC_AUTH" https://kattsvep.apps.humblebrag.se/ | grep -o '/_next/static/[^"]*\.js' | head -3
```

Öppna sedan sidan i webbläsaren (skillen `anthropic-skills:chrome-browser`) med basic auth och kontrollera att spelplanen ritas och att konsolen saknar fel.

2. Anonym spelare skickar en tid från Demo-origin (CORS-preflight OK) och syns i topplistan. Topplistans slug (`$LB`) hämtas från routine B:s rapport eller `get_game`.

```bash
ORIGIN=https://kattsvep.apps.humblebrag.se
curl -si -X OPTIONS "https://api.computercat.co/api/v1/games/kattsvep/leaderboards/$LB" -H "Origin: $ORIGIN" -H 'Access-Control-Request-Method: POST' -H 'Access-Control-Request-Headers: authorization,content-type' | grep -i '^access-control-allow-origin'
# förväntat: access-control-allow-origin: https://kattsvep.apps.humblebrag.se
TOKEN=$(curl -s -X POST https://api.computercat.co/api/v1/auth/anonymous -H "Origin: $ORIGIN" -H 'Content-Type: application/json' -d '{"display_name":"acceptans"}' | jq -r .token)
curl -s -o /dev/null -w '%{http_code}\n' -X POST "https://api.computercat.co/api/v1/games/kattsvep/leaderboards/$LB" -H "Origin: $ORIGIN" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"score": 61234}'
# förväntat: 2xx (201 eller 200 enligt LeaderboardController)
curl -s "https://api.computercat.co/api/v1/games/kattsvep/leaderboards/$LB" -H "Authorization: Bearer $TOKEN" | jq '.data[] | select(.user.display_name=="acceptans")'   # Entry = {rank, score, metadata, submitted_at, user: {id, display_name}}
```

Spelaren och tiden finns kvar i prod; Erik beslutar om dem i steg 5. Avvisar anti-cheat tiden: läs topplistans `settings.anti_cheat` via `get_game` och välj en tid inom intervallet.

3. SiteOps-sajten aktiv och miljön övervakad, repo, Coolify och Demo: `computercat:check-provisioning` (utan `--dry-run`, så att ett fel också larmar) via ssh-claude → `0 avvikelse(r).` och exitkod 0.

4. Revisionsloggen visar routinernas anrop:

```bash
~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd $SITE && sudo -n -u computercat php8.4 artisan tinker --execute=\"echo App\\Models\\McpAuditLog::where('created_at','>=',now()->subDay())->whereIn('tool',['create_game','get_provisioning_status','upsert_leaderboard_type','get_game'])->orderBy('id')->get(['tool','outcome','created_at'])->toJson();\""
```

Förväntat: minst `create_game` (ok), flera `get_provisioning_status` (ok) och `upsert_leaderboard_type` (ok).

5. `GET https://api.computercat.co/api/v1/games` innehåller inte `kattsvep` (olistat).

Faller någon punkt: stanna, beskriv vad som avviker och fråga Erik (AskUserQuestion). Ingen retry eller ändring utan svar.

- [ ] **Steg 5: completion-gate och Eriks beslut.** Kör `completion-gate` igen med verifieringen ovan som bevis (Demo-URL, topplisteraden, invariantens utskrift, revisionsloggens rader). Fråga sedan Erik med AskUserQuestion: "Kattsvep: (a) lever vidare som Demo, (b) arkiveras med archive_game (repo arkiveras, Coolify-appen tas bort, SiteOps avaktiveras, spelet stängs av), (c) acceptansspelaren och tiden tas bort först". Vid (b) körs `archive_game` av Erik via connectorn eller en routine; verifiera därefter att `get_provisioning_status` visar arkiveringsrunnen `succeeded` och att `computercat:check-provisioning` är grön. Vid (c): radera bara på den användares id som skapades i steg 4 punkt 2 (läs id ur `/auth/me` med samma token), räkna först, och bara efter Eriks uttryckliga OK.

- [ ] **Steg 6: Mattias.** Fråga Erik (AskUserQuestion) om och när Mattias ska informeras. Planen informerar aldrig själv.

---

## Spec-avvikelser och tolkningar som planen gör

1. **Coolify-token behöver `read`** utöver `write` + `deploy` (bilaga A §7: alla GET kräver `read`; sagan pollar och invarianten läser). Specen är rättad 2026-10-13 (Förutsättningar 4, Säkerhet). Task 0.6.
2. **SiteOps-nyckeln behöver `sites:read`** utöver `sites:write`. Specen är rättad 2026-10-13 (Förutsättningar 6). Task 0.8.
10. **SiteOps-adoption kräver ägarskap.** En webb som uppslaget eller 409 pekar på adopteras bara om den har rätt adress, är aktiv, har en demo-miljö med adressen och `PATCH` av miljön ger 200 (2a:s spärr på `sites.api_client_id`). Specen säger bara "annars uppslag på exakt URL". Task 11, Task 15.
11. **Invarianten läser `monitoring_enabled` och miljöns `has_basic_auth`** (2a Task 6) utöver `is_active` och `is_monitored`, eftersom 2a:s avaktivering stänger av `monitoring_enabled` och en Demo-miljö utan basic auth ger 401 i SiteOps övervakning. Task 21.
3. **Kö-workern:** `--timeout=120` är längre än databasköns `retry_after` (90 s), och `--tries=1` fäller ett jobb som släpps med `release()`. Planen sätter `$tries = 0`, `retryUntil()` och `$timeout = 60` på jobbet och föreslår `--timeout=60` eller `DB_QUEUE_RETRY_AFTER=180`. Task 0.3, Task 12.
4. **Datamodellen** får `slug_lock` (unik bland runs som inte är released, även i MySQL) och `name` (spelet skapas först i steget `game`); `idea` är nullable för arkiveringsruns.
5. **`COOLIFY_ENVIRONMENT_NAME`** saknas i specens inställningslista men krävs av `POST /applications/private-github-app` (eller `environment_uuid`). Standard `production`.
6. **Coolify-listning och projektspärr** bygger på `GET /projects/{uuid}/{environment_name}`, som inte finns i bilaga A; specen nämner `GET /applications` utan att bilagan dokumenterar svaret eller hur projektet framgår. Verifieras i spiken (S13); faller det stannar planen.
7. **"Bara i Demo"** för `archive_game` tolkas som `is_listed = false`.
8. **`get_provisioning_status`/`retry_provisioning` tar `game`**, men ett fel i `repo` inträffar innan spelet finns. Planen låter den som skapade runnen nå den på slugen; alla andra får "Game not found".
9. **Invarianten** larmar också för runs som stått på `running` i 30 minuter utan stegaktivitet (död kö-worker), utöver specens kontroller.
