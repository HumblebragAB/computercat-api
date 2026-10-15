# Delprojekt 4: släppflöde från Demo till TestFlight, Google Play och produktion

Datum: 2026-10-14. Status: utkast, reviderat efter subagentgranskning. Nyckelbeslutet togs av Erik 2026-10-14: centralt släpprepo, och nycklar per spel för Android.

## Syfte

Mattias (eller Erik) pratar med spelets Claude och bygger ny funktionalitet. Den testas i webbläsaren (Demo), sedan på telefon (TestFlight och Google Plays interna test) och går till sist ut i produktion. Computercat vet i varje steg vad som gäller, stoppar det som inte är tillåtet och säger vad nästa steg är. Flödet är dokumenterat både i API:ts svar och i faktabanken (delprojekt 3).

## Eriks beslut (2026-10-14)

1. **Vad produktion är bestäms per spel**: någon kombination av publik webb, App Store och Google Play.
2. **Uttrycklig befordran**: push till main ger ny Demo som i dag. Ett bygge går vidare till TestFlight eller Plays interna test bara när Claude ber Computercat befordra en exakt commit.
3. **Godkännande till produktion**: Erik godkänner första produktionssläppet per spel och mål; därefter får spelets ägare släppa själv.
4. **Android ingår i modellen** från början.
5. **Upplägg A**: Computercat styr. Byggen körs i GitHub Actions i spelrepot, startade av Computercat.
6. **Vidgade behörigheter godkända**: GitHub-appen får Actions read/write; Coolify-token får `deploy`. Organization secrets write behövs inte med det valda nyckelupplägget.
7. **Signeringsnycklarna** bor i ett centralt släpprepo; Android får nycklar per spel (Del 2).

## Fakta som styr designen

Från dokumentationsgenomgång 2026-10-14 (källor i bilaga, att skrivas i etapp 1:s spike). "Overifierat" betyder att det ska bekräftas i spiken innan koden byggs på det.

- App Store Connect API kan **inte** skapa en app-post (`apps` tillåter bara GET och UPDATE). Bundle-id (`POST /v1/bundleIds`) och profiler (`/v1/profiles`) kan skapas via API. Inskick för granskning finns (`POST /v1/reviewSubmissions`, `appStoreVersions`); exakt vilka metadata som krävs före första inskick är overifierat.
- Google Play Developer API kan **inte** skapa en app. Uppladdning till ett spår (`edits.bundles.upload`, `edits.tracks.update`, `edits.commit`, spåren `qa` och `production`) finns. Att första släppet måste laddas upp manuellt i Play Console är troligt men overifierat. Kravet på sluten test (12 testare i 14 dagar) gäller privata konton skapade efter 13 november 2023; om det gäller Humblebrags konto kontrolleras i etapp 3.
- GitHub: `workflow_dispatch` kräver Actions write och fungerar med installationstoken. Organisationshemligheter med "selected repositories" kan få ett nytt repo via API (Organization secrets write).
- GitHub-hostade runners: macOS kostar ungefär tio gånger Linux per minut. iOS-byggen körs bara vid befordran, aldrig vid varje push.

## Del 1: modellen

### Produktionsmål per spel

Kolumn `games.release_targets` (JSON-lista, standard `[]`), värden `web`, `app_store`, `play_store`. Sätts av en platform admin (Erik) via `set_release_targets` eller Filament. Ett spel utan mål kan bara ha Demo.

### Steg per mål

| Mål | Teststeg | Produktionssteg |
|---|---|---|
| `web` | `demo` (finns: Coolify bygger vid push) | `web_production`: publik Coolify-app låst till en commit |
| `app_store` | `testflight` | `app_store` |
| `play_store` | `play_internal` | `play_production` |

Samma commit vandrar genom stegen. Demo är gemensamt första steg för alla mål.

### Släpp

Ny tabell `releases`:

| Fält | Innehåll |
|---|---|
| `uuid`, `game_id`, `target`, `stage` | vad |
| `commit_sha` | exakt commit (40 tecken) |
| `status` | `requested`, `awaiting_approval`, `building`, `processing`, `succeeded`, `failed`, `cancelled` |
| `requested_by`, `approved_by`, `approved_at` | vem |
| `external` (JSON) | GitHub run-id, Apple build-id och version, Play version code, Coolify deployment-uuid |
| `last_error` | redigerat felmeddelande (SafeMessage/Redactor som i delprojekt 2) |

### Spärrar (kontrolleras i Computercat, med test för varje)

1. Teststeg (`testflight`, `play_internal`) kräver att `commit_sha` har ett lyckat Demo-bygge. Computercat sparar själv ett kvitto per lyckat Demo-bygge i tabellen `demo_builds` (spel, commit, deployment-uuid, tid), skrivet av `get_provisioning_status`, ett pollningsjobb var femte minut och nattkontrollen, eftersom Coolifys bygglista bara visar de senaste byggena. Spärren kontrolleras mot kvittot. Hur Coolifys `commit` ser ut (hel eller kort hash) bekräftas i spiken.
2. Produktionssteg kräver ett `succeeded`-släpp i målets teststeg för samma commit (för `web`: lyckat Demo-bygge).
3. Första produktionssläppet per spel och mål (ingen rad med status `succeeded` för spel, mål och produktionssteg) får status `awaiting_approval` och kräver godkännande av en platform admin i Filament (inte via MCP). Godkänner Erik ett släpp han själv begärt loggas det som `self_approved`. Därefter får spelets ägare (`GameAccess::isOwner`) befordra till produktion; Erik får ett Slack-besked.
4. Högst ett pågående släpp per spel, mål och steg: ett unikt index på `(game_id, target, stage, active_key)` där `active_key` är satt för pågående status och null annars, och befordran sker i en transaktion med `lockForUpdate` på spelets rad. "Första släppet" beräknas i samma transaktion.
7. Spärr 1 och 2 kontrolleras igen när ett släpp godkänns och när bygget startas, inte bara när det skapas. Ett släpp som stått i `awaiting_approval` i 7 dagar blir `cancelled`.
8. Produktionssteg kräver uttrycklig `commit_sha`; standardvalet (senaste commit med lyckat Demo-bygge) gäller bara teststeg.
9. Brytare: `releases_enabled` per spel och globalt (konfig), som Erik kan slå av utan deploy. Avslagen brytare stoppar nya befordringar och nya byggstarter.
5. Mål som inte finns i `release_targets` kan inte befordras.
6. Ett spel med öppna engångssteg för målet kan inte befordras dit (se nedan).

### Engångssteg per spel och butik

Tabell `release_setup_steps` (spel, mål, nyckel, status `open`/`done`, `done_by`, `done_at`, instruktion). Skapas när ett mål läggs till:

- `app_store`: registrera bundle-id (automatiskt via API), skapa appen i App Store Connect med det bundle-id:t (Erik, manuellt), fyll i appens integritetsuppgifter och åldersgräns (Erik, manuellt; vilka som krävs före första inskick bekräftas i etapp 2).
- `play_store`: skapa appen i Play Console med paketnamnet, fyll i innehållsklassning, dataskydd, målgrupp och butikstext, aktivera Play App Signing, och (om spiken bekräftar kravet) ladda upp första AAB manuellt.
- `web`: Erik sätter den publika adressen.

`request_live` (delprojekt 2) ersätts av `promote_release(target: web, stage: web_production)`: verktyget finns kvar som tunn väg in som svarar med hänvisning till `promote_release`, och migrationen sätter `release_targets = ["web"]` för spel som redan bett om Live, så att inget befintligt flöde slutar fungera.

Bundle-id och paketnamn: `co.humblebrag.<slug-utan-bindestreck>` (formatet bekräftas i etapp 2; Apple tillåter bindestreck, Android inte, därför samma form för båda). Värdet sparas på spelet med unikt index; krockar två slugs (`ab-c` och `abc`) vägrar `set_release_targets` och Erik väljer ett annat id.

### `next` i varje svar

`get_release_status` svarar per mål med Demo-commit, senaste släpp per steg, öppna engångssteg och `next`, en mening om vad Claude ska göra (t.ex. "Commit abc123 är testad i Demo. Be om TestFlight med promote_release(target: app_store, stage: testflight)." eller "Väntar på Erik: skapa appen i App Store Connect."). Texterna finns i en enda klass så att faktabankens artiklar och API:t inte glider isär; ett test jämför dem.

## Del 2: byggen och hemligheter

### Beslut: var signeringsnycklarna bor (Erik 2026-10-14: alternativ 1 + 3 för Android)

Ett iOS- eller Android-bygge kör spelrepots egen kod (npm-skript, Capacitor-konfiguration, Gradle, fastlane, Podfile). Ligger signeringsnycklarna i samma jobb kan den koden läsa och skicka iväg dem, av misstag, via ett skadligt paket eller via en kapad session. App Store Connect-nyckeln gäller hela teamet. Att kontrollera flödesfilens sha256 skyddar inte mot det, och `workflow_dispatch` kör flödesfilen från den gren eller tagg som anges, inte från den kontrollerade commiten.

| Alternativ | Hur | För | Mot |
|---|---|---|---|
| **1. Centralt släpprepo** (rekommenderas) | Repot `HumblebragAB/computercat-release` är det enda med nycklar. Computercat startar dess flöde med spel, commit och släpp-id. Jobb 1 checkar ut spelets commit och bygger osignerat utan nycklar och laddar upp artefakten. Jobb 2 hämtar bara artefakten och signerar och laddar upp med kod som ligger i släpprepot. | Spelrepon får inga nycklar; Computercat behöver inte Organization secrets write; flödet kan inte bytas ut från spelrepot. | iOS måste kunna byggas osignerat och signeras i ett separat steg (`xcodebuild archive` utan signering, sedan `-exportArchive` med signering); måste bevisas i spike. |
| **2. Nycklar i spelrepon, manuell spärr** | Som Tocco, men nycklarna ligger i en GitHub-miljö med obligatorisk granskare; Erik klickar godkänn per bygge. | Enkelt, beprövat. | Erik blir mellanhand vid varje TestFlight-bygge; godkännandet skyddar inte mot ett skadligt paket i samma bygge. |
| **3. Nycklar per spel** | Android: egen uppladdningsnyckel och ett servicekonto med rätt bara till spelets app. | Begränsar skadan för Android. | Apples API-nyckel kan inte begränsas till en app; löser inte iOS ensamt. |

Erik valde 1, kombinerat med 3 för Android. Resten av Del 2 beskriver det. Om spiken i etapp 2 visar att iOS inte går att signera i ett separat steg stannar arbetet och Erik väljer igen.

### Byggflöden (alternativ 1)

- **Släpprepot** innehåller `release-ios.yml` och `release-android.yml`, ändras bara via granskad PR och har nycklarna som repo- eller miljöhemligheter. Computercat startar flödet med `workflow_dispatch` på släpprepots egen huvudgren, med indata spel, `commit_sha` och `release_uuid`, och hittar körningen genom att `release_uuid` står i körningens namn (`run-name`).
- **Jobb 1 (utan hemligheter):** checkar ut spelrepot på `commit_sha` med en läsbehörighet som bara gäller det repot, kör `npm ci --ignore-scripts`, Computercats säkerhetskontroll (skriptet hämtas och sha256-kontrolleras som i delprojekt 3; hashen står i släpprepots flöde), bygger webbexporten, kör `npx cap sync` och bygger osignerat (iOS: `xcarchive`, Android: osignerad AAB). Artefakten laddas upp.
- **Jobb 2 (med hemligheter):** checkar inte ut spelrepot. Hämtar artefakten, signerar och laddar upp med verktyg som ligger i släpprepot (fastlane/Gradle-konfiguration där), och skriver resultatet (byggnummer, versionskod) i jobbets utdata.
- **Spelrepot** behöver bara Capacitor-projekten (`ios/`, `android/`) från byggguiden. Inga flödesfiler för släpp och inga hemligheter.

### Hemligheter (alternativ 1)

| Hemlighet (i släpprepot) | Innehåll |
|---|---|
| `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8_BASE64` | App Store Connect API-nyckel (lägsta roll som spiken visar räcker) |
| `IOS_DIST_CERT_P12_BASE64`, `IOS_DIST_CERT_PASSWORD` | distributionscertifikat |
| Android per spel | uppladdningsnyckel och servicekonto med rätt bara till spelets app (hemligheter med spelets slug i namnet) |

Originalen ligger i 1Password-valvet (Erik lägger in dem; Claude läser aldrig värdena). Computercat behöver App Store Connect-nyckeln och Googles servicekonto för att läsa status och skicka in för granskning: de ligger i prod som filer i `storage/app/private/` (läge 600), som GitHub-nyckeln i delprojekt 2. Befintlig per-spel-konfiguration för App Store Connect (`games.settings.app_store_connect`, används för köp i appen) påverkas inte. Actions-loggar kopieras aldrig in i Computercat.

### Statusläsning

Ett köat jobb (`AdvanceRelease`, samma mönster som `AdvanceProvisioningRun`) pollar: GitHub-körningen var 30:e sekund tills den är klar, sedan Apples bearbetning eller Plays spår var 2:a minut. Tidsgränser: GitHub-körning 60 min, Apples bearbetning 90 min, Coolify-bygge 30 min; därefter `failed` med orsak och Slack-larm. En körning som avbryts utanför Computercat (manuellt, slut på Actions-minuter) blir `failed` med körningens slutstatus som orsak.

### Publik webb

En andra Coolify-app per spel, `spel-<slug>-prod`, i ett eget Coolify-projekt "Spel produktion", utan basic auth, med `is_auto_deploy_enabled: false`, samma byggkommando och säkerhetsspärr som Demo, och domänen Erik satt. Vid befordran sätter Computercat appens commit till den befordrade och startar ett bygge (kräver token-förmågan `deploy`). Coolify-spärren från delprojekt 2 utökas med en egen kontroll för produktionsprojektet (`assertInProductionProject`) och en egen kolumn för produktionsappens uuid, med egna tester: muterande anrop bara mot appar som Computercat själv skapat i respektive projekt. Att Coolify kan låsa en app till en commit (`git_commit_sha` via PATCH), att `POST /deploy` bygger den commiten, och att `is_auto_deploy_enabled: false` verkligen stoppar webhooken verifieras i spiken. Token måste skapas om med `write` och `deploy` (Coolifys UI nollställer valet när `deploy` kryssas) och vara team-begränsad, utan `root`. Ett produktionsbygge delar kö med Demo-byggena (`concurrent_builds = 1`); fastnar det i kön gäller tidsgränsen ovan.

## Del 2b: förbered App Store och lämna över (Eriks önskemål 2026-10-14)

Erik vill att Mattias ska kunna gå från idé till test i webbläsaren och till TestFlight utan Erik, och att Computercat sedan förbereder så mycket som möjligt av App Store-publiceringen själv och lämnar över resten till Erik som en färdig checklista.

**Computercat förbereder automatiskt** (varje punkt bekräftas i etapp 2:s spike innan den byggs; det som inte går via API flyttas till checklistan):

- Bundle-id (`POST /v1/bundleIds`) och provisioneringsprofil.
- TestFlight: bygget till intern testgrupp, så att Mattias och Erik kan installera det direkt; testgruppen och dess medlemmar hålls av Computercat.
- App- och versionstexter på svenska (och engelska om spelet har det): namn, undertitel, beskrivning, nyckelord, support- och integritets-URL, utkast skrivet av spelets Claude från README:s idé och granskat i spelets PR.
- Skärmdumpar i de storlekar Apple kräver, tagna automatiskt från Demo i en headless webbläsare i telefonformat och laddade upp via API (`appScreenshotSets`, `appScreenshots`).
- Åldersgränsdeklarationen (frågorna besvaras av spelets Claude utifrån spelets innehåll och syns för Erik i checklistan innan inskick).
- Versionen kopplad till rätt bygge, redo för inskick.

**Överlämning till Erik:** `get_release_status` och Filament visar en checklista med exakta värden att klistra in för det som bara Erik kan göra: skapa app-posten i App Store Connect (namn, primärspråk, bundle-id, SKU), integritetsuppgifterna ("App Privacy") om API:t inte täcker dem, pris och tillgänglighet om de inte kan sättas via API, och avtal i App Store Connect. När Erik bockat av skickar Computercat in för granskning; första produktionssläppet kräver ändå Eriks godkännande (spärr 3).

**Spelets roll:** byggguidens nya version får en mall för butikstexterna (`store/app-store.sv.md`) som spelets Claude fyller i, och faktabanken får artiklar om vad som krävs för App Store (texter, skärmdumpar, åldersgräns), så att spelets Claude vet vad som ska göras utan att fråga.

## Del 3: verktyg, godkännande och dokumentation

### MCP-verktyg

| Verktyg | Vem | Gör |
|---|---|---|
| `get_release_status(game)` | medlem | mål, steg, släpp, engångssteg, `next` |
| `promote_release(game, target, stage, commit_sha?)` | ägare (produktion enligt spärr 3) | skapar släpp; utan `commit_sha` väljs senaste commit med lyckat Demo-bygge |
| `set_release_targets(game, targets)` | platform admin | sätter mål och skapar engångssteg |

Alla ärver `GameTool`, är revisionsloggade och klarar `ToolInvariantsTest` (inga förbjudna ord i namnen).

### Godkännande

Ett släpp i `awaiting_approval` ger ett Slack-meddelande med länk till Filament. Erik godkänner eller avslår där. Inget godkännande via Slack-knappar (kräver signerade Slack-anrop till Computercat; utanför scope).

### Filament

Sida per spel med mål, släpp och engångssteg. Erik bockar av manuella engångssteg och godkänner första produktionssläpp.

### Bevakning

Nattkontrollen (`computercat:check-provisioning`) utökas: släpp som stått i `building`/`processing` för länge, öppna engångssteg äldre än 7 dagar (påminnelse), och att produktionswebben serverar den släppta commiten. Fel larmar i Slack som i dag.

### Dokumentation

Faktabanken får artiklar under ämnet `process`: ett per steg och ett om engångsstegen. Texterna för `next` och artiklarna jämförs i ett test (del 1). `CLAUDE.md` i den nya byggguideversionen beskriver befordran.

## Etapper

Ordningen följer Eriks prioritet: Mattias ska nå TestFlight och Erik få en färdig överlämning för App Store innan publik webb och Android.

| Etapp | Innehåll | Spike före bygge |
|---|---|---|
| **1a. Modell** | `release_targets`, `releases`, `demo_builds`, `release_setup_steps`, spärrar, lås, brytare, tre MCP-verktyg, `request_live` som hänvisning, Filament-godkännande, bevakning, faktabanksartiklar | Coolify: hur `commit` står i bygglistan (S22 visade 40 tecken för ett pushbygge) och hur länge byggen syns |
| **2. iOS: TestFlight och App Store-förberedelse** | släpprepot (`HumblebragAB/computercat-release`), Capacitor iOS i byggguiden, bundle-id och profil via API, TestFlight till intern grupp, butikstexter, automatiska skärmdumpar, åldersgräns, överlämningschecklista, inskick | GitHub: dispatch, `run-name`-korrelation, utcheckning av annat repo med begränsad token. Apple: nyckelns roll, osignerad arkivering och signering i separat steg, profiler via API, certifikat (CSR), byggnummer, exportkrav (`ITSAppUsesNonExemptEncryption`), interna testare via API, vilka metadata, skärmdumpsstorlekar, åldersgräns och integritetsuppgifter som kan sättas via API, kontoägare (Humblebrag AB). Kräver en testapp som Erik skapar i App Store Connect. |
| **1b. Webbproduktion** | produktionsprojekt och -app i Coolify, låst commit, bygge med `deploy`-token, utökad spärr | Coolify: `git_commit_sha` via PATCH, `POST /deploy`, att auto-deploy av verkligen stoppar webhooken, kö och tidsgräns |
| **3. Android** | Capacitor Android, uppladdning till `qa`, befordran till produktion | Play: kontotyp och testkrav, första uppladdning, servicekonto per app (bjuds in i Play Console, ett engångssteg per spel), versionskod, kvoter för `edits`, krav för spel som riktar sig till barn |

Etapp 2:s spike kan köras parallellt med bygget av 1a, eftersom den inte rör Computercats kod.

Varje etapp får egen plan, egen spike mot riktiga tjänster och eget godkännande innan den går live.

## Säkerhet

- Med alternativ 1 når spelrepots kod aldrig signeringsnycklarna: den körs bara i jobb 1, som saknar hemligheter. Med alternativ 2 skyddar bara Eriks godkännande per bygge, och inte mot ett skadligt paket i samma bygge. Det är skälet till rekommendationen.
- Säkerhetskontrollen hämtas från Computercat och kontrolleras mot en sha256 som inte står i spelrepot.
- Revisionsloggen för varje befordran anger `commit_sha` och vilken version av släppflödet som kördes.
- Produktionsspärren ligger i Computercat, inte i spelrepot. En kapad staff-session kan bara befordra egna spel och bara till produktion efter Eriks första godkännande.
- Coolify-token med `deploy` begränsas i kod till appar som Computercat skapat i de två projekten.

## Tester

Varje spärr får ett test; verktygen testas för behörighet, validering och revisionslogg; klienterna mot fejkar byggda på spikresultaten (aldrig gissade svar).

## Utanför scope

- Android-specifika butiksfunktioner utöver spåren `qa` och `production` (stegvis utrullning, andra spår).
- Godkännande via Slack-knappar.
- Att flytta befintliga appar (Tocco) till det här flödet.
- Automatisk generering av skärmdumpar och butikstexter.
