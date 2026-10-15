# Spelplattformen delprojekt 2: provisionering — design

Datum: 2026-10-13 (reviderad samma dag: byggguide i stället för mallrepo)
Status: utkast för granskning (Erik)
Bygger på: `2026-10-13-spelplattform-mcp-design.md` (delprojekt 1, live 2026-10-13)

## Mål

Mattias säger "nytt spel: X" till Claude och får tillbaka ett repo, en Demo-adress och ett spel i Computercat, utan att någon människa klickar i GitHub, Coolify eller SiteOps. Spelets Claude hämtar sedan grundkoden ur Computercats byggguide, pushar den och bygger spelet. Acceptansen är att Claude-molnsessioner på egen hand bygger **Kattsvep** (minröj där katter letar bajs, daglig topplista för snabbaste rensning) från idé till spelbar Demo.

## Beslut (brainstorm 2026-10-13, reviderat 2026-10-13)

1. **Ett repo per spel**, `HumblebragAB/<slug>`, skapat tomt (privat, med en första commit) av `create_game`. **Inget mallrepo på GitHub** (Eriks beslut 2026-10-13).
2. **Computercat serverar byggreglerna.** En versionerad **byggguide** (först `v1`) ligger som filer i computercat-api: stack med exakt låsta versioner, kommandon, obligatoriska kontroller, `CLAUDE.md` för spelets Claude och de färdiga, testade Computercat-klientfilerna. Spelets Claude hämtar den med MCP-verktyget `get_build_guide` och skriver filerna i spelrepot.
3. **Byggguiden följer Mattias stack** (Tocco, birthday-game, julklappsspel): Next.js, React, Tailwind, Zustand, framer-motion. Byggs med `output: 'export'` till statiska filer.
4. **Bygge på Coolify-servern** med nixpacks + statisk sajt (nginx), ett bygge i taget (`concurrent_builds = 1` finns redan). Första bygget startar när spelets Claude pushar grundkoden (auto-deploy), inte av Computercat.
5. **SiteOps API byggs ut** så att Demo-spel registreras och övervakas automatiskt.
6. **Acceptans via Claude Code-routine** på Eriks konto, inte via en konstgjord testrigg.
7. Taket: högst **15 Demo-spel** totalt och **5 nya per dygn** per användare.

## Externa fakta (verifierade mot dokumentation)

Fullständiga anteckningar med käll-rader ligger i bilagorna längst ned. Sammanfattning:

**GitHub** (docs.github.com)
- Skapa repo i organisationen: `POST /orgs/{org}/repos`, body `name` (krävs), `description`, `private` (standard **false**, skickas alltid som `true`), `auto_init` ("Pass true to create an initial commit with empty README", standard false). Svar 201; 422 "Validation failed, or the endpoint has been spammed." (docs.github.com/en/rest/repos/repos#create-an-organization-repository).
- Rättigheter för GitHub App (docs.github.com/en/rest/authentication/permissions-required-for-github-apps): `POST /orgs/{org}/repos` står under "Administration" repository permissions (write, UAT/IAT) och även under "Repository creation" (write), båda med "additional permissions" markerat. Sidan säger inte hur de kombineras; spiken (S8) visar om Administration: write räcker. `PUT /repos/{owner}/{repo}/contents/{path}` kräver Contents: write, `GET` samma sökväg Contents: read.
- "If the app creates any repositories, the app will automatically be granted access to those repositories as well." (docs.github.com/en/apps/using-github-apps/installing-your-own-github-app). En installation på "Only select repositories" når alltså repon den själv skapar.
- App-autentisering: JWT RS256 (`iat` −60 s, `exp` ≤ 10 min, `iss` = client id) → `POST /app/installations/{id}/access_tokens` (giltig 1 h, kan begränsas med `repositories`/`permissions`).
- Administration: write tillåter också `DELETE /repos/...`. Skyddet är att koden aldrig anropar det (test) och att appen installeras på "Only select repositories".

**Coolify v4.4.6** (openapi.yaml och controllers vid taggen v4.4.6)
- Skapa: `POST /api/v1/applications/private-github-app` med `project_uuid`, `server_uuid`, `environment_name`, `github_app_uuid`, `git_repository`, `git_branch`, `build_pack`. Valfritt: `name`, `domains` (kommaseparerad sträng), `ports_exposes`, `is_static`, `static_image` (`nginx:alpine`), `publish_directory`, `install_command`, `build_command`, `is_auto_deploy_enabled`, `instant_deploy`, `is_http_basic_auth_enabled` + `http_basic_auth_username` + `http_basic_auth_password`. Svar 201 `{uuid}`, 409 vid domänkonflikt.
- Next.js statisk export: `build_pack: nixpacks`, `is_static: true`, `publish_directory: out` (officiell Coolify-dokumentation för Next.js).
- Byggen listas med `GET /api/v1/deployments/applications/{uuid}?skip=&take=` (bilaga A §4). `Application::deployments()` i Coolifys källkod (gren v4.x) svarar `{count, deployments[]}`, nyast först; formen vid taggen verifieras i spiken (S22). `status` ∈ `queued | in_progress | finished | failed | cancelled-by-user`.
- Radera: `DELETE /api/v1/applications/{uuid}` (asynkront).
- Auto-deploy via Coolifys GitHub App-källa, matchas på repo-id + gren när `is_auto_deploy_enabled` är sant. Ingen webhook per app behövs.
- Token: rättigheter `read` (alla GET: listning, byggen, invariant; bilaga A §7) och `write` (skapa/radera). `deploy` behövs inte längre, eftersom Computercat aldrig startar ett bygge. Bunden till det team som var aktivt när den skapades. Skapas bara i UI.

**SiteOps** (`~/Web/siteops`, läst i koden)
- `POST /api/v1/sites` finns (scope `sites:write`, auth `X-Api-Key` eller JWT via egen `OAuthMiddleware`), men kan inte sätta basic auth, `is_monitored`, välja lösning eller avaktivera. Det byggs ut här (se delprojekt 2a).

**MCP-svarens storlek**
- laravel/mcp 1.0.2 har ingen egen gräns för ett verktygssvar.
- Claude Code (code.claude.com/docs/en/mcp): varning över 10 000 tokens, standardgräns 25 000 tokens (`MAX_MCP_OUTPUT_TOKENS`), och ett svar över 50 000 tecken läggs i en fil om verktyget inte deklarerar `anthropic/maxResultSizeChars`. claude.ai: ingen officiell siffra hittad; en tredjepartskälla anger cirka 150 000 tecken. Byggguidens verktyg håller därför varje svar under 50 000 tecken (se 2c, Byggguiden).

## Förutsättningar (engångs, före bygget av 2c)

Var och en kräver Eriks OK eller görs av Erik:

1. **Kö-worker i prod.** Computercat har idag inga köjobb och troligen ingen worker. En Forge-daemon `php8.4 artisan queue:work --tries=1 --timeout=120` som användaren `computercat` skapas.
2. **Slack-webhook** för larm (`SLACK_ALERT_WEBHOOK_URL`). Ingen Slack-larmning finns i Computercat idag.
3. **GitHub App "ComputerCat Studio"** (Administration: write, Contents: write, Metadata: read), installerad i HumblebragAB på "Only select repositories". Den når automatiskt repon den skapar. Kräver GitHubs UI att minst ett repo väljs, väljer Erik ett tomt privat ankarrepo, aldrig ett befintligt repo med innehåll (uppstår det stannar den som genomför planen och frågar Erik). Ger spiken 403 på `POST /orgs/HumblebragAB/repos` stannar den som genomför planen och frågar Erik om "Repository creation" ska läggas till.
4. **Coolify-token** med `read` + `write` (inte `deploy`, inte `root`, inte `*:sensitive`), skapad med Humblebrags team aktivt.
5. **Coolifys och Claudes GitHub App-installationer** på HumblebragAB måste nå nya repon. Är någon av dem på "Only select repositories" måste den ändras till "All repositories", eftersom vår app inte kan utöka andra appars installationer.
6. **SiteOps-nyckel** med exakt `sites:read` + `demo-sites:write` (inte `sites:write`), skapad med `php artisan api-clients:create Computercat --team=<id> --scope=sites:read --scope=demo-sites:write` (2a) efter att 2a deployats.
7. Nycklarna läggs i Computercats `.env` på Forge.
8. **Claude Code-molnmiljön** tillåter `api.computercat.co`, `*.apps.humblebrag.se` och npm-registret (`registry.npmjs.org`), och Computercat-connectorn är tillagd i claude.ai.

## Ej verifierat, verifieras i en spike innan 2c byggs

1. `git_repository`-formatet för GitHub App-källa (`owner/repo` eller full URL).
2. Att `ports_exposes: "80"` är rätt för statisk nginx-image.
3. Att basic auth satt via API vid skapande ger samma Traefik-labels som UI:t, så att `robots-sync`/`coolify-health` inte larmar.
4. Att build-variabler satta via `POST /applications/{uuid}/envs` med `is_buildtime: true` (finns i controllern, inte i OpenAPI-specen) når `next build`.
5. Att wildcard-DNS för `*.apps.humblebrag.se` pekar på Coolify-servern.
6. Att en molnsession som instrueras att pusha till `main` faktiskt gör det (prefixet `claude/` är ett standardval enligt dokumentationen, inte en spärr).
7. Att en `next build` med statisk export klarar sig med Coolify-serverns minne medan övriga appar kör (mät RAM under bygget).
8. Att Coolifys nixpacks bygger grundkoden med Node 22. Nixpacks standard är Node 18 och Next.js 16 kräver ≥ 20.9; byggguiden sätter `engines.node: "22.x"`, och versionen kan också sättas med `NIXPACKS_NODE_VERSION` (nixpacks.com/docs/providers/node). Kontrolleras i byggloggen.
9. Att Coolify-servern faktiskt har `concurrent_builds = 1` (beslut 4 och Säkerhet bygger på det).
10. Att installationstoken får skapa repo med `POST /orgs/HumblebragAB/repos` (`private: true`, `auto_init: true`) med Administration: write, och att appen därefter når repot.
11. Att Contents-API:t fungerar på ett nytt `auto_init`-repo: `GET README.md` ger `sha`, `PUT` med `sha` skriver över, `PUT` utan `sha` skapar en ny fil (`CLAUDE.md`).
12. Att en Coolify-app som skapas mot ett repo med bara README (ingen byggbar kod, `instant_deploy: false`) inte bygger, och att första pushen av grundkoden startar ett bygge via auto-deploy.
13. Formen på `GET /deployments/applications/{uuid}` vid v4.4.6.

Spiken använder en riktig Demo-app med Coolify-namn `spel-e2e-spike`, som tas bort på sitt uuid efteråt. Faller en punkt: stanna och fråga Erik.

## Uppdelning

| Del | Repo | Innehåll |
|---|---|---|
| 2a | siteops | API: skapa sajt med miljö av typ demo, uppdatera miljö, avaktivera, slå upp på URL |
| 2b | spelmall (lokalt, pushas aldrig) | Byggguidens innehåll: Computercat-klient, exempelspel, konfiguration, tester. Byggt och testat (83 tester); importeras till computercat-api i 2c |
| 2c | computercat-api | Byggguiden (import, verifiering, MCP-verktyg, REST), provisionerings- och arkiveringssagor, MCP-verktyg, Slack-larm, invariant |
| 2d | — | Acceptans: Kattsvep |

Ordning: förutsättningarna, 2a (2b är redan gjord lokalt), deploy av 2a till SiteOps (Eriks OK), spiken, 2c, sist 2d. Spiken kan inte köras förrän 2a är deployad.

---

## 2a. SiteOps API

Nya eller utökade routes under `/api/v1`, samma `OAuthMiddleware` och scope-kontroll som befintliga:

| Route | Scope | Gör |
|---|---|---|
| `POST /sites` (utökas) | `sites:write` eller `demo-sites:write` | Ny valfri `environment`: `{type: "demo", name, url, basic_auth_user, basic_auth_password, is_monitored}`. Skapar bara den miljön, inte den tomma Production-miljön. |
| `GET /sites?url=` | `sites:read` | Slå upp sajt på exakt URL (webbens eller en miljös, avslutande `/` ignoreras), för idempotens. `/sites/search` är delsträngssökning och används inte. |
| `PATCH /sites/{id}/environments/{env}` | `sites:write` eller `demo-sites:write` | Sätter `basic_auth_user`, `basic_auth_password`, `is_monitored`. Bara på webbar som samma API-klient skapade (`sites.api_client_id`), annars 403. |
| `POST /sites/{id}/deactivate` | `sites:write` eller `demo-sites:write` | `active = false`, `monitoring_enabled = false`. Bara på webbar som samma API-klient skapade, annars 403. Ingen radering via API. |

`POST /sites` med en adress som redan finns i teamet ger 409 med `site_id`. `GET /sites/{id}` får `monitoring_enabled` och `GET /sites/{id}/environments` får `type` och `has_basic_auth`, för 2c:s invariant. Det exakta kontraktet låses i planen för 2a.

- `type` måste finnas i `Environment::TYPES`.
- Basic auth lagras krypterat som i UI:t och visas aldrig i API-svar.
- Validering: `url` måste vara `https://`, `basic_auth_*` max 255, båda eller ingen.
- Sajten får som idag en egen lösning (`BelongsToSolution`). Att samla spelen i en gemensam lösning ingår inte.
- Nytt smalt scope `demo-sites:write` (Eriks beslut 2026-10-13). Med bara det kräver `POST /sites` ett `environment` av typ `demo` och avvisar `credentials`, `pejl_customer_id` och `cms_type` (422); `PATCH` av miljö och `deactivate` godtas (ägarskapsregeln oförändrad); alla andra skrivande routes ger 403.
- En avaktiverad API-klients client_credentials-JWT avvisas direkt.
- Ny API-klient för Computercat med `sites:read` + `demo-sites:write`.
- Tester: skapa med demo-miljö, uppslag på URL, uppdatera, avaktivera, scope saknas → 403, lösenord syns aldrig i svar.

## 2b. Byggguidens innehåll (byggt i `~/Web/spelmall`)

Arbetet gjordes lokalt i `~/Web/spelmall` (worktree `feat-spelmall`, 83 tester) innan beslutet om byggguide. Repot pushas aldrig; filerna importeras till computercat-api (2c, Byggguiden) och sanningen flyttar dit.

- Next.js (App Router), React, Tailwind, Zustand, framer-motion, TypeScript. `next.config.ts` med `output: 'export'` och `images.unoptimized`.
- `src/lib/computercat/` — liten klient: anonym inloggning, lagring av token, ny anonym användare vid 401, offline-först (lokalt först, synk i bakgrunden), topplistor, sparningar. Läser `NEXT_PUBLIC_COMPUTERCAT_GAME`; API-adressen `https://api.computercat.co/api/v1` är hårdkodad som standard och kan skrivas över med `NEXT_PUBLIC_COMPUTERCAT_URL`.
- Ett minimalt spelbart exempel (klick-räknare med topplista) som bevisar hela kedjan direkt efter första pushen av grundkoden.
- `CLAUDE.md` för spelets Claude (serveras av byggguiden och skrivs i repot av `create_game`):
  - Steg 1 när `package.json` saknas: anropa `get_build_guide`, skriv filerna exakt, kontrollera `sha256`, kör kontrollerna, pusha till `main`. Sedan byggs spelet.
  - Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.
  - Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg (`get_game`, `upsert_*`). Hämta integrationsmanualen med `get_integration_guide`.
  - Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt. Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
  - Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.
- `README.md`-mall med spelets namn och idén mellan raderna `<!-- computercat:idea:start -->` och `<!-- computercat:idea:end -->` (fylls i av `create_game`, se steget `repo`; kontraktet står i planen för 2c).
- `package.json` med exakt låsta versioner, `engines.node: "22.x"` (nixpacks standard är Node 18, Next.js 16 kräver ≥ 20.9) och den godkända `overrides: {"vite": "7.3.7"}`, plus `package-lock.json`.
- Ingen CI (Actions-budget); bygget verifieras av Coolify och av `computercat:byggguide:verify` lokalt.

## 2c. Provisionering i Computercat

### Konfiguration (Forge `.env`, med Eriks OK)

`GITHUB_APP_CLIENT_ID`, `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH` (fil i storage, 600), `GITHUB_OWNER=HumblebragAB`, `COOLIFY_URL`, `COOLIFY_TOKEN`, `COOLIFY_PROJECT_UUID` (Demo), `COOLIFY_ENVIRONMENT_NAME` (standard `production`), `COOLIFY_SERVER_UUID`, `COOLIFY_GITHUB_APP_UUID`, `SITEOPS_URL`, `SITEOPS_API_KEY`, `DEMO_BASIC_AUTH_USER/PASSWORD` (demo/demo enligt befintlig konvention), `SLACK_ALERT_WEBHOOK_URL`, `PROVISIONING_RESERVED_SUBDOMAINS`. `lcobucci/jwt` krävs uttryckligen i composer (finns idag bara indirekt via Passport). Saknas en inställning svarar `create_game` med vilken, innan något skapas. Byggguiden behöver ingen konfiguration.

### Byggguiden

- **Lagring:** `resources/byggguide/<version>/` i computercat-api: `manifest.json`, `files/` (filerna som skrivs i spelrepot), `templates/README.md` (används bara av `create_game`). Version `v1`, mönster `v1`–`v999`. En publicerad version ändras aldrig; ändringar blir `v2`. Ett test låser varje publicerad versions `manifest.json` med sin `sha256`.
- **Manifestet:** `stack` (exakta versioner, lika med `files/package.json`:s beroenden), `node`, `overrides` (`vite: 7.3.7`), `output` (statisk export, `out/`), `env`, `commands`, `required_checks` (`npm run check`, `npm audit --omit=dev --audit-level=high`, `test -f out/index.html`), `steps` (ordningen för spelets Claude) och `files[]` med `path` i spelrepot, `source`, `sha256` och `bytes`. Dotfiler lagras som `files/dot.gitignore` osv. så att computercat-api:s egen git inte påverkas.
- **Källan** är spelmall-worktreen (2b) vid commit `4a1fecb`, utom `README.md` (blir `templates/README.md`) och `CLAUDE.md` (ny text med steg 1 ovan). Efter importen är computercat-api sanningen.
- **Exponering:**
  - MCP `get_build_guide(version?)` (staff, inget spel): manifestet utan interna fält, varje fil med `delivery` (`mcp` om högst 30 000 byte, annars `download` med en `https://`-`download_url`). Dotfiler levereras alltid via MCP, eftersom Forges nginx nekar sökvägar med `/.`.
  - MCP `get_build_guide_file(path, version?)` (staff): en fil med `path`, `sha256`, `bytes`, `content`. Uppdelningen behövs eftersom alla filer tillsammans är cirka 400 KB (varav `package-lock.json` cirka 300 KB), långt över Claude Codes 50 000 tecken. Filer över 30 000 byte får `content: null` och hämtas via REST.
  - REST `GET /api/v1/build-guide`, `GET /api/v1/build-guide/{version}` (manifestet) och `GET /api/v1/build-guide/{version}/files/{path}` (filen som `text/plain`, `X-Content-SHA256`, `ETag`, `Cache-Control: public, max-age=3600`). **Publik och bara läsning**: innehållet är byggregler och klientkod som ändå hamnar i varje spels publika JavaScript, inga hemligheter. Egen gräns `throttle:build-guide` (30/min per IP) utöver API:ts globala 60/min; ett nytt spelrepo behöver två anrop (manifest och `package-lock.json`). Sökvägen slås bara upp i manifestet, aldrig mot filsystemet direkt. Beslutet: REST behövs för `package-lock.json` och vidgar inte attackytan (inga skrivningar, ingen databas, innehållet är publikt).
- **Verifiering:** `php artisan computercat:byggguide:verify [version]` skriver ut guiden i en tillfällig katalog och kör `npm ci`, `npm run check` (typkontroll, lint, test, bygge, exportkontroll) och `npm audit --omit=dev --audit-level=high`. Bara lokalt och i utveckling; vägrar i produktion och körs aldrig i en request. `computercat:byggguide:hash` skriver om `sha256`/`bytes` i manifestet för en version som inte är publicerad. Ett PHPUnit-test kontrollerar att manifestet är giltigt, att varje fil finns med rätt `sha256` och att inga filer finns utanför manifestet.

### Slug

- Regel `^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){2,39}\z`: giltig som GitHub-reponamn och DNS-etikett, inga dubbla eller avslutande bindestreck.
- Reserverade: `www`, `api`, `admin`, `app`, `apps`, `demo`, `mail`, `spel`, samt varje befintlig underdomän under `apps.humblebrag.se` (lista i config, uppdateras när Coolify-appar läggs till för hand).
- Regeln läggs i en regelklass som `create_game` använder. `GameResource` får regeln bara för nya spel (befintliga `tocco`, `tells`, `glosis` matchar redan, men redigering av ett befintligt spel ska aldrig blockeras).
- IntegrationGuide skickar även spelets och eventens slug genom `code()`.

### Datamodell

**`provisioning_runs`**: `id`, `uuid`, `slug` (unik bland runs som inte är `released`), `user_id`, `game_id` (null tills spelet skapats), `kind` (`provision | archive`), `status` (`running | succeeded | failed | released | archived`), `idea` (text), `github_repo`, `coolify_app_uuid`, `siteops_site_id`, `demo_url`, `monitoring_enabled_at` (när SiteOps-övervakningen av Demo-miljön slogs på, null tills första lyckade deploy), `last_error` (text, max 4 KB, se redigering nedan), timestamps.
**`provisioning_steps`**: `run_id`, `step`, `status` (`pending | done | failed`), `attempts`, `detail` (json), timestamps. Unik på (`run_id`, `step`).

### Provisioneringssagan

Slugen reserveras när run-raden skapas. Spelposten skapas först när repot finns, så att ett tidigt fel aldrig lämnar ett spel som låser slugen.

| Steg | Gör | Idempotens |
|---|---|---|
| `repo` | `POST /orgs/HumblebragAB/repos` med `name: <slug>`, `private: true`, `auto_init: true`, `description: "Computercat-spel <slug> (run <uuid>)"`. Skriver sedan via Contents-API `README.md` (byggguidens README-mall med spelets namn och idén mellan idé-markörerna) och `CLAUDE.md` (byggguidens). 404 på README försöks igen en stund; saknade eller dubblerade markörer i mallen → steget fallerar och skriver ingenting. Byggguidens version sparas i stegets `detail`, så att en omkörning skriver samma version. | Vid omkörning: `GET /repos/HumblebragAB/<slug>`; godta bara om `description` innehåller run-uuid och repot är privat. 422 "name already exists" utan match → run `released` med "slug upptagen på GitHub". Filer med rätt innehåll skrivs inte igen. |
| `game` | Skapa spelet **aktivt** med `is_listed = false`, anroparen som `owner` | slug i `games`, kopplad till runnens `game_id` |
| `coolify_app` | `POST /applications/private-github-app`: namn `spel-<slug>`, domän `https://<slug>.apps.humblebrag.se`, nixpacks + statisk + `out`, basic auth, `is_auto_deploy_enabled: true`, `instant_deploy: false` | sparat uuid; annars `GET /applications` och matcha exakt namn `spel-<slug>` i Demo-projektet. 409 domänkonflikt → `failed`. |
| `coolify_env` | `NEXT_PUBLIC_COMPUTERCAT_GAME=<slug>` som build-variabel | finns redan med samma värde |
| `cors` | Demo-adressen i spelets `allowed_origins` | finns redan |
| `siteops` | Skapa sajt typ `app` med demo-miljö, basic auth, `is_monitored: false` (övervakningen slås på först när Demo lever, se nedan) | sparat id; annars uppslag på exakt URL eller 409 från `POST /sites`. En befintlig webb adopteras bara om adressen är Demo-URL:en, den är aktiv, har en demo-miljö med adressen och `PATCH` av miljön lyckas (alltså att Computercats klient skapade den); annars `failed` |

Efter `siteops` är runnen `succeeded`: repot är **redo för kod**. Det finns inget deploy-steg; repot har ingen byggbar kod förrän spelets Claude pushar grundkoden, och då bygger Coolify av sig själv.

**Övervakning först när Demo lever** (Eriks beslut 2026-10-13, inga falsklarm): Demo-miljön skapas med `is_monitored: false`. Första gången Computercat ser att senaste bygget är `finished` och Demo-URL svarar 200 med basic auth (i `get_provisioning_status` och i den nattliga invarianten, inte med `--dry-run`) skickas en gång `PATCH /sites/{id}/environments/{env}` med `is_monitored: true`, och tiden sparas i `monitoring_enabled_at`.

**`is_listed`** är en ny kolumn på `games` (default `true`, så befintliga spel påverkas inte). `GET /api/v1/games` visar bara listade spel. Demo-spel är aktiva så att spelar-API:t fungerar, men syns inte i den publika listan förrän Erik listar dem vid Live.

**Arkiveringssagan** (`kind: archive`), samma motor, stegen: `coolify_delete` (bara det sparade uuid:t), `siteops_deactivate`, `repo_archive` (`PATCH archived: true`), `game_deactivate` (`is_active = false`). Ingen HTTP-anrop sker inne i en databastransaktion.

**Fel:** steget `failed`, `last_error` sparas, Slack-larm, sagan stannar. `retry_provisioning` fortsätter från första ej klara steg. 4xx/5xx från GitHub, Coolify och SiteOps loggas med metod, URL, status och body, max 4 KB, där `Authorization`, `private_key`, `password`, `token`, `value` och Coolifys `logs` tas bort. Samma redigering gäller `last_error`, som visas för spelets medlemmar.

**Klienternas egna spärrar** (testade på samma sätt som GitHub-spärren):
- GitHub-klienten har ingen metod för `DELETE`; ett test i hela sviten (Http::fake) säkerställer att inget `DELETE` mot `api.github.com` skickas.
- Coolify-klienten tar bara emot uuid:n som finns i `provisioning_runs`, och kontrollerar `project_uuid == COOLIFY_PROJECT_UUID` innan `DELETE` eller `PATCH`. Den har ingen metod som startar ett bygge.

### Slack-larm

En liten `SlackAlert`-klass (POST till webhooken, timeout 5 s, fel loggas men stoppar aldrig anroparen) med dedupe per nyckel och dygn via cache. Används av sagan, `request_live` och invarianten.

### Behörighet

- `GameAccess::isOwner(User, Game)`: ägare enligt pivot, eller plattformsadmin.
- En gemensam bas för verktyg utan spel (`create_game`, `list_games`, `get_build_guide`, `get_build_guide_file`) med samma revisionslogg och felhantering som `GameTool`; `ListGamesTool` flyttas dit.

### MCP-verktyg

| Verktyg | Vem | Gör |
|---|---|---|
| `create_game(slug, name, idea)` | staff | Kontrollerar inställningar, slugregel och reservationer, tak och att slugen är ledig i Computercat och bland runs. Startar sagan och svarar direkt med run-id. |
| `get_build_guide(version?)` | staff | Byggguidens manifest och fillista (se Byggguiden) |
| `get_build_guide_file(path, version?)` | staff | En fil ur byggguiden med `sha256` |
| `get_provisioning_status(game)` | medlem | Steg och status, repo-URL, Demo-URL, senaste fel (redigerat), och för en lyckad run även `demo`: `awaiting_code` (inget bygge än), `building`, `live` (senaste bygget klart och Demo svarar 200), `deployed_not_answering`, `deploy_failed` eller `unknown` (Coolify gick inte att läsa; felet visas), med senaste byggets status, Demo-adressens HTTP-status och `monitoring` (`pending`/`enabled`). Slår på SiteOps-övervakningen första gången Demo lever |
| `retry_provisioning(game)` | ägare | Fortsätter från första ej klara steg |
| `request_live(game, domain?)` | ägare | Slack-larm till Erik med spel, Demo-URL och önskad domän. Ändrar inget. |
| `archive_game(game)` | ägare, bara spel med en lyckad provisioneringsrun och bara i Demo | Startar arkiveringssagan |

**Tak:** 15 gäller runs med status `running`, `succeeded` eller `failed` (alltså spel som finns eller kan finnas på Coolify). 5 per dygn och användare räknar alla `create_game`-anrop som skapade en run.

### Invariant (nattlig, `computercat:check-provisioning`)

För varje run med status `succeeded`: repot finns och är inte arkiverat, Coolify-appen `spel-<slug>` finns i Demo-projektet och har basic auth, SiteOps-sajten är aktiv med `monitoring_enabled` och demo-miljön har basic auth. Utan `monitoring_enabled_at` (ingen lyckad deploy sedd): lever Demo nu slås övervakningen på; annars ingen avvikelse om runnen är yngre än 7 dagar, och avvikelsen "ingen lyckad deploy på 7 dagar" efter det. Med `monitoring_enabled_at`: demo-miljön är övervakad, senaste bygget är inte `failed`/`cancelled-by-user`, och Demo-URL svarar 200 med basic auth. Omvänt: varje Coolify-app i Demo-projektet med namn `spel-*` har en run. Avvikelse → Slack-larm (dedupe per avvikelse och dygn). `--dry-run` finns.

## Säkerhet

- **Nycklar** finns bara i `.env`/storage på Forge, aldrig i verktygssvar eller loggar. De passerar aldrig verktygsargument, så revisionsloggen (som kortar strängar till 200 tecken men inte redigerar) får dem aldrig.
- **GitHub App** på "Only select repositories"; inget `DELETE` i kod, vaktat av test.
- **Coolify-token** `read` + `write`, inte `deploy` eller `root`. Den når hela teamet, därför klientens spärr på sparade uuid:n och Demo-projektet.
- **SiteOps-nyckel** bara `sites:read` + `demo-sites:write`: den kan bara skapa Demo-webbar (utan inloggningsuppgifter, Pejl-koppling eller CMS-typ), ändra och avaktivera webbar den själv skapat, och inget annat. En avaktiverad klients JWT avvisas direkt.
- **Slug** valideras strikt innan den hamnar i URL:er, reponamn eller domäner.
- **Byggguiden** är publik och bara läsbar. Filsökvägar slås upp i manifestet, aldrig direkt mot disk; varje fil kontrolleras mot sin `sha256` innan den skickas, och en avvikelse ger fel i stället för innehåll. Verifieringskommandot kör `npm` bara lokalt och vägrar i produktion. Spelets Claude kontrollerar varje fil mot `sha256` efter skrivning.
- **Kapat claude.ai-konto (staff):** högst 5 nya Demo-spel per dygn och 15 totalt, arkivering bara av egna Demo-spel, inget i Live, inga raderingar av repon eller data.
- **Coolify-servern** skyddas av taket och av att byggen köas ett i taget. Spiken mäter minnet under ett bygge.
- **Basic auth på Demo** är en konvention (demo/demo), inte en hemlighet.

## Acceptans: Kattsvep (2d)

Körning:
1. Routine A (Eriks konto, connectorn påslagen, inget repo): "Skapa ett nytt spel i Computercat: Kattsvep (slug `kattsvep`), ett minröj där katter letar bajs, med daglig topplista för snabbaste rensning. Följ upp med get_provisioning_status tills provisioneringen är klar och repot är redo för kod."
2. När sagan är klar kopplas `HumblebragAB/kattsvep` till routine B (Claude gör det i Eriks session om routine-API:t tillåter det, annars är det ett klick för Erik i routinens inställningar): "Följ CLAUDE.md: hämta byggguiden med get_build_guide, skriv grundkoden, kör kontrollerna och pusha till main. Bygg sedan spelet enligt README, konfigurera topplistan via Computercat, pusha till main och verifiera på Demo-adressen."
3. Verifiering (av Claude i Eriks session):
   - Demo-URL svarar 200 med basic auth och spelet laddar.
   - Grundkodens filer i `HumblebragAB/kattsvep` har samma `sha256` som byggguiden v1 vid commiten "Grundkod från byggguide v1".
   - En anonym spelare kan skicka en tid via API:t från Demo-origin (CORS-preflight OK) och den syns i topplistan.
   - SiteOps-sajten är aktiv och miljön övervakas (påslagen av Computercat efter första lyckade deploy, `monitoring_enabled_at` satt).
   - `computercat:check-provisioning` är grön.
   - Revisionsloggen visar routinernas anrop, inklusive `get_build_guide`.
4. Erik väljer om Kattsvep lever vidare eller arkiveras med `archive_game`.

## Ingår inte

- Live-gång, egna domäner, App Store/Capacitor.
- Dokument-samlingar och AI-mallar (delprojekt 3).
- Automatisk radering av något.
- Uppgradering av ett befintligt spel till en nyare byggguide (görs för hand när `v2` finns).

## Bilagor

- A: `bilagor/2026-10-13-coolify-api.md`
- B: `bilagor/2026-10-13-github-app.md` (§1 mallvägen är ersatt av §6 organisationsrepo)
