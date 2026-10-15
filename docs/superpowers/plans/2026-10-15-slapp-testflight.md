# Delprojekt 4, etapp 2 del 1: "Skicka till TestFlight" — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Spelets Claude höjer `version` i `package.json`, pushar, väntar på grönt Demo och anropar `promote_release(target: app_store, stage: testflight)`. Computercat nekar om versionen inte är högre än den senast släppta, taggar commiten `v<version>` i spelrepot, delar ut nästa byggnummer, startar `release-ios.yml` i släpprepot och följer körningen och Apples bearbetning tills bygget ligger i TestFlight-gruppen "ComputerCat intern", eller fäller släppet med orsak och Slack-larm. Engångsstegen för App Store (bundle-id, profil, TestFlight-grupp och testare) sköts via API; appposten skapar Erik med exakta värden som Computercat visar och sedan själv upptäcker.

**Architecture:** En ny App Store Connect-klient (`App\Releases\AppStore\AppStoreConnectApi`, ES256-JWT med lcobucci/jwt) bredvid den befintliga `GitHubAppClient`, som får läsning vid commit, taggar, dispatch och körningar. `ReleaseService::promote` får en förkontroll för TestFlight (`TestFlightPreflight`: läser `package.json` och `CLAUDE.md` vid commiten, versionsspärren, taggkollen) som körs före transaktionen, och delar ut byggnumret i transaktionen. Jobbet `AdvanceRelease` (samma mönster som `AdvanceProvisioningRun`) driver släppet genom `TestFlightBuilder`: starta (spärr 7/9 igen, profilkoll, tagg, dispatch med `return_run_details`), följa GitHub-körningen var 30:e sekund (60 min), följa Apples bearbetning var 2:a minut (90 min), lägga bygget i gruppen. Engångsstegen för `app_store` körs av jobbet `RunAppStoreSetup` (`AppStoreSetup`), idempotent och i ordning. Alla `next`-texter och flödestexten "Skicka till TestFlight" bor i `ReleaseNext`; ett test jämför dem med faktabanken och med byggguidens `CLAUDE.md` för varje version som stöder iOS. Släpprepot får `release-ios.yml` (fetch/build/sign) via PR.

**Tech Stack:** Laravel 12, PHP 8.4, laravel/mcp 1.0.2, Filament 3.3, lcobucci/jwt 5.6 (`Signer\Ecdsa\Sha256`), PHPUnit 11 (klasser), SQLite i minnet för test, MySQL i prod. GitHub REST API 2022-11-28. App Store Connect API (OpenAPI 4.5.1). GitHub Actions: `ubuntu-24.04`, `macos-26` (Xcode 26), Node 22, Capacitor 8.5.3 (SPM).

**Spec:** `docs/superpowers/specs/2026-10-14-slappflode-design.md`: Del 1 (modellen, spärrarna, engångssteg, `next`), Del 2 (alternativ 1, byggflöden, "Versionsflöde: Skicka till TestFlight", "Spikresultat som ändrar Del 2", Hemligheter, Statusläsning), Säkerhet, Tester. Bilaga `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (spike A och B) är källan för allt om GitHub Actions och Apple. Apples OpenAPI-spec (4.5.1) ligger nedladdad i sessionens scratchpad: `<scratchpad>/ascspec/spec/openapi.oas.json`; saknas den, hämta den på nytt från Apple (App Store Connect API, "Download the OpenAPI specification") innan Task 1. Varje fältnamn mot Apple i planen är kontrollerat mot den filen.

**Beroenden:**
- Etapp 1a (`releases`, `demo_builds`, `release_setup_steps`, spärrar, verktyg, Filament-sidan, faktabanksartiklarna) finns på `main`.
- **Byggguide v3 får en egen plan** (R1): achievements enligt `docs/superpowers/specs/2026-10-14-achievements-klient-design.md` (server och klient, med en öppen fråga till Erik) plus Capacitor iOS och versionsflödet i `CLAUDE.md`. Den här planen definierar kontraktet v3 ska uppfylla (Task 4) och testet som håller texten i synk (Task 9). Task 0–11 kräver inte v3. Task 12 (acceptans med ett riktigt v3-spel) kräver att v3 är låst och deployad.
- Spike A och B är klara (bilagan). Nyckeln `ComputerCat Release` (Key ID `N2SC66KSLW`, App Manager) ligger i prod som `storage/app/private/asc-release.p8` (600) och i släpprepot; certifikatet `9W6K83MH5B` (iOS Distribution) i släpprepot.

---

## Global Constraints

**Arbetssätt**
- Implementationen görs i en ny worktree med egen databas (grenen har migrationer): `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/slapp-testflight --db`. Sista raden på stdout är sökvägen, nedan `$WT`. Absoluta sökvägar; huvudkatalogen rörs aldrig; `EnterWorktree` används inte. `$SCRATCH` = `<sessionens scratchpad>/slapp-testflight` (`mkdir -p`).
- Testkommandon: `cd $WT && php artisan test --filter=<Klass>`. Hela sviten körs i Task 8 och Task 11.
- Commit-meddelanden på svenska, utan Co-Authored-By-rader. Kommentarer och artisan-utskrifter på svenska. Verktygssvar och felmeddelanden (`ReleaseRefused`, `last_error`) på engelska; `next`, engångsstegens `instruction` och flödestexten på svenska (1a R5).
- **Ingen åtgärd mot GitHub (utöver läsning med `gh api`), App Store Connect, Forge, Slack eller prod utan Eriks uttryckliga OK via AskUserQuestion.** Task 10 (PR och testkörning) och Task 12 frågar var för sig. `.env` i prod ändras bara med Eriks OK och med fil, nyckelnamn och skäl angivna (CLAUDE.md, secret-filer). Inga nyckelvärden skrivs i kod, test, loggar eller planen; `N2SC66KSLW`, issuer-id och `9W6K83MH5B` hör till `.env` (Eriks beslut) och står bara i Task 12:s instruktion för prod.
- `security-first-mindset` invokeras i början av Task 1 (hotmodell: nyckel, JWT, loggning), Task 7 (vad jobbet får göra i GitHub och Apple) och Task 10 (släpprepots flöde), och i Task 11 (review gate). `completion-gate` passeras i Task 11 innan något kallas klart.
- **Gissa aldrig ett fältnamn.** Apple: bara fält som står i OpenAPI 4.5.1 eller i bilagan. GitHub: Task 2 steg 1 läser referensen på docs.github.com för varje ny endpoint och skriver det i bilagan innan koden skrivs. Visar en riktig körning (Task 10, 12) något annat än planen förutsätter: stanna och fråga Erik.
- Inget HTTP-anrop inne i en databastransaktion. Varje testklass som kan nå HTTP använder `AssertsNoHttpInTransaction`. Jobb köas med `->afterCommit()` när de köas i en transaktion.
- Radering: inga raderingar mot GitHub eller Apple i koden. Klienterna har ingen DELETE (`ALLOWED_METHODS`). Taggar flyttas och raderas aldrig.

**Eriks beslut (bindande, 2026-10-15)**
- Bara TestFlight i denna plan. App Store-förberedelse (texter, skärmdumpar, åldersgräns, inskick) och produktionssteget `app_store` ingår inte; `releases.buildable_stages` ändras inte.
- Bundle-id: `se.computercat.<slug utan bindestreck>` (ersätter specens `co.humblebrag.`-form; Task 0 uppdaterar specen).
- Appen byggs bara när versionen höjs. Flödet (ordagrant i `ReleaseNext::TESTFLIGHT_FLOW`, faktabanken och v3:s `CLAUDE.md`): höj `version` i `package.json`, `npm run check`, pusha till `main`, vänta på grönt Demo, `promote_release(target: app_store, stage: testflight, commit_sha)`. Computercat nekar om versionen inte är högre än senast släppta, sätter taggen `v<version>` via ComputerCat Studio (Contents write), väljer nästa byggnummer (stigande per app, sparat i Computercat), dispatchar `release-ios.yml` med `return_run_details` och indata `game`, `commit_sha`, `release_uuid`, `build_number`, `marketing_version`, `bundle_id`, `profile_id` (plus `build_guide`, R2), och följer körningen och Apples bearbetning (`AdvanceRelease`).
- Engångssteg `app_store`: bundle-id och profil automatiskt via API; appposten manuellt av Erik (namn, bundle-id, SKU = slug, svenska); TestFlight-gruppen "ComputerCat intern" och interna testare via API.
- Släpprepot ändras bara via PR; `release-ios.yml` ersätter `release-ios-spike.yml`; jobben `fetch` (ubuntu, ComputerCat Release Reader via `CC_READER_APP_ID`/`CC_READER_PRIVATE_KEY`), `build` (`macos-26`, inga hemligheter), `sign` (`ASC_*`, `IOS_DIST_CERT_*`, uppladdning via `buildUploads`). `cc-gate.mjs` körs i `build` med sha256 ur manifestet för spelets byggguideversion.
- Computercat i prod har ASC-nyckeln som `storage/app/private/asc-release.p8`; key id, issuer och certifikat-id i config/env.

**Rulings i denna plan (Erik ska känna till dem)**
- **R1: byggguide v3 får egen plan.** Achievements-delen är för stor för samma plan: serverändringar i `AchievementController` med egna tester, en ny klientmodul med kö, ändrad bakgrundssynk, integrationsmanualen, och en öppen fråga till Erik om rimlighetskontrollen (specens fråga 1). v3 kan bara låsas en gång, så achievements och Capacitor iOS måste in i samma version. Förslag: planen "Byggguide v3: achievements och Capacitor iOS" skrivs efter denna, uppfyller kontraktet i Task 4 och Task 9:s synktest, och denna plans Task 12 väntar på att v3 är live.
- **R2: indata `build_guide` utöver Eriks sju.** Släpprepot måste veta vilken sha256 `cc-gate.mjs` ska ha; den står per version i flödet (specen: "hashen står i släpprepots flöde"). Computercat läser versionen ur spelets `CLAUDE.md` vid commiten (`Byggguide: **vN**`) och kräver att versionen stöder iOS (Task 4), så att ett spel inte kan välja en äldre spärr. *Fråga till Erik i rapporten.*
- **R3: versionsspärren och taggen.** Versionen jämförs med den högsta `marketing_version` bland `succeeded`-släpp till TestFlight för spelet (Eriks regel). Dessutom: finns taggen `v<version>` redan på en annan commit nekas släppet och svaret säger att versionen ska höjas. Samma commit kan alltså försökas igen med samma version efter ett misslyckat släpp, men en ny commit kräver alltid en ny version. Taggen flyttas aldrig. *Fråga till Erik i rapporten.*
- **R4: taggen sätts av jobbet, inte av verktyget**, precis före dispatch, som lättviktig tagg (`POST /git/refs`). Verktyget kontrollerar bara att taggen är ledig eller redan pekar på commiten, så att Claude får nej direkt.
- **R5: byggnumret delas ut i befordrans transaktion** (`games.ios_build_number + 1`, spelets rad är redan låst, spärr 4). Ett misslyckat släpp lämnar en lucka; ett nummer används aldrig två gånger (unikt index `(game_id, build_number)` på `releases`).
- **R6: engångsstegen gäller per steg.** `appstore_privacy_and_age` stoppar bara produktionssteget `app_store`, inte `testflight` (internt TestFlight kräver det inte, bilagan "Avtal"). Övriga steg stoppar båda. `SetupSteps::BLOCKS` styr det; spärr 6 och statusen använder `SetupSteps::openFor()`.
- **R7: gruppen skapas med `hasAccessToAllBuilds: false`** och varje bygge läggs till uttryckligen (`POST /v1/betaGroups/{id}/relationships/builds`, 204). Spike B använde `true`; med `false` är "lägga bygget i gruppen" ett dokumenterat anrop i stället för ett implicit beteende. Kontrolleras i Task 12.
- **R8: appposten upptäcks av Computercat.** Steget `appstore_create_app` är Eriks, men både jobbet och Filaments "Bocka av" kontrollerar med `GET /v1/apps?filter[bundleId]` och sätter steget klart först när appen finns. Erik behöver alltså bara skapa appen.
- **R9: `get_release_status` gör fortfarande inga externa anrop** (1a R11). Versionen i Demo-commitens `package.json` är därför okänd i statusen; den kontrolleras i `promote_release`, som svarar exakt vad som ska höjas.
- **R10: inga Actions-loggar kopieras in.** Felorsaken vid en misslyckad körning är jobbets och stegets namn från `GET …/actions/runs/{id}/jobs` (namnen står i släpprepots flöde). Revisionen sparar släpprepots `head_sha` för körningen ("vilken version av släppflödet som kördes", specens Säkerhet).
- **R11: tidsgränser** räknas från dispatch (GitHub, 60 min; körningen avbryts med `POST …/cancel` för att spara macOS-minuter) och från körningens slut (Apple, 90 min). Pollning var 30:e sekund (GitHub) och 120:e (Apple). Ett svep var femte minut (`computercat:advance-releases`) köar om jobbet för pågående TestFlight-släpp; `ShouldBeUnique` hindrar dubbletter.
- **R12: bundle-id i Computercat är sanningen.** Byggjobbet ändrar inget i spelets iOS-projekt; det kontrollerar att `appId` efter `cap sync` och varje `PRODUCT_BUNDLE_IDENTIFIER` i `project.pbxproj` är `bundle_id`, att `package.json` har `marketing_version`, och att `ITSAppUsesNonExemptEncryption` är `false`, och fäller annars med ett namngivet steg.
- **R13: profilen förnyas inte automatiskt.** Före varje dispatch läses profilen; är den inte `ACTIVE` eller går ut inom ett dygn fälls släppet med en text till Erik. Certifikatet gäller till 2027-10-15.
- **R14: släpp registrerade i 1a utan version** (`requested` för `testflight` utan `marketing_version`) fälls av svepet med texten "registrerat innan TestFlight-byggen fanns; begär ett nytt", i stället för att byggas med gissade värden.

**Värden**
- Bundle-id: `se.computercat.` + slug utan `-`; mönster `^se\.computercat\.[a-z][a-z0-9]{1,39}\z`. SKU = slug. Appnamn = spelets `name`. Primärspråk svenska.
- Version: `^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\z`. Tagg: `v` + version.
- Släpprepot: `HumblebragAB/computercat-release`, flöde `release-ios.yml`, ref `main`. `run-name`: `ios <game> <release_uuid>`.
- TestFlight-grupp: `ComputerCat intern`, intern. Testare: `ASC_TESTFLIGHT_TESTERS` (kommaseparerade e-postadresser för användare i App Store Connect).
- Env (namn, inga värden i repot): `ASC_RELEASE_KEY_ID`, `ASC_RELEASE_ISSUER_ID`, `ASC_RELEASE_PRIVATE_KEY_PATH` (standard `app/private/asc-release.p8`), `ASC_RELEASE_CERTIFICATE_ID`, `ASC_TESTFLIGHT_TESTERS`.
- v2:s `scripts/cc-gate.mjs`: sha256 `e4460881ca534a085c500bb0c768e4ea9a1d6c7d7c2f33babf4e7ef8c8969009` (manifestet). v3:s läggs till i släpprepot när v3 är låst (Task 12).
- Team-id i ExportOptions: `CUDL7PSFD2` (spikflödet).

---

## Review Focus

De fem fel som lätt passerar oupptäckta. Varje rad har ett test i den task som äger koden.

1. **Versionsspärren släpper igenom fel version eller stoppar ett rättmätigt omförsök.** Lägre eller lika med senast `succeeded`, ett misslyckat släpp som felaktigt räknas som släppt, versioner som jämförs som strängar (`1.10.0` mot `1.9.0`), en tagg på en annan commit. Test: `TestFlightPromoteTest::test_version_must_be_higher_than_the_latest_succeeded_release`, `test_versions_compare_numerically`, `test_failed_release_does_not_block_a_retry_of_the_same_commit`, `test_tag_on_another_commit_is_refused_with_bump_advice` (Task 6).
2. **Ett byggnummer används två gånger, eller dispatchen sker två gånger.** Två jobb för samma släpp, ett dispatch-svar som går förlorat, en omkörning efter krasch. Test: `ReleaseModelTest::test_build_number_is_unique_per_game` (Task 3), `TestFlightPromoteTest::test_build_numbers_never_repeat` (Task 6), `TestFlightBuilderTest::test_lost_dispatch_response_is_recovered_by_run_name` och `test_start_never_dispatches_twice` (Task 7).
3. **Släppet fastnar tyst.** En körning som avbryts utanför Computercat, en körning som aldrig blir klar, ett bygge Apple aldrig bearbetar, ett jobb som dör i kön. Test: `TestFlightBuilderTest::test_run_cancelled_outside_computercat_fails_with_its_conclusion`, `test_run_timeout_cancels_and_alerts`, `test_apple_timeout_fails_and_alerts`, `AdvanceReleaseTest::test_failed_handler_marks_the_release_failed`, `test_sweep_requeues_active_releases` (Task 7), `CheckReleasesTest::test_testflight_release_stuck_in_requested_is_reported` (Task 7).
4. **Hemligheter läcker.** JWT, p8-nyckeln eller installationstoken i `last_error`, i loggen vid 4xx/5xx, i Slack eller i verktygssvar. Test: `AppStoreConnectApiTest::test_4xx_is_logged_with_request_and_response_and_the_jwt_never_appears`, `test_world_readable_key_is_refused_before_any_request` (Task 1), `TestFlightBuilderTest::test_failure_text_is_redacted` (Task 7).
5. **Engångsstegen räknas fel.** Integritetssteget stoppar TestFlight, ett manuellt steg bockas av fast appen inte finns, ett automatiskt steg kan bockas av för hand, två slugs får samma bundle-id. Test: `SetupStepsTest::test_privacy_step_blocks_only_the_app_store_stage`, `AppStoreSetupTest::test_create_app_step_is_done_only_when_apple_has_the_app`, `GameReleasesPageTest::test_marking_create_app_checks_apple_first`, `SetReleaseTargetsToolTest::test_colliding_bundle_id_is_refused` (Task 5).

---

## Filstruktur

| Fil | Ansvar |
|---|---|
| `config/releases.php` (ändras) | `app_store`, `ios_workflow`, `timeouts`, `poll_seconds`, `start_stuck_minutes` |
| `.env.example` (ändras) | nyckelnamnen utan värden |
| `app/Releases/AppStore/AppStoreConfig.php`, `AppStoreNotConfigured.php` | config och kontroll av nyckelfilen |
| `app/Releases/AppStore/AppStoreConnectApi.php` | ES256-JWT och de anrop etapp 2 behöver |
| `app/Releases/AppStore/BundleId.php`, `app/Releases/MarketingVersion.php` | format och jämförelse |
| `app/Provisioning/Clients/GitHubAppClient.php` (ändras) | fil vid commit, taggar, dispatch, körningar |
| `database/migrations/2026_10_17_10000{1,2}_*.php` | kolumner; bundle-id och engångssteg för befintliga `app_store`-spel |
| `app/Models/Game.php`, `Release.php`, `ReleaseSetupStep.php` (ändras) | casts, fillable, `appStoreId()` |
| `app/Services/BuildGuide.php` (ändras), `app/Releases/TestFlight/IosBuildGuides.php` | vilka byggguideversioner som stöder iOS |
| `app/Releases/SetupSteps.php` (ändras) | mallar, `BLOCKS`, `openFor()`, `render()` |
| `app/Releases/AppStore/AppStoreSetup.php`, `app/Jobs/RunAppStoreSetup.php` | automatiska engångssteg |
| `app/Releases/TestFlight/TestFlightPreflight.php`, `PreflightResult.php` | förkontroll vid befordran |
| `app/Releases/ReleaseService.php`, `ReleaseGate.php`, `ReleaseCatalog.php` (ändras) | befordran med byggnummer, spärr 6 per steg, `BUILDERS` |
| `app/Releases/TestFlight/TestFlightBuilder.php`, `ReleaseFailed.php`, `app/Jobs/AdvanceRelease.php` | släppets livscykel |
| `app/Console/Commands/AdvanceReleases.php`, `routes/console.php`, `CheckProvisioning.php` (ändras) | svep och bevakning |
| `app/Releases/ReleaseNext.php`, `ReleaseStatus.php` (ändras) | texter och status |
| `app/Mcp/Tools/PromoteReleaseTool.php`, `GetReleaseStatusTool.php`, `SetReleaseTargetsTool.php` (ändras) | verktygen |
| `app/Filament/Resources/GameResource/Pages/GameReleases.php`, vyn (ändras) | App Store-avsnitt, verifierad avbockning, kör steg igen |
| `resources/faktabank/process/process-slappflode.md`, `process-slapp-engangssteg.md` (ändras) | artiklarna |
| `tests/Support/AppStoreFakes.php`, `ProvisioningFakes.php`, `CreatesReleases.php` (ändras) | fejkar och hjälpare |
| Släpprepot: `.github/workflows/release-ios.yml`, `scripts/asc-profile.mjs`, `README.md` | flödet (PR) |

---

### Task 0: Specen och bilagan efter Eriks beslut 2026-10-15

**Files:**
- Modify: `docs/superpowers/specs/2026-10-14-slappflode-design.md`
- Modify: `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md`

**Interfaces:**
- Produces: en spec som stämmer med koden i Task 1–10 (bundle-id-formen, engångsstegen per steg, indata, versions- och taggregeln).

- [ ] **Steg 1: Skapa worktreen.**

```bash
~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/slapp-testflight --db
```

Spara sista raden som `$WT`. `SCRATCH=<scratchpad>/slapp-testflight && mkdir -p $SCRATCH`. Kontrollera att Apples spec finns: `test -f <scratchpad>/ascspec/spec/openapi.oas.json && python3 -I -c "import json,sys;print(json.load(open(sys.argv[1]))['info']['version'])" <scratchpad>/ascspec/spec/openapi.oas.json`. Förväntat: `4.5.1`. Saknas filen: stanna och fråga Erik var den ska hämtas.

- [ ] **Steg 2: Specen.** Gör exakt dessa ändringar (Edit-verktyget):
1. Under "Engångssteg per spel och butik", ersätt stycket som börjar "Bundle-id och paketnamn: `co.humblebrag.<slug-utan-bindestreck>`" med: "Bundle-id: `se.computercat.<slug utan bindestreck>` (Eriks beslut 2026-10-15). Paketnamnet för Android beslutas i etapp 3. Värdet sparas på spelet (`games.bundle_id`) med unikt index; krockar två slugs (`ab-c` och `abc`) vägrar `set_release_targets`."
2. I samma avsnitt, punkten `app_store`, ersätt med: "`app_store`: registrera bundle-id och skapa App Store-profilen (automatiskt via API), skapa appen i App Store Connect med namn, bundle-id, SKU = slug och svenska (Erik, manuellt; Computercat ser själv när appen finns), skapa TestFlight-gruppen "ComputerCat intern" och lägg till de interna testarna (automatiskt), och integritetsuppgifter och åldersgräns (Erik; stoppar bara produktionssteget `app_store`)."
3. Under spärr 6, lägg till meningen: "Ett engångssteg stoppar de steg det gäller (`SetupSteps::BLOCKS`); integritetsuppgifter och åldersgräns stoppar inte TestFlight."
4. I "Versionsflöde: Skicka till TestFlight", punkt 3, lägg till efter första meningen: "Finns taggen `v<version>` redan på en annan commit nekas släppet också: samma commit kan försökas igen med samma version, en ny commit kräver en ny version. Taggen flyttas aldrig." Lägg till sist i avsnittet: "Indata till `release-ios.yml`: `game`, `commit_sha`, `release_uuid`, `build_number`, `marketing_version`, `bundle_id`, `profile_id` och `build_guide` (byggguideversionen ur spelets `CLAUDE.md` vid commiten, som väljer sha256 för `cc-gate.mjs`)."
5. Under "Statusläsning", lägg till: "TestFlight-gruppen skapas med `hasAccessToAllBuilds: false` och varje bygge läggs till i gruppen när Apple satt `processingState` till `VALID`."

- [ ] **Steg 3: Bilagan.** Lägg till sist i bilagan ett avsnitt `## Etapp 2 del 1: vad koden bygger på` med en mening per punkt: dispatch med `return_run_details` (G1b) är huvudvägen, `run-name` reserven (G2); `buildUploads` sker i sign-jobbet och Computercat läser bara `GET /v1/builds`; gruppen med `hasAccessToAllBuilds: false` (R7) är inte prövad i spiken och kontrolleras i Task 12; GitHub-endpoints som inte prövats i spiken (git refs, contents med `ref`, runs/{id}, runs/{id}/jobs, runs/{id}/cancel, workflow runs-listan) dokumenteras i Task 2.

- [ ] **Steg 4: Commit.**

```bash
cd $WT && git add docs/superpowers/specs && git commit -m "Spec: bundle-id se.computercat, engångssteg per steg, taggregeln och indata för TestFlight (Eriks beslut 2026-10-15)"
```

---

### Task 1: Konfiguration och App Store Connect-klienten

**Files:**
- Modify: `config/releases.php`, `.env.example`, `phpunit.xml`
- Create: `app/Releases/AppStore/AppStoreConfig.php`, `app/Releases/AppStore/AppStoreNotConfigured.php`, `app/Releases/AppStore/AppStoreConnectApi.php`
- Create: `tests/Support/AppStoreFakes.php`, `tests/Feature/Releases/AppStoreConnectApiTest.php`

**Interfaces:**
- Produces (används av Task 5 och 7):
  - `AppStoreConfig::missing(): list<string>`, `assertKeyReady(): void`, `keyId()`, `issuerId()`, `certificateId()`, `privateKeyFile()`, `testers(): list<string>`, `betaGroup(): string`.
  - `AppStoreConnectApi`: `jwt(?DateTimeImmutable): string`, `findBundleId(string): ?array{id,identifier}`, `createBundleId(string $identifier, string $name): array{id,identifier}`, `findApp(string $bundleId): ?array{id,name,sku,bundle_id}`, `findProfile(string $name): ?array{id,uuid,state,expires_at}`, `createProfile(string $name, string $bundleResourceId, string $certificateId): array{id,uuid,state,expires_at}`, `profile(string $id): ?array{id,uuid,state,expires_at}`, `findBetaGroup(string $appId, string $name): ?array{id,name}`, `createInternalBetaGroup(string $appId, string $name): array{id,name}`, `findBetaTester(string $email, ?string $groupId = null): ?array{id,email}`, `createBetaTester(string $email, string $groupId): array{id,email}`, `addTestersToGroup(string $groupId, list<string> $testerIds): void`, `findBuild(string $appId, string $marketingVersion, int $buildNumber): ?array{id,state,version,expired}`, `buildIsInGroup(string $groupId, string $buildId): bool`, `addBuildToGroup(string $groupId, string $buildId): void`.
  - Fel: `ExternalApiException` (redigerat, `SafeMessage`), `AppStoreNotConfigured` (`SafeMessage`).

- [ ] **Steg 1: Hotmodell.** Invokera `security-first-mindset`. Ytor: den privata nyckeln (fil 600, läses bara av `InMemory::file`, aldrig in i en sträng som loggas), JWT (15 min, maskeras av `Redactor`s `eyJ…`-mönster), loggning vid 4xx/5xx (request och response, redigerat, 4 KB), id:n från Apple som hamnar i URL:er (valideras), inga skrivande anrop utöver de listade, ingen DELETE. Skriv resultatet som kommentar överst i `AppStoreConnectApi.php` (redan med i koden nedan).

- [ ] **Steg 2: Konfigurationen.** Lägg till sist i arrayen i `config/releases.php` (före `];`):

```php
    // Etapp 2 (TestFlight). Nyckel-id, issuer och certifikat-id är inte hemliga men hör till
    // miljön (Eriks beslut 2026-10-15). Den privata nyckeln är en fil i storage/app/private (läge 600).
    'app_store' => [
        'api_url' => 'https://api.appstoreconnect.apple.com',
        'key_id' => env('ASC_RELEASE_KEY_ID'),
        'issuer_id' => env('ASC_RELEASE_ISSUER_ID'),
        // Relativ till storage/.
        'private_key_path' => env('ASC_RELEASE_PRIVATE_KEY_PATH', 'app/private/asc-release.p8'),
        'certificate_id' => env('ASC_RELEASE_CERTIFICATE_ID'),
        'beta_group' => 'ComputerCat intern',
        // Interna testare måste vara användare i App Store Connect (spike B9). Kommaseparerade e-postadresser.
        'testers' => env('ASC_TESTFLIGHT_TESTERS', ''),
    ],

    // Släpprepots flöde. Computercat startar det alltid på släpprepots egen huvudgren.
    'ios_workflow' => [
        'repo' => 'computercat-release',
        'workflow' => 'release-ios.yml',
        'ref' => 'main',
    ],

    'timeouts' => [
        'github_run_minutes' => 60,
        'apple_processing_minutes' => 90,
        // Så länge letas en körning upp via run-name när dispatch-svaret gick förlorat.
        'dispatch_lookup_minutes' => 3,
    ],

    'poll_seconds' => [
        'github' => 30,
        'apple' => 120,
    ],

    // Ett TestFlight-släpp i requested längre än så här larmar nattkontrollen (jobbet har inte startat).
    'start_stuck_minutes' => 30,
```

I `.env.example`, lägg till efter GitHub-blocket:

```
# Släppflödet etapp 2 (App Store Connect-nyckeln ComputerCat Release, roll App Manager). Värdena står i prod-.env, aldrig i repot.
ASC_RELEASE_KEY_ID=
ASC_RELEASE_ISSUER_ID=
ASC_RELEASE_PRIVATE_KEY_PATH=app/private/asc-release.p8
ASC_RELEASE_CERTIFICATE_ID=
ASC_TESTFLIGHT_TESTERS=
```

I `phpunit.xml`, lägg till bredvid de andra tomma env-raderna (så att en lokal `.env` aldrig når testerna):

```xml
        <env name="ASC_RELEASE_KEY_ID" value=""/>
        <env name="ASC_RELEASE_ISSUER_ID" value=""/>
        <env name="ASC_RELEASE_PRIVATE_KEY_PATH" value=""/>
        <env name="ASC_RELEASE_CERTIFICATE_ID" value=""/>
        <env name="ASC_TESTFLIGHT_TESTERS" value=""/>
```

- [ ] **Steg 3: Fejkarna.** Skapa `tests/Support/AppStoreFakes.php`:

```php
<?php

namespace Tests\Support;

// Konfiguration och svar för App Store Connect i testerna. Formen följer Apples OpenAPI 4.5.1
// (data: {type, id, attributes}); id:n och namn är spike B:s där de finns (bilagan). Nyckeln är
// en P-256-nyckel som skapas här; de riktiga värdena finns bara i prod.
final class AppStoreFakes
{
    public const API = 'https://api.appstoreconnect.apple.com';

    public const KEY_FILE = 'framework/testing/asc-release-test.p8';

    public const KEY_ID = 'TESTKEY001';

    public const ISSUER = '00000000-0000-4000-8000-000000000000';

    public const CERT = 'TESTCERT01';

    public const TESTERS = 'erik@example.test,mattias@example.test';

    public const BUNDLE_RESOURCE = 'WN54V4BM5D';

    public const APP = '6823067635';

    public const PROFILE = 'PROFTEST01';

    public const GROUP = '0f1e2d3c-0000-4000-8000-000000000001';

    private static ?array $keyPair = null;

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        if (self::$keyPair === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($key, $private);
            self::$keyPair = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$keyPair;
    }

    public static function configure(int $mode = 0600): void
    {
        $path = storage_path(self::KEY_FILE);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, self::keyPair()['private']);
        chmod($path, $mode);

        config([
            'releases.app_store.key_id' => self::KEY_ID,
            'releases.app_store.issuer_id' => self::ISSUER,
            'releases.app_store.private_key_path' => self::KEY_FILE,
            'releases.app_store.certificate_id' => self::CERT,
            'releases.app_store.testers' => self::TESTERS,
        ]);
    }

    public static function one(array $resource): array
    {
        return ['data' => $resource, 'links' => ['self' => self::API.'/v1/x']];
    }

    public static function many(array ...$resources): array
    {
        return ['data' => array_values($resources), 'links' => ['self' => self::API.'/v1/x'], 'meta' => ['paging' => ['total' => count($resources), 'limit' => 200]]];
    }

    public static function bundleId(string $identifier, string $id = self::BUNDLE_RESOURCE): array
    {
        return ['type' => 'bundleIds', 'id' => $id, 'attributes' => ['identifier' => $identifier, 'name' => 'ComputerCat Kattsvep', 'platform' => 'IOS', 'seedId' => 'CUDL7PSFD2']];
    }

    public static function app(string $bundleId, string $id = self::APP, string $name = 'Kattsvep', string $sku = 'kattsvep'): array
    {
        return ['type' => 'apps', 'id' => $id, 'attributes' => ['name' => $name, 'bundleId' => $bundleId, 'sku' => $sku, 'primaryLocale' => 'sv']];
    }

    public static function profile(string $id = self::PROFILE, string $state = 'ACTIVE', ?string $expires = null, string $name = 'ComputerCat kattsvep App Store'): array
    {
        return ['type' => 'profiles', 'id' => $id, 'attributes' => [
            'name' => $name, 'profileType' => 'IOS_APP_STORE', 'profileState' => $state,
            'uuid' => '6b1c2a4e-0000-4000-8000-00000000abcd', 'expirationDate' => $expires ?? now()->addYear()->toIso8601String(),
        ]];
    }

    public static function betaGroup(string $id = self::GROUP, string $name = 'ComputerCat intern', bool $internal = true): array
    {
        return ['type' => 'betaGroups', 'id' => $id, 'attributes' => ['name' => $name, 'isInternalGroup' => $internal, 'hasAccessToAllBuilds' => false]];
    }

    public static function betaTester(string $email, string $id): array
    {
        return ['type' => 'betaTesters', 'id' => $id, 'attributes' => ['email' => $email, 'inviteType' => 'EMAIL', 'state' => 'INVITED']];
    }

    public static function build(string $id, int $buildNumber, string $state, bool $expired = false): array
    {
        return ['type' => 'builds', 'id' => $id, 'attributes' => ['version' => (string) $buildNumber, 'processingState' => $state, 'expired' => $expired, 'uploadedDate' => now()->toIso8601String()]];
    }

    /** Felsvar enligt ErrorResponse (id, status, code, title, detail). */
    public static function error(int $status, string $code, string $detail): array
    {
        return ['errors' => [['id' => 'err-1', 'status' => (string) $status, 'code' => $code, 'title' => 'Error', 'detail' => $detail]]];
    }
}
```

- [ ] **Steg 4: Testerna (fallerar).** Skapa `tests/Feature/Releases/AppStoreConnectApiTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Provisioning\Http\ExternalApiException;
use App\Releases\AppStore\AppStoreConnectApi;
use App\Releases\AppStore\AppStoreNotConfigured;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\Support\AppStoreFakes as A;
use Tests\TestCase;

class AppStoreConnectApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        A::configure();
    }

    private function api(): AppStoreConnectApi
    {
        return app(AppStoreConnectApi::class);
    }

    public function test_jwt_is_es256_with_key_id_issuer_audience_and_15_minutes(): void
    {
        $token = (new Parser(new JoseEncoder))->parse($this->api()->jwt(new \DateTimeImmutable('@1760000000')));

        $this->assertTrue((new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText(A::keyPair()['public']))));
        $this->assertSame('ES256', $token->headers()->get('alg'));
        $this->assertSame(A::KEY_ID, $token->headers()->get('kid'));
        $this->assertSame(A::ISSUER, $token->claims()->get('iss'));
        // Apple kräver aud som sträng, inte lista.
        $payload = json_decode(base64_decode(strtr(explode('.', $token->toString())[1], '-_', '+/')), true);
        $this->assertSame('appstoreconnect-v1', $payload['aud']);
        $this->assertSame(900, $payload['exp'] - $payload['iat']);
    }

    public function test_world_readable_key_is_refused_before_any_request(): void
    {
        A::configure(0644);
        Http::fake();

        try {
            $this->api()->findApp('se.computercat.kattsvep');
            $this->fail('Ingen kontroll av nyckelfilen.');
        } catch (AppStoreNotConfigured $e) {
            $this->assertStringContainsString('ASC_RELEASE_PRIVATE_KEY_PATH', $e->getMessage());
            $this->assertStringNotContainsString(A::KEY_ID, $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_key_id_is_refused(): void
    {
        config(['releases.app_store.key_id' => '']);
        Http::fake();

        $this->expectException(AppStoreNotConfigured::class);
        $this->api()->findApp('se.computercat.kattsvep');
    }

    public function test_find_bundle_id_matches_exactly_and_create_sends_ios_platform(): void
    {
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep2', 'OTHER00001'), A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/bundleIds' => Http::response(A::one(A::bundleId('se.computercat.ny', 'NEWID00001')), 201),
        ]);

        $this->assertSame(['id' => A::BUNDLE_RESOURCE, 'identifier' => 'se.computercat.kattsvep'], $this->api()->findBundleId('se.computercat.kattsvep'));
        $this->assertSame('NEWID00001', $this->api()->createBundleId('se.computercat.ny', 'ComputerCat Ny')['id']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'filter[identifier]=se.computercat.kattsvep')
            && str_contains(urldecode($r->url()), 'filter[platform]=IOS') && str_starts_with($r->header('Authorization')[0], 'Bearer eyJ'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => ['type' => 'bundleIds', 'attributes' => ['identifier' => 'se.computercat.ny', 'name' => 'ComputerCat Ny', 'platform' => 'IOS']]]);
    }

    public function test_find_app_by_bundle_id_returns_id_name_and_sku(): void
    {
        Http::fake([A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep')))]);

        $this->assertSame(['id' => A::APP, 'name' => 'Kattsvep', 'sku' => 'kattsvep', 'bundle_id' => 'se.computercat.kattsvep'], $this->api()->findApp('se.computercat.kattsvep'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[bundleId]=se.computercat.kattsvep'));
    }

    public function test_app_with_another_bundle_id_is_not_returned(): void
    {
        Http::fake([A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvepx')))]);

        $this->assertNull($this->api()->findApp('se.computercat.kattsvep'));
    }

    public function test_create_profile_sends_app_store_type_bundle_and_certificate(): void
    {
        Http::fake([A::API.'/v1/profiles' => Http::response(A::one(A::profile()), 201)]);

        $profile = $this->api()->createProfile('ComputerCat kattsvep App Store', A::BUNDLE_RESOURCE, A::CERT);

        $this->assertSame(A::PROFILE, $profile['id']);
        $this->assertSame('ACTIVE', $profile['state']);
        Http::assertSent(fn (Request $r) => $r->data() === ['data' => [
            'type' => 'profiles',
            'attributes' => ['name' => 'ComputerCat kattsvep App Store', 'profileType' => 'IOS_APP_STORE'],
            'relationships' => [
                'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => A::BUNDLE_RESOURCE]],
                'certificates' => ['data' => [['type' => 'certificates', 'id' => A::CERT]]],
            ],
        ]]);
    }

    public function test_profile_is_read_without_profile_content(): void
    {
        Http::fake([A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile(state: 'INVALID')))]);

        $this->assertSame('INVALID', $this->api()->profile(A::PROFILE)['state']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'fields[profiles]=name,profileState,expirationDate,uuid'));
    }

    public function test_beta_group_is_found_by_exact_internal_name_and_created_without_access_to_all_builds(): void
    {
        Http::fake([
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup('ext-1', 'ComputerCat intern', false), A::betaGroup())),
            A::API.'/v1/betaGroups' => Http::response(A::one(A::betaGroup('new-1')), 201),
        ]);

        $this->assertSame(A::GROUP, $this->api()->findBetaGroup(A::APP, 'ComputerCat intern')['id']);
        $this->assertSame('new-1', $this->api()->createInternalBetaGroup(A::APP, 'ComputerCat intern')['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => [
            'type' => 'betaGroups',
            'attributes' => ['name' => 'ComputerCat intern', 'isInternalGroup' => true, 'hasAccessToAllBuilds' => false],
            'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => A::APP]]],
        ]]);
    }

    public function test_beta_tester_is_created_in_the_group_and_existing_ones_are_linked(): void
    {
        Http::fake([
            A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('erik@example.test', 'tester-1')), 201),
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/betaTesters' => Http::response('', 204),
        ]);

        $this->assertSame('tester-1', $this->api()->createBetaTester('erik@example.test', A::GROUP)['id']);
        $this->api()->addTestersToGroup(A::GROUP, ['tester-2']);

        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/betaTesters' && $r->data() === ['data' => [
            'type' => 'betaTesters',
            'attributes' => ['email' => 'erik@example.test'],
            'relationships' => ['betaGroups' => ['data' => [['type' => 'betaGroups', 'id' => A::GROUP]]]],
        ]]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/betaTesters') && $r->data() === ['data' => [['type' => 'betaTesters', 'id' => 'tester-2']]]);
    }

    public function test_find_build_filters_on_app_version_and_build_number(): void
    {
        Http::fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-1', 7, 'VALID')))]);

        $this->assertSame(['id' => 'build-1', 'state' => 'VALID', 'version' => '7', 'expired' => false], $this->api()->findBuild(A::APP, '1.2.0', 7));
        Http::assertSent(function (Request $r) {
            $url = urldecode($r->url());

            return str_contains($url, 'filter[app]='.A::APP) && str_contains($url, 'filter[version]=7') && str_contains($url, 'filter[preReleaseVersion.version]=1.2.0');
        });
    }

    public function test_build_is_added_to_the_group_with_a_linkage(): void
    {
        Http::fake([
            A::API.'/v1/builds?*' => Http::response(A::many()),
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/builds' => Http::response('', 204),
        ]);

        $this->assertFalse($this->api()->buildIsInGroup(A::GROUP, 'build-1'));
        $this->api()->addBuildToGroup(A::GROUP, 'build-1');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[betaGroups]='.A::GROUP) && str_contains(urldecode($r->url()), 'filter[id]=build-1'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => [['type' => 'builds', 'id' => 'build-1']]]);
    }

    public function test_4xx_is_logged_with_request_and_response_and_the_jwt_never_appears(): void
    {
        Log::spy();
        Http::fake([A::API.'/v1/bundleIds' => Http::response(A::error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE', 'An identifier with that value already exists.'), 409)]);

        try {
            $this->api()->createBundleId('se.computercat.kattsvep', 'ComputerCat Kattsvep');
            $this->fail('409 kastades inte.');
        } catch (ExternalApiException $e) {
            $this->assertStringContainsString('App Store Connect POST /v1/bundleIds returned 409', $e->getMessage());
            $this->assertStringContainsString('DUPLICATE', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]+\./', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'App Store Connect API svarade 409')
            && str_contains((string) $ctx['request'], 'se.computercat.kattsvep')
            && str_contains((string) $ctx['response'], 'DUPLICATE')
            && ! str_contains(json_encode($ctx), 'eyJ'))->once();
    }

    public function test_ids_from_callers_are_validated_before_the_url(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->api()->profile('../apps');
        } finally {
            Http::assertNothingSent();
        }
    }
}
```

Run: `cd $WT && php artisan test --filter=AppStoreConnectApiTest`
Expected: FAIL (klasserna saknas).

- [ ] **Steg 5: `AppStoreNotConfigured` och `AppStoreConfig`.**

```php
<?php

namespace App\Releases\AppStore;

use App\Provisioning\SafeMessage;

// Meddelandet nämner bara env-namn, aldrig värden.
final class AppStoreNotConfigured extends \RuntimeException implements SafeMessage {}
```

```php
<?php

namespace App\Releases\AppStore;

// Läser releases.app_store. Inga värden lämnar klassen i fel eller loggar; bara env-namn.
final class AppStoreConfig
{
    private const KEY_ID = '/^[A-Z0-9]{10}\z/';

    private const ISSUER = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** @return list<string> env-namn som saknas eller har fel form */
    public static function missing(): array
    {
        $missing = self::missingKey();
        if (preg_match(self::KEY_ID, (string) config('releases.app_store.certificate_id')) !== 1) {
            $missing[] = 'ASC_RELEASE_CERTIFICATE_ID';
        }
        try {
            self::testers();
        } catch (AppStoreNotConfigured) {
            $missing[] = 'ASC_TESTFLIGHT_TESTERS';
        }

        return $missing;
    }

    // Det som JWT:n behöver. Kontrolleras före varje anrop.
    public static function assertKeyReady(): void
    {
        if (($missing = self::missingKey()) !== []) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: '.implode(', ', $missing).'.');
        }
    }

    public static function keyId(): string
    {
        return (string) config('releases.app_store.key_id');
    }

    public static function issuerId(): string
    {
        return (string) config('releases.app_store.issuer_id');
    }

    public static function certificateId(): string
    {
        $id = (string) config('releases.app_store.certificate_id');
        if (preg_match(self::KEY_ID, $id) !== 1) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: ASC_RELEASE_CERTIFICATE_ID.');
        }

        return $id;
    }

    public static function privateKeyFile(): string
    {
        return storage_path((string) config('releases.app_store.private_key_path'));
    }

    public static function betaGroup(): string
    {
        return (string) config('releases.app_store.beta_group');
    }

    /** @return list<string> */
    public static function testers(): array
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', (string) config('releases.app_store.testers'))), fn (string $e) => $e !== ''));
        if ($emails === [] || count(array_filter($emails, fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false)) > 0) {
            throw new AppStoreNotConfigured('App Store Connect is not configured: ASC_TESTFLIGHT_TESTERS (comma-separated e-mail addresses of App Store Connect users).');
        }

        return $emails;
    }

    /** @return list<string> */
    private static function missingKey(): array
    {
        $missing = [];
        if (preg_match(self::KEY_ID, self::keyId()) !== 1) {
            $missing[] = 'ASC_RELEASE_KEY_ID';
        }
        if (preg_match(self::ISSUER, self::issuerId()) !== 1) {
            $missing[] = 'ASC_RELEASE_ISSUER_ID';
        }
        $path = (string) config('releases.app_store.private_key_path');
        $file = self::privateKeyFile();
        // Nyckeln får inte vara läsbar för grupp eller andra (samma regel som GitHub-nyckeln).
        if ($path === '' || ! is_file($file) || ! is_readable($file) || (fileperms($file) & 0077) !== 0) {
            $missing[] = 'ASC_RELEASE_PRIVATE_KEY_PATH (file missing, unreadable or not mode 600)';
        }

        return $missing;
    }
}
```

- [ ] **Steg 6: `AppStoreConnectApi`.**

```php
<?php

namespace App\Releases\AppStore;

use App\Provisioning\Http\ExternalApiException;
use App\Provisioning\Http\SendsApiRequests;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

// App Store Connect för släppflödet (spike B; fältnamn ur Apples OpenAPI 4.5.1, se bilagan).
// Hotmodell (Task 1): teamnyckeln (App Manager) kan mer än etapp 2 behöver, så klienten har bara
// de metoder som finns här och skickar aldrig DELETE eller PATCH. Nyckeln läses bara av
// InMemory::file; JWT:n gäller 15 min och maskeras av Redactor (eyJ…). Id:n som går in i en
// sökväg valideras. 4xx/5xx loggas med request och response, redigerat (SendsApiRequests).
final class AppStoreConnectApi
{
    use SendsApiRequests;

    public const ALLOWED_METHODS = ['GET', 'POST'];

    private const ID = '/^[A-Za-z0-9-]{1,64}\z/';

    private ?string $token = null;

    private ?DateTimeImmutable $tokenExpires = null;

    protected function serviceName(): string
    {
        return 'App Store Connect';
    }

    protected function baseUrl(): string
    {
        return (string) config('releases.app_store.api_url');
    }

    // ES256; kid = nyckel-id; iss = issuer; aud = appstoreconnect-v1 (sträng); exp högst 20 min.
    public function jwt(?DateTimeImmutable $now = null): string
    {
        AppStoreConfig::assertKeyReady();
        $now ??= new DateTimeImmutable;

        return Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', AppStoreConfig::keyId())
            ->issuedBy(AppStoreConfig::issuerId())
            ->issuedAt($now)
            ->expiresAt($now->modify('+15 minutes'))
            ->permittedFor('appstoreconnect-v1')
            ->getToken(new Sha256, InMemory::file(AppStoreConfig::privateKeyFile()))
            ->toString();
    }

    /** @return array{id: string, identifier: string}|null */
    public function findBundleId(string $identifier): ?array
    {
        foreach ($this->list('/v1/bundleIds', ['filter[identifier]' => $identifier, 'filter[platform]' => 'IOS', 'limit' => 200]) as $row) {
            if (($row['attributes']['identifier'] ?? null) === $identifier) {
                return ['id' => $this->id($row), 'identifier' => $identifier];
            }
        }

        return null;
    }

    /** @return array{id: string, identifier: string} */
    public function createBundleId(string $identifier, string $name): array
    {
        $row = $this->create('/v1/bundleIds', ['type' => 'bundleIds', 'attributes' => ['identifier' => $identifier, 'name' => $name, 'platform' => 'IOS']]);

        return ['id' => $this->id($row), 'identifier' => (string) ($row['attributes']['identifier'] ?? $identifier)];
    }

    /** @return array{id: string, name: string, sku: string, bundle_id: string}|null */
    public function findApp(string $bundleId): ?array
    {
        foreach ($this->list('/v1/apps', ['filter[bundleId]' => $bundleId, 'fields[apps]' => 'name,bundleId,sku,primaryLocale', 'limit' => 10]) as $row) {
            if (($row['attributes']['bundleId'] ?? null) === $bundleId) {
                return ['id' => $this->id($row), 'name' => (string) ($row['attributes']['name'] ?? ''), 'sku' => (string) ($row['attributes']['sku'] ?? ''), 'bundle_id' => $bundleId];
            }
        }

        return null;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string}|null */
    public function findProfile(string $name): ?array
    {
        foreach ($this->list('/v1/profiles', ['filter[name]' => $name, 'filter[profileType]' => 'IOS_APP_STORE', 'fields[profiles]' => 'name,profileState,expirationDate,uuid', 'limit' => 10]) as $row) {
            if (($row['attributes']['name'] ?? null) === $name) {
                return $this->profileFields($row);
            }
        }

        return null;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string} */
    public function createProfile(string $name, string $bundleResourceId, string $certificateId): array
    {
        return $this->profileFields($this->create('/v1/profiles', [
            'type' => 'profiles',
            'attributes' => ['name' => $name, 'profileType' => 'IOS_APP_STORE'],
            'relationships' => [
                'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => $this->pathId($bundleResourceId)]],
                'certificates' => ['data' => [['type' => 'certificates', 'id' => $this->pathId($certificateId)]]],
            ],
        ]));
    }

    // profileContent hämtas aldrig hit; det gör bara sign-jobbet i släpprepot.
    /** @return array{id: string, uuid: string, state: string, expires_at: string}|null */
    public function profile(string $id): ?array
    {
        $json = $this->get('/v1/profiles/'.$this->pathId($id), ['fields[profiles]' => 'name,profileState,expirationDate,uuid'], [404]);

        return $json === null ? null : $this->profileFields($json['data'] ?? []);
    }

    /** @return array{id: string, name: string}|null */
    public function findBetaGroup(string $appId, string $name): ?array
    {
        foreach ($this->list('/v1/betaGroups', ['filter[app]' => $this->pathId($appId), 'filter[name]' => $name, 'filter[isInternalGroup]' => 'true', 'limit' => 50]) as $row) {
            if (($row['attributes']['name'] ?? null) === $name && ($row['attributes']['isInternalGroup'] ?? null) === true) {
                return ['id' => $this->id($row), 'name' => $name];
            }
        }

        return null;
    }

    /** @return array{id: string, name: string} */
    public function createInternalBetaGroup(string $appId, string $name): array
    {
        $row = $this->create('/v1/betaGroups', [
            'type' => 'betaGroups',
            // R7: inga automatiska byggen; Computercat lägger till varje bygge.
            'attributes' => ['name' => $name, 'isInternalGroup' => true, 'hasAccessToAllBuilds' => false],
            'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => $this->pathId($appId)]]],
        ]);

        return ['id' => $this->id($row), 'name' => $name];
    }

    /** @return array{id: string, email: string}|null */
    public function findBetaTester(string $email, ?string $groupId = null): ?array
    {
        $query = ['filter[email]' => $email, 'fields[betaTesters]' => 'email', 'limit' => 10];
        if ($groupId !== null) {
            $query['filter[betaGroups]'] = $this->pathId($groupId);
        }
        foreach ($this->list('/v1/betaTesters', $query) as $row) {
            if (strcasecmp((string) ($row['attributes']['email'] ?? ''), $email) === 0) {
                return ['id' => $this->id($row), 'email' => $email];
            }
        }

        return null;
    }

    /** @return array{id: string, email: string} */
    public function createBetaTester(string $email, string $groupId): array
    {
        $row = $this->create('/v1/betaTesters', [
            'type' => 'betaTesters',
            'attributes' => ['email' => $email],
            'relationships' => ['betaGroups' => ['data' => [['type' => 'betaGroups', 'id' => $this->pathId($groupId)]]]],
        ]);

        return ['id' => $this->id($row), 'email' => $email];
    }

    /** @param list<string> $testerIds */
    public function addTestersToGroup(string $groupId, array $testerIds): void
    {
        $this->send('POST', '/v1/betaGroups/'.$this->pathId($groupId).'/relationships/betaTesters', [
            'data' => array_map(fn (string $id) => ['type' => 'betaTesters', 'id' => $this->pathId($id)], array_values($testerIds)),
        ]);
    }

    /** @return array{id: string, state: string, version: string, expired: bool}|null */
    public function findBuild(string $appId, string $marketingVersion, int $buildNumber): ?array
    {
        $rows = $this->list('/v1/builds', [
            'filter[app]' => $this->pathId($appId),
            'filter[version]' => (string) $buildNumber,
            'filter[preReleaseVersion.version]' => $marketingVersion,
            'fields[builds]' => 'version,processingState,expired,uploadedDate',
            'limit' => 2,
        ]);
        foreach ($rows as $row) {
            if (($row['attributes']['version'] ?? null) === (string) $buildNumber) {
                return [
                    'id' => $this->id($row),
                    'state' => (string) ($row['attributes']['processingState'] ?? ''),
                    'version' => (string) $buildNumber,
                    'expired' => (bool) ($row['attributes']['expired'] ?? false),
                ];
            }
        }

        return null;
    }

    public function buildIsInGroup(string $groupId, string $buildId): bool
    {
        return $this->list('/v1/builds', ['filter[betaGroups]' => $this->pathId($groupId), 'filter[id]' => $this->pathId($buildId), 'fields[builds]' => 'version', 'limit' => 1]) !== [];
    }

    public function addBuildToGroup(string $groupId, string $buildId): void
    {
        $this->send('POST', '/v1/betaGroups/'.$this->pathId($groupId).'/relationships/builds', ['data' => [['type' => 'builds', 'id' => $this->pathId($buildId)]]]);
    }

    /** @return list<array> */
    private function list(string $path, array $query): array
    {
        $data = $this->get($path, $query)['data'] ?? null;
        if (! is_array($data)) {
            throw new ExternalApiException($this->serviceName(), 'GET', $path, 200, 'response lacks a data list');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function create(string $path, array $data): array
    {
        $response = $this->send('POST', $path, ['data' => $data]);
        $row = $response->json('data');
        if (! is_array($row)) {
            throw new ExternalApiException($this->serviceName(), 'POST', $path, $response->status(), 'response lacks data');
        }

        return $row;
    }

    /** @param list<int> $accept */
    private function get(string $path, array $query, array $accept = []): ?array
    {
        $response = $this->send('GET', $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, $accept);
        if (in_array($response->status(), $accept, true)) {
            return null;
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new ExternalApiException($this->serviceName(), 'GET', $path, $response->status(), 'response is not JSON');
        }

        return $json;
    }

    /** @param list<int> $accept */
    private function send(string $method, string $path, ?array $body = null, array $accept = []): Response
    {
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \LogicException("AppStoreConnectApi skickar aldrig {$method}.");
        }

        return $this->sendRequest($this->request(), $method, $path, $body, $accept);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->acceptJson()
            ->timeout((int) config('provisioning.http_timeout_seconds'))
            ->connectTimeout(5);
    }

    private function token(): string
    {
        $now = new DateTimeImmutable;
        if ($this->token === null || $this->tokenExpires === null || $this->tokenExpires <= $now->modify('+2 minutes')) {
            $this->token = $this->jwt($now);
            $this->tokenExpires = $now->modify('+15 minutes');
        }

        return $this->token;
    }

    /** @return array{id: string, uuid: string, state: string, expires_at: string} */
    private function profileFields(array $row): array
    {
        return [
            'id' => $this->id($row),
            'uuid' => (string) ($row['attributes']['uuid'] ?? ''),
            'state' => (string) ($row['attributes']['profileState'] ?? ''),
            'expires_at' => (string) ($row['attributes']['expirationDate'] ?? ''),
        ];
    }

    private function id(array $row): string
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw new ExternalApiException($this->serviceName(), 'GET', '(resource)', 200, 'resource without a valid id');
        }

        return $id;
    }

    private function pathId(string $id): string
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('Ogiltigt App Store Connect-id.');
        }

        return $id;
    }
}
```

Två kontroller mot specen innan testet körs, med verktyget och filen från steg 1 i Task 0 (skriv utdata till `$SCRATCH/asc-check.txt`):

```bash
python3 -I - <scratchpad>/ascspec/spec/openapi.oas.json <<'PY' | tee $SCRATCH/asc-check.txt
import json, sys
s = json.load(open(sys.argv[1])); P = s['paths']
need = {('/v1/bundleIds','get'):['filter[identifier]','filter[platform]','limit'], ('/v1/apps','get'):['filter[bundleId]','fields[apps]','limit'],
 ('/v1/profiles','get'):['filter[name]','filter[profileType]','fields[profiles]'], ('/v1/profiles/{id}','get'):['fields[profiles]'],
 ('/v1/betaGroups','get'):['filter[app]','filter[name]','filter[isInternalGroup]'], ('/v1/betaTesters','get'):['filter[email]','filter[betaGroups]','fields[betaTesters]'],
 ('/v1/builds','get'):['filter[app]','filter[version]','filter[preReleaseVersion.version]','filter[betaGroups]','filter[id]','fields[builds]']}
for (p,m), names in need.items():
    have = {x.get('name') for x in P[p][m]['parameters']}
    print(p, m, 'OK' if set(names) <= have else 'SAKNAS: '+str(set(names)-have))
for p in ['/v1/bundleIds','/v1/profiles','/v1/betaGroups','/v1/betaTesters','/v1/betaGroups/{id}/relationships/builds','/v1/betaGroups/{id}/relationships/betaTesters']:
    print(p, 'post', sorted(P[p]['post']['responses']))
PY
```

Förväntat: varje rad `OK`, och POST-svaren innehåller `201` (skapande) respektive `204` (länkar). En rad med `SAKNAS`: stanna och fråga Erik.

- [ ] **Steg 7: Kör testerna.**

Run: `cd $WT && php artisan test --filter=AppStoreConnectApiTest`
Expected: PASS (13 tester).

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add config/releases.php .env.example phpunit.xml app/Releases/AppStore tests/Support/AppStoreFakes.php tests/Feature/Releases/AppStoreConnectApiTest.php
git commit -m "App Store Connect-klient för släppflödet (ES256, bara etapp 2:s anrop, redigerad loggning)"
```

---
### Task 2: GitHub — fil vid commit, taggar, dispatch och körningar

**Files:**
- Modify: `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (avsnitt "GitHub REST som etapp 2 bygger på")
- Modify: `app/Provisioning/Clients/GitHubAppClient.php`
- Modify: `tests/Support/ProvisioningFakes.php`
- Create: `tests/Feature/Provisioning/GitHubReleaseCallsTest.php`

**Interfaces:**
- Produces (Task 6 och 7):
  - `getFile(string $name, string $path, ?string $ref = null): ?array{sha, text}` (ref = fullständigt commit-SHA)
  - `GitHubAppClient::TAG_PATTERN`; `getTag(string $name, string $tag): ?array{sha: string, type: string}`; `createTag(string $name, string $tag, string $sha): void`
  - `dispatchWorkflow(string $repo, string $workflow, string $ref, array<string,string> $inputs): array{run_id: int, html_url: string}`
  - `workflowRun(string $repo, int $runId): array{id: int, name: string, status: string, conclusion: ?string, html_url: string, head_sha: string}`
  - `workflowRunJobs(string $repo, int $runId): list<array{name: string, conclusion: ?string, failed_step: ?string}>`
  - `findWorkflowRun(string $repo, string $workflow, string $needle, DateTimeInterface $since): ?array` (samma form som `workflowRun`)
  - `cancelWorkflowRun(string $repo, int $runId): void`

- [ ] **Steg 1: Läs GitHubs referens först (CLAUDE.md: gissa aldrig).** Hämta med WebFetch och skriv i bilagan, under rubriken `## GitHub REST som etapp 2 bygger på (docs.github.com, API 2022-11-28)`, för varje endpoint: metod och sökväg, behörighet för en GitHub App, statuskoder, och exakt de fält koden läser:
  - `GET /repos/{owner}/{repo}/contents/{path}` med `ref` (https://docs.github.com/en/rest/repos/contents#get-repository-content): `sha`, `encoding`, `content`.
  - `GET /repos/{owner}/{repo}/git/ref/{ref}` och `POST /repos/{owner}/{repo}/git/refs` (https://docs.github.com/en/rest/git/refs): `object.sha`, `object.type`; 404 när referensen saknas; 422 när den redan finns.
  - `POST /repos/{owner}/{repo}/actions/workflows/{workflow_id}/dispatches` (https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event): `return_run_details`, svaret `workflow_run_id`, `run_url`, `html_url` (bekräftat i spike A G1b: 200).
  - `GET /repos/{owner}/{repo}/actions/runs/{run_id}`, `GET …/runs/{run_id}/jobs`, `POST …/runs/{run_id}/cancel`, `GET /repos/{owner}/{repo}/actions/workflows/{workflow_id}/runs` (https://docs.github.com/en/rest/actions/workflow-runs och https://docs.github.com/en/rest/actions/workflow-jobs): `status` (värdena), `conclusion` (värdena), `name`, `html_url`, `head_sha`; för jobb `jobs[].name`, `jobs[].conclusion`, `jobs[].steps[].name`, `jobs[].steps[].conclusion`; parametrarna `event`, `created` (sökform `>=`), `per_page`; statuskoden när en avslutad körning avbryts.
  Stämmer något fält eller någon statuskod inte med koden nedan: rätta koden och fejkarna efter referensen, och skriv avvikelsen i bilagan. Commit av bilagan sker i steg 6.

- [ ] **Steg 2: Fejkarna.** Lägg till i `tests/Support/ProvisioningFakes.php`, i GitHub-avsnittet:

```php
    /** GET /repos/{o}/{r}/git/ref/tags/{tag} (docs: git/refs). Lättviktig tagg: object.type = commit. */
    public static function githubTagRef(string $tag, string $sha, string $type = 'commit'): array
    {
        return ['ref' => "refs/tags/{$tag}", 'object' => ['type' => $type, 'sha' => $sha, 'url' => self::GITHUB.'/repos/HumblebragAB/x/git/commits/'.$sha]];
    }

    /** 200 från dispatch med return_run_details (spike A G1b). */
    public static function githubDispatch(int $runId): array
    {
        return [
            'workflow_run_id' => $runId,
            'run_url' => self::GITHUB."/repos/HumblebragAB/computercat-release/actions/runs/{$runId}",
            'html_url' => "https://github.com/HumblebragAB/computercat-release/actions/runs/{$runId}",
        ];
    }

    /** GET …/actions/runs/{id} (docs: workflow-runs; name = run-name, spike A G2). */
    public static function githubRun(int $id, string $status, ?string $conclusion, string $releaseUuid, string $game = 'kattsvep'): array
    {
        return [
            'id' => $id, 'name' => "ios {$game} {$releaseUuid}", 'status' => $status, 'conclusion' => $conclusion,
            'event' => 'workflow_dispatch', 'head_branch' => 'main', 'head_sha' => str_repeat('9', 40),
            'html_url' => "https://github.com/HumblebragAB/computercat-release/actions/runs/{$id}",
        ];
    }

    /** GET …/actions/runs/{id}/jobs (docs: workflow-jobs). $failed = [jobb, steg] som föll. */
    public static function githubRunJobs(?array $failed = null): array
    {
        $jobs = [];
        foreach (['fetch', 'build', 'sign'] as $name) {
            $isFailed = $failed !== null && $failed[0] === $name;
            $jobs[] = [
                'id' => crc32($name), 'name' => $name, 'status' => 'completed', 'conclusion' => $isFailed ? 'failure' : 'success',
                'steps' => [
                    ['name' => 'Set up job', 'status' => 'completed', 'conclusion' => 'success', 'number' => 1],
                    ['name' => $isFailed ? $failed[1] : 'Klart', 'status' => 'completed', 'conclusion' => $isFailed ? 'failure' : 'success', 'number' => 2],
                ],
            ];
        }

        return ['total_count' => count($jobs), 'jobs' => $jobs];
    }
```

- [ ] **Steg 3: Testerna (fallerar).** Skapa `tests/Feature/Provisioning/GitHubReleaseCallsTest.php`:

```php
<?php

namespace Tests\Feature\Provisioning;

use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class GitHubReleaseCallsTest extends TestCase
{
    private const SHA = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private const REPO = F::GITHUB.'/repos/HumblebragAB';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
    }

    private function fake(array $routes): void
    {
        Http::fake([F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201)] + $routes);
    }

    private function client(): GitHubAppClient
    {
        return app(GitHubAppClient::class);
    }

    public function test_get_file_at_a_commit_sends_ref_and_refuses_anything_but_a_full_sha(): void
    {
        $this->fake([self::REPO.'/kattsvep/contents/package.json?ref='.self::SHA => Http::response(F::githubContent('{"version":"1.2.0"}'))]);

        $this->assertSame('{"version":"1.2.0"}', $this->client()->getFile('kattsvep', 'package.json', self::SHA)['text']);

        $this->expectException(\InvalidArgumentException::class);
        $this->client()->getFile('kattsvep', 'package.json', 'main');
    }

    public function test_get_tag_returns_sha_and_type_and_null_when_missing(): void
    {
        $this->fake([
            self::REPO.'/kattsvep/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', self::SHA)),
            self::REPO.'/kattsvep/git/ref/tags/v1.3.0' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(['sha' => self::SHA, 'type' => 'commit'], $this->client()->getTag('kattsvep', 'v1.2.0'));
        $this->assertNull($this->client()->getTag('kattsvep', 'v1.3.0'));
    }

    public function test_create_tag_posts_a_lightweight_ref(): void
    {
        $this->fake([self::REPO.'/kattsvep/git/refs' => Http::response(F::githubTagRef('v1.2.0', self::SHA), 201)]);

        $this->client()->createTag('kattsvep', 'v1.2.0', self::SHA);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['ref' => 'refs/tags/v1.2.0', 'sha' => self::SHA]);
    }

    public function test_tag_must_be_v_major_minor_patch(): void
    {
        $this->fake([]);
        foreach (['1.2.0', 'v1.2', 'v01.2.0', 'v1.2.0-rc1', 'v1.2.0/../x', "v1.2.0\n"] as $bad) {
            try {
                $this->client()->getTag('kattsvep', $bad);
                $this->fail("{$bad} släpptes igenom.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dispatch_sends_ref_string_inputs_and_return_run_details_and_reads_the_run_id(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/dispatches' => Http::response(F::githubDispatch(4711))]);

        $run = $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['game' => 'kattsvep', 'build_number' => '7']);

        $this->assertSame(['run_id' => 4711, 'html_url' => 'https://github.com/HumblebragAB/computercat-release/actions/runs/4711'], $run);
        Http::assertSent(fn (Request $r) => $r->data() === ['ref' => 'main', 'inputs' => ['game' => 'kattsvep', 'build_number' => '7'], 'return_run_details' => true]);
    }

    public function test_dispatch_without_a_run_id_fails_loud(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/dispatches' => Http::response('', 204)]);

        $this->expectException(ExternalApiException::class);
        $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['game' => 'kattsvep']);
    }

    public function test_non_string_inputs_are_refused_before_any_request(): void
    {
        $this->fake([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client()->dispatchWorkflow('computercat-release', 'release-ios.yml', 'main', ['build_number' => 7]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_workflow_run_and_the_failed_step(): void
    {
        $this->fake([
            self::REPO.'/computercat-release/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'failure', 'uuid-1')),
            self::REPO.'/computercat-release/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs(['build', 'Kontrollera version och bundle-id'])),
        ]);

        $run = $this->client()->workflowRun('computercat-release', 4711);
        $this->assertSame(['completed', 'failure', str_repeat('9', 40)], [$run['status'], $run['conclusion'], $run['head_sha']]);
        $failed = array_values(array_filter($this->client()->workflowRunJobs('computercat-release', 4711), fn (array $j) => $j['conclusion'] === 'failure'));
        $this->assertSame([['name' => 'build', 'conclusion' => 'failure', 'failed_step' => 'Kontrollera version och bundle-id']], $failed);
    }

    public function test_find_run_by_release_uuid_in_run_name(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 2, 'workflow_runs' => [
            F::githubRun(1, 'completed', 'success', 'other-uuid'),
            F::githubRun(2, 'queued', null, 'the-uuid'),
        ]])]);

        $this->assertSame(2, $this->client()->findWorkflowRun('computercat-release', 'release-ios.yml', 'the-uuid', now()->subMinute())['id']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'event=workflow_dispatch') && str_contains(urldecode($r->url()), 'created=>='));
    }

    public function test_cancel_accepts_409_for_a_finished_run(): void
    {
        $this->fake([self::REPO.'/computercat-release/actions/runs/4711/cancel' => Http::response(['message' => 'Cannot cancel a workflow run that is completed.'], 409)]);

        $this->client()->cancelWorkflowRun('computercat-release', 4711);
        Http::assertSentCount(2); // token + cancel
    }
}
```

Run: `cd $WT && php artisan test --filter=GitHubReleaseCallsTest`
Expected: FAIL.

- [ ] **Steg 4: Implementationen.** I `GitHubAppClient`:
1. Ersätt signaturen och första raden i `getFile` med:

```php
    /** @return array{sha: string, text: string}|null */
    public function getFile(string $name, string $path, ?string $ref = null): ?array
    {
        $apiPath = '/repos/'.$this->owner().'/'.$this->repoName($name).'/contents/'.$this->filePath($path);
        if ($ref !== null) {
            $apiPath .= '?ref='.$this->commit($ref);
        }
```

(resten av metoden oförändrad).
2. Lägg till efter `archiveRepository`:

```php
    public const TAG_PATTERN = '/^v(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\z/';

    /** @return array{sha: string, type: string}|null */
    public function getTag(string $name, string $tag): ?array
    {
        $path = '/repos/'.$this->owner().'/'.$this->repoName($name).'/git/ref/tags/'.$this->tag($tag);
        $response = $this->github('GET', $path, null, [404]);
        if ($response->status() === 404) {
            return null;
        }

        $sha = $response->json('object.sha');
        $type = $response->json('object.type');
        if (! is_string($sha) || ! is_string($type)) {
            throw new ExternalApiException('GitHub', 'GET', $path, $response->status(), 'unexpected ref response (expected object.sha and object.type)');
        }

        return ['sha' => strtolower($sha), 'type' => $type];
    }

    // Lättviktig tagg (en ref direkt på commiten). Flyttas och raderas aldrig (plan R3, R4).
    public function createTag(string $name, string $tag, string $sha): void
    {
        $this->github('POST', '/repos/'.$this->owner().'/'.$this->repoName($name).'/git/refs', [
            'ref' => 'refs/tags/'.$this->tag($tag),
            'sha' => $this->commit($sha),
        ]);
    }

    /**
     * Spike A G1b: med return_run_details svarar GitHub 200 med körningens id.
     *
     * @param  array<string, string>  $inputs
     * @return array{run_id: int, html_url: string}
     */
    public function dispatchWorkflow(string $repo, string $workflow, string $ref, array $inputs): array
    {
        foreach ($inputs as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new \InvalidArgumentException('Workflow-indata måste vara strängar.');
            }
        }
        $path = $this->actions($repo).'/workflows/'.$this->workflowFile($workflow).'/dispatches';
        $response = $this->github('POST', $path, ['ref' => $this->branch($ref), 'inputs' => $inputs, 'return_run_details' => true]);

        $id = $response->json('workflow_run_id');
        $url = $response->json('html_url');
        if (! is_int($id) || $id <= 0 || ! is_string($url)) {
            throw new ExternalApiException('GitHub', 'POST', $path, $response->status(), 'dispatch response lacks workflow_run_id or html_url');
        }

        return ['run_id' => $id, 'html_url' => $url];
    }

    /** @return array{id: int, name: string, status: string, conclusion: ?string, html_url: string, head_sha: string} */
    public function workflowRun(string $repo, int $runId): array
    {
        $path = $this->actions($repo).'/runs/'.$this->runId($runId);

        return $this->runFields($this->github('GET', $path)->json(), $path);
    }

    /** @return list<array{name: string, conclusion: ?string, failed_step: ?string}> */
    public function workflowRunJobs(string $repo, int $runId): array
    {
        $path = $this->actions($repo).'/runs/'.$this->runId($runId).'/jobs?per_page=30';
        $jobs = $this->github('GET', $path)->json('jobs');
        if (! is_array($jobs)) {
            throw new ExternalApiException('GitHub', 'GET', $path, 200, 'response lacks jobs');
        }

        return array_values(array_map(function (array $job) {
            $failed = collect($job['steps'] ?? [])->first(fn ($s) => is_array($s) && in_array($s['conclusion'] ?? null, ['failure', 'timed_out', 'cancelled'], true));

            return [
                'name' => (string) ($job['name'] ?? '?'),
                'conclusion' => is_string($job['conclusion'] ?? null) ? $job['conclusion'] : null,
                'failed_step' => is_array($failed) ? (string) ($failed['name'] ?? '?') : null,
            ];
        }, array_filter($jobs, 'is_array')));
    }

    // Reserv när dispatch-svaret gick förlorat: run-name innehåller release_uuid (spike A G2).
    public function findWorkflowRun(string $repo, string $workflow, string $needle, \DateTimeInterface $since): ?array
    {
        $path = $this->actions($repo).'/workflows/'.$this->workflowFile($workflow).'/runs?'.http_build_query([
            'event' => 'workflow_dispatch',
            'created' => '>='.Carbon::instance($since)->utc()->format('Y-m-d\TH:i:s\Z'),
            'per_page' => 50,
        ], '', '&', PHP_QUERY_RFC3986);
        foreach ($this->github('GET', $path)->json('workflow_runs') ?? [] as $run) {
            if (is_array($run) && is_string($run['name'] ?? null) && str_contains($run['name'], $needle)) {
                return $this->runFields($run, $path);
            }
        }

        return null;
    }

    // 409 = körningen är redan avslutad; det är målet ändå.
    public function cancelWorkflowRun(string $repo, int $runId): void
    {
        $this->github('POST', $this->actions($repo).'/runs/'.$this->runId($runId).'/cancel', null, [409]);
    }

    private function runFields(mixed $run, string $path): array
    {
        if (! is_array($run) || ! is_int($run['id'] ?? null) || ! is_string($run['status'] ?? null)) {
            throw new ExternalApiException('GitHub', 'GET', $path, 200, 'unexpected workflow run (expected id and status)');
        }

        return [
            'id' => $run['id'],
            'name' => (string) ($run['name'] ?? ''),
            'status' => $run['status'],
            'conclusion' => is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
            'html_url' => (string) ($run['html_url'] ?? ''),
            'head_sha' => strtolower((string) ($run['head_sha'] ?? '')),
        ];
    }

    private function actions(string $repo): string
    {
        return '/repos/'.$this->owner().'/'.$this->repoName($repo).'/actions';
    }

    private function tag(string $tag): string
    {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new \InvalidArgumentException("Ogiltig tagg: {$tag}");
        }

        return $tag;
    }

    private function commit(string $sha): string
    {
        if (preg_match('/^[0-9a-f]{40}\z/', $sha) !== 1) {
            throw new \InvalidArgumentException('Ogiltig ref: måste vara ett fullständigt commit-SHA.');
        }

        return $sha;
    }

    private function workflowFile(string $workflow): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,60}\.ya?ml\z/', $workflow) !== 1) {
            throw new \InvalidArgumentException("Ogiltigt flödesnamn: {$workflow}");
        }

        return $workflow;
    }

    private function branch(string $ref): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,100}\z/', $ref) !== 1) {
            throw new \InvalidArgumentException("Ogiltig gren: {$ref}");
        }

        return $ref;
    }

    private function runId(int $id): int
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Ogiltigt körnings-id.');
        }

        return $id;
    }
```

`Carbon` är redan importerad (`Illuminate\Support\Carbon`). Kommentaren överst i klassen ("Appen har Administration: write …") får en mening till: "Etapp 2: klienten läser filer vid en commit, sätter lättviktiga taggar, startar släpprepots flöde och läser och avbryter körningar; den flyttar eller raderar aldrig taggar."

- [ ] **Steg 5: Kör.**

Run: `cd $WT && php artisan test --filter='GitHubReleaseCallsTest|GitHubAppClientTest|RepoStepTest'`
Expected: PASS (befintliga GitHub-tester oförändrade).

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Provisioning/Clients/GitHubAppClient.php tests/Support/ProvisioningFakes.php tests/Feature/Provisioning/GitHubReleaseCallsTest.php docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md
git commit -m "GitHub-klienten: fil vid commit, taggar, dispatch med return_run_details och körningar"
```

---

### Task 3: Datamodellen — bundle-id, byggnummer och version

**Files:**
- Create: `database/migrations/2026_10_17_100001_add_ios_release_columns.php`
- Create: `app/Releases/AppStore/BundleId.php`, `app/Releases/MarketingVersion.php`
- Modify: `app/Models/Game.php`, `app/Models/Release.php`, `app/Models/ReleaseSetupStep.php`
- Create: `tests/Unit/Releases/MarketingVersionTest.php`, `tests/Unit/Releases/BundleIdTest.php`
- Modify: `tests/Feature/Releases/ReleaseModelTest.php`

**Interfaces:**
- Produces: `games.bundle_id` (unik, null), `games.ios_build_number` (senast utdelade, 0), `games.app_store_ids` (JSON: `bundle_resource_id`, `profile_id`, `app_id`, `app_name`, `beta_group_id`); `Game::appStoreId(string $key): ?string`. `releases.marketing_version`, `releases.build_number` (unik per spel), `releases.build_guide`. `release_setup_steps.last_error`. `BundleId::forSlug(string): string`, `BundleId::PATTERN`. `MarketingVersion::parse(mixed): ?MarketingVersion`, `->isHigherThan(MarketingVersion): bool`, `->compare()`, `->nextPatch()`, `(string)`, `MarketingVersion::highest(iterable): ?MarketingVersion`.

- [ ] **Steg 1: Testerna (fallerar).** `tests/Unit/Releases/MarketingVersionTest.php`:

```php
<?php

namespace Tests\Unit\Releases;

use App\Releases\MarketingVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketingVersionTest extends TestCase
{
    public static function invalid(): array
    {
        return [['1.2'], ['1.2.3.4'], ['01.2.3'], ['1.2.3-beta'], ['v1.2.3'], ['1000.0.0'], [' 1.2.3'], ["1.2.3\n"], [''], [null], [123]];
    }

    #[DataProvider('invalid')]
    public function test_only_major_minor_patch_is_accepted(mixed $value): void
    {
        $this->assertNull(MarketingVersion::parse($value));
    }

    public function test_versions_compare_numerically_not_as_strings(): void
    {
        $this->assertTrue(MarketingVersion::parse('1.10.0')->isHigherThan(MarketingVersion::parse('1.9.9')));
        $this->assertFalse(MarketingVersion::parse('1.2.0')->isHigherThan(MarketingVersion::parse('1.2.0')));
        $this->assertSame('2.0.0', (string) MarketingVersion::highest(['1.9.0', null, '2.0.0', '1.10.3']));
        $this->assertNull(MarketingVersion::highest([null, 'trasig']));
        $this->assertSame('1.2.4', (string) MarketingVersion::parse('1.2.3')->nextPatch());
    }
}
```

`tests/Unit/Releases/BundleIdTest.php`:

```php
<?php

namespace Tests\Unit\Releases;

use App\Releases\AppStore\BundleId;
use PHPUnit\Framework\TestCase;

class BundleIdTest extends TestCase
{
    public function test_bundle_id_is_se_computercat_and_the_slug_without_hyphens(): void
    {
        $this->assertSame('se.computercat.kattsvep', BundleId::forSlug('kattsvep'));
        $this->assertSame('se.computercat.tinkerisle', BundleId::forSlug('tinker-isle'));
        $this->assertSame('se.computercat.ab', BundleId::forSlug('a-b'));
        $this->assertSame(1, preg_match(BundleId::PATTERN, BundleId::forSlug(str_repeat('a', 40))));
    }

    public function test_invalid_slug_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BundleId::forSlug('Kattsvep');
    }
}
```

I `tests/Feature/Releases/ReleaseModelTest.php`, lägg till (klassen har `CreatesReleases`):

```php
    public function test_build_number_is_unique_per_game(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $other = $this->releasableGame('tinker-isle', ['app_store']);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);
        $this->release($other, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 3]);
    }

    public function test_two_games_cannot_share_a_bundle_id(): void
    {
        $this->releasableGame('ab-c')->forceFill(['bundle_id' => 'se.computercat.abc'])->save();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->releasableGame('abc')->forceFill(['bundle_id' => 'se.computercat.abc'])->save();
    }
```

Run: `cd $WT && php artisan test --filter='MarketingVersionTest|BundleIdTest|ReleaseModelTest'`
Expected: FAIL.

- [ ] **Steg 2: Migrationen.**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Släppflöde etapp 2 del 1 (TestFlight).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // se.computercat.<slug utan bindestreck> (Eriks beslut 2026-10-15). Unikt: ab-c och abc krockar.
            $table->string('bundle_id', 155)->nullable()->unique();
            // Senast utdelade byggnumret för iOS-appen. Stiger och återanvänds aldrig (R5).
            $table->unsignedInteger('ios_build_number')->default(0);
            // Id:n i App Store Connect som Computercat skapat eller hittat. Inga hemligheter.
            $table->json('app_store_ids')->nullable();
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->string('marketing_version', 20)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('build_guide', 8)->nullable();
            // Ett byggnummer används en gång per spel (null för steg utan bygge).
            $table->unique(['game_id', 'build_number']);
        });

        Schema::table('release_setup_steps', function (Blueprint $table) {
            // Senaste felet när Computercat försökte göra ett automatiskt steg (redigerat).
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('release_setup_steps', fn (Blueprint $table) => $table->dropColumn('last_error'));
        Schema::table('releases', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'build_number']);
            $table->dropColumn(['marketing_version', 'build_number', 'build_guide']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['bundle_id']);
            $table->dropColumn(['bundle_id', 'ios_build_number', 'app_store_ids']);
        });
    }
};
```

- [ ] **Steg 3: Värdeklasserna.**

```php
<?php

namespace App\Releases;

// Versionen i package.json, som också blir CFBundleShortVersionString och taggen v<version>.
// Bara MAJOR.MINOR.PATCH med högst tre siffror per del och utan inledande nollor.
final class MarketingVersion implements \Stringable
{
    private const PATTERN = '/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\z/';

    private function __construct(public readonly int $major, public readonly int $minor, public readonly int $patch) {}

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function isHigherThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    // Förslaget i svaren ("till exempel 1.2.1").
    public function nextPatch(): self
    {
        return new self($this->major, $this->minor, $this->patch + 1);
    }

    /** @param iterable<mixed> $versions */
    public static function highest(iterable $versions): ?self
    {
        $best = null;
        foreach ($versions as $value) {
            $v = self::parse($value);
            if ($v !== null && ($best === null || $v->isHigherThan($best))) {
                $best = $v;
            }
        }

        return $best;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
```

```php
<?php

namespace App\Releases\AppStore;

use App\Rules\GameSlug;

// Eriks beslut 2026-10-15: se.computercat.<slug utan bindestreck>.
final class BundleId
{
    public const PREFIX = 'se.computercat.';

    public const PATTERN = '/^se\.computercat\.[a-z][a-z0-9]{1,39}\z/';

    public static function forSlug(string $slug): string
    {
        if (! GameSlug::isValid($slug)) {
            throw new \InvalidArgumentException('Ogiltig slug för bundle-id.');
        }

        $id = self::PREFIX.str_replace('-', '', $slug);
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw new \LogicException("Bundle-id {$id} har fel form.");
        }

        return $id;
    }
}
```

- [ ] **Steg 4: Modellerna.**
1. `Game`: lägg till i `casts()` `'ios_build_number' => 'integer', 'app_store_ids' => 'array',` och metoden:

```php
    /** Ett App Store Connect-id som Computercat sparat (bundle_resource_id, profile_id, app_id, app_name, beta_group_id). */
    public function appStoreId(string $key): ?string
    {
        $value = ($this->app_store_ids ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
```

`bundle_id`, `ios_build_number` och `app_store_ids` läggs inte i `$fillable` (skrivs bara med `forceFill` av Computercat).
2. `Release`: lägg till `'marketing_version', 'build_number', 'build_guide'` i `$fillable` och `'build_number' => 'integer'` i `casts()`.
3. `ReleaseSetupStep`: lägg till `'last_error'` i `$fillable` och i `booted()`:

```php
    protected static function booted(): void
    {
        // last_error visas i Filament och Slack: kända hemligheter bort och högst 4 KB.
        static::saving(function (ReleaseSetupStep $step) {
            if (is_string($step->last_error)) {
                $step->last_error = \App\Provisioning\Http\Redactor::limit(\App\Provisioning\Http\Redactor::scrubSecrets($step->last_error));
            }
        });
    }
```

- [ ] **Steg 5: Kör.**

Run: `cd $WT && php artisan test --filter='MarketingVersionTest|BundleIdTest|ReleaseModelTest'`
Expected: PASS. Kör också `cd $WT && php artisan migrate` mot worktreens egen databas och `php artisan migrate:rollback --step=1 && php artisan migrate` (down och up fungerar).

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add database/migrations/2026_10_17_100001_add_ios_release_columns.php app/Releases/MarketingVersion.php app/Releases/AppStore/BundleId.php app/Models tests/Unit/Releases tests/Feature/Releases/ReleaseModelTest.php
git commit -m "Datamodell för TestFlight: bundle-id, byggnummer, version och App Store-id:n"
```

---

### Task 4: Byggguiden — vilka versioner kan byggas för iOS (v3-kontraktet)

**Files:**
- Modify: `app/Services/BuildGuide.php`
- Create: `app/Releases/TestFlight/IosBuildGuides.php`
- Modify: `tests/Feature/BuildGuideTest.php`
- Modify: `tests/Support/CreatesReleases.php`

**Interfaces:**
- Produces: `BuildGuide::supportsIos(string $version): bool` (bara låsta versioner med giltigt `ios`-block), `BuildGuide::iosBlockIsValid(mixed): bool`, `BuildGuide::versionFromClaudeMd(string): ?string`. `IosBuildGuides::supports(string): bool` (injicerbar, så att tester kan låtsas att en version stöder iOS innan v3 finns). `CreatesReleases::iosGuides(string ...$versions)`.
- **Kontraktet som byggguide v3 (egen plan, R1) ska uppfylla** — släpprepots flöde (Task 10) och Computercat förutsätter exakt detta:
  1. `manifest.json` har `"ios": {"capacitor": "8.5.3", "package_manager": "spm", "project": "ios/App/App.xcodeproj", "scheme": "App"}`.
  2. `CLAUDE.md` har raden `Byggguide: **v3**.` (som v1 och v2) och ett avsnitt "Skicka till TestFlight" med `ReleaseNext::TESTFLIGHT_FLOW` ordagrant (Task 9:s test läser varje version med `ios`-block, låst eller inte).
  3. `package.json` har `version` (`MAJOR.MINOR.PATCH`, start `0.1.0`) och beroendena `@capacitor/cli`, `@capacitor/core`, `@capacitor/ios` i den version manifestet anger.
  4. Spelrepot har `capacitor.config.ts` med `appId` = spelets bundle-id från `get_release_status` (`targets.app_store.bundle_id`), `appName` = spelets namn, `webDir: 'out'`, och `ios/` skapat med `npx cap add ios --packagemanager SPM`. `ios/App/App/Info.plist` har `ITSAppUsesNonExemptEncryption` = `false`, och `CFBundleShortVersionString`/`CFBundleVersion` = `$(MARKETING_VERSION)`/`$(CURRENT_PROJECT_VERSION)` (Capacitor-mallen; spike B7). `capacitor.config.ts` och `ios/` är per spel och står därför inte i `files[]`; v3-planen avgör hur spelets Claude skapar dem och verifierar att `cap add ios` fungerar där spelets Claude kör (spike i v3-planen).
  5. Ingen fil i spelrepot innehåller släppflöden eller hemligheter (specens Del 2).

- [ ] **Steg 1: Testerna (fallerar).** Lägg till i `tests/Feature/BuildGuideTest.php`:

```php
    public function test_no_published_version_before_v3_supports_ios(): void
    {
        $this->assertFalse(\App\Services\BuildGuide::supportsIos('v1'));
        $this->assertFalse(\App\Services\BuildGuide::supportsIos('v2'));
        $this->assertFalse(\App\Services\BuildGuide::supportsIos('v99'));
        $this->assertFalse(\App\Services\BuildGuide::supportsIos('../v2'));
    }

    public function test_the_ios_block_must_match_the_contract(): void
    {
        $ok = ['capacitor' => '8.5.3', 'package_manager' => 'spm', 'project' => 'ios/App/App.xcodeproj', 'scheme' => 'App'];
        $this->assertTrue(\App\Services\BuildGuide::iosBlockIsValid($ok));
        $this->assertFalse(\App\Services\BuildGuide::iosBlockIsValid(array_replace($ok, ['project' => 'ios/App/Other.xcodeproj'])));
        $this->assertFalse(\App\Services\BuildGuide::iosBlockIsValid(array_replace($ok, ['package_manager' => 'cocoapods'])));
        $this->assertFalse(\App\Services\BuildGuide::iosBlockIsValid(array_replace($ok, ['capacitor' => '^8'])));
        $this->assertFalse(\App\Services\BuildGuide::iosBlockIsValid(null));
    }

    public function test_version_is_read_from_the_claude_md_line(): void
    {
        $this->assertSame('v2', \App\Services\BuildGuide::versionFromClaudeMd(\App\Services\BuildGuide::claudeMd('v2')));
        $this->assertSame('v1', \App\Services\BuildGuide::versionFromClaudeMd(\App\Services\BuildGuide::claudeMd('v1')));
        $this->assertNull(\App\Services\BuildGuide::versionFromClaudeMd("# CLAUDE.md\nByggguide: v3\n"));
        $this->assertNull(\App\Services\BuildGuide::versionFromClaudeMd("Text Byggguide: **v3**.\n"));
    }
```

Run: `cd $WT && php artisan test --filter=BuildGuideTest`
Expected: FAIL.

- [ ] **Steg 2: Implementationen.** I `BuildGuide`, efter `runsSecurityChecksInBuild`:

```php
    // Kan spel på versionen byggas för iOS i släpprepot? Styrs av manifestets "ios"-block
    // (v3-kontraktet, plan 2026-10-15-slapp-testflight Task 4). Bara låsta versioner räknas.
    public static function supportsIos(string $version): bool
    {
        if (! self::isPublished($version)) {
            return false;
        }

        try {
            return self::iosBlockIsValid(self::manifest($version)['ios'] ?? null);
        } catch (BuildGuideNotFound) {
            return false;
        }
    }

    public static function iosBlockIsValid(mixed $ios): bool
    {
        return is_array($ios)
            && ($ios['project'] ?? null) === 'ios/App/App.xcodeproj'
            && ($ios['scheme'] ?? null) === 'App'
            && ($ios['package_manager'] ?? null) === 'spm'
            && is_string($ios['capacitor'] ?? null)
            && preg_match('/^\d+\.\d+\.\d+\z/', $ios['capacitor']) === 1;
    }

    // Varje versions CLAUDE.md börjar med raden "Byggguide: **vN**." (v1, v2; kontraktet för v3).
    public static function versionFromClaudeMd(string $text): ?string
    {
        return preg_match('/^Byggguide: \*\*(v[1-9][0-9]{0,2})\*\*/m', $text, $m) === 1 ? $m[1] : null;
    }
```

`app/Releases/TestFlight/IosBuildGuides.php`:

```php
<?php

namespace App\Releases\TestFlight;

use App\Services\BuildGuide;

// Tunn, injicerbar väg till BuildGuide::supportsIos, så att släpptesterna kan köras innan
// någon låst version stöder iOS. Medvetet inte final.
class IosBuildGuides
{
    public function supports(string $version): bool
    {
        return BuildGuide::supportsIos($version);
    }
}
```

I `tests/Support/CreatesReleases.php`, lägg till:

```php
    /** Låtsas att de här byggguideversionerna stöder iOS (före v3). */
    protected function iosGuides(string ...$versions): void
    {
        $this->app->instance(\App\Releases\TestFlight\IosBuildGuides::class, new class($versions) extends \App\Releases\TestFlight\IosBuildGuides
        {
            public function __construct(private array $versions) {}

            public function supports(string $version): bool
            {
                return in_array($version, $this->versions, true);
            }
        });
    }
```

- [ ] **Steg 3: Kör.**

Run: `cd $WT && php artisan test --filter=BuildGuideTest`
Expected: PASS.

- [ ] **Steg 4: Commit.**

```bash
cd $WT && git add app/Services/BuildGuide.php app/Releases/TestFlight/IosBuildGuides.php tests/Feature/BuildGuideTest.php tests/Support/CreatesReleases.php
git commit -m "Byggguiden: vilka låsta versioner som stöder iOS (kontraktet för v3)"
```

---
### Task 5: Engångsstegen för `app_store` — automatiska steg, Eriks app-post och Filament

**Files:**
- Modify: `app/Releases/SetupSteps.php`, `app/Releases/ReleaseGate.php`, `app/Releases/ReleaseStatus.php`
- Create: `app/Releases/AppStore/AppStoreSetup.php`, `app/Releases/AppStore/AppStoreSetupFailed.php`, `app/Jobs/RunAppStoreSetup.php`
- Create: `database/migrations/2026_10_17_100002_assign_bundle_ids_and_sync_app_store_steps.php`
- Modify: `app/Mcp/Tools/SetReleaseTargetsTool.php`
- Modify: `app/Filament/Resources/GameResource/Pages/GameReleases.php`, `resources/views/filament/resources/game-resource/pages/game-releases.blade.php`
- Modify: `resources/faktabank/process/process-slapp-engangssteg.md` (stegens rader, så att `ReleaseFactbankSyncTest` håller; resten av artikeln i Task 9)
- Create: `tests/Feature/Releases/SetupStepsTest.php`, `tests/Feature/Releases/AppStoreSetupTest.php`
- Modify: `tests/Feature/Mcp/SetReleaseTargetsToolTest.php`, `tests/Feature/Filament/GameReleasesPageTest.php`, `tests/Support/CreatesReleases.php`

**Interfaces:**
- Consumes: `AppStoreConnectApi` (Task 1), `BundleId`, `Game::appStoreId()` (Task 3).
- Produces: `SetupSteps::DEFINITIONS` (mallar med `{bundle_id}`, `{name}`, `{sku}`), `AUTOMATIC`, `VERIFIED`, `BLOCKS`, `render(string, Game): string`, `blocks(string $key, string $stage): bool`, `openFor(Game, string $target, string $stage): ?ReleaseSetupStep`. `AppStoreSetup::run(Game): list<string>`. `RunAppStoreSetup` (jobb, unikt per spel). `CreatesReleases::testFlightGame(string $slug = 'kattsvep'): Game`.

- [ ] **Steg 1: Testerna för stegen (fallerar).** `tests/Feature/Releases/SetupStepsTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseGate;
use App\Releases\SetupSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class SetupStepsTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    public function test_instructions_are_rendered_with_the_games_values(): void
    {
        $game = $this->testFlightGame('tinker-isle');
        ReleaseSetupStep::where('game_id', $game->id)->delete();
        SetupSteps::sync($game);

        $text = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('instruction');
        $this->assertStringContainsString('bundle-id se.computercat.tinkerisle', $text);
        $this->assertStringContainsString('SKU tinker-isle', $text);
        $this->assertStringContainsString('namn Tinker-isle', $text);
        $this->assertStringNotContainsString('{', $text);
    }

    public function test_privacy_step_blocks_only_the_app_store_stage(): void // R6
    {
        $this->openProductionStages();
        $game = $this->testFlightGame();
        $this->demoBuild($game);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_privacy_and_age')->update(['status' => ReleaseSetupStep::OPEN]);

        $this->assertNull(SetupSteps::openFor($game, 'app_store', 'testflight'));
        $this->assertSame('appstore_privacy_and_age', SetupSteps::openFor($game, 'app_store', 'app_store')->key);
        $this->assertNull(ReleaseGate::check($game, 'app_store', 'testflight', $this->sha()));
        $this->assertStringContainsString('integritetsuppgifter', (string) ReleaseGate::check($game, 'app_store', 'app_store', $this->sha()));
    }

    public function test_other_open_steps_block_testflight_in_definition_order(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->whereIn('key', ['appstore_testflight_group', 'appstore_profile'])->update(['status' => ReleaseSetupStep::OPEN]);

        $this->assertSame('appstore_profile', SetupSteps::openFor($game, 'app_store', 'testflight')->key);
    }

    public function test_sync_refreshes_open_instructions_and_leaves_done_steps_alone(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN, 'instruction' => 'gammal text']);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->update(['instruction' => 'klar gammal text']);

        SetupSteps::sync($game);

        $this->assertStringContainsString('Ny app', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('instruction'));
        $this->assertSame('klar gammal text', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->value('instruction'));
    }
}
```

Lägg till i `tests/Support/CreatesReleases.php`:

```php
    /** Spel med app_store klart för TestFlight: bundle-id, App Store-id:n (spike B) och alla engångssteg klara. */
    protected function testFlightGame(string $slug = 'kattsvep'): Game
    {
        $game = $this->releasableGame($slug, ['app_store']);
        $game->forceFill([
            'bundle_id' => \App\Releases\AppStore\BundleId::forSlug($slug),
            'app_store_ids' => [
                'bundle_resource_id' => 'WN54V4BM5D', 'profile_id' => 'PROFTEST01', 'app_id' => '6823067635',
                'app_name' => ucfirst($slug), 'beta_group_id' => '0f1e2d3c-0000-4000-8000-000000000001',
            ],
        ])->save();
        \App\Releases\SetupSteps::sync($game);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => ReleaseSetupStep::DONE]);

        return $game->fresh();
    }
```

Run: `cd $WT && php artisan test --filter=SetupStepsTest`
Expected: FAIL.

- [ ] **Steg 2: `SetupSteps`.** Ersätt `app_store`-blocket i `DEFINITIONS`, `AUTOMATIC` och `sync` och lägg till de nya delarna:

```php
        'app_store' => [
            'appstore_bundle_id' => 'Computercat registrerar bundle-id {bundle_id} i App Store Connect via API.',
            'appstore_profile' => 'Computercat skapar App Store-profilen för {bundle_id} via API.',
            'appstore_create_app' => 'Erik skapar appen i App Store Connect (Appar, +, Ny app): plattform iOS, namn {name}, primärspråk svenska, bundle-id {bundle_id}, SKU {sku}, användaråtkomst full åtkomst. Är namnet upptaget väljer Erik ett annat. Computercat ser själv när appen finns.',
            'appstore_testflight_group' => 'Computercat skapar den interna TestFlight-gruppen ComputerCat intern och lägger till testarna via API.',
            'appstore_privacy_and_age' => 'Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect. Krävs före App Store, inte före TestFlight.',
        ],
```

```php
    /** Steg som Computercat själv gör (etapp 2). De kan inte bockas av för hand i Filament. */
    public const AUTOMATIC = ['appstore_bundle_id', 'appstore_profile', 'appstore_testflight_group'];

    /** Eriks steg som Computercat kontrollerar mot Apple innan de räknas som klara (R8). */
    public const VERIFIED = ['appstore_create_app'];

    /** Steg som bara stoppar vissa steg (R6). Ett steg som saknas här stoppar alla målets steg. */
    public const BLOCKS = ['appstore_privacy_and_age' => ['app_store']];

    public static function render(string $template, Game $game): string
    {
        return strtr($template, [
            '{bundle_id}' => (string) ($game->bundle_id ?? '(saknas)'),
            '{name}' => (string) $game->name,
            '{sku}' => (string) $game->slug,
        ]);
    }

    public static function blocks(string $key, string $stage): bool
    {
        return ! isset(self::BLOCKS[$key]) || in_array($stage, self::BLOCKS[$key], true);
    }

    // Första öppna steget som stoppar steget, i definitionsordning (inte radordning: steg som
    // lagts till i etapp 2 har högre id än 1a:s).
    public static function openFor(Game $game, string $target, string $stage): ?ReleaseSetupStep
    {
        $order = array_flip(array_keys(self::DEFINITIONS[$target] ?? []));

        return ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)->where('status', ReleaseSetupStep::OPEN)->get()
            ->filter(fn (ReleaseSetupStep $s) => self::blocks($s->key, $stage))
            ->sortBy(fn (ReleaseSetupStep $s) => $order[$s->key] ?? PHP_INT_MAX)
            ->first();
    }

    /** Skapar saknade steg och uppdaterar texten på öppna steg. Klara steg lämnas orörda; inget raderas. */
    public static function sync(Game $game): void
    {
        foreach ($game->releaseTargets() as $target) {
            foreach (self::DEFINITIONS[$target] ?? [] as $key => $template) {
                $instruction = self::render($template, $game);
                $step = ReleaseSetupStep::firstOrCreate(
                    ['game_id' => $game->id, 'target' => $target, 'key' => $key],
                    ['status' => ReleaseSetupStep::OPEN, 'instruction' => $instruction],
                );
                if ($step->status === ReleaseSetupStep::OPEN && $step->instruction !== $instruction) {
                    $step->update(['instruction' => $instruction]);
                }
            }
        }
    }
```

- [ ] **Steg 3: Spärr 6 och statusen per steg.** I `ReleaseGate::check`, ersätt raderna från `$open = ReleaseSetupStep::where(...)` till och med `return "Setup is not finished …";`-blocket med:

```php
        $open = SetupSteps::openFor($game, $target, $stage);
        if ($open !== null) { // 6, per steg (R6)
            return "Setup is not finished for {$target}: {$open->instruction}";
        }
```

och ta bort importen av `ReleaseSetupStep` om den inte används längre. I `ReleaseStatus::forTarget`, ersätt `$open = $steps->firstWhere('status', ReleaseSetupStep::OPEN);` med:

```php
        // Öppna steg som stoppar nästa steg i flödet (teststeget, eller produktionen när testet är Demo).
        $open = SetupSteps::openFor($game, $target, $test === 'demo' ? $prod : $test);
```

och i `flowState`, före `$testLabel = …`, lägg till:

```php
        if (($prodOpen = SetupSteps::openFor($game, $target, $prod)) !== null && ReleaseCatalog::isBuildable($prod)) {
            return ['setup_open', ['instruction' => $prodOpen->instruction]];
        }
```

Run: `cd $WT && php artisan test --filter='SetupStepsTest|ReleaseGateTest|ReleaseStatusTest'`
Expected: PASS. Fallerar ett befintligt test i `ReleaseGateTest` eller `ReleaseStatusTest` som öppnar `appstore_privacy_and_age` och väntar sig att TestFlight stoppas: byt steget i testet till `appstore_create_app` (avsikten "ett öppet steg stoppar" står kvar) och skriv R6 i testets kommentar.

- [ ] **Steg 4: Testerna för de automatiska stegen (fallerar).** `tests/Feature/Releases/AppStoreSetupTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\ReleaseSetupStep;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\AppStore\AppStoreSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AppStoreFakes as A;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class AppStoreSetupTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        A::configure();
        $this->startHttpTransactionGuard();
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    private function freshAppStoreGame()
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $game->forceFill(['bundle_id' => 'se.computercat.kattsvep', 'app_store_ids' => null])->save();
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => ReleaseSetupStep::OPEN, 'done_at' => null]);

        return $game->fresh();
    }

    private function status($game): array
    {
        return ReleaseSetupStep::where('game_id', $game->id)->pluck('status', 'key')->all();
    }

    public function test_all_automatic_steps_run_in_order_when_the_app_exists(): void
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::one(A::bundleId('se.computercat.kattsvep')), 201),
            A::API.'/v1/profiles?*' => Http::response(A::many()),
            A::API.'/v1/profiles' => Http::response(A::one(A::profile()), 201),
            A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep'))),
            A::API.'/v1/betaGroups?*' => Http::response(A::many()),
            A::API.'/v1/betaGroups' => Http::response(A::one(A::betaGroup()), 201),
            A::API.'/v1/betaTesters?*' => Http::response(A::many()),
            A::API.'/v1/betaTesters' => Http::sequence()->push(A::one(A::betaTester('erik@example.test', 't-1')), 201)->push(A::one(A::betaTester('mattias@example.test', 't-2')), 201),
        ]);

        $done = app(AppStoreSetup::class)->run($game);

        $this->assertSame(['appstore_bundle_id', 'appstore_profile', 'appstore_create_app', 'appstore_testflight_group'], $done);
        $this->assertSame('open', $this->status($game)['appstore_privacy_and_age']);
        $ids = $game->fresh()->app_store_ids;
        $this->assertSame([A::BUNDLE_RESOURCE, A::PROFILE, A::APP, A::GROUP], [$ids['bundle_resource_id'], $ids['profile_id'], $ids['app_id'], $ids['beta_group_id']]);
        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/profiles' && $r->data()['data']['relationships']['certificates']['data'][0]['id'] === A::CERT);
        $this->assertNull(ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->value('done_by'));
    }

    public function test_create_app_step_is_done_only_when_apple_has_the_app(): void // R8
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/profiles?*' => Http::response(A::many(A::profile())),
            A::API.'/v1/apps?*' => Http::response(A::many()),
        ]);

        $done = app(AppStoreSetup::class)->run($game);

        $this->assertSame(['appstore_bundle_id', 'appstore_profile'], $done);
        $this->assertSame('open', $this->status($game)['appstore_create_app']);
        $this->assertSame('open', $this->status($game)['appstore_testflight_group']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/betaGroups'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_second_run_creates_nothing_new(): void
    {
        $game = $this->testFlightGame();
        Http::fake();

        $this->assertSame([], app(AppStoreSetup::class)->run($game));
        Http::assertNothingSent();
    }

    public function test_existing_tester_is_linked_and_member_is_left_alone(): void
    {
        $game = $this->freshAppStoreGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', '!=', 'appstore_testflight_group')->update(['status' => ReleaseSetupStep::DONE]);
        $game->forceFill(['app_store_ids' => ['app_id' => A::APP]])->save();
        Http::fake([
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup())),
            // erik är redan med i gruppen; mattias finns som testare men inte i gruppen.
            A::API.'/v1/betaTesters?*' => function (Request $r) {
                $url = urldecode($r->url());
                if (str_contains($url, 'erik@example.test')) {
                    return Http::response(A::many(A::betaTester('erik@example.test', 't-1')));
                }

                return Http::response(str_contains($url, 'filter[betaGroups]') ? A::many() : A::many(A::betaTester('mattias@example.test', 't-2')));
            },
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/betaTesters' => Http::response('', 204),
        ]);

        app(AppStoreSetup::class)->run($game->fresh());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/betaTesters') && $r->data() === ['data' => [['type' => 'betaTesters', 'id' => 't-2']]]);
        Http::assertNotSent(fn (Request $r) => $r->url() === A::API.'/v1/betaTesters' && $r->method() === 'POST');
        $this->assertSame('done', $this->status($game)['appstore_testflight_group']);
    }

    public function test_an_error_is_saved_on_the_step_redacted_and_rethrown(): void
    {
        $game = $this->freshAppStoreGame();
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many()),
            A::API.'/v1/bundleIds' => Http::response(A::error(403, 'FORBIDDEN_ERROR', 'The API key in use does not allow this request'), 403),
        ]);

        try {
            app(AppStoreSetup::class)->run($game);
            $this->fail('Felet kastades inte.');
        } catch (ExternalApiException) {
            $error = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_bundle_id')->value('last_error');
            $this->assertStringContainsString('returned 403', $error);
            $this->assertStringNotContainsString('eyJ', $error);
            $this->assertSame('open', $this->status($game)['appstore_bundle_id']);
        }
    }

    public function test_game_without_app_store_target_is_left_alone(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        Http::fake();

        $this->assertSame([], app(AppStoreSetup::class)->run($game));
        Http::assertNothingSent();
    }
}
```

Run: `cd $WT && php artisan test --filter=AppStoreSetupTest`
Expected: FAIL.

- [ ] **Steg 5: `AppStoreSetup`, felet och jobbet.**

```php
<?php

namespace App\Releases\AppStore;

use App\Provisioning\SafeMessage;

final class AppStoreSetupFailed extends \RuntimeException implements SafeMessage {}
```

```php
<?php

namespace App\Releases\AppStore;

use App\Models\Game;
use App\Models\ReleaseSetupStep;
use App\Provisioning\SafeMessage;
use App\Releases\SetupSteps;
use Illuminate\Support\Facades\Log;
use Throwable;

// Computercats engångssteg för app_store, i definitionsordning. Varje steg letar först och skapar
// sedan, så att en omkörning efter ett fel aldrig skapar dubbletter. Inga HTTP-anrop i transaktioner.
final class AppStoreSetup
{
    public function __construct(private AppStoreConnectApi $asc) {}

    /** @return list<string> steg som blev klara i den här körningen */
    public function run(Game $game): array
    {
        if (! in_array('app_store', $game->releaseTargets(), true) || $game->bundle_id === null) {
            return [];
        }

        $done = [];
        foreach (array_keys(SetupSteps::DEFINITIONS['app_store']) as $key) {
            $step = ReleaseSetupStep::where('game_id', $game->id)->where('target', 'app_store')->where('key', $key)->first();
            if ($step === null || $step->status === ReleaseSetupStep::DONE) {
                continue;
            }

            try {
                $finished = match ($key) {
                    'appstore_bundle_id' => $this->bundleId($game),
                    'appstore_profile' => $this->profile($game),
                    'appstore_create_app' => $this->detectApp($game),
                    'appstore_testflight_group' => $this->group($game),
                    default => null, // Eriks steg som inte kan kontrolleras via API (integritet, åldersgräns)
                };
            } catch (Throwable $e) {
                $step->update(['last_error' => $e instanceof SafeMessage ? $e->getMessage() : 'Internal error ('.class_basename($e).').']);
                throw $e;
            }

            if ($finished === null) {
                continue;
            }
            if ($finished === false) {
                break; // appen finns inte än: gruppen kräver den
            }

            $step->update(['status' => ReleaseSetupStep::DONE, 'done_by' => null, 'done_at' => now(), 'last_error' => null]);
            Log::notice('Engångssteg klart via App Store Connect', ['game' => $game->slug, 'step' => $key]);
            $done[] = $key;
            $game->refresh();
        }

        return $done;
    }

    private function bundleId(Game $game): bool
    {
        $found = $this->asc->findBundleId($game->bundle_id)
            ?? $this->asc->createBundleId($game->bundle_id, 'ComputerCat '.str_replace('-', ' ', $game->slug));
        $this->remember($game, ['bundle_resource_id' => $found['id']]);

        return true;
    }

    // R13: en profil som inte är ACTIVE förnyas inte här; sign-jobbet skulle falla, så det syns.
    private function profile(Game $game): bool
    {
        $bundle = $game->appStoreId('bundle_resource_id') ?? throw new AppStoreSetupFailed('The bundle id is not registered yet.');
        $name = 'ComputerCat '.str_replace('-', ' ', $game->slug).' App Store';

        $profile = $this->asc->findProfile($name);
        if ($profile !== null && $profile['state'] !== 'ACTIVE') {
            throw new AppStoreSetupFailed("Profile \"{$name}\" exists but is {$profile['state']}; Erik must remove or renew it in the developer portal.");
        }
        $profile ??= $this->asc->createProfile($name, $bundle, AppStoreConfig::certificateId());
        $this->remember($game, ['profile_id' => $profile['id']]);

        return true;
    }

    private function detectApp(Game $game): bool
    {
        $app = $this->asc->findApp($game->bundle_id);
        if ($app === null) {
            return false;
        }
        $this->remember($game, ['app_id' => $app['id'], 'app_name' => $app['name']]);

        return true;
    }

    private function group(Game $game): bool
    {
        $appId = $game->appStoreId('app_id') ?? throw new AppStoreSetupFailed('The app does not exist in App Store Connect yet.');
        $testers = AppStoreConfig::testers();

        $group = $this->asc->findBetaGroup($appId, AppStoreConfig::betaGroup())
            ?? $this->asc->createInternalBetaGroup($appId, AppStoreConfig::betaGroup());
        $this->remember($game, ['beta_group_id' => $group['id']]);

        foreach ($testers as $email) {
            if ($this->asc->findBetaTester($email, $group['id']) !== null) {
                continue;
            }
            $existing = $this->asc->findBetaTester($email);
            $existing !== null
                ? $this->asc->addTestersToGroup($group['id'], [$existing['id']])
                : $this->asc->createBetaTester($email, $group['id']);
        }

        return true;
    }

    private function remember(Game $game, array $ids): void
    {
        $game->forceFill(['app_store_ids' => array_merge($game->app_store_ids ?? [], $ids)])->save();
    }
}
```

`app/Jobs/RunAppStoreSetup.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Game;
use App\Provisioning\SafeMessage;
use App\Releases\AppStore\AppStoreSetup;
use App\Support\SlackAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Kör Computercats engångssteg för app_store. Köas när app_store läggs till, när Erik bockar av
// app-posten och från Filament. Ett fel stannar på steget (last_error) och larmar; ingen omförsöksloop.
class RunAppStoreSetup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    // Under databasköns retry_after (90 s).
    public int $timeout = 80;

    public int $uniqueFor = 600;

    public function __construct(public int $gameId) {}

    public function uniqueId(): string
    {
        return 'appstore-setup-'.$this->gameId;
    }

    public function handle(AppStoreSetup $setup, SlackAlert $slack): void
    {
        $game = Game::find($this->gameId);
        if ($game === null || ! $game->is_active) {
            return;
        }

        try {
            $setup->run($game);
        } catch (Throwable $e) {
            $ref = (string) Str::uuid();
            Log::error('Engångssteg för App Store misslyckades', ['ref' => $ref, 'game' => $game->slug, 'exception' => $e]);
            $why = $e instanceof SafeMessage ? $e->getMessage() : "internt fel (ref {$ref})";
            $slack->send("appstore-setup-failed:{$game->slug}", 'Engångsstegen för App Store stannade för `'.SlackAlert::escape($game->slug).'`: '.SlackAlert::escape($why).' Kör igen från Filament när orsaken är åtgärdad.');
        }
    }
}
```

Run: `cd $WT && php artisan test --filter=AppStoreSetupTest`
Expected: PASS.

- [ ] **Steg 6: `set_release_targets` ger bundle-id och köar stegen.** Testerna först, i `tests/Feature/Mcp/SetReleaseTargetsToolTest.php`: lägg till `Queue::fake();` sist i `setUp()` (importera `Illuminate\Support\Facades\Queue` och `App\Jobs\RunAppStoreSetup`), och:

```php
    public function test_app_store_gets_a_bundle_id_and_queues_the_setup(): void
    {
        $result = $this->set('app_store');

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame('se.computercat.kattsvep', $this->game->fresh()->bundle_id);
        Queue::assertPushed(RunAppStoreSetup::class, fn (RunAppStoreSetup $job) => $job->gameId === $this->game->id);
        $this->assertStringContainsString('bundle-id se.computercat.kattsvep', $result['text']);
    }

    public function test_colliding_bundle_id_is_refused(): void
    {
        $other = $this->game('ab-c');
        $other->forceFill(['bundle_id' => 'se.computercat.abc'])->save();
        $this->game('abc');

        $result = $this->set('app_store', slug: 'abc');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('se.computercat.abc', $result['text']);
        $this->assertStringNotContainsString('ab-c', $result['text']);
        $this->assertSame([], Game::where('slug', 'abc')->first()->releaseTargets());
        Queue::assertNothingPushed();
    }

    public function test_web_only_queues_nothing(): void
    {
        $this->set('web');

        Queue::assertNothingPushed();
        $this->assertNull($this->game->fresh()->bundle_id);
    }
```

(`$this->game('ab-c')` är testklassens befintliga hjälpare; saknar den platform admin-åtkomst till ett andra spel räcker det att `adminUser()` är platform admin.)

I `SetReleaseTargetsTool::run`, efter kontrollen av borttagna mål och före `$game->forceFill(['release_targets' => …])`:

```php
        if (in_array('app_store', $targets, true) && $game->bundle_id === null) {
            $bundle = BundleId::forSlug($game->slug);
            // Specen: krockar två slugs vägrar verktyget, och Erik väljer ett annat id. Vilket spel som
            // har id:t sägs inte (spelet kan tillhöra någon annan).
            if (Game::where('bundle_id', $bundle)->whereKeyNot($game->getKey())->exists()) {
                return Response::error("Bundle id {$bundle} is already used by another game. Ask Erik to choose another bundle id for this game.");
            }
            $game->forceFill(['bundle_id' => $bundle]);
        }
```

och efter `SetupSteps::sync($game);`:

```php
        if (in_array('app_store', $targets, true)) {
            // Verktyget körs i en transaktion; jobbet (HTTP mot Apple) startar först efter commit.
            RunAppStoreSetup::dispatch($game->id)->afterCommit();
        }
```

Importera `App\Jobs\RunAppStoreSetup` och `App\Releases\AppStore\BundleId`. Uppdatera verktygets `$description` med meningen: "Adding app_store gives the game its bundle id (se.computercat.<slug without hyphens>) and starts Computercat's App Store setup steps."

Run: `cd $WT && php artisan test --filter=SetReleaseTargetsToolTest`
Expected: PASS.

- [ ] **Steg 7: Befintliga `app_store`-spel (migration).** `database/migrations/2026_10_17_100002_assign_bundle_ids_and_sync_app_store_steps.php`:

```php
<?php

use App\Models\Game;
use App\Releases\AppStore\BundleId;
use App\Releases\SetupSteps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// Spel som fick app_store i etapp 1a får bundle-id och de nya engångsstegen. En krock fäller
// migrationen (unikt index): fail loud, Erik väljer id. Automatiska steg körs först när Erik trycker
// "Kör Computercats steg" i Filament (inga anrop mot Apple från en migration).
return new class extends Migration
{
    public function up(): void
    {
        foreach (Game::query()->orderBy('id')->get() as $game) {
            if (! in_array('app_store', $game->releaseTargets(), true)) {
                continue;
            }
            if ($game->bundle_id === null) {
                $game->forceFill(['bundle_id' => BundleId::forSlug($game->slug)])->save();
            }
            SetupSteps::sync($game);
            Log::notice('Etapp 2: bundle-id och App Store-steg', ['game' => $game->slug, 'bundle_id' => $game->bundle_id]);
        }
    }

    public function down(): void
    {
        // Ingenting: bundle-id och steg tas bort med kolumnerna i 100001:s down.
    }
};
```

Test i `SetupStepsTest`:

```php
    public function test_migration_gives_existing_app_store_games_a_bundle_id_and_the_new_steps(): void
    {
        $game = $this->releasableGame('tinker-isle', ['web', 'app_store']);
        $game->forceFill(['bundle_id' => null])->save();
        ReleaseSetupStep::where('game_id', $game->id)->whereIn('key', ['appstore_profile', 'appstore_testflight_group'])->delete();
        $web = $this->releasableGame('kattsvep', ['web']);

        (require database_path('migrations/2026_10_17_100002_assign_bundle_ids_and_sync_app_store_steps.php'))->up();

        $this->assertSame('se.computercat.tinkerisle', $game->fresh()->bundle_id);
        $this->assertSame('open', ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')->value('status'));
        $this->assertNull($web->fresh()->bundle_id);
    }
```

Run: `cd $WT && php artisan test --filter=SetupStepsTest`
Expected: PASS.

- [ ] **Steg 8: Filament.** Testerna först, i `GameReleasesPageTest` (importera `App\Jobs\RunAppStoreSetup`, `Illuminate\Support\Facades\Queue`, `Tests\Support\AppStoreFakes`):

```php
    public function test_marking_create_app_checks_apple_first(): void // R8
    {
        AppStoreFakes::configure();
        Queue::fake();
        $game = $this->testFlightGame('tinker-isle');
        $step = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->first();
        $step->update(['status' => 'open']);
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok'), AppStoreFakes::API.'/v1/apps?*' => Http::sequence()
            ->push(AppStoreFakes::many())
            ->push(AppStoreFakes::many(AppStoreFakes::app('se.computercat.tinkerisle', '6800000001', 'Tinker Isle')))]);
        $this->actingAs($admin = $this->adminUser());
        $page = Livewire::test(GameReleases::class, ['record' => $game->getKey()]);

        $page->call('markStepDone', $step->id);
        $this->assertSame('open', $step->fresh()->status);

        $page->call('markStepDone', $step->id);
        $this->assertSame('done', $step->fresh()->status);
        $this->assertSame($admin->id, $step->fresh()->done_by);
        $this->assertSame('6800000001', $game->fresh()->appStoreId('app_id'));
        Queue::assertPushed(RunAppStoreSetup::class);
    }

    public function test_new_automatic_steps_cannot_be_marked_by_hand(): void
    {
        $game = $this->testFlightGame('tinker-isle');
        $step = ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')->first();
        $step->update(['status' => 'open']);
        $this->actingAs($this->adminUser());

        Livewire::test(GameReleases::class, ['record' => $game->getKey()])->call('markStepDone', $step->id);

        $this->assertSame('open', $step->fresh()->status);
    }

    public function test_run_setup_queues_the_job_and_shows_the_app_store_ids(): void
    {
        Queue::fake();
        $game = $this->testFlightGame('tinker-isle');
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')->update(['status' => 'open', 'last_error' => 'App Store Connect POST /v1/profiles returned 409']);

        $html = $this->actingAs($this->adminUser())->get(GameResource::getUrl('releases', ['record' => $game]))->assertOk()->getContent();
        $this->assertStringContainsString('se.computercat.tinkerisle', $html);
        $this->assertStringContainsString('returned 409', $html);

        Livewire::test(GameReleases::class, ['record' => $game->getKey()])->call('runAppStoreSetup');
        Queue::assertPushed(RunAppStoreSetup::class, fn ($job) => $job->gameId === $game->id);
    }
```

I `GameReleases`:
1. I `markStepDone`, efter kontrollen av `DONE` och före `$step->update(...)`:

```php
        // R8: app-posten räknas som klar först när Apple har appen med spelets bundle-id.
        if (in_array($step->key, SetupSteps::VERIFIED, true)) {
            $game = $this->getRecord();
            try {
                $app = app(AppStoreConnectApi::class)->findApp((string) $game->bundle_id);
            } catch (ExternalApiException|AppStoreNotConfigured $e) {
                Notification::make()->danger()->title('App Store Connect svarade inte: '.$e->getMessage())->send();

                return;
            }
            if ($app === null) {
                Notification::make()->danger()->title("Appen med bundle-id {$game->bundle_id} finns inte i App Store Connect ännu.")->send();

                return;
            }
            $game->forceFill(['app_store_ids' => array_merge($game->app_store_ids ?? [], ['app_id' => $app['id'], 'app_name' => $app['name']])])->save();
        }
```

och efter `$step->update([...])`:

```php
        if ($step->target === 'app_store') {
            RunAppStoreSetup::dispatch($this->getRecord()->id);
        }
```

2. Nya metoder:

```php
    public function runAppStoreSetup(): void
    {
        $this->admin();
        RunAppStoreSetup::dispatch($this->getRecord()->id);
        Notification::make()->success()->title('Computercats engångssteg för App Store körs. Ladda om sidan om en minut.')->send();
    }

    /** @return array{bundle_id: ?string, ios_build_number: int, ids: array<string, string>} */
    public function getAppStore(): array
    {
        $game = $this->getRecord()->fresh();

        return ['bundle_id' => $game->bundle_id, 'ios_build_number' => (int) $game->ios_build_number, 'ids' => $game->app_store_ids ?? []];
    }
```

Importera `App\Jobs\RunAppStoreSetup`, `App\Releases\AppStore\AppStoreConnectApi`, `App\Releases\AppStore\AppStoreNotConfigured`, `App\Provisioning\Http\ExternalApiException`.

3. I vyn, före avsnittet "Engångssteg":

```blade
        @if (in_array('app_store', $this->getRecord()->releaseTargets(), true))
            @php($appStore = $this->getAppStore())
            <x-filament::section>
                <x-slot name="heading">App Store</x-slot>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>Bundle-id</dt><dd class="font-mono">{{ $appStore['bundle_id'] ?? 'saknas' }}</dd>
                    <dt>Senaste byggnummer</dt><dd class="font-mono">{{ $appStore['ios_build_number'] }}</dd>
                    @foreach ($appStore['ids'] as $key => $value)
                        <dt>{{ $key }}</dt><dd class="font-mono">{{ $value }}</dd>
                    @endforeach
                </dl>
                <x-filament::button class="mt-4" color="gray" wire:click="runAppStoreSetup">Kör Computercats steg</x-filament::button>
            </x-filament::section>
        @endif
```

och i stegets rad, efter `<div>{{ $step->instruction }}</div>`:

```blade
                        @if ($step->last_error)
                            <div class="text-xs text-danger-600">{{ $step->last_error }}</div>
                        @endif
```

Run: `cd $WT && php artisan test --filter=GameReleasesPageTest`
Expected: PASS.

- [ ] **Steg 9: Artikelns stegrader.** I `resources/faktabank/process/process-slapp-engangssteg.md`, ersätt de tre `appstore_*`-raderna under "Så gör du" med fem rader, i definitionsordning, med exakt mallarna från steg 2 (`- \`appstore_bundle_id\`: Computercat registrerar bundle-id {bundle_id} i App Store Connect via API.` osv.). Övrig text ändras i Task 9.

Run: `cd $WT && php artisan test --filter=ReleaseFactbankSyncTest`
Expected: PASS.

- [ ] **Steg 10: Commit.**

```bash
cd $WT && git add app/Releases app/Jobs/RunAppStoreSetup.php app/Mcp/Tools/SetReleaseTargetsTool.php app/Filament resources/views/filament database/migrations/2026_10_17_100002_assign_bundle_ids_and_sync_app_store_steps.php resources/faktabank/process/process-slapp-engangssteg.md tests
git commit -m "Engångssteg för App Store: bundle-id, profil och TestFlight-grupp via API, app-posten kontrolleras mot Apple, integritetssteget stoppar bara App Store"
```

---
### Task 6: `promote_release` för TestFlight — versionsspärren, taggkollen och byggnumret

**Files:**
- Create: `app/Releases/TestFlight/TestFlightPreflight.php`, `app/Releases/TestFlight/PreflightResult.php`
- Modify: `app/Releases/ReleaseCatalog.php`, `app/Releases/ReleaseService.php`
- Create: `tests/Feature/Releases/TestFlightPromoteTest.php`
- Modify: `tests/Feature/Releases/ReleaseServiceTest.php`, `tests/Feature/Mcp/PromoteReleaseToolTest.php`, `tests/Feature/Releases/ReleaseNotifierTest.php`

**Interfaces:**
- Consumes: `GitHubAppClient::getFile(…, $ref)`, `getTag()` (Task 2), `MarketingVersion` (Task 3), `IosBuildGuides`, `BuildGuide::versionFromClaudeMd` (Task 4), `testFlightGame()` (Task 5).
- Produces: `ReleaseCatalog::BUILT_BY_COMPUTERCAT = ['testflight']`, `ReleaseCatalog::isBuiltByComputercat(string $stage): bool`. `TestFlightPreflight::check(Game, string $commit): PreflightResult` (kastar `ReleaseRefused`), `TestFlightPreflight::lastReleased(Game): ?MarketingVersion`. `PreflightResult{version: MarketingVersion, buildGuide: string}`. Ett TestFlight-släpp skapas med `marketing_version`, `build_number`, `build_guide`; `games.ios_build_number` ökar med 1 i samma transaktion. (Jobbet köas i Task 7.)

- [ ] **Steg 1: Testerna (fallerar).** `tests/Feature/Releases/TestFlightPromoteTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class TestFlightPromoteTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    private Game $game;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->iosGuides('v3');
        $this->startHttpTransactionGuard();
        $this->game = $this->testFlightGame();
        $this->owner = $this->ownerOf($this->game);
        $this->demoBuild($this->game, $this->sha('a'));
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    /** Spelrepot vid commiten. $tag = ['sha' => …, 'type' => …] om taggen v<version> redan finns. */
    private function repo(mixed $version = '1.2.0', string $guide = 'v3', ?array $tag = null, int $packageStatus = 200): void
    {
        $base = F::GITHUB.'/repos/HumblebragAB/kattsvep';
        Http::fake([
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            $base.'/contents/package.json?ref=*' => $packageStatus === 200
                ? Http::response(F::githubContent(json_encode(['name' => 'kattsvep', 'version' => $version])))
                : Http::response(['message' => 'Server Error'], $packageStatus),
            $base.'/contents/CLAUDE.md?ref=*' => Http::response(F::githubContent("# CLAUDE.md\n\nByggguide: **{$guide}**. Grundkoden kommer från byggguiden.\n")),
            $base.'/git/ref/tags/*' => $tag === null
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response(F::githubTagRef("v{$version}", $tag['sha'], $tag['type'] ?? 'commit')),
        ]);
    }

    private function promote(?string $commit = null): Release
    {
        return app(ReleaseService::class)->promote($this->game->fresh(), $this->owner, 'app_store', 'testflight', $commit);
    }

    private function refused(string ...$needles): void
    {
        try {
            $this->promote();
            $this->fail('Släppet nekades inte.');
        } catch (ReleaseRefused $e) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->assertSame(0, Release::where('stage', 'testflight')->where('status', Release::REQUESTED)->count());
    }

    private function released(string $version, string $status = Release::SUCCEEDED, int $build = 1, ?string $commit = null): Release
    {
        return $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => $version, 'build_number' => $build, 'build_guide' => 'v3', 'commit_sha' => $commit ?? $this->sha('c')]);
    }

    public function test_promote_reads_version_and_guide_at_the_commit_and_takes_the_next_build_number(): void
    {
        $this->game->forceFill(['ios_build_number' => 4])->save();
        $this->repo('1.2.0');

        $release = $this->promote();

        $this->assertSame([Release::REQUESTED, $this->sha('a'), '1.2.0', 5, 'v3'], [$release->status, $release->commit_sha, $release->marketing_version, $release->build_number, $release->build_guide]);
        $this->assertSame(5, $this->game->fresh()->ios_build_number);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/package.json?ref='.$this->sha('a')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/ref/tags/v1.2.0'));
    }

    public function test_version_must_be_higher_than_the_latest_succeeded_release(): void // R3
    {
        $this->released('1.2.0');
        $this->repo('1.2.0');

        $this->refused('1.2.0 is already released to TestFlight', 'for example 1.2.1');
    }

    public function test_lower_version_is_refused(): void
    {
        $this->released('2.0.0');
        $this->repo('1.9.9');

        $this->refused('above 2.0.0');
    }

    public function test_versions_compare_numerically(): void
    {
        $this->released('1.9.0');
        $this->repo('1.10.0');

        $this->assertSame('1.10.0', $this->promote()->marketing_version);
    }

    public function test_failed_release_does_not_block_a_retry_of_the_same_commit(): void
    {
        $this->released('1.2.0', Release::FAILED, 3, $this->sha('a'));
        $this->game->forceFill(['ios_build_number' => 3])->save();
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a')]);

        $release = $this->promote();

        $this->assertSame(['1.2.0', 4], [$release->marketing_version, $release->build_number]);
    }

    public function test_tag_on_another_commit_is_refused_with_bump_advice(): void // R3
    {
        $this->repo('1.2.0', tag: ['sha' => $this->sha('f')]);

        $this->refused('Tag v1.2.0 already exists on another commit', 'for example 1.2.1');
    }

    public function test_annotated_tag_is_treated_as_another_commit(): void
    {
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a'), 'type' => 'tag']);

        $this->refused('Tag v1.2.0 already exists');
    }

    public function test_invalid_version_is_refused_with_the_format(): void
    {
        $this->repo('1.2');

        $this->refused('"1.2"', 'MAJOR.MINOR.PATCH');
    }

    public function test_build_guide_without_ios_is_refused(): void
    {
        $this->repo('1.2.0', guide: 'v2');

        $this->refused('build guide v2', 'cannot be built for iOS');
    }

    public function test_missing_app_store_setup_is_refused_before_any_github_call(): void
    {
        $this->game->forceFill(['app_store_ids' => ['bundle_resource_id' => 'WN54V4BM5D']])->save();
        Http::fake();

        $this->refused('App Store setup is not complete', 'missing profile_id');
        Http::assertNothingSent();
    }

    public function test_gate_refusal_comes_before_github(): void
    {
        $this->game->demoBuilds()->delete();
        Http::fake();

        $this->refused('No successful Demo build');
        Http::assertNothingSent();
    }

    public function test_github_error_becomes_a_clear_refusal(): void
    {
        $this->repo('1.2.0', packageStatus: 502);

        $this->refused('Could not read the game repository', 'returned 502');
    }

    public function test_build_numbers_never_repeat(): void // Review Focus 2
    {
        $this->game->forceFill(['ios_build_number' => 7])->save();
        $this->repo('1.2.0', tag: ['sha' => $this->sha('a')]);

        $first = $this->promote();
        $first->update(['status' => Release::FAILED]);
        $second = $this->promote();

        $this->assertSame([8, 9], [$first->build_number, $second->build_number]);
        $this->assertSame(9, $this->game->fresh()->ios_build_number);
    }

    public function test_a_pending_testflight_release_is_refused_before_github(): void // spärr 4
    {
        $this->released('1.1.0', Release::BUILDING, 2);
        Http::fake();

        $this->refused('already pending');
        Http::assertNothingSent();
    }
}
```

Run: `cd $WT && php artisan test --filter=TestFlightPromoteTest`
Expected: FAIL.

- [ ] **Steg 2: `ReleaseCatalog`.** Lägg till:

```php
    /** Steg som Computercat själv bygger i den här etappen (etapp 2 del 1: bara TestFlight). */
    public const BUILT_BY_COMPUTERCAT = ['testflight'];

    public static function isBuiltByComputercat(string $stage): bool
    {
        return in_array($stage, self::BUILT_BY_COMPUTERCAT, true);
    }
```

och ändra `BUILD_ETAPP['app_store']` från `'2'` till `'2 (App Store-delen)'`, så att texten inte påstår att produktionen kommer i den etapp som nu är live.

- [ ] **Steg 3: Förkontrollen.**

```php
<?php

namespace App\Releases\TestFlight;

use App\Releases\MarketingVersion;

final class PreflightResult
{
    public function __construct(public readonly MarketingVersion $version, public readonly string $buildGuide) {}
}
```

```php
<?php

namespace App\Releases\TestFlight;

use App\Models\Game;
use App\Models\Release;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\MarketingVersion;
use App\Releases\ReleaseRefused;
use App\Services\BuildGuide;

// Det promote_release kontrollerar i spelrepot innan ett TestFlight-släpp skapas (Eriks beslut
// 2026-10-15, plan R2–R4). Körs före transaktionen: inga HTTP-anrop under spelets lås. Svaren säger
// exakt vad som ska höjas, så att spelets Claude kan rätta utan att fråga.
class TestFlightPreflight
{
    private const IDS = ['bundle_resource_id', 'profile_id', 'app_id', 'beta_group_id'];

    public function __construct(private GitHubAppClient $github, private IosBuildGuides $guides) {}

    /** @throws ReleaseRefused */
    public function check(Game $game, string $commit): PreflightResult
    {
        foreach (self::IDS as $key) {
            if ($game->bundle_id === null || $game->appStoreId($key) === null) {
                throw new ReleaseRefused("App Store setup is not complete for this game (missing {$key}). Ask Erik to check the setup steps in Filament.");
            }
        }

        $short = substr($commit, 0, 7);
        try {
            $package = $this->github->getFile($game->slug, 'package.json', $commit);
            $claude = $this->github->getFile($game->slug, 'CLAUDE.md', $commit);
        } catch (ExternalApiException $e) {
            throw new ReleaseRefused("Could not read the game repository at commit {$short}: {$e->getMessage()} Try again in a minute.");
        }

        $json = $package === null ? null : json_decode($package['text'], true);
        $raw = is_array($json) ? ($json['version'] ?? null) : null;
        $version = MarketingVersion::parse($raw);
        if ($version === null) {
            throw new ReleaseRefused("package.json at commit {$short} has version ".json_encode($raw).'; use MAJOR.MINOR.PATCH (for example 1.0.0), run npm run check, push to main, wait for the Demo build and call promote_release again.');
        }

        $guide = $claude === null ? null : BuildGuide::versionFromClaudeMd($claude['text']);
        if ($guide === null || ! $this->guides->supports($guide)) {
            throw new ReleaseRefused("Commit {$short} is on build guide ".($guide ?? '(unknown)').', which cannot be built for iOS. Move the game to the latest build guide (get_build_guide) before sending it to TestFlight.');
        }

        $last = self::lastReleased($game);
        if ($last !== null && ! $version->isHigherThan($last)) {
            throw new ReleaseRefused("package.json at commit {$short} has version {$version}, but {$last} is already released to TestFlight. Raise version in package.json above {$last} (for example {$last->nextPatch()}), run npm run check, push to main, wait for the Demo build and call promote_release again.");
        }

        try {
            $tag = $this->github->getTag($game->slug, 'v'.$version);
        } catch (ExternalApiException $e) {
            throw new ReleaseRefused("Could not read the game repository at commit {$short}: {$e->getMessage()} Try again in a minute.");
        }
        // R3: samma commit får försökas igen; en ny commit kräver en ny version. Taggen flyttas aldrig.
        if ($tag !== null && ($tag['type'] !== 'commit' || $tag['sha'] !== $commit)) {
            throw new ReleaseRefused("Tag v{$version} already exists on another commit in the game repository. Every new commit to TestFlight needs a new version: raise version in package.json (for example {$version->nextPatch()}), run npm run check, push to main, wait for the Demo build and call promote_release again.");
        }

        return new PreflightResult($version, $guide);
    }

    public static function lastReleased(Game $game): ?MarketingVersion
    {
        return MarketingVersion::highest(Release::where('game_id', $game->id)->where('target', 'app_store')
            ->where('stage', 'testflight')->where('status', Release::SUCCEEDED)->pluck('marketing_version'));
    }
}
```

- [ ] **Steg 4: `ReleaseService::promote`.** Ersätt metoden med:

```php
    /** @throws ReleaseRefused */
    public function promote(Game $game, User $user, string $target, string $stage, ?string $commit): Release
    {
        if (! GameAccess::isOwner($user, $game)) { // R2
            throw new ReleaseRefused('Only the game owner can promote a release.');
        }

        // Utanför transaktionen: en utgången plats ska vara fri när låset tas.
        Release::expireStale();

        // Steg som Computercat bygger läser spelrepot först (HTTP, därför före transaktionen).
        // Commiten bestäms här och kontrolleras igen under låset.
        $preflight = null;
        if (ReleaseCatalog::isBuiltByComputercat($stage)) {
            $commit ??= DemoBuild::latestFor($game)?->commit_sha;
            if (($why = ReleaseGate::check($game, $target, $stage, $commit)) !== null) {
                throw new ReleaseRefused($why);
            }
            if (Release::active()->where('game_id', $game->id)->where('target', $target)->where('stage', $stage)->exists()) {
                throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
            }
            $preflight = app(TestFlightPreflight::class)->check($game->fresh(), $commit);
        }

        try {
            return DB::transaction(function () use ($game, $user, $target, $stage, $commit, $preflight) {
                // Spärr 4: spelets rad låses, så två samtidiga befordringar köas efter varandra.
                // (SQLite i test ignorerar låset; det unika indexet är då det som håller.)
                $locked = Game::query()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

                $production = ReleaseCatalog::isProduction($target, $stage);
                if ($commit === null && ! $production) { // spärr 8: standardvalet gäller bara teststeg
                    $commit = DemoBuild::latestFor($locked)?->commit_sha;
                }

                if (($why = ReleaseGate::check($locked, $target, $stage, $commit)) !== null) {
                    throw new ReleaseRefused($why);
                }

                if (Release::active()->where('game_id', $locked->id)->where('target', $target)->where('stage', $stage)->exists()) {
                    throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
                }

                // Spärr 3, beräknat i samma transaktion som skrivningen.
                $first = $production && ! Release::where('game_id', $locked->id)->where('target', $target)
                    ->where('stage', $stage)->where('status', Release::SUCCEEDED)->exists();

                $build = [];
                if ($preflight !== null) {
                    // R3 igen under låset: ett annat släpp kan ha lyckats sedan förkontrollen.
                    $last = TestFlightPreflight::lastReleased($locked);
                    if ($last !== null && ! $preflight->version->isHigherThan($last)) {
                        throw new ReleaseRefused("Version {$preflight->version} is no longer higher than the latest TestFlight release {$last}; raise version in package.json and try again.");
                    }
                    // R5: nästa nummer, i samma transaktion som släppet. Luckor är tillåtna, upprepning aldrig.
                    $next = (int) $locked->ios_build_number + 1;
                    $locked->forceFill(['ios_build_number' => $next])->save();
                    $build = ['marketing_version' => (string) $preflight->version, 'build_number' => $next, 'build_guide' => $preflight->buildGuide];
                }

                return Release::create([
                    'game_id' => $locked->id,
                    'target' => $target,
                    'stage' => $stage,
                    'commit_sha' => $commit,
                    'status' => $first ? Release::AWAITING_APPROVAL : Release::REQUESTED,
                    'requested_by' => $user->id,
                ] + $build);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
        }
    }
```

Importera `App\Releases\TestFlight\TestFlightPreflight`.

Run: `cd $WT && php artisan test --filter=TestFlightPromoteTest`
Expected: PASS.

- [ ] **Steg 5: Befintliga tester som befordrade till TestFlight utan att det gällde TestFlight.** `testflight` byggs nu, och en befordran läser spelrepot. Tester vars avsikt är "ett teststeg" i allmänhet flyttas till `play_internal`, som fortfarande inte byggs:

```bash
cd $WT && grep -n "testflight" tests/Feature/Releases/ReleaseServiceTest.php tests/Feature/Mcp/PromoteReleaseToolTest.php tests/Feature/Releases/ReleaseNotifierTest.php
```

För varje träff där testet befordrar via `promote(…)`/`promote_release` eller förutsätter att `testflight` inte byggs: byt målet `app_store`→`play_store` och steget `testflight`→`play_internal`, lägg till `play_store` i spelets mål i testets `releasableGame(...)`, och byt en förväntan på `'etapp 2'` till `'etapp 3'`. Tester som bara skapar rader med `$this->release(...)` (ingen befordran) och tester i `ReleaseGateTest`, `ReleaseModelTest` och `ReleaseStatusTest` lämnas; `ReleaseGate::check` läser inte spelrepot. Skriv en rad i varje ändrat tests kommentar: `// play_internal: testflight byggs sedan etapp 2 och läser spelrepot (TestFlightPromoteTest).`

Run: `cd $WT && php artisan test --filter='ReleaseServiceTest|PromoteReleaseToolTest|ReleaseNotifierTest|ReleaseGateTest|ReleaseStatusTest'`
Expected: PASS.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Releases tests
git commit -m "promote_release för TestFlight: version och byggguide vid commiten, versionsspärren, taggkollen och byggnumret"
```

---
### Task 7: `AdvanceRelease` — dispatch, GitHub-körningen, Apples bearbetning och gruppen

**Files:**
- Create: `app/Releases/TestFlight/ReleaseFailed.php`, `app/Releases/TestFlight/TestFlightBuilder.php`, `app/Jobs/AdvanceRelease.php`, `app/Console/Commands/AdvanceReleases.php`
- Modify: `app/Releases/ReleaseService.php` (köar jobbet), `routes/console.php`, `app/Console/Commands/CheckProvisioning.php`
- Create: `tests/Feature/Releases/TestFlightBuilderTest.php`, `tests/Feature/Releases/AdvanceReleaseTest.php`
- Modify: `tests/Feature/Releases/TestFlightPromoteTest.php` (`Queue::fake()`), `tests/Feature/Console/CheckReleasesTest.php`

**Interfaces:**
- Consumes: `GitHubAppClient` (Task 2), `AppStoreConnectApi` (Task 1), `ReleaseService::assertCanStart` (1a), `Game::appStoreId` (Task 3).
- Produces: `TestFlightBuilder::handles(Release): bool`, `advance(Release): ?int` (sekunder till nästa kontroll, `null` = klart eller fällt), `fail(Release, Throwable): void`. `AdvanceRelease` (jobb, unikt per släpp). `computercat:advance-releases` (var femte minut). `releases.external` för TestFlight: `tag`, `dispatch_requested_at`, `github_run_id`, `github_run_url`, `dispatched_at`, `run_completed_at`, `workflow_sha`, `apple_build_id`, `succeeded_at`.

- [ ] **Steg 1: Hotmodell.** Invokera `security-first-mindset` för jobbet: vad som får skrivas i GitHub (en tagg per släpp i spelets repo, en dispatch per släpp i släpprepot, cancel av egen körning) och i Apple (länka bygget till gruppen); att indata till flödet bara kommer från Computercats egna kolumner (aldrig från spelrepot); att `last_error` och Slack bara får redigerade texter; att en dispatch aldrig upprepas. Skriv slutsatsen som kommentar överst i `TestFlightBuilder` (med i koden nedan).

- [ ] **Steg 2: Testerna för byggaren (fallerar).** `tests/Feature/Releases/TestFlightBuilderTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Releases\TestFlight\TestFlightBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AppStoreFakes as A;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class TestFlightBuilderTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    private const RELEASE_REPO = F::GITHUB.'/repos/HumblebragAB/computercat-release';

    private const GAME_REPO = F::GITHUB.'/repos/HumblebragAB/kattsvep';

    private Game $game;

    private Release $release;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        A::configure();
        $this->startHttpTransactionGuard();
        $this->game = $this->testFlightGame();
        $this->demoBuild($this->game, $this->sha('a'));
        $this->game->forceFill(['ios_build_number' => 5])->save();
        $this->release = $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::REQUESTED,
            'commit_sha' => $this->sha('a'), 'marketing_version' => '1.2.0', 'build_number' => 5, 'build_guide' => 'v3']);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    /** Standardsvar; $routes går före (första matchande mönster vinner). */
    private function fake(array $routes = []): void
    {
        Http::fake($routes + [
            F::SLACK => Http::response('ok'),
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile())),
            self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(['message' => 'Not Found'], 404),
            self::GAME_REPO.'/git/refs' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a')), 201),
            self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches' => Http::response(F::githubDispatch(4711)),
        ]);
    }

    private function advance(): ?int
    {
        $release = Release::with('game')->find($this->release->id);

        return app(TestFlightBuilder::class)->advance($release);
    }

    private function fresh(): Release
    {
        return $this->release->fresh();
    }

    private function building(array $external = []): void
    {
        $this->release->update(['status' => Release::BUILDING, 'external' => $external + ['tag' => 'v1.2.0', 'github_run_id' => 4711, 'dispatched_at' => now()->toIso8601String(), 'dispatch_requested_at' => now()->toIso8601String()]]);
    }

    private function processing(array $external = []): void
    {
        $this->release->update(['status' => Release::PROCESSING, 'external' => $external + ['github_run_id' => 4711, 'run_completed_at' => now()->toIso8601String()]]);
    }

    public function test_start_tags_dispatches_with_computercats_values_and_moves_to_building(): void
    {
        $this->fake();

        $this->assertSame(30, $this->advance());

        $release = $this->fresh();
        $this->assertSame(Release::BUILDING, $release->status);
        $this->assertSame([4711, 'v1.2.0'], [$release->external['github_run_id'], $release->external['tag']]);
        Http::assertSent(fn (Request $r) => $r->url() === self::GAME_REPO.'/git/refs' && $r->data() === ['ref' => 'refs/tags/v1.2.0', 'sha' => $this->sha('a')]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches') && $r->data() === ['ref' => 'main', 'inputs' => [
            'game' => 'kattsvep', 'commit_sha' => $this->sha('a'), 'release_uuid' => $this->release->uuid, 'build_number' => '5',
            'marketing_version' => '1.2.0', 'bundle_id' => 'se.computercat.kattsvep', 'profile_id' => A::PROFILE, 'build_guide' => 'v3',
        ], 'return_run_details' => true]);
        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertLessThan(array_search(self::RELEASE_REPO.'/actions/workflows/release-ios.yml/dispatches', $urls, true), array_search(self::GAME_REPO.'/git/refs', $urls, true), 'Taggen sätts före dispatch.');
    }

    public function test_existing_tag_on_the_same_commit_is_reused(): void
    {
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('a')))]);

        $this->advance();

        $this->assertSame(Release::BUILDING, $this->fresh()->status);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::GAME_REPO.'/git/refs');
    }

    public function test_tag_on_another_commit_fails_without_dispatch(): void
    {
        $this->fake([self::GAME_REPO.'/git/ref/tags/v1.2.0' => Http::response(F::githubTagRef('v1.2.0', $this->sha('f')))]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('Tag v1.2.0 now points at another commit', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK);
    }

    public function test_inactive_or_expiring_profile_fails_before_tag_and_dispatch(): void // R13
    {
        $this->fake([A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile(expires: now()->addHours(3)->toIso8601String())))]);

        $this->advance();

        $this->assertStringContainsString('profile is not active or expires within a day', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/git/'));
    }

    public function test_switch_off_before_start_fails_with_the_reason(): void // spärr 9 vid byggstart
    {
        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->fake();

        $this->advance();

        $this->assertSame(Release::FAILED, $this->fresh()->status);
        $this->assertStringContainsString('switched off', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'github.com/repos'));
    }

    public function test_release_from_1a_without_version_fails_clearly(): void // R14
    {
        $this->release->update(['marketing_version' => null, 'build_number' => null, 'build_guide' => null]);
        $this->fake();

        $this->advance();

        $this->assertStringContainsString('registered before TestFlight builds existed', $this->fresh()->last_error);
    }

    public function test_lost_dispatch_response_is_recovered_by_run_name(): void // Review Focus 2
    {
        $this->release->update(['external' => ['tag' => 'v1.2.0', 'dispatch_requested_at' => now()->subMinute()->toIso8601String()]]);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 1, 'workflow_runs' => [F::githubRun(4712, 'in_progress', null, $this->release->uuid)]])]);

        $this->advance();

        $this->assertSame([Release::BUILDING, 4712], [$this->fresh()->status, $this->fresh()->external['github_run_id']]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_start_never_dispatches_twice(): void
    {
        $this->release->update(['external' => ['tag' => 'v1.2.0', 'dispatch_requested_at' => now()->toIso8601String()]]);
        $this->fake([self::RELEASE_REPO.'/actions/workflows/release-ios.yml/runs?*' => Http::response(['total_count' => 0, 'workflow_runs' => []])]);

        $this->assertSame(15, $this->advance());
        $this->travel(4)->minutes();
        $this->assertNull($this->advance());

        $this->assertStringContainsString('no run was found', $this->fresh()->last_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/dispatches'));
    }

    public function test_successful_run_moves_to_processing_and_records_the_workflow_version(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'success', $this->release->uuid))]);

        $this->assertSame(120, $this->advance());

        $this->assertSame(Release::PROCESSING, $this->fresh()->status);
        $this->assertSame(str_repeat('9', 40), $this->fresh()->external['workflow_sha']);
    }

    public function test_running_run_is_polled_again(): void
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'in_progress', null, $this->release->uuid))]);

        $this->assertSame(30, $this->advance());
        $this->assertSame(Release::BUILDING, $this->fresh()->status);
    }

    public function test_failed_run_names_the_job_and_step(): void
    {
        $this->building();
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs(['build', 'Kontrollera version'])),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'failure', $this->release->uuid)),
        ]);

        $this->assertNull($this->advance());

        $this->assertSame('The GitHub run ended with failure in job build, step "Kontrollera version".', $this->fresh()->last_error);
    }

    public function test_run_cancelled_outside_computercat_fails_with_its_conclusion(): void // Review Focus 3
    {
        $this->building();
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/jobs*' => Http::response(F::githubRunJobs()),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'completed', 'cancelled', $this->release->uuid)),
        ]);

        $this->advance();

        $this->assertStringContainsString('ended with cancelled', $this->fresh()->last_error);
    }

    public function test_run_timeout_cancels_and_alerts(): void // R11
    {
        $this->building(['dispatched_at' => now()->subMinutes(61)->toIso8601String()]);
        $this->fake([
            self::RELEASE_REPO.'/actions/runs/4711/cancel' => Http::response('', 202),
            self::RELEASE_REPO.'/actions/runs/4711' => Http::response(F::githubRun(4711, 'queued', null, $this->release->uuid)),
        ]);

        $this->assertNull($this->advance());

        $this->assertStringContainsString('did not finish within 60 minutes', $this->fresh()->last_error);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/runs/4711/cancel'));
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK && str_contains($r['text'], 'kattsvep'));
    }

    public function test_valid_build_is_added_to_the_group_and_the_release_succeeds(): void
    {
        $this->processing();
        $this->fake([
            A::API.'/v1/builds?*' => function (Request $r) {
                return str_contains(urldecode($r->url()), 'filter[betaGroups]')
                    ? Http::response(A::many())
                    : Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')));
            },
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/builds' => Http::response('', 204),
        ]);

        $this->assertNull($this->advance());

        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
        $this->assertSame('build-uuid-1', $this->fresh()->external['apple_build_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/builds') && $r->data() === ['data' => [['type' => 'builds', 'id' => 'build-uuid-1']]]);
    }

    public function test_build_already_in_the_group_is_not_added_again(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-uuid-1', 5, 'VALID')))]);

        $this->advance();

        $this->assertSame(Release::SUCCEEDED, $this->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/relationships/builds'));
    }

    public function test_build_not_yet_visible_or_processing_is_polled_again(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::sequence()->push(A::many())->push(A::many(A::build('b', 5, 'PROCESSING')))]);

        $this->assertSame(120, $this->advance());
        $this->assertSame(120, $this->advance());
        $this->assertSame(Release::PROCESSING, $this->fresh()->status);
    }

    public function test_invalid_build_fails(): void
    {
        $this->processing();
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('b', 5, 'INVALID')))]);

        $this->advance();

        $this->assertSame('Apple marked build 5 (version 1.2.0) as INVALID.', $this->fresh()->last_error);
    }

    public function test_apple_timeout_fails_and_alerts(): void // R11
    {
        $this->processing(['run_completed_at' => now()->subMinutes(91)->toIso8601String()]);
        $this->fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('b', 5, 'PROCESSING')))]);

        $this->advance();

        $this->assertStringContainsString('within 90 minutes', $this->fresh()->last_error);
        Http::assertSent(fn (Request $r) => $r->url() === F::SLACK);
    }

    public function test_failure_text_is_redacted(): void // Review Focus 4
    {
        $this->building();
        $this->fake([self::RELEASE_REPO.'/actions/runs/4711' => Http::response(['message' => 'bad token ghs_abcdefghijklmnopqrstuvwxyz0123456789'], 500)]);

        $this->advance();

        $this->assertStringNotContainsString('ghs_abcdefghijklmnopqrstuvwxyz0123456789', $this->fresh()->last_error);
        $this->assertStringContainsString('returned 500', $this->fresh()->last_error);
    }

    public function test_internal_error_shows_only_a_reference(): void
    {
        $this->building(['github_run_id' => 'inte-ett-tal']);
        $this->fake();

        $this->advance();

        $this->assertMatchesRegularExpression('/^Internal error \(ref [0-9a-f-]{36}\)\.$/', $this->fresh()->last_error);
    }
}
```

Run: `cd $WT && php artisan test --filter=TestFlightBuilderTest`
Expected: FAIL.

- [ ] **Steg 3: `ReleaseFailed` och `TestFlightBuilder`.**

```php
<?php

namespace App\Releases\TestFlight;

use App\Provisioning\SafeMessage;

// Orsaken till att ett TestFlight-släpp fälls. Meddelandet skrivs av Computercat och får visas.
final class ReleaseFailed extends \RuntimeException implements SafeMessage {}
```

```php
<?php

namespace App\Releases\TestFlight;

use App\Models\Release;
use App\Provisioning\Clients\GitHubAppClient;
use App\Provisioning\SafeMessage;
use App\Releases\AppStore\AppStoreConnectApi;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Support\SlackAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Ett TestFlight-släpp från requested till succeeded eller failed (spec "Statusläsning", plan R11).
//
// Hotmodell (Task 7): i GitHub skrivs bara taggen v<version> i spelets repo (aldrig flyttad), en
// dispatch per släpp i släpprepot och, vid tidsgräns, cancel av den egna körningen. I Apple länkas
// bara bygget till gruppen. Flödets indata kommer bara från Computercats egna kolumner. Markören
// dispatch_requested_at sparas före dispatch, så att en krasch leder till uppslag via run-name och
// aldrig till en andra körning. last_error och Slack får bara SafeMessage-texter (redigerade av
// Release-modellen och SlackAlert); allt annat blir "Internal error (ref …)". Inga Actions-loggar läses.
final class TestFlightBuilder
{
    public function __construct(
        private GitHubAppClient $github,
        private AppStoreConnectApi $asc,
        private ReleaseService $releases,
        private SlackAlert $slack,
    ) {}

    public function handles(Release $release): bool
    {
        return $release->target === 'app_store' && $release->stage === 'testflight'
            && in_array($release->status, [Release::REQUESTED, Release::BUILDING, Release::PROCESSING], true);
    }

    /** @return int|null sekunder till nästa kontroll; null när släppet är klart eller fällt */
    public function advance(Release $release): ?int
    {
        try {
            return match ($release->status) {
                Release::REQUESTED => $this->start($release),
                Release::BUILDING => $this->followRun($release),
                Release::PROCESSING => $this->followApple($release),
            };
        } catch (Throwable $e) {
            $this->fail($release, $e);

            return null;
        }
    }

    public function fail(Release $release, Throwable $e): void
    {
        $ref = (string) Str::uuid();
        $message = $e instanceof SafeMessage || $e instanceof ReleaseRefused ? $e->getMessage() : "Internal error (ref {$ref}).";
        Log::error('TestFlight-släpp misslyckades', ['ref' => $ref, 'release' => $release->uuid, 'exception' => $e]);

        $fresh = Release::with('game')->whereKey($release->getKey())->whereIn('status', [Release::REQUESTED, Release::BUILDING, Release::PROCESSING])->first();
        if ($fresh === null) {
            return;
        }
        $fresh->update(['status' => Release::FAILED, 'last_error' => $message]);

        $this->slack->send(
            "release-failed:{$fresh->uuid}",
            'TestFlight-släppet av `'.SlackAlert::escape($fresh->game->slug).'` version '.SlackAlert::escape((string) $fresh->marketing_version)
                .' (bygge '.SlackAlert::escape((string) $fresh->build_number).') föll: '.SlackAlert::escape((string) $fresh->last_error)." (släpp {$fresh->uuid}, ref {$ref}).",
        );
    }

    private function start(Release $release): int
    {
        $external = $release->external ?? [];
        if (isset($external['dispatch_requested_at'])) {
            return $this->recoverDispatch($release);
        }

        // R14: rader från 1a saknar version och byggnummer; de byggs aldrig med gissade värden.
        if ($release->marketing_version === null || $release->build_number === null || $release->build_guide === null) {
            throw new ReleaseFailed('This release was registered before TestFlight builds existed; request a new one with promote_release.');
        }

        // Spärr 7 och 9: samma kontroll som vid begäran, nu mot dagens läge.
        $this->releases->assertCanStart($release);

        $game = $release->game;
        $profileId = $game->appStoreId('profile_id') ?? throw new ReleaseFailed('The game has no App Store profile; ask Erik to run the setup steps in Filament.');
        $profile = $this->asc->profile($profileId);
        if ($profile === null || $profile['state'] !== 'ACTIVE' || Carbon::parse($profile['expires_at'])->lt(now()->addDay())) {
            throw new ReleaseFailed('The App Store provisioning profile is not active or expires within a day; Erik must renew it before the next TestFlight build.');
        }

        $tag = 'v'.$release->marketing_version;
        $existing = $this->github->getTag($game->slug, $tag);
        if ($existing === null) {
            $this->github->createTag($game->slug, $tag, $release->commit_sha);
        } elseif ($existing['type'] !== 'commit' || $existing['sha'] !== $release->commit_sha) {
            throw new ReleaseFailed("Tag {$tag} now points at another commit; raise the version in package.json and request a new release.");
        }

        $release->update(['external' => array_merge($external, ['tag' => $tag, 'dispatch_requested_at' => now()->toIso8601String()])]);

        $run = $this->github->dispatchWorkflow(
            (string) config('releases.ios_workflow.repo'),
            (string) config('releases.ios_workflow.workflow'),
            (string) config('releases.ios_workflow.ref'),
            $this->inputs($release),
        );
        $this->building($release, $run['run_id'], $run['html_url']);

        return (int) config('releases.poll_seconds.github');
    }

    private function recoverDispatch(Release $release): int
    {
        $since = Carbon::parse($release->external['dispatch_requested_at']);
        $run = $this->github->findWorkflowRun(
            (string) config('releases.ios_workflow.repo'),
            (string) config('releases.ios_workflow.workflow'),
            $release->uuid,
            $since->copy()->subMinute(),
        );
        if ($run !== null) {
            $this->building($release, $run['id'], $run['html_url']);

            return (int) config('releases.poll_seconds.github');
        }

        $window = (int) config('releases.timeouts.dispatch_lookup_minutes');
        if ($since->lt(now()->subMinutes($window))) {
            throw new ReleaseFailed("The release workflow was requested but no run was found within {$window} minutes; check GitHub Actions in computercat-release and request a new release.");
        }

        return 15;
    }

    private function building(Release $release, int $runId, string $url): void
    {
        $release->update(['status' => Release::BUILDING, 'external' => array_merge($release->external ?? [], [
            'github_run_id' => $runId, 'github_run_url' => $url, 'dispatched_at' => now()->toIso8601String(),
        ])]);
        Log::notice('TestFlight-bygge startat', ['release' => $release->uuid, 'run' => $runId, 'commit' => $release->commit_sha, 'version' => $release->marketing_version, 'build' => $release->build_number]);
    }

    /** @return array<string, string> */
    private function inputs(Release $release): array
    {
        $game = $release->game;

        return [
            'game' => $game->slug,
            'commit_sha' => $release->commit_sha,
            'release_uuid' => $release->uuid,
            'build_number' => (string) $release->build_number,
            'marketing_version' => (string) $release->marketing_version,
            'bundle_id' => (string) $game->bundle_id,
            'profile_id' => (string) $game->appStoreId('profile_id'),
            'build_guide' => (string) $release->build_guide,
        ];
    }

    private function followRun(Release $release): ?int
    {
        $external = $release->external ?? [];
        $runId = $external['github_run_id'] ?? null;
        if (! is_int($runId)) {
            throw new \LogicException('BUILDING-släpp utan github_run_id.');
        }
        $repo = (string) config('releases.ios_workflow.repo');
        $run = $this->github->workflowRun($repo, $runId);

        if ($run['status'] !== 'completed') {
            $limit = (int) config('releases.timeouts.github_run_minutes');
            if (Carbon::parse($external['dispatched_at'])->lt(now()->subMinutes($limit))) {
                try {
                    $this->github->cancelWorkflowRun($repo, $runId);
                } catch (Throwable $e) {
                    Log::warning('Kunde inte avbryta GitHub-körningen', ['release' => $release->uuid, 'run' => $runId, 'error' => get_class($e)]);
                }
                throw new ReleaseFailed("The GitHub run did not finish within {$limit} minutes (status {$run['status']}) and was cancelled.");
            }

            return (int) config('releases.poll_seconds.github');
        }

        if ($run['conclusion'] !== 'success') {
            $failed = collect($this->github->workflowRunJobs($repo, $runId))
                ->first(fn (array $job) => in_array($job['conclusion'], ['failure', 'timed_out', 'cancelled'], true));
            $where = $failed === null ? '' : " in job {$failed['name']}".($failed['failed_step'] === null ? '' : ", step \"{$failed['failed_step']}\"");

            throw new ReleaseFailed('The GitHub run ended with '.($run['conclusion'] ?? 'no conclusion')."{$where}.");
        }

        $release->update(['status' => Release::PROCESSING, 'external' => array_merge($external, [
            'run_completed_at' => now()->toIso8601String(), 'workflow_sha' => $run['head_sha'],
        ])]);

        return (int) config('releases.poll_seconds.apple');
    }

    private function followApple(Release $release): ?int
    {
        $external = $release->external ?? [];
        $game = $release->game;
        $appId = $game->appStoreId('app_id') ?? throw new ReleaseFailed('The game has no App Store app id; ask Erik to check the setup steps.');
        $groupId = $game->appStoreId('beta_group_id') ?? throw new ReleaseFailed('The game has no TestFlight group; ask Erik to run the setup steps in Filament.');

        $build = $this->asc->findBuild($appId, (string) $release->marketing_version, (int) $release->build_number);
        if ($build === null || $build['state'] === 'PROCESSING') {
            $limit = (int) config('releases.timeouts.apple_processing_minutes');
            if (Carbon::parse($external['run_completed_at'])->lt(now()->subMinutes($limit))) {
                throw new ReleaseFailed("Apple did not finish processing build {$release->build_number} (version {$release->marketing_version}) within {$limit} minutes.");
            }

            return (int) config('releases.poll_seconds.apple');
        }

        if ($build['state'] !== 'VALID') {
            throw new ReleaseFailed("Apple marked build {$release->build_number} (version {$release->marketing_version}) as {$build['state']}.");
        }

        // R7: gruppen har inte tillgång till alla byggen; bygget läggs till uttryckligen.
        if (! $this->asc->buildIsInGroup($groupId, $build['id'])) {
            $this->asc->addBuildToGroup($groupId, $build['id']);
        }

        $release->update(['status' => Release::SUCCEEDED, 'external' => array_merge($external, [
            'apple_build_id' => $build['id'], 'succeeded_at' => now()->toIso8601String(),
        ])]);
        Log::notice('TestFlight-bygge klart', ['release' => $release->uuid, 'game' => $game->slug, 'version' => $release->marketing_version, 'build' => $release->build_number]);

        return null;
    }
}
```

`external['github_run_id']` sparas som heltal (`array`-cast bevarar typen); testet `test_internal_error_shows_only_a_reference` visar att ett skadat värde blir en referens, inte ett läckt fel.

Run: `cd $WT && php artisan test --filter=TestFlightBuilderTest`
Expected: PASS.

- [ ] **Steg 4: Jobbet, köningen och svepet (tester först).** `tests/Feature/Releases/AdvanceReleaseTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Jobs\AdvanceRelease;
use App\Models\Release;
use App\Releases\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes as F;
use Tests\TestCase;

class AdvanceReleaseTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private function testflightRelease(string $status, array $attrs = []): Release
    {
        $game = $this->testFlightGame();

        return $this->release($game, $attrs + ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => '1.0.0', 'build_number' => 1, 'build_guide' => 'v3']);
    }

    public function test_promote_queues_the_job_after_commit(): void
    {
        Queue::fake();
        F::configure();
        $this->iosGuides('v3');
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('a'));
        $base = F::GITHUB.'/repos/HumblebragAB/kattsvep';
        Http::fake([
            F::GITHUB.'/app/installations/4242/access_tokens' => Http::response(F::githubToken(), 201),
            $base.'/contents/package.json?ref=*' => Http::response(F::githubContent('{"version":"1.0.0"}')),
            $base.'/contents/CLAUDE.md?ref=*' => Http::response(F::githubContent("Byggguide: **v3**.\n")),
            $base.'/git/ref/tags/*' => Http::response([], 404),
        ]);

        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'app_store', 'testflight', null);

        Queue::assertPushed(AdvanceRelease::class, fn (AdvanceRelease $job) => $job->releaseId === $release->id);
    }

    public function test_play_internal_queues_nothing(): void
    {
        Queue::fake();
        $game = $this->releasableGame('kattsvep', ['play_store']);
        $this->demoBuild($game);

        app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'play_store', 'play_internal', null);

        Queue::assertNothingPushed();
    }

    public function test_failed_handler_marks_the_release_failed(): void // Review Focus 3
    {
        Http::fake([F::SLACK => Http::response('ok')]);
        F::configure();
        $release = $this->testflightRelease(Release::BUILDING);

        (new AdvanceRelease($release->id))->failed(new \RuntimeException('timeout'));

        $this->assertSame(Release::FAILED, $release->fresh()->status);
        $this->assertStringContainsString('did not finish in time', $release->fresh()->last_error);
    }

    public function test_finished_release_is_left_alone(): void
    {
        Http::fake();
        $release = $this->testflightRelease(Release::SUCCEEDED);

        app()->call([new AdvanceRelease($release->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(Release::SUCCEEDED, $release->fresh()->status);
    }

    public function test_sweep_requeues_active_testflight_releases(): void // R11
    {
        Queue::fake();
        $active = $this->testflightRelease(Release::PROCESSING);
        $this->release($active->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::FAILED, 'build_number' => 2]);

        $this->artisan('computercat:advance-releases')->assertSuccessful();

        Queue::assertPushed(AdvanceRelease::class, 1);
        Queue::assertPushed(AdvanceRelease::class, fn (AdvanceRelease $job) => $job->releaseId === $active->id);
    }

    public function test_job_is_unique_per_release(): void
    {
        $job = new AdvanceRelease(42);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame('release-42', $job->uniqueId());
    }
}
```

I `TestFlightPromoteTest::setUp()`, lägg till `\Illuminate\Support\Facades\Queue::fake();` först (annars kör sync-kön jobbet mot ofejkade adresser).

`app/Jobs/AdvanceRelease.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Release;
use App\Releases\TestFlight\ReleaseFailed;
use App\Releases\TestFlight\TestFlightBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

// Driver ett TestFlight-släpp ett steg i taget och släpper sig självt (release) tills nästa kontroll,
// som AdvanceProvisioningRun. Workern körs med --tries=1: $tries = 0 tar bort taket och retryUntil()
// sätter gränsen i tid (båda tidsgränserna plus marginal). TestFlightBuilder fångar allt och fäller
// släppet själv; failed() täcker det som dör i kön. Unikt per släpp, så att svepet aldrig ger två jobb.
class AdvanceRelease implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    // Under databasköns retry_after (90 s).
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $backoff = 30;

    public int $uniqueFor = 4 * 3600;

    public function __construct(public int $releaseId) {}

    public function uniqueId(): string
    {
        return 'release-'.$this->releaseId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes((int) config('releases.timeouts.github_run_minutes') + (int) config('releases.timeouts.apple_processing_minutes') + 30);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('release-'.$this->releaseId))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(TestFlightBuilder $builder): void
    {
        $release = Release::with('game')->find($this->releaseId);
        if ($release === null || ! $builder->handles($release)) {
            return;
        }

        $next = $builder->advance($release);
        if ($next !== null) {
            $this->release($next);
        }
    }

    public function failed(?Throwable $e): void
    {
        $release = Release::with('game')->find($this->releaseId);
        if ($release === null || ! app(TestFlightBuilder::class)->handles($release)) {
            return;
        }

        app(TestFlightBuilder::class)->fail($release, new ReleaseFailed('The release job did not finish in time (queue timeout or retry window).'));
    }
}
```

I `ReleaseService::promote`: byt `return DB::transaction(...)` mot `$release = DB::transaction(...)`, och efter `try`/`catch`:

```php
        // Efter commit: jobbet ska se raden, och inget HTTP sker i transaktionen.
        if ($preflight !== null && $release->status === Release::REQUESTED) {
            AdvanceRelease::dispatch($release->id);
        }

        return $release;
```

(importera `App\Jobs\AdvanceRelease`).

`app/Console/Commands/AdvanceReleases.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceRelease;
use App\Models\Release;
use App\Releases\ReleaseCatalog;
use Illuminate\Console\Command;

// Reserv (plan R11): köar AdvanceRelease för varje pågående släpp i ett steg som Computercat bygger.
// Jobbet är unikt per släpp, så ett jobb som redan väntar i kön får inget syskon.
class AdvanceReleases extends Command
{
    protected $signature = 'computercat:advance-releases';

    protected $description = 'Köar AdvanceRelease för pågående TestFlight-släpp (om ett jobb har tappats).';

    public function handle(): int
    {
        $ids = Release::whereIn('stage', ReleaseCatalog::BUILT_BY_COMPUTERCAT)
            ->whereIn('status', [Release::REQUESTED, Release::BUILDING, Release::PROCESSING])
            ->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            AdvanceRelease::dispatch($id);
        }
        $this->info('Köade '.count($ids).' släpp.');

        return self::SUCCESS;
    }
}
```

I `routes/console.php`, efter raden för `computercat:poll-demo-builds`:

```php
// Släppflödet etapp 2: reserv för TestFlight-jobben (plan R11).
Schedule::command('computercat:advance-releases')->everyFiveMinutes()->withoutOverlapping(10);
```

Run: `cd $WT && php artisan test --filter='AdvanceReleaseTest|TestFlightPromoteTest|TestFlightBuilderTest'`
Expected: PASS.

- [ ] **Steg 5: Nattkontrollen.** Test först, i `tests/Feature/Console/CheckReleasesTest.php` (följ klassens befintliga sätt att köra kommandot och läsa avvikelserna):

```php
    public function test_testflight_release_stuck_in_requested_is_reported(): void
    {
        $game = $this->testFlightGame();
        $release = $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::REQUESTED, 'build_number' => 1, 'marketing_version' => '1.0.0', 'build_guide' => 'v3']);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(31)]);

        $output = $this->runCheck(); // klassens befintliga hjälpare som kör computercat:check-provisioning --dry-run

        $this->assertStringContainsString("Släppet {$release->uuid}", $output);
        $this->assertStringContainsString('har inte startat', $output);
    }

    public function test_setup_step_with_an_error_is_reported(): void
    {
        $game = $this->testFlightGame();
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_profile')->update(['status' => 'open', 'last_error' => 'App Store Connect POST /v1/profiles returned 409']);

        $this->assertStringContainsString('appstore_profile', $this->runCheck());
    }
```

Heter klassens hjälpare något annat: använd den; finns ingen, kör `$this->artisan('computercat:check-provisioning', ['--dry-run' => true])` och fånga utdata som klassens andra tester gör. I `CheckProvisioning::checkReleases`, inne i guarden `'fastnade byggen'`, efter loopen över `$stuck`:

```php
            $startCutoff = now()->subMinutes((int) config('releases.start_stuck_minutes'));
            $notStarted = Release::with('game')->where('status', Release::REQUESTED)
                ->whereIn('stage', ReleaseCatalog::BUILT_BY_COMPUTERCAT)->where('updated_at', '<', $startCutoff)->orderBy('id')->get();
            foreach ($notStarted as $release) {
                $this->deviations["release:{$release->uuid}:not-started"] = "Släppet {$release->uuid} ({$release->game->slug} {$release->target}/{$release->stage}) har inte startat sedan {$release->updated_at}; kontrollera kön (AdvanceRelease).";
            }
```

och en ny guard efter `'engångssteg'`:

```php
        $this->guardReleases('fel i engångssteg', function () {
            foreach (ReleaseSetupStep::with('game')->where('status', ReleaseSetupStep::OPEN)->whereNotNull('last_error')->orderBy('id')->get() as $step) {
                $this->deviations["setup-error:{$step->game->slug}:{$step->key}"] = "Engångssteget {$step->key} för {$step->game->slug} föll senast: {$step->last_error}";
            }
        });
```

(importera `App\Releases\ReleaseCatalog`). Run: `cd $WT && php artisan test --filter=CheckReleasesTest`
Expected: PASS.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app routes tests
git commit -m "AdvanceRelease: tagg, dispatch, GitHub-körningen, Apples bearbetning och TestFlight-gruppen, med tidsgränser, svep och larm"
```

---
### Task 8: `next`-texterna, `get_release_status` och verktygsbeskrivningarna

**Files:**
- Modify: `app/Releases/ReleaseNext.php`, `app/Releases/ReleaseStatus.php`
- Modify: `app/Mcp/Tools/PromoteReleaseTool.php`, `app/Mcp/Tools/GetReleaseStatusTool.php`
- Modify: `resources/faktabank/process/process-slappflode.md` (bara tillståndsraderna, så att synktestet håller; resten i Task 9)
- Modify: `tests/Feature/Releases/ReleaseStatusTest.php`, `tests/Feature/Mcp/PromoteReleaseToolTest.php`, `tests/Feature/Mcp/GetReleaseStatusToolTest.php`

**Interfaces:**
- Produces: `ReleaseNext::TESTFLIGHT_FLOW` (den enda källan för flödestexten); nya tillstånd `ready_for_testflight`, `starting`, `building_testflight`, `succeeded_testflight`, `failed_testflight`; `{flow}` fylls alltid i av `ReleaseNext::for`. `get_release_status` per mål `app_store`: `ios: {bundle_id, last_released_version, last_build_number}`; varje släpp visar `marketing_version` och `build_number`. `promote_release` svarar med `marketing_version` och `build_number`.

- [ ] **Steg 1: Testerna (fallerar).** Lägg till i `ReleaseStatusTest`:

```php
    private function tf(Game $game, string $status, array $attrs = []): Release
    {
        return $this->release($game, $attrs + ['target' => 'app_store', 'stage' => 'testflight', 'status' => $status,
            'marketing_version' => '1.2.0', 'build_number' => 5, 'build_guide' => 'v3']);
    }

    public function test_app_store_ready_state_gives_the_testflight_flow(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('b'));
        $this->tf($game, Release::SUCCEEDED, ['commit_sha' => $this->sha('c'), 'marketing_version' => '1.1.0', 'build_number' => 4]);

        $target = $this->statusOf($game)['targets']['app_store'];

        $this->assertSame('ready_for_testflight', $target['state']);
        $this->assertStringContainsString('Senast släppta version till TestFlight: 1.1.0.', $target['next']);
        $this->assertStringContainsString(ReleaseNext::TESTFLIGHT_FLOW, $target['next']);
        $this->assertSame(['bundle_id' => 'se.computercat.kattsvep', 'last_released_version' => '1.1.0', 'last_build_number' => 0], $target['ios']);
    }

    public function test_first_testflight_release_says_none_is_released(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game);

        $this->assertStringContainsString('Senast släppta version till TestFlight: ingen.', $this->statusOf($game)['targets']['app_store']['next']);
    }

    public function test_testflight_phases_have_their_own_texts(): void
    {
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]); // App Store stängt, som i drift
        $game = $this->testFlightGame();
        $this->demoBuild($game);
        $release = $this->tf($game, Release::REQUESTED);

        foreach ([
            Release::REQUESTED => ['starting', 'startar'],
            Release::BUILDING => ['building_testflight', 'GitHub Actions bygger'],
            Release::PROCESSING => ['building_testflight', 'Apple bearbetar'],
            Release::SUCCEEDED => ['succeeded_testflight', 'finns i TestFlight för gruppen ComputerCat intern'],
        ] as $status => [$state, $needle]) {
            $release->update(['status' => $status]);
            $target = $this->statusOf($game)['targets']['app_store'];
            $this->assertSame($state, $target['state'], $status);
            $this->assertStringContainsString($needle, $target['next'], $status);
            $this->assertStringContainsString('version 1.2.0', $target['next'], $status);
        }

        $release->update(['status' => Release::FAILED, 'last_error' => 'The GitHub run ended with failure in job build.']);
        $target = $this->statusOf($game)['targets']['app_store'];
        $this->assertSame('failed_testflight', $target['state']);
        $this->assertStringContainsString('ended with failure in job build. Samma commit kan skickas igen', $target['next']);
        $this->assertSame(['1.2.0', 5], [$target['stages']['testflight']['latest_release']['marketing_version'], $target['stages']['testflight']['latest_release']['build_number']]);
    }

    public function test_succeeded_testflight_is_the_end_while_app_store_is_closed(): void
    {
        $game = $this->testFlightGame();
        $this->demoBuild($game, $this->sha('a'));
        $this->tf($game, Release::SUCCEEDED, ['commit_sha' => $this->sha('a')]);
        config(['releases.buildable_stages' => ['testflight', 'play_internal']]);

        $this->assertSame('succeeded_testflight', $this->statusOf($game)['targets']['app_store']['state']);
    }
```

Befintliga tester i `ReleaseStatusTest`, `GetReleaseStatusToolTest` och `PromoteReleaseToolTest` som väntar sig `queued_for_build`/`ready_for_test` för `testflight`: byt till `starting`/`ready_for_testflight` om testet gäller TestFlight, annars byt målet till `play_store`/`play_internal` (som i Task 6 steg 5). Hitta dem med `grep -n "testflight\|queued_for_build\|ready_for_test" tests/Feature/Releases/ReleaseStatusTest.php tests/Feature/Mcp/GetReleaseStatusToolTest.php tests/Feature/Mcp/PromoteReleaseToolTest.php`.

Lägg till i `PromoteReleaseToolTest` (fejka spelrepot som i `AdvanceReleaseTest::test_promote_queues_the_job_after_commit`; lägg `Queue::fake()` först i testet):

```php
    public function test_testflight_answer_has_version_build_number_and_the_starting_state(): void
    {
        Queue::fake();
        $this->iosGuides('v3');
        $game = $this->testFlightGame('tinker-isle');
        $owner = $this->ownerOf($game);
        $this->demoBuild($game, $this->sha('d'));
        $this->fakeGameRepo('tinker-isle', '0.2.0'); // hjälpare i testklassen, samma rutter som AdvanceReleaseTest

        $json = json_decode($this->callTool($owner, 'promote_release', ['game' => 'tinker-isle', 'target' => 'app_store', 'stage' => 'testflight'])['text'], true);

        $this->assertSame(['requested', 'starting', '0.2.0', 1], [$json['status'], $json['state'], $json['marketing_version'], $json['build_number']]);
        $this->assertStringContainsString('startar', $json['next']);
    }
```

Hjälparen (i `PromoteReleaseToolTest`, som anropar `Http::fake` bara en gång per test; testklassens `setUp` fejkar Slack, och ett andra `Http::fake` med andra adresser läggs till efter det):

```php
    private function fakeGameRepo(string $slug, string $version): void
    {
        $base = ProvisioningFakes::GITHUB."/repos/HumblebragAB/{$slug}";
        Http::fake([
            ProvisioningFakes::GITHUB.'/app/installations/4242/access_tokens' => Http::response(ProvisioningFakes::githubToken(), 201),
            $base.'/contents/package.json?ref=*' => Http::response(ProvisioningFakes::githubContent(json_encode(['version' => $version]))),
            $base.'/contents/CLAUDE.md?ref=*' => Http::response(ProvisioningFakes::githubContent("Byggguide: **v3**.\n")),
            $base.'/git/ref/tags/*' => Http::response([], 404),
        ]);
    }
```

Run: `cd $WT && php artisan test --filter='ReleaseStatusTest|PromoteReleaseToolTest|GetReleaseStatusToolTest'`
Expected: FAIL.

- [ ] **Steg 2: `ReleaseNext`.** Lägg till konstanten och mallarna, och fyll `{flow}`:

```php
    // "Skicka till TestFlight" (Eriks beslut 2026-10-15). Ordagrant i process-slappflode och i CLAUDE.md
    // för varje byggguideversion som stöder iOS; ReleaseFactbankSyncTest jämför. Ändra här, där och i
    // byggguiden i samma commit (en låst version ändras aldrig: då krävs en ny version).
    public const TESTFLIGHT_FLOW = 'Skicka till TestFlight: 1. Höj version i package.json (MAJOR.MINOR.PATCH): högre än den senast släppta till TestFlight och inte använd förut. 2. Kör npm run check, committa och pusha till main. 3. Vänta tills Demo-bygget för commiten är grönt (get_provisioning_status visar demo.state live). 4. Anropa promote_release(target: app_store, stage: testflight, commit_sha: <commiten>). 5. Följ get_release_status tills släppet är klart. Computercat sätter taggen v<version>, väljer byggnummer, bygger appen och lägger den i TestFlight-gruppen ComputerCat intern.';
```

i `TEMPLATES`:

```php
        'ready_for_testflight' => 'Commit {commit} är testad i Demo. Senast släppta version till TestFlight: {last_version}. {flow}',
        'starting' => 'Släppet {release} (version {version}, bygge {build}) av commit {commit} startar. Fråga igen med get_release_status om en minut.',
        'building_testflight' => 'Släppet {release} (version {version}, bygge {build}) pågår: {phase}. Fråga igen med get_release_status om några minuter.',
        'succeeded_testflight' => 'Släppet {release} (version {version}, bygge {build}) finns i TestFlight för gruppen ComputerCat intern. Installera det med TestFlight-appen. Nästa version: {flow}',
        'failed_testflight' => 'Släppet {release} (version {version}) av commit {commit} misslyckades: {error} Samma commit kan skickas igen med promote_release(target: app_store, stage: testflight, commit_sha: {commit}). Ändrar du koden: {flow}',
```

och först i `for()`:

```php
        $vars += ['flow' => self::TESTFLIGHT_FLOW];
```

- [ ] **Steg 3: `ReleaseStatus`.**
1. `releaseState`: ersätt `match` och variablerna med:

```php
        $testflight = $release->stage === 'testflight';
        $state = match ($release->status) {
            Release::AWAITING_APPROVAL => 'awaiting_approval',
            Release::REQUESTED => ReleaseCatalog::isBuiltByComputercat($release->stage) ? 'starting' : 'queued_for_build',
            Release::BUILDING, Release::PROCESSING => $testflight ? 'building_testflight' : 'in_progress',
            Release::SUCCEEDED => $testflight ? 'succeeded_testflight' : 'succeeded',
            Release::FAILED => $testflight ? 'failed_testflight' : 'failed',
            Release::CANCELLED => 'cancelled',
        };

        return [$state, [
            'release' => $release->uuid,
            'commit' => $release->commit_sha,
            'status' => $release->status,
            'stage' => $release->stage,
            'target' => $release->target,
            'etapp' => ReleaseCatalog::BUILD_ETAPP[$release->stage] ?? '?',
            'error' => $release->last_error === null || $release->last_error === '' ? 'orsak saknas.' : rtrim($release->last_error, '.').'.',
            'version' => (string) ($release->marketing_version ?? '?'),
            'build' => (string) ($release->build_number ?? '?'),
            'phase' => $release->status === Release::PROCESSING ? 'Apple bearbetar bygget' : 'GitHub Actions bygger och laddar upp',
        ]];
```

2. `flowState`: ersätt `return ['ready_for_test', …];` med:

```php
                return $test === 'testflight'
                    ? ['ready_for_testflight', ['commit' => $commit, 'last_version' => (string) (TestFlightPreflight::lastReleased($game) ?? 'ingen')]]
                    : ['ready_for_test', ['commit' => $commit, 'stage' => $test, 'target' => $target]];
```

och efter blocket `if ($testForCommit->status !== Release::SUCCEEDED) { … }`, inne i `if ($test !== 'demo')`:

```php
            // Etapp 2 del 1: App Store är stängt; ett lyckat TestFlight-släpp är slutet tills nästa version.
            if ($test === 'testflight' && ! ReleaseCatalog::isBuildable($prod)) {
                return self::releaseState($testForCommit);
            }
```

3. `forTarget`: lägg till i returarrayen:

```php
        ] + ($target === 'app_store' ? ['ios' => [
            'bundle_id' => $game->bundle_id,
            'last_released_version' => ($last = TestFlightPreflight::lastReleased($game)) === null ? null : (string) $last,
            'last_build_number' => (int) $game->ios_build_number,
        ]] : []);
```

4. `present`: lägg till `'marketing_version' => $release->marketing_version, 'build_number' => $release->build_number,`.

Importera `App\Releases\TestFlight\TestFlightPreflight`. `TestFlightPreflight::lastReleased` är en databasfråga; R9 håller (inga externa anrop).

- [ ] **Steg 4: Verktygen.** `PromoteReleaseTool::$description`:

```php
    protected string $description = 'Promote an exact commit to the next release stage. testflight (app_store) builds the iOS app: raise "version" in package.json (MAJOR.MINOR.PATCH, higher than the latest TestFlight release and not used before), push to main, wait for the green Demo build, then call this; Computercat tags v<version>, picks the build number, builds in GitHub Actions and puts the build in the TestFlight group "ComputerCat intern". play_internal is registered but not built yet. Production stages need an explicit commit_sha that succeeded in the test stage, and the first production release per game and target must be approved by Erik in Filament; production stages that are not built yet are refused. Test stages default to the latest commit with a successful Demo build. Only the game owner. Check get_release_status first; follow it after promoting.';
```

och lägg till i svaret efter `'commit_sha'`:

```php
            'marketing_version' => $release->marketing_version,
            'build_number' => $release->build_number,
```

`GetReleaseStatusTool::$description`: lägg till meningen "For app_store it also shows the bundle id, the latest version released to TestFlight and the latest build number."

- [ ] **Steg 5: Artikelns tillståndsrader.** Lägg till i `resources/faktabank/process/process-slappflode.md` under listan i "Så gör du", efter `cancelled`-raden, fem rader `- \`<tillstånd>\`: <mall>` med exakt mallarna från steg 2 (i samma ordning).

Run: `cd $WT && php artisan test --filter='ReleaseStatusTest|PromoteReleaseToolTest|GetReleaseStatusToolTest|ReleaseFactbankSyncTest|ToolInvariantsTest'`
Expected: PASS.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Releases app/Mcp/Tools resources/faktabank/process/process-slappflode.md tests
git commit -m "next-texter och status för TestFlight: flödestexten, faser, version och byggnummer"
```

---

### Task 9: Faktabanken och testet som jämför texterna

**Files:**
- Modify: `resources/faktabank/process/process-slappflode.md`, `resources/faktabank/process/process-slapp-engangssteg.md`
- Modify: `tests/Feature/ReleaseFactbankSyncTest.php`, `tests/Feature/FactBankContentTest.php`

**Interfaces:**
- Consumes: `ReleaseNext::TESTFLIGHT_FLOW`, `SetupSteps::DEFINITIONS` (Task 5, 8).
- Produces: synktestet som byggguide v3-planen måste klara: varje `resources/byggguide/v*/manifest.json` med `ios`-block har `files/CLAUDE.md` med flödestexten ordagrant på en egen rad.

- [ ] **Steg 1: Testerna (fallerar).** Lägg till i `ReleaseFactbankSyncTest`:

```php
    // Eriks beslut 2026-10-15: "Skicka till TestFlight" betyder samma sak i API:t, faktabanken och spelens CLAUDE.md.
    public function test_testflight_flow_is_verbatim_in_the_article_the_next_text_and_every_ios_build_guide(): void
    {
        $flow = ReleaseNext::TESTFLIGHT_FLOW;
        $line = '/^'.preg_quote($flow, '/').'$/m';

        $this->assertMatchesRegularExpression($line, (string) file_get_contents(resource_path('faktabank/process/process-slappflode.md')));
        $this->assertStringContainsString($flow, ReleaseNext::for('ready_for_testflight', ['commit' => 'x', 'last_version' => 'ingen']));

        // Låsta och olåsta versioner: v3-planen ska få fel innan låsningen, inte efter.
        foreach (glob(resource_path('byggguide/v*/manifest.json')) ?: [] as $manifest) {
            if (! isset(json_decode((string) file_get_contents($manifest), true)['ios'])) {
                continue;
            }
            $claude = dirname($manifest).'/files/CLAUDE.md';
            $this->assertFileExists($claude);
            $this->assertMatchesRegularExpression($line, (string) file_get_contents($claude), basename(dirname($manifest)).': CLAUDE.md saknar flödestexten ordagrant.');
        }
    }

    public function test_articles_name_the_version_rule_and_the_tag(): void
    {
        $text = (string) file_get_contents(resource_path('faktabank/process/process-slappflode.md'));

        foreach (['package.json', 'v<version>', 'byggnummer', 'ComputerCat intern', 'TestFlight'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
    }
```

I `FactBankContentTest::test_common_questions_find_the_right_article`, lägg till i listan: `'skicka till testflight' => 'process-slappflode', 'höja version package.json' => 'process-slappflode', 'bundle-id app store' => 'process-slapp-engangssteg',`.

Run: `cd $WT && php artisan test --filter='ReleaseFactbankSyncTest|FactBankContentTest'`
Expected: FAIL.

- [ ] **Steg 2: `process-slappflode.md`.** Ändra front matter: `tags: [process, slapp, release, promote_release, get_release_status, testflight, version, package.json, tagg, byggnummer, produktion, godkännande]`, `summary: get_release_status säger vad som gäller och vad nästa steg är. Skicka till TestFlight: höj version i package.json, pusha, vänta på grönt Demo och anropa promote_release. Första produktionssläppet per mål godkänns av Erik i Filament.`, `updated: "2026-10-17"`. Ersätt "## Regel" med:

```markdown
## Regel

1. Fråga `get_release_status` först. Svaret har en rad `next` som säger vad du ska göra nu; gör det och inget annat.
2. En ny commit på main bygger Demo som vanligt. Ingen app byggs vid en vanlig push. Commiten går vidare först när du ber om det med `promote_release(target, stage, commit_sha?)`. Bara spelets ägare kan göra det.
3. TestFlight byggs bara när versionen höjs. `version` i `package.json` (MAJOR.MINOR.PATCH) är det enda stället versionen sätts. Den ska vara högre än den senast släppta till TestFlight och inte använd förut; varje ny commit till TestFlight får en ny version. Samma commit kan skickas igen med samma version om släppet misslyckades.
4. Computercat sätter taggen `v<version>` på commiten i spelrepot, väljer byggnumret och bygger appen. Ändra eller flytta aldrig taggen och sätt aldrig byggnummer själv.
5. Produktionssteg (`web_production`, `app_store`, `play_production`) kräver att du anger `commit_sha`, och den commiten måste ha lyckats i teststeget (för webben: ha ett lyckat Demo-bygge). Det första produktionssläppet per spel och mål godkänns av Erik i Filament; du kan inte godkänna det själv och ska inte försöka.
6. Mål (`web`, `app_store`, `play_store`) och engångssteg sätts av Erik och Computercat. Ett öppet engångssteg stoppar de steg det gäller.
7. Produktionsstegen öppnas etapp för etapp. Så länge `get_release_status` inte föreslår produktion går det inte att begära den, och du ska inte försöka.
```

Under "## Varför", lägg till sist: "Ett iOS-bygge tar en macOS-maskin i GitHub Actions och ett byggnummer hos Apple, så det görs bara när en ny version faktiskt ska testas på telefon. Taggen visar i efterhand exakt vilken commit som blev vilken version."

Under "## Så gör du", före listan över tillstånden, lägg till:

```markdown
### Skicka till TestFlight

Ordagrant samma text som `next` i `get_release_status` och som spelets `CLAUDE.md`:

<flödestexten, ordagrant ReleaseNext::TESTFLIGHT_FLOW, på en egen rad>

Svarar `promote_release` att versionen är för låg eller att taggen redan finns: höj `version` som svaret säger och börja om från steg 1. Svarar det att byggguiden inte kan byggas för iOS: flytta spelet till senaste byggguiden först (`get_build_guide`).
```

(Klistra in konstantens värde i stället för raden inom `< >`; testet i steg 1 kräver att raden är exakt konstanten.) Lägg till i "## Källa": `- app/Releases/TestFlight/TestFlightPreflight.php` och `- app/Releases/TestFlight/TestFlightBuilder.php`.

- [ ] **Steg 3: `process-slapp-engangssteg.md`.** `updated: "2026-10-17"`, `tags` får `testflight, bundle-id, profil`. Ersätt regel 2 och 3 med:

```markdown
2. Gissa aldrig att ett steg är klart. Bundle-id, profil och TestFlight-grupp gör Computercat själv; appen i App Store Connect skapar Erik, och Computercat ser själv när den finns. Övriga steg bockar en platform admin av i Filament.
3. Ett öppet steg stoppar de steg det gäller. Integritetsuppgifter och åldersgräns stoppar bara App Store, inte TestFlight. Försök inte komma runt ett steg.
```

och lägg till i "## Varför": "Spelets bundle-id är `se.computercat.<slug utan bindestreck>`. Det är samma värde som `appId` i spelets `capacitor.config.ts`; `get_release_status` visar det."

- [ ] **Steg 4: Kör.**

Run: `cd $WT && php artisan test --filter='ReleaseFactbankSyncTest|FactBankContentTest|FactToolsTest'`
Expected: PASS. Faller en sökning i `FactBankContentTest`: justera artikelns `tags`/`summary` (inte testets förväntan).

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add resources/faktabank tests/Feature/ReleaseFactbankSyncTest.php tests/Feature/FactBankContentTest.php
git commit -m "Faktabanken: Skicka till TestFlight ordagrant, versions- och taggregeln, engångsstegen per steg; synktest mot byggguiden"
```

---
### Task 10: Släpprepot — `release-ios.yml` via PR

**Files (i `HumblebragAB/computercat-release`, klonat till `$SCRATCH/computercat-release`):**
- Create: `.github/workflows/release-ios.yml`
- Delete: `.github/workflows/release-ios-spike.yml`
- Modify: `scripts/asc-profile.mjs` (använder `asc.mjs`, kontrollerar `ACTIVE`)
- Modify: `README.md`
- Modify (computercat-api): `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (testkörningens resultat)

**Interfaces:**
- Consumes: indata från `TestFlightBuilder::inputs()` (Task 7): `game`, `commit_sha`, `release_uuid`, `build_number`, `marketing_version`, `bundle_id`, `profile_id`, `build_guide`; `run-name` `ios <game> <release_uuid>` (Task 2:s `findWorkflowRun`). Jobb- och stegnamnen nedan är de som `last_error` visar (R10).
- Produces: ett bygge i App Store Connect för `bundle_id` med `CFBundleShortVersionString = marketing_version` och `CFBundleVersion = build_number`, eller en misslyckad körning med ett namngivet steg.

- [ ] **Steg 1: Hotmodell.** Invokera `security-first-mindset` för flödet. Kontrollera mot specens Del 2 och bilagan: indata valideras i första steget innan något annat körs och används bara via `env:` (aldrig `${{ }}` direkt i ett skript); `permissions: {}` överst; `fetch` har bara läsappens token, begränsad till spelrepot och `contents: read`, och laddar upp källan utan `.git`; `build` har inga hemligheter och kör spelets kod (`npm ci --ignore-scripts`, `npm run build`, `cap sync`); `sign` checkar inte ut spelrepot, får bara `xcarchive` och har nycklarna; nyckelringen och nyckelfilen tas bort i `if: always()`. `concurrency` per spel hindrar två samtidiga byggen av samma app.

- [ ] **Steg 2: Klona och skapa gren (bara lokalt).**

```bash
gh repo clone HumblebragAB/computercat-release $SCRATCH/computercat-release && cd $SCRATCH/computercat-release && git switch -c feat/release-ios && git log --oneline -3
```

- [ ] **Steg 3: `release-ios.yml`.**

```yaml
name: release-ios
run-name: "ios ${{ inputs.game }} ${{ inputs.release_uuid }}"
# Startas bara av Computercat (workflow_dispatch på main, return_run_details). Spec:
# computercat-api docs/superpowers/specs/2026-10-14-slappflode-design.md, Del 2 alternativ 1.
# Jobb- och stegnamnen visas för spelets Claude när ett släpp faller; byt dem inte utan att
# uppdatera faktabanken.
on:
  workflow_dispatch:
    inputs:
      game: { required: true, type: string }
      commit_sha: { required: true, type: string }
      release_uuid: { required: true, type: string }
      build_number: { required: true, type: string }
      marketing_version: { required: true, type: string }
      bundle_id: { required: true, type: string }
      profile_id: { required: true, type: string }
      build_guide: { required: true, type: string }
permissions: {}
concurrency:
  group: ios-${{ inputs.game }}
  cancel-in-progress: false
jobs:
  fetch:
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    steps:
      - name: Validera indata
        env:
          GAME: "${{ inputs.game }}"
          SHA: "${{ inputs.commit_sha }}"
          UUID: "${{ inputs.release_uuid }}"
          BUILD: "${{ inputs.build_number }}"
          VER: "${{ inputs.marketing_version }}"
          BID: "${{ inputs.bundle_id }}"
          PID: "${{ inputs.profile_id }}"
          GUIDE: "${{ inputs.build_guide }}"
        run: |
          [[ "$GAME" =~ ^[a-z][a-z0-9-]{2,39}$ ]] && [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] \
            && [[ "$UUID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] \
            && [[ "$BUILD" =~ ^[1-9][0-9]{0,8}$ ]] \
            && [[ "$VER" =~ ^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$ ]] \
            && [[ "$BID" =~ ^se\.computercat\.[a-z][a-z0-9]{1,39}$ ]] && [[ "$PID" =~ ^[A-Z0-9]{10}$ ]] \
            && [[ "$GUIDE" =~ ^v[1-9][0-9]{0,2}$ ]] || { echo "::error::ogiltig indata"; exit 1; }
      - id: token
        name: Lästoken för spelrepot
        uses: actions/create-github-app-token@v2
        with:
          app-id: ${{ secrets.CC_READER_APP_ID }}
          private-key: ${{ secrets.CC_READER_PRIVATE_KEY }}
          owner: HumblebragAB
          repositories: ${{ inputs.game }}
          permission-contents: read
      - name: Token når bara spelrepot
        env: { GH_TOKEN: "${{ steps.token.outputs.token }}", GAME: "${{ inputs.game }}" }
        run: |
          gh api "repos/HumblebragAB/$GAME" --silent
          if gh api repos/HumblebragAB/computercat-release --silent 2>/dev/null; then echo "::error::lästoken når släpprepot"; exit 1; fi
      - name: Checka ut spelrepot
        uses: actions/checkout@v4
        with:
          repository: HumblebragAB/${{ inputs.game }}
          ref: ${{ inputs.commit_sha }}
          token: ${{ steps.token.outputs.token }}
          persist-credentials: false
          submodules: false
          lfs: false
          path: src
      - name: Packa källan utan .git
        run: tar -C src --exclude=.git -czf source.tgz .
      - uses: actions/upload-artifact@v4
        with: { name: source, path: source.tgz, retention-days: 1 }

  build:
    needs: fetch
    runs-on: macos-26
    timeout-minutes: 40
    steps:
      - name: Inga hemligheter i byggjobbet
        run: |
          if env | grep -qE 'BEGIN [A-Z ]*PRIVATE KEY|^(GH_TOKEN|GITHUB_TOKEN)='; then echo "::error::byggjobbet har en hemlighet i miljön"; exit 1; fi
      - uses: actions/download-artifact@v4
        with: { name: source }
      - name: Packa upp källan
        run: mkdir src && tar -C src -xzf source.tgz && rm source.tgz
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - name: Installera beroenden
        working-directory: src
        run: npm ci --ignore-scripts
      - name: Kontrollera version
        working-directory: src
        env: { VER: "${{ inputs.marketing_version }}" }
        run: |
          ACTUAL=$(node -p "require('./package.json').version")
          [ "$ACTUAL" = "$VER" ] || { echo "::error::package.json har version $ACTUAL, Computercat väntade $VER"; exit 1; }
      - name: Bygg och säkerhetskontroll
        working-directory: src
        env:
          NEXT_PUBLIC_COMPUTERCAT_GAME: "${{ inputs.game }}"
          BUILD_GUIDE: "${{ inputs.build_guide }}"
        run: |
          # sha256 för cc-gate.mjs per byggguideversion, ur computercat-api resources/byggguide/<v>/manifest.json.
          case "$BUILD_GUIDE" in
            v2) GATE_SHA=e4460881ca534a085c500bb0c768e4ea9a1d6c7d7c2f33babf4e7ef8c8969009 ;;
            *) echo "::error::byggguide $BUILD_GUIDE har ingen sha256 för cc-gate.mjs i release-ios.yml"; exit 1 ;;
          esac
          npm run build
          curl -fsS "https://api.computercat.co/api/v1/build-guide/${BUILD_GUIDE}/files/scripts/cc-gate.mjs" -o "$RUNNER_TEMP/cc-gate.mjs"
          echo "${GATE_SHA}  $RUNNER_TEMP/cc-gate.mjs" | shasum -a 256 -c -
          node "$RUNNER_TEMP/cc-gate.mjs"
      - name: Capacitor sync
        working-directory: src
        run: npx cap sync ios
      - name: Kontrollera bundle-id och exportdeklaration
        working-directory: src/ios/App
        env: { BID: "${{ inputs.bundle_id }}" }
        run: |
          APPID=$(node -p "require('./App/capacitor.config.json').appId")
          [ "$APPID" = "$BID" ] || { echo "::error::capacitor.config.ts har appId $APPID, Computercat väntade $BID"; exit 1; }
          IDS=$(grep -E '^[[:space:]]*PRODUCT_BUNDLE_IDENTIFIER = ' App.xcodeproj/project.pbxproj | sed -E 's/.*= "?([^";]+)"?;.*/\1/' | sort -u)
          [ "$IDS" = "$BID" ] || { echo "::error::project.pbxproj har PRODUCT_BUNDLE_IDENTIFIER $IDS, Computercat väntade $BID"; exit 1; }
          ENC=$(/usr/libexec/PlistBuddy -c 'Print :ITSAppUsesNonExemptEncryption' App/Info.plist 2>/dev/null || echo saknas)
          [ "$ENC" = "false" ] || { echo "::error::Info.plist: ITSAppUsesNonExemptEncryption är $ENC, ska vara false"; exit 1; }
      - name: Arkivera osignerat
        working-directory: src/ios/App
        env: { BUILD: "${{ inputs.build_number }}", VER: "${{ inputs.marketing_version }}" }
        run: |
          xcodebuild -version | head -1
          xcodebuild -project App.xcodeproj -scheme App -configuration Release -destination 'generic/platform=iOS' \
            -archivePath "$RUNNER_TEMP/App.xcarchive" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO \
            CURRENT_PROJECT_VERSION="$BUILD" MARKETING_VERSION="$VER" archive | tail -5
          test -d "$RUNNER_TEMP/App.xcarchive/Products/Applications/App.app"
          ditto -c -k --keepParent "$RUNNER_TEMP/App.xcarchive" "$RUNNER_TEMP/xcarchive.zip"
      - uses: actions/upload-artifact@v4
        with: { name: xcarchive, path: "${{ runner.temp }}/xcarchive.zip", retention-days: 1 }

  sign:
    needs: build
    runs-on: macos-26
    timeout-minutes: 30
    # Bara läsrätt till släpprepot självt (skripten). Spelrepot checkas aldrig ut här.
    permissions:
      contents: read
    env:
      ASC_KEY_ID: ${{ secrets.ASC_KEY_ID }}
      ASC_ISSUER_ID: ${{ secrets.ASC_ISSUER_ID }}
      BID: ${{ inputs.bundle_id }}
      PID: ${{ inputs.profile_id }}
    steps:
      - uses: actions/checkout@v4
        with: { persist-credentials: false }
      - uses: actions/download-artifact@v4
        with: { name: xcarchive }
      - name: Packa upp arkivet
        run: |
          ditto -x -k xcarchive.zip "$RUNNER_TEMP"
          test -x "$RUNNER_TEMP/App.xcarchive/Products/Applications/App.app/App"
      - name: Nyckel, certifikat och profil
        env:
          ASC_KEY_P8_BASE64: ${{ secrets.ASC_KEY_P8_BASE64 }}
          P12_BASE64: ${{ secrets.IOS_DIST_CERT_P12_BASE64 }}
          P12_PASSWORD: ${{ secrets.IOS_DIST_CERT_PASSWORD }}
        run: |
          mkdir -p ~/.appstoreconnect/private_keys
          echo "$ASC_KEY_P8_BASE64" | base64 -D > ~/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8
          chmod 600 ~/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8
          KC="$RUNNER_TEMP/sign.keychain-db"; KCP=$(openssl rand -hex 16)
          security create-keychain -p "$KCP" "$KC"
          security set-keychain-settings -lut 1800 "$KC"
          security unlock-keychain -p "$KCP" "$KC"
          echo "$P12_BASE64" | base64 -D > "$RUNNER_TEMP/dist.p12"
          security import "$RUNNER_TEMP/dist.p12" -k "$KC" -P "$P12_PASSWORD" -T /usr/bin/codesign -T /usr/bin/security
          rm -f "$RUNNER_TEMP/dist.p12"
          security set-key-partition-list -S apple-tool:,apple: -s -k "$KCP" "$KC" > /dev/null
          security list-keychains -d user -s "$KC" $(security list-keychains -d user | tr -d '"')
          ASC_KEY_P8_PATH=~/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8 node scripts/asc-profile.mjs "$PID" "$RUNNER_TEMP/app.mobileprovision" | tee "$RUNNER_TEMP/profile.txt"
          UUID=$(sed -n 's/^uuid=//p' "$RUNNER_TEMP/profile.txt")
          [[ "$UUID" =~ ^[0-9A-Fa-f-]{36}$ ]] || { echo "::error::profilen saknar uuid"; exit 1; }
          for d in ~/Library/MobileDevice/Provisioning\ Profiles ~/Library/Developer/Xcode/UserData/Provisioning\ Profiles; do mkdir -p "$d"; cp "$RUNNER_TEMP/app.mobileprovision" "$d/$UUID.mobileprovision"; done
          echo "PROFILE_UUID=$UUID" >> "$GITHUB_ENV"
      - name: Signera och exportera
        run: |
          cat > "$RUNNER_TEMP/ExportOptions.plist" <<PLIST
          <?xml version="1.0" encoding="UTF-8"?>
          <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
          <plist version="1.0"><dict>
            <key>method</key><string>app-store-connect</string>
            <key>teamID</key><string>CUDL7PSFD2</string>
            <key>signingStyle</key><string>manual</string>
            <key>signingCertificate</key><string>iPhone Distribution</string>
            <key>provisioningProfiles</key><dict><key>${BID}</key><string>${PROFILE_UUID}</string></dict>
            <key>destination</key><string>export</string>
          </dict></plist>
          PLIST
          xcodebuild -exportArchive -archivePath "$RUNNER_TEMP/App.xcarchive" -exportOptionsPlist "$RUNNER_TEMP/ExportOptions.plist" -exportPath "$RUNNER_TEMP/export" | tail -5
          test -f "$RUNNER_TEMP/export/App.ipa"
      - name: Ladda upp till App Store Connect
        env:
          VER: ${{ inputs.marketing_version }}
          BUILD: ${{ inputs.build_number }}
        run: ASC_KEY_P8_PATH=~/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8 node scripts/asc-upload.mjs "$RUNNER_TEMP/export/App.ipa" "$BID" "$VER" "$BUILD"
      - name: Städa
        if: always()
        run: |
          security delete-keychain "$RUNNER_TEMP/sign.keychain-db" 2>/dev/null || true
          rm -rf ~/.appstoreconnect "$RUNNER_TEMP/app.mobileprovision" "$RUNNER_TEMP/export" "$RUNNER_TEMP/dist.p12"
```

Skillnader mot spikflödet, alla avsiktliga: indata `build_guide` och sha256 per version (R2), strängare mönster för `release_uuid`, `build_number`, `marketing_version` och `bundle_id`, kontroll av version, bundle-id och exportdeklaration i byggjobbet (R12), felande kontroller i stället för utskrifter (G3, G4, G6), `concurrency`, profilens uuid kontrolleras.

- [ ] **Steg 4: `scripts/asc-profile.mjs`.** Ersätt filen (samma utdata som förut, en JWT-implementation i stället för två):

```js
// Hämtar en provisioneringsprofil från App Store Connect och skriver den till disk.
// Användning: node scripts/asc-profile.mjs <profil-id> <utfil>. Nyckeln läses från ASC_KEY_P8_PATH.
import { writeFileSync } from 'node:fs';
import { asc } from './asc.mjs';

const [id, out] = process.argv.slice(2);
if (!/^[A-Z0-9]{10}$/.test(id ?? '') || !out) { console.error('användning: asc-profile.mjs <profil-id> <utfil>'); process.exit(1); }
const attrs = (await asc('GET', `/v1/profiles/${id}`)).data.attributes;
if (attrs.profileState !== 'ACTIVE') { console.error(`profilen är ${attrs.profileState}`); process.exit(1); }
writeFileSync(out, Buffer.from(attrs.profileContent, 'base64'));
console.log(`profil ${attrs.name} type=${attrs.profileType}`);
console.log(`uuid=${attrs.uuid}`);
```

`README.md`: lägg till ett avsnitt "## release-ios.yml" med indata, de tre jobben, att v3:s sha256 läggs till i `Bygg och säkerhetskontroll` när byggguide v3 är låst, och att flödet bara startas av Computercat.

- [ ] **Steg 5: Lokala kontroller.**

```bash
cd $SCRATCH/computercat-release && git rm -q .github/workflows/release-ios-spike.yml
command -v actionlint >/dev/null && actionlint .github/workflows/release-ios.yml || ruby -ryaml -e 'YAML.load_file(ARGV[0]); puts "yaml ok"' .github/workflows/release-ios.yml
node --check scripts/asc-profile.mjs && echo "js ok"
grep -c '\${{ inputs\.' .github/workflows/release-ios.yml
grep -nE 'run: .*\$\{\{ inputs\.' .github/workflows/release-ios.yml || echo "inga inputs direkt i run"
```

Förväntat: `yaml ok` (eller actionlint utan fel), `js ok`, och raden "inga inputs direkt i run" (indata går bara via `env:`, `with:`, `run-name` och `concurrency`). Saknas både actionlint och ruby: fråga Erik innan något installeras.

- [ ] **Steg 6: Fråga Erik (AskUserQuestion) och öppna PR.** "Släpprepot: push av grenen feat/release-ios och en PR mot main i HumblebragAB/computercat-release (release-ios.yml ersätter spikflödet; diffen bifogad). Inget körs förrän du har mergat. OK?" Med OK:

```bash
cd $SCRATCH/computercat-release && git add -A && git commit -m "release-ios.yml: det riktiga iOS-flödet (fetch, build, sign) ersätter spikflödet"
git push -u origin feat/release-ios
gh pr create --repo HumblebragAB/computercat-release --base main --head feat/release-ios --title "release-ios.yml: iOS-släpp till TestFlight" --body "Ersätter release-ios-spike.yml. Indata från Computercat (etapp 2 del 1, plan 2026-10-15-slapp-testflight Task 10). Tre jobb: fetch (läsapp, bara spelrepot), build (macos-26, inga hemligheter, cc-gate per byggguideversion, kontroll av version, bundle-id och exportdeklaration), sign (nycklarna, buildUploads)."
```

Erik granskar och mergar. Merga aldrig själv.

- [ ] **Steg 7: Testkörning mot spikappen (efter Eriks merge, eget OK).** Fråga Erik (AskUserQuestion): "Testkörning av release-ios.yml mot spikrepot ccspike-ios (byggguide v2, appen ComputerCat Test, se.computercat.test): en dispatch med byggnummer 10 och version från ccspike-ios package.json, som laddar upp ett bygge till ComputerCat Test i App Store Connect. Vilket profil-id har spikprofilen för se.computercat.test (spike B2)? OK?" Med OK och profil-id:

```bash
gh workflow run release-ios.yml --repo HumblebragAB/computercat-release --ref main \
  -f game=ccspike-ios -f commit_sha=$(gh api repos/HumblebragAB/ccspike-ios/commits/main --jq .sha) \
  -f release_uuid=$(uuidgen | tr 'A-Z' 'a-z') -f build_number=10 \
  -f marketing_version=$(gh api repos/HumblebragAB/ccspike-ios/contents/package.json --jq .content | base64 -d | jq -r .version) \
  -f bundle_id=se.computercat.test -f profile_id=<profil-id> -f build_guide=v2
gh run watch --repo HumblebragAB/computercat-release $(gh run list --repo HumblebragAB/computercat-release --workflow release-ios.yml --limit 1 --json databaseId --jq '.[0].databaseId')
```

Förväntat: alla tre jobben gröna, `Kontrollera bundle-id och exportdeklaration` passerar (bekräftar sökvägen `ios/App/App/capacitor.config.json` efter `cap sync`), uppladdningen når `COMPLETE`. Faller ett steg på något annat än spelets kod: stanna och fråga Erik; ändra flödet bara via ny PR.

- [ ] **Steg 8: Bilagan.** Skriv i bilagan, avsnittet "Etapp 2 del 1", körningens id, jobbtider, Xcode-version och resultatet av kontrollstegen. Commit i worktreen:

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md && git commit -m "Bilaga: testkörning av release-ios.yml mot spikappen"
```

---

### Task 11: Verifiering, säkerhetsgranskning och completion-gate

**Files:** inga nya (rättelser där granskningen hittar fel).

- [ ] **Steg 1: Hela sviten och stil.**

```bash
cd $WT && php artisan test && vendor/bin/pint --test
```

Förväntat: allt grönt. `git diff main...HEAD --stat -- resources/byggguide` är tom (byggguiden ändras inte i den här planen).

- [ ] **Steg 2: Invarianter (CLAUDE.md "Invarianter").** Bekräfta att varje invariant har ett test som körs i sviten och ange testets namn i commit-meddelandet för steg 5:
  1. Ett byggnummer används högst en gång per spel (unikt index + `test_build_numbers_never_repeat`).
  2. `games.ios_build_number` ≥ högsta `releases.build_number` för spelet (lägg till i `TestFlightPromoteTest`: efter två befordringar och ett misslyckande är `ios_build_number === Release::where('game_id', …)->max('build_number')`).
  3. Ett `succeeded` TestFlight-släpp har `marketing_version`, `build_number`, `external.github_run_id`, `external.apple_build_id` och `external.workflow_sha` (lägg till assertion i `test_valid_build_is_added_to_the_group_and_the_release_succeeds`, efter att släppet gått hela vägen från `requested` i ett nytt test `test_full_path_from_requested_to_succeeded` som anropar `advance()` tre gånger med samma fejkar).
  4. Flödestexten är identisk i `ReleaseNext`, artikeln och varje iOS-byggguide (`ReleaseFactbankSyncTest`).

- [ ] **Steg 3: Säkerhetsgranskning.** Invokera `security-first-mindset` och kör dess review gate över `git diff main...HEAD` med en angripares ögon: kan en spelägare få Computercat att tagga, bygga eller ladda upp något annat än sin egen commit (`commit_sha` kommer bara från Demo-kvittot eller valideras mot det; spelrepots innehåll påverkar bara version och byggguide, som valideras)? Kan en kapad session bygga ett annat spels app (owner-kontrollen, `bundle_id` från spelet)? Kan ett svar från GitHub eller Apple hamna oredigerat i `last_error`, Slack eller loggen? Kan JWT:n, installationstoken eller p8-nyckeln nå en logg (`Log::spy`-testet, `Redactor`)? Finns någon DELETE/PATCH-väg? Fynd rapporteras till Erik, fixas inte tyst.

- [ ] **Steg 4: completion-gate.** Invokera `completion-gate` och passera alla fem block. "Fungerar det" kan i den här tasken bara visas med fejkar och Task 10:s testkörning; grinden ska notera att ett riktigt Computercat-drivet släpp först sker i Task 12.

- [ ] **Steg 5: Commit av rättelser och överlämning.**

```bash
cd $WT && git add -A && git commit -m "Verifiering etapp 2 del 1: invarianttester och rättelser från granskningen"
```

Avsluta med `~/.claude/bin/wt-finish $WT --keep-db` först när Erik beordrat merge (deploy ingår inte i Task 11).

---

### Task 12: Efter deploy (bara på Eriks order): prod-konfiguration, engångssteg och acceptans

Detta är en "stor lansering" i CLAUDE.md:s mening (nytt subsystem live, första skrivande operationer mot Apple och GitHub från Computercat, och Mattias ska informeras). Ordningen nedan är tvingande: deploy, verifiera, först därefter berättas det för Mattias.

- [ ] **Steg 1: Prod-miljön (AskUserQuestion först, secret-fil).** Fråga Erik: "Prod-.env på api.computercat.co (/home/computercat/api.computercat.co/shared/.env eller motsvarande) får fem nya rader: ASC_RELEASE_KEY_ID=N2SC66KSLW, ASC_RELEASE_ISSUER_ID=8850ac41-1ee7-41bd-b588-250700218b39, ASC_RELEASE_PRIVATE_KEY_PATH=app/private/asc-release.p8, ASC_RELEASE_CERTIFICATE_ID=9W6K83MH5B och ASC_TESTFLIGHT_TESTERS=<e-postadresserna du anger>. Sedan php artisan config:cache. Rollback: ta bort raderna och config:cache. OK, och vilka testare?" Gör ändringen bara med OK, via `ssh-claude`, och skriv aldrig ut filens övriga innehåll.

- [ ] **Steg 2: Kontroll i prod (bara läsning).** `sudo -n -u computercat php8.4 artisan tinker --execute='print_r(App\Releases\AppStore\AppStoreConfig::missing());'` ger en tom lista; `stat -c %a storage/app/private/asc-release.p8` ger `600`; en läsning `app(App\Releases\AppStore\AppStoreConnectApi::class)->findApp("se.computercat.test")` ger app-id `6823067635` (spike B3). Kön kör (`AdvanceRelease` behöver workern; kontrollera Forge-daemonen) och schemat har `computercat:advance-releases`.

- [ ] **Steg 3: Migrationerna (OK före).** `php artisan migrate --pretend` först, sedan `php artisan migrate` med OK. Invariant direkt efter: för varje spel med `app_store` i målen har `bundle_id` formen `se.computercat.*` och fem `appstore_*`-steg; inga andra spel har fått `bundle_id`. Läs SQL-resultatet, gissa inte.

- [ ] **Steg 4: Byggguide v3 och släpprepot.** Vänta tills v3-planen (R1) är låst och deployad. Lägg sedan v3:s sha256 för `scripts/cc-gate.mjs` (`jq -r '.files[] | select(.path=="scripts/cc-gate.mjs") | .sha256' resources/byggguide/v3/manifest.json`) som en rad `v3)` i steget "Bygg och säkerhetskontroll" via en ny PR i släpprepot (samma OK-gång som Task 10 steg 6).

- [ ] **Steg 5: Acceptans med ett riktigt v3-spel (AskUserQuestion: vilket spel).** I ordning, med Eriks OK före steg som skriver mot Apple eller GitHub:
  1. Erik kör `set_release_targets` med `app_store`. `get_release_status` visar bundle-id och de öppna stegen; `RunAppStoreSetup` gör bundle-id och profil. Kontrollera i Filament att stegen är klara och att `last_error` är tom.
  2. Erik skapar appen med exakt värdena i stegets instruktion. Inom en minut efter "Bocka av" (eller nästa körning) är steget klart, gruppen "ComputerCat intern" finns och testarna är med (kontrollera i App Store Connect, TestFlight, Interna testare).
  3. Spelets Claude följer `next` ordagrant: höjer version, `npm run check`, push, väntar på grönt Demo, `promote_release(target: app_store, stage: testflight)`.
  4. Följ `get_release_status`: `starting` → `building_testflight` (GitHub) → `building_testflight` (Apple) → `succeeded_testflight`. Verifiera efter första fasbytet, inte efter en timme: taggen `v<version>` finns på exakt commiten (`gh api repos/HumblebragAB/<spel>/git/ref/tags/v<version>`), körningens `run-name` innehåller släppets uuid, och `releases.build_number` = `games.ios_build_number`.
  5. Slutinvariant: bygget i App Store Connect har version och byggnummer som släppet, `processingState` `VALID`, och finns i gruppen (R7: bekräftar att `hasAccessToAllBuilds: false` plus uttrycklig länk fungerar). Erik installerar det med TestFlight på sin telefon och bekräftar att spelet startar och når Computercat (topplistan laddar).
  6. Ett nej-fall: be spelets Claude befordra samma version från en ny commit; svaret ska vara taggregeln med förslag på nästa version, och ingen ny rad, tagg eller körning skapas.
  Faller något: stanna, rör inget mer, rapportera till Erik.

- [ ] **Steg 6: Först nu informeras Mattias** (Erik bestämmer när). Skriv i Pejl vad som gäller.

---

## Täckning mot specen (självgranskning)

| Spec / Eriks beslut | Task |
|---|---|
| Bara TestFlight; App Store-förberedelse senare | Global Constraints, R14-avgränsning, `buildable_stages` oförändrad |
| Bundle-id `se.computercat.<slug utan bindestreck>`, unikt, krock vägras, spec uppdaterad | 0, 3, 5 |
| Versionsflödet: höj version, check, push, grönt Demo, `promote_release` | 6 (förkontroll), 8 (`TESTFLIGHT_FLOW`), 9 (faktabank), 4 + v3-plan (CLAUDE.md) |
| Nekar om versionen inte är högre; svaret säger vad som ska höjas | 6 |
| Taggen `v<version>` via ComputerCat Studio | 2, 7 (R3, R4) |
| Byggnummer stigande per app, sparat i Computercat | 3, 6 (R5) |
| Dispatch med `return_run_details` och de sju indata (+ `build_guide`) | 2, 7, 10 (R2) |
| Följa körningen och Apples bearbetning, 60/90 min, Slack, avbruten körning blir `failed` | 7 (R11) |
| Bygget i gruppen "ComputerCat intern" | 1, 7 (R7) |
| Engångssteg: bundle-id och profil via API; appposten manuellt med exakta värden; grupp och testare via API | 1, 5 (R8) |
| Spärrar 1, 4, 5, 6, 9 + versionsspärren | 1a-koden (1, 5, 9), 6 (4 före HTTP, version), 5 (6 per steg), 7 (7/9 vid start) |
| Släpprepot via PR; tre jobb; cc-gate per byggguideversion; spikflödet ersätts | 10, 12 steg 4 |
| ASC-nyckeln som fil i prod, key id/issuer/certifikat i env | 1, 12 |
| Fejkar byggda på spikresultaten, redigerade fel, request/response vid 4xx/5xx | 1, 2 |
| Texten ordagrant i faktabanken, CLAUDE.md och `next`, med test | 8, 9 |
| Revisionslogg: commit och version av släppflödet | 7 (`workflow_sha`), befintlig `mcp_audit_logs` |
| Acceptans med ett riktigt v3-spel efter deploy | 12 |
| Achievements i v3 | R1: egen plan; denna plan definierar iOS-kontraktet (4) och synktestet (9) |

**Platshållare:** inga TBD/TODO i kod. Avsiktliga värden som fylls vid körning: profil-id och testarnas e-post (Erik anger, Task 10 och 12), v3:s sha256 (Task 12 steg 4, när v3 finns), flödestexten i artikeln (klistras in från konstanten; testet kräver exakt likhet).

**Typkonsistens (kontrollerad):** `TestFlightPreflight::check(Game, string): PreflightResult` (6) används i `ReleaseService::promote` (6); `lastReleased(): ?MarketingVersion` i 6 och 8. `GitHubAppClient::dispatchWorkflow(): array{run_id, html_url}` (2) läses som `$run['run_id']`/`$run['html_url']` (7); `findWorkflowRun()`/`workflowRun()` ger `id`, `html_url`, `head_sha` (2, 7). `AppStoreConnectApi::profile(): ?array{state, expires_at}` (1) i 7; `findBuild(): ?array{id, state}` (1, 7); `findBetaTester(string, ?string)` (1, 5). `Game::appStoreId()`-nycklar `bundle_resource_id`, `profile_id`, `app_id`, `app_name`, `beta_group_id` är desamma i 3, 5, 6, 7 och `testFlightGame()`. `ReleaseCatalog::isBuiltByComputercat` (6) i 7 och 8. Jobb: `AdvanceRelease(int $releaseId)`, `RunAppStoreSetup(int $gameId)`; testerna läser `$job->releaseId`/`$job->gameId`.

**Kända risker som planen inte kan ta bort:** (1) R7 (`hasAccessToAllBuilds: false` + uttrycklig länk) är oprövat mot Apple tills Task 12; (2) sökvägen `ios/App/App/capacitor.config.json` efter `cap sync` bekräftas först i Task 10 steg 7; (3) macOS-minuternas pris och kvot är inte verifierade (spike A G7); (4) Apples krav på att `CFBundleVersion` ska stiga inom en version: Computercat delar alltid ut ett högre nummer, så det kan bara slå fel om någon laddar upp utanför Computercat.
