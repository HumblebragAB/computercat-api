# Coolify v4 REST API: static Next.js app from private GitHub repo

Source: tag **v4.4.6** (latest release, 2026-10-09). `S` = `openapi.yaml@v4.4.6`, `C` = `app/Http/Controllers/Api/ApplicationsController.php@v4.4.6`, `R` = `routes/api.php@v4.4.6`. Base URL `https://<host>/api/v1`, header `Authorization: Bearer <token>`.

## 1. Create an app
**POST `/applications/private-github-app`** (S:698, R:244, ability `write`).
Required per spec (S:711): `project_uuid`, `server_uuid`, `environment_name`, `environment_uuid`, `github_app_uuid`, `git_repository`, `git_branch`, `build_pack`. The description says you need only one of environment_name/uuid. All are strings.
Optional fields used here: `ports_exposes` (string, S:742), `name`, `domains` (S:758), `is_static` (bool, S:774), `is_spa`, `is_auto_deploy_enabled` (S:780), `static_image` (enum `nginx:alpine`, S:789), `install_command` (S:793), `build_command` (S:796), `base_directory` (S:805), `publish_directory` (S:808), `custom_labels` (S:872), `instant_deploy` (bool, S:907), `is_http_basic_auth_enabled`/`http_basic_auth_username`/`http_basic_auth_password` (S:1024), `destination_uuid`, `autogenerate_domain` (default true), `force_domain_override`.
Responses: 201 `{uuid}`, 409 domain conflict `{message, warning, conflicts[]}`, 400, 401.
Public-repo contrast: **POST `/applications/public`** (S:316). Same body, but no `github_app_uuid`.
`git_repository` format for GH App: VERIFIERAT 2026-10-14 (spike S1): `owner/repo` (`HumblebragAB/e2e-spike`) ger 201 och klonen i bygget lyckas.

## 2. Build pack for `next build` + `output: 'export'`
Enum: `nixpacks, railpack, static, dockerfile, dockercompose` (S, private-github-app body).
- `static` runs **no build**. It copies the repo into nginx as is (`deploy_static_buildpack` and `build_static_image`, `app/Jobs/ApplicationDeploymentJob.php@v4.4.6`:1214-1237, 4295-4305). It only fits pre-built output.
- **Use `build_pack: "nixpacks"` + `is_static: true` + `publish_directory: "/out"`** (VERIFIERAT 2026-10-14: `"out"` ger 422 "The publish directory field format is invalid."; regeln är `ValidationPatterns::DIRECTORY_PATH_PATTERN = /^\/([a-zA-Z0-9._\-\/~@+]*)?$/`, så inledande `/` krävs). Nixpacks builds, then `COPY --from=<build> /app/out` into `static_image` nginx (Job.php:4395-4505). Matches the official docs: "Build strategy nixpacks, Site type Static, Output Directory `out`" (coollabsio/coolify-docs `content/docs/applications/framework-examples/javascript/nextjs.mdx`:18-22). `ports_exposes` "80" for nginx: VERIFIERAT 2026-10-14 (spike S2): sidan svarar 401 utan och 200 med basic auth.
- `railpack` + `is_static` also exists (Job.php:3716-3723).

## 3. Domains, env, basic auth, labels
- `domains`: comma-separated URL string (S:758). PATCH `/applications/{uuid}` also accepts it (S:2256).
- Env: `POST /applications/{uuid}/envs`, `PATCH /applications/{uuid}/envs` (update; `key`+`value` required), `PATCH /applications/{uuid}/envs/bulk` (`{data:[...]}`), `DELETE /applications/{uuid}/envs/{env_uuid}` (S:2699-2935). Spec fields: `key`, `value`, `is_preview`, `is_literal`, `is_multiline`, `is_shown_once`. **Spec gap:** the controller also accepts `is_runtime`, `is_buildtime` (bool, default true) and `comment` (C:4219, 4246-4247, 4282). They are missing from the request schema but present in the `EnvironmentVariable` response schema (S:15326-15328).
- Basic auth: `is_http_basic_auth_enabled`, `http_basic_auth_username`, `http_basic_auth_password` on every create endpoint (S:1024; C:1251, 1261). They appeared in openapi.yaml at **v4.0.0-beta.411**; beta.410 lacks them. PATCH accepts them in code (C:3253, 3265-3268, 3350-3357). They are **missing from the PATCH spec**. Enabling requires both username and password.
- `custom_labels`: string that **must be base64-encoded**, otherwise 422 (C:4913-4932). `is_container_label_escape_enabled` (default true) escapes `$`.

## 4. Deploy and poll
> 2026-10-13: Computercat startar inga byggen längre (byggguide i stället för mall; första bygget kommer från auto-deploy vid push). Bara listningen `GET /deployments/applications/{uuid}` används, och token behöver inte `deploy`. Coolifys källkod (gren v4.x, `Application::deployments()`) svarar `{count, deployments}` sorterat nyast först; formen vid v4.4.6 verifieras i spiken (S22).
- `POST /deploy?uuid=<app>[&force=true]` (S:6778, R:153, ability `deploy`). Returns `{deployments:[{message, resource_uuid, deployment_uuid}]}`. **Note:** `GET /deploy` now returns post_required (R:152).
- Alternative: `POST /applications/{uuid}/start?force=&instant_deploy=` → `{message, deployment_uuid}` (S:2935, R:290).
- Poll: `GET /deployments/{deployment_uuid}` → `ApplicationDeploymentQueue` with `status` and `logs` (S:6698). `GET /deployments/applications/{uuid}?skip=&take=` (S:6840). `POST /deployments/{uuid}/cancel`.
- Status values: `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user` (`app/Enums/ApplicationDeploymentStatus.php`).

## 5. Delete
`DELETE /applications/{uuid}` (S:2137, R:251, `write`). Query bools, all default true: `delete_configurations`, `delete_volumes`, `docker_cleanup`, `delete_connected_networks`. The work is asynchronous (`DeleteResourceJob`): 200 `{message:"Application deletion request queued."}` (C:2986-3022).

## 6. Auto-deploy
Field `is_auto_deploy_enabled` (bool, default true; S:780). GitHub App webhooks hit `POST /webhooks/source/github/events` (`routes/webhooks.php`:16). The app is resolved via the `X-GitHub-Hook-Installation-Target-Id` header plus its secret. Matching applications have `repository_project_id` = repo id, `source_id` = the app and `git_branch` = the pushed branch. A deploy runs only if `isDeployable()` is true, meaning `is_auto_deploy_enabled` (`Webhook/Github.php`:389-520; `Models/Application.php`:1335). `watch_paths` filters by path. `[skip ci]`/`[skip cd]` skips the deploy. No per-app webhook setup is needed with a GitHub App.

## 7. Tokens
Abilities: `read`, `read:sensitive`, `write`, `write:sensitive`, `deploy`, `root` (`app/Livewire/Security/ApiTokens.php`:129). `root` bypasses all checks (`app/Http/Middleware/ApiAbility.php`). Create and delete need `write`, deploy and cancel need `deploy`, GETs need `read`. Expiry options: 7, 30, 60, 90 or 365 days, or none. **Team scoping:** `team_id` is set to the session's current team at creation (`app/Models/User.php`:278), and `api.token.team` enforces it. To restrict a token to one team, switch to that team in the UI before creating it. Non-admin members are limited to read (ApiAbility). UI quirk: toggling `deploy` resets the selection to `['deploy']` only (ApiTokens.php:110). You need write+deploy for create+deploy, so add `write` after `deploy`. A token can only be created in the UI. An API endpoint for it is **UNCONFIRMED**.

## Spikresultat (2026-10-14, Coolify v4.4.6)

Körd från Forge-servern med prodtoken (read+write, utan deploy). Engångsapp `spel-e2e-spike`, raderad efteråt på sitt uuid.

- S1: `git_repository: "owner/repo"` fungerar med GitHub App-källa (201, klon lyckas).
- S1b: `publish_directory` måste börja med `/` (`"/out"`); `"out"` ger 422. Alla andra fält i CoolifyClient-bodyn godtogs.
- S2: `ports_exposes: "80"` + `static_image: nginx:alpine` serverar exporten (401 utan auth, 200 med `demo:demo`).
- S3: Traefik-labels från API-skapad basic auth är identiska med en UI-konfigurerad app (Footr): `http-basic-auth-<uuid>`-middleware på https-routern. `coolify-robots-sync` lade till värden (noindex, `X-Robots-Tag: noindex, nofollow, noarchive`), `coolify-health` förväntar 401 för appar med basic auth. Inga larm.
- S4: build-variabel med `is_buildtime: true` hamnar i den byggda JS:en.
- S5: wildcard `*.apps.humblebrag.se` → 46.246.30.194 (Coolify-servern).
- S7: bygget tog cirka 4 min; RAM-topp 2043 MB använt av 3936, minst 1893 MB tillgängligt, swap 490→530 MB. 1 vCPU.
- S13: `GET /projects/{uuid}/{env}` ger objekt med `applications[]` (`uuid`, `name`). Appen syns direkt efter skapande.
- S14: `GET /applications/{uuid}` ger `is_http_basic_auth_enabled: true`; `http_basic_auth_password` saknas helt med denna token. `is_static` på toppnivå är `null`, värdet ligger i `settings.is_static` (true). Projekt syns bara som `environment_id`.
- S15: `GET /applications/{uuid}/envs` ger en lista med `key`, `uuid`, `is_buildtime`, `is_runtime`, `is_preview`; `value` är `null` (dold). Varje variabel finns två gånger: en med `is_preview: false` och en med `is_preview: true`. Coolify lägger själv till `NIXPACKS_NODE_VERSION` vid skapande. `POST` ger 201 `{uuid}`, `PATCH /envs` med samma body ger 201 med hela posten.
- S16: read+write räcker för alla anrop ovan (skapa, läsa, envs, PATCH, DELETE).
- S17: `DELETE /applications/{uuid}` ger 200 `{"message":"Application deletion request queued."}`; appen var borta ur projektlistan inom 30 s och domänen svarar 503.
- S18: Coolifys GitHub App når det nya repot (klon i bygget lyckas).
- S20: Coolify sätter `NIXPACKS_NODE_VERSION=22` automatiskt (syns i `nixpacks plan --env 'NIXPACKS_NODE_VERSION=22'`); Next.js 16.4 byggde utan fel. Ingen egen variabel behövs.
- S20b: `install_command: "npm ci --ignore-scripts"` (satt via `PATCH /applications/{uuid}`) skickas som `--install-cmd 'npm ci --ignore-scripts'` till nixpacks; bygget lyckas.
- S21: servern `localhost` har `concurrent_builds = 1` (läst i `server_settings`, bara läsning).
- S22: `GET /deployments/applications/{uuid}?skip=0&take=5` ger `{"count":N,"deployments":[…]}` nyast först, med `deployment_uuid`, `status` (`in_progress`, `finished`), `created_at`, `commit`. `GET /deployments/{uuid}` innehåller inget `logs`-fält i v4.4.6; bygglogg läses i `application_deployment_queues.logs`.
- S23: `instant_deploy: false` mot repo med bara README startar inget bygge (`count: 0` efter 2+ min).
- S24: första pushen till `main` startade ett bygge inom 1 s via auto-deploy, utan `POST /deploy`. En push från en molnsession (S6) startade nästa bygge på samma sätt.
- S25 (acceptans Kattsvep 2026-10-14): webhook-race. `repo`-steget skrev CLAUDE.md 08:43:08 och appen skapades 08:43:10; GitHubs push-webhook kom fram efter appen och auto-deploy byggde repot utan kod (`is_webhook = t`, status `failed`). Rättat: `repo`-stegets commits har `[skip ci]`, och Coolify hoppar över en push där varje commit har `[skip ci]` eller `[skip cd]` (`DetectsSkipDeployCommits::shouldSkipDeploy`, v4.4.6).

## Spikresultat etapp 1a (2026-10-14, Coolify v4.4.6)

Läsning från Forge-servern med prodtoken (read+write) och SELECT i Coolifys databas. Inget startades.

- S26: `commit` i `GET /deployments/applications/{uuid}` är ett fullständigt 40-tecken-SHA i gemener för alla 38 lyckade (`finished`) byggen i databasen. Avvikelser finns bara bland misslyckade byggen: ett med `HEAD` (2026-10-06) och ett med en kort hash på 7 tecken (2026-10-12).
- S27: `take=20`, `take=100` och `take=1000` ger alla 200 med `count: 3` och 3 poster för Kattsvep (appen har bara 3 byggen). Någon övre gräns för `take` syntes inte; med `take=20` får man alltså alla byggen upp till 20.
- S28: fälten i en post är: id, application_id, deployment_uuid, pull_request_id, force_rebuild, commit, status, is_webhook, created_at, updated_at, current_process_id, restart_only, git_type, server_id, application_name, server_name, deployment_url, destination_id, only_this_server, rollback, commit_message, is_api, build_server_id, horizon_job_id, horizon_job_worker, finished_at, docker_registry_image_tag, configuration_hash, parent_deployment_uuid (inget `logs`). Status som förekommer: `finished`, `failed` (i API-listan och i databasen).
- S29: äldsta bygget i databasen är från 2026-09-04 (40 dagar), totalt 47 rader för alla appar; ingen gallring syns.

## Spikresultat delprojekt 3 etapp B (Coolify v4.4.6)

Spike A (2026-10-14): engångsappen `ccspike-v2a` (raderad på sitt uuid) och engångsrepot HumblebragAB/ccspike-v2a (arkiverat), v1-grundkoden plus ett ställföreträdande skript under `.ccspike/`. S-numren är faktabank-specens (S1–S7) och planens (S8–S13).

- S10: `build_command` valideras mot `ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN`: bara blanksteg, `&&`, `||`, citerade strängar utan `$`, backtick och backslash, och tecknen `a-zA-Z0-9._-/=:@,+[]{}#%^~*?!`. Ingen `|`, `;`, `>`, `<` eller `$`. Valideringen tillåter 1000 tecken, men kolumnen `applications.build_command` är `varchar(255)`: ett längre kommando ger 500 (`SQLSTATE[22001] value too long`, Coolifys laravel.log) och ingen app skapas. Ett kommando på 247 tecken gav 201 och sparades oförändrat; nixpacks får det som `--build-cmd '<kommando>'` och kör det i `/bin/bash -ol pipefail -c` (Dockerfile `RUN`). `install_command` körs fortfarande (`npm ci --ignore-scripts`).
- S2: byggsteget når `api.computercat.co` (v1-filen hämtades) och `registry.npmjs.org`; `curl` finns; arbetskatalogen är `/app`; `NODE_ENV=production`; `NEXT_PUBLIC_COMPUTERCAT_GAME` är osatt när inget satts som build-variabel.
- S8: npm 10.9.2, Node v22.14.0. `npm audit --omit=dev --audit-level=high --json` ger nycklarna `auditReportVersion,metadata,vulnerabilities`, men räknar ändå med utvecklingsberoenden (5 high: eslint-config-next → @next/eslint-plugin-next → fast-glob → micromatch → braces), eftersom nixpacks sätter `NPM_CONFIG_PRODUCTION=false`. Med den variabeln borttagen ur miljön ger samma kommando status 0 och 0 fynd. `NODE_ENV=development` ändrar inget.
- S4a: ett steg i `build_command` som avslutar med fel ger status `failed`; Demo fortsätter servera förra bygget (`/spike-version.txt` gav `1` efter det fällda bygget med `2`).
- S9: `server_settings.dynamic_timeout = 3600` (sekunder) för servern `localhost`; ingen timeout per app.
- S13: byggkatalogen `/app` har ingen `.git/config` (Dockerfile `COPY . /app/.` utan `.git`); Coolifys GitHub-token kan inte läsas där.
- Loggar: för lyckade byggen är byggstegets utdata dolda (`hidden: true`) och syns bara för misslyckade byggen. Raderna har prefixet `#<steg> <sekunder> ` (t.ex. `#13 6.885 `). Mönster mot loggen måste vara oankrade och läsa även dolda poster (de dolda kan innehålla klonkommandot med token och ska redigeras).
- Webhook-race: ett repo som pushas sekunder innan appen skapas kan ändå bygga (webhooken kom efter appen), som S25.

Spike B (2026-10-14): engångsappen `ccspike-v2b` med den olåsta v2-katalogen och en kopia av det riktiga `cc-gate.mjs` under `.ccspike/` (prod serverar inte en olåst version), raderad på sitt uuid; engångsrepot HumblebragAB/ccspike-v2b arkiverat.

- S1: specens byggkommando (193 tecken med spikens `cp`) fungerar i nixpacks static-läge; `out/` publiceras. Byggtid 83 s (16:49:07–16:50:30), RAM-topp 1770 MB använt av 3936 MB, minst 2166 MB tillgängligt (swap 638 MB använt, oförändrat av bygget); timeouten (S9) är 3600 s. De dolda raderna visar `check-security: OK (23 filer, 3 HTML-sidor med rätt CSP)` och `cc-audit: OK (inga high eller critical i produktionsberoenden)`, alltså nådde `NPM_CONFIG_PRODUCTION` inte npm (S8).
- S3: exemplet laddar och skickar en tid till topplistan utan CSP-meddelanden (headless Chrome via CDP med konsol, `Log` och `Audits`; lokalt API på 127.0.0.1:8123, `connect-src` från `NEXT_PUBLIC_COMPUTERCAT_URL`; en rad i topplistan). Demo serverar metataggen. Sidosynpunkt: från `http://localhost:3000` gav POST 419 (CSRF), även mot prod, eftersom Sanctums standardlista räknade den adressen som inloggad webbapp; rättat i `config/sanctum.php` (bara API:ts egen värd).
- S4: en falsk JWT i `public/` ger `failed` med `check-security: out/cc-spike-key.txt: hemlighet (jwt)`; Demo serverar förra bygget (startsidan 200, nyckelfilen 404). Den globala gitleaks-hooken stoppade committen först; spiken använde `--no-verify` för just den (Eriks beslut). GitHubs push protection stoppade inte pushen.
- S5: säkerhetsdelen i `cc-gate.mjs` ger noll träffar på hemligheter, source maps, symboliska länkar och dolda filer i `out/` från v1-exemplet och Kattsvep (bara de väntade tre `CSP saknas`).
- S6: ett repo utan `scripts/cc-gate.mjs` och med `check` ändrat till ett eko fäller ändå med samma rad (kommandot använder inte repots `scripts/` eller `check`).
- S7: `npm run check` med `NODE_ENV=production` i miljön är grön (exit 0).
- S11: ingen `Content-Security-Policy`-header från Coolify, Traefik eller nginx; bara metataggen gäller.
- S12: `next dev` fungerar (HMR ansluten, rundan skickas) utan CSP-tagg i DOM:en, utan CSP-meddelanden och utan taggen i serverns HTML (kontrollerat med cache avstängd; en första körning visade cachad produktions-HTML).
