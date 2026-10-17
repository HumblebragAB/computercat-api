# Bilaga: GitHub Actions för iOS-släpp (spike A, 2026-10-15)

Släppflöde etapp 2, plan `docs/superpowers/plans/2026-10-15-slapp-ios-spike-a.md`. Körning 38695720205 i `HumblebragAB/computercat-release` (flödet `release-ios-spike.yml` på `main`, spelrepot `HumblebragAB/ccspike-ios` med byggguide v2 och Capacitor iOS).

## Uppsättning

- **ComputerCat Studio** (App ID 5312120, installation 171274645): Actions read and write tillagt 2026-10-15 (installationen godkänd). Används av Computercat för dispatch och för att läsa körningar.
- **ComputerCat Release Reader** (App ID 5327052, installation 171633357, alla repon, bara Contents read och Metadata read). Nyckeln finns bara som hemligheten `CC_READER_PRIVATE_KEY` i släpprepot (plus `CC_READER_APP_ID`). Används bara i jobb 0 (`fetch`).
- Spelrepot `ccspike-ios` behålls för spike B (Erik raderar det när etapp 2 är klar).

## Resultat

- **G1 (dispatch):** `POST /repos/HumblebragAB/computercat-release/actions/workflows/release-ios-spike.yml/dispatches` med ComputerCat Studios installationstoken och `ref: main` ger svar.
- **G1b (`return_run_details`):** med `"return_run_details": true` i kroppen svarar GitHub **200** med `{"workflow_run_id": <id>, "run_url": …, "html_url": …}`. Körningens id fås alltså direkt; `run-name` med `release_uuid` blir reserv och för människor.
- **G2 (run-name):** körningen syns i `GET …/actions/runs?event=workflow_dispatch&created>=…` efter 3 s med `name` = `ios ccspike-ios <release_uuid>`, `status` `queued`.
- **G3 (begränsad token):** `actions/create-github-app-token@v2` med `repositories: <spel>` och `permission-contents: read` når spelrepot; samma token får 404 på `computercat-release`.
- **G4 (inga hemligheter i byggjobbet):** jobb 1 (`build`, `macos-15`) har 0 privata nycklar och 0 token-variabler i miljön. Steg har `sudo` utan lösenord (`sudo=ja`); därför får byggjobbet aldrig en nyckel eller token, och utcheckningen sker i ett eget jobb på ubuntu som laddar upp källan som artefakt (`tar`, utan `.git`).
- **Säkerhetskontrollen:** `cc-gate.mjs` hämtades från Computercat, sha256 kontrollerades mot v2-manifestet (`shasum -c`, OK), `check-security: OK (23 filer, 3 HTML-sidor med rätt CSP)`, `cc-audit: OK`.
- **G5 (osignerad arkivering):** `xcodebuild … CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO archive` lyckas på runnern med **Xcode 16.4** (lokalt Xcode 27). Capacitor 8.5.3 med Swift Package Manager (`npx cap add ios --packagemanager SPM`), `-project App.xcodeproj`. Arkivet 9,4 MB, som zip (`ditto -c -k --keepParent`) 3,4 MB.
- **G6 (signeringsjobbet):** jobb 2 får bara `xcarchive.zip`, packar upp (`ditto -x -k`), `App.app/App` är körbar; arbetskatalogen innehåller inget annat.
- **G7 (tid):** fetch 10 s, build 49 s, sign 3 s (köande mellan jobben 4–5 s). Pris och minutkvot för macOS-runners är inte verifierade (API:t kräver `admin:org`); frågas Erik före bygget.

## Påverkar specen och nästa steg

- Flödet blir tre jobb (fetch, build, sign) i stället för två: nyckeln för utcheckning får inte finnas i byggjobbet eftersom steg där har `sudo`.
- Computercat sparar `workflow_run_id` från dispatch-svaret; sökning på `run-name` behövs bara som reserv.
- **Öppen fråga för spike B:** Apple kan kräva ett nyare SDK än Xcode 16.4 för uppladdning till App Store Connect. Spike B måste ladda upp ett bygge och, om det nekas, välja en runner med nyare Xcode (`macos-26` eller `xcode-select`).

# Spike B: Apple (2026-10-15)

Nyckeln **ComputerCat Release** (team-nyckel, roll **App Manager**, Key ID `N2SC66KSLW`) ligger som `ASC_KEY_ID`/`ASC_ISSUER_ID`/`ASC_KEY_P8_BASE64` i släpprepot och som `storage/app/private/asc-release.p8` (600) i prod. Certifikatet är **iOS Distribution** (id `9W6K83MH5B`, Humblebrag AB, gäller till 2027-10-15; "Apple Distribution" var fullt) som `IOS_DIST_CERT_P12_BASE64`/`IOS_DIST_CERT_PASSWORD`. Originalen finns inte i 1Password (Claude kan inte skriva dit).

- **B1 roll:** App Manager räcker för `GET /v1/apps`, `POST /v1/bundleIds` (201, `se.computercat.test` → `WN54V4BM5D`), `POST /v1/profiles` (201), `buildUploads`, `betaGroups` och `betaTesters`.
- **B2 profil:** `POST /v1/profiles` med `profileType: IOS_APP_STORE`, `bundleId` och `certificates` ger `profileContent` (base64) direkt; sign-jobbet hämtar den med `GET /v1/profiles/{id}` och lägger den i både `~/Library/MobileDevice/Provisioning Profiles` och `~/Library/Developer/Xcode/UserData/Provisioning Profiles`.
- **B3 appen:** `POST /v1/apps` ger 403 `The resource 'apps' does not allow 'CREATE'`. Appposten skapas manuellt (Erik eller Claude i webbläsaren): "ComputerCat Test", SKU `computercat-test`, svenska, id `6823067635`. Det blir en punkt i överlämningslistan per spel.
- **B4 signering i separat steg:** `xcodebuild -exportArchive` med `method app-store-connect`, `signingStyle manual`, `signingCertificate iPhone Distribution`, `provisioningProfiles {bundle-id: profil-uuid}` och en tillfällig nyckelring ger `App.ipa` (1,2 MB). Alternativ 1 i specen håller: osignerad arkivering i byggjobbet, signering bara i sign-jobbet.
- **B5 uppladdning:** `xcrun altool --upload-app` med API-nyckel nekas (3004 "Cannot verify client's JWT headers") fast samma nyckel fungerar mot API:t. Uppladdning via **`buildUploads`** (API 4.1+, fält enligt Apples OpenAPI 4.5.1): `POST /v1/buildUploads` (`cfBundleShortVersionString`, `cfBundleVersion`, `platform: IOS`, relation `app`) → `POST /v1/buildUploadFiles` (`assetType: ASSET`, `fileName`, `fileSize`, `uti: com.apple.ipa`) → `uploadOperations` (1 del för 1,2 MB) → `PATCH /v1/buildUploadFiles/{id}` (`uploaded: true`, `sourceFileChecksums.file` MD5) → `GET /v1/buildUploads/{id}` tills `state.state` är `COMPLETE` (cirka 2 min) eller `FAILED` med `errors`.
- **B6 Xcode:** Xcode 16.4 (`macos-15`) nekas: 90725 "All iOS and iPadOS apps must be built with the iOS 26 SDK or later". `macos-26` (Xcode 26) godkänns. Hela körningen: fetch 11 s, build 46 s, sign 2 min 17 s (varav Apples bearbetning cirka 2 min).
- **B7 byggnummer:** `CURRENT_PROJECT_VERSION`/`MARKETING_VERSION` sätts på `xcodebuild archive` (Capacitor-mallen läser dem i Info.plist). Byggnummer 1 som nekats av Apple kunde inte återanvändas? (ej prövat; 2 användes). Computercat ska hålla ett stigande byggnummer per app.
- **B8 export:** `ITSAppUsesNonExemptEncryption = false` i Info.plist ger `usesNonExemptEncryption: false` på bygget; ingen manuell exportfråga.
- **B9 TestFlight:** bygget blir `processingState: VALID`. `POST /v1/betaGroups` (`isInternalGroup: true`, `hasAccessToAllBuilds: true`, relation `app`) och `POST /v1/betaTesters` (e-post för en användare i teamet, relation `betaGroups`) lägger bygget och testaren i gruppen "ComputerCat intern" utan manuellt steg. Interna testare måste vara användare i App Store Connect.
- **Avtal:** App Store Connect visar att kontoinnehavaren måste godkänna det uppdaterade "Paid Applications Schedule" innan nya appar kan skickas in för granskning. TestFlight internt påverkades inte.

## Etapp 2 del 1: vad koden bygger på

- Dispatch med `return_run_details` (G1b) är huvudvägen; `run-name` är reserven (G2).
- `buildUploads` sker i sign-jobbet och Computercat läser bara `GET /v1/builds`.
- Gruppen skapas med `hasAccessToAllBuilds: true` som i B9, men att Computercat ser bygget i gruppen via `filter[betaGroups]` kontrolleras i Task 10 och 12.
- GitHub-endpoints som inte prövats i spiken (git refs, contents med `ref`, runs/{id}, runs/{id}/jobs, runs/{id}/cancel, workflow runs-listan) dokumenteras i Task 2.
- **cc-gate i byggjobbet är tidig återkoppling, inte en bindande kontroll.** Byggjobbet kör spelets kod med sudo, så den koden kan ändra `node`, `shasum` eller `cc-gate.mjs` innan steget körs, eller hoppa över kontrollen och ändå lämna ett lyckat steg. En grön `Bygg och säkerhetskontroll` visar att spelet klarar kontrollen när det inte motarbetar den, inte att bygget är granskat. Bindande är bara det som sker i sign-jobbet (egen VM, ingen spelkod): granskningen av arkivet och av `App.ipa` (bundle-id, version, byggnummer, exportdeklaration, bara `App.app`) innan nycklarna används. Samma sak gäller byggjobbets kontroller av version, bundle-id och exportdeklaration.

### Testkörning av release-ios.yml mot spikappen (2026-10-15)

Körning `38755502361` i HumblebragAB/computercat-release, `workflow_dispatch` på `main` (merge `62f97dd` av PR #8), `run-name` `ios ccspike-ios 7f284fd0-4dfa-4ea8-a954-7abef13e50cc`. Indata: ccspike-ios `fe94d2f`, version 0.1.0, bygge 10, `se.computercat.test`, profil `74R2J9RJM4`, byggguide v2. Utfall `success`, alla steg gröna.

| Jobb | Runner | Tid (UTC) |
|---|---|---|
| fetch | ubuntu-24.04 (image 20261004.327.1) | 14:53:56–14:54:04 (8 s) |
| build | macos-26-arm64 (image 20260907.0351.1), Xcode 26.6 | 14:54:10–14:55:01 (51 s) |
| sign | macos-26-arm64 (samma image) | 14:55:08–14:57:57 (2 min 49 s, varav cirka 2 min 35 s väntan på Apples bearbetning) |

Kontrollstegen:

- **Validera indata** och **Token når bara spelrepot**: gröna. Kontrollen att körningen startats från `main` passerade.
- **Inga hemligheter i byggjobbet**: grön.
- **Kontrollera version**: `package.json` 0.1.0 = indata.
- **Bygg och säkerhetskontroll**: `cc-gate.mjs: OK` (sha256 för v2), `check-security: OK (23 filer, 3 HTML-sidor med rätt CSP)`, `cc-audit: OK`.
- **Capacitor sync**: loggen visar "Creating capacitor.config.json in ios/App/App". Det bekräftar sökvägen `ios/App/App/capacitor.config.json` som **Kontrollera bundle-id och exportdeklaration** läser. Steget passerade: appId, `PRODUCT_BUNDLE_IDENTIFIER` och `ITSAppUsesNonExemptEncryption = false` stämde med `se.computercat.test`.
- **Arkivera osignerat**: `Xcode 26.6`, `** ARCHIVE SUCCEEDED **`.
- **Kontrollera skripten**: tre `OK` mot de fästa sha256. Kontrollen kördes igen efter uppackningen och före uppladdningen, med tre `OK` båda gångerna och ren `git status`.
- **Packa upp och granska arkivet**: grönt. Granskningen omfattade zip-poster utan absoluta sökvägar, `..` och symlänkar, uppackning med bsdtar, bara `App.xcarchive` och `App.app`, `ApplicationPath = Applications/App.app` samt Info.plist med bundle-id, version 0.1.0, bygge 10 och exportdeklaration lika med indata. Detta bekräftar att arkivets Info.plist bär version och byggnummer från indata.
- **Certifikat**: `1 identity imported`. **Profil**: "ComputerCat Test App Store type=IOS_APP_STORE" med en uuid i giltigt format.
- **Signera och exportera**: `** EXPORT SUCCEEDED **`. ipa-kontrollen (bara `Payload/App.app` och samma fyra Info.plist-värden) passerade.
- **Ladda upp till App Store Connect**: buildUpload `905807bf-c893-42c3-ab4a-ddd08c7a7b99` gick från AWAITING_UPLOAD via en uppladdningsoperation, och efter cirka 2,5 minuter i PROCESSING blev resultatet `state=COMPLETE`, utan fel och varningar.
- **Städa**: grön.

Apple efteråt (kontrollerat av controllern): bygge 10 har `processingState: VALID` och `usesNonExemptEncryption: false`, och det finns i gruppen `342f40c7-366f-4b70-9b09-6621ded72ab1` (`hasAccessToAllBuilds: true`, R7).

Loggen är kontrollerad: alla hemligheter (läsappen, P12, P12-lösenordet, ASC-nyckeln och dess id) visas som `***`. Det finns inga omaskerade nycklar och inga långa base64-strängar; de två träffarna på "PRIVATE KEY" är grep-mönstret i byggjobbets kontroll. Stegen kör `/bin/bash -e {0}` utan pipefail, vilket är avsiktligt (se Task 10-rapporten).


## GitHub REST som etapp 2 bygger på (docs.github.com, API 2022-11-28)

Läst 2026-10-15 på docs.github.com. Sidorna anger inga fine-grained-behörigheter för GitHub App (bara `repo`-scope för OAuth/classic PAT); appens faktiska rättigheter för Actions och Contents är inte belagda här och prövas först i Task 12.

| Endpoint | Koden läser | Statuskoder enligt referensen |
|---|---|---|
| `GET /repos/{o}/{r}/contents/{path}?ref=` | `sha`, `encoding`, `content` (ref = commit/gren/tagg) | 200, 302, 304, 403, 404. `encoding` är `base64` (verifierat 2026-10-15); `none` gäller filer 1 till 100 MB med object-mediatyp, och koden failar högljutt på annat än `base64`. |
| `GET /repos/{o}/{r}/git/ref/{ref}` (`tags/<tagg>`) | `object.sha`, `object.type` | 200, 404 (saknas), 409 |
| `POST /repos/{o}/{r}/git/refs` (`ref`, `sha`) | inget i svaret | 201, 409, 422 |
| `POST /repos/{o}/{r}/actions/workflows/{id}/dispatches` | `workflow_run_id`, `html_url` (även `run_url`) | 200 med körningens id (bekräftat i spike A G1b). `inputs` max 25 egenskaper. |
| `GET /repos/{o}/{r}/actions/runs/{id}` | `id`, `status`, `conclusion`, `name`, `html_url`, `head_sha` | 200 |
| `GET /repos/{o}/{r}/actions/runs/{id}/jobs` (`filter=latest\|all`, `per_page` max 100) | `jobs[].name`, `.conclusion`, `.steps[].name`, `.steps[].conclusion` | 200. Jobbets `conclusion`: success, failure, neutral, cancelled, skipped, timed_out, action_required, null. |
| `POST /repos/{o}/{r}/actions/runs/{id}/cancel` | inget | 202, 409 |
| `GET /repos/{o}/{r}/actions/workflows/{id}/runs` (`event`, `created`, `per_page` max 100) | `workflow_runs[]` med samma fält som en körning | 200 |

Verifierat mot riktiga repon 2026-10-15 (gh, användartoken; inte GitHub App-token, så appens behörigheter återstår till Task 12):
- `POST git/refs` för en tagg som redan finns: **422** `{"message":"Reference already exists"}`. Första anropet: 201. Referensens 422-text täcker alltså detta fall. `createTag` kastar fortfarande `ExternalApiException` på 422; Task 6 avgör med `getTag`.
- `POST actions/runs/{id}/cancel` på en avslutad körning: **409** `{"message":"Cannot cancel a workflow run that is completed."}`. `cancelWorkflowRun` accepterar 409.
- `GET actions/workflows/{id}/runs?event=workflow_dispatch&created=>=2026-10-15T08:00:00Z` fungerar (total_count 2). Formen `>=Y-m-dTH:i:sZ` är den koden skickar.
- `GET contents/{path}?ref=<sha>` ger `encoding: "base64"`.
- `steps[].conclusion` förekommer som success, failure och skipped.

Fortfarande obelagt:
- App-behörigheter för Actions och Contents (Task 12).
- Värdena för körningens `status` utöver completed/queued/in_progress; koden jämför bara mot `completed`, `success` och `failure`.
- `steps[].conclusion` timed_out och cancelled (koden behandlar dem som misslyckade steg, men de är inte observerade).
- `return_run_details` finns inte i referensen men är belagt i spike A G1b.

## Byggguide v3: Capacitor och jsdom lokalt (2026-10-16)

Spelens Claude kör i Linux-molnsessioner utan Xcode (Erik 2026-10-16). v2-grundkoden plus `@capacitor/core`/`@capacitor/ios` 8.5.3 (dependencies), `@capacitor/cli` 8.5.3 och jsdom 29.1.1 (devDependencies).

- `npm run check` grön (20 testfiler, 171 tester med jsdom 29.1.1; `check-security: OK`, `cc-audit: OK`); `npm audit --omit=dev --audit-level=high` ren (0). Hela `npm audit` (med dev) visar 8 (5 high, 3 moderate), alla i devDependencies: braces via `eslint-config-next`, och uuid via `xcode` via `@capacitor/cli`. R4:s uppdelning håller alltså, men `@capacitor/cli` drar in en ny dev-sårbarhet.
- `cap add ios --packagemanager SPM` på macOS: 0; utan `out/`: också 0, med `[warn] sync could not run--missing out directory.` (Capacitor stoppar inte, så kontrollen i `ios-setup` behövs). `PRODUCT_BUNDLE_IDENTIFIER` = appId på 2 rader; `Info.plist` med `$(MARKETING_VERSION)`/`$(CURRENT_PROJECT_VERSION)`, `CFBundleDisplayName` = appName, slutar med dict- och plist-sluttaggarna, utan exportdeklaration (läggs in av skriptet); `Package.swift` med `exact: "8.5.3"`; `ios/.gitignore` ignorerar `App/App/public` och `App/App/capacitor.config.json`. Ingen `Package.resolved` skapas av `cap add`.
- Osignerad arkivering med Xcode 27.0: `** ARCHIVE SUCCEEDED **`; arkivets Info.plist: `se.computercat.test`, `0.1.0`, `3`, `false`.
- Node 22.14.0 i Docker med `NODE_ENV=production` och `NPM_CONFIG_PRODUCTION=false`: `npm ci --ignore-scripts` 0, `EBADENGINE` 0, bygge 0 och `cc-gate.mjs` 0 (`check-security: OK`, `cc-audit: OK`). Samma på aarch64 och linux/amd64 (emulerad).
- Linux-grinden: väg A (R15). `cap add ios` på Linux 0 (`[info] Writing Package.swift`, `[success] ios platform added!`), id på 2 rader och `exact: "8.5.3"`; amd64- och aarch64-utfallen är filidentiska, och mot Mac-skapat `ios/` skiljer bara exportdeklarationen och Xcodes SwiftPM-tillstånd. Det Linux-skapade `ios/` arkiverades oförändrat på macOS (bara exportdeklarationen och `cap sync`) med Xcode 27.0: `** ARCHIVE SUCCEEDED **`, `se.computercat.test`, `0.1.0`, `5`, `false`.

## Byggguide v3: generalrepetition före låsningen (2026-10-17)

v3 olåst (manifest-sha256 da28ba0d7cce), utskriven med `computercat:byggguide:verify v3 --keep`.

- `ios-setup.mjs se.computercat.test "Spike v3"`: 0, `ios-setup: OK (se.computercat.test). Kör npm run check och committa capacitor.config.ts och ios/.`; andra körningen vägrar (exitkod 1, `ios-setup: ios/ finns redan. … Ta aldrig bort ett committat ios/; fråga en människa. …`). `npm run check` slutar med `check-ios: OK`.
- Släpprepots kontroller lokalt (appId, `PRODUCT_BUNDLE_IDENTIFIER`, exportdeklaration): `appId=se.computercat.test`, `pbx=se.computercat.test` (ett enda värde), `enc=false`, och `check-ios: OK` före `cap sync`. Osignerad arkivering med Xcode 27.1 (27A9275): `** ARCHIVE SUCCEEDED **`; arkivets Info.plist `se.computercat.test`, `0.1.0`, `4`, `false`. Den kopierade exporten och `capacitor.config.json` hamnar inte i git.
- `ios/` skapades i `node:22` (Linux, ingen Xcode) med väg A och arkiverades oförändrat på Mac.
- Uppgraderingen v2 → v3 enligt `CLAUDE.md` på en v2-utskrift: `npm run check` 0 (`check-security: OK`, `cc-audit: OK`, sist `check-ios: ingen ios/ …`), `npm audit --omit=dev --audit-level=high` 0. Bara `package.json`, `package-lock.json` och `src/app/layout.tsx` behölls; låsfilen efter `npm install --package-lock-only` är v3:s utom `name` (jsdom 29.1.1, Capacitor 8.5.3). v2:s egen låsfil har jsdom 30.1.2, som ger `EBADENGINE` på Node 22.15.
- Simulatorn (iPhone 16e, iOS 18.3.1) mot lokalt API: `/api/v1/auth/anonymous`, `/api/v1/auth/me` och `/api/v1/games/ccspike-ios-v3/leaderboards/daily-clicks` i loggen; en anonym användare och en token skapades, och appen visade API:ts 404-svar för topplistan ("Topplistan finns inte i Computercat ännu"), så svaren passerade CORS och CSP från `capacitor://localhost`. App Transport Security stoppade inte `http://127.0.0.1`. Preflight mot `/auth/anonymous` med `Origin: capacitor://localhost`: 204 med `Access-Control-Allow-Origin: capacitor://localhost`.
- Node 22.14.0 i Docker med `ios/` i repot: `npm ci` 0, `EBADENGINE` 0, bygge 0 och `cc-gate.mjs` 0 (`check-security: OK`, `cc-audit: OK`), `out/index.html` finns.
