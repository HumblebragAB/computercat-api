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
- Token: rättigheter `write` (skapa/radera) och `deploy`. Bunden till det team som var aktivt när den skapades. Skapas bara i UI.

**SiteOps** (`~/Web/siteops`, läst i koden)
- `POST /api/v1/sites` finns (scope `sites:write`, auth `X-Api-Key` eller JWT via egen `OAuthMiddleware`), men kan inte sätta basic auth, `is_monitored`, välja lösning eller avaktivera. Det byggs ut här (se delprojekt 2a).

## Ej verifierat, verifieras i en spike innan resten byggs

1. `git_repository`-formatet för GitHub App-källa (`owner/repo` eller full URL).
2. Att `ports_exposes: "80"` är rätt för statisk nginx-image.
3. Att Coolifys GitHub App-installation på HumblebragAB når **nya** repon (All repositories) eller måste utökas.
4. Att Claude GitHub App-installationen når nya repon, så att en molnsession kan öppnas på dem.
5. Att basic auth satt via API ger samma Traefik-labels som UI:t (minnesanteckningen säger att labels måste regenereras efter ändring via tinker; skapande via API kan bete sig annorlunda). Kontrolleras mot `robots-sync`/`coolify-health`, som larmar om en Demo-app saknar basic auth.

Spiken körs mot en riktig Demo-app med slug `e2e-spike`, som tas bort på sitt uuid efteråt. Faller en punkt: stanna och fråga Erik.

## Uppdelning

| Del | Repo | Innehåll |
|---|---|---|
| 2a | siteops | API: sätt basic auth och `is_monitored` på en miljö, avaktivera en sajt |
| 2b | spelmall (nytt) | Mallrepot med exempelspel, Computercat-klient, CLAUDE.md |
| 2c | computercat-api | Provisioneringssagan och MCP-verktygen, invariant, acceptans |

Ordning: 2a och 2b först (oberoende), sedan spiken, sedan 2c, sist acceptansen.

---

## 2a. SiteOps API

Nya routes under `/api/v1`, samma `OAuthMiddleware` och scope-kontroll som befintliga:

| Route | Scope | Gör |
|---|---|---|
| `POST /sites` (befintlig, utökas) | `sites:write` | Ny valfri `environment`: `{name, url, basic_auth_user, basic_auth_password, is_monitored}`. Skapar miljön även utan credentials. |
| `PATCH /sites/{id}/environments/{env}` | `sites:write` | Sätter `basic_auth_user`, `basic_auth_password`, `is_monitored` |
| `POST /sites/{id}/deactivate` | `sites:write` | `active = false`, `monitoring_enabled = false`. Ingen radering via API. |

- Basic auth lagras krypterat som i UI:t (`Environment` casts), och visas aldrig i API-svar.
- Validering: `url` måste vara `https://`, `basic_auth_*` max 255, båda eller ingen.
- Ny API-klient för Computercat med bara `sites:write`. Nyckeln skapas och lämnas till Computercats `.env` med Eriks OK.
- Tester: skapa med miljö, uppdatera, avaktivera, scope saknas → 403, lösenord syns aldrig i svar.

## 2b. Spelmallen `HumblebragAB/spelmall`

- Next.js (App Router), React, Tailwind, Zustand, framer-motion, TypeScript. `next.config.ts` med `output: 'export'` och `images.unoptimized`.
- `lib/computercat/` — liten klient: anonym inloggning, lagring av token, förnyelse vid 401, offline-först (lokalt först, synk i bakgrunden), topplistor, sparningar. Läser `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL` (build-variabler).
- Ett minimalt spelbart exempel (klick-räknare med topplista) som visar mönstret, så att mallen bevisar hela kedjan direkt efter första deploy.
- `CLAUDE.md` för spelets Claude:
  - Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.
  - Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg (`get_game`, `upsert_*`). Hämta integrationsmanualen med `get_integration_guide`.
  - Push till `main` deployar Demo automatiskt. Kontrollera Demo-adressen efter deploy.
  - Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.
- `README.md` med samma sak för människor.
- Markeras som mallrepo (`is_template: true`).
- CI: ingen (Actions-budget). Bygget verifieras av Coolify vid deploy.

## 2c. Provisionering i Computercat

### Konfiguration (Forge `.env`, med Eriks OK)

`GITHUB_APP_CLIENT_ID`, `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH` (fil i storage, 600), `GITHUB_TEMPLATE_REPO=HumblebragAB/spelmall`, `COOLIFY_URL`, `COOLIFY_TOKEN`, `COOLIFY_PROJECT_UUID` (Demo), `COOLIFY_SERVER_UUID`, `COOLIFY_GITHUB_APP_UUID`, `SITEOPS_URL`, `SITEOPS_API_KEY`, `DEMO_BASIC_AUTH_USER/PASSWORD` (demo/demo enligt befintlig konvention). Saknas något svarar `create_game` med ett tydligt fel om vilken inställning som saknas; inget halvt skapas.

### Datamodell

**`provisioning_runs`**: `id`, `game_id`, `user_id`, `status` (`running | succeeded | failed | archived`), `github_repo` (full_name), `coolify_app_uuid`, `siteops_site_id`, `demo_url`, `last_error` (text, trunkerad 4 KB, utan hemligheter), timestamps.
**`provisioning_steps`**: `run_id`, `step`, `status` (`pending | done | failed`), `attempts`, `detail` (json), timestamps. Unik på (`run_id`, `step`).

### Sagans steg

| Steg | Idempotens (finns resursen redan?) |
|---|---|
| `game` — skapa inaktivt spel, anroparen blir `owner` | slug i `games` |
| `repo` — generera `HumblebragAB/<slug>` från mallen, `private: true` | `GET /repos/HumblebragAB/<slug>`; finns det men skapades inte av denna run → fel, aldrig återanvänd |
| `coolify_app` — skapa app i Demo enligt "Externa fakta", domän `https://<slug>.apps.humblebrag.se`, basic auth, build-variablerna ovan, `is_auto_deploy_enabled: true` | sparat uuid; vid 409 domänkonflikt → fel |
| `cors` — lägg till Demo-adressen i spelets `allowed_origins` | finns redan i listan |
| `siteops` — skapa sajt typ `app` med miljön Demo, basic auth, `is_monitored: true` | sparat id |
| `deploy` — trigga deploy, polla till `finished` (max 15 min), sedan `GET` Demo-URL med basic auth → 200 | sparat deployment-uuid |

- Körs i en kö-jobbkedja (ett jobb per steg) så att MCP-anropet svarar direkt. Kön finns redan på servern (`$RESTART_QUEUES()` i deploy-skriptet); verifieras i spiken att en worker körs.
- Ett misslyckat steg: status `failed`, `last_error` sparas, Slack-larm (samma webhook som Glosis-larmen), sagan stannar. `retry_provisioning(game)` kör om från första ej klara steget.
- 4xx/5xx från GitHub, Coolify och SiteOps loggas med metod, URL, status och body, trunkerat till 4 KB, med `Authorization`, `private_key`, `password` och `token` borttagna.

### MCP-verktyg

| Verktyg | Vem | Gör |
|---|---|---|
| `create_game(slug, name, idea)` | staff | Kontrollerar tak (15 Demo-spel, 5/dygn/användare), slugformat, att slug är ledig i Computercat, GitHub och Coolify. Startar sagan. Svarar med run-id och vad som händer härnäst. |
| `get_provisioning_status(game)` | medlem | Steg och status, repo-URL, Demo-URL, senaste fel |
| `retry_provisioning(game)` | ägare | Kör om från första ej klara steg |
| `request_live(game, domain?)` | ägare | Slack-larm till Erik med spel, Demo-URL och önskad domän. Ändrar inget. |
| `archive_game(game)` | ägare, bara spel med en run från `create_game` och bara i Demo | Tar bort Coolify-appen, avaktiverar SiteOps-sajten, arkiverar repot (`PATCH archived: true`), avaktiverar spelet, run → `archived`. Inga raderingar i GitHub eller Computercat. |

Alla ärver `GameTool` (åtkomst, transaktion, revisionslogg) utom `create_game`, som ärver basen för verktyg utan spel och loggar sig själv. Verktygsnamnsinvarianten från delprojekt 1 (inga `delete`-namn) gäller fortfarande; `archive_game` matchar inte mönstret.

### Gameslug

Delprojekt 1 lämnade spelets slug utan formatregel och med råa slug-interpolationer i IntegrationGuide. Här: `^[a-z][a-z0-9-]{2,39}\z` (även giltigt som GitHub-reponamn och DNS-etikett), regeln läggs i en regelklass som både `GameResource` och `create_game` använder, och IntegrationGuide skickar även spelets slug genom `code()`.

### Invariant (nattlig, `computercat:check-provisioning`)

För varje run som inte är `archived`: repot finns, Coolify-appen finns och har basic auth, SiteOps-sajten är aktiv och övervakad, Demo-URL svarar. Omvänt: varje Coolify-app i Demo-projektet vars namn börjar med ett spelslug från Computercat har en run. Avvikelse → Slack-larm, ett per avvikelse och dygn. Kör `--dry-run` lokalt.

## Säkerhet

- **Nycklar** endast i `.env`/storage på Forge, aldrig i svar, loggar eller revisionslogg (summeringen redaktar redan långa strängar; nycklar passerar aldrig verktygsargument).
- **GitHub App** på "Only select repositories". Kod och test garanterar att inget `DELETE` skickas till GitHub (Http::fake med `assertNotSent` på alla tester i sviten).
- **Coolify-token** `write` + `deploy`, inte `root`, i rätt team.
- **SiteOps-nyckel** bara `sites:write`.
- **Kapat claude.ai-konto (staff):** högst 5 nya Demo-spel per dygn, arkivering bara av egna Demo-spel, inget i Live, inga raderingar av repon eller data.
- **Coolify-servern** skyddas av taket 15 och av att byggen köas ett i taget.
- **Basic auth på Demo** är en konvention (demo/demo), inte en hemlighet; den hindrar indexering och tillfälliga besökare.

## Acceptans: Kattsvep

Förutsättningar (engångs, Erik gör eller godkänner var och en):
1. GitHub App "Humblebrag Spelstudio" skapas och installeras på `spelmall`.
2. Coolify-token med `write` + `deploy` i rätt team.
3. Nycklarna läggs i Computercats `.env` på Forge.
4. Computercat-connectorn läggs till i claude.ai (Eriks konto; organisationen för Mattias).
5. Claude Code-molnmiljön tillåter `api.computercat.co` och `*.apps.humblebrag.se` i nätverket.

Körning:
1. Routine A (Eriks konto, connectorn påslagen, inget repo): "Skapa ett nytt spel i Computercat: Kattsvep (slug `kattsvep`), ett minröj där katter letar bajs, med daglig topplista för snabbaste rensning. Följ upp tills Demo-adressen svarar."
2. När sagan är klar kopplas `HumblebragAB/kattsvep` till routine B: "Bygg spelet enligt idén i README och CLAUDE.md, konfigurera topplistan via Computercat, pusha till main och verifiera på Demo-adressen."
3. Verifiering (av Claude i Eriks session, inte av routinen):
   - Demo-URL svarar 200 med basic auth och spelet laddar (HTML innehåller spelets rot).
   - En anonym spelare kan skicka en tid via API:t från Demo-origin (CORS-preflight OK) och den syns i `get_game`/topplistan.
   - SiteOps-sajten finns, är aktiv och miljön övervakas.
   - `computercat:check-provisioning` är grön.
   - Revisionsloggen visar anropen från routinen.
4. Erik väljer om Kattsvep lever vidare eller arkiveras med `archive_game`.

## Ingår inte

- Live-gång, egna domäner, App Store/Capacitor.
- Dokument-samlingar och AI-mallar (delprojekt 3).
- Automatisk radering av något.

## Bilagor

- A: `bilagor/2026-10-13-coolify-api.md`
- B: `bilagor/2026-10-13-github-app.md`
