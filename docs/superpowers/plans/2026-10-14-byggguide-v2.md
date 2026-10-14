# Delprojekt 3, etapp B: byggguide v2 och säkerhetsspärren i bygget — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Byggguide v2 blir en ny låst version med säkerhetskontroller (hemligheter, source maps och CSP i `out/`, `npm audit` för produktionsberoenden, eslint-regler), och Coolify kör kontrollerna i varje bygge av nya v2-spel med ett byggkommando som Computercat äger, så att ett spel som faller aldrig deployas till Demo.

**Architecture:** v2 är en kopia av v1 i `resources/byggguide/v2/` med nya filer (`scripts/cc-gate.mjs`, `src/lib/computercat/csp.ts`, fyra testfiler) och ändrade (`CLAUDE.md`, README-mallen, `package.json`, `eslint.config.mjs`, `layout.tsx`). `cc-gate.mjs` är spärren i ett enda skript utan beroenden: säkerhetskontroll av `out/` och sedan `npm audit` för produktionsberoenden; `npm run check` och Coolify kör samma fil. `BuildGuide` visar och serverar bara låsta versioner. `BuildGuide::coolifyBuildCommand($version)` ger Coolifys byggkommando (hämta `cc-gate.mjs` över https från den låsta versionens REST och kör den) för versioner som har skriptet; `CoolifyClient::createApplication($slug, $version)` skickar det som `build_command`, och `CoolifyAppStep` tar versionen som repo-steget frös. `get_provisioning_status` får en `next`-text vid `deploy_failed` för sådana versioner. Två spikar mot Coolify ramar in arbetet: A (plattformen, klar) före all kod, B (de riktiga skripten) före låsningen.

**Tech Stack:** Laravel 12, PHP 8.4, laravel/mcp 1.0.2, PHPUnit 11 (klasser, `#[DataProvider]`), SQLite i minnet för test. Byggguiden: Next.js 16.4 (statisk export), React 19.2, vitest 4.1, ESLint 9.39 (flat config), Node 22, npm 10. Coolify v4.4.6 (nixpacks, `is_static`).

**Spec:** `docs/superpowers/specs/2026-10-14-faktabank-design.md`, avsnitten "Etapper" (rad B), "Del 3: säkerhetsspärren i bygget (byggguide v2, etapp B)", "Säkerhet", "Tester" (B) och "Utanför scope". Bilagan `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (alla Spikresultat, inklusive etapp 1a) är källan för allt om Coolify; nya fakta skrivs dit i Task 1 och Task 7. Specen ligger på `main`. Planen ligger på grenen `docs/byggguide-v2-plan` (worktree `/Users/erik/Web/computercat-api/.claude/worktrees/docs-byggguide-v2-plan`) tills den är mergad; läs den därifrån.

**Beroenden:** Etapp A (faktabanken: `App\Services\FactBank`, `list_facts`, `search_facts`, `get_fact`) är live och finns på `main`. Etapp C (artiklar som beskriver v2-spärren, bland annat ny text i `resources/faktabank/security/`) görs efter att B är live och ingår inte här.

---

## Global Constraints

**Arbetssätt**
- Implementationen görs i en ny worktree med egen databas (Task 7 skapar ett lokalt spel för CSP-spiken): `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/byggguide-v2 --db`. Sista raden på stdout är sökvägen, nedan `$WT`. Absoluta sökvägar, huvudkatalogen rörs aldrig, `EnterWorktree` används inte. `$SCRATCH` är `<sessionens scratchpad-katalog>/byggguide-v2` (systemprompten anger scratchpad-katalogen); skapa den med `mkdir -p`.
- Testkommandon: `cd $WT && php artisan test --filter=<Klass>`. Hela sviten körs i Task 8 och Task 11.
- Varje ändring under `resources/byggguide/v2` följs av `cd $WT && php artisan computercat:byggguide:hash v2` (skriver `files[]` och `server_templates` i manifestet) innan PHP-testerna körs. `php artisan computercat:byggguide:verify v2` kör guidens egna kontroller (npm mot registret, bara lokalt) och är testet för guidens TypeScript- och skriptkod.
- Commit-meddelanden på svenska, utan Co-Authored-By-rader. Kommentarer och artisan-utskrifter på svenska. MCP-verktygens svar och fel på engelska, utom `next`-texten vid `deploy_failed` (Ruling R7).
- Ingen åtgärd mot GitHub, Coolify, Forge, Coolify-servern eller prod utan Eriks uttryckliga OK via AskUserQuestion. Task 1 och Task 7 frågar var för sig. Deploy ingår inte i planen; Task 12 körs bara efter Eriks order att deploya.
- `security-first-mindset` invokeras i början av Task 4 (hotmodell för skripten och byggkommandot) och i Task 11 (review gate). `completion-gate` passeras i Task 11 innan något kallas klart.
- **Gissa aldrig Coolifys, npms eller Nexts beteende.** Det som inte står i bilagan, i specen eller i en spikpunkt nedan verifieras i Task 1 eller Task 7. Faller en spikpunkt eller visar något annat än planen förutsätter: stanna och fråga Erik (AskUserQuestion) innan något annat görs.

**v1 och v2**
- **v1 ändras inte.** Ingen fil under `resources/byggguide/v1` ändras och `BuildGuide::FROZEN['v1']` står kvar. Kattsvep och andra v1-spel bygger som förut, utan nytt byggkommando. Kattsvep flyttas inte till v2 (specens "Utanför scope"). Task 11 kontrollerar att `git diff main...HEAD --stat -- resources/byggguide/v1` är tom.
- **`BuildGuide::LATEST` byts från `'v1'` till `'v2'` i samma commit som `FROZEN['v2']` fylls i (Task 8), aldrig tidigare.** `versions()` returnerar bara låsta versioner; en katalog utan `FROZEN`-post syns inte och serveras inte i verktyg och REST (R3).
- **En publicerad version tas aldrig bort ur `resources/byggguide`** så länge appar byggda från den finns: varje v2-apps byggkommando hämtar skripten från `/api/v1/build-guide/v2/files/…`, och utan katalogen faller alla deras byggen. Det gäller v1 på samma sätt (spelens Claude läser den).
- Behöver v2 rättas efter låsningen men före deploy: töm `FROZEN['v2']` och sätt `LATEST` till `'v1'` i samma commit, rätta, hasha, lås igen (`FROZEN['v2']` och `LATEST = 'v2'` i samma commit) och skriv varför i commit-meddelandet. Efter deploy blir varje ändring v3.

**Spec-värden (ordagrant)**
- Hemlighetsmönster med namn som skrivs ut (aldrig värdet): `private-key` `-----BEGIN [A-Z ]*PRIVATE KEY-----`; `github-token` `\bgh[pousr]_[A-Za-z0-9]{36}\b`; `github-pat` `\bgithub_pat_[A-Za-z0-9_]{50,}\b`; `slack-token` `\bxox[abprs]-[A-Za-z0-9-]{10,}\b`; `slack-webhook` `hooks\.slack\.com/services/T[A-Z0-9]+/`; `stripe-live-key` `\b[sr]k_live_[A-Za-z0-9]{20,}\b`; `aws-access-key` `\bAKIA[0-9A-Z]{16}\b`; `jwt` `\beyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b`. (Mönstren är specens; namnen är planens.)
- Inga `*.map` i `out/`.
- CSP i varje `out/**/*.html`, exakt en `<meta http-equiv="Content-Security-Policy">` med: `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' <API-ursprung>; object-src 'none'; base-uri 'self'; form-action 'self'`, där `<API-ursprung>` är ursprunget för `NEXT_PUBLIC_COMPUTERCAT_URL` (standard `https://api.computercat.co`).
- eslint förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med sträng, och `process.env.NEXT_PUBLIC_*` utöver `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`.
- `"test": "NODE_ENV=test vitest run"`. `npm run check` kör typkontroll, lint, test, bygge, `check-export.mjs` och `cc-gate.mjs` (säkerhetskontrollen och audit, samma fil som Coolify kör).
- Audit-delen i `cc-gate.mjs` kör `npm audit --omit=dev --audit-level=high` utan `NPM_CONFIG_PRODUCTION`/`npm_config_production` i miljön (spike A S8), fäller med paketnamn och allvarlighetsgrad vid fynd, gör ett omförsök efter 10 s vid fel mot registret och fäller sedan med texten "npm-registret svarade inte".
- Byggkommandot (Eriks beslut 2026-10-14 efter spike A, S10: Coolify sparar högst 255 tecken och godkänner inte `|`, `;`, `>`, `<` eller `$`, så ingen sha256 i kommandot): `npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs`. Integriteten: Computercat serverar bara låsta guidefiler, `BuildGuide::read` kontrollerar varje fils sha256 innan den skickas, hämtningen går över https till vår egen server, och spelrepot kan inte ändra filen. v1 får inget `build_command`.
- `get_provisioning_status`, `deploy_failed` och v2: `next` = "Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen." Vid v1 eller saknat värde behålls dagens svar (inget `next` vid `deploy_failed`).

**Rulings i denna plan (Erik ska känna till dem)**
- **R1: spiken delas i två.** Specens S1–S7 kräver de riktiga v2-filerna, men Coolify-fakta som koden bygger på ska avgöras innan någon kod skrivs. Task 1 (spike A) är klar 2026-10-14 (bilagan, "Spikresultat delprojekt 3 etapp B"); Task 7 (spike B) kör S1, S3–S7 med de riktiga skripten mot den olåsta v2-katalogen. Båda kräver Eriks OK och städas på sparade id:n.
- **R2: i spikarna ligger skripten i engångsrepot under `.ccspike/`**, och spikens byggkommando kopierar dem till `/tmp` (`cp .ccspike/cc-gate.mjs /tmp/cc-gate.mjs`) och kör dem. Prod serverar inte en olåst v2 (R3) och v2 är inte deployad, så hämtningen från Computercat kan inte provas före deploy. Båda spikarna hämtar ändå v1:s `scripts/check-export.mjs` från `api.computercat.co` med `curl -fsS`, vilket visar att byggmiljön når Computercat (spike A: ja). Att REST serverar exakt filen vaktas av `BuildGuideApiTest`; prod-vägen för v2 kontrolleras i Task 12 efter deploy. Ingen gist eller annan extern lagring används.
- **R3: olåsta versioner döljs också vid läsning.** Specens "syns inte i verktyg och REST" tolkas så att `get_build_guide`, `get_build_guide_file` och REST svarar "okänd version"/404 för en version som inte är låst, inte bara att den saknas i listan. `computercat:byggguide:hash` och `computercat:byggguide:verify` läser olåsta versioner som förut.
- **R4: byggkommandot styrs av manifestet.** En låst version vars manifest har `scripts/cc-gate.mjs` får kommandot; v1 har det inte och får inget. Webbadressen byggs med `BuildGuide::downloadUrl()` (APP_URL, alltid https), som i prod ger exakt specens adress. `coolifyBuildCommand()` fäller själv om kommandot blir längre än 255 tecken eller får tecken utanför Coolifys mönster, i stället för att Coolify svarar 500 utan att skapa appen (spike A S10).
- **R5: audit-delen i `cc-gate.mjs` skickar `--json` och `--registry=https://registry.npmjs.org/` utöver specens flaggor.** JSON behövs för att skilja fynd från registerfel; registerflaggan hindrar att spelrepots `.npmrc` pekar audit mot ett annat register. Formen på svaret verifierades vid planeringen (2026-10-14, npm 10.9.3): en rapport har `auditReportVersion`, `metadata` och `vulnerabilities`; ett registerfel ger `{message, error: {summary, detail}}` och exitkod 1, där orsaken står i `message` (`… reason: getaddrinfo ENOTFOUND registry.npmjs.org`). Felkoden läses ur `error.code` om den finns, annars ur `message`. Bara nätverkskoder (`ENOTFOUND`, `ECONNRESET`, `ECONNREFUSED`, `ETIMEDOUT`, `EAI_AGAIN`) och 5xx (`503`, `E503`) räknas som registerfel och ger ett omförsök efter 10 s; andra koder och utdata som inte är JSON fäller direkt med koden utskriven. Fail closed: high eller critical i rapporten fäller även om npm avslutar med 0. npm startas utan `NPM_CONFIG_PRODUCTION` och `npm_config_production`: nixpacks sätter `NPM_CONFIG_PRODUCTION=false`, och då tar `--omit=dev` med utvecklingsberoenden (spike A S8: 5 high från eslint-kedjan; utan variabeln status 0). Bygget kör npm 10.9.2, Node 22.14.0 och `NODE_ENV=production`.
- **R6: CSP-kontrollen** läser `NEXT_PUBLIC_COMPUTERCAT_URL` ur miljön och sedan ur `.env.production.local`, `.env.local`, `.env.production`, `.env` (samma ordning som `check-export.mjs`), och avkodar HTML-entiteter i attributet (React skriver `'` som `&#x27;`). Policyn sätts i `src/lib/computercat/csp.ts`; `test/security.test.ts` visar att `cc-gate.mjs` godkänner exakt den. Metataggen renderas bara när `process.env.NODE_ENV === 'production'` (Eriks beslut 2026-10-14): `next build` sätter alltid det värdet, så `out/` och kontrollen påverkas inte, medan `npm run dev` slipper policyn.
- **R7: `next`-texten vid `deploy_failed` är svenska ordagrant ur specen**, som `next`-texterna i släppflödet (1a, R5). Övriga svar från verktyget är engelska som förut.
- **R8: braces accepteras utan override (Eriks beslut 2026-10-14).** Det finns ingen rättad version: senaste publicerade braces är 3.0.3 och GHSA-vfj7-8cjw-p6xm gäller `<=3.0.3`; `npm audit` föreslår bara att nedgradera `eslint-config-next` till 14.2.35. Fyndet finns bara i dev-beroenden (eslint-kedjan). Produktionsgranskningen (`npm audit --omit=dev --audit-level=high`) är ren, och det är den som spärren kör. v2:s `CLAUDE.md` och README säger det på en rad; `overrides` i v2 är oförändrad (`{"vite": "7.3.7"}`).
- **R9: `CoolifyAppStep` fäller med `StepFailed` om repo-steget saknar guideversion.** Alla runs sedan delprojekt 2 har värdet; ett saknat värde är ett fel, ingen gissning.
- **R10: spikapparna heter `ccspike-v2a` och `ccspike-v2b`** (utan prefixet `spel-`), så att nattkontrollen (`CheckProvisioning`, som larmar för `spel-*`-appar utan run) inte larmar medan de finns.
- **R11: den planterade hemligheten i spike B är en falsk JWT som skapas vid körning.** Stoppar en commit-hook (gitleaks) eller GitHubs push protection den: stanna och fråga; `--no-verify` används aldrig på eget initiativ.
- **R12: ärlig gräns för spärren.** Att ta bort eller ändra skripten eller `check` stänger inte av spärren; spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod (`npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön). Samma mening står i v2:s `CLAUDE.md`, README, hotmodellen (Task 4), granskningen (Task 11) och specens "Säkerhet". Spike A (S13) visade att byggkatalogen `/app` saknar `.git/config` (Dockerfile kopierar repot utan `.git`), så Coolifys GitHub-token går inte att läsa där.
- **R13: `CoolifyAppStep` räknar fram byggkommandot innan markören `create_requested_at` sparas**, och ett fel där (olåst version, trasig guide) blir `StepFailed` utan att något skapas i Coolify.
- **R14: ett enda spärrskript, `scripts/cc-gate.mjs`** (Eriks beslut 2026-10-14). Coolifys `varchar(255)` rymmer inte två hämtningar med sha256-kontroll, så säkerhetskontrollen och audit ligger i samma fil, som inte importerar något utanför Node. Den är enda implementationen: `npm run check` kör den också, så spelets Claude får samma fel lokalt som Coolify. Det finns inga separata `check-security.mjs` och `cc-audit.mjs`; utskrifterna behåller prefixen `check-security:` och `cc-audit:` för de två delarna.
- **R15: byggloggar.** För lyckade byggen är byggstegets utdata dolda (`hidden: true`) och raderna har prefixet `#<steg> <sekunder> ` (spike A). Spike B läser därför även dolda poster (tokens redigeras) med oankrade mönster. Webhook-race (S25) syntes igen i spike A: en push sekunder före appen kan ändå starta ett bygge.
- **R16: Coolifys timeout** för en deployment är `dynamic_timeout = 3600` s (spike A S9); spike B jämför byggtiden med det.

**Värden**
- Prod-artisan (Coolify-API:t är IP-låst till Forge-servern): `~/.claude/bin/ssh-claude --via-home forge@46.246.48.182`, katalog `/home/computercat/api.computercat.co/current`, `sudo -n -u computercat php8.4 artisan …`. Coolify-databasen (bara `SELECT`): `~/.claude/bin/ssh-claude --via-home erik@apps.humblebrag.se` + `sudo -n docker exec coolify-db psql -U coolify -d coolify`.
- v1:s `scripts/check-export.mjs` har sha256 `cc64163bcc8c45df8d0e77f5df825c09c367794a95eec0b877ee0569164c53b8` (låst manifest); spike A hämtar den från prod-REST för att visa att byggmiljön når Computercat.
- Spikarna: repo `HumblebragAB/ccspike-v2a` / `ccspike-v2b` (privata, arkiveras efteråt, raderas aldrig), Coolify-app med samma namn i Demo-projektet (raderas på sitt uuid), Demo `https://ccspike-v2a.apps.humblebrag.se` / `https://ccspike-v2b.apps.humblebrag.se` (basic auth `demo`/`demo`). Spikskripten ligger i repot under `.ccspike/` (R2).

---

## Review Focus

De fem fel som specen innebär men som lätt passerar oupptäckta. Varje rad har ett test i den task som äger koden.

1. **En olåst version läcker ut.** En v2-katalog som ligger i repot men inte är låst får inte synas i `get_build_guide`, serveras av REST eller bakas in i ett Coolify-kommando (vars skript då inte går att hämta i prod och fäller varje bygge). Test: `BuildGuideApiTest::test_unpublished_version_is_not_listed_or_served`, `BuildGuideToolsTest::test_unpublished_version_is_unknown` (Task 2), `BuildGuideTest::test_build_command_requires_a_published_version` (Task 9).
2. **Byggkommandot ryms inte eller avvisas av Coolify.** Coolify sparar `build_command` i `varchar(255)` (längre ger 500 och ingen app) och godkänner inte `|`, `;`, `>`, `<` eller `$` (spike A S10); en längre APP_URL eller ett tillagt steg skulle tyst stoppa varje ny provisionering. Test: `BuildGuideTest::test_build_command_fits_coolify` och `test_too_long_command_is_refused_before_coolify` (Task 9).
3. **Säkerhetsdelen i `cc-gate.mjs` larmar fel eller läcker.** Ordgränser (`xsk_live_…` är ingen träff), CSP som React skrivit med `&#x27;`, URL ur `.env.local`, två CSP-taggar, och att värdet aldrig skrivs ut. Test: `test/security.test.ts` i guiden (Task 4), körd via `computercat:byggguide:verify v2`.
4. **Audit räknar fel: registerfel som ren audit, fynd som registerfel, eller dev-beroenden som produktion.** Ett npm som inte når registret får aldrig släppa igenom bygget, `NPM_CONFIG_PRODUCTION=false` från nixpacks får aldrig nå npm (spike A S8), ett high-fynd får aldrig få texten "npm-registret svarade inte", och ett annat npm-fel (till exempel `E401`) ska fälla direkt med sin kod i stället för att vänta. Test: `test/ccAudit.test.ts` (Task 5): miljön utan `NPM_CONFIG_PRODUCTION`, registret nere två gånger, nere och sedan uppe, 5xx, annan felkod, high med exitkod 0, utdata utan JSON.
5. **Gamla runs får v2-beteende.** En run som frös v1 (eller saknar värdet) får inget byggkommando och ingen ny statustext, även efter att `LATEST` blivit v2. Test: `CoolifyStepsTest::test_v1_run_creates_app_without_build_command`, `test_missing_build_guide_version_fails_before_any_create` (Task 9), `ProvisioningStatusToolTest::test_failed_v1_build_keeps_todays_answer` och `test_failed_build_without_recorded_version_keeps_todays_answer` (Task 10).

---

## Filstruktur

| Fil | Ansvar |
|---|---|
| `app/Services/BuildGuide.php` (ändras) | `isPublished()`, `versions()` bara låsta, `GATE_SCRIPT`, `COOLIFY_COMMAND_MAX`, `runsSecurityChecksInBuild()`, `coolifyBuildCommand()`; `LATEST`/`FROZEN['v2']` i Task 8 |
| `app/Http/Controllers/Api/V1/BuildGuideController.php`, `app/Mcp/Tools/GetBuildGuideTool.php`, `GetBuildGuideFileTool.php` (ändras) | nekar olåsta versioner |
| `resources/byggguide/v2/**` (ny) | byggguide v2: manifest, filer, README-mall |
| `resources/byggguide/v2/files/scripts/cc-gate.mjs` | spärren: hemligheter, source maps, dolda filer och CSP i `out/`, sedan `npm audit` med skillnad på fynd och registerfel |
| `resources/byggguide/v2/files/src/lib/computercat/csp.ts` (+ `csp.test.ts`) | CSP-policyn |
| `resources/byggguide/v2/files/src/app/layout.tsx` (ändrad kopia) | CSP-meta |
| `resources/byggguide/v2/files/eslint.config.mjs` (ändrad kopia) | säkerhetsreglerna |
| `resources/byggguide/v2/files/test/security.test.ts`, `test/ccAudit.test.ts`, `test/eslintRules.test.ts` | guidens tester för skripten och reglerna |
| `resources/byggguide/v2/files/CLAUDE.md`, `templates/README.md`, `test/docs.test.ts`, `package.json` (ändrade kopior) | v2-texter, Faktabanken, skript |
| `app/Provisioning/Clients/CoolifyClient.php` (ändras) | `createApplication($slug, $version)` med `build_command` |
| `app/Provisioning/Steps/CoolifyAppStep.php` (ändras) | skickar den frysta versionen |
| `app/Mcp/Tools/GetProvisioningStatusTool.php` (ändras) | `next` vid `deploy_failed` för versioner med spärren |
| `tests/Feature/BuildGuideTest.php` (skrivs om, parametriseras), `tests/Feature/BuildGuideV2Test.php` (ny) | invarianter och v2-innehåll |
| `tests/Feature/Api/V1/BuildGuideApiTest.php`, `tests/Feature/Mcp/BuildGuideToolsTest.php`, `tests/Feature/Provisioning/CoolifyClientTest.php`, `CoolifyStepsTest.php`, `RepoStepTest.php`, `tests/Feature/Mcp/ProvisioningStatusToolTest.php` (ändras) | tester |
| `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (ändras) | spikresultat etapp B |

---

## Spikverktyg (Task 1 och Task 7)

Definieras i skalet före spikstegen. Ingen gist eller annan extern lagring används (R2). Inga hemligheter passerar transkriptet: Coolify-token läses av Computercat i prod (`config('provisioning.coolify.token')`), och skripten skriver bara status, uuid, tider och utvalda fält.

```bash
export WT SCRATCH
# Demo-adressernas basic auth är konventionen demo/demo (provisioning.demo, ingen hemlighet).
DEMO_AUTH="demo:demo"
# Kör ett tinker-skript (utan <?php) i prod. Skriptet skickas base64-kodat så att citattecken inte krockar.
prodtinker() {
  local b64; b64=$(base64 < "$1" | tr -d '\n')
  ~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd /home/computercat/api.computercat.co/current && echo $b64 | base64 -d | sudo -n -u computercat php8.4 artisan tinker --execute=\"\$(cat)\""
}
# Bara SELECT mot Coolifys databas. SQL:en får inte innehålla dubbla citattecken.
coolifydb() {
  ~/.claude/bin/ssh-claude --via-home erik@apps.humblebrag.se "sudo -n docker exec coolify-db psql -U coolify -d coolify -At -c \"$1\""
}
```

Svarar `ssh-claude` med en felmod (FM1–FM6): följ wrapperns åtgärd; gissa aldrig orsaken.

Tinker-mallar (tokens `__NAME__`, `__CMD_B64__`, `__UUID__` ersätts med `sed` vid körning, aldrig för hand). Skriv dem till `$SCRATCH/tinker/`:

`$SCRATCH/tinker/create-app.php`:

```php
$cfg = config('provisioning.coolify');
$body = [
    'project_uuid' => $cfg['project_uuid'], 'server_uuid' => $cfg['server_uuid'],
    'environment_name' => $cfg['environment_name'], 'github_app_uuid' => $cfg['github_app_uuid'],
    'git_repository' => 'HumblebragAB/__NAME__', 'git_branch' => 'main', 'build_pack' => 'nixpacks', 'name' => '__NAME__',
    'domains' => 'https://__NAME__.apps.humblebrag.se', 'ports_exposes' => '80', 'is_static' => true,
    'static_image' => 'nginx:alpine', 'publish_directory' => '/out', 'install_command' => 'npm ci --ignore-scripts',
    'build_command' => base64_decode('__CMD_B64__'),
    'is_auto_deploy_enabled' => true, 'instant_deploy' => false,
    'is_http_basic_auth_enabled' => true, 'http_basic_auth_username' => 'demo', 'http_basic_auth_password' => 'demo',
];
$r = Illuminate\Support\Facades\Http::withToken($cfg['token'])->acceptJson()->post(rtrim($cfg['url'], '/').'/api/v1/applications/private-github-app', $body);
echo 'status='.$r->status().' uuid='.($r->json('uuid') ?? '-').' message='.json_encode($r->json('message')).' errors='.json_encode($r->json('errors'))."\n";
```

`$SCRATCH/tinker/read-app.php`:

```php
$cfg = config('provisioning.coolify');
$r = Illuminate\Support\Facades\Http::withToken($cfg['token'])->acceptJson()->get(rtrim($cfg['url'], '/').'/api/v1/applications/__UUID__');
$json = $r->json() ?? [];
echo 'status='.$r->status().' has_build_command='.var_export(array_key_exists('build_command', $json), true)
    .' build_command_same='.var_export(($json['build_command'] ?? null) === base64_decode('__CMD_B64__'), true)
    .' install_command='.json_encode($json['install_command'] ?? null)."\n";
```

`$SCRATCH/tinker/deployments.php`:

```php
$cfg = config('provisioning.coolify');
$r = Illuminate\Support\Facades\Http::withToken($cfg['token'])->acceptJson()->get(rtrim($cfg['url'], '/').'/api/v1/deployments/applications/__UUID__', ['skip' => 0, 'take' => 5]);
echo 'status='.$r->status().' count='.$r->json('count')."\n";
foreach ($r->json('deployments') ?? [] as $d) {
    echo implode(' ', [$d['deployment_uuid'] ?? '-', $d['status'] ?? '-', $d['created_at'] ?? '-', $d['finished_at'] ?? '-', $d['commit'] ?? '-'])."\n";
}
```

`$SCRATCH/tinker/delete-app.php` (raderar bara om uuid:t finns i Demo-projektet med det sparade namnet):

```php
$cfg = config('provisioning.coolify');
$base = rtrim($cfg['url'], '/').'/api/v1';
$http = fn () => Illuminate\Support\Facades\Http::withToken($cfg['token'])->acceptJson();
$apps = $http()->get($base.'/projects/'.$cfg['project_uuid'].'/'.$cfg['environment_name'])->json('applications') ?? [];
$match = array_values(array_filter($apps, fn ($a) => ($a['uuid'] ?? null) === '__UUID__' && ($a['name'] ?? null) === '__NAME__'));
if (count($match) !== 1) {
    echo "STOPP: __UUID__ med namnet __NAME__ finns inte i Demo-projektet; inget raderas.\n";
} else {
    $r = $http()->delete($base.'/applications/__UUID__');
    echo 'delete status='.$r->status().' '.json_encode($r->json('message'))."\n";
}
```

Fyll i en mall: `sed -e "s/__NAME__/$NAME/g" -e "s/__UUID__/$UUID/g" -e "s|__CMD_B64__|$CMD_B64|g" $SCRATCH/tinker/<mall>.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php`.

Bygglogg (bara våra markörer, mönstren oankrade eftersom raderna har prefixet `#<steg> <sekunder> `; råfilen kan innehålla klonkommandot med token i dolda poster, så tokens redigeras och råfilen tas bort direkt):

```bash
buildlog() {   # $1 = deployment_uuid, $2 = grep-mönster
  local raw="$SCRATCH/spike/log-$1.raw"
  coolifydb "select logs from application_deployment_queues where deployment_uuid='$1'" > "$raw"
  # Även dolda poster: lyckade byggen döljer byggstegets utdata (spike A). Tokens redigeras före grep.
  jq -r '.[] | .output' "$raw" | sed -E -e 's/(gh[pousr]_|github_pat_)[A-Za-z0-9_]+/[REDACTED]/g' -e 's/x-access-token:[^@[:space:]]+@/x-access-token:[REDACTED]@/g' | grep -E "$2"
  rm -f "$raw"
}
```

Är `logs` inte en JSON-lista med `output`: stanna och fråga; skriv aldrig ut hela loggen.

---
### Task 1: Spike A — Coolify kör ett eget byggkommando (före all kod) — KLAR

**Status: klar 2026-10-14 (commit 8cf1286 på `feat/byggguide-v2`).** Resultaten står i bilagan, avsnittet "Spikresultat delprojekt 3 etapp B". Det som ändrade planen: `build_command` ryms i högst 255 tecken och får inte innehålla `|`, `;`, `>`, `<` eller `$` (S10), därför ett enda spärrskript utan sha256 i kommandot (R14, Eriks beslut); nixpacks sätter `NPM_CONFIG_PRODUCTION=false`, som audit-delen måste ta bort (S8, R5); lyckade byggen döljer byggstegets utdata och raderna har prefixet `#<steg> <sekunder> ` (R15); timeouten är 3600 s (S9, R16); `/app` saknar `.git/config`, så Coolifys GitHub-token kan inte läsas i bygget (S13, R12); webhook-racet syntes igen (R15). Stegen nedan står kvar som dokumentation av hur spiken kördes och körs inte om.

**Files:**
- Modify: `$WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (nytt avsnitt "Spikresultat delprojekt 3 etapp B")
- Create (scratch, committas inte): `$SCRATCH/spike-a/ids.env`, `$SCRATCH/tinker/*.php`; i engångsrepot: `.ccspike/cc-security.mjs`, `.ccspike/cc-audit.mjs`

**Interfaces:**
- Produces: verifierade fakta som Task 4, 5 och 9 bygger på: S2 (nätverk till npm-registret och `api.computercat.co`, `curl` och `sha256sum` i nixpacks-bilden, `NODE_ENV` i byggsteget), S4a (ett steg i byggkommandot som fäller ger `failed`, och Demo visar förra bygget), S8 (npm-version i bygget; formen på `npm audit --json` jämförs med R5), S9 (Coolifys timeout för en deployment), S10 (`build_command` via `POST /applications/private-github-app` sparas och når nixpacks oförändrat, körs av ett skal som förstår `&&` och `|`, och `install_command` körs fortfarande), S13 (går Coolifys GitHub-token att läsa från bygget: finns `.git/config` i byggkatalogen och innehåller den `x-access-token`; bara ja/nej skrivs ut).

Specens S1, S3–S7 kräver de riktiga v2-skripten och körs i Task 7 (R1). Här används v1-grundkoden och två ställföreträdande skript som bara skriver miljöfakta; de ligger i engångsrepot under `.ccspike/` (R2). **Faller en punkt: stanna och fråga Erik.**

- [x] **Steg 1: Worktree och förutsättningar (bara läsning).**

```bash
~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/byggguide-v2 --db
```

Spara sista raden som `$WT`. Sätt `SCRATCH=<sessionens scratchpad>/byggguide-v2` och `mkdir -p $SCRATCH/spike-a $SCRATCH/spike $SCRATCH/tinker`. Definiera spikverktygen (avsnittet ovan) och skriv tinker-mallarna. Kontrollera:

```bash
cd $WT && php artisan migrate:status | tail -3          # egen databas migrerad; annars: php artisan migrate
gh auth status && gh api orgs/HumblebragAB --jq .login   # förväntat: HumblebragAB
gh api repos/HumblebragAB/ccspike-v2a --silent; echo "exit=$?"   # förväntat: exit=1 (404, namnet ledigt)
```

Finns repot redan, eller når `gh` inte organisationen: stanna och fråga.

- [x] **Steg 2: Fråga Erik (AskUserQuestion) innan något skapas.** Texten: "Spike A för byggguide v2 skapar på riktigt: det privata repot HumblebragAB/ccspike-v2a med v1-grundkoden och två ställföreträdande skript under .ccspike/ (arkiveras efteråt), och Coolify-appen ccspike-v2a i Demo-projektet (via Computercats token från Forge-servern, raderas på sitt uuid). Appen får ett eget build_command som hämtar v1:s check-export.mjs från api.computercat.co, kopierar skripten till /tmp, kontrollerar sha256 och kör dem; två byggen körs (ett lyckat, ett som fäller med avsikt). Skriptet svarar också ja/nej på om .git/config i bygget innehåller Coolifys GitHub-token (aldrig värdet). Jag läser byggloggen och Coolifys timeout-inställning med SELECT i Coolify-databasen. Inget rör Kattsvep eller andra appar. OK?" Utan OK: stanna.

- [x] **Steg 3: Repot med v1-grundkoden och spikskripten.** Grundkoden skrivs ut av verify (filerna ur det låsta manifestet plus README ur mallen):

```bash
cd $WT && php artisan computercat:byggguide:verify v1 --keep | tee $SCRATCH/spike-a/verify.log | tail -2
KEEP=$(sed -n 's/^Katalogen behålls: //p' $SCRATCH/spike-a/verify.log); echo "KEEP=$KEEP" >> $SCRATCH/spike-a/ids.env
mkdir -p "$KEEP/.ccspike" "$KEEP/public" && echo 0 > "$KEEP/public/spike-version.txt"
```

`$KEEP/.ccspike/cc-security.mjs`:

```js
// Spike A, byggguide v2: ställföreträdare för check-security.mjs. Skriver bara miljöfakta, aldrig värden.
import { existsSync, readFileSync } from 'node:fs';

const gitConfig = existsSync('.git/config');
const token = gitConfig && readFileSync('.git/config', 'utf8').includes('x-access-token');
console.log(`spike-security: cwd=${process.cwd()} node=${process.version} NODE_ENV=${process.env.NODE_ENV ?? '(osatt)'} GAME=${process.env.NEXT_PUBLIC_COMPUTERCAT_GAME ?? '(osatt)'}`);
console.log(`spike-security: git-config=${gitConfig ? 'ja' : 'nej'} x-access-token=${token ? 'ja' : 'nej'}`);
if (!existsSync('out/index.html')) {
  console.error('spike-security: out/index.html saknas');
  process.exit(1);
}
if (existsSync('out/cc-spike-fail.txt')) {
  console.error('spike-security: out/cc-spike-fail.txt finns, fäller med avsikt');
  process.exit(1);
}
console.log('spike-security: OK');
```

`$KEEP/.ccspike/cc-audit.mjs`:

```js
// Spike A, byggguide v2: kör npm audit som cc-audit.mjs kommer att göra och skriver bara formen.
import { spawnSync } from 'node:child_process';

const version = spawnSync('npm', ['--version'], { encoding: 'utf8' });
console.log(`spike-audit: npm=${(version.stdout ?? '').trim()}`);
const r = spawnSync('npm', ['audit', '--omit=dev', '--audit-level=high', '--json', '--registry=https://registry.npmjs.org/'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
let keys = '(ingen JSON)';
let counts = '';
try {
  const json = JSON.parse(r.stdout);
  keys = Object.keys(json).sort().join(',');
  counts = JSON.stringify(json.metadata?.vulnerabilities ?? null);
} catch {
  // formen skrivs som "(ingen JSON)"
}
console.log(`spike-audit: status=${r.status} keys=${keys} vulnerabilities=${counts}`);
process.exit(r.status ?? 1);
```

Committa och skapa repot:

```bash
cd "$KEEP" && S=$(shasum -a 256 .ccspike/cc-security.mjs | cut -c1-64) && A=$(shasum -a 256 .ccspike/cc-audit.mjs | cut -c1-64)
git init -q -b main && git add -A && git -c user.name=Spike -c user.email=spike@invalid commit -qm "Spike: grundkod från byggguide v1 och spikskript"
git ls-files | grep -cE '^(node_modules|out|\.next)/'    # förväntat: 0 (guidens .gitignore)
gh repo create HumblebragAB/ccspike-v2a --private --description "Engångsrepo för spike byggguide v2 (arkiveras)" --source=. --remote=origin --push
gh api repos/HumblebragAB/ccspike-v2a --jq '"REPO_ID=\(.id) private=\(.private)"'
```

Förväntat: `Byggguide v1: OK` sist i verify, och `private=true`. Skriv `REPO_ID=…` till `ids.env` direkt. **Är `private` false: stanna omedelbart.** Ingen app finns ännu, så pushen bygger inget.

- [x] **Steg 4: Byggkommandot.** Specens form, med en diagnostisk början (S2), hämtning av v1:s `check-export.mjs` från prod-REST (byggmiljön når Computercat) och spikskripten ur `.ccspike/` i stället för REST (R2). Inga enkla citattecken, inget `$`:

```bash
CMD="node --version && npm --version && command -v curl && command -v sha256sum && npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v1/files/scripts/check-export.mjs -o /tmp/cc-v1-check-export.mjs && echo \"cc64163bcc8c45df8d0e77f5df825c09c367794a95eec0b877ee0569164c53b8  /tmp/cc-v1-check-export.mjs\" | sha256sum -c - && cp .ccspike/cc-security.mjs /tmp/cc-security.mjs && cp .ccspike/cc-audit.mjs /tmp/cc-audit.mjs && echo \"$S  /tmp/cc-security.mjs\" | sha256sum -c - && echo \"$A  /tmp/cc-audit.mjs\" | sha256sum -c - && node /tmp/cc-security.mjs && node /tmp/cc-audit.mjs"
printf '%s' "$CMD" | grep -c "'" ; printf '%s' "$CMD" | grep -c '\$'   # förväntat: 0 och 0
CMD_B64=$(printf '%s' "$CMD" | base64 | tr -d '\n')
```

- [x] **Steg 5: Skapa appen (S10).**

```bash
NAME=ccspike-v2a
sed -e "s/__NAME__/$NAME/g" -e "s|__CMD_B64__|$CMD_B64|g" $SCRATCH/tinker/create-app.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php
```

Förväntat: `status=201 uuid=<uuid>`. Skriv `APP_UUID=<uuid>` till `ids.env` direkt. 422 eller annat: spara utskriften, stanna och fråga (bilaga A §1 listar `build_command` som valfritt fält, S:796). Läs tillbaka:

```bash
UUID=$APP_UUID; sed -e "s/__UUID__/$UUID/g" -e "s|__CMD_B64__|$CMD_B64|g" $SCRATCH/tinker/read-app.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php
```

Notera `has_build_command`, `build_command_same` (förväntat `true`) och `install_command`. Är `build_command_same` false: stanna och fråga (Coolify ändrar kommandot).

- [x] **Steg 6: Bygge 1, lyckat (S2, S8, S10, S13).** Starta bygget med en push (auto-deploy, bilaga S24):

```bash
cd "$KEEP" && echo 1 > public/spike-version.txt && git commit -qam "Spike: bygge 1" && git push -q origin main
```

Poll `deployments.php` (fyll i `__UUID__`) var 30:e sekund (Monitor eller bakgrundsloop) tills senaste posten är `finished` eller `failed`, högst 20 minuter. Spara `DEP1=<deployment_uuid>` i `ids.env`. Läs först en enda synlig loggrad oförändrad, för att se vilket prefix (tid, steg) Coolify sätter före utdata: `buildlog "$DEP1" 'spike-security: cwd' | head -1`. Mönstren nedan är därför oankrade. Läs sedan:

```bash
buildlog "$DEP1" 'spike-|v22\.|[0-9]+\.[0-9]+\.[0-9]+|/(curl|sha256sum)|/tmp/cc-.*: OK|build-cmd|install-cmd'
curl -s -u "$DEMO_AUTH" https://ccspike-v2a.apps.humblebrag.se/spike-version.txt     # förväntat: 1
```

Förväntat: `finished`; `node` v22.x; en npm-version (S8); sökvägar till `curl` och `sha256sum` (S2); tre rader `/tmp/cc-…: OK` (v1-filen från `api.computercat.co` och båda spikskripten, S2); `spike-security: … NODE_ENV=<värde>` (notera värdet, S2); `spike-security: git-config=<ja|nej> x-access-token=<ja|nej>` (S13); `spike-audit: npm=… status=0 keys=auditReportVersion,metadata,vulnerabilities …` (S8, samma form som R5); `--build-cmd`-raden med kommandot oförändrat och `--install-cmd 'npm ci --ignore-scripts'` (S10, jämför med bilaga S20b). Syns inte `--build-cmd` i den synliga loggen men alla markörer kom i rätt ordning: notera det. **`x-access-token=ja`: stanna och fråga Erik** (Coolifys GitHub-token kan läsas av spelkod i bygget; det påverkar säkerhetsgränsen R12). `failed`, saknade markörer eller en annan JSON-form: stanna och fråga.

- [x] **Steg 7: Bygge 2, fäller med avsikt (S4a).**

```bash
cd "$KEEP" && echo 2 > public/spike-version.txt && echo fall > public/cc-spike-fail.txt && git add -A && git commit -qm "Spike: bygge 2 ska falla" && git push -q origin main
```

Poll som i steg 6; spara `DEP2`. Förväntat: `failed`, `buildlog "$DEP2" 'spike-'` visar `spike-security: out/cc-spike-fail.txt finns, fäller med avsikt`, och `curl -s -u "$DEMO_AUTH" https://ccspike-v2a.apps.humblebrag.se/spike-version.txt` ger fortfarande `1` (förra bygget serveras). Visar Demo `2` eller svarar inte 200: stanna och fråga.

- [x] **Steg 8: Timeout för en deployment (S9).** Bara läsning:

```bash
coolifydb "select table_name, column_name from information_schema.columns where table_schema = 'public' and column_name ilike '%timeout%' order by 1, 2"
```

Läs värdet för de kolumner som gäller byggen (för servern `localhost` och för appen `ccspike-v2a`), till exempel `coolifydb "select <kolumn> from <tabell> where …"` med kolumnen ur listan. Skriv bara kolumnnamn och värde. Hittas ingen kolumn som rör byggen: fråga Erik (AskUserQuestion) var timeouten står i Coolify-UI:t och vilket värde den har.

- [x] **Steg 9: Städning på sparade id:n.** Läs `ids.env`.

```bash
NAME=ccspike-v2a; UUID=$APP_UUID
sed -e "s/__NAME__/$NAME/g" -e "s/__UUID__/$UUID/g" $SCRATCH/tinker/delete-app.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php
[ "$(gh api repos/HumblebragAB/ccspike-v2a --jq .id)" = "$REPO_ID" ] && gh api -X PATCH repos/HumblebragAB/ccspike-v2a -F archived=true --jq .archived
rm -rf "$KEEP"     # katalogen som verify skapade i steg 3, och bara den
```

Förväntat: `delete status=200` och `true`. Poll `deployments.php` eller projektlistan tills appen är borta (bilaga S17: inom 30 s). `STOPP:` från delete-skriptet eller fel id på repot: stanna och fråga; inget raderas på något annat id.

- [x] **Steg 10: Skriv resultaten i bilagan.** Lägg till sist i `$WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md`:

```markdown
## Spikresultat delprojekt 3 etapp B (Coolify v4.4.6)

Spike A (<datum>): engångsappen `ccspike-v2a` med v1-grundkoden och ställföreträdande skript i repot under `.ccspike/`, skapad med Computercats token från Forge-servern och raderad på sitt uuid. S-numren nedan är faktabank-specens (S1–S7) och planens (S8–S13), inte avsnitten ovan.

- S2: byggsteget når `registry.npmjs.org` och `api.computercat.co`; `curl` finns i <sökväg>, `sha256sum` i <sökväg>; `NODE_ENV` i byggsteget är <värde>.
- S4a: ett steg i `build_command` som avslutar med fel ger status `failed`, och Demo serverar förra bygget (<vad som kontrollerades>).
- S8: npm i byggsteget är <version>; `npm audit --omit=dev --audit-level=high --json` ger nycklarna <nycklar> och exitkod <kod> på v1-grundkoden.
- S9: Coolifys timeout för en deployment är <kolumn = värde, eller var i UI:t>.
- S10: `build_command` i `POST /applications/private-github-app` ger 201 och sparas oförändrat (<has_build_command, build_command_same>); nixpacks får det som <hur det syns i loggen>; `&&` och `|` tolkas av ett skal; `install_command` körs fortfarande. Loggraderna har prefixet <format>.
- S13: `.git/config` i byggkatalogen: <ja|nej>; innehåller `x-access-token`: <ja|nej>.
```

Ersätt varje `<…>` med uppmätt värde och kontrollera: `sed -n '/^## Spikresultat delprojekt 3 etapp B/,$p' $WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md | grep -c '<'` ska ge `0`.

- [x] **Steg 11: Stäm av och committa.** Avviker något från planens antaganden (kommandot ändras eller avvisas, inget skal tolkar `&&`/`|`, `sha256sum` eller `curl` saknas, byggsteget når inte nätet, npm-JSON har annan form än R5, ett fällt steg ger inte `failed` eller Demo byter ändå, token läsbar enligt S13): stanna, fråga Erik och uppdatera berörda tasks (4, 5, 9) innan Task 2 påbörjas.

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md && git commit -m "Spike A: Coolify kör eget byggkommando (etapp B, S2, S4a, S8-S10, S13)"
```

---

### Task 2: Bara låsta versioner syns och serveras; parametriserade guidetester

**Files:**
- Modify: `app/Services/BuildGuide.php`
- Modify: `app/Http/Controllers/Api/V1/BuildGuideController.php`
- Modify: `app/Mcp/Tools/GetBuildGuideTool.php`, `app/Mcp/Tools/GetBuildGuideFileTool.php`
- Rewrite: `tests/Feature/BuildGuideTest.php`
- Modify: `tests/Feature/Api/V1/BuildGuideApiTest.php`, `tests/Feature/Mcp/BuildGuideToolsTest.php`

**Interfaces:**
- Produces: `BuildGuide::isPublished(string $version): bool` (mönstret, en icke-tom `FROZEN`-post och `manifest.json` på disk). `BuildGuide::versions(): list<string>` returnerar bara låsta versioner, sorterade numeriskt. `root()`, `manifest()`, `file()`, `claudeMd()`, `readme()` fungerar för olåsta versioner som förut (hash och verify behöver dem). REST svarar 404 med samma meddelanden som för okända versioner; verktygen svarar "Unknown build guide version. Available: …".
- `BuildGuideTest` har `public static function publishedVersions(): array` (data provider ur `FROZEN`) och konstanten `EXPECTED` (version ⇒ `overrides`); Task 8 lägger till `'v2'`.

- [ ] **Steg 1: Skriv om `tests/Feature/BuildGuideTest.php`.** Hela filen:

```php
<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use App\Services\BuildGuideNotFound;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

// Byggguiden (spec 2c, "Byggguiden"; delprojekt 3 del 3, "Versioner"). Två uttryck för samma
// sanning får aldrig glida isär: manifestet och filerna på disk, och manifestets stack och
// package.json. Versionsspecifika tester körs för varje låst version (BuildGuide::FROZEN).
class BuildGuideTest extends TestCase
{
    // Det som skiljer de låsta versionerna åt. En version läggs till här i samma
    // commit som den låses (test_every_published_version_has_expectations vaktar).
    private const EXPECTED = [
        'v1' => ['overrides' => ['vite' => '7.3.7']],
    ];

    private ?string $tempRoot = null;

    /** @return array<string, array{string}> */
    public static function publishedVersions(): array
    {
        $versions = [];
        foreach (BuildGuide::FROZEN as $version => $sha) {
            if ($sha !== '') {
                $versions[$version] = [$version];
            }
        }

        return $versions;
    }

    protected function tearDown(): void
    {
        // Bara katalogen som testet självt skapade.
        if ($this->tempRoot !== null) {
            File::deleteDirectory($this->tempRoot);
        }
        parent::tearDown();
    }

    /** Kopia av guiden i en temporär katalog, för tester som måste ändra filer. */
    private function tempGuide(): string
    {
        $this->tempRoot = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $this->tempRoot);
        config(['byggguide.path' => $this->tempRoot]);

        return $this->tempRoot;
    }

    /** v1 kopierad till en olåst version i en temporär guide. */
    private function unpublishedCopy(string $version): string
    {
        $root = $this->tempGuide();
        File::copyDirectory("{$root}/v1", "{$root}/{$version}");
        $manifest = json_decode(file_get_contents("{$root}/{$version}/manifest.json"), true);
        $manifest['version'] = $version;
        file_put_contents("{$root}/{$version}/manifest.json", json_encode($manifest));

        return $root;
    }

    public function test_latest_version_is_published(): void
    {
        $this->assertContains(BuildGuide::LATEST, BuildGuide::versions());
        $this->assertTrue(BuildGuide::isPublished(BuildGuide::LATEST));
        $this->assertSame(BuildGuide::LATEST, BuildGuide::manifest()['version']);
    }

    public function test_every_published_version_has_expectations(): void
    {
        $this->assertSame(array_keys(self::publishedVersions()), array_keys(self::EXPECTED));
        $this->assertSame(array_keys(self::publishedVersions()), BuildGuide::versions());
    }

    #[DataProvider('publishedVersions')]
    public function test_manifest_has_the_rules(string $version): void
    {
        $m = BuildGuide::manifest($version);

        $this->assertSame($version, $m['version']);
        $this->assertSame('22.x', $m['node']);
        $this->assertSame(self::EXPECTED[$version]['overrides'], $m['overrides']);
        $this->assertSame('npm ci --ignore-scripts', $m['commands']['install']);
        $this->assertSame(
            ['npm run check', 'npm audit --omit=dev --audit-level=high', 'test -f out/index.html'],
            array_column($m['required_checks'], 'command'),
        );
        $this->assertStringContainsString("Grundkod från byggguide {$version}", implode("\n", $m['steps']));
        foreach ($m['stack'] as $package => $exact) {
            // Exakt låsta versioner: inga intervall.
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\z/', $exact, $package);
        }
    }

    #[DataProvider('publishedVersions')]
    public function test_stack_matches_package_json(string $version): void
    {
        $package = json_decode(BuildGuide::file($version, 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
        $m = BuildGuide::manifest($version);

        $this->assertSame($package['dependencies'] + $package['devDependencies'], $m['stack']);
        $this->assertSame($package['overrides'], $m['overrides']);
        $this->assertSame($package['engines']['node'], $m['node']);
    }

    // Alla kataloger på disk, även en olåst version under arbete: manifestet och filerna stämmer alltid.
    public function test_every_file_matches_its_sha256_and_nothing_is_unlisted(): void
    {
        $dirs = glob(resource_path('byggguide').'/v*', GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($dirs);

        foreach ($dirs as $root) {
            $version = basename($root);
            $m = BuildGuide::manifest($version);
            $listed = [];

            foreach ($m['files'] as $entry) {
                $this->assertFalse(str_contains($entry['path'], '..') || str_starts_with($entry['path'], '/'), $entry['path']);
                $this->assertStringStartsWith('files/', $entry['source']);
                $this->assertFileExists("{$root}/{$entry['source']}");
                $this->assertSame($entry['sha256'], hash_file('sha256', "{$root}/{$entry['source']}"), "{$version}: {$entry['path']}");
                $this->assertSame($entry['bytes'], filesize("{$root}/{$entry['source']}"), "{$version}: {$entry['path']}");
                $listed[] = $entry['source'];
            }

            $readme = $m['server_templates']['readme'];
            $this->assertSame($readme['sha256'], hash_file('sha256', "{$root}/{$readme['source']}"), "{$version}: README-mallen");
            $listed[] = $readme['source'];

            $onDisk = [];
            foreach ((new Finder)->files()->in($root)->ignoreDotFiles(false)->notName('manifest.json') as $file) {
                $onDisk[] = str_replace('\\', '/', $file->getRelativePathname());
            }
            sort($listed);
            sort($onDisk);
            $this->assertSame($listed, $onDisk, "{$version}: filer på disk och i manifestet skiljer sig");
            $this->assertSame(count($m['files']), count(array_unique(array_column($m['files'], 'path'))), "{$version}: dubblerad path");
        }
    }

    #[DataProvider('publishedVersions')]
    public function test_required_files_are_in_the_guide_and_readme_is_not(string $version): void
    {
        $paths = array_column(BuildGuide::manifest($version)['files'], 'path');

        foreach (['package.json', 'package-lock.json', '.gitignore', '.env.example', 'next.config.ts', 'tsconfig.json', 'eslint.config.mjs', 'vitest.config.ts', 'postcss.config.mjs', 'scripts/check-export.mjs', 'CLAUDE.md', 'test/docs.test.ts', 'test/fakeFetch.ts', 'src/lib/computercat/index.ts', 'src/lib/computercat/auth.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
        // README skrivs av create_game ur mallen; spelets Claude skriver aldrig över den.
        $this->assertNotContains('README.md', $paths);
    }

    #[DataProvider('publishedVersions')]
    public function test_claude_md_tells_the_game_claude_to_use_the_guide(string $version): void
    {
        $md = BuildGuide::claudeMd($version);

        foreach (["Byggguide: **{$version}**", "{\"version\": \"{$version}\"}", "`Grundkod från byggguide {$version}`", '`get_build_guide`', '`get_build_guide_file`', 'sha256sum', 'npm run check', 'npm audit --omit=dev --audit-level=high', 'Bygg aldrig en egen backend', 'Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertStringNotContainsString('HumblebragAB/spelmall', $md);
    }

    #[DataProvider('publishedVersions')]
    public function test_readme_inserts_the_name_safely_and_keeps_one_pair_of_markers(string $version): void
    {
        $readme = BuildGuide::readme($version, "Katt<!-- x -->\nsvep");

        $this->assertStringStartsWith("# Katt&lt;!-- x --&gt; svep\n", $readme);
        $this->assertStringContainsString("byggguide {$version}", $readme);
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:start -->'));
        $this->assertSame(1, substr_count($readme, '<!-- computercat:idea:end -->'));
        $this->assertStringNotContainsString('{{name}}', $readme);
    }

    #[DataProvider('publishedVersions')]
    public function test_public_manifest_hides_sources_and_marks_large_files_for_download(string $version): void
    {
        $public = BuildGuide::publicManifest($version);
        $files = collect($public['files'])->keyBy('path');

        $this->assertSame('download', $files['package-lock.json']['delivery']);
        $this->assertSame('mcp', $files['package.json']['delivery']);
        $this->assertStringStartsWith('https://', $files['package-lock.json']['download_url']);
        $this->assertStringEndsWith("/api/v1/build-guide/{$version}/files/package-lock.json", $files['package-lock.json']['download_url']);
        $this->assertNull($files['.gitignore']['download_url']);
        $this->assertArrayNotHasKey('source', $files['package.json']);
        $this->assertArrayNotHasKey('server_templates', $public);
    }

    public function test_paths_outside_the_manifest_and_unknown_versions_are_refused(): void
    {
        foreach (['../../.env', '/etc/passwd', 'README.md', 'files/package.json'] as $path) {
            try {
                BuildGuide::file('v1', $path);
                $this->fail("Släpptes igenom: {$path}");
            } catch (BuildGuideNotFound) {
                // väntat
            }
        }

        foreach (['v0', 'v9', '../v1', 'v1/..', ''] as $version) {
            try {
                BuildGuide::manifest($version);
                $this->fail("Släpptes igenom: {$version}");
            } catch (BuildGuideNotFound) {
                // väntat
            }
            $this->assertFalse(BuildGuide::isPublished($version), $version);
        }
    }

    public function test_tampered_file_fails_loudly_instead_of_being_served(): void
    {
        $root = $this->tempGuide();
        file_put_contents("{$root}/v1/files/next.config.ts", "export default {};\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stämmer inte med manifestets sha256');
        BuildGuide::file('v1', 'next.config.ts');
    }

    // En publicerad version ändras aldrig (spec 2c). Ändras manifest.json för
    // en version i FROZEN fäller testet; en ändring ska bli en ny version.
    public function test_published_versions_are_frozen(): void
    {
        foreach (BuildGuide::versions() as $version) {
            $this->assertNotSame('', BuildGuide::FROZEN[$version] ?? '', "{$version} är inte låst i BuildGuide::FROZEN");
            $this->assertSame(BuildGuide::FROZEN[$version], hash_file('sha256', BuildGuide::root($version).'/manifest.json'), "{$version} har ändrats efter publicering");
        }
    }

    // Spec delprojekt 3: en katalog utan FROZEN-post syns inte, men hash och verify läser den.
    public function test_unpublished_version_is_hidden_but_readable_by_the_commands(): void
    {
        $this->unpublishedCopy('v99');

        $this->assertNotContains('v99', BuildGuide::versions());
        $this->assertFalse(BuildGuide::isPublished('v99'));
        $this->assertSame('v99', BuildGuide::manifest('v99')['version']);
        $this->assertSame(BuildGuide::file('v1', 'next.config.ts')['content'], BuildGuide::file('v99', 'next.config.ts')['content']);
    }

    public function test_hash_command_refuses_a_frozen_version_and_hashes_an_unpublished_one(): void
    {
        $this->artisan('computercat:byggguide:hash v1')->expectsOutputToContain('är publicerad')->assertExitCode(1);

        $root = $this->unpublishedCopy('v99');
        $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
        $manifest['files'] = [];
        file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
        file_put_contents("{$root}/v99/files/next.config.ts", "export default {};\n");

        $this->artisan('computercat:byggguide:hash v99')->assertExitCode(0);

        $this->assertSame(hash('sha256', "export default {};\n"), BuildGuide::file('v99', 'next.config.ts')['sha256']);
        $this->assertContains('.gitignore', array_column(BuildGuide::manifest('v99')['files'], 'path'));
    }

    // En symbolisk länk i guiden kan peka var som helst på servern; den
    // serveras aldrig, inte ens när innehållet råkar stämma med sha256.
    public function test_symlinked_file_is_refused_even_with_matching_sha256(): void
    {
        $root = $this->tempGuide();
        $target = "{$root}/utanfor-next.config.ts";
        copy("{$root}/v1/files/next.config.ts", $target);
        unlink("{$root}/v1/files/next.config.ts");
        symlink($target, "{$root}/v1/files/next.config.ts");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolisk länk');
        BuildGuide::file('v1', 'next.config.ts');
    }

    public function test_hash_command_refuses_symlinks(): void
    {
        $root = $this->unpublishedCopy('v99');
        symlink("{$root}/v99/files/package.json", "{$root}/v99/files/lank.json");

        $this->artisan('computercat:byggguide:hash v99')->expectsOutputToContain('symbolisk länk')->assertExitCode(1);
    }

    public function test_download_url_requires_an_https_app_url(): void
    {
        config(['app.url' => 'http://computercat.test']);
        $this->assertSame('https://computercat.test/api/v1/build-guide/v1/files/package-lock.json', BuildGuide::downloadUrl('v1', 'package-lock.json'));

        foreach (['', 'computercat.test', 'ftp://computercat.test', 'https://'] as $url) {
            config(['app.url' => $url]);
            try {
                BuildGuide::downloadUrl('v1', 'package-lock.json');
                $this->fail("Släpptes igenom: '{$url}'");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('APP_URL', $e->getMessage());
            }
        }
    }

    // Forges nginx nekar sökvägar med /. (kommentaren i publicManifest): en
    // dotfil måste alltid gå inline via MCP och får därför aldrig bli för stor.
    public function test_dotfiles_are_always_delivered_inline(): void
    {
        foreach (BuildGuide::versions() as $version) {
            foreach (BuildGuide::publicManifest($version)['files'] as $file) {
                $dotted = collect(explode('/', $file['path']))->contains(fn (string $segment) => str_starts_with($segment, '.'));
                if (! $dotted) {
                    continue;
                }
                $this->assertLessThanOrEqual(BuildGuide::INLINE_MAX_BYTES, $file['bytes'], $file['path']);
                $this->assertSame('mcp', $file['delivery'], $file['path']);
                $this->assertNull($file['download_url'], $file['path']);
            }
        }
    }

    // Installationsskript körs aldrig: varken i verify, av spelets Claude eller
    // i Demo-bygget. Varje installationskommando i guidens texter har flaggan.
    #[DataProvider('publishedVersions')]
    public function test_every_install_command_in_the_guide_ignores_scripts(string $version): void
    {
        $texts = [
            'manifest' => json_encode(BuildGuide::manifest($version), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'CLAUDE.md' => BuildGuide::claudeMd($version),
            'README' => BuildGuide::readme($version, 'Spel'),
        ];

        foreach ($texts as $name => $text) {
            $this->assertStringContainsString('npm ci --ignore-scripts', $text, $name);
            $this->assertDoesNotMatchRegularExpression('/npm ci(?! --ignore-scripts)/', $text, $name);
        }
    }
}
```

- [ ] **Steg 2: Tester för REST och verktyg.** I `tests/Feature/Api/V1/BuildGuideApiTest.php`: lägg till `use Illuminate\Support\Facades\File;` och `use Illuminate\Support\Str;` bland importerna, ersätt i `test_manifest_is_public_and_cacheable` raderna

```php
        $this->assertSame('v1', $response->json('data.version'));
        $this->assertSame(BuildGuide::publicManifest('v1')['files'], $response->json('data.files'));
```

med

```php
        $this->assertSame(BuildGuide::LATEST, $response->json('data.version'));
        $this->assertSame(BuildGuide::publicManifest(BuildGuide::LATEST)['files'], $response->json('data.files'));
        $this->assertSame(BuildGuide::versions(), $response->json('data.available_versions'));
```

och lägg till testet:

```php
    // Spec delprojekt 3: en version utan FROZEN-post syns inte och serveras inte.
    public function test_unpublished_version_is_not_listed_or_served(): void
    {
        $root = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $root);
        try {
            File::copyDirectory("{$root}/v1", "{$root}/v99");
            $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
            $manifest['version'] = 'v99';
            file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
            config(['byggguide.path' => $root]);

            $this->assertSame('v99', BuildGuide::manifest('v99')['version']);
            $this->getJson('/api/v1/build-guide/v99')->assertNotFound()->assertJsonPath('message', 'Build guide version not found.');
            $this->getJson('/api/v1/build-guide/v99/files/package.json')->assertNotFound()->assertJsonPath('message', 'Build guide file not found.');
            $this->assertNotContains('v99', $this->getJson('/api/v1/build-guide')->json('data.available_versions'));
        } finally {
            // Bara katalogen som testet skapade.
            File::deleteDirectory($root);
        }
    }
```

I `tests/Feature/Mcp/BuildGuideToolsTest.php`: lägg till `use Illuminate\Support\Facades\File;` och `use Illuminate\Support\Str;`. Ersätt `$this->assertSame('v1', $json['version']);` med `$this->assertSame(BuildGuide::LATEST, $json['version']);`, `$this->assertSame(['v1'], $json['available_versions']);` med `$this->assertSame(BuildGuide::versions(), $json['available_versions']);`, `$this->assertStringEndsWith('/api/v1/build-guide/v1/files/package-lock.json', $json['download_url']);` med `$this->assertStringEndsWith('/api/v1/build-guide/'.BuildGuide::LATEST.'/files/package-lock.json', $json['download_url']);`, och `foreach (BuildGuide::manifest('v1')['files'] as $entry) {` med `foreach (BuildGuide::manifest(BuildGuide::LATEST)['files'] as $entry) {`. Lägg till:

```php
    public function test_unpublished_version_is_unknown(): void
    {
        $root = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $root);
        try {
            File::copyDirectory("{$root}/v1", "{$root}/v99");
            $manifest = json_decode(file_get_contents("{$root}/v99/manifest.json"), true);
            $manifest['version'] = 'v99';
            file_put_contents("{$root}/v99/manifest.json", json_encode($manifest));
            config(['byggguide.path' => $root]);
            $staff = $this->staffMember();

            $guide = $this->callTool($staff, 'get_build_guide', ['version' => 'v99']);
            $file = $this->callTool($staff, 'get_build_guide_file', ['path' => 'package.json', 'version' => 'v99']);

            $this->assertFalse($guide['ok']);
            $this->assertStringContainsString('Unknown build guide version', $guide['text']);
            $this->assertFalse($file['ok']);
            $this->assertStringContainsString('Unknown build guide version', $file['text']);
            $this->assertStringNotContainsString('v99', json_encode($this->callTool($staff, 'get_build_guide')));
        } finally {
            File::deleteDirectory($root);
        }
    }
```

- [ ] **Steg 3: Kör testerna och se dem falla.**

Run: `cd $WT && php artisan test --filter='BuildGuideTest|BuildGuideApiTest|BuildGuideToolsTest'`
Expected: FAIL: `Call to undefined method App\Services\BuildGuide::isPublished()` och de två nya testerna för olåsta versioner (REST svarar 200, verktyget svarar ok).

- [ ] **Steg 4: `BuildGuide`.** I `app/Services/BuildGuide.php`, ersätt `versions()` med:

```php
    // Bara låsta versioner syns (spec delprojekt 3, "Versioner"). En katalog under
    // arbete läses av hash och verify men visas och serveras aldrig.
    /** @return list<string> */
    public static function versions(): array
    {
        $versions = [];
        foreach (glob(self::base().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (self::isPublished(basename($dir))) {
                $versions[] = basename($dir);
            }
        }
        usort($versions, fn (string $a, string $b) => (int) substr($a, 1) <=> (int) substr($b, 1));

        return $versions;
    }

    public static function isPublished(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1
            && (self::FROZEN[$version] ?? '') !== ''
            && is_file(self::base()."/{$version}/manifest.json");
    }
```

- [ ] **Steg 5: REST och verktyg nekar olåsta versioner.** I `BuildGuideController`:

```php
    public function show(?string $version = null): JsonResponse
    {
        $version ??= BuildGuide::LATEST;
        if (! BuildGuide::isPublished($version)) {
            return response()->json(['message' => 'Build guide version not found.'], 404);
        }

        try {
            $guide = BuildGuide::publicManifest($version);
        } catch (BuildGuideNotFound) {
            return response()->json(['message' => 'Build guide version not found.'], 404);
        }

        return response()->json(['data' => $guide + ['available_versions' => BuildGuide::versions()]])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function file(string $version, string $path): Response|JsonResponse
    {
        if (! BuildGuide::isPublished($version)) {
            return response()->json(['message' => 'Build guide file not found.'], 404);
        }

        try {
            $file = BuildGuide::file($version, $path);
        } catch (BuildGuideNotFound) {
            return response()->json(['message' => 'Build guide file not found.'], 404);
        }
```

(resten av `file()` oförändrad). I `GetBuildGuideTool::run`, ersätt `try`-blocket med:

```php
        $version = $args['version'] ?? BuildGuide::LATEST;
        if (! BuildGuide::isPublished($version)) {
            return Response::error('Unknown build guide version. Available: '.implode(', ', BuildGuide::versions()).'.');
        }

        try {
            $guide = BuildGuide::publicManifest($version);
        } catch (BuildGuideNotFound) {
            return Response::error('Unknown build guide version. Available: '.implode(', ', BuildGuide::versions()).'.');
        }
```

I `GetBuildGuideFileTool::run`, direkt efter `$version = $args['version'] ?? BuildGuide::LATEST;`:

```php
        if (! BuildGuide::isPublished($version)) {
            return Response::error('Unknown build guide version. Available: '.implode(', ', BuildGuide::versions()).'.');
        }
```

- [ ] **Steg 6: Kör testerna.**

Run: `cd $WT && php artisan test --filter='BuildGuide|FactBank|FactTools|RepoStepTest'`
Expected: PASS (FactBank validerar `build_guide` mot `versions()`, som fortfarande är `['v1']`).

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Services/BuildGuide.php app/Http/Controllers/Api/V1/BuildGuideController.php app/Mcp/Tools/GetBuildGuideTool.php app/Mcp/Tools/GetBuildGuideFileTool.php tests/Feature/BuildGuideTest.php tests/Feature/Api/V1/BuildGuideApiTest.php tests/Feature/Mcp/BuildGuideToolsTest.php && git commit -m "Byggguide: bara låsta versioner syns och serveras, tester parametriserade över låsta versioner"
```

---
### Task 3: v2-katalogen: kopia av v1, v2-texter, Faktabanken och testskriptet

**Files:**
- Create: `resources/byggguide/v2/` (kopia av `resources/byggguide/v1/`)
- Modify (i v2): `manifest.json`, `files/CLAUDE.md` (ersätts helt), `templates/README.md`, `files/test/docs.test.ts`, `files/package.json`
- Create: `tests/Feature/BuildGuideV2Test.php`

**Interfaces:**
- Consumes: `BuildGuide::manifest/file/claudeMd/readme` för en olåst version (Task 2).
- Produces: en olåst, hashad v2 som `computercat:byggguide:verify v2` godkänner. `BuildGuideV2Test` (konstanten `V1_REFERENCE`) som Task 4–6 bygger ut. `CLAUDE.md` v2 beskriver redan säkerhetsspärren som Task 4–6 och 9 bygger; v2 publiceras inte förrän Task 8.

- [ ] **Steg 1: Fallerande test.** Skapa `tests/Feature/BuildGuideV2Test.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use Tests\TestCase;

// Byggguide v2 (spec delprojekt 3, del 3, "Innehåll i v2 jämfört med v1"). Läser katalogen
// även innan v2 är låst; BuildGuide::manifest kräver ingen låsning.
class BuildGuideV2Test extends TestCase
{
    // En hänvisning till v1 i v2:s texter (adresser som /api/v1 är inte versionen).
    private const V1_REFERENCE = '/byggguide v1|\*\*v1\*\*|"v1"/i';

    private function package(): array
    {
        return json_decode(BuildGuide::file('v2', 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_claude_md_is_v2_throughout_and_has_the_fact_bank_section(): void
    {
        $md = BuildGuide::claudeMd('v2');

        foreach (['Byggguide: **v2**', '{"version": "v2"}', '`Grundkod från byggguide v2`', '## Faktabanken', '`search_facts`', '`get_fact`', '`list_facts`', '"build_guide": "v2"', 'går före din egen bedömning', 'fråga en människa', '## Säkerhetsspärren i Coolify', '`scripts/cc-gate.mjs`', 'npm-registret svarade inte', 'inte avsiktligt skadlig spelkod', 'bara i utvecklingsberoenden'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, $md);
    }

    public function test_readme_template_names_v2(): void
    {
        $readme = BuildGuide::readme('v2', 'Spel');

        $this->assertStringContainsString('enligt Computercats byggguide v2', $readme);
        $this->assertStringContainsString('scripts/cc-gate.mjs', $readme);
        $this->assertStringContainsString('inte avsiktligt skadlig spelkod', $readme);
        $this->assertStringContainsString('bara i utvecklingsberoenden', $readme);
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, $readme);
    }

    public function test_manifest_is_v2_and_commits_as_v2(): void
    {
        $m = BuildGuide::manifest('v2');

        $this->assertSame('v2', $m['version']);
        $this->assertStringContainsString('Grundkod från byggguide v2', implode("\n", $m['steps']));
        $this->assertDoesNotMatchRegularExpression(self::V1_REFERENCE, json_encode($m['steps'], JSON_UNESCAPED_UNICODE));
    }

    // Spec punkt 4: testerna körs aldrig med React i produktionsläge.
    public function test_tests_run_with_node_env_test(): void
    {
        $this->assertSame('NODE_ENV=test vitest run', $this->package()['scripts']['test']);
    }

    public function test_docs_test_guards_the_fact_bank_and_the_security_scripts(): void
    {
        $docs = BuildGuide::file('v2', 'test/docs.test.ts')['content'];

        foreach (["'search_facts'", "'get_fact'", "'list_facts'", "'\"build_guide\": \"v2\"'", "'scripts/cc-gate.mjs'"] as $text) {
            $this->assertStringContainsString($text, $docs);
        }
    }
}
```

Run: `cd $WT && php artisan test --filter=BuildGuideV2Test`
Expected: FAIL med `Unknown build guide version.` (v2 finns inte).

- [ ] **Steg 2: Kopiera v1 och skriv v2-manifestets fasta fält.**

```bash
cd $WT/resources/byggguide && cp -R v1 v2 && cd v2 && jq '
  .version = "v2"
  | .source = "byggguide v1 plus säkerhetsspärren i bygget (delprojekt 3 etapp B)"
  | .steps = [.steps[] | sub("byggguide v1"; "byggguide v2")]
  | .server_templates = {}
  | .files = []' manifest.json > manifest.tmp && mv manifest.tmp manifest.json
jq -r '.version, .steps[3]' manifest.json
git -C $WT status --short resources/byggguide/v1     # förväntat: ingen utskrift
```

Förväntat: `v2` och `Commit as "Grundkod från byggguide v2" and push directly to main. Coolify builds the Demo on that push.`

- [ ] **Steg 3: `CLAUDE.md` för v2.** Ersätt `resources/byggguide/v2/files/CLAUDE.md` helt med:

````markdown
# CLAUDE.md

Det här är ett Computercat-spel. Spelets idé står under "Idé" i `README.md`; läs den först.

Byggguide: **v2**. Grundkoden (Computercat-klienten, konfiguration, tester, säkerhetskontroller och ett exempelspel) kommer från Computercats byggguide. Det finns inget mallrepo.

## Steg 1: grundkoden (bara när `package.json` saknas)

Ett nytt spelrepo innehåller bara `README.md` och den här filen. Gör så här innan något annat:

1. Anropa `get_build_guide` i Computercat-connectorn med `{"version": "v2"}`. Svaret listar stacken med exakta versioner, installationskommandot, de obligatoriska kontrollerna och varje fil med `path`, `sha256` och `delivery`.
2. Filer med `delivery: "mcp"`: hämta med `get_build_guide_file` (`{"path": "<path>", "version": "v2"}`) och skriv `content` **exakt** till `path` i repots rot. Filer med `delivery: "download"` (bland annat `package-lock.json`): `curl -fsS "<download_url>" -o "<path>"`.
3. Kontrollera varje fil mot manifestet med `sha256sum <path>` (eller `shasum -a 256 <path>`). Avviker en fil: hämta den igen. Ändra den aldrig för hand.
4. `README.md` finns inte i fillistan; låt den vara. `CLAUDE.md` finns i listan med samma innehåll som den här filen.
5. Kör `npm ci --ignore-scripts`, sedan `npm run check` och `npm audit --omit=dev --audit-level=high`. Alla ska gå igenom utan att du ändrat något.
6. Committa allt med meddelandet `Grundkod från byggguide v2` och pusha direkt till `main` (ingen annan gren). Coolify bygger då Demo för första gången. Följ upp med `get_provisioning_status` tills `demo.state` är `live`; Demo visar då exemplet (klickrace).
7. Bygg sedan spelet enligt idén och reglerna nedan.

Faller ett steg (verktyget svarar med fel, en kontroll blir röd utan att du ändrat något, `sha256` stämmer inte efter ny hämtning): stanna och beskriv felet. Laga aldrig byggguidens filer för att få det grönt; felet hör hemma i computercat-api.

## Regler

- **Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.** All kommunikation går genom `src/lib/computercat/`.
- **Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg** (`get_game`, `upsert_*`). **Hämta integrationsmanualen med `get_integration_guide`** innan du använder en byggsten du inte använt förut.
- **Fråga faktabanken innan du bestämmer något om teknik, tester eller säkerhet** (se "Faktabanken" nedan).
- **Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.** Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
- **Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.**
- **Klientkoden i `src/lib/computercat/` och skripten i `scripts/` kommer från byggguiden.** Ändra dem inte i spelet; behövs en ändring hör den hemma i en ny byggguideversion i computercat-api.

## Faktabanken

Computercat har en faktabank om hur vi bygger spel: val av teknik (det som redan finns i stacken går före nytt), vilka tester ett spel ska ha, säkerhetskontrollerna före Demo, best practice och vägen från idé till Live.

- **När du frågar:** innan du väljer ett bibliotek eller lägger till ett beroende, innan du skriver tester, innan du sätter upp topplistor eller anti-cheat, när ett bygge eller en kontroll faller, och före `promote_release`.
- **Hur:** `search_facts` med `{"query": "<några ord>", "build_guide": "v2"}` och läs träffen med `get_fact` (`{"id": "<id>"}`). `list_facts` med `{"build_guide": "v2"}` visar allt som finns. Skicka alltid med `"build_guide": "v2"`, så får du bara artiklar som gäller det här spelet.
- **En artikel går före din egen bedömning.** Följ dess regel även om du hade valt annorlunda.
- **Ett stort beslut som ingen artikel täcker** (ny tjänst, ny arkitektur, ett beroende med stor påverkan, något som rör säkerhet, personuppgifter eller pengar): fråga en människa innan du bygger.

## Köra och testa lokalt

```bash
npm ci --ignore-scripts      # installationsskript körs aldrig
cp .env.example .env.local    # NEXT_PUBLIC_COMPUTERCAT_GAME=<spelets slug>
npm run dev                    # http://localhost:3000 (localhost är tillåtet i Computercats CORS)
npm test                       # enhetstester, inget nätverk
npm run check                  # typkontroll, lint, test, bygge, kontroll av out/ och säkerhetskontrollen
```

**Före varje push till `main`:** (1) `npm run check` grön, (2) `npm audit --omit=dev --audit-level=high` utan träff (avslutar med fel vid high eller critical i produktionsberoenden; åtgärda med `npm audit fix` eller uppdatera paketet, tysta aldrig). Bygget är en statisk export (`output: 'export'`): inga API-routes, inga serverkomponenter som hämtar data, ingen `next/font/google` (bygget ska klara sig utan nätverk). `npm audit` utan `--omit=dev` visar en high i braces via eslint; den finns bara i utvecklingsberoenden, ingen rättad version finns och fyndet är accepterat. Produktionsgranskningen (`npm audit --omit=dev --audit-level=high`) är ren, och det är den som spärren kör.

## Säkerhetsspärren i Coolify

Coolify bygger Demo med ett byggkommando som Computercat äger: `npm run build`, sedan hämtas `scripts/cc-gate.mjs` över https från den låsta byggguiden i Computercat och körs. Faller en kontroll deployas inget, och Demo visar förra bygget. Att ta bort eller ändra skripten eller `check` stänger inte av spärren; spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod (`npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön).

- `scripts/cc-gate.mjs` fäller först på hemligheter i `out/` (privata nycklar, GitHub-, Slack-, Stripe- och AWS-nycklar, JWT), på source maps (`*.map`), på dolda filer (`.env*`, `.git` med flera) och på HTML-sidor utan byggguidens Content-Security-Policy. Policyn sätts i `src/app/layout.tsx` med `src/lib/computercat/csp.ts` (bara i produktionsbygget, så `npm run dev` påverkas inte); ändra den inte. Felet anger fil och mönstrets namn, aldrig värdet.
- Därefter kör `scripts/cc-gate.mjs` `npm audit --omit=dev --audit-level=high` och fäller med paketnamn och allvarlighetsgrad vid high eller critical i produktionsberoenden. Svarar inte npm-registret försöker skriptet en gång till efter 10 sekunder och fäller sedan med "npm-registret svarade inte": vänta och pusha igen.
- `npm run check` kör samma `scripts/cc-gate.mjs`, så du får samma fel lokalt som Coolify.
- Visar `get_provisioning_status` `deploy_failed`: kör `npm run check` lokalt, slå upp felet med `search_facts` och pusha igen.

Lint förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med en sträng och `process.env.NEXT_PUBLIC_*` utöver `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`. Stäng aldrig av en regel. Behövs något av det: fråga faktabanken och sedan en människa.

## Computercat i koden

- `getComputercat()` ger klienten i webbläsaren. Den kastar aldrig: saknas `NEXT_PUBLIC_COMPUTERCAT_GAME` (eller körs den utanför webbläsaren) returnerar den `{ ok: false, error }`, och det felet ska visas för spelaren.
- Spelaren (token) förnyas automatiskt vid start om den är äldre än 24 h (tokens gäller 90 dagar); du behöver inte göra något. `recordScore` kastar om resultatet inte kan sparas lokalt (full lagring): visa det för spelaren.
- Resultat: `recordScore(typ, score, metadata?)` köar lokalt och skickar i bakgrunden. Visa `flushed`-resultatet för spelaren; dölj aldrig fel och visa aldrig påhittad topplistedata.
- Topplistor: `leaderboards.get(typ)` (aktuell period enligt servern, UTC), `leaderboards.me(typ)`.
- Sparningar: `saves.sync(nyckel, lokalt, merge)` hämtar, slår ihop och skickar med version.
- Anti-cheat: `createProof(...)` i `metadata.proof` om spelet kräver bevis.

## Exemplets topplista

Exemplet skickar till `daily-clicks`. Skapa den innan du testar på Demo:

`upsert_leaderboard_type` med `{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}`

När exemplet ersätts: skapa spelets egna topplistor på samma sätt, byt `LEADERBOARD_TYPE` i `src/game/constants.ts` och ta bort `src/game/exampleDocs.test.ts` tillsammans med exemplet. Byt också `title` i `src/app/layout.tsx`, `name` i `package.json` (kör sedan `npm install --package-lock-only`) och lagringsnyckeln `spelmall-click` (prefixet i `src/game/clickStore.ts`) till spelets namn.

Låt de två raderna `computercat:idea` i `README.md` vara kvar; `test/docs.test.ts` vaktar dem.

## Säkerhet

Allt i `NEXT_PUBLIC_*` hamnar i den publika koden. Lägg aldrig nycklar eller lösenord där eller i repot.
````

- [ ] **Steg 4: README-mallen.** I `resources/byggguide/v2/templates/README.md`, fyra ändringar:

1. Ersätt `enligt Computercats byggguide v1.` med `enligt Computercats byggguide v2.`
2. Ersätt raden `npm run check   # typkontroll, lint, test, bygge och kontroll av out/` med `npm run check   # typkontroll, lint, test, bygge, kontroll av out/ och säkerhetskontrollen`
3. Efter stycket som slutar med `Status följs med Computercat-verktyget `get_provisioning_status`.` (under `## Deploy`), lägg till ett nytt stycke:

```markdown

Coolify bygger med Computercats eget byggkommando: `npm run build`, sedan spärren `scripts/cc-gate.mjs`: säkerhetskontrollen (hemligheter, source maps, dolda filer och Content-Security-Policy i varje HTML-sida) och `npm audit` för produktionsberoenden. Skriptet hämtas över https från den låsta byggguiden i Computercat. Faller en kontroll deployas inget och Demo visar förra bygget. Att ta bort eller ändra skripten eller `check` stänger inte av spärren; spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod (`npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön).
```

4. Under `## Beroenden: `overrides` för vite`, efter stycket som slutar med `när vitest har ett vite-intervall som installeras rent.`, lägg till ett nytt stycke:

```markdown

`npm audit` utan `--omit=dev` visar en high i braces via eslint; den finns bara i utvecklingsberoenden, ingen rättad version finns och fyndet är accepterat. Produktionsgranskningen (`npm audit --omit=dev --audit-level=high`) är ren, och det är den som spärren kör.
```

- [ ] **Steg 5: `test/docs.test.ts` och `package.json`.** I `resources/byggguide/v2/files/test/docs.test.ts`, i listan i testet "CLAUDE.md innehåller specens regler och verktyg", ersätt raden

```ts
      'npm audit --omit=dev --audit-level=high',
```

med

```ts
      'npm audit --omit=dev --audit-level=high',
      'search_facts',
      'get_fact',
      'list_facts',
      '"build_guide": "v2"',
      'scripts/cc-gate.mjs',
```

Sätt testskriptet (jq behåller nyckelordningen och två blankstegs indrag):

```bash
cd $WT/resources/byggguide/v2/files && jq '.scripts.test = "NODE_ENV=test vitest run"' package.json > package.tmp && mv package.tmp package.json && jq -r .scripts.test package.json
```

Förväntat: `NODE_ENV=test vitest run`.

- [ ] **Steg 6: Hasha och kör testerna.**

```bash
cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter='BuildGuideV2Test|BuildGuideTest'
```

Förväntat: `48 filer i v2.` och PASS. (`test_every_file_matches_its_sha256_and_nothing_is_unlisted` går nu också igenom v2.)

- [ ] **Steg 7: Guiden på riktigt.**

Run: `cd $WT && php artisan computercat:byggguide:verify v2`
Expected: sista raden `Byggguide v2: OK (48 filer, check, audit, static_export).` Röd kontroll: rätta i `resources/byggguide/v2`, kör steg 6 och 7 igen.

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add resources/byggguide/v2 tests/Feature/BuildGuideV2Test.php && git commit -m "Byggguide v2 (olåst): kopia av v1 med v2-texter, Faktabanken och NODE_ENV=test i testskriptet"
```

---

### Task 4: CSP i layouten och spärrskriptet `scripts/cc-gate.mjs` (säkerhetsdelen)

Först: invokera `security-first-mindset` och skriv hotmodellen för skriptet och byggkommandot (vad som skyddas: att en hemlighet, source map eller sida utan CSP publiceras på Demo; vem som kan påverka: spelets kod och spelets Claude, inte Computercat; gränsen (R12): Att ta bort eller ändra skripten eller `check` stänger inte av spärren; spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod (`npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön). Spike A (S13) visade att byggkatalogen `/app` saknar `.git/config`, så Coolifys GitHub-token går inte att läsa där.)

**Files:**
- Create (i v2): `files/src/lib/computercat/csp.ts`, `files/src/lib/computercat/csp.test.ts`, `files/scripts/cc-gate.mjs` (Task 5 lägger npm audit sist i samma fil), `files/test/security.test.ts`
- Modify (i v2): `files/src/app/layout.tsx`, `files/package.json` (`check`)
- Modify: `tests/Feature/BuildGuideV2Test.php`

**Interfaces:**
- Produces: `contentSecurityPolicy(apiUrl: string | undefined): string` i `@/lib/computercat/csp` (kastar på en ogiltig adress). `node scripts/cc-gate.mjs` (cwd = repots rot; R14: ett enda skript som Coolify kör och som `npm run check` kör), säkerhetsdelen: exitkod 0 och `check-security: OK (N filer, M HTML-sidor med rätt CSP)`, annars exitkod 1 och en rad per fynd på formen `check-security: out/<sökväg>: <fynd>` där `<fynd>` är `hemlighet (<namn>)`, `source map (*.map publiceras inte)`, `CSP saknas (<meta http-equiv="Content-Security-Policy">)`, `mer än en CSP-tagg`, `CSP avviker från byggguidens policy`, `symbolisk länk (tillåts inte i out/)` eller `dold fil (.env, .git m.fl. publiceras inte)` (varje namn som börjar med punkt, utom katalogen `.well-known`); saknad `out/` ger `check-security: out/ saknas. Kör npm run build först.` Vid fynd avslutar skriptet innan audit-delen (Task 5). Task 7 och 9 använder filen.

- [ ] **Steg 1: Fallerande tester.** `resources/byggguide/v2/files/src/lib/computercat/csp.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from './csp';

describe('contentSecurityPolicy', () => {
  it('är byggguidens policy med Computercats ursprung som standard', () => {
    expect(contentSecurityPolicy(undefined)).toBe(
      "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' https://api.computercat.co; object-src 'none'; base-uri 'self'; form-action 'self'",
    );
  });

  it('tar ursprunget, inte sökvägen, ur NEXT_PUBLIC_COMPUTERCAT_URL', () => {
    expect(contentSecurityPolicy('http://127.0.0.1:8123/api/v1/')).toContain("connect-src 'self' http://127.0.0.1:8123;");
    expect(contentSecurityPolicy('   ')).toContain("connect-src 'self' https://api.computercat.co;");
  });

  it('kastar på en ogiltig adress, så att bygget faller i stället för att släppa igenom fel policy', () => {
    expect(() => contentSecurityPolicy('inte en adress')).toThrow();
  });
});
```

`resources/byggguide/v2/files/test/security.test.ts`:

```ts
import { spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { afterAll, afterEach, describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from '@/lib/computercat/csp';

// scripts/cc-gate.mjs (byggguide v2), säkerhetsdelen, körs som Coolify kör den: med repots rot som cwd.
const SCRIPT = resolve(process.cwd(), 'scripts/cc-gate.mjs');
const dirs: string[] = [];

// Audit-delen (Task 5) körs efter en godkänd säkerhetsdel; här svarar en falsk npm alltid rent.
const cleanNpm = mkdtempSync(join(tmpdir(), 'cc-gate-npm-'));
writeFileSync(join(cleanNpm, 'npm'), '#!/bin/sh\necho \'{"auditReportVersion":2,"vulnerabilities":{},"metadata":{"vulnerabilities":{}}}\'\n');
chmodSync(join(cleanNpm, 'npm'), 0o755);
afterAll(() => rmSync(cleanNpm, { recursive: true, force: true }));

afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

// React skriver ' som &#x27; i attribut; skriptet ska godkänna det.
const metaTag = (policy: string) => `<meta http-equiv="Content-Security-Policy" content="${policy.replace(/'/g, '&#x27;')}"/>`;
const page = (...policies: string[]) =>
  `<!DOCTYPE html><html lang="sv"><head><meta charSet="utf-8"/>${policies.map(metaTag).join('')}<title>x</title></head><body>ok</body></html>`;
const okPage = page(contentSecurityPolicy(undefined));

function project(files: Record<string, string>): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-security-'));
  dirs.push(dir);
  for (const [path, content] of Object.entries(files)) {
    const full = join(dir, path);
    mkdirSync(dirname(full), { recursive: true });
    writeFileSync(full, content);
  }
  return dir;
}

function run(dir: string, env: Record<string, string> = {}) {
  const base: NodeJS.ProcessEnv = { ...process.env };
  delete base.NEXT_PUBLIC_COMPUTERCAT_URL;
  const r = spawnSync(process.execPath, [SCRIPT], { cwd: dir, env: { ...base, PATH: `${cleanNpm}:${process.env.PATH}`, ...env }, encoding: 'utf8' });
  return { status: r.status, output: `${r.stdout}${r.stderr}` };
}

// Planterade värden byggs vid körning, så att GitHubs push protection och gitleaks inte stoppar committen.
const filler = (n: number) => 'A1b2C3d4E5'.repeat(Math.ceil(n / 10)).slice(0, n);
const PLANTED: Array<[string, string]> = [
  ['private-key', '-----BEGIN ' + 'RSA PRIVATE' + ' KEY-----'],
  ['github-token', 'gh' + 'p_' + filler(36)],
  ['github-pat', 'github' + '_pat_' + filler(60)],
  ['slack-token', 'xo' + 'xb-' + filler(24)],
  ['slack-webhook', 'https://hooks.slack.com/services/' + 'T' + 'ABC123' + '/B000/xyz'],
  ['stripe-live-key', 'sk_' + 'live_' + filler(24)],
  ['aws-access-key', 'AK' + 'IA' + 'ABCDEFGHIJKLMNOP'],
  ['jwt', 'ey' + 'J' + filler(24) + '.ey' + 'J' + filler(24) + '.' + filler(24)],
];

describe('cc-gate.mjs: säkerhetsdelen', () => {
  it('godkänner en export med rätt CSP och utan fynd', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/404.html': okPage, 'out/_next/static/a.js': 'console.log(1)' }));
    expect(res.output).toContain('check-security: OK (3 filer, 2 HTML-sidor med rätt CSP)');
    expect(res.status).toBe(0);
  });

  it.each(PLANTED)('fäller på %s med filnamn och mönstrets namn, aldrig värdet', (name, value) => {
    const res = run(project({ 'out/index.html': okPage, 'out/_next/static/chunks/app.js': `const k="${value}";` }));
    expect(res.status).toBe(1);
    expect(res.output).toContain(`check-security: out/_next/static/chunks/app.js: hemlighet (${name})`);
    expect(res.output).not.toContain(value);
  });

  it('kräver ordgräns: en nyckel som sitter ihop med ett ord före är ingen träff', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/a.js': `const k="x${'sk_' + 'live_' + filler(24)}";` }));
    expect(res.status).toBe(0);
  });

  it('fäller på source maps', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/_next/static/a.js.map': '{}' }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/_next/static/a.js.map: source map (*.map publiceras inte)');
  });

  it('fäller på dolda filer som .env och .git men släpper igenom .well-known', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/.env.local': 'X=1', 'out/.git/config': '[core]', 'out/.well-known/security.txt': 'Contact: x' }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/.env.local: dold fil');
    expect(res.output).toContain('check-security: out/.git: dold fil');
    expect(res.output).not.toContain('.well-known');
  });

  it('fäller på en HTML-sida utan CSP', () => {
    const res = run(project({ 'out/index.html': okPage, 'out/sub/index.html': page() }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/sub/index.html: CSP saknas');
  });

  it('fäller på en CSP som avviker och på två CSP-taggar', () => {
    const res = run(project({
      'out/index.html': page(contentSecurityPolicy('https://annan.example/api/v1')),
      'out/dubbel.html': page(contentSecurityPolicy(undefined), contentSecurityPolicy(undefined)),
    }));
    expect(res.status).toBe(1);
    expect(res.output).toContain('check-security: out/index.html: CSP avviker från byggguidens policy');
    expect(res.output).toContain('check-security: out/dubbel.html: mer än en CSP-tagg');
  });

  it('följer NEXT_PUBLIC_COMPUTERCAT_URL i miljön', () => {
    const local = page(contentSecurityPolicy('http://127.0.0.1:8123/api/v1'));
    expect(run(project({ 'out/index.html': local }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'http://127.0.0.1:8123/api/v1' }).status).toBe(0);
    expect(run(project({ 'out/index.html': local })).status).toBe(1);
  });

  it('läser NEXT_PUBLIC_COMPUTERCAT_URL ur .env.local som Next gör', () => {
    const dir = project({
      'out/index.html': page(contentSecurityPolicy('http://127.0.0.1:8123/api/v1')),
      '.env.local': 'NEXT_PUBLIC_COMPUTERCAT_URL="http://127.0.0.1:8123/api/v1"\n',
    });
    expect(run(dir).status).toBe(0);
  });

  it('fäller när out/ saknas eller adressen är ogiltig', () => {
    expect(run(project({ 'README.md': 'x' })).output).toContain('check-security: out/ saknas');
    const res = run(project({ 'out/index.html': okPage }), { NEXT_PUBLIC_COMPUTERCAT_URL: 'inte en adress' });
    expect(res.status).toBe(1);
    expect(res.output).toContain('NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress');
  });
});
```

Lägg till i `tests/Feature/BuildGuideV2Test.php`:

```php
    // Spec punkt 1, 2 och 6: säkerhetsskriptet finns, körs sist i npm run check och testas i guiden.
    public function test_security_script_is_in_the_guide_and_runs_last_in_check(): void
    {
        $paths = array_column(BuildGuide::manifest('v2')['files'], 'path');

        foreach (['scripts/cc-gate.mjs', 'src/lib/computercat/csp.ts', 'src/lib/computercat/csp.test.ts', 'test/security.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
        $this->assertSame(
            'npm run typecheck && npm run lint && npm test && NEXT_TELEMETRY_DISABLED=1 npm run build && node scripts/check-export.mjs && node scripts/cc-gate.mjs',
            $this->package()['scripts']['check'],
        );
        $layout = BuildGuide::file('v2', 'src/app/layout.tsx')['content'];
        $this->assertStringContainsString('contentSecurityPolicy(process.env.NEXT_PUBLIC_COMPUTERCAT_URL)', $layout);
        $this->assertStringContainsString("process.env.NODE_ENV === 'production'", $layout);
    }
```

Run: `cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter=BuildGuideV2Test`
Expected: FAIL i `test_security_script_is_in_the_guide_and_runs_last_in_check` (skriptet och `csp.ts` saknas, `check` är v1:s).

- [ ] **Steg 2: `csp.ts`.** `resources/byggguide/v2/files/src/lib/computercat/csp.ts`:

```ts
import { DEFAULT_BASE_URL } from './config';

// Byggguide v2: Content-Security-Policy för den statiska exporten. Sätts som <meta> i
// src/app/layout.tsx och kontrolleras i varje out/**/*.html av scripts/cc-gate.mjs
// (lokalt i npm run check och i Coolify). test/security.test.ts visar att skriptet godkänner
// exakt den här policyn; en ändring här kräver en ny byggguideversion.
// 'unsafe-inline' för skript krävs av Next.js-exportens inline-skript (self.__next_f.push).
export function contentSecurityPolicy(apiUrl: string | undefined): string {
  // Ogiltig adress: new URL kastar och bygget faller (fail loud).
  const origin = new URL(apiUrl?.trim() || DEFAULT_BASE_URL).origin;
  return [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    `connect-src 'self' ${origin}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ');
}
```

- [ ] **Steg 3: `layout.tsx`.** Ersätt `resources/byggguide/v2/files/src/app/layout.tsx` helt med:

```tsx
import type { Metadata, Viewport } from 'next';
import { BackgroundSync } from '@/components/BackgroundSync';
import { contentSecurityPolicy } from '@/lib/computercat/csp';
import './globals.css';

export const metadata: Metadata = {
  title: 'Spelmall',
  description: 'Computercat-spel byggt enligt Computercats byggguide.',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#f5f0e8',
};

// Byggguide v2: policyn kontrolleras i varje out/**/*.html av scripts/cc-gate.mjs.
// Bara i produktionsbygget: next build sätter alltid NODE_ENV=production, så out/ och
// kontrollen påverkas inte, medan npm run dev slipper policyn (Eriks beslut 2026-10-14).
const CSP = contentSecurityPolicy(process.env.NEXT_PUBLIC_COMPUTERCAT_URL);
const IS_PRODUCTION_BUILD = process.env.NODE_ENV === 'production';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="sv" className="h-full">
      <head>{IS_PRODUCTION_BUILD && <meta httpEquiv="Content-Security-Policy" content={CSP} />}</head>
      <body className="h-full antialiased">
        {children}
        <BackgroundSync />
      </body>
    </html>
  );
}
```

- [ ] **Steg 4: `cc-gate.mjs`, säkerhetsdelen.** `resources/byggguide/v2/files/scripts/cc-gate.mjs` (Task 5 lägger audit-delen sist i samma fil):

```js
// Byggguide v2: spärren (spec delprojekt 3, del 3). Del 1: säkerhetskontroll av den statiska
// exporten i out/. Del 2 (sist i filen): npm audit för produktionsberoenden.
// Körs sist i `npm run check` och i Coolify-bygget, där kommandot hämtar den här filen över
// https från den låsta byggguidens REST (Computercat serverar bara låsta filer och kontrollerar
// deras sha256 innan de skickas); spelrepot kan inte ändra det som körs där. Skriptet importerar
// inget utanför Node, eftersom Coolify kör det fristående från /tmp.
// Skriver aldrig ut ett hittat värde, bara fil och mönstrets namn.
import { existsSync, lstatSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const OUT = 'out';
const URL_NAME = 'NEXT_PUBLIC_COMPUTERCAT_URL';
const DEFAULT_API_URL = 'https://api.computercat.co/api/v1';

// Exakta mönster med ordgränser (specen). Namnet skrivs ut, aldrig träffen.
const SECRETS = [
  ['private-key', /-----BEGIN [A-Z ]*PRIVATE KEY-----/],
  ['github-token', /\bgh[pousr]_[A-Za-z0-9]{36}\b/],
  ['github-pat', /\bgithub_pat_[A-Za-z0-9_]{50,}\b/],
  ['slack-token', /\bxox[abprs]-[A-Za-z0-9-]{10,}\b/],
  ['slack-webhook', /hooks\.slack\.com\/services\/T[A-Z0-9]+\//],
  ['stripe-live-key', /\b[sr]k_live_[A-Za-z0-9]{20,}\b/],
  ['aws-access-key', /\bAKIA[0-9A-Z]{16}\b/],
  ['jwt', /\beyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/],
];

const findings = [];
const report = (file, what) => findings.push(`${file}: ${what}`);

// Samma ordning som Next vid `next build` (och som check-export.mjs): miljön först, sedan filerna.
function fromEnvFiles(name) {
  for (const file of ['.env.production.local', '.env.local', '.env.production', '.env']) {
    if (!existsSync(file)) continue;
    for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(new RegExp(`^\\s*(?:export\\s+)?${name}\\s*=\\s*(.*?)\\s*$`));
      const value = m?.[1].replace(/^(['"])(.*)\1$/, '$2').trim();
      if (value) return value;
    }
  }
  return null;
}

// Samma policy som src/lib/computercat/csp.ts (test/security.test.ts jämför dem). Skriptet kan inte
// importera csp.ts: Coolify kör det fristående.
function expectedPolicy() {
  const raw = process.env[URL_NAME]?.trim() || fromEnvFiles(URL_NAME) || DEFAULT_API_URL;
  let origin;
  try {
    origin = new URL(raw).origin;
  } catch {
    return null;
  }
  return [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    `connect-src 'self' ${origin}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ');
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    const stat = lstatSync(path);
    // .env*, .git och andra dolda filer ska aldrig publiceras; .well-known är en standardkatalog.
    if (name.startsWith('.') && !(name === '.well-known' && stat.isDirectory())) {
      report(path, 'dold fil (.env, .git m.fl. publiceras inte)');
      return [];
    }
    if (stat.isSymbolicLink()) {
      report(path, 'symbolisk länk (tillåts inte i out/)');
      return [];
    }
    return stat.isDirectory() ? walk(path) : [path];
  });
}

const ENTITIES = { quot: '"', amp: '&', apos: "'", lt: '<', gt: '>' };
const decode = (text) =>
  text.replace(/&(?:#x([0-9a-f]+)|#(\d+)|(quot|amp|apos|lt|gt));/gi, (_, hex, dec, name) =>
    hex ? String.fromCodePoint(parseInt(hex, 16)) : dec ? String.fromCodePoint(Number(dec)) : ENTITIES[name.toLowerCase()],
  );

function cspPolicies(html) {
  const policies = [];
  for (const [tag] of html.matchAll(/<meta\b[^>]*>/gi)) {
    const attrs = {};
    for (const [, key, double, single] of tag.matchAll(/([a-zA-Z:-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
      attrs[key.toLowerCase()] = decode(double ?? single ?? '');
    }
    if ((attrs['http-equiv'] ?? '').toLowerCase() === 'content-security-policy') policies.push(attrs.content ?? '');
  }
  return policies;
}

if (!existsSync(OUT) || !lstatSync(OUT).isDirectory()) {
  console.error('check-security: out/ saknas. Kör npm run build först.');
  process.exit(1);
}

const policy = expectedPolicy();
if (policy === null) {
  console.error(`check-security: ${URL_NAME} är ingen giltig adress.`);
  process.exit(1);
}

const files = walk(OUT);
let pages = 0;
for (const file of files) {
  if (file.endsWith('.map')) report(file, 'source map (*.map publiceras inte)');
  // latin1 läser varje byte som ett tecken: mönstren är ASCII och träffar oavsett filens kodning.
  const text = readFileSync(file, 'latin1');
  for (const [name, pattern] of SECRETS) {
    if (pattern.test(text)) report(file, `hemlighet (${name})`);
  }
  if (file.endsWith('.html')) {
    pages++;
    const found = cspPolicies(text);
    if (found.length === 0) report(file, 'CSP saknas (<meta http-equiv="Content-Security-Policy">)');
    else if (found.length > 1) report(file, 'mer än en CSP-tagg');
    else if (found[0] !== policy) report(file, 'CSP avviker från byggguidens policy');
  }
}

if (findings.length > 0) {
  for (const finding of findings) console.error(`check-security: ${finding}`);
  console.error(`check-security: ${findings.length} fynd. Rätta källan (inte out/) och kör npm run check igen.`);
  process.exit(1);
}

console.log(`check-security: OK (${files.length} filer, ${pages} HTML-sidor med rätt CSP)`);
```

- [ ] **Steg 5: `check` i `package.json`.**

```bash
cd $WT/resources/byggguide/v2/files && jq '.scripts.check = "npm run typecheck && npm run lint && npm test && NEXT_TELEMETRY_DISABLED=1 npm run build && node scripts/check-export.mjs && node scripts/cc-gate.mjs"' package.json > package.tmp && mv package.tmp package.json
```

- [ ] **Steg 6: Hasha, PHP-testerna och guiden.**

```bash
cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter='BuildGuideV2Test|BuildGuideTest'
cd $WT && php artisan computercat:byggguide:verify v2
```

Förväntat: `52 filer i v2.`, PASS, och i verify: vitest grön (bland annat `csp.test.ts` 3 och `security.test.ts` 17 tester), `check-export: OK …`, `check-security: OK (<N> filer, <M> HTML-sidor med rätt CSP)` med `M` ≥ 2 (`index.html` och `404.html`) och sista raden `Byggguide v2: OK (52 filer, check, audit, static_export).` Säger `cc-gate.mjs` "CSP saknas" eller "CSP avviker" på den riktiga exporten (Next placerar eller skriver metataggen annorlunda än testerna förutsätter): stanna och fråga, med utdraget ur `out/index.html` (`grep -o '<meta[^>]*Content-Security[^>]*>' out/index.html`, kör `verify v2 --keep` för att få katalogen). Ett skript-fel i lint (till exempel en regel i eslint-config-next som träffar skriptet): läs regeln, rätta skriptet utan att stänga av regeln.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add resources/byggguide/v2 tests/Feature/BuildGuideV2Test.php && git commit -m "Byggguide v2: CSP i layouten och cc-gate.mjs (säkerhetsdelen: hemligheter, source maps, dolda filer och CSP i out/)"
```

---
### Task 5: audit-delen i `cc-gate.mjs`: npm audit som skiljer fynd från registerfel

**Files:**
- Modify (i v2): `files/scripts/cc-gate.mjs` (audit-delen sist i filen)
- Create (i v2): `files/test/ccAudit.test.ts`
- Modify: `tests/Feature/BuildGuideV2Test.php`

**Interfaces:**
- Consumes: formen på `npm audit --json` (R5, bekräftad i bygget av Task 1 S8).
- Produces: `node scripts/cc-gate.mjs` kör audit-delen efter en godkänd säkerhetsdel. npm startas utan `NPM_CONFIG_PRODUCTION` och `npm_config_production` i miljön (spike A S8: nixpacks sätter `NPM_CONFIG_PRODUCTION=false`, och då räknar `--omit=dev` ändå med utvecklingsberoenden). Utfall: exitkod 0 och `cc-audit: OK (inga high eller critical i produktionsberoenden)`; vid fynd exitkod 1 och en rad `cc-audit: <paket> (<high|critical>)` per paket; vid registerfel (nätverkskod eller 5xx, R5) två försök med 10 s emellan och sedan exitkod 1 och `cc-audit: npm-registret svarade inte (två försök). Vänta och pusha igen.`; vid annan npm-felkod eller utdata som inte är JSON direkt exitkod 1 med `cc-audit: npm audit misslyckades (<kod>) …` Miljövariabeln `CC_AUDIT_RETRY_DELAY_MS` kan bara korta väntan (0–10000 ms; används av testerna). Task 7 och 9 använder filen.

- [ ] **Steg 1: Fallerande tester.** `resources/byggguide/v2/files/test/ccAudit.test.ts`:

```ts
import { spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { contentSecurityPolicy } from '@/lib/computercat/csp';

// Audit-delen i scripts/cc-gate.mjs (byggguide v2), i ett projekt vars out/ klarar säkerhetsdelen,
// mot en falsk npm först i PATH. Svarens form är
// npm 10:s (verifierad 2026-10-14): en rapport har auditReportVersion, metadata och
// vulnerabilities; ett fel har message och error, och orsaken står i message.
const SCRIPT = resolve(process.cwd(), 'scripts/cc-gate.mjs');
const dirs: string[] = [];

afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

type Answer = { status: number; stdout: string };
const report = (vulnerabilities: Record<string, unknown>): string =>
  JSON.stringify({ auditReportVersion: 2, vulnerabilities, metadata: { vulnerabilities: {} } });
const vulnerability = (name: string, severity: string) => ({ name, severity, isDirect: true, via: [], range: '*', fixAvailable: false });
const CLEAN: Answer = { status: 0, stdout: report({}) };
const REGISTRY_DOWN: Answer = {
  status: 1,
  stdout: JSON.stringify({ message: 'request to https://registry.npmjs.org/-/npm/v1/security/audits/quick failed, reason: getaddrinfo ENOTFOUND registry.npmjs.org', error: { summary: '', detail: '' } }),
};

// Svar nummer n tas ur answers[n]; det sista upprepas.
function fakeNpm(answers: Answer[]): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-audit-'));
  dirs.push(dir);
  answers.forEach((a, i) => {
    writeFileSync(join(dir, `svar-${i}.json`), a.stdout);
    writeFileSync(join(dir, `kod-${i}`), String(a.status));
  });
  const last = answers.length - 1;
  writeFileSync(
    join(dir, 'npm'),
    `#!/bin/sh
n=$(cat "${dir}/anrop" 2>/dev/null || echo 0)
echo $((n + 1)) > "${dir}/anrop"
echo "$@" >> "${dir}/argument"
echo "\${NPM_CONFIG_PRODUCTION-osatt} \${npm_config_production-osatt}" >> "${dir}/miljo"
i=$n; [ "$i" -gt ${last} ] && i=${last}
cat "${dir}/svar-$i.json"
exit $(cat "${dir}/kod-$i")
`,
  );
  chmodSync(join(dir, 'npm'), 0o755);
  return dir;
}

// Ett projekt vars out/ klarar säkerhetsdelen, så att audit-delen nås.
function project(): string {
  const dir = mkdtempSync(join(tmpdir(), 'cc-gate-project-'));
  dirs.push(dir);
  mkdirSync(join(dir, 'out'));
  const policy = contentSecurityPolicy(undefined).replace(/'/g, '&#x27;');
  writeFileSync(join(dir, 'out/index.html'), `<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="${policy}"/></head><body>ok</body></html>`);
  return dir;
}

function run(answers: Answer[], env: Record<string, string> = {}) {
  const dir = fakeNpm(answers);
  const base: NodeJS.ProcessEnv = { ...process.env };
  delete base.NEXT_PUBLIC_COMPUTERCAT_URL;
  const r = spawnSync(process.execPath, [SCRIPT], {
    cwd: project(),
    env: { ...base, PATH: `${dir}:${process.env.PATH}`, CC_AUDIT_RETRY_DELAY_MS: '0', ...env },
    encoding: 'utf8',
  });
  return {
    status: r.status,
    output: `${r.stdout}${r.stderr}`,
    calls: Number(readFileSync(join(dir, 'anrop'), 'utf8')),
    args: readFileSync(join(dir, 'argument'), 'utf8'),
    env: readFileSync(join(dir, 'miljo'), 'utf8'),
  };
}

describe('cc-gate.mjs: audit-delen', () => {
  // Spike A (S8): nixpacks sätter NPM_CONFIG_PRODUCTION=false, och då tar --omit=dev med dev-beroenden.
  it('startar npm utan NPM_CONFIG_PRODUCTION och npm_config_production', () => {
    const res = run([CLEAN], { NPM_CONFIG_PRODUCTION: 'false', npm_config_production: 'false' });
    expect(res.status).toBe(0);
    expect(res.env.trim()).toBe('osatt osatt');
  });

  it('godkänner en ren rapport och kör npm audit med specens flaggor', () => {
    const res = run([CLEAN]);
    expect(res.status).toBe(0);
    expect(res.output).toContain('cc-audit: OK');
    expect(res.calls).toBe(1);
    expect(res.args).toContain('audit --omit=dev --audit-level=high --json --registry=https://registry.npmjs.org/');
  });

  it('fäller på high och critical med paketnamn och allvarlighetsgrad', () => {
    const res = run([{ status: 1, stdout: report({ lodash: vulnerability('lodash', 'high'), debug: vulnerability('debug', 'critical'), ms: vulnerability('ms', 'moderate') }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('cc-audit: debug (critical)');
    expect(res.output).toContain('cc-audit: lodash (high)');
    expect(res.output).not.toContain('ms (moderate)');
    expect(res.output).not.toContain('npm-registret');
    expect(res.calls).toBe(1);
  });

  it('släpper igenom moderate när npm avslutar med 0', () => {
    expect(run([{ status: 0, stdout: report({ ms: vulnerability('ms', 'moderate') }) }]).status).toBe(0);
  });

  it('fäller på high i rapporten även om npm avslutar med 0 (fail closed)', () => {
    const res = run([{ status: 0, stdout: report({ lodash: vulnerability('lodash', 'high') }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('cc-audit: lodash (high)');
  });

  it('försöker en gång till när registret inte svarar och fäller sedan med specens text', () => {
    const res = run([REGISTRY_DOWN]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm-registret svarade inte');
    expect(res.calls).toBe(2);
  });

  it('godkänner när registret svarar vid andra försöket', () => {
    const res = run([REGISTRY_DOWN, CLEAN]);
    expect(res.status).toBe(0);
    expect(res.calls).toBe(2);
  });

  it('räknar 5xx från registret som registerfel', () => {
    const res = run([{ status: 1, stdout: JSON.stringify({ message: 'audit endpoint returned an error', error: { code: 'E503', summary: '', detail: '' } }) }, CLEAN]);
    expect(res.status).toBe(0);
    expect(res.calls).toBe(2);
  });

  it('fäller direkt med koden vid ett annat npm-fel, utan omförsök', () => {
    const res = run([{ status: 1, stdout: JSON.stringify({ message: 'Unable to authenticate', error: { code: 'E401', summary: '', detail: '' } }) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit misslyckades (E401)');
    expect(res.output).not.toContain('npm-registret svarade inte');
    expect(res.calls).toBe(1);
  });

  it('fäller direkt när utdata inte är JSON, aldrig som ren', () => {
    const res = run([{ status: 0, stdout: 'inte json' }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit misslyckades (ingen JSON)');
    expect(res.calls).toBe(1);
  });

  it('fäller när npm avslutar med fel utan high eller critical i rapporten', () => {
    const res = run([{ status: 1, stdout: report({}) }]);
    expect(res.status).toBe(1);
    expect(res.output).toContain('npm audit avslutade med kod 1');
  });
});
```

Lägg till i `tests/Feature/BuildGuideV2Test.php`:

```php
    public function test_audit_script_is_in_the_guide(): void
    {
        $paths = array_column(BuildGuide::manifest('v2')['files'], 'path');

        $gate = BuildGuide::file('v2', 'scripts/cc-gate.mjs')['content'];

        $this->assertContains('test/ccAudit.test.ts', $paths);
        $this->assertStringContainsString("'--omit=dev', '--audit-level=high'", $gate);
        $this->assertStringContainsString('delete env.NPM_CONFIG_PRODUCTION;', $gate);
    }
```

Run: `cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter=BuildGuideV2Test`
Expected: FAIL i `test_audit_script_is_in_the_guide` (`test/ccAudit.test.ts` saknas, audit-delen finns inte).

- [ ] **Steg 2: audit-delen i `cc-gate.mjs`.** Lägg till `import { spawnSync } from 'node:child_process';` bland importerna överst i `resources/byggguide/v2/files/scripts/cc-gate.mjs`, och lägg till sist i filen (efter raden `console.log(`check-security: OK …`)`):

```js
// ---- Del 2: npm audit för produktionsberoenden (spec delprojekt 3, del 3).
// - Fynd (high eller critical) fäller med paketnamn och allvarlighetsgrad.
// - Fel mot registret (nätverkskod eller 5xx) ger ett nytt försök efter 10 s; sedan fäller
//   skriptet med "npm-registret svarade inte".
// - Andra fel (annan kod, utdata som inte är JSON) fäller direkt med koden.
// Formen på `npm audit --json` (npm 10, verifierad 2026-10-14): en rapport har
// auditReportVersion, metadata och vulnerabilities; ett fel har message och error, och
// orsaken står i message ("… reason: getaddrinfo ENOTFOUND registry.npmjs.org").
// --registry gör att spelrepots .npmrc inte kan peka granskningen mot ett annat register.

const ARGS = ['audit', '--omit=dev', '--audit-level=high', '--json', '--registry=https://registry.npmjs.org/'];
const SERIOUS = new Set(['high', 'critical']);
const NETWORK = /^(ENOTFOUND|ECONNRESET|ECONNREFUSED|ETIMEDOUT|EAI_AGAIN|E?5\d\d)$/;
// Bara testerna kortar väntan; den kan aldrig bli längre än 10 s.
const RETRY_DELAY_MS = Math.min(10_000, Math.max(0, Number(process.env.CC_AUDIT_RETRY_DELAY_MS ?? 10_000) || 0));

// error.code om npm skickar den, annars koden ur message (E-kod eller HTTP-status).
function errorCode(json) {
  if (typeof json?.error?.code === 'string' && json.error.code !== '') return json.error.code;
  const message = typeof json?.message === 'string' ? json.message : '';
  return message.match(/\b(E[A-Z][A-Z0-9_]+)\b/)?.[1] ?? message.match(/\b(5\d\d)\b/)?.[1] ?? 'okänd kod';
}

function audit() {
  // Spike A (S8): nixpacks sätter NPM_CONFIG_PRODUCTION=false, och då räknar npm audit
  // --omit=dev ändå med utvecklingsberoenden. Variabeln får aldrig nå npm här.
  const env = { ...process.env };
  delete env.NPM_CONFIG_PRODUCTION;
  delete env.npm_config_production;
  const r = spawnSync('npm', ARGS, { encoding: 'utf8', env, maxBuffer: 64 * 1024 * 1024 });
  if (r.error) return { kind: 'failed', code: r.error.code ?? 'npm startade inte' };

  let json = null;
  try {
    json = JSON.parse(r.stdout);
  } catch {
    return { kind: 'failed', code: 'ingen JSON' };
  }
  const isReport =
    json !== null && typeof json === 'object' && 'auditReportVersion' in json && typeof json.vulnerabilities === 'object' && json.vulnerabilities !== null;
  if (!isReport) {
    const code = errorCode(json);
    return NETWORK.test(code) ? { kind: 'registry', code } : { kind: 'failed', code };
  }

  // Fail closed: high eller critical fäller även om npm avslutade med 0.
  const serious = Object.values(json.vulnerabilities)
    .filter((v) => v && SERIOUS.has(v.severity))
    .map((v) => `${v.name} (${v.severity})`)
    .sort();
  if (serious.length > 0) return { kind: 'findings', serious };
  if (r.status !== 0) return { kind: 'unexpected', status: r.status };
  return { kind: 'ok' };
}

let result = audit();
if (result.kind === 'registry') {
  console.error(`cc-audit: npm-registret svarade inte (${result.code}); nytt försök om ${RETRY_DELAY_MS / 1000} s.`);
  await new Promise((done) => setTimeout(done, RETRY_DELAY_MS));
  result = audit();
}

if (result.kind === 'ok') {
  console.log('cc-audit: OK (inga high eller critical i produktionsberoenden)');
} else if (result.kind === 'findings') {
  for (const line of result.serious) console.error(`cc-audit: ${line}`);
  console.error('cc-audit: uppdatera paketen (npm audit fix eller en ny version) och pusha igen. Tysta aldrig fyndet.');
  process.exit(1);
} else if (result.kind === 'unexpected') {
  console.error(`cc-audit: npm audit avslutade med kod ${result.status} utan high eller critical i rapporten.`);
  process.exit(1);
} else if (result.kind === 'failed') {
  console.error(`cc-audit: npm audit misslyckades (${result.code}). Kör npm audit --omit=dev lokalt och läs felet.`);
  process.exit(1);
} else {
  console.error('cc-audit: npm-registret svarade inte (två försök). Vänta och pusha igen.');
  process.exit(1);
}
```

- [ ] **Steg 3: Hasha, PHP-testerna och guiden.**

```bash
cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter='BuildGuideV2Test|BuildGuideTest'
cd $WT && php artisan computercat:byggguide:verify v2
```

Förväntat: `53 filer i v2.`, PASS, och i verify `ccAudit.test.ts` med 11 gröna tester och `Byggguide v2: OK (53 filer, check, audit, static_export).` Kör sedan skriptet mot den riktiga guiden en gång (nätverk mot registret):

```bash
cd $WT && KEEP=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "$KEEP"
cd "$KEEP" && node scripts/cc-gate.mjs; echo "exit=$?"
rm -rf "$KEEP"     # katalogen som verify skapade, och bara den
```

Förväntat: `check-security: OK …`, `cc-audit: OK …` och `exit=0`.

- [ ] **Steg 4: Commit.**

```bash
cd $WT && git add resources/byggguide/v2 tests/Feature/BuildGuideV2Test.php && git commit -m "Byggguide v2: audit-delen i cc-gate.mjs skiljer fynd från registerfel och tar bort NPM_CONFIG_PRODUCTION"
```

---

### Task 6: eslint-reglerna och braces (accepterad, ingen override)

**Files:**
- Modify (i v2): `files/eslint.config.mjs` (`package.json`, `package-lock.json` och `manifest.overrides` ändras inte: braces får ingen override, R8)
- Create (i v2): `files/test/eslintRules.test.ts`
- Modify: `tests/Feature/BuildGuideV2Test.php`

**Interfaces:**
- Produces: `npm run lint` fäller på specens fem mönster (regel-id `no-restricted-syntax`, `no-eval`, `no-new-func`, `no-implied-eval`). v2:s `overrides` är `{"vite": "7.3.7"}` som i v1 (Task 8 skriver det i `BuildGuideTest::EXPECTED`).

- [ ] **Steg 1: Fallerande test.** `resources/byggguide/v2/files/test/eslintRules.test.ts`:

```ts
import { resolve } from 'node:path';
import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

// Byggguide v2: säkerhetsreglerna i eslint.config.mjs (spec delprojekt 3, del 3, punkt 3).
// Koden lintas som om den låg i en befintlig komponent, med projektets riktiga konfiguration.
const FILE = resolve(process.cwd(), 'src/components/ConfigBanner.tsx');
const eslint = new ESLint({ cwd: process.cwd() });

async function rulesFor(code: string): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath: FILE });
  return result.messages.map((m) => m.ruleId ?? `parse: ${m.message}`);
}

describe('säkerhetsreglerna i eslint', () => {
  it.each([
    ['dangerouslySetInnerHTML', 'export function A({ h }: { h: string }) {\n  return <div dangerouslySetInnerHTML={{ __html: h }} />;\n}\n', 'no-restricted-syntax'],
    ['dangerouslySetInnerHTML som objektnyckel', "import { createElement } from 'react';\nexport const a = (h: string) => createElement('div', { dangerouslySetInnerHTML: { __html: h } });\n", 'no-restricted-syntax'],
    ['eval', 'export const a = (s: string) => eval(s);\n', 'no-eval'],
    ['new Function', "export const f = new Function('return 1');\n", 'no-new-func'],
    ['setTimeout med sträng', "setTimeout('alert(1)', 10);\n", 'no-implied-eval'],
    ['setInterval med sträng', 'setInterval(`alert(1)`, 10);\n', 'no-implied-eval'],
    ['en annan NEXT_PUBLIC-variabel', 'export const k = process.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['NEXT_PUBLIC med hakparentes', "export const k = process.env['NEXT_PUBLIC_COMPUTERCAT_GAME'];\n", 'no-restricted-syntax'],
  ])('fäller %s', async (_name, code, rule) => {
    const rules = await rulesFor(code);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).toContain(rule);
  }, 30_000);

  it('släpper igenom de två tillåtna variablerna, timers med funktion och vanlig JSX', async () => {
    const code = [
      'export const g = process.env.NEXT_PUBLIC_COMPUTERCAT_GAME;',
      'export const u = process.env.NEXT_PUBLIC_COMPUTERCAT_URL;',
      'export const n = process.env.NODE_ENV;',
      'setTimeout(() => undefined, 10);',
      'export function B({ t }: { t: string }) {',
      '  return <p>{t}</p>;',
      '}',
      '',
    ].join('\n');
    const rules = await rulesFor(code);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    for (const rule of ['no-restricted-syntax', 'no-eval', 'no-new-func', 'no-implied-eval']) {
      expect(rules).not.toContain(rule);
    }
  }, 30_000);
});
```

Lägg till i `tests/Feature/BuildGuideV2Test.php`:

```php
    public function test_eslint_has_the_security_rules(): void
    {
        $config = BuildGuide::file('v2', 'eslint.config.mjs')['content'];

        foreach (["'no-eval': 'error'", "'no-implied-eval': 'error'", "'no-new-func': 'error'", "Property[key.name='dangerouslySetInnerHTML']", 'NEXT_PUBLIC_COMPUTERCAT_(GAME|URL)'] as $text) {
            $this->assertStringContainsString($text, $config);
        }
        $this->assertContains('test/eslintRules.test.ts', array_column(BuildGuide::manifest('v2')['files'], 'path'));
    }
```

Run: `cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter=BuildGuideV2Test`
Expected: FAIL i `test_eslint_has_the_security_rules`.

- [ ] **Steg 2: `eslint.config.mjs`.** Grundkoden läser `process.env` med hakparentes på två ställen, båda i `scripts/` (inte i det som bakas in i `out/`). Kontrollera:

```bash
cd $WT/resources/byggguide/v2/files && grep -rnE 'process\.env\[' src test scripts *.ts *.mjs
```

Förväntat: precis `scripts/check-export.mjs` (`process.env[NAME]`) och `scripts/cc-gate.mjs` (`process.env[URL_NAME]`). Regeln mot hakparentes gäller därför bara `src/`. Ersätt `resources/byggguide/v2/files/eslint.config.mjs` helt med:

```js
import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

// Byggguide v2: säkerhetsregler (spec delprojekt 3, del 3, punkt 3). Stäng aldrig av dem i spelet.
// NEXT_PUBLIC_* bakas in i den publika koden vid bygget; bara spelets slug och API-adressen får läsas.
// Både JSX-attributet och nyckeln i ett props-objekt (createElement, spridda props).
const DANGEROUS_HTML = {
  selector: "JSXAttribute[name.name='dangerouslySetInnerHTML'], Property[key.name='dangerouslySetInnerHTML']",
  message: 'dangerouslySetInnerHTML är förbjudet (byggguide v2). Rendera texten som text.',
};
const OTHER_PUBLIC_ENV = {
  selector:
    "MemberExpression[object.type='MemberExpression'][object.object.name='process'][object.property.name='env'][computed=false][property.name=/^NEXT_PUBLIC_/]:not([property.name=/^NEXT_PUBLIC_COMPUTERCAT_(GAME|URL)$/])",
  message: 'Bara NEXT_PUBLIC_COMPUTERCAT_GAME och NEXT_PUBLIC_COMPUTERCAT_URL får läsas (byggguide v2). Allt i NEXT_PUBLIC_* blir publikt.',
};
const COMPUTED_ENV = {
  selector: "MemberExpression[object.type='MemberExpression'][object.object.name='process'][object.property.name='env'][computed=true]",
  message: 'Läs process.env med punkt och bokstavligt namn (byggguide v2); Next bakar bara in NEXT_PUBLIC_* i den formen.',
};

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-restricted-syntax': ['error', DANGEROUS_HTML, OTHER_PUBLIC_ENV],
    },
  },
  // Spelets källkod (det som bakas in i out/). Ett senare block ersätter regelns hela
  // värde för sina filer, därför upprepas de två första väljarna här.
  {
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', DANGEROUS_HTML, OTHER_PUBLIC_ENV, COMPUTED_ENV],
    },
  },
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', '.claude/**']),
]);
```

Testets fil (`src/components/ConfigBanner.tsx`) ligger under `src/`, så alla åtta fallen gäller där.

- [ ] **Steg 3: Hasha, PHP-testerna och guiden.**

```bash
cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter='BuildGuideV2Test|BuildGuideTest'
cd $WT && php artisan computercat:byggguide:verify v2
```

Förväntat: `54 filer i v2.`, PASS, och i verify `eslintRules.test.ts` med 9 gröna tester, `npm run lint` utan fel på grundkoden och `Byggguide v2: OK (54 filer, check, audit, static_export).` Faller ett fall med `parse:` (konfigurationen kan inte tolka TSX i den sökvägen) eller ger en regel ett annat id än testet väntar: stanna och fråga med utskriften; ändra inte testet för att få det grönt.

- [ ] **Steg 4: braces utan override (R8, Eriks beslut 2026-10-14).** Ingen ändring i `overrides` eller låsfilen. Kontrollera att utfallet är det beslutet bygger på, i en utskriven kopia av guiden:

```bash
cd $WT && KEEP=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "$KEEP"
cd "$KEEP" && npm audit --omit=dev --audit-level=high; echo "prod-exit=$?"
cd "$KEEP" && npm audit --json | jq -c '{high: .metadata.vulnerabilities.high, critical: .metadata.vulnerabilities.critical, paket: (.vulnerabilities | keys)}'
rm -rf "$KEEP"     # katalogen som verify skapade, och bara den
jq -c .overrides $WT/resources/byggguide/v2/files/package.json $WT/resources/byggguide/v2/manifest.json
```

Förväntat: `prod-exit=0`; i hela granskningen bara braces-kedjan (`@next/eslint-plugin-next`, `braces`, `eslint-config-next`, `fast-glob`, `micromatch`) och ingen critical; `overrides` `{"vite":"7.3.7"}` i båda filerna. Har det tillkommit något annat (ett nytt fynd i produktionsberoenden, eller något utanför braces-kedjan): stanna och fråga. Raden om braces i v2:s `CLAUDE.md` och README skrevs i Task 3. Lägg till i `tests/Feature/BuildGuideV2Test.php`:

```php
    // R8: braces accepteras som dev-only; ingen override, och texterna säger varför.
    public function test_braces_is_accepted_without_override(): void
    {
        $this->assertSame(['vite' => '7.3.7'], $this->package()['overrides']);
        $this->assertSame(['vite' => '7.3.7'], BuildGuide::manifest('v2')['overrides']);
    }
```

- [ ] **Steg 5: Hasha, testa, verify.**

```bash
cd $WT && php artisan computercat:byggguide:hash v2 && php artisan test --filter='BuildGuideV2Test|BuildGuideTest'
cd $WT && php artisan computercat:byggguide:verify v2
```

Förväntat: PASS och `Byggguide v2: OK (54 filer, check, audit, static_export).`

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add resources/byggguide/v2 tests/Feature/BuildGuideV2Test.php && git commit -m "Byggguide v2: eslint-regler mot dangerouslySetInnerHTML, eval och publika variabler; braces accepterad som dev-only"
```

---

### Task 7: Spike B — de riktiga skripten i Coolify och i webbläsaren (före låsningen)

**Files:**
- Modify: `$WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (avsnittet från Task 1 fylls på)
- Create (scratch, committas inte): `$SCRATCH/spike-b/ids.env`, `$SCRATCH/s5/`; i engångsrepot: `.ccspike/cc-gate.mjs`

**Interfaces:**
- Consumes: den olåsta, hashade v2 (Task 3–6), `verify v2` grön; spikverktygen; S9 (timeouten) från Task 1.
- Produces: specens S1, S3, S4, S5, S6, S7 och planens S11 (ingen CSP-header från Coolify, Traefik eller nginx som skär i meta-policyn) och S12 (`npm run dev` fungerar; taggen renderas bara i produktionsbygget, R6). Är något rött rättas v2 (hash, verify, commit i ägartasken) och berörda punkter körs om innan Task 8.

Byggkommandot är specens (R14) med två skillnader (R2): `cc-gate.mjs` kopieras från `.ccspike/` i engångsrepot i stället för att hämtas från Computercat, och v1:s `check-export.mjs` hämtas från `api.computercat.co` för att visa nätet. Loggkontrollerna läser även dolda poster med oankrade mönster (R15). **Faller en punkt: stanna och fråga Erik.**

- [ ] **Steg 1: Lokala punkter först (S5, S7), utan något mot prod.**

S7 (testerna med `NODE_ENV=production` i miljön):

```bash
cd $WT && KEEP_V2=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "KEEP_V2=$KEEP_V2" >> $SCRATCH/spike-b/ids.env
cd "$KEEP_V2" && NODE_ENV=production NEXT_PUBLIC_COMPUTERCAT_GAME=byggguide-verify npm run check; echo "exit=$?"
```

Förväntat: `exit=0` och `check-security: OK …` sist.

S5 (noll hemlighetsträffar på riktiga exporter). v1-exemplet (som saknar CSP, så bara CSP-raderna får förekomma):

```bash
cd $WT && KEEP_V1=$(php artisan computercat:byggguide:verify v1 --keep | sed -n 's/^Katalogen behålls: //p') && echo "KEEP_V1=$KEEP_V1" >> $SCRATCH/spike-b/ids.env
cd "$KEEP_V1" && node $WT/resources/byggguide/v2/files/scripts/cc-gate.mjs 2>&1 | grep -v 'CSP saknas' | grep -cE 'hemlighet|source map|symbolisk|dold fil'
```

Förväntat: `0`. Kattsvep (bara läsning av repot; bygget körs lokalt):

```bash
mkdir -p $SCRATCH/s5 && gh repo clone HumblebragAB/kattsvep $SCRATCH/s5/kattsvep -- --depth 1
cd $SCRATCH/s5/kattsvep && npm ci --ignore-scripts && NEXT_TELEMETRY_DISABLED=1 NEXT_PUBLIC_COMPUTERCAT_GAME=kattsvep npm run build
node $WT/resources/byggguide/v2/files/scripts/cc-gate.mjs 2>&1 | grep -v 'CSP saknas' | grep -cE 'hemlighet|source map|symbolisk|dold fil'
```

Förväntat: `0`. En träff: skriv bara raden (fil och mönsternamn; värdet skrivs aldrig ut av skriptet), stanna och fråga om det är en falsk träff (mönstret behöver ändras före låsning) eller en riktig.

- [ ] **Steg 2: Lokalt i webbläsaren (S3, S12).** Ett lokalt spel i worktreens egen databas, id:n sparas (läs sedan in dem med `source $SCRATCH/spike-b/ids.env`):

```bash
cd $WT && php artisan tinker --execute='$g = App\Models\Game::create(["slug" => "ccspike-csp", "name" => "Spike CSP", "is_active" => true, "is_listed" => false]); $t = App\Models\LeaderboardType::create(["game_id" => $g->id, "slug" => "daily-clicks", "name" => "Dagens klick", "sort_direction" => "desc", "score_label" => "Klick", "period" => "daily", "is_active" => true]); echo "GAME_ID={$g->id}\nTYPE_ID={$t->id}\n";' | tee -a $SCRATCH/spike-b/ids.env
```

Starta API:t i bakgrunden: `cd $WT && php artisan serve --host=127.0.0.1 --port=8123` (run_in_background). Bygg och kontrollera exporten mot det lokala API:t, och servera den:

```bash
cd "$KEEP_V2" && export NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-csp NEXT_PUBLIC_COMPUTERCAT_URL=http://127.0.0.1:8123/api/v1
NEXT_TELEMETRY_DISABLED=1 npm run build && node scripts/cc-gate.mjs
python3 -m http.server 3000 --bind 127.0.0.1 --directory out     # run_in_background
```

Öppna `http://localhost:3000/` i Chrome (claude-in-chrome; `localhost` med port är tillåtet i CORS, `config/cors.php`). Klicka "Starta en runda", klicka några gånger, vänta 11 sekunder. Läs konsolen (`read_console_messages`, mönster `Content Security Policy|Refused|CSP`) och kontrollera i databasen:

```bash
cd $WT && php artisan tinker --execute="echo App\Models\LeaderboardEntry::where('leaderboard_type_id', $TYPE_ID)->count().PHP_EOL;"
```

Förväntat: inga CSP-meddelanden i konsolen och minst `1` rad (S3). Stoppa `http.server`. S12: i samma katalog `npm run dev` (run_in_background, samma två variabler), öppna `http://localhost:3000/`, ladda om en gång och läs konsolen. Förväntat: sidan fungerar, inga CSP-meddelanden i konsolen och ingen CSP-tagg i sidans `<head>` (taggen renderas bara när `NODE_ENV` är `production`, R6). Syns taggen eller CSP-meddelanden i dev: stanna och fråga. Stoppa dev-servern och `php artisan serve`.

Städa det lokala spelet på sparade id:n (bara läsning först):

```bash
cd $WT && php artisan tinker --execute="\$g = App\Models\Game::find($GAME_ID); echo (\$g?->slug === 'ccspike-csp' ? 'radera '.\$g->slug : 'STOPP: annat spel').PHP_EOL; if (\$g?->slug === 'ccspike-csp') { \$g->delete(); }"
```

(Topplistan och raderna försvinner med spelet genom `cascadeOnDelete`.)

- [ ] **Steg 3: Fråga Erik (AskUserQuestion) innan något skapas i Coolify.** Texten: "Spike B för byggguide v2 skapar på riktigt: det privata repot HumblebragAB/ccspike-v2b med v2-grundkoden och en kopia av det riktiga cc-gate.mjs under .ccspike/ (arkiveras efteråt) och Coolify-appen ccspike-v2b i Demo-projektet med specens byggkommando (raderas på sitt uuid). Tre byggen: ett lyckat, ett med en falsk JWT i public/ som ska falla, och ett där repot tagit bort scripts/cc-gate.mjs och ändrat check i package.json men ska falla ändå. Jag mäter byggtid och RAM på Coolify-servern (free -m) och läser byggloggens check-security-rader med SELECT. Om en commit-hook stoppar den falska nyckeln frågar jag dig igen. OK?" Utan OK: stanna.

- [ ] **Steg 4: Repot med v2-grundkoden och spikskripten.** Från den rena delen av `$KEEP_V2` (guidens `.gitignore` håller `node_modules`, `out`, `.next` och `.env*` utanför):

```bash
cd "$KEEP_V2" && unset NEXT_PUBLIC_COMPUTERCAT_GAME NEXT_PUBLIC_COMPUTERCAT_URL
mkdir -p .ccspike && cp scripts/cc-gate.mjs .ccspike/cc-gate.mjs
G=$(jq -r '.files[] | select(.path == "scripts/cc-gate.mjs") | .sha256' $WT/resources/byggguide/v2/manifest.json)
[ "$G" = "$(shasum -a 256 .ccspike/cc-gate.mjs | cut -c1-64)" ] && echo "kopian stämmer med manifestet"
git init -q -b main && git add -A && git -c user.name=Spike -c user.email=spike@invalid commit -qm "Spike: grundkod från byggguide v2 (olåst) och spikskript"
git ls-files | grep -cE '^(node_modules|out|\.next)/|^\.env'    # förväntat: 1 (.env.example)
gh repo create HumblebragAB/ccspike-v2b --private --description "Engångsrepo för spike byggguide v2 (arkiveras)" --source=. --remote=origin --push
gh api repos/HumblebragAB/ccspike-v2b --jq '"REPO_ID=\(.id) private=\(.private)"'
```

Skriv `REPO_ID` i `ids.env`. **`private` false: stanna omedelbart.**

- [ ] **Steg 5: Byggkommandot.** Specens rad (R14) med `cp .ccspike/cc-gate.mjs` i stället för hämtningen från v2:s REST (R2), plus v1-hämtningen från `api.computercat.co` som visar nätet:

```bash
CMD="npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v1/files/scripts/check-export.mjs -o /tmp/cc-v1.mjs && cp .ccspike/cc-gate.mjs /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs"
[ ${#CMD} -le 255 ] && echo "längd ${#CMD}: ryms (S10)"
CMD_B64=$(printf '%s' "$CMD" | base64 | tr -d '\n')
```

- [ ] **Steg 6: Appen och bygge 1 (S1).** Skapa appen med `create-app.php` (`NAME=ccspike-v2b`, `CMD_B64`), spara `APP_UUID`, läs tillbaka med `read-app.php` (`build_command_same=true`). Webhook-race (R15): pushen i steg 4 kom sekunder före appen och kan ändå starta ett bygge. Poll `deployments.php` en gång: finns redan ett bygge, vänta tills det är klart och räkna det som bygge 1 (hoppa över den tomma pushen nedan). Annars, starta RAM-mätningen i bakgrunden och bygget:

```bash
( for i in $(seq 1 60); do ~/.claude/bin/ssh-claude --via-home erik@apps.humblebrag.se "free -m | awk '/Mem:/{print \$3, \$7} /Swap:/{print \"swap\", \$3}'" >> $SCRATCH/spike-b/ram.txt; sleep 20; done ) &   # run_in_background
cd "$KEEP_V2" && git commit -q --allow-empty -m "Spike: bygge 1" && git push -q origin main
```

Poll `deployments.php` tills `finished` eller `failed` (högst 20 minuter); spara `DEP1`. Byggtiden är `finished_at` minus `created_at` för `DEP1`. Kontrollera:

```bash
buildlog "$DEP1" 'check-security:|cc-audit:|/tmp/cc-.*: OK'
curl -s -o /dev/null -w '%{http_code}\n' -u "$DEMO_AUTH" https://ccspike-v2b.apps.humblebrag.se/
curl -s -u "$DEMO_AUTH" https://ccspike-v2b.apps.humblebrag.se/ | grep -o '<meta[^>]*Content-Security-Policy[^>]*>'
curl -sI -u "$DEMO_AUTH" https://ccspike-v2b.apps.humblebrag.se/ | grep -i 'content-security-policy' ; echo "header-träffar=$?"
sort -n $SCRATCH/spike-b/ram.txt | tail -1      # högsta använda minne (MB) och tillgängligt
```

Förväntat (S1): `finished`, `check-security: OK …` och `cc-audit: OK …` i de dolda raderna (buildlog läser dem, R15; `cc-audit: OK` bekräftar att `NPM_CONFIG_PRODUCTION` inte nådde npm, S8), Demo `200`, metataggen finns, byggtiden ligger tydligt under timeouten 3600 s (S9) och minst 512 MB var tillgängligt. Förväntat (S11): ingen `Content-Security-Policy`-header (`header-träffar=1`); finns en header gäller båda policyerna samtidigt, så stanna och fråga. Notera byggtid och RAM-topp för bilagan.

- [ ] **Steg 7: Bygge 2, planterad nyckel (S4).** En falsk JWT skapas vid körning och skrivs aldrig ut:

```bash
cd "$KEEP_V2" && printf 'eyJ%s.eyJ%s.%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > public/cc-spike-key.txt
git add public/cc-spike-key.txt && git commit -qm "Spike: planterad nyckel ska fälla bygget" && git push -q origin main
```

Stoppar en hook eller GitHubs push protection committen eller pushen: stanna och fråga (R11). Poll tills klart; spara `DEP2`. Förväntat: `failed`; `buildlog "$DEP2" 'check-security:'` visar `check-security: out/cc-spike-key.txt: hemlighet (jwt)`; `curl -s -o /dev/null -w '%{http_code}\n' -u "$DEMO_AUTH" https://ccspike-v2b.apps.humblebrag.se/cc-spike-key.txt` ger `404` och startsidan fortfarande `200` (förra bygget serveras).

- [ ] **Steg 8: Bygge 3, repot försöker stänga av spärren (S6).** Nyckeln ligger kvar; repot tar bort `scripts/cc-gate.mjs` och gör `check` till ett eko (kopian i `.ccspike/` står för det som Computercat levererar i prod):

```bash
cd "$KEEP_V2" && git rm -q scripts/cc-gate.mjs && jq '.scripts.check = "echo hoppar över"' package.json > package.tmp && mv package.tmp package.json
git add -A && git commit -qm "Spike: repot tar bort säkerhetsskriptet" && git push -q origin main
```

Poll; spara `DEP3`. Förväntat: `failed` och samma `check-security: out/cc-spike-key.txt: hemlighet (jwt)` i loggen (kommandot beror inte på repots `scripts/` eller `check`; i prod hämtas skriptet från Computercat, Task 12). `finished`: stanna och fråga.

- [ ] **Steg 9: Städning på sparade id:n.**

```bash
NAME=ccspike-v2b; UUID=$APP_UUID
sed -e "s/__NAME__/$NAME/g" -e "s/__UUID__/$UUID/g" $SCRATCH/tinker/delete-app.php > $SCRATCH/tinker/run.php && prodtinker $SCRATCH/tinker/run.php
[ "$(gh api repos/HumblebragAB/ccspike-v2b --jq .id)" = "$REPO_ID" ] && gh api -X PATCH repos/HumblebragAB/ccspike-v2b -F archived=true --jq .archived
rm -rf "$KEEP_V2" "$KEEP_V1" "$SCRATCH/s5/kattsvep" "$SCRATCH/spike-b/ram.txt"   # bara katalogerna och filen som spiken skapade
```

Kontrollera att RAM-loopen har slutat. `STOPP:` eller fel id: stanna och fråga.

- [ ] **Steg 10: Bilagan.** Lägg till under avsnittet från Task 1:

```markdown
Spike B (<datum>): engångsappen `ccspike-v2b` med den olåsta v2-katalogen och kopior av de riktiga skripten under `.ccspike/` (prod serverar inte en olåst version), raderad på sitt uuid.

- S1: specens byggkommando fungerar i nixpacks static-läge; `out/` publiceras. Byggtid <min:s>, RAM-topp <MB använt>/<MB totalt>, minst <MB> tillgängligt; timeouten (S9) är <värde>.
- S3: exemplet laddar och skickar en tid till topplistan i Chrome utan CSP-fel (lokalt API på 127.0.0.1:8123, `connect-src` från `NEXT_PUBLIC_COMPUTERCAT_URL`); Demo serverar metataggen.
- S4: en falsk JWT i `public/` ger `failed` med `check-security: out/cc-spike-key.txt: hemlighet (jwt)`; Demo serverar förra bygget.
- S5: säkerhetsdelen i `cc-gate.mjs` ger noll hemlighetsträffar på `out/` från v1-exemplet och Kattsvep.
- S6: ett repo utan `scripts/cc-gate.mjs` och med `check` ändrat fäller ändå (kommandot använder inte repots `scripts/` eller `check`).
- S7: `npm run check` med `NODE_ENV=production` i miljön är grön.
- S11: <ingen Content-Security-Policy-header från Coolify/Traefik/nginx | vad som syntes>.
- S12: `npm run dev` fungerar utan CSP-tagg och utan CSP-meddelanden (taggen bara i produktionsbygget) (<vad som kontrollerades>).
```

Fyll i varje `<…>` och kontrollera: `sed -n '/^## Spikresultat delprojekt 3 etapp B/,$p' $WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md | grep -c '<'` ger `0`.

- [ ] **Steg 11: Commit.**

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md && git commit -m "Spike B: säkerhetsspärren i Coolify och CSP i webbläsaren (etapp B, S1, S3-S7, S11, S12)"
```

---

### Task 8: Lås v2 och gör den till senaste version

**Files:**
- Modify: `app/Services/BuildGuide.php` (`LATEST`, `FROZEN`)
- Modify: `tests/Feature/BuildGuideTest.php` (`EXPECTED`)
- Modify: `tests/Feature/Provisioning/RepoStepTest.php`

**Interfaces:**
- Consumes: v2 klar (Task 3–6) och spike B grön (Task 7).
- Produces: `BuildGuide::LATEST === 'v2'`, `BuildGuide::FROZEN['v2']` = sha256 av `resources/byggguide/v2/manifest.json`, `BuildGuide::versions() === ['v1', 'v2']`. Nya provisioneringar fryser v2 i repo-stegets `detail['build_guide']`; befintliga runs behåller sitt värde.

- [ ] **Steg 1: Förutsättningar.** `cd $WT && git log --oneline -1 -- docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` visar commiten från Task 7, och `php artisan computercat:byggguide:verify v2` är grön på nuvarande katalog. Annars: stanna.

- [ ] **Steg 2: Testet för v2:s förväntningar (fallerar tills låset finns).** Läs v2:s `overrides`: `jq -c .overrides $WT/resources/byggguide/v2/files/package.json`. Förväntat: `{"vite":"7.3.7"}` (ingen braces-override, R8). I `tests/Feature/BuildGuideTest.php`, ersätt `EXPECTED`:

```php
    private const EXPECTED = [
        'v1' => ['overrides' => ['vite' => '7.3.7']],
        'v2' => ['overrides' => ['vite' => '7.3.7']],
    ];
```

Run: `cd $WT && php artisan test --filter=BuildGuideTest::test_every_published_version_has_expectations`
Expected: FAIL (`EXPECTED` har v2, `FROZEN` inte).

- [ ] **Steg 3: Lås och byt senaste version i samma ändring.**

```bash
cd $WT && SHA=$(shasum -a 256 resources/byggguide/v2/manifest.json | cut -c1-64)
sed -i '' "s/public const LATEST = 'v1';/public const LATEST = 'v2';/" app/Services/BuildGuide.php
sed -i '' "s/        'v1' => '7f763d8ee8d01c0765debcc225e40a5884c979035f4e3649f00815325699dc85',/        'v1' => '7f763d8ee8d01c0765debcc225e40a5884c979035f4e3649f00815325699dc85',\n        'v2' => '$SHA',/" app/Services/BuildGuide.php
grep -n "LATEST = \|'v[12]' => '" app/Services/BuildGuide.php
```

Förväntat: `public const LATEST = 'v2';`, v1-raden oförändrad och en ny rad `'v2' => '<64 hextecken>',`. (BSD-`sed` på macOS skriver inte `\n` som radbrytning i ersättningen: syns raderna inte som två, gör ändringen med Edit-verktyget i stället.)

- [ ] **Steg 4: Tester som antog att senaste version är v1.** I `tests/Feature/Provisioning/RepoStepTest.php`:
1. Ersätt `private function wantedReadme(): string` och dess rad med:

```php
    private function wantedReadme(string $version = BuildGuide::LATEST): string
    {
        return ReadmeIdea::insert(BuildGuide::readme($version, 'Kattsvep'), 'Minröj där katter letar bajs.');
    }
```

2. I `test_creates_repo_waits_for_readme_then_writes_readme_and_claude_md`: ersätt `$this->assertSame('v1', $run->steps()->where('step', 'repo')->first()->detail['build_guide']);` med `$this->assertSame(BuildGuide::LATEST, $run->steps()->where('step', 'repo')->first()->detail['build_guide']);` och `BuildGuide::claudeMd('v1')` med `BuildGuide::claudeMd(BuildGuide::LATEST)`.
3. I `test_own_repository_is_adopted_on_retry_and_correct_files_are_not_rewritten`: `BuildGuide::claudeMd('v1')` → `BuildGuide::claudeMd(BuildGuide::LATEST)`.
4. I `test_retry_keeps_the_build_guide_version_of_the_first_attempt` (detail har `'build_guide' => 'v1'`): `$this->wantedReadme()` → `$this->wantedReadme('v1')`; `BuildGuide::claudeMd('v1')` står kvar. Testet visar nu på riktigt att en omkörning skriver v1 fast senaste är v2.

Sök efter fler ställen: `cd $WT && grep -rn "'v1'" tests/Feature/Provisioning tests/Feature/Mcp | grep -v "build_guide' => 'v1'"`. Varje träff som förutsätter att v1 är senaste ändras till `BuildGuide::LATEST`; träffar som avsiktligt gäller v1 står kvar.

- [ ] **Steg 5: Hela sviten.**

Run: `cd $WT && php artisan test`
Expected: PASS. Särskilt: `BuildGuideTest` kör nu alla parametriserade tester för v1 och v2; `FactBankContentTest` validerar artiklarnas källor mot v2 (`build_guide ?? [LATEST]`); `CoolifyClientTest::test_install_command_matches_the_build_guide` jämför med v2:s `npm ci --ignore-scripts`. Ett fel i en v2-text eller i manifestet: lås upp enligt Global Constraints (`FROZEN['v2']` tom och `LATEST = 'v1'` i samma ändring), rätta, hasha, verify, lås igen.

- [ ] **Steg 6: Commit (en commit: låset och bytet tillsammans).**

```bash
cd $WT && git add app/Services/BuildGuide.php tests/Feature/BuildGuideTest.php tests/Feature/Provisioning/RepoStepTest.php && git commit -m "Byggguide v2 låst och senaste version (FROZEN['v2'] och LATEST i samma commit)"
git show --stat HEAD | grep -c 'app/Services/BuildGuide.php'    # förväntat: 1
```

---
### Task 9: Coolify kör spärren: byggkommandot för nya v2-appar

**Files:**
- Modify: `app/Services/BuildGuide.php`
- Modify: `app/Provisioning/Clients/CoolifyClient.php`
- Modify: `app/Provisioning/Steps/CoolifyAppStep.php`
- Modify: `tests/Feature/BuildGuideTest.php`, `tests/Feature/Provisioning/CoolifyClientTest.php`, `tests/Feature/Provisioning/CoolifyStepsTest.php`

**Interfaces:**
- Consumes: `BuildGuide::isPublished()`, `manifest()`, `downloadUrl()`; v2 låst (Task 8); S10 och S1 (bilagan).
- Produces:
  - `BuildGuide::GATE_SCRIPT = 'scripts/cc-gate.mjs'`, `BuildGuide::COOLIFY_COMMAND_MAX = 255` (Coolifys `varchar(255)`, spike A S10)
  - `BuildGuide::runsSecurityChecksInBuild(string $version): bool` (har manifestet `scripts/cc-gate.mjs`; false för okänd version). Task 10 använder den.
  - `BuildGuide::coolifyBuildCommand(string $version): ?string` (R4: `npm run build && curl -fsS <REST-adress> -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs`; `RuntimeException` för en olåst version, eller om kommandot blir längre än 255 tecken eller innehåller tecken utanför Coolifys mönster; null när versionen saknar skriptet).
  - `CoolifyClient::createApplication(string $slug, string $version): string` (ny andra parameter; `build_command` direkt efter `install_command` när kommandot inte är null).
  - `CoolifyAppStep` läser `build_guide` ur repo-stegets `detail` och fäller med `StepFailed` om värdet saknas (R9). Den räknar fram `BuildGuide::coolifyBuildCommand($version)` innan markören `create_requested_at` sparas; ett `RuntimeException` därifrån blir `StepFailed` utan att något skickas till Coolify (R13).

- [ ] **Steg 1: Fallerande tester i `BuildGuideTest`.** Ändra hjälparen `unpublishedCopy` så att den kan kopiera från en annan version:

```php
    /** En låst version kopierad till en olåst, i en temporär guide. */
    private function unpublishedCopy(string $version, string $from = 'v1'): string
    {
        $root = $this->tempGuide();
        File::copyDirectory("{$root}/{$from}", "{$root}/{$version}");
        $manifest = json_decode(file_get_contents("{$root}/{$version}/manifest.json"), true);
        $manifest['version'] = $version;
        file_put_contents("{$root}/{$version}/manifest.json", json_encode($manifest));

        return $root;
    }
```

Lägg till:

```php
    // Spec delprojekt 3, "Coolify kör spärren": bara versioner med cc-gate.mjs (v2 och senare).
    public function test_only_versions_with_the_gate_script_run_it_in_the_build(): void
    {
        $this->assertFalse(BuildGuide::runsSecurityChecksInBuild('v1'));
        $this->assertTrue(BuildGuide::runsSecurityChecksInBuild('v2'));
        foreach (['v9', 'x1', ''] as $version) {
            $this->assertFalse(BuildGuide::runsSecurityChecksInBuild($version), $version);
        }
        $this->assertNull(BuildGuide::coolifyBuildCommand('v1'));
    }

    public function test_build_command_is_the_specs_line(): void
    {
        config(['app.url' => 'https://api.computercat.co']);

        $this->assertSame(
            'npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs',
            BuildGuide::coolifyBuildCommand('v2'),
        );
    }

    // Spike A (S10): Coolify sparar build_command i varchar(255) (längre ger 500 och ingen app) och
    // godkänner bara SHELL_SAFE_COMMAND_PATTERN (inga | ; > < $). Planens mönster är strängare.
    public function test_build_command_fits_coolify(): void
    {
        config(['app.url' => 'https://api.computercat.co']);
        $command = (string) BuildGuide::coolifyBuildCommand('v2');

        $this->assertLessThanOrEqual(255, strlen($command));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9._\/=:@,+ -]+\z/', str_replace(' && ', ' ', $command));
        $this->assertDoesNotMatchRegularExpression('/[|;><$`\'"\\\\\n]/', $command);
    }

    public function test_too_long_command_is_refused_before_coolify(): void
    {
        config(['app.url' => 'https://'.str_repeat('a', 200).'.example']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('255');
        BuildGuide::coolifyBuildCommand('v2');
    }

    public function test_build_command_requires_a_published_version(): void
    {
        $this->unpublishedCopy('v99', 'v2');
        $this->assertTrue(BuildGuide::runsSecurityChecksInBuild('v99'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('är inte publicerad');
        BuildGuide::coolifyBuildCommand('v99');
    }
```

Run: `cd $WT && php artisan test --filter=BuildGuideTest`
Expected: FAIL med `Call to undefined method App\Services\BuildGuide::runsSecurityChecksInBuild()`.

- [ ] **Steg 2: `BuildGuide`.** Lägg till efter `INLINE_MAX_BYTES`:

```php
    // Spärrskriptet som Coolify hämtar och kör i bygget (spec delprojekt 3, "Coolify kör spärren").
    public const GATE_SCRIPT = 'scripts/cc-gate.mjs';

    // Coolify v4.4.6 sparar build_command i varchar(255) och godkänner bara
    // SHELL_SAFE_COMMAND_PATTERN (bilaga A, spike etapp B S10). Mönstret här är strängare.
    public const COOLIFY_COMMAND_MAX = 255;

    private const COOLIFY_SAFE = '/\A[A-Za-z0-9._\/=:@,+ -]+\z/';
```

och efter `publicManifest()`:

```php
    // Har versionen spärrskriptet som Coolify kör i bygget? v1 har det inte. Okänd version: nej.
    public static function runsSecurityChecksInBuild(string $version): bool
    {
        try {
            return in_array(self::GATE_SCRIPT, array_column(self::manifest($version)['files'], 'path'), true);
        } catch (BuildGuideNotFound) {
            return false;
        }
    }

    // Coolifys byggkommando för nya appar (spec delprojekt 3, "Coolify kör spärren", Eriks beslut
    // 2026-10-14). Ingen sha256 i kommandot (det ryms inte i 255 tecken): integriteten kommer av
    // att Computercat bara serverar låsta guidefiler, att BuildGuide::read kontrollerar varje fils
    // sha256 innan den skickas, och att hämtningen går över https till vår egen server. Spelrepot
    // kan inte ändra det som hämtas.
    public static function coolifyBuildCommand(string $version): ?string
    {
        if (! self::isPublished($version)) {
            throw new \RuntimeException("Byggguiden {$version} är inte publicerad; Coolify kan inte hämta dess skript.");
        }
        if (! self::runsSecurityChecksInBuild($version)) {
            return null;
        }

        $command = 'npm run build && curl -fsS '.self::downloadUrl($version, self::GATE_SCRIPT).' -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs';
        // Fail loud här i stället för 500 och ingen app i Coolify.
        if (strlen($command) > self::COOLIFY_COMMAND_MAX) {
            throw new \RuntimeException('Coolifys byggkommando blir '.strlen($command).' tecken; Coolify sparar högst '.self::COOLIFY_COMMAND_MAX.'. Kontrollera APP_URL.');
        }
        if (preg_match(self::COOLIFY_SAFE, str_replace(' && ', ' ', $command)) !== 1) {
            throw new \RuntimeException('Coolifys byggkommando innehåller tecken som Coolify inte godkänner. Kontrollera APP_URL.');
        }

        return $command;
    }
```

Run: `cd $WT && php artisan test --filter=BuildGuideTest`
Expected: PASS.

- [ ] **Steg 3: Fallerande tester för klienten och steget.** I `tests/Feature/Provisioning/CoolifyClientTest.php`: lägg till `use App\Services\BuildGuide;`. Ersätt varje `$this->client()->createApplication('kattsvep')` med `$this->client()->createApplication('kattsvep', 'v1')`, utom i `test_install_command_matches_the_build_guide`, där anropet blir `$this->client()->createApplication('kattsvep', BuildGuide::LATEST)` och raden `$install = \App\Services\BuildGuide::manifest(\App\Services\BuildGuide::LATEST)['commands']['install'];` blir `$install = BuildGuide::manifest(BuildGuide::LATEST)['commands']['install'];`. I `test_create_sends_exact_body_and_returns_uuid` byts kommentaren `// Bilaga A S:793. Demo-bygget kör inga installationsskript (byggguide v1).` mot `// Bilaga A S:793. Demo-bygget kör inga installationsskript. v1: inget build_command (exakt body).` Lägg till:

```php
    // Spec delprojekt 3: v2 bygger med Computercats kommando, direkt efter installationen i bodyn.
    public function test_v2_sends_the_build_command(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201)]);

        $this->client()->createApplication('kattsvep', 'v2');

        Http::assertSent(function ($r) {
            $keys = array_keys($r->data());

            return ($r->data()['build_command'] ?? null) === BuildGuide::coolifyBuildCommand('v2')
                && $keys[array_search('install_command', $keys, true) + 1] === 'build_command';
        });
    }

    public function test_v1_sends_no_build_command(): void
    {
        Http::fake(['coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201)]);

        $this->client()->createApplication('kattsvep', 'v1');

        Http::assertSent(fn ($r) => ! array_key_exists('build_command', $r->data()));
    }

    public function test_unknown_version_is_refused_without_any_request(): void
    {
        Http::fake();

        try {
            $this->client()->createApplication('kattsvep', 'v9');
            $this->fail('En okänd version släpptes igenom.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('är inte publicerad', $e->getMessage());
        }
        Http::assertNothingSent();
    }
```

I `tests/Feature/Provisioning/CoolifyStepsTest.php`: lägg till `use App\Services\BuildGuide;` och `use App\Models\ProvisioningRun;` och hjälparen

```php
    // Repo-steget fryser guideversionen; coolify_app läser den därifrån.
    private function runWithGuide(?string $version, array $attrs = []): ProvisioningRun
    {
        $run = $this->provisioningRun(array_merge(['slug' => 'kattsvep'], $attrs));
        if ($version !== null) {
            $this->stepRow($run, 'repo')->update(['detail' => ['build_guide' => $version]]);
        }

        return $run;
    }
```

Byt `$run = $this->provisioningRun(['slug' => 'kattsvep']);` mot `$run = $this->runWithGuide('v1');` i `test_app_is_created_and_uuid_saved`, `test_domain_conflict_fails`, `test_definite_rejection_clears_the_create_marker_so_a_later_manual_app_is_not_adopted` och `test_conflict_clears_the_marker_but_server_errors_keep_it`. Lägg till:

```php
    public function test_v2_run_creates_app_with_the_build_command(): void
    {
        $run = $this->runWithGuide('v2');
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201),
        ]);

        $this->assertSame(StepResult::DONE, $this->runStep(CoolifyAppStep::class, $run, 'coolify_app')->outcome);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/applications/private-github-app')
            && ($r->data()['build_command'] ?? null) === BuildGuide::coolifyBuildCommand('v2'));
    }

    // Ett spel som frös v1 får aldrig v2:s byggkommando, även när LATEST är v2.
    public function test_v1_run_creates_app_without_build_command(): void
    {
        $run = $this->runWithGuide('v1');
        Http::fake([
            self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([])),
            'coolify.test/api/v1/applications/private-github-app' => Http::response(ProvisioningFakes::coolifyCreated('app-1'), 201),
        ]);

        $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/applications/private-github-app') && ! array_key_exists('build_command', $r->data()));
    }

    // R13: en version som inte går att bygga kommando för fäller före markören och före Coolify.
    public function test_unusable_build_guide_version_fails_before_marker_and_create(): void
    {
        $run = $this->runWithGuide('v9');
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([]))]);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
            $this->fail('En okänd guideversion släpptes igenom.');
        } catch (StepFailed $e) {
            $this->assertStringContainsString('v9', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/applications/private-github-app'));
        $this->assertArrayNotHasKey('create_requested_at', $this->stepRow($run, 'coolify_app')->fresh()->detail ?? []);
    }

    public function test_missing_build_guide_version_fails_before_any_create(): void
    {
        $run = $this->runWithGuide(null);
        Http::fake([self::PROJECT_URL => Http::response(ProvisioningFakes::coolifyProject([]))]);

        try {
            $this->runStep(CoolifyAppStep::class, $run, 'coolify_app');
            $this->fail('Ett saknat build_guide släpptes igenom.');
        } catch (StepFailed $e) {
            $this->assertStringContainsString('build guide version', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/applications/private-github-app'));
        $this->assertArrayNotHasKey('create_requested_at', $this->stepRow($run, 'coolify_app')->fresh()->detail ?? []);
    }
```

Run: `cd $WT && php artisan test --filter='CoolifyClientTest|CoolifyStepsTest'`
Expected: FAIL (`createApplication()` tar ett argument; v2 skickar inget `build_command`; steget kräver inget värde).

- [ ] **Steg 4: `CoolifyClient::createApplication`.** Lägg till `use App\Services\BuildGuide;`. Ersätt raderna från och med `    public function createApplication(string $slug): string` till och med `        ], [409]);` (slutet på `$this->coolify('POST', $path, [ … ], [409])`-anropet, före `if ($response->status() === 409) {`) med:

```php
    // $version är byggguideversionen som repo-steget frös. Versioner med säkerhetsskripten
    // (v2 och senare) får Computercats byggkommando; v1 bygger som förut (spec delprojekt 3).
    public function createApplication(string $slug, string $version): string
    {
        // Före anropet: en olåst version eller en trasig guide ger fel utan att något skapas.
        $buildCommand = BuildGuide::coolifyBuildCommand($version);

        $path = '/applications/private-github-app';
        $body = [
            'project_uuid' => (string) config('provisioning.coolify.project_uuid'),
            'server_uuid' => (string) config('provisioning.coolify.server_uuid'),
            'environment_name' => (string) config('provisioning.coolify.environment_name'),
            'github_app_uuid' => (string) config('provisioning.coolify.github_app_uuid'),
            // Verifierat i spike 2026-10-14: S1 (git_repository som owner/repo) och S2 (ports_exposes '80').
            // publish_directory måste börja med snedstreck (Coolify 4.4.6 DIRECTORY_PATH_PATTERN).
            'git_repository' => ProvisioningConfig::repoFullName($slug),
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'name' => ProvisioningConfig::coolifyAppName($slug),
            'domains' => ProvisioningConfig::demoUrl($slug),
            'ports_exposes' => '80',
            'is_static' => true,
            'static_image' => 'nginx:alpine',
            'publish_directory' => '/out',
            // Bilaga A S:793. Samma installation som byggguiden (BuildGuideTest och
            // CoolifyClientTest vaktar): installationsskript körs aldrig i Demo-bygget.
            'install_command' => 'npm ci --ignore-scripts',
        ];
        if ($buildCommand !== null) {
            // Bilaga A S:796; spike etapp B (S10, S1): sparas och körs oförändrat i nixpacks.
            $body['build_command'] = $buildCommand;
        }
        $body += [
            'is_auto_deploy_enabled' => true,
            'instant_deploy' => false,
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_username' => (string) config('provisioning.demo.basic_auth_user'),
            'http_basic_auth_password' => (string) config('provisioning.demo.basic_auth_password'),
        ];

        $response = $this->coolify('POST', $path, $body, [409]);
```

(resten av metoden, från `if ($response->status() === 409) {`, oförändrad).

- [ ] **Steg 5: `CoolifyAppStep`.** I `run()`, direkt före raden `// Markören sparas före anropet, så att en omkörning efter ett avbrott vet`, lägg till:

```php
        // Samma guideversion som repo-steget frös (spec delprojekt 3, "Versioner"). Kommandot
        // räknas fram före markören, så att en olåst version eller en trasig guide stoppar
        // steget utan att Coolify anropas och utan en markör som senare kunde adoptera en app (R13).
        $version = self::buildGuideVersion($run);
        try {
            BuildGuide::coolifyBuildCommand($version);
        } catch (\RuntimeException $e) {
            throw new StepFailed("Build guide {$version} cannot produce the Coolify build command; nothing was created in Coolify.", 0, $e);
        }

```

Lägg till `use App\Services\BuildGuide;` bland importerna och ersätt `$this->coolify->createApplication($run->slug)` med `$this->coolify->createApplication($run->slug, $version)`, och lägg till metoden:

```php
    // Alla runs sedan delprojekt 2 har värdet; saknas det är något fel, och ingen version gissas.
    private static function buildGuideVersion(ProvisioningRun $run): string
    {
        $version = $run->steps()->where('step', 'repo')->first()?->detail['build_guide'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new StepFailed('The repo step has not recorded a build guide version, so the Coolify build command cannot be chosen.');
        }

        return $version;
    }
```

- [ ] **Steg 6: Kör testerna och sviten.**

Run: `cd $WT && php artisan test --filter='CoolifyClientTest|CoolifyStepsTest|BuildGuideTest|AdvanceProvisioningRunTest|CreateGameToolTest|RetryProvisioningToolTest'`
Expected: PASS. Sedan `cd $WT && php artisan test` → PASS.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Services/BuildGuide.php app/Provisioning/Clients/CoolifyClient.php app/Provisioning/Steps/CoolifyAppStep.php tests/Feature/BuildGuideTest.php tests/Feature/Provisioning/CoolifyClientTest.php tests/Feature/Provisioning/CoolifyStepsTest.php && git commit -m "Coolify kör säkerhetsspärren: byggkommando med sha256 för nya v2-appar, inget för v1"
```

---

### Task 10: `get_provisioning_status` vid `deploy_failed` för v2

**Files:**
- Modify: `app/Mcp/Tools/GetProvisioningStatusTool.php`
- Modify: `tests/Feature/Mcp/ProvisioningStatusToolTest.php`

**Interfaces:**
- Consumes: `BuildGuide::runsSecurityChecksInBuild(string $version): bool` (Task 9); repo-stegets `detail['build_guide']`.
- Produces: `GetProvisioningStatusTool::DEPLOY_FAILED_NEXT` (specens text, Coolify-status `failed`) och `DEPLOY_CANCELLED_NEXT` (status `cancelled-by-user`); `demo.next` sätts bara när `state` är `deploy_failed` och runnens version kör spärren. Kan guideversionen inte läsas (trasig guide) visas `demo.next_error` med en referens och felet loggas; svaret är ändå ok.

- [ ] **Steg 1: Fallerande tester.** I `tests/Feature/Mcp/ProvisioningStatusToolTest.php`, ersätt `demoFor`:

```php
    private function demoFor(array $fakes, ?string $buildGuide = null): array
    {
        $run = $this->succeededRun();
        if ($buildGuide !== null) {
            $run->steps()->where('step', 'repo')->firstOrFail()->update(['detail' => ['build_guide' => $buildGuide]]);
        }
        Http::fake($fakes);

        $result = $this->callTool($this->staffMember(Game::where('slug', 'kattsvep')->firstOrFail()), 'get_provisioning_status', ['game' => 'kattsvep']);
        $this->assertTrue($result['ok'], $result['text']);

        return json_decode($result['text'], true)['demo'];
    }
```

och lägg till:

```php
    // Spec delprojekt 3, "Status vid fel".
    public function test_failed_v2_build_tells_the_game_claude_to_run_the_checks_locally(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ], 'v2');

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertSame('Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen.', $demo['next']);
    }

    public function test_cancelled_v2_build_gets_its_own_text(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'cancelled-by-user']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ], 'v2');

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertSame('Bygget avbröts i Coolify innan det blev klart. Pusha igen för att starta ett nytt bygge; Demo visar förra lyckade bygget tills dess.', $demo['next']);
    }

    // Ett fel i byggguiden får inte fälla statusen, men det döljs inte heller.
    public function test_unreadable_build_guide_is_shown_not_fatal(): void
    {
        $root = storage_path('framework/testing/byggguide-'.Str::lower(Str::random(10)));
        File::copyDirectory(resource_path('byggguide'), $root);
        try {
            File::ensureDirectoryExists("{$root}/v99");
            file_put_contents("{$root}/v99/manifest.json", '{');
            config(['byggguide.path' => $root]);

            $demo = $this->demoFor([
                'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed']])),
                'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
            ], 'v99');

            $this->assertSame('deploy_failed', $demo['state']);
            $this->assertArrayNotHasKey('next', $demo);
            $this->assertStringContainsString('Could not read the build guide', $demo['next_error']);
        } finally {
            // Bara katalogen som testet skapade.
            File::deleteDirectory($root);
        }
    }

    public function test_failed_v1_build_keeps_todays_answer(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ], 'v1');

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertArrayNotHasKey('next', $demo);
    }

    public function test_failed_build_without_recorded_version_keeps_todays_answer(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-2', 'failed']])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ]);

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertArrayNotHasKey('next', $demo);
    }

    public function test_live_v2_build_has_no_failure_text(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', str_repeat('c', 40)]])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('<html></html>'),
            'siteops.test/api/v1/sites/42/environments/7' => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated(42, 'https://kattsvep.apps.humblebrag.se')),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments('https://kattsvep.apps.humblebrag.se', monitored: false)),
        ], 'v2');

        $this->assertSame('live', $demo['state']);
        $this->assertArrayNotHasKey('next', $demo);
    }
```

Lägg till `use Illuminate\Support\Facades\File;` och `use Illuminate\Support\Str;` bland importerna.

Run: `cd $WT && php artisan test --filter=ProvisioningStatusToolTest`
Expected: FAIL i `test_failed_v2_build_tells_the_game_claude_to_run_the_checks_locally`, `test_cancelled_v2_build_gets_its_own_text` och `test_unreadable_build_guide_is_shown_not_fatal` (`Undefined array key`).

- [ ] **Steg 2: Implementera.** I `GetProvisioningStatusTool`: lägg till `use App\Services\BuildGuide;`, konstanten

```php
    // Spec delprojekt 3, "Status vid fel", ordagrant (svenska som släppflödets next-texter).
    public const DEPLOY_FAILED_NEXT = 'Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen.';

    // Ett avbrutet bygge är inget fel i koden; det ska inte skicka spelets Claude att leta fel.
    public const DEPLOY_CANCELLED_NEXT = 'Bygget avbröts i Coolify innan det blev klart. Pusha igen för att starta ett nytt bygge; Demo visar förra lyckade bygget tills dess.';
```

i `demo()`, direkt efter blocket som bygger `$result = ['state' => …, 'latest_deployment' => …, 'url_status' => $code];`:

```php
        if ($state === 'deploy_failed') {
            try {
                if ($this->runsSecurityChecks($run)) {
                    $result['next'] = $latest['status'] === 'cancelled-by-user' ? self::DEPLOY_CANCELLED_NEXT : self::DEPLOY_FAILED_NEXT;
                }
            } catch (\Throwable $e) {
                // Synligt men inte fatalt: Demo-läget gäller ändå.
                $ref = (string) Str::uuid();
                Log::warning('get_provisioning_status kunde inte läsa byggguiden', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);
                $result['next_error'] = "Could not read the build guide for this game (ref {$ref}).";
            }
        }
```

och metoden:

```php
    // Guideversionen som repo-steget frös. v1 eller saknat värde: dagens svar, utan next.
    private function runsSecurityChecks(ProvisioningRun $run): bool
    {
        $version = $run->steps->firstWhere('step', 'repo')?->detail['build_guide'] ?? null;

        return is_string($version) && BuildGuide::runsSecurityChecksInBuild($version);
    }
```

- [ ] **Steg 3: Kör testerna.**

Run: `cd $WT && php artisan test --filter=ProvisioningStatusToolTest`
Expected: PASS.

- [ ] **Steg 4: Commit.**

```bash
cd $WT && git add app/Mcp/Tools/GetProvisioningStatusTool.php tests/Feature/Mcp/ProvisioningStatusToolTest.php && git commit -m "get_provisioning_status: vid deploy_failed i v2 säger next hur felet hittas lokalt"
```

---

### Task 11: Verifiering, säkerhetsgranskning och överlämning

**Files:**
- Inga nya filer (rättningar görs i den task som äger koden).

**Interfaces:**
- Consumes: allt ovan.

- [ ] **Steg 1: Hela sviten, stilen och guiderna.**

```bash
cd $WT && php artisan test
cd $WT && git diff --name-only --diff-filter=AM main...HEAD -- '*.php' | xargs vendor/bin/pint && git status --short
cd $WT && php artisan computercat:byggguide:verify v1 && php artisan computercat:byggguide:verify v2
```

Förväntat: PASS; ändrade filer efter pint committas som `Stil: pint` och sviten körs om; `Byggguide v1: OK (48 filer, …)` och `Byggguide v2: OK (54 filer, …)`.

- [ ] **Steg 2: Klass-svep (syskon).**

```bash
cd $WT && git diff main...HEAD --stat -- resources/byggguide/v1                       # förväntat: ingen utskrift
cd $WT && grep -n "'v1' => '7f763d8ee8d01c0765debcc225e40a5884c979035f4e3649f00815325699dc85'" app/Services/BuildGuide.php
cd $WT && grep -rn "createApplication(" app tests | grep -v "function createApplication"
cd $WT && grep -rn "BuildGuide::LATEST" app
cd $WT && grep -rn "BuildGuide::manifest(\|BuildGuide::publicManifest(\|BuildGuide::file(" app | grep -v "^app/Services/BuildGuide.php"
```

Förväntat: (1) v1 orörd och låst som förut; (2) varje anrop till `createApplication` skickar en version (bara `CoolifyAppStep` i app); (3) `LATEST` används bara som standardval för nya runs (`RepoStep`), verify och de publika ytorna; (4) varje publik läsning (`BuildGuideController`, `GetBuildGuideTool`, `GetBuildGuideFileTool`) föregås av `isPublished`, och övriga läsare (`RepoStep`, `FactBank`, `VerifyBuildGuide`, `BuildGuideHash`) läser bara låsta versioner eller är kommandon som avsiktligt läser olåsta. Avvikelse: rätta i ägartasken, eller AskUserQuestion om det inte går direkt.

- [ ] **Steg 3: Säkerhetsgranskning.** Invokera `security-first-mindset` och kör dess review gate på hela diffen (`cd $WT && git diff main...HEAD`) med en angripares ögon. Frågor som besvaras mot koden: Kan spelrepot stänga av spärren (nej, inte genom att ta bort eller ändra skripten eller `check`: `build_command` sätts av Computercat när appen skapas och `cc-gate.mjs` hämtas över https från den låsta versionens REST, inte från repot; S6)? Är gränsen ärligt beskriven överallt (R12: spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod, eftersom `npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön; `CLAUDE.md`, README och specen säger samma sak; S13: ingen `.git/config` i `/app`, token inte läsbar)? Kan någon byta skriptet på vägen (kommandot har ingen sha256, R14: integriteten vilar på att REST bara serverar låsta filer som `BuildGuide::read` kontrollerar mot manifestets sha256 före sändning, på https till vår egen server utan `-L`, och på att en låst version aldrig ändras eller tas bort; den som kan ändra computercat-api kan ändra spärren, och det är samma förtroende som för resten av API:t)? Byggs kommandot av något som en användare styr (nej: bara `APP_URL` och en konstant sökväg, och `coolifyBuildCommand` kontrollerar längd och tecken)? Når `NPM_CONFIG_PRODUCTION` npm i audit (nej: tas bort ur barnprocessens miljö; testat)? Skriver skriptet eller statusen ut en hemlighet (nej: bara fil och mönsternamn; `next` är en konstant)? Kan en olåst version serveras eller bakas in (nej: `isPublished` i REST, verktyg och `coolifyBuildCommand`)? Kan audit-delen släppa igenom ett fel (nej: ingen rapport ger två försök och sedan fel; high i rapporten fäller även vid exitkod 0; `CC_AUDIT_RETRY_DELAY_MS` kan bara korta väntan)?

- [ ] **Steg 4: `completion-gate`.** Invokera `completion-gate` mot specen (rad B i "Etapper", "Del 3", "Tester" B) och denna plan. Blockerande findings stoppar. Icke-blockerande buntas till **en** AskUserQuestion före överlämning.

- [ ] **Steg 5: Överlämning.** Rapportera till Erik (ett stycke): v2 är låst och senaste version, nya spel byggs i Coolify med säkerhetsspärren, v1 och Kattsvep är orörda, spikresultaten står i bilagan, och braces-utfallet (R8). Deploy är Eriks beslut; efter en deploy körs Task 12. Merge med `~/.claude/bin/wt-finish $WT --drop-db` eller `--keep-db` först när Erik sagt till (worktreen har egen databas; `--drop-db` kräver hans OK). Etapp C (artiklarna om spärren) kan börja när B är live.

---

### Task 12: Efter deploy (bara på Eriks order att deploya)

Stor lansering enligt CLAUDE.md: från deployen bygger varje nytt spel med ett nytt byggkommando. Ordningen är deploy → kontrollerna nedan → första v2-spelet byggt grönt → först därefter informeras Mattias, och bara på Eriks beslut.

**Files:** inga.

**Interfaces:**
- Consumes: deployad `main` med Task 2–10.

- [ ] **Steg 1: Prod serverar v2 och spärrskriptet med rätt sha256, sett från Coolify-servern.**

```bash
curl -fsS https://api.computercat.co/api/v1/build-guide | jq -c '[.data.version, .data.available_versions]'      # förväntat: ["v2",["v1","v2"]]
want=$(jq -r '.files[] | select(.path == "scripts/cc-gate.mjs") | .sha256' $WT/resources/byggguide/v2/manifest.json)
got=$(~/.claude/bin/ssh-claude --via-home erik@apps.humblebrag.se "curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs | sha256sum | cut -c1-64")
[ "$want" = "$got" ] && echo "cc-gate.mjs: OK" || echo "cc-gate.mjs: AVVIKER"
```

Förväntat: `["v2",["v1","v2"]]` och `cc-gate.mjs: OK`. `AVVIKER` eller fel: stoppa nya spel (fråga Erik om `create_game` ska pausas) och felsök innan något annat.

- [ ] **Steg 2: Kommandot i prod.** Skriv `$SCRATCH/tinker/cmd.php` med `echo App\Services\BuildGuide::coolifyBuildCommand('v2').PHP_EOL;` och kör `prodtinker $SCRATCH/tinker/cmd.php`. Förväntat exakt: `npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-gate.mjs -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs`.

- [ ] **Steg 2b: Sanctum i prod (localhost:3000 gav 419 före rättningen).** Bara läsande anrop utan sidoeffekt (ingen token, inget skapas):

```bash
for o in http://localhost:3000 https://api.computercat.co; do printf '%s → ' $o; curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Accept: application/json' -H "Origin: $o" -H "Referer: $o/" https://api.computercat.co/api/v1/auth/refresh; done
```

Förväntat: `http://localhost:3000 → 401` (inte längre stateful) och `https://api.computercat.co → 419` (API:ts egen värd är fortfarande stateful). Ger localhost:3000 fortfarande `419` sätter prod-`.env` troligen `SANCTUM_STATEFUL_DOMAINS`: stanna och fråga Erik innan `.env` läses eller ändras (hemlighetsfil).

- [ ] **Steg 3: Första v2-spelet.** Fråga Erik (AskUserQuestion) hur det första v2-bygget verifieras: (a) nästa riktiga spel är acceptansen, och jag följer dess första bygge med `get_provisioning_status` och byggloggens `check-security:`/`cc-audit:`-rader; (b) ett engångsspel via `create_game` nu, byggt av en routine, arkiverat med `archive_game` efteråt; (c) vänta. Mattias informeras först när ett v2-bygge varit `finished` med båda `OK`-raderna och Erik sagt till. Känd orsak till `failed` från deploy: ett nytt high-fynd i next, react eller zustand fäller varje nytt v2-bygge (avsiktligt enligt specen); `search_facts` och en uppdatering av byggguiden är vägen.

---

## Täckning mot specen (självgranskning)

| Specens krav (etapp B) | Task |
|---|---|
| Spike S1–S7 med engångsapp, städning på sparade id:n, stanna vid fel | 1 (S2 och plattformen), 7 (S1, S3–S7) |
| v2 ny låst version; v1 oförändrad; Kattsvep bygger som förut | 3–6, 8; Global Constraints; 11 steg 2 |
| `LATEST` byts i samma commit som `FROZEN['v2']` | 8 |
| `versions()` bara låsta; olåsta syns inte i verktyg och REST | 2 (R3) |
| `BuildGuideTest` parametriseras över låsta versioner | 2, 8 |
| v2-mallar: README med "byggguide v2", `CLAUDE.md` med v2 överallt och avsnittet "Faktabanken" | 3 |
| `cc-gate.mjs`, säkerhetsdelen: hemligheter med exakta mönster, inga `.map`, CSP i varje HTML med exakt policy och `connect-src` från `NEXT_PUBLIC_COMPUTERCAT_URL` | 4 |
| CSP i `src/app/layout.tsx` | 4 |
| `cc-gate.mjs`, audit-delen: fynd mot registerfel, ett omförsök efter 10 s, "npm-registret svarade inte", utan `NPM_CONFIG_PRODUCTION` | 5 |
| eslint-regler | 6 |
| `"test": "NODE_ENV=test vitest run"` | 3 |
| braces: ingen override, dev-only och accepterad (Eriks beslut); raden i `CLAUDE.md` och README | 3, 6 (R8), 8 |
| `npm run check` med `check-export.mjs` och `cc-gate.mjs` | 4 |
| `test/security.test.ts` med planterade värden byggda vid körning | 4 |
| `createApplication($slug, $version)`: byggkommandot (≤ 255 tecken, Coolifys teckenmönster) för v2, inget för v1 (test) | 9 |
| `CoolifyAppStep` skickar den frysta versionen | 9 |
| `get_provisioning_status` vid `deploy_failed`: v2-text, v1/saknat oförändrat | 10 |
| Tester B: verify v2, `security.test.ts`, `FROZEN`-hash, parametriserade tester, `CoolifyClient` v1/v2, statustext v1/v2 | 2–10, 11 |
| Gränsen (spärren hittar kända mönster, ersätter inte granskning) i artikeln `security` | Etapp C (utanför denna plan) |
