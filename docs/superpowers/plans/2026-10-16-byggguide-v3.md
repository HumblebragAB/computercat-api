# Byggguide v3: achievements i klienten, Capacitor iOS och "Skicka till TestFlight" — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Byggguide v3 blir en ny låst version där spelets klient kan rapportera och läsa achievements utan egen nätverkskod, där spelet kan få ett iOS-projekt (Capacitor 8.5.3, Swift Package Manager) som släpprepots `release-ios.yml` bygger, och där `CLAUDE.md` säger ordagrant hur man skickar till TestFlight.

**Architecture:** v3 är en kopia av v2 i `resources/byggguide/v3/` med en ny klientmodul (`src/lib/computercat/achievements.ts`: kö i lagringen, omgångar om högst 10, synliga `unknown`/`rejected`/`dropped`), `flushAll()`/`onFlushed()` i `index.ts` som `BackgroundSync` anropar, två skript för iOS (`scripts/ios-setup.mjs` skapar `capacitor.config.ts` och `ios/` en gång med spelets bundle-id från Computercat; `scripts/check-ios.mjs` kontrollerar projektet sist i `npm run check` och hoppar över när `ios/` saknas), stängda lint-omvägar till `process.env`, jsdom som klarar Coolifys Node 22.14, och ett `ios`-block i manifestet enligt kontraktet i TestFlight-planens Task 4. Säkerhetsspärren (`cc-gate.mjs`) och CSP:n (`csp.ts`) är byte för byte desamma som i v2. v3 låses med `FROZEN['v3']` och blir `LATEST` i samma commit; faktabanken följer i nästa commit.

**Tech Stack:** Laravel 12, PHP 8.4, PHPUnit 11. Byggguiden: Next.js 16.4 (statisk export), React 19.2, vitest 4.1.11, jsdom 29.1.1, ESLint 9.39, TypeScript 5.9, Node 22, npm 10, Capacitor 8.5.3 (`@capacitor/core`, `@capacitor/ios`, `@capacitor/cli`, SPM). Lokalt: Xcode 27 och Docker (`/opt/homebrew/bin/docker`). Släpprepot: `macos-26` (Xcode 26).

**Spec:**
- `docs/superpowers/specs/2026-10-14-achievements-klient-design.md`, avsnittet "Klient (byggguide v3, …)" och "Ordning". Serverdelen och specens öppna fråga 1 är redan gjorda på `main` (commit `84c470c` och `21b1e9d`: `unknown`/`rejected` i svaret, trösklar bara ur `settings.achievement_thresholds`, integrationsmanualen). Se R1.
- `docs/superpowers/plans/2026-10-15-slapp-testflight.md`: Task 4 ("Kontraktet som byggguide v3 ska uppfylla", punkt 1–5), R1, R12, Task 9 (`ReleaseFactbankSyncTest`) och Task 12 steg 4–5.
- `docs/superpowers/specs/2026-10-14-faktabank-design.md`, "Underlag till byggguide v3" (tre punkter).
- `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (spike A/B och testkörningen; källan för allt om GitHub Actions, Capacitor och Apple).
- Läs också: `app/Services/BuildGuide.php`, `app/Releases/ReleaseNext.php` (`TESTFLIGHT_FLOW`), `app/Releases/ReleaseStatus.php` (var bundle-id:t står i `get_release_status`), släpprepots flöde: `gh api repos/HumblebragAB/computercat-release/contents/.github/workflows/release-ios.yml --jq .content | base64 -d`.

**Beroenden:** v2 är låst och live. Etapp 2 del 1 (TestFlight) finns på `main`: `BuildGuide::supportsIos`, `iosBlockIsValid`, `versionFromClaudeMd`, `ReleaseNext::TESTFLIGHT_FLOW`, `ReleaseFactbankSyncTest`. Släpprepots `release-ios.yml` är mergad och har bara raden `v2)` för cc-gate.

---

## Global Constraints

**Arbetssätt**
- Implementationen görs i en ny worktree med egen databas (Task 7 skapar ett lokalt spel): `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/byggguide-v3 --db`. Sista raden på stdout är sökvägen, nedan `$WT`. Kör `cd $WT && composer install` en gång (wt-start varnar för vendor). Absoluta sökvägar; huvudkatalogen rörs aldrig; `EnterWorktree` används inte.
- `$SCRATCH` = `<sessionens scratchpad-katalog>/byggguide-v3` (`mkdir -p`). Variabler som ska överleva mellan Bash-anrop skrivs till `$SCRATCH/env.sh` (`echo "NAMN=värde" >> $SCRATCH/env.sh`) och läses med `source $SCRATCH/env.sh`; skalet behåller inga variabler mellan anrop.
- Testkommandon: `cd $WT && php artisan test --filter=<Klass>`. Hela sviten körs i Task 8 och Task 10.
- **Guidens TypeScript-tester.** Varje ändring under `resources/byggguide/v3` följs av `cd $WT && php artisan computercat:byggguide:hash v3`. Snabb TDD-slinga för guidens kod: Task 1 sparar en utskriven guide med `node_modules` som `$GUIDE_DEV` (i `env.sh`); synka och kör en testfil med `rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run <fil>`. (`dot.gitignore`/`dot.env.example` hamnar som extra filer i `$GUIDE_DEV`; det stör inget.) Ändras `package.json` eller `package-lock.json`: kör `npm ci --ignore-scripts` i `$GUIDE_DEV` efter synken. Slutprovet för varje task är alltid `php artisan computercat:byggguide:verify v3` (ren katalog, npm mot registret, bara lokalt).
- Commit-meddelanden på svenska, utan Co-Authored-By-rader. Kommentarer och skriptutskrifter på svenska.
- **Ingen åtgärd mot GitHub (utöver läsning med `gh api`), Coolify, Forge, App Store Connect eller prod utan Eriks uttryckliga OK via AskUserQuestion.** Task 11 och Task 12 frågar för varje steg. Inga `.env` läses eller ändras.
- `security-first-mindset` invokeras i början av Task 5 (skript som skriver filer och startar `cap`) och i Task 10 (review gate). `completion-gate` passeras i Task 10 innan något kallas klart.
- **Gissa aldrig Capacitors, npms, Xcodes eller Coolifys beteende.** Det som inte står i bilagan verifieras i Task 0 (före all kod) eller Task 7 (före låsningen). Visar något annat än planen förutsätter: stanna och fråga Erik (AskUserQuestion).
- Radering: bara kataloger och filer som planen själv skapat, på sökvägar sparade i `env.sh`. Lokala databasrader raderas inte; worktreens databas tas bort med `wt-finish --drop-db` efter Eriks OK.

**v1, v2 och v3**
- **v1 och v2 ändras aldrig.** Inga filer under `resources/byggguide/v1` eller `v2`, och `FROZEN['v1']`/`FROZEN['v2']` står kvar. Task 10 kontrollerar `git diff main...HEAD --stat -- resources/byggguide/v1 resources/byggguide/v2` (tom).
- **`BuildGuide::LATEST` byts från `'v2'` till `'v3'` i samma commit som `FROZEN['v3']` fylls i (Task 8), aldrig tidigare.** Faktabankens `build_guide: [... v3]` kan bara skrivas efter låsningen (`FactBank` godkänner bara låsta versioner) och kommer därför i commiten efter (Task 9).
- Behöver v3 rättas efter låsningen men före deploy: töm `FROZEN['v3']` och sätt `LATEST = 'v2'` i samma commit (och ta tillbaka `v3` ur faktabankens listor i samma commit), rätta, hasha, verify, lås igen och skriv varför i commit-meddelandet. Efter deploy blir varje ändring v4.
- En publicerad version tas aldrig bort ur `resources/byggguide` (Coolify hämtar `cc-gate.mjs` per version).

**Eriks beslut (bindande)**
- v3 = achievements + Capacitor iOS i samma version (2026-10-15).
- Bundle-id `se.computercat.<slug utan bindestreck>`; `appId` i `capacitor.config.ts` kommer från spelet (Computercat), aldrig från mallen. Ett test visar att ingen guidefil hårdkodar ett bundle-id (Task 5, Task 6).
- "Skicka till TestFlight": `ReleaseNext::TESTFLIGHT_FLOW` ordagrant, på en egen rad, i v3:s `CLAUDE.md`. En push utan versionshöjning bygger aldrig appen; versionen står bara i `package.json`.
- Inga installationsskript (`npm ci --ignore-scripts`); säkerhetsspärren (`cc-gate.mjs`) och CSP:n gäller fortsatt; lint-reglerna från v2 består; v1 och v2 ändras aldrig; v3 låses med `FROZEN` och `LATEST` byts i samma commit.
- Underlaget i faktabanksspecen tas med: lint-omvägarna (`const { env } = process`, `self['process'].env`), jsdom/Node, och formuleringen om nätverk i `CLAUDE.md`.
- Efter v3: PR i `computercat-release` med raden `v3)` för cc-gate (med Eriks OK), **före deploy** (Task 11), och acceptans med ett riktigt spel (TestFlight-planens Task 12 steg 4–5) efter deploy (Task 12).

**Eriks svar 2026-10-16 (bindande)**
- Spelens Claude kör i Claude Code-molnsessioner: **Linux, utan Xcode**. `CLAUDE_RUNS=linux`. iOS-projektet måste alltså kunna skapas på Linux; det är en hård grind i Task 0 och bevisas igen i Task 7 och i acceptansen (Task 12, i en riktig molnsession).
- Acceptansspelet är ett **nytt testspel med v3 från start**, skapat med `create_game` efter deploy.
- Capacitors standardikon och startbild räcker för interna TestFlight-byggen.
- Uppgraderingen v2 → v3 ska stå i v3:s `CLAUDE.md` (R10), kort, och måste ge grön `npm run check` (provas i Task 7 steg 5).

**Rulings i denna plan (Erik ska känna till dem)**
- **R1: serverdelen av achievements-specen är redan gjord.** `AchievementController::store` svarar `{unlocked, already_unlocked, unknown, rejected}` (201), trösklar läses bara ur `settings.achievement_thresholds` (specens fråga 1, avgjord), och integrationsmanualen visar achievements när det begärs (commit `84c470c`, `21b1e9d`, testat i `AchievementTest`). Planen gör bara klienten och texterna.
- **R2: iOS-projektet skapas av ett guideskript, inte för hand.** `node scripts/ios-setup.mjs <bundle-id> "<namn>"` skriver `capacitor.config.ts` (`appId` = argumentet, `appName`, `webDir: 'out'`), kör Capacitors `cap add ios --packagemanager SPM` ur `node_modules` och lägger in `ITSAppUsesNonExemptEncryption = false`. Bundle-id:t hämtar spelets Claude ur `get_release_status`: **`targets.app_store.ios.bundle_id`** (`ReleaseStatus::forTarget`; TestFlight-planens Task 4 skriver `targets.app_store.bundle_id`, men koden lägger det under `ios`). Namnet ur `get_game` (`name`). Skriptet kontrollerar formatet men härleder aldrig id:t ur slugen: Computercat är sanningen (TestFlight-planens R12), och vid en krock väljer Erik ett annat id. `capacitor.config.ts` och `ios/` är per spel och står inte i `files[]` (kontraktets punkt 4). Skriptet körs där spelets Claude kör, dvs. i en Linux-molnsession utan Xcode; Capacitors `cap add ios` packar bara upp mallen `node_modules/@capacitor/cli/assets/ios-spm-template.tar.gz` och redigerar `project.pbxproj` och `Info.plist` (`dist/ios/add.js`, `dist/ios/common.js` `editProjectSettingsIOS`; `xcodebuild` saknas bara med en varning), vilket Task 0 visar i praktiken. Arkiveringen sker alltid på macOS i släpprepot.
- **R3: iOS är frivilligt per spel.** `scripts/check-ios.mjs` körs sist i `npm run check`; utan `ios/` skriver den att inget finns att kontrollera och avslutar med 0. Med `ios/` kontrollerar den samma sak som släpprepots byggjobb (bundle-id i `capacitor.config.ts` och `project.pbxproj`, exportdeklarationen) plus att version och byggnummer inte är hårdkodade i `Info.plist`, att `@capacitor/core`, `@capacitor/ios` och `@capacitor/cli` finns i `package.json` med exakt guidens version (`8.5.3`, konstanten `CAPACITOR` i skriptet, lika med manifestets `ios.capacitor`) och att `Package.swift` har samma, att CocoaPods inte används, att `ios/` inte innehåller några symboliska länkar (varje länk fäller; `lstatSync`), och att inga certifikat, nycklar, profiler eller `ExportOptions.plist` ligger i `ios/`. Bara exakt `ios/App/App/public` (Capacitors kopia av `out/`, ignorerad av `ios/.gitignore`) hoppas över.
- **R4: Capacitor-paketen.** `@capacitor/core` och `@capacitor/ios` i `dependencies`, `@capacitor/cli` i `devDependencies` (Capacitors installationsanvisning; cli:ns beroendeträd hamnar utanför produktionsgranskningen). Alla tre exakt `8.5.3` = manifestets `ios.capacitor`. Spikrepot `ccspike-ios` hade alla tre i `dependencies`; Task 0 visar att `npm audit --omit=dev` är ren med R4:s uppdelning.
- **R5: jsdom 29.1.1 i stället för 30.1.2.** 30.x kräver Node `^22.22.2`; Coolifys nixpacks kör 22.14.0 (v2-planen R5). 29.1.1 har motorn `^20.19.0 || ^22.13.0 || >=24.0.0` och är publicerad 2026-04-30 (`npm view jsdom@29.1.1 engines time`). `engines.node` står kvar `22.x`. Task 0 och Task 7 visar att `npm ci` på Node 22.14 inte ger någon `EBADENGINE`.
- **R6: lint-omvägarna stängs i spelets kod.** Två nya väljare gäller `src/**/*.{ts,tsx}` utom testfiler: varje referens till identifieraren `process` som inte är `process.env` direkt, och varje egenskap eller destrukturering som heter `process` (`self['process']`, `globalThis.process`, `const { process: p } = globalThis`). Testfiler (`src/**/*.test.{ts,tsx}`) läser `process.cwd()` och bakas aldrig in i `out/`; för dem gäller v2:s regler som förut. `globalThis.process.env.NODE_ENV`, som v2 släppte igenom, fäller nu (spelets kod läser `process.env.NODE_ENV` direkt). Dessutom: hakparentes med mall-literal (`` self[`process`] ``), import av modulen `process`/`node:process` i alla former (`no-restricted-imports`: `import { env } from 'process'`, `import * as p from 'node:process'`), `require('process')` och `import('node:process')`. `ios/**` läggs till i `globalIgnores` (Capacitor kopierar den byggda exporten till `ios/App/App/public`).
- **R7: nätverk i `CLAUDE.md`.** "Bygget ska klara sig utan nätverk" blir: `npm run build` ska klara sig utan nätverk; `npm run check` och `npm audit` behöver nätverk eftersom granskningen hämtar sårbarhetsdata från npm-registret.
- **R8: achievements-kön.** Kön är en lista med slugs under `computercat:<spel>:achievement-queue`, utan dubbletter. Ett svar från servern tar bort hela omgången ur kön (upplåsta, redan upplåsta, `unknown` och `rejected`; specen: ett nytt försök med `rejected` ger samma svar). Tillfälliga fel (nätverk, 5xx, 401, 408, 429) lämnar omgången och avbryter tömningen; andra fel tar bort omgången och lägger den i `dropped` med skälet (samma regel som resultatkön: `dropReason` i `queue.ts`, som nu exporteras). Går en omgång inte att ta bort ur lagringen avbryts tömningen med ett fel i konsolen, i stället för att samma omgång skickas om och om igen. En slug kontrolleras strikt mot serverns `SlugRule::PATTERN` (`^[a-z0-9][a-z0-9_.-]*$`) och `AchievementRules` (högst 50 tecken); allt annat ger `RangeError` direkt. Känd gräns: en äldre definition vars slug inte följer mönstret (SlugRule godkänner oförändrade äldre värden) kan inte rapporteras från v3-klienten; ingen sådan är känd (Toccos slugs följer mönstret).
- **R9: `flushAll()`** tömmer resultatkön och sedan achievementkön, i följd, var och en i eget `try`: kastar resultatkön töms achievementkön ändå, och därefter avvisas `flushAll` med det första felet (lyssnarna anropas bara när båda gick igenom). Annars ger den `{ scores, achievements }`. `onFlushed(cb)` får samma resultat efter varje `flushAll` och returnerar en funktion som avslutar prenumerationen. En lyssnare som kastar loggas och stoppar inte de andra. `BackgroundSync` anropar `flushAll` i stället för `queue.flush`.
- **R10: uppgradering från v2.** v3:s `CLAUDE.md` har avsnittet "Uppgradera ett v2-spel till v3" (behövs för acceptansspelet och Tinker Isle): en oförändrad guidefil (samma sha256 som i v2:s manifest) byts mot v3:s; en fil som spelet ändrat behålls och v3:s ändring förs in för hand; `package.json` slås ihop. Listan över filer som v3 lägger till eller ändrar står i `CLAUDE.md` och ett test jämför den med manifesten. v1 → v3: fråga en människa (specens "Utanför scope"). Avsnittet är kort och provas i Task 7 steg 5 på en riktig v2-utskrift, som efter uppgraderingen ska ge grön `npm run check`. Notera (ofarligt nu): ett uppgraderat v2-spels Coolify-app behåller byggkommandot från när den skapades och hämtar `cc-gate.mjs` från `/api/v1/build-guide/v2/…`; filen är byte för byte densamma som v3:s (R11). Skiljer sig spärren i en framtida version måste Coolify-kommandot för uppgraderade spel bytas; det är en sak för den versionens plan.
- **R11: spärren är oförändrad.** `scripts/cc-gate.mjs`, `scripts/check-export.mjs` och `src/lib/computercat/csp.ts` är byte för byte v2:s (test i Task 1). cc-gate:s sha256 för v3 blir därmed v2:s `e4460881ca534a085c500bb0c768e4ea9a1d6c7d7c2f33babf4e7ef8c8969009`, men släpprepot behöver ändå raden `v3)` (flödet väljer på versionsnamnet). PR:en läggs efter låsningen och **före deploy** (Task 11), så att första TestFlight-släppet av ett v3-spel aldrig faller på en saknad rad; värdet läses ur det låsta manifestet, skrivs inte av. Raden är ofarlig innan v3 är deployad: Computercat dispatchar bara versioner som `supportsIos` godkänner i prod.
- **R12: `publicManifest` ändras inte.** `ios`-blocket visas inte i `get_build_guide`; spelets Claude styrs av `CLAUDE.md`, och Computercat läser blocket med `BuildGuide::supportsIos`.
- **R13: exemplet (klickrace) ändras inte.** Ett nytt spel har inga achievement-definitioner, så ett exempel skulle bara visa `unknown`. Användningen visas med kod i `CLAUDE.md` och i faktabanken.
- **R14: appnamnet** (`get_game` → `name`) får vara 1–50 tecken efter trim, utan styrtecken och utan `<`, `>` och `&` (det skrivs av Capacitor in i `Info.plist` som `CFBundleDisplayName`). Ett namn som inte går: `ios-setup` säger det, och spelets Claude frågar en människa. (Capacitor skriver namnet oescapat: `<string>${appName}</string>` i `dist/ios/common.js`.)
- **R15: Linux-grinden och reservvägen B, bestämd i förväg.** Väg A (planens standard): `ios-setup` kör `node_modules/.bin/cap add ios --packagemanager SPM`. Task 0 steg 5 är en hård grind: A ska ge ett `ios/` på Linux (Node 22, ingen Xcode) som sedan arkiveras utan ändring på macOS (Task 0 steg 6). Fäller A på Linux men allt annat fungerar gäller **väg B**, med exakt detta innehåll: `ios-setup` gör själv det `cap add ios` gör, utan Capacitors CLI-steg: `tar -xzf node_modules/@capacitor/cli/assets/ios-spm-template.tar.gz -C ios` (spawnSync, ingen skal), ersätter varje `PRODUCT_BUNDLE_IDENTIFIER = …;` i `ios/App/App.xcodeproj/project.pbxproj` med bundle-id:t och `CFBundleDisplayName` i `ios/App/App/Info.plist` med namnet (samma reguljära uttryck som Capacitors `editProjectSettingsIOS`), och kör sedan `node_modules/.bin/cap sync ios` (som skriver `Package.swift`). Koden står färdig i Task 5 steg 3b och har samma tester (via `addIos`). Väg B provas i Task 0 steg 5 bara om A fäller. Fäller båda: stanna och fråga Erik; ingen tredje väg improviseras.

**Värden**
- Bundle-id: `^se\.computercat\.[a-z][a-z0-9]{1,39}$` (samma som `BundleId` och släpprepots "Validera indata").
- Version: `^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$`; `package.json` börjar på `0.1.0`.
- Manifestets block: `"ios": {"capacitor": "8.5.3", "package_manager": "spm", "project": "ios/App/App.xcodeproj", "scheme": "App"}`.
- `Package.swift` efter `cap add`/`cap sync`: `.package(url: "https://github.com/ionic-team/capacitor-swift-pm.git", exact: "8.5.3")` (spikrepot).
- Lagringsnycklar: `computercat:<spel>:score-queue` (oförändrad), `computercat:<spel>:achievement-queue` (ny).
- Achievements: slug 1–50 tecken (`AchievementRules`), högst 10 slugs per `POST /games/{slug}/achievements` (`AchievementController::store`).
- v3 har 59 filer: v2:s 54 plus `src/lib/computercat/achievements.ts`, `achievements.test.ts`, `scripts/check-ios.mjs`, `scripts/ios-setup.mjs`, `test/iosSetup.test.ts`.

---

## Review Focus

De fem fel som lätt passerar oupptäckta. Varje rad har ett test i den task som äger koden.

1. **Appen får fel bundle-id.** Ett id hårdkodat i en guidefil, härlett ur slugen, felskrivet, med bindestreck, eller ett Xcode-projekt vars `PRODUCT_BUNDLE_IDENTIFIER` inte stämmer med `capacitor.config.ts` (släpprepot fäller då först på macOS efter flera minuter). Test: `test/iosSetup.test.ts` (`capacitorConfigSource` skriver argumentet och inget annat id; ogiltiga id vägras; `checkIos` fäller på annat och dubblerat id; `setupIos` skriver konfigurationen före `cap add`) (Task 5); `BuildGuideV3Test::test_no_guide_file_hardcodes_a_bundle_id_and_ios_files_are_per_game` (Task 5).
2. **En upplåsning försvinner tyst eller fastnar.** Full lagring, en felstavad slug som aldrig syns (eller en slug i fel form som servern aldrig kan ha), resultatkön som kastar och stoppar achievementkön, en avvisad som tolkas som upplåst, en 422 som blockerar kön för alltid, en omgång som skickas i en evig loop när lagringen vägrar skriva, två flikar eller två samtidiga tömningar. Test: `achievements.test.ts` (Task 3, bland annat `SlugRule`-mönstret), `index.test.ts` (`flushAll`, också när resultatkön kastar, Task 4).
3. **"Skicka till TestFlight" glider isär eller versionen sätts på fel ställe.** Flödestexten i `CLAUDE.md` avviker från `ReleaseNext::TESTFLIGHT_FLOW`, eller version/byggnummer hårdkodas i `Info.plist` så att appen inte bär `package.json`:s version. Test: `ReleaseFactbankSyncTest::test_testflight_flow_is_verbatim_in_the_article_the_next_text_and_every_ios_build_guide` (nu med minst en kontrollerad version), `BuildGuideV3Test::test_claude_md_is_v3_and_has_achievements_ios_and_the_testflight_flow` (Task 6), `checkIos` för `CFBundleShortVersionString`/`CFBundleVersion` (Task 5).
4. **iOS-projektet går inte att skapa där spelets Claude kör (Linux, ingen Xcode), eller Demo-bygget i Coolify faller för v3.** Capacitor-paketen i installationen, `ios/` i repot, jsdom och Node 22.14, `npm audit --omit=dev` med nya produktionsberoenden. Går inte att enhetstesta; Task 0 steg 5–6 är en hård grind (Linux-skapat `ios/` arkiverat oförändrat på Mac), Task 7 steg 1–2 och 4 upprepar det med de riktiga v3-filerna, och Task 12 steg 3 gör det i en riktig molnsession.
5. **En omväg förbi lint-reglerna släpps igenom, eller en giltig testfil fälls.** `const { env } = process`, `self['process'].env`, `` self[`process`] ``, alias, `globalThis['process']`, `process` som argument, `import … from 'process'`/`'node:process'`, `require('process')`, `import('node:process')`; `process.cwd()` i en testfil ska gå igenom. Test: `test/eslintRules.test.ts` (Task 2).

---

## Filstruktur

| Fil | Ansvar |
|---|---|
| `resources/byggguide/v3/**` (ny, kopia av v2) | byggguide v3: manifest med `ios`-block, filer, README-mall |
| `resources/byggguide/v3/files/src/lib/computercat/achievements.ts` (+ `.test.ts`) | achievements: `list`, `mine`, `report` med kö och omgångar, `flush` |
| `resources/byggguide/v3/files/src/lib/computercat/queue.ts` (ändrad) | `dropReason` exporteras |
| `resources/byggguide/v3/files/src/lib/computercat/index.ts` (+ `index.test.ts`, ändrade) | `achievements`, `flushAll`, `onFlushed`, typexporter |
| `resources/byggguide/v3/files/src/components/BackgroundSync.tsx` (ändrad) | anropar `flushAll` |
| `resources/byggguide/v3/files/scripts/check-ios.mjs` | kontroll av spelets iOS-projekt, delade hjälpfunktioner |
| `resources/byggguide/v3/files/scripts/ios-setup.mjs` | skapar iOS-projektet en gång |
| `resources/byggguide/v3/files/test/iosSetup.test.ts` | tester för de två skripten |
| `resources/byggguide/v3/files/eslint.config.mjs`, `test/eslintRules.test.ts` (ändrade) | lint-omvägarna, `ios/**` |
| `resources/byggguide/v3/files/package.json`, `package-lock.json` (ändrade) | Capacitor, jsdom 29.1.1, `check-ios` i `check` |
| `resources/byggguide/v3/files/CLAUDE.md`, `templates/README.md`, `test/docs.test.ts` (ändrade) | texterna |
| `tests/Feature/BuildGuideV3Test.php` (ny) | v3:s innehåll och kontrakt |
| `tests/Feature/ReleaseFactbankSyncTest.php` (ändras) | loopen ska ha kontrollerat minst en version |
| `app/Services/BuildGuide.php`, `tests/Feature/BuildGuideTest.php` (ändras i Task 8) | `LATEST`, `FROZEN['v3']`, `EXPECTED['v3']`, v3 stöder iOS |
| `resources/faktabank/**` (ändras och två nya, Task 9) | `build_guide` med v3, achievements, iOS |
| `docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (ändras) | spikresultat Task 0 och Task 7 |

---

### Task 0: Spike — Capacitor och jsdom där spelen byggs (före all kod)

**Files:**
- Modify: `$WT/docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` (nytt avsnitt sist)
- Scratch (committas inte): `$SCRATCH/spike0/`

**Interfaces:**
- Consumes: låsta v2, `computercat:byggguide:verify --keep`.
- Produces: belagda fakta som Task 1–7 bygger på: (a) **hård grind:** `cap add ios` (väg A, annars väg B, R15) ger på Linux (Node 22.14, ingen Xcode) ett `ios/` som arkiveras oförändrat på macOS, och vilken väg som gäller (`IOS_ADD_WAY=A|B` i `env.sh`); (b) `node_modules/.bin/cap add ios --packagemanager SPM` på macOS: skapade filer, `PRODUCT_BUNDLE_IDENTIFIER`, `Info.plist`:s slut, `Package.swift`, om `out/` krävs; (c) osignerad arkivering lokalt med v2 + Capacitor; (d) `npm ci` på Node 22.14 utan `EBADENGINE` med jsdom 29.1.1; (e) `npm audit --omit=dev --audit-level=high` ren med R4:s uppdelning.

Inget skrivs mot GitHub, Coolify eller Apple. Nätverk används bara för npm-registret, Docker Hub (`node:22.14.0`) och Swift-paketet från GitHub (läsning, i Xcode).

- [ ] **Steg 1: Förutsättningar.** Eriks svar 2026-10-16: spelens Claude kör i Linux-molnsessioner utan Xcode. `mkdir -p $SCRATCH && echo "CLAUDE_RUNS=linux" >> $SCRATCH/env.sh`. Docker måste fungera (`command -v docker && docker info >/dev/null 2>&1 && echo docker-ok`); gör det inte: fråga Erik (AskUserQuestion: "Docker behövs för Linux-grinden i Task 0. Starta Docker Desktop/OrbStack nu?") och vänta. Linux-delen hoppas aldrig över.

- [ ] **Steg 2: v2 plus Capacitor och jsdom 29.1.1.**

```bash
mkdir -p $SCRATCH/spike0 && cd $WT && KEEP0=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "KEEP0=$KEEP0" >> $SCRATCH/env.sh
cd "$KEEP0" && npm install --save-exact --ignore-scripts @capacitor/core@8.5.3 @capacitor/ios@8.5.3 \
  && npm install --save-exact --save-dev --ignore-scripts @capacitor/cli@8.5.3 jsdom@29.1.1
npm ls jsdom @capacitor/cli @capacitor/core @capacitor/ios --depth=0
NEXT_PUBLIC_COMPUTERCAT_GAME=byggguide-verify npm run check 2>&1 | tail -4; echo "check=$?"
npm audit --omit=dev --audit-level=high; echo "audit=$?"
ls node_modules/.bin/cap && node -p "require('./node_modules/@capacitor/cli/package.json').bin"
```

Förväntat: de fyra paketen i exakt rätt version, `check=0` med `check-security: OK` och `cc-audit: OK`, `audit=0`, och `bin` har `cap`. Röd test i `test/integration.test.tsx` eller annat med jsdom 29: stanna och fråga.

- [ ] **Steg 3: `cap add ios` på macOS.**

```bash
cd "$KEEP0" && mv out out.bak && printf '%s\n' "import type { CapacitorConfig } from '@capacitor/cli';" "" "const config: CapacitorConfig = {" '  appId: "se.computercat.test",' '  appName: "Spike v3",' "  webDir: 'out'," "};" "" "export default config;" > capacitor.config.ts
node_modules/.bin/cap add ios --packagemanager SPM; echo "utan-out=$?"; rm -rf ios
mv out.bak out && node_modules/.bin/cap add ios --packagemanager SPM; echo "cap-add=$?"
find ios -type f -not -path '*/public/*' | sort
grep -c 'PRODUCT_BUNDLE_IDENTIFIER = se.computercat.test;' ios/App/App.xcodeproj/project.pbxproj
grep -E 'PRODUCT_BUNDLE_IDENTIFIER|MARKETING_VERSION|CURRENT_PROJECT_VERSION' ios/App/App.xcodeproj/project.pbxproj | sort | uniq -c
tail -4 ios/App/App/Info.plist; grep -A1 -E 'CFBundleShortVersionString|CFBundleVersion|CFBundleDisplayName' ios/App/App/Info.plist
grep capacitor-swift-pm ios/App/CapApp-SPM/Package.swift; cat ios/.gitignore
ls ios/App/App/capacitor.config.json ios/App/App/public/index.html
```

Förväntat (det Task 5 bygger på): `utan-out` ≠ 0 (Capacitor kräver `out/`; om 0, notera det, kontrollen i `ios-setup` behålls ändå), `cap-add=0`, filerna som i spikrepot `ccspike-ios` (utan `Package.resolved`), `PRODUCT_BUNDLE_IDENTIFIER = se.computercat.test;` på alla rader, `Info.plist` slutar med `</dict>\n</plist>`, `CFBundleShortVersionString`/`CFBundleVersion` = `$(MARKETING_VERSION)`/`$(CURRENT_PROJECT_VERSION)`, `CFBundleDisplayName` = `Spike v3`, `Package.swift` har raden från Värden, `ios/.gitignore` ignorerar `App/App/public` och `App/App/capacitor.config.json`. Något annat: stanna och fråga.

- [ ] **Steg 4: osignerad arkivering lokalt, som släpprepots byggjobb.**

```bash
cd "$KEEP0" && node -e "
const fs=require('fs');const p='ios/App/App/Info.plist';const t=fs.readFileSync(p,'utf8');const i=t.lastIndexOf('</dict>');
fs.writeFileSync(p,t.slice(0,i)+'\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n'+t.slice(i));"
node_modules/.bin/cap sync ios && cd ios/App && xcodebuild -version | head -1
xcodebuild -project App.xcodeproj -scheme App -configuration Release -destination 'generic/platform=iOS' \
  -archivePath "$SCRATCH/spike0/App.xcarchive" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO \
  CURRENT_PROJECT_VERSION=3 MARKETING_VERSION=0.1.0 archive | tail -3
for K in CFBundleIdentifier CFBundleShortVersionString CFBundleVersion ITSAppUsesNonExemptEncryption; do
  echo "$K=$(/usr/libexec/PlistBuddy -c "Print :$K" "$SCRATCH/spike0/App.xcarchive/Products/Applications/App.app/Info.plist")"; done
```

Förväntat: `** ARCHIVE SUCCEEDED **`, `se.computercat.test`, `0.1.0`, `3`, `false`.

- [ ] **Steg 5: Linux-grinden (hård): `ios/` skapas på Linux med Coolifys Node och miljö.** Källan monteras skrivskyddad och en tom, skrivbar utkatalog tar emot resultatet:

```bash
source $SCRATCH/env.sh && mkdir -p $SCRATCH/spike0/linux $SCRATCH/spike0/linux-out && rm -rf $SCRATCH/spike0/linux-out/* \
  && rsync -a --exclude node_modules --exclude ios --exclude out --exclude .next "$KEEP0/" $SCRATCH/spike0/linux/src/
docker run --rm -e NODE_ENV=production -e NPM_CONFIG_PRODUCTION=false -e NEXT_TELEMETRY_DISABLED=1 -e NEXT_PUBLIC_COMPUTERCAT_GAME=byggguide-verify \
  -v $SCRATCH/spike0/linux/src:/src:ro -v $SCRATCH/spike0/linux-out:/out node:22.14.0 bash -c '
  cp -R /src /w && cd /w && node -v && npm -v && (command -v xcodebuild || echo "ingen xcodebuild")
  npm ci --ignore-scripts > /tmp/ci.log 2>&1; echo "ci=$? EBADENGINE=$(grep -c EBADENGINE /tmp/ci.log)"
  npm run build > /tmp/build.log 2>&1; echo "build=$?"; node scripts/cc-gate.mjs; echo "gate=$?"
  node_modules/.bin/cap add ios --packagemanager SPM > /tmp/cap.log 2>&1; echo "cap-add-linux=$?"; tail -8 /tmp/cap.log
  grep -c "PRODUCT_BUNDLE_IDENTIFIER = se.computercat.test;" ios/App/App.xcodeproj/project.pbxproj
  grep capacitor-swift-pm ios/App/CapApp-SPM/Package.swift
  cp -R ios capacitor.config.ts /out/ && cp /tmp/cap.log /out/cap-a.log'
ls $SCRATCH/spike0/linux-out
```

Förväntat: `v22.14.0`, `ingen xcodebuild`, `ci=0 EBADENGINE=0`, `build=0`, `check-security: OK` och `cc-audit: OK`, `gate=0`, `cap-add-linux=0`, bundle-id:t på alla `PRODUCT_BUNDLE_IDENTIFIER`-rader, `Package.swift` med `exact: "8.5.3"`, och `ios` plus `capacitor.config.ts` i utkatalogen. Då: `echo "IOS_ADD_WAY=A" >> $SCRATCH/env.sh` och vidare till steg 6.

Är `cap-add-linux` ≠ 0 (eller saknas filerna): prova väg B (R15) i samma uppsättning, med en tom utkatalog:

```bash
rm -rf $SCRATCH/spike0/linux-out/* && docker run --rm -e NODE_ENV=production -e NPM_CONFIG_PRODUCTION=false -e NEXT_TELEMETRY_DISABLED=1 -e NEXT_PUBLIC_COMPUTERCAT_GAME=byggguide-verify \
  -v $SCRATCH/spike0/linux/src:/src:ro -v $SCRATCH/spike0/linux-out:/out node:22.14.0 bash -c '
  cp -R /src /w && cd /w && npm ci --ignore-scripts > /dev/null 2>&1 && npm run build > /dev/null 2>&1
  mkdir ios && tar -xzf node_modules/@capacitor/cli/assets/ios-spm-template.tar.gz -C ios; echo "tar=$?"
  sed -i -E "s/PRODUCT_BUNDLE_IDENTIFIER = [^;]+;/PRODUCT_BUNDLE_IDENTIFIER = se.computercat.test;/g" ios/App/App.xcodeproj/project.pbxproj
  node_modules/.bin/cap sync ios > /tmp/sync.log 2>&1; echo "sync-linux=$?"; tail -8 /tmp/sync.log
  grep -c "PRODUCT_BUNDLE_IDENTIFIER = se.computercat.test;" ios/App/App.xcodeproj/project.pbxproj; grep capacitor-swift-pm ios/App/CapApp-SPM/Package.swift
  cp -R ios capacitor.config.ts /out/'
```

Grönt (`tar=0`, `sync-linux=0`, id och `Package.swift` som ovan): `echo "IOS_ADD_WAY=B" >> $SCRATCH/env.sh`; Task 5 använder då steg 3b. Fäller också B: stanna och fråga Erik (AskUserQuestion) med loggraderna; planen fortsätter inte.

- [ ] **Steg 6: Linux-`ios/` arkiveras oförändrat på macOS (andra halvan av grinden).** Exakt det `ios/` och den `capacitor.config.ts` som containern skrev ersätter Mac-katalogens; bara `cap sync` (som släpprepots byggjobb kör) och arkiveringen körs på Mac:

```bash
source $SCRATCH/env.sh && cd "$KEEP0" && rm -rf ios && cp -R $SCRATCH/spike0/linux-out/ios ios && cp $SCRATCH/spike0/linux-out/capacitor.config.ts capacitor.config.ts
node -e "
const fs=require('fs');const p='ios/App/App/Info.plist';const t=fs.readFileSync(p,'utf8');if(!t.includes('ITSAppUsesNonExemptEncryption')){const i=t.lastIndexOf('</dict>');
fs.writeFileSync(p,t.slice(0,i)+'\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n'+t.slice(i));}"
npx cap sync ios && cd ios/App
xcodebuild -project App.xcodeproj -scheme App -configuration Release -destination 'generic/platform=iOS' \
  -archivePath "$SCRATCH/spike0/App-linux.xcarchive" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO \
  CURRENT_PROJECT_VERSION=5 MARKETING_VERSION=0.1.0 archive | tail -2
for K in CFBundleIdentifier CFBundleShortVersionString CFBundleVersion ITSAppUsesNonExemptEncryption; do
  echo "$K=$(/usr/libexec/PlistBuddy -c "Print :$K" "$SCRATCH/spike0/App-linux.xcarchive/Products/Applications/App.app/Info.plist")"; done
```

Förväntat: `** ARCHIVE SUCCEEDED **`, `se.computercat.test`, `0.1.0`, `5`, `false`. Annat: stanna och fråga Erik. (Exportdeklarationen läggs in här för hand; i v3 gör `ios-setup` det.)

- [ ] **Steg 7: Bilagan och städning.** Lägg till sist i bilagan:

```markdown
## Byggguide v3: Capacitor och jsdom lokalt (<datum>)

Spelens Claude kör i Linux-molnsessioner utan Xcode (Erik 2026-10-16). v2-grundkoden plus `@capacitor/core`/`@capacitor/ios` 8.5.3 (dependencies), `@capacitor/cli` 8.5.3 och jsdom 29.1.1 (devDependencies).

- `npm run check` grön; `npm audit --omit=dev --audit-level=high` ren.
- `cap add ios --packagemanager SPM` på macOS: <exitkod>; utan `out/`: <exitkod och felraden>. `PRODUCT_BUNDLE_IDENTIFIER` = appId på <n> rader; `Info.plist` med `$(MARKETING_VERSION)`/`$(CURRENT_PROJECT_VERSION)`, utan exportdeklaration (läggs in av skriptet); `Package.swift` med `exact: "8.5.3"`.
- Osignerad arkivering med <Xcode-version>: <utfall>; arkivets Info.plist: <fyra värden>.
- Node 22.14.0 i Docker med `NODE_ENV=production` och `NPM_CONFIG_PRODUCTION=false`: `npm ci --ignore-scripts` <utfall>, `EBADENGINE` <antal>, bygge och `cc-gate.mjs` <utfall>.
- Linux-grinden: väg <A|B> (R15). `cap add ios` på Linux <utfall och relevanta loggrader>; det Linux-skapade `ios/` arkiverades oförändrat på macOS med <Xcode-version>: <utfall och fyra värden>.
```

Fyll i varje `<…>`; `sed -n '/^## Byggguide v3: Capacitor och jsdom lokalt/,$p' $WT/docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md | grep -c '<'` ger `0`. Städa bara det spiken skapade: `rm -rf "$KEEP0" "$SCRATCH/spike0"`.

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md && git commit -m "Bilaga: Capacitor och jsdom inför byggguide v3 (lokalt och i Node 22.14)"
```

---

### Task 1: v3-katalogen — kopia av v2, beroenden och första testerna

**Files:**
- Create: `resources/byggguide/v3/` (kopia av `resources/byggguide/v2/`)
- Modify (i v3): `manifest.json`, `files/package.json`, `files/package-lock.json`
- Create: `tests/Feature/BuildGuideV3Test.php`

**Interfaces:**
- Consumes: Task 0:s resultat (R4, R5).
- Produces: en olåst, hashad v3 som `verify v3` godkänner; `$GUIDE_DEV` (i `env.sh`) för TDD-slingan; `BuildGuideV3Test` som Task 2–6 bygger ut.

- [ ] **Steg 1: Fallerande test.** Skapa `tests/Feature/BuildGuideV3Test.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\BuildGuide;
use Tests\TestCase;

// Byggguide v3 (plan 2026-10-16-byggguide-v3): achievements i klienten och Capacitor iOS.
// Läser katalogen även innan v3 är låst; BuildGuide::manifest kräver ingen låsning.
class BuildGuideV3Test extends TestCase
{
    private function package(): array
    {
        return json_decode(BuildGuide::file('v3', 'package.json')['content'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_manifest_is_v3_and_commits_as_v3(): void
    {
        $m = BuildGuide::manifest('v3');

        $this->assertSame('v3', $m['version']);
        $this->assertStringContainsString('Grundkod från byggguide v3', implode("\n", $m['steps']));
        $this->assertStringNotContainsString('v2', json_encode($m['steps'], JSON_UNESCAPED_UNICODE));
    }

    // R4, R5: Capacitor exakt, jsdom som klarar Coolifys Node 22.14, version att höja.
    public function test_capacitor_is_pinned_and_jsdom_runs_on_coolifys_node(): void
    {
        $p = $this->package();

        $this->assertSame('8.5.3', $p['dependencies']['@capacitor/core']);
        $this->assertSame('8.5.3', $p['dependencies']['@capacitor/ios']);
        $this->assertSame('8.5.3', $p['devDependencies']['@capacitor/cli']);
        $this->assertArrayNotHasKey('@capacitor/cli', $p['dependencies']);
        $this->assertSame('29.1.1', $p['devDependencies']['jsdom']);
        $this->assertSame('0.1.0', $p['version']);
        $this->assertSame('22.x', $p['engines']['node']);
        $this->assertSame($p['dependencies'] + $p['devDependencies'], BuildGuide::manifest('v3')['stack']);
    }

    // R11: spärren och policyn är v2:s, byte för byte.
    public function test_the_gate_and_the_policy_are_unchanged_from_v2(): void
    {
        foreach (['scripts/cc-gate.mjs', 'scripts/check-export.mjs', 'src/lib/computercat/csp.ts'] as $path) {
            $this->assertSame(BuildGuide::file('v2', $path)['sha256'], BuildGuide::file('v3', $path)['sha256'], $path);
        }
    }
}
```

Run: `cd $WT && php artisan test --filter=BuildGuideV3Test`
Expected: FAIL med `Unknown build guide version.`

- [ ] **Steg 2: Kopiera v2 och skriv v3-manifestets fasta fält.**

```bash
cd $WT/resources/byggguide && cp -R v2 v3 && cd v3 && jq '
  .version = "v3"
  | .source = "byggguide v2 plus achievements i klienten och Capacitor iOS (byggguide v3)"
  | .steps = [.steps[] | gsub("byggguide v2"; "byggguide v3")]
  | .server_templates = {}
  | .files = []' manifest.json > manifest.tmp && mv manifest.tmp manifest.json
jq -r '.version, .steps[3]' manifest.json
git -C $WT status --short resources/byggguide/v1 resources/byggguide/v2     # förväntat: ingen utskrift
```

Förväntat: `v3` och `Commit as "Grundkod från byggguide v3" and push directly to main. Coolify builds the Demo on that push.`

- [ ] **Steg 3: Beroendena (R4, R5) och låsfilen.**

```bash
cd $WT/resources/byggguide/v3/files && jq '
  .dependencies += {"@capacitor/core": "8.5.3", "@capacitor/ios": "8.5.3"}
  | .devDependencies += {"@capacitor/cli": "8.5.3", "jsdom": "29.1.1"}
  | .dependencies |= (to_entries | sort_by(.key) | from_entries)
  | .devDependencies |= (to_entries | sort_by(.key) | from_entries)' package.json > package.tmp && mv package.tmp package.json
npm install --package-lock-only --ignore-scripts
git -C $WT status --short resources/byggguide/v3/files     # bara package.json och package-lock.json (ny katalog: allt är ospårat; kontrollera att inga node_modules skapades)
ls node_modules 2>/dev/null && echo "STOPP: node_modules skapades"
cd .. && jq --slurpfile p files/package.json '.stack = ($p[0].dependencies + $p[0].devDependencies)' manifest.json > manifest.tmp && mv manifest.tmp manifest.json
jq -c '.stack | {"@capacitor/core", "@capacitor/ios", "@capacitor/cli", jsdom}' manifest.json
```

Förväntat: `{"@capacitor/core":"8.5.3","@capacitor/ios":"8.5.3","@capacitor/cli":"8.5.3","jsdom":"29.1.1"}`.

- [ ] **Steg 4: Hasha och kör testerna.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest'
```

Förväntat: `54 filer i v3.` och PASS (`test_every_file_matches_its_sha256_and_nothing_is_unlisted` går igenom v3).

- [ ] **Steg 5: Guiden på riktigt, och TDD-katalogen.**

```bash
cd $WT && GUIDE_DEV=$(php artisan computercat:byggguide:verify v3 --keep | tee /dev/stderr | sed -n 's/^Katalogen behålls: //p') && echo "GUIDE_DEV=$GUIDE_DEV" >> $SCRATCH/env.sh
```

Förväntat: `Byggguide v3: OK (54 filer, check, audit, static_export).` och en rad `GUIDE_DEV=…` i `env.sh`.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add resources/byggguide/v3 tests/Feature/BuildGuideV3Test.php && git commit -m "Byggguide v3 (olåst): kopia av v2 med Capacitor 8.5.3 och jsdom 29.1.1"
```

---

### Task 2: Lint — omvägarna till `process.env` och `ios/**`

**Files:**
- Modify: `resources/byggguide/v3/files/eslint.config.mjs`
- Modify: `resources/byggguide/v3/files/test/eslintRules.test.ts`

**Interfaces:**
- Consumes: `$GUIDE_DEV` (Task 1).
- Produces: väljarna `PROCESS_REFERENCE`, `PROCESS_PROPERTY`, `PROCESS_MODULE_CALL` och `no-restricted-imports` för `process`/`node:process` (R6) i spelets kod utom testfiler; `ios/**` ignoreras.

- [ ] **Steg 1: Fallerande tester.** I `resources/byggguide/v3/files/test/eslintRules.test.ts`, ersätt `// Byggguide v2: säkerhetsreglerna` med `// Byggguide v3: säkerhetsreglerna`, och lägg till efter raden `    ['dangerouslySetInnerHTML med hakparentes', …],` (sista raden i `it.each`-listan):

```ts
    // v3 (faktabanksspecen, "Underlag till byggguide v3"): omvägar som v2 släppte igenom.
    ['destrukturering av env ur process', 'const { env } = process;\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ["self['process'].env", "export const k = self['process'].env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["globalThis['process'].env", "export const k = globalThis['process'].env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ['alias av process', 'const p = process;\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['process ur globalThis med destrukturering', 'const { process: p } = globalThis;\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ['process som argument', 'const read = (o: { env: Record<string, string | undefined> }) => o.env.NEXT_PUBLIC_STRIPE_KEY;\nexport const k = read(process);\n', 'no-restricted-syntax'],
    ['globalThis.process.env.NODE_ENV', 'export const n = globalThis.process.env.NODE_ENV;\n', 'no-restricted-syntax'],
    ['self[`process`] med mall-literal', 'export const k = self[`process`].env.NEXT_PUBLIC_STRIPE_KEY;\n', 'no-restricted-syntax'],
    ["import { env } from 'process'", "import { env } from 'process';\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import { env } from 'node:process'", "import { env } from 'node:process';\nexport const k = env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import * as p from 'process'", "import * as p from 'process';\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["import p from 'node:process'", "import p from 'node:process';\nexport const k = p.env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-imports'],
    ["require('process')", "export const k = require('process').env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["require('node:process')", "export const k = require('node:process').env.NEXT_PUBLIC_STRIPE_KEY;\n", 'no-restricted-syntax'],
    ["import('node:process')", "export const k = import('node:process').then((m) => m.env.NEXT_PUBLIC_STRIPE_KEY);\n", 'no-restricted-syntax'],
```

Lägg till ett nytt test sist i `describe`-blocket:

```ts
  it('testfiler får läsa process.cwd() (de bakas aldrig in i out/)', async () => {
    const [result] = await eslint.lintText("import { resolve } from 'node:path';\nexport const root = resolve(process.cwd(), 'src');\n", {
      filePath: resolve(process.cwd(), 'src/lib/computercat/config.test.ts'),
    });
    const rules = result.messages.map((m) => m.ruleId ?? `parse: ${m.message}`);
    expect(rules.filter((r) => r.startsWith('parse:'))).toEqual([]);
    expect(rules).not.toContain('no-restricted-syntax');
  }, 30_000);
```

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run test/eslintRules.test.ts`
Expected: FAIL för de nya fallen. Fall som v2:s `ENV_OBJECT` redan fångade (till exempel `globalThis['process'].env`) går igenom redan nu; notera vilka, testet ska ändå finnas. Kontrollera efter steg 2 att varje nytt fall fäller av rätt regel och inte av ett parsningsfel (testet kräver noll `parse:`-rader).

- [ ] **Steg 2: Reglerna.** I `resources/byggguide/v3/files/eslint.config.mjs`:

1. Byt `(byggguide v2)` mot `(byggguide v3)` i alla meddelanden och första kommentaren: `sed -i '' 's/byggguide v2/byggguide v3/g' $WT/resources/byggguide/v3/files/eslint.config.mjs && grep -c 'byggguide v2' $WT/resources/byggguide/v3/files/eslint.config.mjs` (förväntat `0`).
2. Lägg till efter `DANGEROUS_HTML_MEMBER`-konstanten:

```js
// v3 (R6): i spelets kod (utom testfiler) får identifieraren process bara stå som process.env, och
// ingen egenskap eller destrukturering får heta process. Stänger const { env } = process,
// const p = process, self['process'], globalThis.process och { process: p } = globalThis.
const PROCESS_MESSAGE =
  'process får bara användas som process.env.NODE_ENV, process.env.NEXT_PUBLIC_COMPUTERCAT_GAME eller process.env.NEXT_PUBLIC_COMPUTERCAT_URL i spelets kod (byggguide v3). Inga alias, ingen destrukturering och inga omvägar via globalThis, self eller window.';
const PROCESS_REFERENCE = {
  selector:
    "Identifier[name='process']:not(MemberExpression[computed=false][property.name='env'] > .object):not(MemberExpression[computed=false] > .property):not(Property[shorthand=false] > .key)",
  message: PROCESS_MESSAGE,
};
const PROCESS_PROPERTY = {
  selector:
    "MemberExpression[computed=false][property.name='process'], MemberExpression[computed=true][property.value='process'], MemberExpression[computed=true][property.type='TemplateLiteral'][property.quasis.0.value.cooked='process'], ObjectPattern > Property[key.name='process'], ObjectPattern > Property[key.value='process']",
  message: PROCESS_MESSAGE,
};
// require('process'), require('node:process') och import('…'): modulen ger samma objekt som process.
const PROCESS_MODULE_CALL = {
  selector:
    "CallExpression[callee.name='require'][arguments.0.value=/^(node:)?process$/], ImportExpression[source.value=/^(node:)?process$/]",
  message: PROCESS_MESSAGE,
};
// import … from 'process' / 'node:process' i alla former (namngivet, default, namnrymd).
const PROCESS_IMPORTS = [
  'error',
  { paths: [{ name: 'process', message: PROCESS_MESSAGE }, { name: 'node:process', message: PROCESS_MESSAGE }] },
];
```

3. Ersätt blocket för `src/**/*.{ts,tsx}` och `globalIgnores`-raden med:

```js
  // Spelets källkod (det som bakas in i out/). Ett senare block ersätter regelns hela
  // värde för sina filer, därför upprepas de tre första väljarna här.
  {
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', DANGEROUS_HTML, DANGEROUS_HTML_MEMBER, OTHER_PUBLIC_ENV, ENV_OBJECT],
    },
  },
  // Utom testfiler (de läser process.cwd() och bakas aldrig in): process bara som process.env.
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: ['src/**/*.test.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', DANGEROUS_HTML, DANGEROUS_HTML_MEMBER, OTHER_PUBLIC_ENV, ENV_OBJECT, PROCESS_REFERENCE, PROCESS_PROPERTY, PROCESS_MODULE_CALL],
      'no-restricted-imports': PROCESS_IMPORTS,
    },
  },
  // ios/ är spelets Capacitor-projekt; ios/App/App/public är en kopia av out/.
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', '.claude/**', 'ios/**']),
```

- [ ] **Steg 3: Kör.**

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run test/eslintRules.test.ts && npx eslint`
Expected: PASS och inga lintfel i guidens egna filer (`src/app/layout.tsx` och `config.ts` läser bara `process.env.<tillåtet namn>` direkt). Fäller lint en guidefil: visa raden, rätta väljaren (inte filen), kör igen. Går fallet med mall-literal inte att fånga med `property.quasis.0.value.cooked` (esquery-sökvägen): byt mot `MemberExpression[computed=true] > TemplateLiteral.property:has(TemplateElement[value.cooked='process'])` och kör igen; testet avgör.

- [ ] **Steg 4: Hasha, verify, commit.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest' && php artisan computercat:byggguide:verify v3
git add resources/byggguide/v3 && git commit -m "Byggguide v3: lint stänger omvägarna till process.env och ignorerar ios/"
```

Förväntat: PASS och `Byggguide v3: OK (54 filer, …)`.

---

### Task 3: `achievements.ts` — läsning, kö och omgångar

**Files:**
- Create: `resources/byggguide/v3/files/src/lib/computercat/achievements.ts`
- Create: `resources/byggguide/v3/files/src/lib/computercat/achievements.test.ts`
- Modify: `resources/byggguide/v3/files/src/lib/computercat/queue.ts` (`dropReason` exporteras)

**Interfaces:**
- Consumes: `Authed` (`auth.ts`), `isRecord` (`http.ts`), `readJson`/`writeJson`/`KeyValueStore` (`storage.ts`), `dropReason` (`queue.ts`).
- Produces:
  - `type AchievementDefinition = { id: number; slug: string; name: string; description: string; icon: string; is_secret: boolean; sort_order: number }`
  - `type UnlockedAchievement = { slug: string; name: string; description: string; icon: string; unlocked_at: string | null }`
  - `type DroppedAchievement = { slug: string; reason: string }`
  - `type AchievementFlushResult = { sent: number; unlocked: UnlockedAchievement[]; unknown: string[]; rejected: string[]; dropped: DroppedAchievement[]; remaining: number }`
  - `const MAX_ACHIEVEMENT_SLUG = 50`, `const ACHIEVEMENT_BATCH = 10`, `const ACHIEVEMENT_SLUG = /^[a-z0-9][a-z0-9_.-]*$/` (= `SlugRule::PATTERN`)
  - `function assertAchievementSlug(slug: unknown): asserts slug is string` (kastar `RangeError`)
  - `type AchievementDeps = { storage: KeyValueStore; game: string; authed: Authed }`
  - `function createAchievements(deps: AchievementDeps): { list(): Promise<AchievementDefinition[]>; mine(): Promise<UnlockedAchievement[]>; report(slugs: readonly string[]): { queued: string[]; flushed: Promise<AchievementFlushResult> }; flush(): Promise<AchievementFlushResult>; pending(): string[] }`
  - `queue.ts`: `export function dropReason(e: unknown): string | null` (oförändrad logik)

- [ ] **Steg 1: Fallerande tester.** Skapa `resources/byggguide/v3/files/src/lib/computercat/achievements.test.ts`:

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { ACHIEVEMENT_BATCH, ACHIEVEMENT_SLUG, createAchievements, MAX_ACHIEVEMENT_SLUG } from './achievements';
import { createAuth, type Authed } from './auth';
import { memoryStorage, type KeyValueStore } from './storage';

const GAME = 'kattsvep';
const KEY = `computercat:${GAME}:achievement-queue`;
const PATH = '/games/kattsvep/achievements';
const unlocked = (slug: string) => ({ slug, name: slug, description: 'd', icon: '*', unlocked_at: '2026-10-16T10:00:00.000000Z' });
const answer = (body: Record<string, unknown>) => json(201, { unlocked: [], already_unlocked: 0, unknown: [], rejected: [], ...body });

function setup(routes: Parameters<typeof fakeFetch>[0], storage: KeyValueStore = storedSession(GAME)) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
  return { f, storage, ach: createAchievements({ storage, game: GAME, authed: auth.authed }) };
}

const bodies = (f: ReturnType<typeof fakeFetch>) =>
  f.calls.filter((c) => c.method === 'POST').map((c) => (c.body as { slugs: string[] }).slugs);

beforeEach(() => {
  vi.spyOn(console, 'warn').mockImplementation(() => {});
  vi.spyOn(console, 'error').mockImplementation(() => {});
});
afterEach(() => vi.restoreAllMocks());

describe('createAchievements: läsning', () => {
  it('list ger definitionerna, också hemliga med ???', async () => {
    const defs = [
      { id: 1, slug: 'first-log', name: 'Första stocken', description: 'Hugg ett träd', icon: 'stock', is_secret: false, sort_order: 1 },
      { id: 2, slug: 'first-storm', name: 'Stormen', description: '???', icon: 'storm', is_secret: true, sort_order: 2 },
    ];
    const { f, ach } = setup({ [`GET ${PATH}`]: [json(200, { data: defs })] });
    expect(await ach.list()).toEqual(defs);
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|stored');
    expect(f.unmatched).toEqual([]);
  });

  it('mine ger spelarens upplåsta', async () => {
    const { ach } = setup({ [`GET ${PATH}/me`]: [json(200, { data: [unlocked('first-log')] })] });
    expect(await ach.mine()).toEqual([unlocked('first-log')]);
  });

  it('list och mine kastar vid oväntad svarsform', async () => {
    const { ach } = setup({
      [`GET ${PATH}`]: [json(200, { data: [{ id: 1 }] })],
      [`GET ${PATH}/me`]: [json(200, { items: [] })],
    });
    await expect(ach.list()).rejects.toThrow(`Oväntat svar från GET ${PATH}`);
    await expect(ach.mine()).rejects.toThrow(`Oväntat svar från GET ${PATH}/me`);
  });
});

describe('createAchievements: report', () => {
  it('kastar RangeError synkront för en ogiltig slug och köar ingenting', () => {
    const { f, ach, storage } = setup({});
    for (const bad of ['', '   ', 'x'.repeat(MAX_ACHIEVEMENT_SLUG + 1), 42 as unknown as string, 'First-Log', 'first log', 'första', '-first', 'first\n']) {
      expect(() => ach.report(['first-log', bad])).toThrow(RangeError);
    }
    expect(storage.getItem(KEY)).toBeNull();
    expect(f.calls).toEqual([]);
  });

  it('slugmönstret är serverns (SlugRule::PATTERN) och tillåter siffror, _, . och -', () => {
    expect(ACHIEVEMENT_SLUG.source).toBe('^[a-z0-9][a-z0-9_.-]*$');
    const { ach } = setup({ [`POST ${PATH}`]: ['network-error'] });
    expect(ach.report(['first_12x12', 'solve.5', 'belt-network', '9lives']).queued).toHaveLength(4);
  });

  it('en slug på exakt 50 tecken går igenom', async () => {
    const slug = 'x'.repeat(MAX_ACHIEVEMENT_SLUG);
    const { ach } = setup({ [`POST ${PATH}`]: [answer({ unlocked: [unlocked(slug)] })] });
    expect((await ach.report([slug]).flushed).unlocked.map((u) => u.slug)).toEqual([slug]);
  });

  it('offline: köas lokalt utan dubbletter, ligger kvar och skickas senare i en omgång', async () => {
    const { f, ach, storage } = setup({
      [`POST ${PATH}`]: ['network-error', 'network-error', answer({ unlocked: [unlocked('a'), unlocked('b')] })],
    });
    const first = ach.report(['a', 'a']);
    expect(first.queued).toEqual(['a']);
    expect(await first.flushed).toMatchObject({ sent: 0, dropped: [], remaining: 1 });

    const second = ach.report(['a', 'b']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual(['a', 'b']);
    expect(await second.flushed).toMatchObject({ sent: 0, remaining: 2 });

    const r = await ach.flush();
    expect(r).toMatchObject({ sent: 2, remaining: 0, dropped: [], unknown: [], rejected: [] });
    expect(r.unlocked.map((u) => u.slug)).toEqual(['a', 'b']);
    expect(bodies(f).at(-1)).toEqual(['a', 'b']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it('skickar i omgångar om högst 10', async () => {
    expect(ACHIEVEMENT_BATCH).toBe(10);
    const slugs = Array.from({ length: 23 }, (_, i) => `a${i}`);
    const { f, ach } = setup({
      [`POST ${PATH}`]: [(req) => answer({ unlocked: (req.body as { slugs: string[] }).slugs.map(unlocked) })],
    });
    const r = await ach.report(slugs).flushed;
    expect(bodies(f).map((b) => b.length)).toEqual([10, 10, 3]);
    expect(r).toMatchObject({ sent: 23, remaining: 0, dropped: [] });
    expect(r.unlocked.map((u) => u.slug)).toEqual(slugs);
  });

  it('unknown och rejected syns och tas ur kön', async () => {
    const { ach, storage } = setup({
      [`POST ${PATH}`]: [answer({ unlocked: [unlocked('a')], already_unlocked: 1, unknown: ['typo'], rejected: ['c'] })],
    });
    const r = await ach.report(['a', 'b', 'typo', 'c']).flushed;
    expect(r).toMatchObject({ sent: 4, unknown: ['typo'], rejected: ['c'], dropped: [], remaining: 0 });
    expect(r.unlocked.map((u) => u.slug)).toEqual(['a']);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it('ett svar utan unknown och rejected (äldre server) räknas som skickat', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(201, { unlocked: [], already_unlocked: 1 })] });
    expect(await ach.report(['a']).flushed).toEqual({ sent: 1, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 });
  });

  it('422 tar bort omgången synligt i stället för att blockera kön', async () => {
    const { ach, storage } = setup({ [`POST ${PATH}`]: [json(422, { message: 'The slugs.0 field is invalid.' })] });
    const r = await ach.report(['a', 'b']).flushed;
    expect(r.dropped).toEqual([
      { slug: 'a', reason: '422 The slugs.0 field is invalid.' },
      { slug: 'b', reason: '422 The slugs.0 field is invalid.' },
    ]);
    expect(r.remaining).toBe(0);
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual([]);
  });

  it.each([503, 408, 429])('%i väntar och skickas vid nästa tömning', async (status) => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(status, { message: 'x' }), answer({ unlocked: [unlocked('a')] })] });
    expect(await ach.report(['a']).flushed).toMatchObject({ sent: 0, dropped: [], remaining: 1 });
    expect(await ach.flush()).toMatchObject({ sent: 1, remaining: 0 });
  });

  it('en oväntad svarsform tar bort omgången med skäl', async () => {
    const { ach } = setup({ [`POST ${PATH}`]: [json(201, { ok: true })] });
    const r = await ach.report(['a']).flushed;
    expect(r.dropped).toHaveLength(1);
    expect(r.dropped[0].reason).toMatch(/^oväntat fel: Oväntat svar från POST /);
  });

  it('full lagring: report kastar och inget skickas', () => {
    const inner = storedSession(GAME);
    const full: KeyValueStore = {
      getItem: (k) => inner.getItem(k),
      setItem: (k, v) => {
        if (k === KEY) throw new DOMException('full', 'QuotaExceededError');
        inner.setItem(k, v);
      },
      removeItem: (k) => inner.removeItem(k),
    };
    const { f, ach } = setup({}, full);
    expect(() => ach.report(['a'])).toThrow('Kunde inte spara upplåsningen lokalt');
    expect(f.calls).toEqual([]);
  });

  it('en omgång som inte går att ta bort ur kön skickas inte om och om igen', async () => {
    const inner = storedSession(GAME);
    let writes = 0;
    const stuck: KeyValueStore = {
      getItem: (k) => inner.getItem(k),
      setItem: (k, v) => {
        if (k === KEY && ++writes > 1) throw new Error('blockerad');
        inner.setItem(k, v);
      },
      removeItem: (k) => inner.removeItem(k),
    };
    const { f, ach } = setup({ [`POST ${PATH}`]: [answer({ already_unlocked: 1 })] }, stuck);
    const r = await ach.report(['a']).flushed;
    expect(bodies(f)).toEqual([['a']]);
    expect(r).toMatchObject({ sent: 1, remaining: 1 });
  });

  it('två samtidiga tömningar skickar varje omgång en gång', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const authed = vi.fn(async () => {
      await gate;
      return { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] };
    }) as unknown as Authed;
    const ach = createAchievements({ storage: memoryStorage(), game: GAME, authed });
    const a = ach.report(['a']).flushed;
    const b = ach.flush();
    release();
    const [ra, rb] = await Promise.all([a, b]);
    expect(authed).toHaveBeenCalledTimes(1);
    expect(ra.sent + rb.sent).toBe(1);
    expect(ach.pending()).toEqual([]);
  });

  it('en tom kö gör inga anrop', async () => {
    const { f, ach } = setup({});
    expect(await ach.flush()).toEqual({ sent: 0, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 });
    expect(f.calls).toEqual([]);
  });

  it('en trasig post i lagringen tas bort ensam', async () => {
    const storage = storedSession(GAME);
    storage.setItem(KEY, JSON.stringify(['a', 7, '', 'b']));
    const { ach } = setup({}, storage);
    expect(ach.pending()).toEqual(['a', 'b']);
  });
});
```

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run src/lib/computercat/achievements.test.ts`
Expected: FAIL (`./achievements` finns inte).

- [ ] **Steg 2: `dropReason` exporteras.** I `resources/byggguide/v3/files/src/lib/computercat/queue.ts`, ersätt `function dropReason(e: unknown): string | null {` med `export function dropReason(e: unknown): string | null {` och lägg till sist i kommentaren ovanför: ` * Delas med achievementkön (achievements.ts).`

- [ ] **Steg 3: Implementationen.** Skapa `resources/byggguide/v3/files/src/lib/computercat/achievements.ts`:

```ts
import type { Authed } from './auth';
import { isRecord } from './http';
import { dropReason } from './queue';
import { readJson, writeJson, type KeyValueStore } from './storage';

/** AchievementDefinitionResource.php. description är "???" för hemliga. */
export type AchievementDefinition = {
  id: number;
  slug: string;
  name: string;
  description: string;
  icon: string;
  is_secret: boolean;
  sort_order: number;
};
/** UserAchievementResource.php. */
export type UnlockedAchievement = { slug: string; name: string; description: string; icon: string; unlocked_at: string | null };
export type DroppedAchievement = { slug: string; reason: string };
export type AchievementFlushResult = {
  /** Slugs som servern tog emot (upplåsta, redan upplåsta, okända och avvisade). */
  sent: number;
  unlocked: UnlockedAchievement[];
  /** Ingen aktiv definition med den sluggen: felstavad eller inte skapad med upsert_achievement. */
  unknown: string[];
  /** Avvisade av rimlighetskontrollen (settings.achievement_thresholds mot molnsparningen). */
  rejected: string[];
  dropped: DroppedAchievement[];
  remaining: number;
};

/** AchievementRules: slug högst 50 tecken. */
export const MAX_ACHIEVEMENT_SLUG = 50;
/** AchievementController::store: 1–10 slugs per anrop. */
export const ACHIEVEMENT_BATCH = 10;
/** SlugRule::PATTERN i computercat-api: gemener, siffror, -, _ och ., först en bokstav eller siffra. */
export const ACHIEVEMENT_SLUG = /^[a-z0-9][a-z0-9_.-]*$/;

const seg = encodeURIComponent;

const isValidSlug = (s: unknown): s is string =>
  typeof s === 'string' && s.length <= MAX_ACHIEVEMENT_SLUG && ACHIEVEMENT_SLUG.test(s);

/** Synkront, som assertScore: en felaktig slug ska aldrig fälla en hel omgång med 422 eller bli tyst "unknown". */
export function assertAchievementSlug(slug: unknown): asserts slug is string {
  if (!isValidSlug(slug)) {
    throw new RangeError(
      `achievement-slug ska ha högst ${MAX_ACHIEVEMENT_SLUG} tecken: gemener, siffror, -, _ och ., först en bokstav eller siffra (som upsert_achievement); fick ${JSON.stringify(slug)}`,
    );
  }
}

const isArray = (v: unknown): v is unknown[] => Array.isArray(v);
const strings = (v: unknown): string[] => (Array.isArray(v) ? v.filter((s): s is string => typeof s === 'string') : []);

export type AchievementDeps = { storage: KeyValueStore; game: string; authed: Authed };

export function createAchievements(deps: AchievementDeps) {
  const base = `/games/${seg(deps.game)}/achievements`;
  const key = `computercat:${deps.game}:achievement-queue`;
  let chain: Promise<unknown> = Promise.resolve();

  // Samma mönster som resultatkön: inget hålls i minnet, och varje ändring läser, ändrar och
  // skriver i ett synkront steg, så att flikar som delar lagringen inte skriver över varandra.
  const load = (): string[] => {
    const raw = readJson(deps.storage, key, isArray) ?? [];
    const valid = raw.filter(isValidSlug);
    if (valid.length < raw.length) {
      console.warn(`[computercat] ${raw.length - valid.length} ogiltiga poster i achievementkön togs bort`);
      writeJson(deps.storage, key, valid);
    }
    return valid;
  };
  const change = (fn: (current: string[]) => string[]): boolean => writeJson(deps.storage, key, fn(load()));

  async function read<T>(path: string, valid: (item: Record<string, unknown>) => boolean): Promise<T[]> {
    const res = await deps.authed<unknown>('GET', path);
    if (!isRecord(res) || !Array.isArray(res.data) || !res.data.every((d) => isRecord(d) && valid(d))) {
      throw new Error(`Oväntat svar från GET ${path}`);
    }
    return res.data as T[];
  }

  async function submit(slugs: string[]) {
    const res = await deps.authed<unknown>('POST', base, { slugs });
    if (!isRecord(res) || !Array.isArray(res.unlocked)) throw new Error(`Oväntat svar från POST ${base}`);
    // unknown och rejected saknas hos en server före ändringen: då räknas allt som skickat.
    return {
      unlocked: res.unlocked.filter(isRecord) as UnlockedAchievement[],
      unknown: strings(res.unknown),
      rejected: strings(res.rejected),
    };
  }

  async function run(): Promise<AchievementFlushResult> {
    const result: AchievementFlushResult = { sent: 0, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 };
    for (;;) {
      const batch = load().slice(0, ACHIEVEMENT_BATCH);
      if (batch.length === 0) break;
      const done = new Set(batch);
      const removeBatch = () => change((current) => current.filter((s) => !done.has(s)));
      try {
        const r = await submit(batch);
        const removed = removeBatch();
        result.sent += batch.length;
        result.unlocked.push(...r.unlocked);
        result.unknown.push(...r.unknown);
        result.rejected.push(...r.rejected);
        if (r.unknown.length > 0) console.warn(`[computercat] okända achievements (ingen aktiv definition): ${r.unknown.join(', ')}`);
        if (r.rejected.length > 0) console.warn(`[computercat] achievements avvisade av rimlighetskontrollen: ${r.rejected.join(', ')}`);
        if (!removed) {
          console.error('[computercat] kunde inte ta bort skickade achievements ur kön; tömningen avbryts');
          break;
        }
      } catch (e) {
        const reason = dropReason(e);
        if (reason === null) break; // tillfälligt fel: försök senare
        const removed = removeBatch();
        for (const slug of batch) result.dropped.push({ slug, reason });
        console.error(`[computercat] achievements ${batch.join(', ')} avvisades: ${reason}`);
        if (!removed) break;
      }
    }
    result.remaining = load().length;
    return result;
  }

  /** Körningar läggs efter varandra, så samma omgång skickas aldrig två gånger parallellt i en flik. */
  function flush(): Promise<AchievementFlushResult> {
    const p = chain.then(run);
    chain = p.catch(() => undefined);
    return p;
  }

  /**
   * Köa upplåsningar lokalt (utan dubbletter) och försök skicka direkt. Spara upplåsningen i spelets
   * egen sparning först, och rapportera hela listan vid varje start (servern låser upp en gång).
   */
  function report(slugs: readonly string[]): { queued: string[]; flushed: Promise<AchievementFlushResult> } {
    if (!Array.isArray(slugs)) throw new RangeError('report tar en lista med slugs');
    for (const slug of slugs) assertAchievementSlug(slug);
    const queued = [...new Set(slugs)];
    if (!change((current) => [...current, ...queued.filter((s) => !current.includes(s))])) {
      throw new Error('Kunde inte spara upplåsningen lokalt (lagringen är full eller blockerad); den skickades inte.');
    }
    return { queued, flushed: flush() };
  }

  return {
    list: () => read<AchievementDefinition>(base, (d) => typeof d.slug === 'string' && typeof d.name === 'string'),
    mine: () => read<UnlockedAchievement>(`${base}/me`, (d) => typeof d.slug === 'string'),
    report,
    flush,
    pending: () => load(),
  };
}
```

- [ ] **Steg 4: Kör.**

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run src/lib/computercat/ && npx tsc --noEmit && npx eslint`
Expected: PASS, inga typ- eller lintfel. (`queue.test.ts` är oförändrad och ska fortfarande gå igenom.)

- [ ] **Steg 5: Hasha, verify, commit.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest' && php artisan computercat:byggguide:verify v3
git add resources/byggguide/v3 && git commit -m "Byggguide v3: achievements i klienten (kö, omgångar om 10, synliga unknown och rejected)"
```

Förväntat: `56 filer i v3.` och `Byggguide v3: OK (56 filer, …)`.

---

### Task 4: `flushAll`, `onFlushed` och bakgrunden

**Files:**
- Modify: `resources/byggguide/v3/files/src/lib/computercat/index.ts`
- Modify: `resources/byggguide/v3/files/src/lib/computercat/index.test.ts`
- Modify: `resources/byggguide/v3/files/src/components/BackgroundSync.tsx`

**Interfaces:**
- Consumes: `createAchievements`, `AchievementFlushResult` (Task 3); `createScoreQueue`, `FlushResult` (`queue.ts`).
- Produces: på klienten från `createComputercat`: `achievements` (Task 3:s objekt), `flushAll(): Promise<FlushAllResult>`, `onFlushed(cb: (r: FlushAllResult) => void): () => void`. Typen `FlushAllResult = { scores: FlushResult; achievements: AchievementFlushResult }` och `export { assertAchievementSlug, MAX_ACHIEVEMENT_SLUG, ACHIEVEMENT_BATCH, ACHIEVEMENT_SLUG }` plus typerna `AchievementDefinition`, `UnlockedAchievement`, `DroppedAchievement`, `AchievementFlushResult` från `@/lib/computercat`.

- [ ] **Steg 1: Fallerande tester.** I `resources/byggguide/v3/files/src/lib/computercat/index.test.ts`, ersätt importraden `import { BASE, fakeFetch, json } from '../../../test/fakeFetch';` med `import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';` och lägg till sist i `describe('computercat', …)`:

```ts
  it('flushAll tömmer resultatkön och achievementkön och meddelar onFlushed', async () => {
    const { createComputercat } = await import('./index');
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({
      'POST /games/kattsvep/leaderboards/daily-clicks': [
        'network-error',
        json(201, { data: { rank: 1, score: 5, metadata: null, submitted_at: 'x', user: { id: 7, display_name: 'Player-7' } } }),
      ],
      'POST /games/kattsvep/achievements': ['network-error', json(201, { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] })],
    });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    expect((await cc.recordScore('daily-clicks', 5).flushed).remaining).toBe(1);
    expect((await cc.achievements.report(['first-log']).flushed).remaining).toBe(1);

    const seen: unknown[] = [];
    const stop = cc.onFlushed((r) => seen.push(r));
    const result = await cc.flushAll();
    expect(result.scores).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(result.achievements).toMatchObject({ sent: 1, dropped: [], remaining: 0 });
    expect(seen).toEqual([result]);

    stop();
    await cc.flushAll();
    expect(seen).toHaveLength(1);
    expect(f.unmatched).toEqual([]);
  });

  it('flushAll med tomma köer gör inga anrop', async () => {
    const { createComputercat } = await import('./index');
    const f = fakeFetch({});
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    const r = await cc.flushAll();
    expect(r.scores).toEqual({ sent: 0, dropped: [], remaining: 0 });
    expect(r.achievements.remaining).toBe(0);
    expect(f.calls).toEqual([]);
  });

  it('kastar resultatkön töms achievementkön ändå, och flushAll avvisas med felet', async () => {
    const { createComputercat } = await import('./index');
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /games/kattsvep/achievements': ['network-error', json(201, { unlocked: [], already_unlocked: 1, unknown: [], rejected: [] })] });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: storedSession('kattsvep') });
    expect((await cc.achievements.report(['first-log']).flushed).remaining).toBe(1);
    vi.spyOn(cc.queue, 'flush').mockRejectedValue(new Error('trasig kö'));
    const seen = vi.fn();
    cc.onFlushed(seen);
    await expect(cc.flushAll()).rejects.toThrow('trasig kö');
    expect(cc.achievements.pending()).toEqual([]);
    expect(seen).not.toHaveBeenCalled();
  });

  it('en lyssnare som kastar stoppar inte de andra', async () => {
    const { createComputercat } = await import('./index');
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: fakeFetch({}).fetch, storage: memoryStorage() });
    const second = vi.fn();
    cc.onFlushed(() => {
      throw new Error('trasig lyssnare');
    });
    cc.onFlushed(second);
    await cc.flushAll();
    expect(second).toHaveBeenCalledTimes(1);
    expect(errors).toHaveBeenCalled();
  });
```

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run src/lib/computercat/index.test.ts`
Expected: FAIL (`cc.achievements`, `flushAll` och `onFlushed` finns inte).

- [ ] **Steg 2: `index.ts`.** I `resources/byggguide/v3/files/src/lib/computercat/index.ts`:

1. Ersätt `import { createScoreQueue } from './queue';` med:

```ts
import { createAchievements, type AchievementFlushResult } from './achievements';
import { createScoreQueue, type FlushResult } from './queue';
```

2. Efter raden `export type { CloudSave, PutResult } from './saves';`, lägg till:

```ts
export { assertAchievementSlug, MAX_ACHIEVEMENT_SLUG, ACHIEVEMENT_BATCH, ACHIEVEMENT_SLUG } from './achievements';
export type { AchievementDefinition, UnlockedAchievement, DroppedAchievement, AchievementFlushResult } from './achievements';

/** Vad en tömning av båda köerna gav (BackgroundSync, onFlushed). */
export type FlushAllResult = { scores: FlushResult; achievements: AchievementFlushResult };
```

3. Ersätt från `  const queue = createScoreQueue(` till och med funktionens `return { … };` med:

```ts
  const queue = createScoreQueue({ storage, game, submit: leaderboards.submit, now: opts.now });
  const achievements = createAchievements({ storage, game, authed: auth.authed });
  const listeners = new Set<(r: FlushAllResult) => void>();

  /**
   * Resultatkön först, sedan achievementkön, var och en i eget try: kastar den ena töms den andra ändå.
   * Kastade någon avvisas flushAll efteråt med det första felet; lyssnarna får bara hela resultat.
   */
  async function flushAll(): Promise<FlushAllResult> {
    const failures: unknown[] = [];
    let scores: FlushResult | null = null;
    let achieved: AchievementFlushResult | null = null;
    try {
      scores = await queue.flush();
    } catch (e) {
      console.error('[computercat] resultatkön kunde inte tömmas', e);
      failures.push(e);
    }
    try {
      achieved = await achievements.flush();
    } catch (e) {
      console.error('[computercat] achievementkön kunde inte tömmas', e);
      failures.push(e);
    }
    if (failures.length > 0 || scores === null || achieved === null) throw failures[0];
    const result: FlushAllResult = { scores, achievements: achieved };
    for (const cb of [...listeners]) {
      try {
        cb(result);
      } catch (e) {
        console.error('[computercat] en onFlushed-lyssnare kastade', e);
      }
    }
    return result;
  }

  return {
    config: opts.config,
    auth,
    leaderboards,
    saves,
    queue,
    achievements,
    /** Lokalt först: köa resultatet och försök skicka direkt. `flushed` säger vad som hände. */
    recordScore(type: string, score: number, metadata?: Record<string, unknown>) {
      const item = queue.enqueue(type, score, metadata ?? null);
      return { item, flushed: queue.flush() };
    },
    flushAll,
    /** Prenumerera på varje flushAll (också bakgrundens). Returnerar en funktion som avslutar prenumerationen. */
    onFlushed(cb: (r: FlushAllResult) => void): () => void {
      listeners.add(cb);
      return () => {
        listeners.delete(cb);
      };
    },
  };
```

- [ ] **Steg 3: `BackgroundSync.tsx`.** Ersätt hela filen `resources/byggguide/v3/files/src/components/BackgroundSync.tsx` med:

```tsx
'use client';

import { useEffect } from 'react';
import { getComputercat, startBackgroundSync } from '@/lib/computercat';

/** Kontrollerar spelaren och tömmer resultat- och achievementkön vid start, vid "online" och varje minut. */
export function BackgroundSync() {
  useEffect(() => {
    const cc = getComputercat();
    if (!cc.ok) return;
    cc.client.auth.verify().catch((e: unknown) => console.warn('[computercat] kunde inte kontrollera spelaren', e));
    return startBackgroundSync(() => cc.client.flushAll(), window);
  }, []);
  return null;
}
```

- [ ] **Steg 4: Kör guidens hela svit.**

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run && npx tsc --noEmit && npx eslint`
Expected: PASS. `test/integration.test.tsx` (BackgroundSync tömmer resultatkön vid "online") går igenom oförändrad: den tomma achievementkön gör inga anrop.

- [ ] **Steg 5: Hasha, verify, commit.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest' && php artisan computercat:byggguide:verify v3
git add resources/byggguide/v3 && git commit -m "Byggguide v3: flushAll och onFlushed; bakgrunden tömmer båda köerna"
```

---

### Task 5: iOS-skripten — `check-ios.mjs` och `ios-setup.mjs`

**Files:**
- Create: `resources/byggguide/v3/files/scripts/check-ios.mjs`
- Create: `resources/byggguide/v3/files/scripts/ios-setup.mjs`
- Create: `resources/byggguide/v3/files/test/iosSetup.test.ts`
- Modify: `resources/byggguide/v3/files/package.json` (`check`)
- Modify: `tests/Feature/BuildGuideV3Test.php`

**Interfaces:**
- Consumes: Task 0:s resultat (vad `cap add ios` skapar; om `out/` krävs; Linux-beslutet).
- Produces:
  - `check-ios.mjs`: `export const BUNDLE_ID: RegExp`, `export const CAPACITOR = '8.5.3'`, `export function capacitorConfigSource(appId: string, appName: string): string` (kastar `Error` med `bundle-id` eller `appnamn` i texten), `export function addExportCompliance(plist: string): string`, `export function checkIos(root: string): { present: boolean; errors: string[] }`. Körs som skript: `node scripts/check-ios.mjs` (utskrift `check-ios: …`, exitkod 1 vid fel).
  - `ios-setup.mjs`: `export function setupIos(opts: { root: string; appId: string; appName: string; addIos?: (root: string) => void }): void`. Körs som `node scripts/ios-setup.mjs <bundle-id> "<namn>"`.
  - `package.json` `check` slutar med `&& node scripts/cc-gate.mjs && node scripts/check-ios.mjs`.

- [ ] **Steg 0: Hotmodell.** Invokera `security-first-mindset` för de två skripten. Svara mot planen: skriptet tar två argument från spelets Claude (bundle-id, namn), skriver två filer i repot och startar `node_modules/.bin/cap` utan skal (argumentlista, ingen interpolering). Indata valideras före första skrivningen (R14, mönstret i Värden); namnet skrivs med `JSON.stringify`. Inga hemligheter läses eller skrivs; `check-ios` fäller på certifikat, nycklar, profiler och `ExportOptions.plist` i `ios/`. Vad skriptet inte skyddar mot: en ändrad `node_modules` (samma förtroende som `npm run build`). Notera svaren i Task 10:s granskning.

- [ ] **Steg 1: Fallerande tester.** Skapa `resources/byggguide/v3/files/test/iosSetup.test.ts`:

```ts
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { addExportCompliance, BUNDLE_ID, CAPACITOR, capacitorConfigSource, checkIos } from '../scripts/check-ios.mjs';
import { setupIos } from '../scripts/ios-setup.mjs';

// Byggguide v3: scripts/check-ios.mjs och scripts/ios-setup.mjs. Fixturerna har samma form som det
// Capacitor 8.5.3 skapar med cap add ios --packagemanager SPM (bilagan 2026-10-15, Task 0).
const ID = 'se.computercat.kattsvep';
const COMPLIANT = '\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n';
const PLIST = (extra = '') => `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
\t<key>CFBundleDisplayName</key>
\t<string>Kattsvep</string>
\t<key>CFBundleShortVersionString</key>
\t<string>$(MARKETING_VERSION)</string>
\t<key>CFBundleVersion</key>
\t<string>$(CURRENT_PROJECT_VERSION)</string>
${extra}</dict>
</plist>
`;
const SPM = (v: string) =>
  `let package = Package(\n    dependencies: [\n        .package(url: "https://github.com/ionic-team/capacitor-swift-pm.git", exact: "${v}")\n    ]\n)\n`;
const PBX = (id: string) => `\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = ${id};\n\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = ${id};\n`;
const PKG = (over: Record<string, unknown> = {}) =>
  JSON.stringify({
    version: '0.1.0',
    dependencies: { '@capacitor/core': '8.5.3', '@capacitor/ios': '8.5.3' },
    devDependencies: { '@capacitor/cli': '8.5.3' },
    ...over,
  });
const PACKAGE = PKG();

const dirs: string[] = [];
afterEach(() => {
  // Bara katalogerna som testet skapade.
  for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

function write(root: string, files: Record<string, string | null>): string {
  for (const [path, content] of Object.entries(files)) {
    if (content === null) continue;
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), content);
  }
  return root;
}

function tempRoot(): string {
  const root = mkdtempSync(join(tmpdir(), 'check-ios-'));
  dirs.push(root);
  return root;
}

/** Ett projekt som ios-setup har skapat; `files` ersätter eller (med null) tar bort filer. */
function project(files: Record<string, string | null> = {}): string {
  return write(tempRoot(), {
    'package.json': PACKAGE,
    'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep'),
    'ios/App/App.xcodeproj/project.pbxproj': PBX(ID),
    'ios/App/App/Info.plist': PLIST(COMPLIANT),
    'ios/App/CapApp-SPM/Package.swift': SPM('8.5.3'),
    ...files,
  });
}

/** Ett nytt spelrepo efter npm run build. */
function freshRepo(withOut = true): string {
  return write(tempRoot(), { 'package.json': PACKAGE, 'out/index.html': withOut ? '<html></html>' : null });
}

/** Låtsas-cap: skriver det som cap add ios skriver och som kontrollen läser, med appId ur capacitor.config.ts. */
function fakeCapAdd(root: string): void {
  const id = /appId: "([^"]+)"/.exec(readFileSync(join(root, 'capacitor.config.ts'), 'utf8'))![1];
  write(root, {
    'ios/App/App.xcodeproj/project.pbxproj': PBX(id),
    'ios/App/App/Info.plist': PLIST(),
    'ios/App/CapApp-SPM/Package.swift': SPM('8.5.3'),
  });
}

describe('capacitorConfigSource', () => {
  it('skriver spelets bundle-id och namn och inget annat id', () => {
    const src = capacitorConfigSource('se.computercat.tinkerisle', 'Tinker Isle');
    expect(src).toContain('appId: "se.computercat.tinkerisle",');
    expect(src).toContain('appName: "Tinker Isle",');
    expect(src).toContain("webDir: 'out',");
    expect(src.match(/se\.computercat\.[a-z0-9]+/g)).toEqual(['se.computercat.tinkerisle']);
  });

  it.each(['', 'se.computercat.', 'se.computercat.k', 'se.computercat.tinker-isle', 'se.computercat.Tinker', 'co.humblebrag.tinkerisle', 'se.computercat.tinkerisle\n'])(
    'vägrar bundle-id %j',
    (id) => {
      expect(() => capacitorConfigSource(id, 'Tinker Isle')).toThrow('bundle-id');
    },
  );

  it.each(['', '   ', 'x'.repeat(51), 'Rad\nbrytning', 'Katt & Hund', 'A<b>'])('vägrar appnamn %j', (name) => {
    expect(() => capacitorConfigSource(ID, name)).toThrow('appnamn');
  });

  it('har samma mönster som Computercat och släpprepot', () => {
    expect(BUNDLE_ID.source).toBe('^se\\.computercat\\.[a-z][a-z0-9]{1,39}$');
  });
});

describe('addExportCompliance', () => {
  it('lägger in nyckeln en gång, före sista </dict>', () => {
    const once = addExportCompliance(PLIST());
    expect(once).toBe(PLIST(COMPLIANT));
    expect(addExportCompliance(once)).toBe(once);
  });

  it('kastar utan </dict>', () => {
    expect(() => addExportCompliance('<plist/>')).toThrow('</dict>');
  });
});

describe('checkIos', () => {
  it('utan ios/ finns inget att kontrollera', () => {
    expect(checkIos(freshRepo())).toEqual({ present: false, errors: [] });
  });

  it('godkänner ett projekt som ios-setup skapat', () => {
    expect(checkIos(project())).toEqual({ present: true, errors: [] });
  });

  it('har guidens Capacitor-version', () => {
    expect(CAPACITOR).toBe('8.5.3');
  });

  it('fäller på varje symbolisk länk i ios/, också en som pekar inåt', () => {
    const root = project();
    symlinkSync('/etc/hosts', join(root, 'ios/App/hosts'));
    symlinkSync('Info.plist', join(root, 'ios/App/App/kopia.plist'));
    const errors = checkIos(root).errors.join('\n');
    expect(errors).toContain('ios/App/hosts: symbolisk länk');
    expect(errors).toContain('ios/App/App/kopia.plist: symbolisk länk');
  });

  it('hoppar bara över exakt ios/App/App/public', () => {
    const root = project({ 'ios/App/App/public/x.p12': 'kopia av out/', 'ios/App/public/dist.p12': 'x' });
    const errors = checkIos(root).errors.join('\n');
    expect(errors).not.toContain('ios/App/App/public');
    expect(errors).toContain('ios/App/public/dist.p12');
  });

  it.each([
    ['annat bundle-id i Xcode-projektet', { 'ios/App/App.xcodeproj/project.pbxproj': PBX('se.computercat.annat') }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['två bundle-id i Xcode-projektet', { 'ios/App/App.xcodeproj/project.pbxproj': PBX(ID) + PBX('se.computercat.annat') }, 'PRODUCT_BUNDLE_IDENTIFIER'],
    ['appId i fel form', { 'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep').replace(ID, 'co.humblebrag.kattsvep') }, 'appId'],
    ['två appId', { 'capacitor.config.ts': `${capacitorConfigSource(ID, 'Kattsvep')}  appId: "${ID}",\n` }, 'appId'],
    ['saknad capacitor.config.ts', { 'capacitor.config.ts': null }, 'capacitor.config.ts saknas'],
    ['webDir som inte är out', { 'capacitor.config.ts': capacitorConfigSource(ID, 'Kattsvep').replace("webDir: 'out'", "webDir: 'public'") }, 'webDir'],
    ['saknad exportdeklaration', { 'ios/App/App/Info.plist': PLIST() }, 'ITSAppUsesNonExemptEncryption'],
    ['hårdkodat byggnummer', { 'ios/App/App/Info.plist': PLIST(COMPLIANT).replace('$(CURRENT_PROJECT_VERSION)', '7') }, 'CFBundleVersion'],
    ['hårdkodad version', { 'ios/App/App/Info.plist': PLIST(COMPLIANT).replace('$(MARKETING_VERSION)', '1.0') }, 'CFBundleShortVersionString'],
    ['annan Capacitor i Package.swift', { 'ios/App/CapApp-SPM/Package.swift': SPM('8.5.2') }, 'capacitor-swift-pm'],
    ['CocoaPods i stället för SPM', { 'ios/App/CapApp-SPM/Package.swift': null, 'ios/App/Podfile': "platform :ios, '15.0'\n" }, 'Swift Package Manager'],
    ['version utan PATCH', { 'package.json': PKG({ version: '1.0' }) }, 'MAJOR.MINOR.PATCH'],
    ['saknad @capacitor/cli', { 'package.json': PKG({ devDependencies: {} }) }, '@capacitor/cli saknas'],
    ['annan @capacitor/ios i package.json', { 'package.json': PKG({ dependencies: { '@capacitor/core': '8.5.3', '@capacitor/ios': '8.5.2' } }) }, '@capacitor/ios är 8.5.2'],
    ['intervall för @capacitor/core', { 'package.json': PKG({ dependencies: { '@capacitor/core': '^8.5.3', '@capacitor/ios': '8.5.3' } }) }, '@capacitor/core är ^8.5.3'],
    ['certifikat i repot', { 'ios/App/dist.p12': 'x' }, 'dist.p12'],
    ['profil i repot', { 'ios/App/App/app.mobileprovision': 'x' }, 'app.mobileprovision'],
    ['ExportOptions.plist i repot', { 'ios/ExportOptions.plist': '<plist/>' }, 'ExportOptions.plist'],
  ])('fäller: %s', (_name, files, needle) => {
    const { present, errors } = checkIos(project(files));
    expect(present).toBe(true);
    expect(errors.join('\n')).toContain(needle);
  });
});

describe('setupIos', () => {
  it('skriver konfigurationen före cap add, lägger in exportdeklarationen och klarar kontrollen', () => {
    const root = freshRepo();
    setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd });
    expect(readFileSync(join(root, 'capacitor.config.ts'), 'utf8')).toBe(capacitorConfigSource(ID, 'Kattsvep'));
    expect(readFileSync(join(root, 'ios/App/App/Info.plist'), 'utf8')).toBe(PLIST(COMPLIANT));
    expect(checkIos(root)).toEqual({ present: true, errors: [] });
  });

  it('vägrar ett ogiltigt bundle-id innan något skrivs', () => {
    const root = freshRepo();
    const addIos = vi.fn();
    expect(() => setupIos({ root, appId: 'se.computercat.kattsvep-x', appName: 'Kattsvep', addIos })).toThrow('bundle-id');
    expect(existsSync(join(root, 'capacitor.config.ts'))).toBe(false);
    expect(addIos).not.toHaveBeenCalled();
  });

  it('vägrar utan out/index.html och skriver inget', () => {
    const root = freshRepo(false);
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('npm run build');
    expect(existsSync(join(root, 'capacitor.config.ts'))).toBe(false);
  });

  it('körs bara en gång', () => {
    const root = freshRepo();
    mkdirSync(join(root, 'ios'));
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('ios/ finns redan');
  });

  it('skriver inte över en capacitor.config.ts med annat innehåll', () => {
    const root = write(freshRepo(), { 'capacitor.config.ts': capacitorConfigSource('se.computercat.annat', 'Annat') });
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: fakeCapAdd })).toThrow('capacitor.config.ts finns redan');
  });

  it('fäller när cap add inte gav ett godkänt projekt', () => {
    const root = freshRepo();
    const wrongId = (r: string) => {
      fakeCapAdd(r);
      write(r, { 'ios/App/App.xcodeproj/project.pbxproj': PBX('se.computercat.annat') });
    };
    expect(() => setupIos({ root, appId: ID, appName: 'Kattsvep', addIos: wrongId })).toThrow('PRODUCT_BUNDLE_IDENTIFIER');
  });
});
```

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run test/iosSetup.test.ts`
Expected: FAIL (skripten finns inte).

- [ ] **Steg 2: `scripts/check-ios.mjs`.** Skapa `resources/byggguide/v3/files/scripts/check-ios.mjs`:

```js
// Byggguide v3: kontroll av spelets iOS-projekt (Capacitor, Swift Package Manager). Körs sist i
// npm run check. Utan ios/ finns inget att kontrollera. Kraven är desamma som släpprepots
// release-ios.yml ställer (bundle-id, exportdeklaration, version och byggnummer från bygget),
// så att felet syns lokalt i stället för efter flera minuter på en macOS-maskin.
import { existsSync, lstatSync, readdirSync, readFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { pathToFileURL } from 'node:url';

// Samma mönster som Computercat (BundleId) och släpprepots "Validera indata".
export const BUNDLE_ID = /^se\.computercat\.[a-z][a-z0-9]{1,39}$/;
const VERSION = /^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$/;
const SECRET_FILE = /\.(p8|p12|cer|pem|key|mobileprovision)$/i;
// Guidens Capacitor; samma som manifestets ios.capacitor (BuildGuideV3Test jämför).
export const CAPACITOR = '8.5.3';
const CAPACITOR_PACKAGES = [['@capacitor/core', 'dependencies'], ['@capacitor/ios', 'dependencies'], ['@capacitor/cli', 'devDependencies']];
// Bara Capacitors kopia av out/ (ignorerad av ios/.gitignore) hoppas över, och bara på exakt den platsen.
const SKIP = join('ios', 'App', 'App', 'public');

/** capacitor.config.ts för spelet. appId och appName kommer från Computercat (get_release_status, get_game). */
export function capacitorConfigSource(appId, appName) {
  if (typeof appId !== 'string' || !BUNDLE_ID.test(appId)) {
    throw new Error(`ogiltigt bundle-id ${JSON.stringify(appId)}; använd targets.app_store.ios.bundle_id från get_release_status (se.computercat.<slug utan bindestreck>)`);
  }
  const name = typeof appName === 'string' ? appName.trim() : '';
  const control = [...name].some((c) => c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127);
  if (name === '' || name.length > 50 || control || /[<>&]/.test(name)) {
    throw new Error(`ogiltigt appnamn ${JSON.stringify(appName)}: 1–50 tecken, utan radbrytningar och utan < > &; fråga en människa om spelets namn inte går`);
  }
  return [
    "import type { CapacitorConfig } from '@capacitor/cli';",
    '',
    '// Skrivet av scripts/ios-setup.mjs (byggguide v3). appId är spelets bundle-id i Computercat',
    '// (get_release_status: targets.app_store.ios.bundle_id). Ändra det aldrig för hand.',
    'const config: CapacitorConfig = {',
    `  appId: ${JSON.stringify(appId)},`,
    `  appName: ${JSON.stringify(name)},`,
    "  webDir: 'out',",
    '};',
    '',
    'export default config;',
    '',
  ].join('\n');
}

/** Exportdeklarationen (bilagan B8): utan den frågar App Store Connect för varje bygge. */
export function addExportCompliance(plist) {
  if (/<key>ITSAppUsesNonExemptEncryption<\/key>/.test(plist)) return plist;
  const end = plist.lastIndexOf('</dict>');
  if (end === -1) throw new Error('Info.plist saknar </dict>');
  return `${plist.slice(0, end)}\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n${plist.slice(end)}`;
}

function readAppId(source) {
  const found = [...source.matchAll(/^\s*appId:\s*("(?:[^"\\]|\\.)*"),\s*$/gm)];
  if (found.length !== 1) return null;
  try {
    return JSON.parse(found[0][1]);
  } catch {
    return null;
  }
}

/** Alla poster under dir. lstat, så att en symbolisk länk syns som länk och aldrig följs. */
function walk(root, dir) {
  return readdirSync(join(root, dir)).flatMap((name) => {
    const rel = join(dir, name);
    if (rel === SKIP) return [];
    const st = lstatSync(join(root, rel));
    if (st.isSymbolicLink()) return [{ rel, link: true }];
    if (st.isDirectory()) return walk(root, rel);
    return [{ rel, link: false }];
  });
}

/** Fel i spelets iOS-projekt. present: false när ios/ saknas (spelet är inte förberett för iOS). */
export function checkIos(root) {
  if (!existsSync(join(root, 'ios'))) return { present: false, errors: [] };
  const errors = [];
  const read = (p) => (existsSync(join(root, p)) ? readFileSync(join(root, p), 'utf8') : null);

  let pkg = {};
  try {
    pkg = JSON.parse(read('package.json') ?? '{}');
  } catch {
    errors.push('package.json går inte att läsa');
  }
  if (!VERSION.test(String(pkg.version ?? ''))) {
    errors.push(`package.json: version ${JSON.stringify(pkg.version)} ska vara MAJOR.MINOR.PATCH (0–999 per del); versionen sätts bara där`);
  }

  const config = read('capacitor.config.ts');
  const appId = config === null ? null : readAppId(config);
  if (config === null) {
    errors.push('capacitor.config.ts saknas; iOS-projektet skapas med node scripts/ios-setup.mjs');
  } else if (appId === null || !BUNDLE_ID.test(appId)) {
    errors.push('capacitor.config.ts: appId ska stå en gång och vara spelets bundle-id (se.computercat.<slug utan bindestreck>)');
  } else if (!/^\s*webDir:\s*'out',\s*$/m.test(config)) {
    errors.push("capacitor.config.ts: webDir ska vara 'out'");
  }

  const pbx = read('ios/App/App.xcodeproj/project.pbxproj');
  if (pbx === null) {
    errors.push('ios/App/App.xcodeproj/project.pbxproj saknas; iOS-projektet skapas med node scripts/ios-setup.mjs');
  } else if (appId !== null) {
    const ids = [...new Set([...pbx.matchAll(/^\s*PRODUCT_BUNDLE_IDENTIFIER = "?([^";]+)"?;/gm)].map((m) => m[1]))];
    if (ids.length !== 1 || ids[0] !== appId) {
      errors.push(`project.pbxproj: PRODUCT_BUNDLE_IDENTIFIER är ${ids.join(', ') || 'saknas'}, ska vara ${appId} som i capacitor.config.ts`);
    }
  }

  const plist = read('ios/App/App/Info.plist');
  if (plist === null) {
    errors.push('ios/App/App/Info.plist saknas');
  } else {
    if (!/<key>ITSAppUsesNonExemptEncryption<\/key>\s*<false\s*\/>/.test(plist)) {
      errors.push('Info.plist: ITSAppUsesNonExemptEncryption ska vara false');
    }
    if (!/<key>CFBundleShortVersionString<\/key>\s*<string>\$\(MARKETING_VERSION\)<\/string>/.test(plist)) {
      errors.push('Info.plist: CFBundleShortVersionString ska vara $(MARKETING_VERSION); versionen sätts bara i package.json');
    }
    if (!/<key>CFBundleVersion<\/key>\s*<string>\$\(CURRENT_PROJECT_VERSION\)<\/string>/.test(plist)) {
      errors.push('Info.plist: CFBundleVersion ska vara $(CURRENT_PROJECT_VERSION); byggnumret sätts av Computercat');
    }
  }

  for (const [name, section] of CAPACITOR_PACKAGES) {
    const got = pkg.dependencies?.[name] ?? pkg.devDependencies?.[name];
    if (got === undefined) {
      errors.push(`package.json: ${name} saknas; byggguiden kräver ${name} ${CAPACITOR} i ${section}`);
    } else if (got !== CAPACITOR) {
      errors.push(`package.json: ${name} är ${got}, byggguiden kräver exakt ${CAPACITOR}; ändra inte Capacitors version i spelet`);
    }
  }
  const spm = read('ios/App/CapApp-SPM/Package.swift');
  if (spm === null) {
    errors.push('ios/App/CapApp-SPM/Package.swift saknas: iOS-projektet ska använda Swift Package Manager');
  } else if (!spm.includes(`capacitor-swift-pm.git", exact: "${CAPACITOR}"`)) {
    errors.push(`Package.swift: capacitor-swift-pm ska vara exakt ${CAPACITOR}; kör npx cap sync ios`);
  }
  if (existsSync(join(root, 'ios', 'App', 'Podfile'))) {
    errors.push('ios/App/Podfile finns: byggguiden använder Swift Package Manager, inte CocoaPods');
  }

  for (const { rel, link } of walk(root, 'ios')) {
    if (link) {
      errors.push(`${rel}: symbolisk länk; ios/ får inte innehålla länkar (en länk kan peka ut ur repot i bygget)`);
    } else if (SECRET_FILE.test(rel) || basename(rel) === 'ExportOptions.plist') {
      errors.push(`${rel}: certifikat, nycklar, profiler och exportinställningar hör inte hemma i repot; Computercat signerar`);
    }
  }

  return { present: true, errors };
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const { present, errors } = checkIos(process.cwd());
  if (!present) {
    console.log('check-ios: ingen ios/ (spelet är inte förberett för iOS); inget att kontrollera.');
  } else if (errors.length > 0) {
    for (const e of errors) console.error(`check-ios: ${e}`);
    process.exit(1);
  } else {
    console.log('check-ios: OK');
  }
}
```

- [ ] **Steg 3: `scripts/ios-setup.mjs`.** Skapa `resources/byggguide/v3/files/scripts/ios-setup.mjs`:

```js
// Byggguide v3: skapar spelets iOS-projekt en gång (Capacitor 8.5.3, Swift Package Manager).
//   node scripts/ios-setup.mjs <bundle-id> "<spelets namn>"
// bundle-id: get_release_status, targets.app_store.ios.bundle_id. Namnet: get_game, name.
// Skriver capacitor.config.ts, kör cap add ios ur node_modules (utan skal) och lägger in
// exportdeklarationen. Kontrollerar resultatet med check-ios.mjs.
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { addExportCompliance, capacitorConfigSource, checkIos } from './check-ios.mjs';

function capAddIos(root) {
  const cap = join(root, 'node_modules', '.bin', 'cap');
  if (!existsSync(cap)) throw new Error('node_modules/.bin/cap saknas; kör npm ci --ignore-scripts först');
  const r = spawnSync(cap, ['add', 'ios', '--packagemanager', 'SPM'], { cwd: root, stdio: 'inherit' });
  if (r.status !== 0) throw new Error(`cap add ios misslyckades (exitkod ${r.status})`);
}

export function setupIos({ root, appId, appName, addIos = capAddIos }) {
  // Indata först: ett ogiltigt id eller namn ska aldrig lämna något efter sig.
  const config = capacitorConfigSource(appId, appName);
  if (existsSync(join(root, 'ios'))) {
    throw new Error('ios/ finns redan. ios-setup körs bara en gång; kontrollera projektet med node scripts/check-ios.mjs');
  }
  const configPath = join(root, 'capacitor.config.ts');
  if (existsSync(configPath) && readFileSync(configPath, 'utf8') !== config) {
    throw new Error('capacitor.config.ts finns redan med annat innehåll; ändra den inte utan att fråga en människa');
  }
  if (!existsSync(join(root, 'out', 'index.html'))) {
    throw new Error('out/index.html saknas; kör npm run build först (Capacitor kopierar den statiska exporten)');
  }

  writeFileSync(configPath, config);
  addIos(root);

  const plistPath = join(root, 'ios', 'App', 'App', 'Info.plist');
  if (!existsSync(plistPath)) throw new Error('ios/App/App/Info.plist saknas efter cap add ios');
  writeFileSync(plistPath, addExportCompliance(readFileSync(plistPath, 'utf8')));

  const { errors } = checkIos(root);
  if (errors.length > 0) throw new Error(`iOS-projektet klarar inte kontrollen:\n- ${errors.join('\n- ')}`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const [appId = '', appName = ''] = process.argv.slice(2);
  try {
    setupIos({ root: process.cwd(), appId, appName });
    console.log(`ios-setup: OK (${appId}). Kör npm run check och committa capacitor.config.ts och ios/.`);
  } catch (e) {
    console.error(`ios-setup: ${e instanceof Error ? e.message : String(e)}`);
    process.exit(1);
  }
}
```

- [ ] **Steg 3b: bara om Task 0 gav `IOS_ADD_WAY=B` (R15).** Kontrollera: `source $SCRATCH/env.sh && echo "$IOS_ADD_WAY"`. Är det `A`: hoppa över steget. Är det `B`: ersätt funktionen `capAddIos` i `scripts/ios-setup.mjs` med koden nedan (samma namn, så att `setupIos` och testerna är oförändrade), och lägg till `spawnSync`-importen om den saknas. Det gör samma sak som Capacitors `cap add ios` (packar upp SPM-mallen, sätter bundle-id och namn med Capacitors egna uttryck) och sedan `cap sync ios`, som Task 0 steg 5 visade fungerar på Linux.

```js
// Väg B (R15): Capacitors egen SPM-mall ur node_modules, utan cap add (som inte fungerar på Linux).
function capAddIos(root) {
  const cli = join(root, 'node_modules', '@capacitor', 'cli');
  const template = join(cli, 'assets', 'ios-spm-template.tar.gz');
  const cap = join(root, 'node_modules', '.bin', 'cap');
  if (!existsSync(template) || !existsSync(cap)) throw new Error('@capacitor/cli saknas i node_modules; kör npm ci --ignore-scripts först');
  const config = readFileSync(join(root, 'capacitor.config.ts'), 'utf8');
  const appId = JSON.parse(/^\s*appId:\s*("(?:[^"\\]|\\.)*"),\s*$/m.exec(config)[1]);
  const appName = JSON.parse(/^\s*appName:\s*("(?:[^"\\]|\\.)*"),\s*$/m.exec(config)[1]);
  mkdirSync(join(root, 'ios'));
  const tar = spawnSync('tar', ['-xzf', template, '-C', join(root, 'ios')], { stdio: 'inherit' });
  if (tar.status !== 0) throw new Error(`kunde inte packa upp Capacitors iOS-mall (exitkod ${tar.status})`);
  // Som Capacitors editProjectSettingsIOS (dist/ios/common.js). appName är redan kontrollerad (R14).
  const pbx = join(root, 'ios', 'App', 'App.xcodeproj', 'project.pbxproj');
  writeFileSync(pbx, readFileSync(pbx, 'utf8').replace(/PRODUCT_BUNDLE_IDENTIFIER = ([^;]+)/g, `PRODUCT_BUNDLE_IDENTIFIER = ${appId}`));
  const plist = join(root, 'ios', 'App', 'App', 'Info.plist');
  writeFileSync(
    plist,
    readFileSync(plist, 'utf8').replace(/<key>CFBundleDisplayName<\/key>[\s\S]?\s+<string>([^<]*)<\/string>/, `<key>CFBundleDisplayName</key>\n        <string>${appName}</string>`),
  );
  const sync = spawnSync(cap, ['sync', 'ios'], { cwd: root, stdio: 'inherit' });
  if (sync.status !== 0) throw new Error(`cap sync ios misslyckades (exitkod ${sync.status})`);
}
```

Importraden blir `import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';`. Kör steg 5 och sedan Task 7, som provar vägen i en Linux-container.

- [ ] **Steg 4: `check` kör `check-ios`.**

```bash
cd $WT/resources/byggguide/v3/files && jq '.scripts.check += " && node scripts/check-ios.mjs"' package.json > package.tmp && mv package.tmp package.json && jq -r .scripts.check package.json
```

Förväntat: raden slutar med `&& node scripts/cc-gate.mjs && node scripts/check-ios.mjs`. (`npm install --package-lock-only` behövs inte: `scripts` står inte i låsfilen. Kontrollera: `git -C $WT diff --stat -- resources/byggguide/v3/files/package-lock.json` är tom.)

- [ ] **Steg 5: Kör guidens svit.**

Run: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run && npx tsc --noEmit && npx eslint && node scripts/check-ios.mjs`
Expected: PASS, inga typ- eller lintfel, sista raden `check-ios: ingen ios/ (spelet är inte förberett för iOS); inget att kontrollera.`

- [ ] **Steg 6: PHP-testet för bundle-id och per-spel-filer (Eriks krav).** Lägg till i `tests/Feature/BuildGuideV3Test.php`:

```php
    // Eriks krav: appId kommer från spelet. Ingen fil som följer med till spelet (utom testfixturer)
    // har ett färdigt bundle-id, och capacitor.config.ts och ios/ är spelets egna (TestFlight-planen
    // Task 4 punkt 4). Mönstret i check-ios.mjs står med \. och träffas inte.
    public function test_no_guide_file_hardcodes_a_bundle_id_and_ios_files_are_per_game(): void
    {
        $checked = 0;
        foreach (BuildGuide::manifest('v3')['files'] as $f) {
            $this->assertNotSame('capacitor.config.ts', $f['path']);
            $this->assertStringStartsNotWith('ios/', $f['path']);
            if (str_starts_with($f['path'], 'test/') || str_ends_with($f['path'], '.test.ts') || $f['path'] === 'package-lock.json') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/se\.computercat\.[a-z][a-z0-9]/', BuildGuide::file('v3', $f['path'])['content'], $f['path']);
            $checked++;
        }
        $this->assertGreaterThan(30, $checked);
    }

    // check-ios.mjs har guidens Capacitor-version som konstant; den får inte glida från manifestet.
    public function test_the_ios_check_pins_the_manifests_capacitor(): void
    {
        $script = BuildGuide::file('v3', 'scripts/check-ios.mjs')['content'];

        $this->assertSame(1, preg_match("/^export const CAPACITOR = '([0-9.]+)';$/m", $script, $m));
        $this->assertSame(BuildGuide::manifest('v3')['ios']['capacitor'] ?? '8.5.3', $m[1]);
    }

    public function test_check_runs_the_ios_check_last(): void
    {
        $this->assertStringEndsWith('&& node scripts/cc-gate.mjs && node scripts/check-ios.mjs', $this->package()['scripts']['check']);
        $paths = array_column(BuildGuide::manifest('v3')['files'], 'path');
        foreach (['scripts/check-ios.mjs', 'scripts/ios-setup.mjs', 'test/iosSetup.test.ts', 'src/lib/computercat/achievements.ts', 'src/lib/computercat/achievements.test.ts'] as $path) {
            $this->assertContains($path, $paths);
        }
    }
```

- [ ] **Steg 7: Hasha, testa, verify, commit.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest' && php artisan computercat:byggguide:verify v3
git add resources/byggguide/v3 tests/Feature/BuildGuideV3Test.php && git commit -m "Byggguide v3: ios-setup och check-ios (bundle-id från Computercat, kontroll i npm run check)"
```

Förväntat: `59 filer i v3.`, PASS och `Byggguide v3: OK (59 filer, …)` med `check-ios: ingen ios/ …` i utskriften.

---

### Task 6: Texterna — `CLAUDE.md`, README-mallen, `docs.test.ts` och `ios`-blocket

**Files:**
- Modify: `resources/byggguide/v3/files/CLAUDE.md` (ersätts helt)
- Modify: `resources/byggguide/v3/templates/README.md`
- Modify: `resources/byggguide/v3/files/test/docs.test.ts`
- Modify: `resources/byggguide/v3/manifest.json` (`ios`)
- Modify: `tests/Feature/BuildGuideV3Test.php`
- Modify: `tests/Feature/ReleaseFactbankSyncTest.php`

**Interfaces:**
- Consumes: `ReleaseNext::TESTFLIGHT_FLOW`, `BuildGuide::iosBlockIsValid`, `versionFromClaudeMd`; filerna från Task 1–5.
- Produces: v3 uppfyller TestFlight-kontraktet (punkt 1–5); `ReleaseFactbankSyncTest` kontrollerar v3 (olåst) och kräver minst en kontrollerad version.

`ios`-blocket och flödestexten läggs till i samma task: så fort manifestet har `ios` kräver `ReleaseFactbankSyncTest` texten i `CLAUDE.md`.

- [ ] **Steg 1: Fallerande tester.** Lägg till i `tests/Feature/BuildGuideV3Test.php` (och `use App\Releases\ReleaseNext;` överst):

```php
    // En hänvisning till v2 som version (adresser som /api/v1 är inte versionen). Avsnittet om
    // uppgradering från v2 räknas inte.
    private const V2_REFERENCE = '/byggguide v2|\*\*v2\*\*|"v2"/i';

    private function withoutUpgradeSection(string $md): string
    {
        return (string) preg_replace('/^## Uppgradera ett v2-spel till v3$.*?(?=^## )/ms', '', $md);
    }

    public function test_claude_md_is_v3_and_has_achievements_ios_and_the_testflight_flow(): void
    {
        $md = BuildGuide::claudeMd('v3');

        foreach (['Byggguide: **v3**', '{"version": "v3"}', '`Grundkod från byggguide v3`', '"build_guide": "v3"', '## Achievements', '`achievements.report', '`upsert_achievement`', 'hela listan', '`unknown`', '`rejected`', '`dropped`', '## iOS (Capacitor)', '`node scripts/ios-setup.mjs <bundle_id> "<name>"`', '`targets.app_store.ios.bundle_id`', '`check-ios: OK`', '## Skicka till TestFlight', '## Uppgradera ett v2-spel till v3', '`npm run build` ska klara sig utan nätverk', '`const { env } = process`', 'inte avsiktligt skadlig spelkod'] as $text) {
            $this->assertStringContainsString($text, $md);
        }
        $this->assertMatchesRegularExpression('/^'.preg_quote(ReleaseNext::TESTFLIGHT_FLOW, '/').'$/m', $md);
        $this->assertSame('v3', BuildGuide::versionFromClaudeMd($md));
        $this->assertStringNotContainsString('(bygget ska klara sig utan nätverk)', $md);
        $this->assertDoesNotMatchRegularExpression(self::V2_REFERENCE, $this->withoutUpgradeSection($md));
        $this->assertStringContainsString('## Exemplets topplista', $this->withoutUpgradeSection($md));
    }

    // R10: listan i CLAUDE.md är exakt de filer som är nya eller ändrade jämfört med v2, och v3 tar inte bort någon.
    public function test_the_upgrade_list_is_exactly_the_files_v3_adds_or_changes(): void
    {
        $v2 = array_column(BuildGuide::manifest('v2')['files'], 'sha256', 'path');
        $v3 = BuildGuide::manifest('v3')['files'];
        $changed = [];
        foreach ($v3 as $f) {
            if (($v2[$f['path']] ?? null) !== $f['sha256']) {
                $changed[] = $f['path'];
            }
        }
        preg_match('/^Filer som är nya eller ändrade i v3 jämfört med v2:\n\n((?:- `[^`]+`\n)+)/m', BuildGuide::claudeMd('v3'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'Listan saknas i CLAUDE.md.');
        preg_match_all('/^- `([^`]+)`$/m', $m[1], $listed);
        $l = $listed[1];
        sort($changed);
        sort($l);
        $this->assertSame($changed, $l);
        $this->assertSame([], array_values(array_diff(array_keys($v2), array_column($v3, 'path'))));
    }

    public function test_readme_template_names_v3_achievements_and_ios(): void
    {
        $readme = BuildGuide::readme('v3', 'Spel');

        foreach (['enligt Computercats byggguide v3', '`achievements.report/list/mine`', '## iOS och TestFlight', '`node scripts/ios-setup.mjs', 'och iOS-kontrollen', 'inte avsiktligt skadlig spelkod'] as $text) {
            $this->assertStringContainsString($text, $readme);
        }
        $this->assertDoesNotMatchRegularExpression(self::V2_REFERENCE, $readme);
    }

    // TestFlight-planen Task 4 punkt 1: kontraktet, och Capacitor i stacken i samma version.
    public function test_manifest_has_the_ios_contract(): void
    {
        $m = BuildGuide::manifest('v3');

        $this->assertSame(['capacitor' => '8.5.3', 'package_manager' => 'spm', 'project' => 'ios/App/App.xcodeproj', 'scheme' => 'App'], $m['ios']);
        $this->assertTrue(BuildGuide::iosBlockIsValid($m['ios']));
        foreach (['@capacitor/core', '@capacitor/ios', '@capacitor/cli'] as $package) {
            $this->assertSame($m['ios']['capacitor'], $m['stack'][$package], $package);
        }
    }

    public function test_docs_test_guards_the_new_texts(): void
    {
        $docs = BuildGuide::file('v3', 'test/docs.test.ts')['content'];

        foreach (["'\"build_guide\": \"v3\"'", "'achievements.report'", "'scripts/ios-setup.mjs'", "'scripts/check-ios.mjs'", "'promote_release(target: app_store, stage: testflight'"] as $text) {
            $this->assertStringContainsString($text, $docs);
        }
    }
```

I `tests/Feature/ReleaseFactbankSyncTest.php`, i `test_testflight_flow_is_verbatim_in_the_article_the_next_text_and_every_ios_build_guide`: ersätt de två kommentarraderna `// Låsta och olåsta versioner: …` och `// Loopen nedan är avsiktligt tom …` med `// Låsta och olåsta versioner med ios-block (v3 och senare): en ny version får fel innan låsningen, inte efter.`, lägg `$checked = 0;` före `foreach ($manifests as $manifest) {`, `$checked++;` sist i loopen, och efter loopen:

```php
        $this->assertGreaterThan(0, $checked, 'Ingen byggguideversion har ios-block; v3 ska ha det.');
```

Run: `cd $WT && php artisan test --filter='BuildGuideV3Test|ReleaseFactbankSyncTest'`
Expected: FAIL (texterna och `ios` saknas; `$checked` är 0).

- [ ] **Steg 2: `CLAUDE.md` för v3.** Ersätt `resources/byggguide/v3/files/CLAUDE.md` helt med (raden under "## Skicka till TestFlight" är `ReleaseNext::TESTFLIGHT_FLOW` ordagrant; kontrollera efteråt med testet, inte med ögat):

````markdown
# CLAUDE.md

Det här är ett Computercat-spel. Spelets idé står under "Idé" i `README.md`; läs den först.

Byggguide: **v3**. Grundkoden (Computercat-klienten med topplistor, sparningar och achievements, konfiguration, tester, säkerhetskontroller, skripten för iOS och ett exempelspel) kommer från Computercats byggguide. Det finns inget mallrepo.

## Steg 1: grundkoden (bara när `package.json` saknas)

Ett nytt spelrepo innehåller bara `README.md` och den här filen. Gör så här innan något annat:

1. Anropa `get_build_guide` i Computercat-connectorn med `{"version": "v3"}`. Svaret listar stacken med exakta versioner, installationskommandot, de obligatoriska kontrollerna och varje fil med `path`, `sha256` och `delivery`.
2. Filer med `delivery: "mcp"`: hämta med `get_build_guide_file` (`{"path": "<path>", "version": "v3"}`) och skriv `content` **exakt** till `path` i repots rot. Filer med `delivery: "download"` (bland annat `package-lock.json`): `curl -fsS "<download_url>" -o "<path>"`.
3. Kontrollera varje fil mot manifestet med `sha256sum <path>` (eller `shasum -a 256 <path>`). Avviker en fil: hämta den igen. Ändra den aldrig för hand.
4. `README.md` finns inte i fillistan; låt den vara. `CLAUDE.md` finns i listan med samma innehåll som den här filen.
5. Kör `npm ci --ignore-scripts`, sedan `npm run check` och `npm audit --omit=dev --audit-level=high`. Alla ska gå igenom utan att du ändrat något.
6. Committa allt med meddelandet `Grundkod från byggguide v3` och pusha direkt till `main` (ingen annan gren). Coolify bygger då Demo för första gången. Följ upp med `get_provisioning_status` tills `demo.state` är `live`; Demo visar då exemplet (klickrace).
7. Bygg sedan spelet enligt idén och reglerna nedan.

Faller ett steg (verktyget svarar med fel, en kontroll blir röd utan att du ändrat något, `sha256` stämmer inte efter ny hämtning): stanna och beskriv felet. Laga aldrig byggguidens filer för att få det grönt; felet hör hemma i computercat-api.

## Regler

- **Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.** All kommunikation går genom `src/lib/computercat/`.
- **Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg** (`get_game`, `upsert_*`). **Hämta integrationsmanualen med `get_integration_guide`** innan du använder en byggsten du inte använt förut.
- **Fråga faktabanken innan du bestämmer något om teknik, tester eller säkerhet** (se "Faktabanken" nedan).
- **Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.** Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
- **Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.**
- **Klientkoden i `src/lib/computercat/` och skripten i `scripts/` kommer från byggguiden.** Ändra dem inte i spelet; behövs en ändring hör den hemma i en ny byggguideversion i computercat-api.
- **Appens version står bara i `package.json`** (`version`, MAJOR.MINOR.PATCH). Sätt aldrig version eller byggnummer i `ios/`, och ändra aldrig bundle-id:t.

## Faktabanken

Computercat har en faktabank om hur vi bygger spel: val av teknik (det som redan finns i stacken går före nytt), vilka tester ett spel ska ha, säkerhetskontrollerna före Demo, best practice och vägen från idé till Live.

- **När du frågar:** innan du väljer ett bibliotek eller lägger till ett beroende, innan du skriver tester, innan du sätter upp topplistor, achievements eller anti-cheat, när ett bygge eller en kontroll faller, och före `promote_release`.
- **Hur:** `search_facts` med `{"query": "<några ord>", "build_guide": "v3"}` och läs träffen med `get_fact` (`{"id": "<id>"}`). `list_facts` med `{"build_guide": "v3"}` visar allt som finns. Skicka alltid med `"build_guide": "v3"`, så får du bara artiklar som gäller det här spelet.
- **En artikel går före din egen bedömning.** Följ dess regel även om du hade valt annorlunda.
- **Ett stort beslut som ingen artikel täcker** (ny tjänst, ny arkitektur, ett beroende med stor påverkan, något som rör säkerhet, personuppgifter eller pengar): fråga en människa innan du bygger.

## Köra och testa lokalt

```bash
npm ci --ignore-scripts      # installationsskript körs aldrig
cp .env.example .env.local    # NEXT_PUBLIC_COMPUTERCAT_GAME=<spelets slug>
npm run dev                    # http://localhost:3000 (localhost är tillåtet i Computercats CORS)
npm test                       # enhetstester, inget nätverk
npm run check                  # typkontroll, lint, test, bygge, kontroll av out/, säkerhetskontrollen och iOS-kontrollen
```

**Före varje push till `main`:** (1) `npm run check` grön, (2) `npm audit --omit=dev --audit-level=high` utan träff (avslutar med fel vid high eller critical i produktionsberoenden; åtgärda med `npm audit fix` eller uppdatera paketet, tysta aldrig). Bygget är en statisk export (`output: 'export'`): inga API-routes, inga serverkomponenter som hämtar data, ingen `next/font/google`. `npm run build` ska klara sig utan nätverk; `npm run check` och `npm audit` behöver nätverk, eftersom granskningen hämtar sårbarhetsdata från npm-registret. `npm audit` utan `--omit=dev` visar en high i braces via eslint; den finns bara i utvecklingsberoenden, ingen rättad version finns och fyndet är accepterat. Produktionsgranskningen (`npm audit --omit=dev --audit-level=high`) är ren, och det är den som spärren kör.

## Säkerhetsspärren i Coolify

Coolify bygger Demo med ett byggkommando som Computercat äger: `npm run build`, sedan hämtas `scripts/cc-gate.mjs` över https från den låsta byggguiden i Computercat och körs. Faller en kontroll deployas inget, och Demo visar förra bygget. Att ta bort eller ändra skripten eller `check` stänger inte av spärren; spärren fångar misstag och kända mönster, inte avsiktligt skadlig spelkod (`npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön).

- `scripts/cc-gate.mjs` fäller först på hemligheter i `out/` (privata nycklar, GitHub-, Slack-, Stripe- och AWS-nycklar, JWT), på source maps (`*.map`), på dolda filer (`.env*`, `.git` med flera) och på HTML-sidor utan byggguidens Content-Security-Policy. Den fäller också på symboliska länkar i `out/` och på externa resurser (script, stilmallar, bilder, iframes, `<base>` med flera som pekar utanför samma ursprung), eftersom Next lägger dem ovanför policyn. Policyn sätts i `src/app/layout.tsx` med `src/lib/computercat/csp.ts` (bara i produktionsbygget, så `npm run dev` påverkas inte); ändra den inte. Felet anger fil och mönstrets namn, aldrig värdet.
- Därefter kör `scripts/cc-gate.mjs` `npm audit --omit=dev --audit-level=high` och fäller med paketnamn och allvarlighetsgrad vid high eller critical i produktionsberoenden. Svarar inte npm-registret försöker skriptet en gång till efter 10 sekunder och fäller sedan med "npm-registret svarade inte": vänta och pusha igen.
- `npm run check` kör samma `scripts/cc-gate.mjs`, så du får samma fel lokalt som Coolify.
- Visar `get_provisioning_status` `deploy_failed`: kör `npm run check` lokalt, slå upp felet med `search_facts` och pusha igen.

Lint förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med en sträng och `process.env.NEXT_PUBLIC_*` utöver `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`. I spelets kod i `src/` (utom testfiler) får `process` bara förekomma som `process.env.NODE_ENV`, `process.env.NEXT_PUBLIC_COMPUTERCAT_GAME` eller `process.env.NEXT_PUBLIC_COMPUTERCAT_URL`: inga alias (`const p = process`), ingen destrukturering (`const { env } = process`) och inga omvägar via `globalThis`, `self` eller `window`. Stäng aldrig av en regel. Behövs något av det: fråga faktabanken och sedan en människa.

## Computercat i koden

- `getComputercat()` ger klienten i webbläsaren. Den kastar aldrig: saknas `NEXT_PUBLIC_COMPUTERCAT_GAME` (eller körs den utanför webbläsaren) returnerar den `{ ok: false, error }`, och det felet ska visas för spelaren.
- Spelaren (token) förnyas automatiskt vid start om den är äldre än 24 h (tokens gäller 90 dagar); du behöver inte göra något. `recordScore` kastar om resultatet inte kan sparas lokalt (full lagring): visa det för spelaren.
- Resultat: `recordScore(typ, score, metadata?)` köar lokalt och skickar i bakgrunden. Visa `flushed`-resultatet för spelaren; dölj aldrig fel och visa aldrig påhittad topplistedata.
- Topplistor: `leaderboards.get(typ)` (aktuell period enligt servern, UTC), `leaderboards.me(typ)`.
- Achievements: `achievements.report(slugs)`, `achievements.list()`, `achievements.mine()` (se "Achievements").
- Sparningar: `saves.sync(nyckel, lokalt, merge)` hämtar, slår ihop och skickar med version.
- Anti-cheat: `createProof(...)` i `metadata.proof` om spelet kräver bevis.
- Bakgrunden: `BackgroundSync` (monterad i `src/app/layout.tsx`) anropar `flushAll()` vid start, vid "online" och varje minut; den tömmer resultatkön och achievementkön. Vill spelet visa vad bakgrunden skickade: `onFlushed(cb)` ger `{ scores, achievements }` efter varje tömning och returnerar en funktion som avslutar prenumerationen.

## Achievements

1. Skapa definitionerna med `upsert_achievement` (slug högst 50 tecken, `name`, `description`, `icon` högst 20 tecken). Använd inga siffror i en slug som inte är en tröskel i spelets `settings.achievement_thresholds`.
2. Villkoren utvärderas i spelet. Spara upplåsningen i spelets egen sparning först, rapportera sedan: `achievements.report(['forsta-rundan'])`.
3. Rapportera hela listan med spelarens upplåsningar vid varje start. Det är ofarligt (servern låser upp en gång) och tar med det som avvisades tidigare.
4. `report` kastar `RangeError` direkt för en ogiltig slug (samma regler som `upsert_achievement`: högst 50 tecken, gemener, siffror, `-`, `_` och `.`, först en bokstav eller siffra) och `Error` när kön inte kan sparas lokalt. Fånga det och visa det.
5. `report` ger `{ queued, flushed }`. `flushed` blir `{ sent, unlocked, unknown, rejected, dropped, remaining }`:
   - `unknown`: ingen aktiv definition med den sluggen (felstavad eller inte skapad). Visa det och skapa definitionen med `upsert_achievement`.
   - `rejected`: avvisad av rimlighetskontrollen (spelarens molnsparning har för få resultat för tröskeln). Den tas ur kön och kommer med nästa gång spelet rapporterar hela listan.
   - `dropped`: Computercat tog inte emot omgången (till exempel 422); visa skälet.
   - `remaining` över 0: köad lokalt; skickas när nätet är tillbaka (offline, 5xx, 401, 408 och 429 väntar).
6. `achievements.list()` ger definitionerna (`is_secret` sann och `description` `???` för hemliga), `achievements.mine()` spelarens upplåsta.

```ts
const cc = getComputercat();
if (cc.ok) {
  try {
    const { flushed } = cc.client.achievements.report(unlockedSlugs);
    flushed.then(
      (r) => {
        if (r.unknown.length > 0) show(`Okända achievements: ${r.unknown.join(', ')}`);
        if (r.rejected.length > 0) show(`Avvisade: ${r.rejected.join(', ')}`);
        if (r.dropped.length > 0) show(r.dropped.map((d) => `${d.slug}: ${d.reason}`).join('; '));
      },
      (e: unknown) => show(describeError(e)),
    );
  } catch (e) {
    show(describeError(e));
  }
}
```

## iOS (Capacitor)

Spelet kan byggas som iOS-app med Capacitor 8.5.3 (Swift Package Manager). Gör det här en gång, och bara när spelet ska till TestFlight:

1. Anropa `get_release_status` för spelet. `targets.app_store.ios.bundle_id` är spelets bundle-id (`se.computercat.<slug utan bindestreck>`). Saknas `app_store` i `release_targets`, eller är värdet `null`: stanna och be Erik sätta målet med `set_release_targets`. Hitta aldrig på ett bundle-id.
2. Anropa `get_game` och ta spelets `name`. Det blir appens namn på hemskärmen (1–50 tecken, utan `<`, `>` och `&`).
3. Kör `npm ci --ignore-scripts` och `npm run build`.
4. Kör `node scripts/ios-setup.mjs <bundle_id> "<name>"`. Skriptet skriver `capacitor.config.ts` (`appId` = bundle-id, `webDir: 'out'`), kör Capacitors `cap add ios --packagemanager SPM` ur `node_modules` och lägger in `ITSAppUsesNonExemptEncryption` = `false` i `ios/App/App/Info.plist`. Det körs en gång; finns `ios/` redan vägrar det.
5. Kör `npm run check`. Sista raden ska vara `check-ios: OK`.
6. Committa `capacitor.config.ts` och `ios/` med meddelandet `iOS-projekt (Capacitor)` och pusha till `main`. Coolify bygger Demo som vanligt; `ios/` påverkar inte webbygget.

Regler för `ios/` och `capacitor.config.ts`:

- De tillhör spelet men ändras inte för hand. `scripts/check-ios.mjs` och Computercats bygge kontrollerar `appId`, `PRODUCT_BUNDLE_IDENTIFIER` och `Info.plist`.
- Version och byggnummer sätts aldrig i `ios/`: `CFBundleShortVersionString` och `CFBundleVersion` står kvar som `$(MARKETING_VERSION)` och `$(CURRENT_PROJECT_VERSION)`. Versionen kommer från `package.json`, byggnumret från Computercat.
- Inga certifikat, nycklar, profiler, `ExportOptions.plist` eller byggflöden (`.github/workflows`, fastlane) i repot. Computercat bygger, signerar och laddar upp.
- Faller `ios-setup.mjs` halvvägs: `git status` visar vad körningen skapade. Ta bara bort det som inte är committat (`git clean -fd ios` och `rm capacitor.config.ts`), rätta orsaken och kör igen.
- Nya Capacitor-plugin eller annan inbyggd kod: fråga faktabanken och en människa först.

## Skicka till TestFlight

Skicka till TestFlight (kräver byggguide v3 eller senare i spelet, den med iOS-stöd). En push utan höjd version bygger aldrig appen; bara promote_release efter en versionshöjning gör det. 1. Höj version i package.json (MAJOR.MINOR.PATCH): högre än den senast släppta till TestFlight och inte använd förut. 2. Kör npm run check, committa och pusha till main. 3. Vänta tills Demo-bygget för commiten är grönt (get_provisioning_status visar demo.state live). 4. Anropa promote_release(target: app_store, stage: testflight, commit_sha: <commiten>). 5. Följ get_release_status tills släppet är klart. Computercat sätter taggen v<version>, väljer byggnummer, bygger appen och lägger den i TestFlight-gruppen ComputerCat intern.

Svarar `promote_release` att versionen är för låg eller att taggen redan finns: höj `version` i `package.json` som svaret säger och börja om från punkt 1. Följ alltid `next` i `get_release_status`. iOS-projektet måste finnas (se "iOS (Capacitor)") innan första släppet.

## Uppgradera ett v2-spel till v3

Gäller bara spel vars `CLAUDE.md` har `Byggguide: **v2**`. Ett v1-spel: fråga en människa.

1. Hämta manifestet för spelets nuvarande version och för v3 med `get_build_guide`.
2. För varje fil i v3:s manifest: finns den inte i spelet, eller har spelets fil samma `sha256` som i den gamla versionens manifest (en oförändrad guidefil), skriv v3:s fil exakt och kontrollera `sha256`.
3. En fil som spelet har ändrat (till exempel exemplets filer, `src/app/page.tsx` och `src/app/layout.tsx`) behålls. Står den i listan nedan: för in v3:s ändring för hand, och fråga en människa om det är oklart.
4. `package.json`: behåll spelets `name` och de beroenden spelet har lagt till, sätt varje paket i v3:s `stack` till exakt den versionen, lägg `node scripts/check-ios.mjs` sist i `check` som i v3:s fil, och kör `npm install --package-lock-only --ignore-scripts`. Ta v3:s `package-lock.json` bara om spelet inte har egna beroenden.
5. Kör `npm ci --ignore-scripts`, `npm run check` och `npm audit --omit=dev --audit-level=high`. Alla ska gå igenom.
6. Committa med meddelandet `Byggguide v3` och pusha till `main`. Fortsätt med "iOS (Capacitor)" när spelet ska till TestFlight.

Filer som är nya eller ändrade i v3 jämfört med v2:

- `CLAUDE.md`
- `eslint.config.mjs`
- `package-lock.json`
- `package.json`
- `scripts/check-ios.mjs`
- `scripts/ios-setup.mjs`
- `src/components/BackgroundSync.tsx`
- `src/lib/computercat/achievements.test.ts`
- `src/lib/computercat/achievements.ts`
- `src/lib/computercat/index.test.ts`
- `src/lib/computercat/index.ts`
- `src/lib/computercat/queue.ts`
- `test/docs.test.ts`
- `test/eslintRules.test.ts`
- `test/iosSetup.test.ts`

## Exemplets topplista

Exemplet skickar till `daily-clicks`. Skapa den innan du testar på Demo:

`upsert_leaderboard_type` med `{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}`

När exemplet ersätts: skapa spelets egna topplistor på samma sätt, byt `LEADERBOARD_TYPE` i `src/game/constants.ts` och ta bort `src/game/exampleDocs.test.ts` tillsammans med exemplet. Byt också `title` i `src/app/layout.tsx`, `name` i `package.json` (kör sedan `npm install --package-lock-only --ignore-scripts`) och lagringsnyckeln `spelmall-click` (prefixet i `src/game/clickStore.ts`) till spelets namn.

Låt de två raderna `computercat:idea` i `README.md` vara kvar; `test/docs.test.ts` vaktar dem.

## Säkerhet

Allt i `NEXT_PUBLIC_*` hamnar i den publika koden. Lägg aldrig nycklar eller lösenord där eller i repot.
````

Kontrollera att `src/game/exampleDocs.test.ts` (oförändrad från v2) fortfarande hittar det den läser i `CLAUDE.md`: `source $SCRATCH/env.sh && rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run src/game/exampleDocs.test.ts`. Förväntat: PASS. Faller den: läs vad den kräver och rätta `CLAUDE.md` (inte testet).

- [ ] **Steg 3: `test/docs.test.ts`.** I `resources/byggguide/v3/files/test/docs.test.ts`, ersätt raden `      '"build_guide": "v2"',` med:

```ts
      '"build_guide": "v3"',
      'achievements.report',
      'scripts/ios-setup.mjs',
      'scripts/check-ios.mjs',
      'promote_release(target: app_store, stage: testflight',
```

- [ ] **Steg 4: README-mallen.** I `resources/byggguide/v3/templates/README.md`, fem ändringar:

1. `enligt Computercats byggguide v2.` → `enligt Computercats byggguide v3.`
2. Raden `npm run check   # typkontroll, lint, test, bygge, kontroll av out/ och säkerhetskontrollen` → `npm run check   # typkontroll, lint, test, bygge, kontroll av out/, säkerhetskontrollen och iOS-kontrollen`
3. Raden `- resultat köas lokalt först och skickas i bakgrunden (vid start, när nätet kommer tillbaka och varje minut),` → `- resultat och achievements köas lokalt först och skickas i bakgrunden (vid start, när nätet kommer tillbaka och varje minut),`
4. Raden `- topplistor (`leaderboards.get/me/submit`), sparningar med version (`saves.get/put/sync`) och anti-cheat-bevis (`createProof`).` → `- topplistor (`leaderboards.get/me/submit`), achievements (`achievements.report/list/mine`), sparningar med version (`saves.get/put/sync`) och anti-cheat-bevis (`createProof`).`
5. Före raden `## Idé-markörerna`, lägg till:

```markdown
## iOS och TestFlight

Spelet kan byggas som iOS-app med Capacitor 8.5.3 (Swift Package Manager). iOS-projektet skapas en gång med `node scripts/ios-setup.mjs <bundle-id> "<namn>"` (bundle-id från Computercat-verktyget `get_release_status`), och `npm run check` kontrollerar det med `scripts/check-ios.mjs`. Appen byggs, signeras och laddas upp av Computercat, aldrig från repot, och bara när `version` i `package.json` har höjts. Hur det går till står under "Skicka till TestFlight" i `CLAUDE.md`.

```

Kontrollera: `grep -c 'byggguide v2' $WT/resources/byggguide/v3/templates/README.md` ger `0`.

- [ ] **Steg 5: `ios`-blocket i manifestet.**

```bash
cd $WT/resources/byggguide/v3 && jq '.ios = {"capacitor": "8.5.3", "package_manager": "spm", "project": "ios/App/App.xcodeproj", "scheme": "App"}' manifest.json > manifest.tmp && mv manifest.tmp manifest.json && jq -c .ios manifest.json
```

- [ ] **Steg 6: Hasha, testa, verify.**

```bash
cd $WT && php artisan computercat:byggguide:hash v3 && php artisan test --filter='BuildGuideV3Test|BuildGuideTest|ReleaseFactbankSyncTest' && php artisan computercat:byggguide:verify v3
```

Förväntat: `59 filer i v3.`, PASS (flödestexten ordagrant, listan i "Uppgradera" stämmer med manifesten) och `Byggguide v3: OK (59 filer, …)`. Fäller `test_the_upgrade_list_is_exactly_the_files_v3_adds_or_changes`: rätta listan i `CLAUDE.md` efter testets diff (en fil som ändrats utan avsikt rättas i stället i filen), hasha och kör igen.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add resources/byggguide/v3 tests/Feature/BuildGuideV3Test.php tests/Feature/ReleaseFactbankSyncTest.php && git commit -m "Byggguide v3: CLAUDE.md med achievements, iOS och Skicka till TestFlight; ios-blocket i manifestet"
```

---

### Task 7: Generalrepetition före låsningen — iOS lokalt, i simulatorn och Coolifys Node

**Files:**
- Modify: `$WT/docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md`
- Scratch: `$SCRATCH/rehearsal/`

**Interfaces:**
- Consumes: den olåsta, hashade v3 (Task 1–6).
- Produces: belägg för att v3:s filer, så som spelets Claude använder dem, ger (a) ett iOS-projekt, skapat med `ios-setup.mjs` på Linux utan Xcode, som klarar släpprepots byggjobbs kontroller och arkiveras osignerat med rätt version och byggnummer; (b) en app som i iOS-simulatorn når Computercats API genom CORS och CSP från `capacitor://localhost`; (c) ett Coolify-likt bygge på Node 22.14 med `ios/` i repot; (d) att uppgraderingen v2 → v3 enligt `CLAUDE.md` ger grön `npm run check`. Är något rött: rätta v3 i ägartasken (hash, verify, commit) och kör om berörda steg innan Task 8.

Inget skrivs mot GitHub, Coolify, Apple eller prod. Det lokala API:t kör i worktreens egen databas.

- [ ] **Steg 1: Skriv ut v3 och skapa iOS-projektet på Linux, som spelets Claude gör i en molnsession.** v3 skrivs ut på Mac (för `node_modules` till steg 2–3); `ios-setup.mjs` körs i en `node:22`-container (Linux, ingen Xcode) med en skrivbar utkatalog som tar emot `ios/` och `capacitor.config.ts`:

```bash
source $SCRATCH/env.sh && mkdir -p $SCRATCH/rehearsal/linux $SCRATCH/rehearsal/linux-out && cd $WT && R=$(php artisan computercat:byggguide:verify v3 --keep | sed -n 's/^Katalogen behålls: //p') && echo "REHEARSAL=$R" >> $SCRATCH/env.sh
rsync -a --exclude node_modules --exclude out --exclude .next "$R/" $SCRATCH/rehearsal/linux/src/
docker run --rm -e NEXT_TELEMETRY_DISABLED=1 -e NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-ios-v3 \
  -v $SCRATCH/rehearsal/linux/src:/src:ro -v $SCRATCH/rehearsal/linux-out:/out node:22 bash -c '
  cp -R /src /w && cd /w && (command -v xcodebuild || echo "ingen xcodebuild")
  npm ci --ignore-scripts > /dev/null 2>&1 && npm run build > /dev/null 2>&1; echo "build=$?"
  node scripts/ios-setup.mjs se.computercat.test "Spike v3"; echo "setup=$?"
  npm run check 2>&1 | tail -2
  node scripts/ios-setup.mjs se.computercat.test "Spike v3"; echo "andra-körningen=$?"
  cp -R ios capacitor.config.ts /out/'
ls $SCRATCH/rehearsal/linux-out
```

Förväntat: `ingen xcodebuild`, `build=0`, `ios-setup: OK (se.computercat.test). …`, `setup=0`, `check-ios: OK` sist i `npm run check`, den andra körningen `ios-setup: ios/ finns redan. …` och `andra-körningen=1`, och `ios` plus `capacitor.config.ts` i utkatalogen.

- [ ] **Steg 2: Släpprepots kontroller och osignerad arkivering på Mac, mot exakt det Linux-skapade `ios/`.** `ios/` och `capacitor.config.ts` från containern kopieras oförändrade in i Mac-utskriften; sedan samma kommandon som stegen "Capacitor sync", "Kontrollera bundle-id och exportdeklaration" och "Arkivera osignerat" i `release-ios.yml`:

```bash
source $SCRATCH/env.sh && cd "$REHEARSAL" && rm -rf ios && cp -R $SCRATCH/rehearsal/linux-out/ios ios && cp $SCRATCH/rehearsal/linux-out/capacitor.config.ts capacitor.config.ts
NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-ios-v3 npm run build > /dev/null && node scripts/check-ios.mjs
npx cap sync ios && cd ios/App && BID=se.computercat.test
APPID=$(node -p "require('./App/capacitor.config.json').appId"); echo "appId=$APPID"
IDS=$(grep -E '^[[:space:]]*PRODUCT_BUNDLE_IDENTIFIER = ' App.xcodeproj/project.pbxproj | sed -E 's/.*= "?([^";]+)"?;.*/\1/' | sort -u); echo "pbx=$IDS"
echo "enc=$(/usr/libexec/PlistBuddy -c 'Print :ITSAppUsesNonExemptEncryption' App/Info.plist)"
xcodebuild -project App.xcodeproj -scheme App -configuration Release -destination 'generic/platform=iOS' \
  -archivePath "$SCRATCH/rehearsal/App.xcarchive" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO \
  CURRENT_PROJECT_VERSION=4 MARKETING_VERSION=0.1.0 archive | tail -2
for K in CFBundleIdentifier CFBundleShortVersionString CFBundleVersion ITSAppUsesNonExemptEncryption; do
  echo "$K=$(/usr/libexec/PlistBuddy -c "Print :$K" "$SCRATCH/rehearsal/App.xcarchive/Products/Applications/App.app/Info.plist")"; done
cd "$REHEARSAL" && git init -q && git add -A && git status --short | grep -E '^A  (ios/App/App/public/|ios/App/App/capacitor.config.json)' ; echo "genererat-i-git=$?"
```

Förväntat: `check-ios: OK`, `appId=se.computercat.test`, `pbx=se.computercat.test`, `enc=false`, `** ARCHIVE SUCCEEDED **`, de fyra värdena `se.computercat.test`, `0.1.0`, `4`, `false`, och `genererat-i-git=1` (Capacitors `ios/.gitignore` håller den kopierade exporten och `capacitor.config.json` utanför; `git init` görs bara i den tillfälliga katalogen).

- [ ] **Steg 3: Appen i simulatorn mot det lokala API:t (CORS och CSP från `capacitor://localhost`).** Ett lokalt spel i worktreens databas (id sparas):

```bash
cd $WT && php artisan migrate --force > /dev/null
php artisan tinker --execute='$cols = Illuminate\Support\Facades\Schema::getColumnListing("games"); $need = ["slug", "name", "is_active", "is_listed"]; $miss = array_diff($need, $cols); $req = collect(Illuminate\Support\Facades\Schema::getColumns("games"))->filter(fn ($c) => ! $c["nullable"] && $c["default"] === null && ! $c["auto_increment"] && ! in_array($c["name"], [...$need, "id", "created_at", "updated_at"]))->pluck("name")->all(); echo "saknas: ".(implode(",", $miss) ?: "inga")." | krävs utöver: ".(implode(",", $req) ?: "inga")."\n";'
```

Förväntat `saknas: inga | krävs utöver: inga` (det finns ingen `GameFactory`; `Game::$fillable` är `slug`, `name`, `description`, `settings`, `allowed_origins`, `is_active`, `is_listed`). Annat: lägg till de krävda kolumnerna med rimliga värden i raden nedan, eller fråga om det är oklart. Skapa sedan spelet och spara id:t:

```bash
cd $WT && php artisan tinker --execute='$g = App\Models\Game::create(["slug" => "ccspike-ios-v3", "name" => "Spike iOS v3", "is_active" => true, "is_listed" => false]); echo "SPIKE_GAME_ID={$g->id}\n";' | tee -a $SCRATCH/env.sh
```

Starta API:t i bakgrunden med logg (run_in_background): `cd $WT && php artisan serve --host=127.0.0.1 --port=8123 > $SCRATCH/rehearsal/serve.log 2>&1`. Bygg exporten mot det lokala API:t och kör appen:

```bash
source $SCRATCH/env.sh && cd "$REHEARSAL" && NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-ios-v3 NEXT_PUBLIC_COMPUTERCAT_URL=http://127.0.0.1:8123/api/v1 npm run build > /dev/null && npx cap sync ios
cd ios/App && xcodebuild -project App.xcodeproj -scheme App -configuration Debug -sdk iphonesimulator -derivedDataPath "$SCRATCH/rehearsal/dd" CODE_SIGNING_ALLOWED=NO build | tail -1
SIM=$(xcrun simctl list devices available -j | jq -r '[.devices[][] | select(.name | startswith("iPhone"))][0].udid') && echo "SIM=$SIM" >> $SCRATCH/env.sh
xcrun simctl boot "$SIM" 2>/dev/null; xcrun simctl install "$SIM" "$SCRATCH/rehearsal/dd/Build/Products/Debug-iphonesimulator/App.app" && xcrun simctl launch "$SIM" se.computercat.test
```

Vänta 30 sekunder (Monitor med en until-slinga på loggen, högst 60 s), läs sedan:

```bash
grep -E 'api/v1/(auth|games)' $SCRATCH/rehearsal/serve.log | head -10
```

Förväntat: anrop till `/api/v1/auth/anonymous` och `/api/v1/games/ccspike-ios-v3/leaderboards/daily-clicks` (BackgroundSync och topplistan vid start). Syns bara `OPTIONS` eller inget alls: läs simulatorns konsol (`xcrun simctl spawn "$SIM" log show --last 2m --predicate 'process == "App"' | grep -iE 'CSP|Content Security|CORS|App Transport|NSURLError' | head`), stanna och fråga Erik (AskUserQuestion) med det som står där. Är orsaken App Transport Security mot `http://127.0.0.1` (bara den lokala provningen; prod är https): skriv det i bilagan och lita på acceptansen i Task 12 för CORS/CSP i appen. Stäng: `xcrun simctl shutdown "$SIM"` och stoppa `php artisan serve`. Spelraden ligger kvar i worktreens databas och försvinner med `wt-finish --drop-db`.

- [ ] **Steg 4: Coolify-likt bygge på Node 22.14 med `ios/` i repot.**

```bash
source $SCRATCH/env.sh && rsync -a --exclude node_modules --exclude out --exclude .next --exclude .git "$REHEARSAL/" $SCRATCH/rehearsal/linux/
docker run --rm -e NODE_ENV=production -e NPM_CONFIG_PRODUCTION=false -e NEXT_TELEMETRY_DISABLED=1 -e NEXT_PUBLIC_COMPUTERCAT_GAME=ccspike-ios-v3 \
  -v $SCRATCH/rehearsal/linux:/src:ro node:22.14.0 bash -c '
  cp -R /src /w && cd /w && ls -d ios capacitor.config.ts
  npm ci --ignore-scripts > /tmp/ci.log 2>&1; echo "ci=$? EBADENGINE=$(grep -c EBADENGINE /tmp/ci.log)"
  npm run build > /tmp/build.log 2>&1; echo "build=$?"; node scripts/cc-gate.mjs; echo "gate=$?"; test -f out/index.html && echo "out-ok"'
```

Förväntat: `ios` och `capacitor.config.ts` finns, `ci=0 EBADENGINE=0`, `build=0`, `check-security: OK …`, `cc-audit: OK …`, `gate=0`, `out-ok`. (Byggkommandot i Coolify är `npm run build && curl … cc-gate.mjs && node /tmp/cc-gate.mjs`; v3:s `cc-gate.mjs` är v2:s, R11.)

- [ ] **Steg 5: Uppgraderingen v2 → v3 enligt `CLAUDE.md` (R10).** En v2-utskrift får två ändringar som ett riktigt spel har (eget namn i `package.json`, ändrad `title` i `src/app/layout.tsx`), och uppgraderas sedan exakt enligt avsnittet "Uppgradera ett v2-spel till v3": oförändrade guidefiler (sha256 som i v2:s manifest) och nya filer skrivs från v3, ändrade filer behålls, `package.json` slås ihop.

```bash
source $SCRATCH/env.sh && cd $WT && U=$(php artisan computercat:byggguide:verify v2 --keep | sed -n 's/^Katalogen behålls: //p') && echo "UPGRADE=$U" >> $SCRATCH/env.sh
cd "$U" && jq '.name = "uppgraderingsprov"' package.json > p.tmp && mv p.tmp package.json && npm install --package-lock-only --ignore-scripts > /dev/null
sed -i '' "s/title: 'Spelmall'/title: 'Uppgraderingsprov'/" src/app/layout.tsx && grep -c Uppgraderingsprov src/app/layout.tsx
V2=$WT/resources/byggguide/v2/manifest.json; V3=$WT/resources/byggguide/v3/manifest.json
jq -r '.files[] | "\(.path)\t\(.source)"' "$V3" | while IFS=$'\t' read -r P S; do
  OLD=$(jq -r --arg p "$P" '.files[] | select(.path == $p) | .sha256' "$V2")
  if [ ! -e "$P" ] || [ "$(shasum -a 256 "$P" | cut -c1-64)" = "$OLD" ]; then mkdir -p "$(dirname "$P")" && cp "$WT/resources/byggguide/v3/$S" "$P"; else echo "behålls: $P"; fi
done
jq --slurpfile v3 "$WT/resources/byggguide/v3/files/package.json" '. as $g | $v3[0] | .name = $g.name' package.json > p.tmp && mv p.tmp package.json
npm install --package-lock-only --ignore-scripts > /dev/null && npm ci --ignore-scripts > /dev/null && NEXT_PUBLIC_COMPUTERCAT_GAME=byggguide-verify npm run check > "$SCRATCH/rehearsal/upgrade.log" 2>&1; echo "check=$?"; tail -3 "$SCRATCH/rehearsal/upgrade.log"
npm audit --omit=dev --audit-level=high > /dev/null; echo "audit=$?"; grep -m1 '^Byggguide:' CLAUDE.md
```

Förväntat: `behålls:` för `package.json`, `package-lock.json` och `src/app/layout.tsx` (och inget annat), `check-ios: ingen ios/ …` sist, `check=0`, `audit=0` och `Byggguide: **v3**.`. Kräver uppgraderingen något som avsnittet inte säger (ett fel i `npm run check`): rätta avsnittet i `CLAUDE.md` (Task 6, hash, verify, commit) och kör om steget. Avsnittet hålls kort; det som behövs här är det som ska stå där.

- [ ] **Steg 6: Bilagan.** Lägg till sist i bilagan:

```markdown
## Byggguide v3: generalrepetition före låsningen (<datum>)

v3 olåst (manifest-sha256 <första 12 tecken>), utskriven med `computercat:byggguide:verify v3 --keep`.

- `ios-setup.mjs se.computercat.test "Spike v3"`: <utfall>; andra körningen vägrar. `npm run check` slutar med `check-ios: OK`.
- Släpprepots kontroller lokalt (appId, `PRODUCT_BUNDLE_IDENTIFIER`, exportdeklaration): <utfall>. Osignerad arkivering med <Xcode-version>: <utfall>; arkivets Info.plist <fyra värden>. Den kopierade exporten och `capacitor.config.json` hamnar inte i git.
- `ios/` skapades i `node:22` (Linux, ingen Xcode) med väg <A|B> och arkiverades oförändrat på Mac.
- Uppgraderingen v2 → v3 enligt `CLAUDE.md` på en v2-utskrift: <utfall av npm run check>.
- Simulatorn (<enhet>, iOS <version>) mot lokalt API: <anrop i loggen | orsak om inget syntes>.
- Node 22.14.0 i Docker med `ios/` i repot: `npm ci` <utfall>, `EBADENGINE` <antal>, bygge och `cc-gate.mjs` <utfall>.
```

Fyll i varje `<…>` (kontroll: `sed -n '/^## Byggguide v3: generalrepetition/,$p' … | grep -c '<'` ger `0`). Städa bara det steget skapade: `rm -rf "$REHEARSAL" "$UPGRADE" "$SCRATCH/rehearsal"`.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md && git commit -m "Bilaga: generalrepetition av byggguide v3 (iOS lokalt, simulatorn, Node 22.14)"
```

---

### Task 8: Lås v3 och gör den till senaste version

**Files:**
- Modify: `app/Services/BuildGuide.php` (`LATEST`, `FROZEN`)
- Modify: `tests/Feature/BuildGuideTest.php` (`EXPECTED`, nytt test)

**Interfaces:**
- Consumes: v3 klar (Task 1–6) och generalrepetitionen grön (Task 7).
- Produces: `BuildGuide::LATEST === 'v3'`, `BuildGuide::FROZEN['v3']` = sha256 av `resources/byggguide/v3/manifest.json`, `BuildGuide::versions() === ['v1', 'v2', 'v3']`, `BuildGuide::supportsIos('v3') === true`. Nya provisioneringar fryser v3 (`RepoStep`); befintliga spel behåller sin version. `coolifyBuildCommand('v3')` pekar på v3:s REST (serveras i prod först efter deploy, som för v2).

- [ ] **Steg 1: Förutsättningar.** `cd $WT && git log --oneline -1 -- docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md` visar commiten från Task 7, och `php artisan computercat:byggguide:verify v3` är grön. Annars: stanna.

- [ ] **Steg 2: Testerna (fallerar tills låset finns).** I `tests/Feature/BuildGuideTest.php`, ersätt `EXPECTED` med:

```php
    private const EXPECTED = [
        'v1' => ['overrides' => ['vite' => '7.3.7']],
        'v2' => ['overrides' => ['vite' => '7.3.7']],
        'v3' => ['overrides' => ['vite' => '7.3.7']],
    ];
```

och lägg till efter `test_no_published_version_before_v3_supports_ios`:

```php
    // TestFlight-planen Task 4: v3 är den första låsta versionen som kan byggas för iOS, och den kör spärren i Coolify.
    public function test_v3_supports_ios_and_runs_the_gate_in_the_build(): void
    {
        $this->assertTrue(BuildGuide::supportsIos('v3'));
        $this->assertTrue(BuildGuide::runsSecurityChecksInBuild('v3'));
        $this->assertSame('v3', BuildGuide::versionFromClaudeMd(BuildGuide::claudeMd('v3')));
        config(['app.url' => 'https://api.computercat.co']);
        $this->assertSame(
            'npm run build && curl -fsS https://api.computercat.co/api/v1/build-guide/v3/files/scripts/cc-gate.mjs -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs',
            BuildGuide::coolifyBuildCommand('v3'),
        );
    }
```

Run: `cd $WT && php artisan test --filter=BuildGuideTest`
Expected: FAIL (`test_every_published_version_has_expectations`, `test_v3_supports_ios_and_runs_the_gate_in_the_build`).

- [ ] **Steg 3: Lås och byt senaste version i samma ändring.**

```bash
cd $WT && SHA=$(shasum -a 256 resources/byggguide/v3/manifest.json | cut -c1-64) && echo "$SHA"
sed -i '' "s/public const LATEST = 'v2';/public const LATEST = 'v3';/" app/Services/BuildGuide.php
perl -0pi -e "s/(        'v2' => '777cdc1e42f16ca26a7daa4a2634f60cea9898d3345e27b062c0668be5e29503',\n)/\$1        'v3' => '$SHA',\n/" app/Services/BuildGuide.php
grep -n "LATEST = \|'v[123]' => '" app/Services/BuildGuide.php
```

Förväntat: `public const LATEST = 'v3';`, v1- och v2-raderna oförändrade och en ny rad `'v3' => '<samma 64 tecken som ovan>',`.

- [ ] **Steg 4: Hela sviten.**

Run: `cd $WT && php artisan test`
Expected: PASS. Särskilt: `BuildGuideTest` kör alla parametriserade tester för v1, v2 och v3; `FactBankContentTest` validerar artiklar utan `build_guide` mot v3 (`LATEST`); `RepoStepTest` och `BuildGuideToolsTest` använder `LATEST`; `TestFlightPromoteTest` med v2 nekas fortfarande. Faller något som antog att senaste version är v2: ändra testet till `BuildGuide::LATEST` bara där det avsiktligt gäller senaste versionen; ett test som avsiktligt gäller v2 står kvar. Fel i en v3-text eller i manifestet: lås upp enligt Global Constraints, rätta, hasha, verify, lås igen.

- [ ] **Steg 5: Commit (en commit: låset och bytet tillsammans).**

```bash
cd $WT && git add app/Services/BuildGuide.php tests/Feature/BuildGuideTest.php && git commit -m "Byggguide v3 låst och senaste version (FROZEN['v3'] och LATEST i samma commit)"
git show --stat HEAD | grep -c 'app/Services/BuildGuide.php'    # förväntat: 1
```

---

### Task 9: Faktabanken för v3

**Files:**
- Modify: `resources/faktabank/security/security-csp.md`, `security-lint-regler.md`, `security-ratta-sparrfel.md`, `security-sparren-i-bygget.md`, `security-sparfiler.md`, `resources/faktabank/testing/testing-npm-run-check.md`, `resources/faktabank/stack/stack-samma-upplagg.md`, `resources/faktabank/process/process-fraga-manniska.md`, `process-slappflode.md`, `resources/faktabank/practice/practice-offline-kon.md`
- Create: `resources/faktabank/practice/practice-achievements.md`, `resources/faktabank/process/process-ios-capacitor.md`
- Modify: `tests/Feature/FactBankContentTest.php`

**Interfaces:**
- Consumes: v3 låst (Task 8); `FactBank`-valideringen (rubrikerna `## Regel`, `## Varför`, `## Så gör du`, `## Källa`; spelkällor under `src/`, `scripts/`, `test/` måste finnas i varje listad version; API-källor under `resources/`, `app/`, `routes/`, `config/`, `docs/` måste finnas i computercat-api).
- Produces: `search_facts`/`list_facts` med `build_guide: "v3"` ger samma regler som för v2 plus två nya artiklar.

- [ ] **Steg 1: Fallerande test.** I `tests/Feature/FactBankContentTest.php`, i listan i `test_common_questions_find_the_right_article`, lägg till efter `'bundle-id app store' => 'process-slapp-engangssteg',`:

```php
            'rapportera achievements' => 'practice-achievements', 'unknown rejected upplåsning' => 'practice-achievements',
            'ios-projekt capacitor' => 'process-ios-capacitor', 'ios-setup' => 'process-ios-capacitor',
```

och lägg till ett test:

```php
    // Byggguide v3: artiklarna som gällde v2 gäller också v3, och de nya gäller bara v3.
    public function test_v2_articles_also_apply_to_v3(): void
    {
        $bank = new FactBank;
        foreach ($bank->all() as $fact) {
            if (is_array($fact['build_guide']) && in_array('v2', $fact['build_guide'], true)) {
                $this->assertContains('v3', $fact['build_guide'], $fact['id']);
            }
        }
        $ids = array_column($bank->all(null, 'v3'), 'id');
        $this->assertContains('practice-achievements', $ids);
        $this->assertContains('process-ios-capacitor', $ids);
        $this->assertNotContains('practice-achievements', array_column($bank->all(null, 'v2'), 'id'));
    }
```

Run: `cd $WT && php artisan test --filter=FactBankContentTest`
Expected: FAIL.

- [ ] **Steg 2: `build_guide` i de åtta artiklarna.**

```bash
cd $WT/resources/faktabank && for f in security/security-csp.md security/security-lint-regler.md security/security-ratta-sparrfel.md security/security-sparren-i-bygget.md testing/testing-npm-run-check.md stack/stack-samma-upplagg.md; do sed -i '' 's/^build_guide: \[v2\]$/build_guide: [v2, v3]/' $f; done
for f in security/security-sparfiler.md process/process-fraga-manniska.md; do sed -i '' 's/^build_guide: \[v1, v2\]$/build_guide: [v1, v2, v3]/' $f; done
grep -rn '^build_guide:' . | sort
```

Förväntat: åtta rader, alla med `v3`.

- [ ] **Steg 3: Innehållet som v3 ändrar.** Sätt `updated: "2026-10-16"` i varje artikel som ändras i detta steg.
  1. `security-lint-regler.md`, under `## Så gör du`, efter stycket som slutar med `och spridning (`{ ...process.env }`).`, lägg till:

     ```markdown

     I byggguide v3 gäller dessutom, i `src/**/*.{ts,tsx}` utom testfiler: identifieraren `process` får bara stå som `process.env`, och ingen egenskap eller destrukturering får heta `process`. Det fäller `const { env } = process`, `const p = process`, `self['process'].env`, `globalThis.process.env` (även `NODE_ENV`) och `const { process: p } = globalThis`. Testfiler får läsa `process.cwd()`. `ios/` ignoreras av lint (Capacitor kopierar exporten dit). Meddelandena säger `(byggguide v3)` i ett v3-spel.
     ```

     och lägg till källan `- resources/byggguide/v3/files/eslint.config.mjs` under `## Källa`.
  2. `testing-npm-run-check.md`: efter punkt `6. node scripts/cc-gate.mjs: …` i den inre listan, lägg till `   7. i byggguide v3: `node scripts/check-ios.mjs`: kontrollerar spelets iOS-projekt (bundle-id, exportdeklaration, version och byggnummer från bygget, Swift Package Manager, inga certifikat eller nycklar); utan `ios/` finns inget att kontrollera`. I listan över testfiler, lägg till `   - `test/iosSetup.test.ts` (iOS-skripten, byggguide v3)`. Under `## Källa`, lägg till `- resources/byggguide/v3/files/scripts/check-ios.mjs` och `- resources/byggguide/v3/files/test/iosSetup.test.ts` (sökvägar i computercat-api, eftersom filerna inte finns i v2).
  3. `stack-samma-upplagg.md`, under `## Så gör du`, efter punkt 5, lägg till: `6. I byggguide v3 har spelet två egna filer som inte står i manifestet: `capacitor.config.ts` och `ios/`. De skapas en gång med `node scripts/ios-setup.mjs` och ändras inte för hand (se `process-ios-capacitor`).`
  4. `process-slappflode.md`: ersätt meningen `Svarar det att byggguiden inte kan byggas för iOS: flytta spelet till senaste byggguiden först (`get_build_guide`).` med `Svarar det att byggguiden inte kan byggas för iOS: uppgradera spelet till v3 enligt avsnittet "Uppgradera ett v2-spel till v3" i v3:s `CLAUDE.md` och skapa iOS-projektet (`process-ios-capacitor`).` Rör inga rader som börjar med `- `` ` `` (tillstånden; `ReleaseFactbankSyncTest`).
  5. `practice-offline-kon.md`, punkt 3 under `## Så gör du`: lägg till sist `I byggguide v3 tömmer `BackgroundSync` både resultatkön och achievementkön med `flushAll()` (se `practice-achievements`).`

- [ ] **Steg 4: Artikeln om achievements.** Skapa `resources/faktabank/practice/practice-achievements.md`:

```markdown
---
id: practice-achievements
title: Achievements rapporteras via klienten, hela listan vid varje start
topic: practice
tags: [achievements, upplåsning, report, upsert_achievement, rimlighetskontroll, unknown, rejected]
summary: Skapa definitionerna med upsert_achievement, spara upplåsningen först, rapportera med achievements.report och hela listan vid varje start. Visa unknown, rejected och dropped.
updated: "2026-10-16"
build_guide: [v3]
---

## Regel

Rapportera achievements bara med `achievements.report(slugs)` i `src/lib/computercat/`. Spara upplåsningen i spelets egen sparning innan du rapporterar, och rapportera hela listan med spelarens upplåsningar vid varje start. Visa `unknown`, `rejected` och `dropped` för spelaren eller i utvecklingsläget; dölj dem aldrig.

## Varför

Servern låser upp en gång per spelare och slug, så att rapportera hela listan igen är ofarligt. Rimlighetskontrollen läser spelarens molnsparning och jämför med trösklarna i spelets `settings.achievement_thresholds`; en upplåsning som avvisas innan sparningen hunnit upp tas ur kön och kommer med nästa gång hela listan rapporteras. En felstavad slug, eller en definition som inte är skapad, syns i `unknown` i stället för att försvinna tyst.

## Så gör du

1. Skapa definitionerna med `upsert_achievement` (slug högst 50 tecken, `icon` högst 20). Använd inga siffror i en slug som inte är en tröskel i `settings.achievement_thresholds`.
2. När ett villkor uppfylls i spelet: spara först, anropa sedan `achievements.report([slug])` i ett `try`. Den kastar `RangeError` för en ogiltig slug (högst 50 tecken, gemener, siffror, `-`, `_` och `.`, först en bokstav eller siffra, som `upsert_achievement`) och `Error` när kön inte kan sparas lokalt.
3. Vid start: `achievements.report(allaUpplåsta)`.
4. `flushed` ger `{ sent, unlocked, unknown, rejected, dropped, remaining }`:
   - `unknown`: skapa definitionen med `upsert_achievement`, eller rätta stavningen i spelet
   - `rejected`: spelarens molnsparning har för få resultat för tröskeln; den kommer med nästa gång hela listan rapporteras
   - `dropped`: Computercat tog inte emot omgången (till exempel 422); visa skälet
   - `remaining` över 0: köad lokalt (`computercat:<spel>:achievement-queue`), skickas när nätet är tillbaka; nätverksfel, 5xx, 401, 408 och 429 väntar
5. Kön skickas i omgångar om högst 10 och töms också i bakgrunden: `BackgroundSync` anropar `flushAll()` vid start, vid `online` och varje minut. Vill spelet visa bakgrundens svar: `onFlushed(cb)`.
6. `achievements.list()` ger definitionerna (hemliga har `is_secret` sann och `description` `???`), `achievements.mine()` spelarens upplåsta.

## Källa

- src/lib/computercat/achievements.ts
- src/lib/computercat/achievements.test.ts
- src/lib/computercat/index.ts
- src/components/BackgroundSync.tsx
- app/Http/Controllers/Api/V1/AchievementController.php
- app/Support/ConfigRules/AchievementRules.php
- resources/byggguide/v3/files/CLAUDE.md
```

- [ ] **Steg 5: Artikeln om iOS-projektet.** Skapa `resources/faktabank/process/process-ios-capacitor.md`:

```markdown
---
id: process-ios-capacitor
title: iOS-projektet skapas med ios-setup och Capacitor
topic: process
tags: [ios, capacitor, ios-setup, check-ios, xcode, spm, app]
summary: Spelets iOS-projekt skapas en gång med node scripts/ios-setup.mjs och bundle-id:t från get_release_status. Ändra inte ios/ för hand; versionen står bara i package.json.
updated: "2026-10-16"
build_guide: [v3]
---

## Regel

Skapa iOS-projektet en gång med `node scripts/ios-setup.mjs <bundle_id> "<name>"`, där `bundle_id` är `targets.app_store.ios.bundle_id` från `get_release_status` och `name` är spelets namn från `get_game`. Hitta aldrig på ett bundle-id, ändra aldrig `ios/` eller `capacitor.config.ts` för hand, och sätt aldrig version eller byggnummer någon annanstans än i `package.json`.

## Varför

Computercat bygger appen i släpprepot och kontrollerar att `appId` och `PRODUCT_BUNDLE_IDENTIFIER` är spelets bundle-id och att `Info.plist` har exportdeklarationen. Version och byggnummer sätts av bygget (`MARKETING_VERSION` från `package.json`, `CURRENT_PROJECT_VERSION` från Computercat). Ett fel här märks annars först efter flera minuter på en macOS-maskin.

## Så gör du

1. `get_release_status`: saknas `app_store` i `release_targets` eller är `targets.app_store.ios.bundle_id` `null`, be Erik sätta målet med `set_release_targets`.
2. `npm ci --ignore-scripts` och `npm run build`.
3. `node scripts/ios-setup.mjs <bundle_id> "<name>"`. Skriptet skriver `capacitor.config.ts`, kör `cap add ios --packagemanager SPM` och lägger in `ITSAppUsesNonExemptEncryption` = `false`. Det vägrar om `ios/` redan finns.
4. `npm run check` ska sluta med `check-ios: OK`. Faller `check-ios`: läs felraden; den säger vilken fil och vilket värde.
5. Committa `capacitor.config.ts` och `ios/` och pusha. Coolify bygger Demo som vanligt; `ios/` påverkar inte webbygget.
6. Inga certifikat, nycklar, profiler, `ExportOptions.plist` eller byggflöden i repot. Skicka sedan till TestFlight enligt `process-slappflode`.

## Källa

- resources/byggguide/v3/files/scripts/ios-setup.mjs
- resources/byggguide/v3/files/scripts/check-ios.mjs
- resources/byggguide/v3/files/CLAUDE.md
- resources/byggguide/v3/manifest.json
- app/Releases/ReleaseStatus.php
- app/Services/BuildGuide.php
- docs/superpowers/specs/bilagor/2026-10-15-github-actions-ios.md
```

- [ ] **Steg 6: Kör.**

Run: `cd $WT && php artisan test --filter='FactBank|ReleaseFactbankSyncTest|FactToolsTest'`
Expected: PASS. Flyttar en befintlig sökning (`skicka till testflight`, `bundle-id app store`, `offline kö` med flera) till en ny artikel: ändra den nya artikelns `tags`/`title`, inte de gamla artiklarna eller testets förväntan, och kör igen.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add resources/faktabank tests/Feature/FactBankContentTest.php && git commit -m "Faktabanken för byggguide v3: v2-artiklarna gäller v3, achievements och iOS-projektet"
```

---

### Task 10: Verifiering, säkerhetsgranskning och completion-gate

**Files:**
- Inga nya filer (rättningar görs i den task som äger koden).

**Interfaces:**
- Consumes: allt ovan.

- [ ] **Steg 1: Hela sviten, stilen och guiderna.**

```bash
cd $WT && php artisan test
cd $WT && git diff --name-only --diff-filter=AM main...HEAD -- '*.php' | xargs vendor/bin/pint && git status --short
cd $WT && php artisan computercat:byggguide:verify v2 && php artisan computercat:byggguide:verify v3
```

Förväntat: PASS; filer som pint ändrat committas som `Stil: pint` och sviten körs om; `Byggguide v2: OK (54 filer, …)` och `Byggguide v3: OK (59 filer, …)`.

- [ ] **Steg 2: Klass-svep (syskon).**

```bash
cd $WT && git diff main...HEAD --stat -- resources/byggguide/v1 resources/byggguide/v2      # förväntat: ingen utskrift
cd $WT && grep -n "'v[12]' => '" app/Services/BuildGuide.php                               # v1 och v2 oförändrade
cd $WT && grep -rn "queue.flush\b\|queue\.flush()" resources/byggguide/v3/files/src | grep -v '\.test\.'   # bara index.ts (recordScore, flushAll)
cd $WT && grep -rln "process" resources/byggguide/v3/files/src | xargs grep -n "process" | grep -v '\.test\.' # bara process.env.<tillåtet namn>
cd $WT && grep -rn "bundle_id\|bundle-id" resources/byggguide/v3/files --include='*.md' --include='*.mjs' | head -20
cd $WT && grep -rn "v2" resources/byggguide/v3/files/CLAUDE.md resources/byggguide/v3/templates/README.md | grep -v "Uppgradera\|v2-spel\|jämfört med v2\|Byggguide: \*\*v2\*\*\|/api/v1"
```

Förväntat: (1) tom; (2) v1/v2-raderna oförändrade; (3) `BackgroundSync` använder inte längre `queue.flush`; (4) inga `process`-användningar utöver tillåtna; (5) varje ställe som nämner bundle-id hänvisar till `get_release_status`, `targets.app_store.ios.bundle_id` eller mönstret, aldrig ett färdigt id; (6) inga v2-hänvisningar utanför uppgraderingsavsnittet. Avvikelse: rätta i ägartasken, eller AskUserQuestion om det inte går direkt.

- [ ] **Steg 3: Säkerhetsgranskning.** Invokera `security-first-mindset` och kör dess review gate på hela diffen (`cd $WT && git diff main...HEAD`) med en angripares ögon. Frågor som besvaras mot koden: Kan spelrepot stänga av spärren med v3 (nej: `cc-gate.mjs` är v2:s byte för byte, Coolify hämtar den från den låsta versionens REST; R11)? Kan `ios-setup.mjs` fås att köra något annat än `cap` eller skriva utanför repot (argumentlista utan skal, sökvägar från `process.cwd()`, indata validerade före första skrivningen; Task 5 steg 0)? Kan ett bundle-id smugglas in (mönstret i tre lager: skriptet, `check-ios`, släpprepots indata och sign-jobbets granskning; den bindande kontrollen är sign-jobbets, bilagan)? Kan nycklar hamna i repot (`check-ios` fäller på `.p8`, `.p12`, `.cer`, `.pem`, `.key`, `.mobileprovision`, `ExportOptions.plist`; gitleaks/push protection oförändrat)? Kan en symbolisk länk i `ios/` peka ut ur repot i bygget (nej: `check-ios` går med `lstatSync` och fäller på varje länk, och hoppar bara över exakt `ios/App/App/public`)? Kan Capacitors version glida (nej: `check-ios` kräver exakt `CAPACITOR` för alla tre paketen och i `Package.swift`)? Väg B (om den valdes): `tar` och `cap` startas utan skal, mallen kommer ur `node_modules` (samma förtroende som `npm run build`). Läcker achievements-kön något (bara slugs i lagringen; loggrader skriver slugs och statuskod, inga token)? Är lint-omvägarna stängda utan att släppa något v2 fångade (testerna i Task 2 plus v2:s rader oförändrade)? Kan en olåst v3 serveras (nej: `isPublished` före Task 8)?

- [ ] **Steg 4: `completion-gate`.** Invokera `completion-gate` mot specarna i planens huvud och denna plan. Blockerande findings stoppar. Icke-blockerande buntas till **en** AskUserQuestion före överlämning.

- [ ] **Steg 5: Överlämning.** Rapportera till Erik (ett stycke): v3 är låst och senaste version; nya spel får achievements i klienten och kan få ett iOS-projekt med `ios-setup`; flödet "Skicka till TestFlight" står ordagrant i `CLAUDE.md`; v1 och v2 är orörda; spikresultaten står i bilagan. Därefter Task 11 (PR i släpprepot, före deploy). Deploy är Eriks beslut; efter deploy körs Task 12. Merge med `~/.claude/bin/wt-finish $WT --drop-db` (kräver hans OK) eller `--keep-db` först när Erik sagt till.

---

### Task 11: Släpprepot får raden `v3)` före deploy (PR med Eriks OK)

**Files:**
- Släpprepot (via PR): `.github/workflows/release-ios.yml` i `HumblebragAB/computercat-release`
- Scratch: `$SCRATCH/computercat-release/`

**Interfaces:**
- Consumes: den låsta v3 (Task 8): sha256 för `scripts/cc-gate.mjs` ur `resources/byggguide/v3/manifest.json` (R11: lika med v2:s).
- Produces: en PR som Erik mergar före deploy, så att första TestFlight-släppet av ett v3-spel inte faller i steget "Bygg och säkerhetskontroll". Raden är ofarlig före deploy (R11).

- [ ] **Steg 1: Förbered ändringen lokalt (bara läsning mot GitHub).**

```bash
cd $WT && GATE_V3=$(jq -r '.files[] | select(.path=="scripts/cc-gate.mjs") | .sha256' resources/byggguide/v3/manifest.json) && echo "GATE_V3=$GATE_V3" >> $SCRATCH/env.sh && echo "$GATE_V3"
[ "$GATE_V3" = "$(jq -r '.files[] | select(.path=="scripts/cc-gate.mjs") | .sha256' resources/byggguide/v2/manifest.json)" ] && echo "samma som v2 (R11)"
source $SCRATCH/env.sh && rm -rf $SCRATCH/computercat-release && gh repo clone HumblebragAB/computercat-release $SCRATCH/computercat-release && cd $SCRATCH/computercat-release && git checkout -b byggguide-v3-gate
perl -pi -e "s/^(\s*)v2\) GATE_SHA=e4460881ca534a085c500bb0c768e4ea9a1d6c7d7c2f33babf4e7ef8c8969009 ;;\n/\$&\${1}v3) GATE_SHA=$GATE_V3 ;;\n/" .github/workflows/release-ios.yml
git diff
```

Förväntat: `samma som v2 (R11)` och en diff som är exakt en ny rad `v3) GATE_SHA=<GATE_V3> ;;` direkt under v2-raden, med samma indrag. Annat: stanna och fråga.

- [ ] **Steg 2: PR (AskUserQuestion först).** Fråga Erik: "Släpprepot, före deploy av byggguide v3: push av grenen byggguide-v3-gate och en PR mot main i HumblebragAB/computercat-release som lägger till raden v3) för cc-gate.mjs (diffen bifogad; samma sha256 som v2). Inget körs förrän du har mergat, och raden används först när v3 är deployad. OK?" Med OK:

```bash
cd $SCRATCH/computercat-release && git add .github/workflows/release-ios.yml && git commit -m "release-ios.yml: sha256 för cc-gate.mjs i byggguide v3"
git push -u origin byggguide-v3-gate
gh pr create --repo HumblebragAB/computercat-release --base main --head byggguide-v3-gate --title "release-ios.yml: byggguide v3" --body "Lägger till v3 i steget Bygg och säkerhetskontroll. sha256 ur computercat-api resources/byggguide/v3/manifest.json (låst); samma fil som v2 (byggguide v3-planen R11). Mergas före deploy av v3."
```

Erik granskar och mergar. Merga aldrig själv. Deploy av v3 (Task 12) väntar tills PR:en är mergad: `gh pr view byggguide-v3-gate --repo HumblebragAB/computercat-release --json state --jq .state` ger `MERGED`.

---

### Task 12: Efter deploy (bara på Eriks order): prod och acceptans med ett nytt testspel

Detta är en "stor lansering" i CLAUDE.md:s mening (första TestFlight-bygget av ett riktigt spel). Ordningen är tvingande: Task 11 mergad, deploy, verifiera, först därefter berättas det för Mattias.

- [ ] **Steg 1: Prod serverar v3 (AskUserQuestion först, bara läsning).** Fråga Erik: "Efter deploy vill jag läsa, utan att ändra något: v3:s cc-gate.mjs från https://api.computercat.co/api/v1/build-guide/v3/files/scripts/cc-gate.mjs och jämföra sha256 med repot. OK?" Med OK:

```bash
source $SCRATCH/env.sh && curl -fsS https://api.computercat.co/api/v1/build-guide/v3/files/scripts/cc-gate.mjs | shasum -a 256 | cut -c1-64 && echo "$GATE_V3"
```

Förväntat: samma 64 tecken två gånger. 404 eller annan summa: stanna och fråga.

- [ ] **Steg 2: Det nya testspelet (Eriks beslut 2026-10-16).** Fråga Erik (AskUserQuestion) om namn och idé för testspelet och om OK att skapa det med `create_game` (repo, Demo i Coolify, spel i Computercat). Med OK skapas det med `create_game`; följ `get_provisioning_status` tills `succeeded`. Kontrollera att repots `CLAUDE.md` har `Byggguide: **v3**.` (`gh api repos/HumblebragAB/<slug>/contents/CLAUDE.md --jq .content | base64 -d | grep -m1 '^Byggguide:'`).

- [ ] **Steg 3: v3-delen av acceptansen, i en riktig Claude Code-molnsession (Linux, ingen Xcode).** Erik startar en molnsession i testspelets repo (det är där spelens Claude kör). Spelets Claude följer `CLAUDE.md`; du kontrollerar resultatet i repot och i Computercat:
  1. Steg 1 i `CLAUDE.md` (grundkoden), `npm run check` grön, push, Demo grön i `get_provisioning_status`.
  2. Erik kör `set_release_targets` med `app_store`; `get_release_status` visar `targets.app_store.ios.bundle_id`.
  3. I molnsessionen: `node scripts/ios-setup.mjs <bundle_id> "<name>"`, `npm run check` slutar med `check-ios: OK`, commit `iOS-projekt (Capacitor)`, push, Demo fortfarande grön (Coolify med `ios/` i repot). Kontrollera i repot att `ios/App/App.xcodeproj/project.pbxproj` har bundle-id:t och att `ios/App/App/public` inte är committad.
  4. Achievements: en definition skapas med `upsert_achievement`, spelets Claude lägger in `achievements.report` enligt `CLAUDE.md`, och i Demo ger `flushed` `unknown: []` och `rejected: []`. Kontrollera i Filament att spelarens upplåsning finns.
  Faller något: stanna, rör inget mer, rapportera till Erik.

- [ ] **Steg 4: TestFlight.** Följ TestFlight-planens Task 12 steg 5 (punkt 1–6) med testspelet, med Eriks OK före varje steg som skriver mot Apple eller GitHub. Utöver det som står där: verifiera efter första fasbytet att släppets `build_guide` är `v3` och att körningens steg "Bygg och säkerhetskontroll" visar `cc-gate.mjs: OK` för v3 (raden från Task 11). Erik installerar appen med TestFlight och bekräftar att spelet startar och når Computercat (topplistan laddar och achievementen syns). Detta är också det skarpa provet av CORS och CSP från `capacitor://localhost` mot `https://api.computercat.co`.

- [ ] **Steg 5: Först nu informeras Mattias** (Erik bestämmer när). Logga vad som gäller med `logg`-skillen (routas till Pejl): nya spel får v3; v2-spel uppgraderas enligt `CLAUDE.md`; TestFlight enligt "Skicka till TestFlight"; testspelets namn och första TestFlight-bygge.

---

## Täckning mot specen (självgranskning)

| Spec / Eriks beslut | Task |
|---|---|
| Achievements-klient: `list()`, `mine()` med formkontroll | 3 |
| `report(slugs)`: synkron kontroll (RangeError, ≤ 50), kö `computercat:<spel>:achievement-queue` utan dubbletter, omgångar om 10, samma tillfälliga fel som resultatkön, ingen åldersgräns | 3 (R8) |
| `{ flushed }` med `{ sent, unlocked, unknown, rejected, dropped, remaining }`; saknade fält = skickat; `rejected` ur kön | 3 |
| `flushAll()` med `{ scores, achievements }`, `BackgroundSync`, `onFlushed(cb)` | 4 (R9) |
| `is_secret`, `description: "???"` | 3 |
| Testerna i specen (ogiltig slug, offline, dubbletter, omgångar, unknown/rejected, 422, 5xx, flushAll, flera flikar/samtidigt) | 3, 4 |
| CLAUDE.md och README om achievements; faktabanksartikel | 6, 9 |
| Serverdelen, specens fråga 1, integrationsmanualen | redan på `main` (R1) |
| v3 kopieras från v2, egna `FROZEN`/`EXPECTED` | 1, 8 |
| Kontrakt punkt 1: `ios`-blocket | 6 |
| Kontrakt punkt 2: `Byggguide: **v3**.` och `TESTFLIGHT_FLOW` ordagrant | 6 (BuildGuideV3Test, ReleaseFactbankSyncTest) |
| Kontrakt punkt 3: `version` 0.1.0, Capacitor-paketen i manifestets version | 1, 6 |
| Kontrakt punkt 4: `capacitor.config.ts` med spelets bundle-id, namn, `webDir: 'out'`, `ios/` med `cap add ios --packagemanager SPM`, Info.plist; per spel, inte i `files[]`; fungerar där spelets Claude kör (Linux) | 0 (hård grind, väg A/B), 5, 7 steg 1–2 (Linux-skapat `ios/` arkiverat på Mac), 12 steg 3 (riktig molnsession) |
| Kontrakt punkt 5: inga flöden eller hemligheter i spelrepot | 5 (`check-ios`), 6 (CLAUDE.md) |
| Bundle-id från spelet, test att mallen inte hårdkodar | 5 (vitest och PHP), R2 |
| En push utan versionshöjning bygger aldrig; version bara i `package.json` | 6 (CLAUDE.md, flödestexten), 5 (`check-ios`: Info.plist) |
| `npm ci --ignore-scripts`, spärren och CSP oförändrade, v2:s lint består | 1 (R11-test), 2, `BuildGuideTest::test_every_install_command_in_the_guide_ignores_scripts` (8) |
| Underlag: lint-omvägar, jsdom/Node, nätverksformuleringen | 2 (R6), 1 och 0/7 (R5), 6 (R7) |
| Demo-bygget i Coolify fungerar för v3 (`ios/` ignoreras) | 0, 7 (Node 22.14 i Docker), 12 steg 3 (riktigt Demo) |
| v1/v2 orörda; `FROZEN` + `LATEST` i samma commit | 8, 10 |
| Faktabanken | 9 |
| Släpprepot `v3)` via PR med Eriks OK, före deploy | 11 |
| Acceptans med ett nytt testspel (Eriks beslut), iOS-steget i en riktig molnsession; TestFlight-planens Task 12 steg 4–5 | 12 |

**Platshållare:** inga TBD/TODO i kod eller steg. Avsiktliga värden som fylls vid körning: bilagornas `<…>` (Task 0 och 7, med `grep -c '<'`-kontroll), `FROZEN['v3']` (räknas i Task 8), `GATE_V3` (läses ur manifestet i Task 11), `IOS_ADD_WAY` (Task 0 steg 5) och testspelets namn (Task 12).

**Typkonsistens (kontrollerad):** `createAchievements(deps: AchievementDeps)` med `{ storage, game, authed }` (3) anropas så i `index.ts` (4). `report()` ger `{ queued, flushed }` i 3, i testerna (3, 4), i `CLAUDE.md` (6) och artikeln (9). `AchievementFlushResult`-fälten `sent, unlocked, unknown, rejected, dropped, remaining` är desamma i 3, 4, 6 och 9; `DroppedAchievement = { slug, reason }` i 3 och CLAUDE.md:s exempel (`d.slug`, `d.reason`). `FlushAllResult = { scores, achievements }` i 4 och 6. `dropReason` exporteras i 3 och används bara där. `ACHIEVEMENT_SLUG` (3) exporteras från `index.ts` (4). `checkIos(root): { present, errors }`, `capacitorConfigSource(appId, appName)`, `addExportCompliance(plist)`, `BUNDLE_ID`, `CAPACITOR` (5) importeras av `ios-setup.mjs` och `test/iosSetup.test.ts` med samma namn; `setupIos({ root, appId, appName, addIos })` likaså. `targets.app_store.ios.bundle_id` stavas lika i R2, CLAUDE.md, skriptens texter och artikeln. Filantalet 54 → 56 (Task 3) → 59 (Task 5) stämmer med listan i Värden.

**Kända risker som planen inte kan ta bort:** (1) att `cap add ios` fungerar på Linux är obelagt tills Task 0 (hård grind); fäller det gäller väg B (R15), fäller båda stannar planen. (2) CORS/CSP i WKWebView mot prod (`capacitor://localhost` → `https://api.computercat.co`) prövas lokalt i simulatorn mot `http://127.0.0.1` (Task 7) och i skarpt läge först i acceptansen (Task 12); `config/cors.php` har `capacitor://localhost`. (3) Uppgraderingen v2 → v3 för ett spel med många egna ändringar kräver omdöme (R10); Tinker Isle är första provet. (4) Capacitors standardikon och startbild används (Erik: räcker för interna TestFlight-byggen).
