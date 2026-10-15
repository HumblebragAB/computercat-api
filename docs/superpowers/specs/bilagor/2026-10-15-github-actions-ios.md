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
