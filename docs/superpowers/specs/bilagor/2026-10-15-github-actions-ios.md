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
