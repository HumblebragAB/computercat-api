# Spelplattformen delprojekt 2: provisionering — design

Datum: 2026-10-13
Status: utkast för granskning (Erik)
Bygger på: `2026-10-13-spelplattform-mcp-design.md` (delprojekt 1, live 2026-10-13)

## Mål

Mattias säger "nytt spel: X" till Claude och får tillbaka ett repo, en spelbar Demo-adress och ett spel i Computercat, utan att någon människa klickar i GitHub, Coolify eller SiteOps. Acceptansen är att en Claude-molnsession på egen hand bygger **Kattsvep** (minröj där katter letar bajs, daglig topplista för snabbaste rensning) från idé till spelbar Demo.

## Beslut (brainstorm 2026-10-13)

1. **Ett repo per spel**, `HumblebragAB/<slug>`, skapat från mallrepot `HumblebragAB/spelmall`.
2. **Mallen följer Mattias stack** (Tocco, birthday-game, julklappsspel): Next.js, React, Tailwind, Zustand, framer-motion. Byggs med `output: 'export'` till statiska filer.
3. **Bygge på Coolify-servern** med nixpacks + statisk sajt (nginx), ett bygge i taget (`concurrent_builds = 1` finns redan).
4. **SiteOps API byggs ut** så att Demo-spel registreras och övervakas automatiskt.
5. **Acceptans via Claude Code-routine** på Eriks konto, inte via en konstgjord testrigg.
6. Taket: högst **15 Demo-spel** totalt och **5 nya per dygn** per användare.

## Externa fakta (verifierade mot dokumentation)

Fullständiga anteckningar med käll-rader ligger i bilagorna längst ned. Sammanfattning:

**GitHub** (docs.github.com)
- Skapa från mall: `POST /repos/{template_owner}/{template_repo}/generate`, body `name` (krävs), `owner`, `description`, `private`, `include_all_branches`. `private` är **false** som standard och måste alltid skickas som `true`. Mallen måste ha `is_template: true`.
- Kräver GitHub App-rättigheten Administration: write + Contents: read. Installation access token fungerar.
- App-autentisering: JWT RS256 (`iat` −60 s, `exp` ≤ 10 min, `iss` = client id) → `POST /app/installations/{id}/access_tokens` (giltig 1 h, kan begränsas med `repositories`/`permissions`).
- Administration: write tillåter också `DELETE /repos/...`. Skyddet är att koden aldrig anropar det (test) och att appen installeras på "Only select repositories" (den får automatiskt åtkomst till repon den själv skapar).

**Coolify v4.4.6** (openapi.yaml och controllers vid taggen v4.4.6)
- Skapa: `POST /api/v1/applications/private-github-app` med `project_uuid`, `server_uuid`, `environment_name`, `github_app_uuid`, `git_repository`, `git_branch`, `build_pack`. Valfritt: `name`, `domains` (kommaseparerad sträng), `ports_exposes`, `is_static`, `static_image` (`nginx:alpine`), `publish_directory`, `install_command`, `build_command`, `is_auto_deploy_enabled`, `instant_deploy`, `is_http_basic_auth_enabled` + `http_basic_auth_username` + `http_basic_auth_password`. Svar 201 `{uuid}`, 409 vid domänkonflikt.
- Next.js statisk export: `build_pack: nixpacks`, `is_static: true`, `publish_directory: out` (officiell Coolify-dokumentation för Next.js).
- Deploy: `POST /api/v1/deploy?uuid=...` → `{deployments:[{deployment_uuid}]}`. Status: `GET /api/v1/deployments/{uuid}`, `status` ∈ `queued | in_progress | finished | failed | cancelled-by-user`.
- Radera: `DELETE /api/v1/applications/{uuid}` (asynkront).
- Auto-deploy via Coolifys GitHub App-källa, matchas på repo-id + gren när `is_auto_deploy_enabled` är sant. Ingen webhook per app behövs.
- Token: rättigheter `read` (alla GET: status, listning, invariant; bilaga A §7), `write` (skapa/radera) och `deploy`. Bunden till det team som var aktivt när den skapades. Skapas bara i UI.

**SiteOps** (`~/Web/siteops`, läst i koden)
- `POST /api/v1/sites` finns (scope `sites:write`, auth `X-Api-Key` eller JWT via egen `OAuthMiddleware`), men kan inte sätta basic auth, `is_monitored`, välja lösning eller avaktivera. Det byggs ut här (se delprojekt 2a).

## Förutsättningar (engångs, före bygget av 2c)

Var och en kräver Eriks OK eller görs av Erik:

1. **Kö-worker i prod.** Computercat har idag inga köjobb och troligen ingen worker. En Forge-daemon `php8.4 artisan queue:work --tries=1 --timeout=120` som användaren `computercat` skapas.
2. **Slack-webhook** för larm (`SLACK_ALERT_WEBHOOK_URL`). Ingen Slack-larmning finns i Computercat idag.
3. **GitHub App "Humblebrag Spelstudio"** (Administration: write, Contents: write, Metadata: read), installerad på "Only select repositories" med `spelmall`.
4. **Coolify-token** med `read` + `write` + `deploy` (inte `root`, inte `*:sensitive`), skapad med Humblebrags team aktivt.
5. **Coolifys och Claudes GitHub App-installationer** på HumblebragAB måste nå nya repon. Är någon av dem på "Only select repositories" måste den ändras till "All repositories", eftersom vår app inte kan utöka andra appars installationer.
6. **SiteOps-nyckel** med exakt `sites:read` + `sites:write`, skapad med `php artisan api-clients:create` (2a) efter att 2a deployats.
7. Nycklarna läggs i Computercats `.env` på Forge.
8. **Claude Code-molnmiljön** tillåter `api.computercat.co` och `*.apps.humblebrag.se`, och Computercat-connectorn är tillagd i claude.ai.

## Ej verifierat, verifieras i en spike innan 2c byggs

1. `git_repository`-formatet för GitHub App-källa (`owner/repo` eller full URL).
2. Att `ports_exposes: "80"` är rätt för statisk nginx-image.
3. Att basic auth satt via API vid skapande ger samma Traefik-labels som UI:t, så att `robots-sync`/`coolify-health` inte larmar.
4. Att build-variabler satta via `POST /applications/{uuid}/envs` med `is_buildtime: true` (finns i controllern, inte i OpenAPI-specen) når `next build`.
5. Att wildcard-DNS för `*.apps.humblebrag.se` pekar på Coolify-servern.
6. Att en molnsession som instrueras att pusha till `main` faktiskt gör det (prefixet `claude/` är ett standardval enligt dokumentationen, inte en spärr).
7. Att en `next build` med statisk export klarar sig med Coolify-serverns minne medan övriga appar kör (mät RAM under bygget).
8. Att Coolifys nixpacks bygger mallen med Node 22. Nixpacks standard är Node 18 och Next.js 16 kräver ≥ 20.9; mallen sätter `engines.node: "22.x"`, och versionen kan också sättas med `NIXPACKS_NODE_VERSION` (nixpacks.com/docs/providers/node). Kontrolleras i byggloggen.

Spiken använder en riktig Demo-app med Coolify-namn `spel-e2e-spike`, som tas bort på sitt uuid efteråt. Faller en punkt: stanna och fråga Erik.

## Uppdelning

| Del | Repo | Innehåll |
|---|---|---|
| 2a | siteops | API: skapa sajt med miljö av typ demo, uppdatera miljö, avaktivera, slå upp på URL |
| 2b | spelmall (nytt) | Mallrepot med exempelspel, Computercat-klient, CLAUDE.md |
| 2c | computercat-api | Provisionerings- och arkiveringssagor, MCP-verktyg, Slack-larm, invariant |
| 2d | — | Acceptans: Kattsvep |

Ordning: förutsättningarna, 2a och 2b (oberoende av varandra), deploy av 2a till SiteOps och push av mallrepot (båda med Eriks OK), spiken, 2c, sist 2d. Spiken kan inte köras förrän 2a är deployad och `HumblebragAB/spelmall` finns.

---

## 2a. SiteOps API

Nya eller utökade routes under `/api/v1`, samma `OAuthMiddleware` och scope-kontroll som befintliga:

| Route | Scope | Gör |
|---|---|---|
| `POST /sites` (utökas) | `sites:write` | Ny valfri `environment`: `{type: "demo", name, url, basic_auth_user, basic_auth_password, is_monitored}`. Skapar bara den miljön, inte den tomma Production-miljön. |
| `GET /sites?url=` | `sites:read` | Slå upp sajt på exakt URL (webbens eller en miljös, avslutande `/` ignoreras), för idempotens. `/sites/search` är delsträngssökning och används inte. |
| `PATCH /sites/{id}/environments/{env}` | `sites:write` | Sätter `basic_auth_user`, `basic_auth_password`, `is_monitored`. Bara på webbar som samma API-klient skapade (`sites.api_client_id`), annars 403. |
| `POST /sites/{id}/deactivate` | `sites:write` | `active = false`, `monitoring_enabled = false`. Bara på webbar som samma API-klient skapade, annars 403. Ingen radering via API. |

`POST /sites` med en adress som redan finns i teamet ger 409 med `site_id`. `GET /sites/{id}` får `monitoring_enabled` och `GET /sites/{id}/environments` får `type` och `has_basic_auth`, för 2c:s invariant. Det exakta kontraktet låses i planen för 2a.

- `type` måste finnas i `Environment::TYPES`.
- Basic auth lagras krypterat som i UI:t och visas aldrig i API-svar.
- Validering: `url` måste vara `https://`, `basic_auth_*` max 255, båda eller ingen.
- Sajten får som idag en egen lösning (`BelongsToSolution`). Att samla spelen i en gemensam lösning ingår inte.
- Ny API-klient för Computercat med `sites:read` + `sites:write`.
- Tester: skapa med demo-miljö, uppslag på URL, uppdatera, avaktivera, scope saknas → 403, lösenord syns aldrig i svar.

## 2b. Spelmallen `HumblebragAB/spelmall`

- Next.js (App Router), React, Tailwind, Zustand, framer-motion, TypeScript. `next.config.ts` med `output: 'export'` och `images.unoptimized`.
- `lib/computercat/` — liten klient: anonym inloggning, lagring av token, ny anonym användare vid 401, offline-först (lokalt först, synk i bakgrunden), topplistor, sparningar. Läser `NEXT_PUBLIC_COMPUTERCAT_GAME`; API-adressen `https://api.computercat.co/api/v1` är hårdkodad som standard och kan skrivas över med `NEXT_PUBLIC_COMPUTERCAT_URL`.
- Ett minimalt spelbart exempel (klick-räknare med topplista) som bevisar hela kedjan direkt efter första deploy.
- `CLAUDE.md` för spelets Claude:
  - Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.
  - Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg (`get_game`, `upsert_*`). Hämta integrationsmanualen med `get_integration_guide`.
  - Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt. Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
  - Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.
- `README.md` med samma sak för människor, plus spelets idé mellan raderna `<!-- computercat:idea:start -->` och `<!-- computercat:idea:end -->` (fylls i av `create_game`, se steget `repo`; kontraktet står i planen för 2b).
- `package.json` med `engines.node: "22.x"` (nixpacks standard är Node 18, Next.js 16 kräver ≥ 20.9).
- Markeras som mallrepo (`is_template: true`). Ingen CI (Actions-budget); bygget verifieras av Coolify.

## 2c. Provisionering i Computercat

### Konfiguration (Forge `.env`, med Eriks OK)

`GITHUB_APP_CLIENT_ID`, `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH` (fil i storage, 600), `GITHUB_TEMPLATE_REPO=HumblebragAB/spelmall`, `COOLIFY_URL`, `COOLIFY_TOKEN`, `COOLIFY_PROJECT_UUID` (Demo), `COOLIFY_SERVER_UUID`, `COOLIFY_GITHUB_APP_UUID`, `SITEOPS_URL`, `SITEOPS_API_KEY`, `DEMO_BASIC_AUTH_USER/PASSWORD` (demo/demo enligt befintlig konvention), `SLACK_ALERT_WEBHOOK_URL`. `lcobucci/jwt` krävs uttryckligen i composer (finns idag bara indirekt via Passport). Saknas en inställning svarar `create_game` med vilken, innan något skapas.

### Slug

- Regel `^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){2,39}\z`: giltig som GitHub-reponamn och DNS-etikett, inga dubbla eller avslutande bindestreck.
- Reserverade: `www`, `api`, `admin`, `app`, `apps`, `demo`, `mail`, `spel`, samt varje befintlig underdomän under `apps.humblebrag.se` (lista i config, uppdateras när Coolify-appar läggs till för hand).
- Regeln läggs i en regelklass som `create_game` använder. `GameResource` får regeln bara för nya spel (befintliga `tocco`, `tells`, `glosis` matchar redan, men redigering av ett befintligt spel ska aldrig blockeras).
- IntegrationGuide skickar även spelets och eventens slug genom `code()`.

### Datamodell

**`provisioning_runs`**: `id`, `uuid`, `slug` (unik bland runs som inte är `released`), `user_id`, `game_id` (null tills spelet skapats), `kind` (`provision | archive`), `status` (`running | succeeded | failed | released | archived`), `idea` (text), `github_repo`, `coolify_app_uuid`, `siteops_site_id`, `demo_url`, `last_error` (text, max 4 KB, se redigering nedan), timestamps.
**`provisioning_steps`**: `run_id`, `step`, `status` (`pending | done | failed`), `attempts`, `detail` (json), timestamps. Unik på (`run_id`, `step`).

### Provisioneringssagan

Slugen reserveras när run-raden skapas. Spelposten skapas först när repot finns, så att ett tidigt fel aldrig lämnar ett spel som låser slugen.

| Steg | Gör | Idempotens |
|---|---|---|
| `repo` | `POST /repos/HumblebragAB/spelmall/generate` med `name: <slug>`, `private: true`, `description: "Computercat-spel <slug> (run <uuid>)"`. Skriver idén mellan idé-markörerna i `README.md` via Contents-API (404 försöks igen en stund, eftersom `/generate` fyller repot asynkront; saknade eller dubblerade markörer → steget fallerar och skriver ingenting). | Vid omkörning: `GET /repos/HumblebragAB/<slug>`; godta bara om `description` innehåller run-uuid och `template_repository.full_name` är mallen. 422 "name already exists" utan match → run `failed` med "slug upptagen på GitHub", slugen släpps (`released`). |
| `game` | Skapa spelet **aktivt** med `is_listed = false`, anroparen som `owner` | slug i `games`, kopplad till runnens `game_id` |
| `coolify_app` | `POST /applications/private-github-app`: namn `spel-<slug>`, domän `https://<slug>.apps.humblebrag.se`, nixpacks + statisk + `out`, basic auth, `is_auto_deploy_enabled: true`, `instant_deploy: false` | sparat uuid; annars `GET /applications` och matcha exakt namn `spel-<slug>` i Demo-projektet. 409 domänkonflikt → `failed`. |
| `coolify_env` | `NEXT_PUBLIC_COMPUTERCAT_GAME=<slug>` som build-variabel | finns redan med samma värde |
| `cors` | Demo-adressen i spelets `allowed_origins` | finns redan |
| `siteops` | Skapa sajt typ `app` med demo-miljö, basic auth, `is_monitored: true` | sparat id; annars uppslag på exakt URL eller 409 från `POST /sites`. En befintlig webb adopteras bara om adressen är Demo-URL:en, den är aktiv, har en demo-miljö med adressen och `PATCH` av miljön lyckas (alltså att Computercats klient skapade den); annars `failed` |
| `deploy` | `POST /deploy?uuid=`; ett jobb som pollar `GET /deployments/{uuid}` och släpper sig själv (`release(30)`) tills `finished`, max 15 min; sedan `GET` Demo-URL med basic auth → 200 | sparat deployment-uuid |

**`is_listed`** är en ny kolumn på `games` (default `true`, så befintliga spel påverkas inte). `GET /api/v1/games` visar bara listade spel. Demo-spel är aktiva så att spelar-API:t fungerar, men syns inte i den publika listan förrän Erik listar dem vid Live.

**Arkiveringssagan** (`kind: archive`), samma motor, stegen: `coolify_delete` (bara det sparade uuid:t), `siteops_deactivate`, `repo_archive` (`PATCH archived: true`), `game_deactivate` (`is_active = false`). Ingen HTTP-anrop sker inne i en databastransaktion.

**Fel:** steget `failed`, `last_error` sparas, Slack-larm, sagan stannar. `retry_provisioning` fortsätter från första ej klara steg. 4xx/5xx från GitHub, Coolify och SiteOps loggas med metod, URL, status och body, max 4 KB, där `Authorization`, `private_key`, `password`, `token`, `value` och Coolifys `logs` tas bort. Samma redigering gäller `last_error`, som visas för spelets medlemmar.

**Klienternas egna spärrar** (testade på samma sätt som GitHub-spärren):
- GitHub-klienten har ingen metod för `DELETE`; ett test i hela sviten (Http::fake) säkerställer att inget `DELETE` mot `api.github.com` skickas.
- Coolify-klienten tar bara emot uuid:n som finns i `provisioning_runs`, och kontrollerar `project_uuid == COOLIFY_PROJECT_UUID` innan `DELETE` eller `PATCH`.

### Slack-larm

En liten `SlackAlert`-klass (POST till webhooken, timeout 5 s, fel loggas men stoppar aldrig anroparen) med dedupe per nyckel och dygn via cache. Används av sagan, `request_live` och invarianten.

### Behörighet

- `GameAccess::isOwner(User, Game)`: ägare enligt pivot, eller plattformsadmin.
- En gemensam bas för verktyg utan spel (`create_game`, `list_games`) med samma revisionslogg och felhantering som `GameTool`; `ListGamesTool` flyttas dit.

### MCP-verktyg

| Verktyg | Vem | Gör |
|---|---|---|
| `create_game(slug, name, idea)` | staff | Kontrollerar inställningar, slugregel och reservationer, tak och att slugen är ledig i Computercat och bland runs. Startar sagan och svarar direkt med run-id. |
| `get_provisioning_status(game)` | medlem | Steg och status, repo-URL, Demo-URL, senaste fel (redigerat) |
| `retry_provisioning(game)` | ägare | Fortsätter från första ej klara steg |
| `request_live(game, domain?)` | ägare | Slack-larm till Erik med spel, Demo-URL och önskad domän. Ändrar inget. |
| `archive_game(game)` | ägare, bara spel med en lyckad provisioneringsrun och bara i Demo | Startar arkiveringssagan |

**Tak:** 15 gäller runs med status `running`, `succeeded` eller `failed` (alltså spel som finns eller kan finnas på Coolify). 5 per dygn och användare räknar alla `create_game`-anrop som skapade en run.

### Invariant (nattlig, `computercat:check-provisioning`)

För varje run med status `succeeded`: repot finns och är inte arkiverat, Coolify-appen `spel-<slug>` finns i Demo-projektet och har basic auth, SiteOps-sajten är aktiv med `monitoring_enabled` och demo-miljön är övervakad och har basic auth, Demo-URL svarar 200 med basic auth. Omvänt: varje Coolify-app i Demo-projektet med namn `spel-*` har en run. Avvikelse → Slack-larm (dedupe per avvikelse och dygn). `--dry-run` finns.

## Säkerhet

- **Nycklar** finns bara i `.env`/storage på Forge, aldrig i verktygssvar eller loggar. De passerar aldrig verktygsargument, så revisionsloggen (som kortar strängar till 200 tecken men inte redigerar) får dem aldrig.
- **GitHub App** på "Only select repositories"; inget `DELETE` i kod, vaktat av test.
- **Coolify-token** `read` + `write` + `deploy`, inte `root`. Den når hela teamet, därför klientens spärr på sparade uuid:n och Demo-projektet.
- **SiteOps-nyckel** bara `sites:read` + `sites:write`.
- **Slug** valideras strikt innan den hamnar i URL:er, reponamn eller domäner.
- **Kapat claude.ai-konto (staff):** högst 5 nya Demo-spel per dygn och 15 totalt, arkivering bara av egna Demo-spel, inget i Live, inga raderingar av repon eller data.
- **Coolify-servern** skyddas av taket och av att byggen köas ett i taget. Spiken mäter minnet under ett bygge.
- **Basic auth på Demo** är en konvention (demo/demo), inte en hemlighet.

## Acceptans: Kattsvep (2d)

Körning:
1. Routine A (Eriks konto, connectorn påslagen, inget repo): "Skapa ett nytt spel i Computercat: Kattsvep (slug `kattsvep`), ett minröj där katter letar bajs, med daglig topplista för snabbaste rensning. Följ upp med get_provisioning_status tills Demo-adressen svarar."
2. När sagan är klar kopplas `HumblebragAB/kattsvep` till routine B (Claude gör det i Eriks session om routine-API:t tillåter det, annars är det ett klick för Erik i routinens inställningar): "Bygg spelet enligt README och CLAUDE.md, konfigurera topplistan via Computercat, pusha till main och verifiera på Demo-adressen."
3. Verifiering (av Claude i Eriks session):
   - Demo-URL svarar 200 med basic auth och spelet laddar.
   - En anonym spelare kan skicka en tid via API:t från Demo-origin (CORS-preflight OK) och den syns i topplistan.
   - SiteOps-sajten är aktiv och miljön övervakas.
   - `computercat:check-provisioning` är grön.
   - Revisionsloggen visar routinens anrop.
4. Erik väljer om Kattsvep lever vidare eller arkiveras med `archive_game`.

## Ingår inte

- Live-gång, egna domäner, App Store/Capacitor.
- Dokument-samlingar och AI-mallar (delprojekt 3).
- Automatisk radering av något.

## Bilagor

- A: `bilagor/2026-10-13-coolify-api.md`
- B: `bilagor/2026-10-13-github-app.md`
