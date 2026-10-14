# Delprojekt 4: släppflöde från Demo till TestFlight, Google Play och produktion

Datum: 2026-10-14. Status: utkast för Eriks granskning.

## Syfte

Mattias (eller Erik) pratar med spelets Claude och bygger ny funktionalitet. Den testas i webbläsaren (Demo), sedan på telefon (TestFlight och Google Plays interna test) och går till sist ut i produktion. Computercat vet i varje steg vad som gäller, stoppar det som inte är tillåtet och säger vad nästa steg är. Flödet är dokumenterat både i API:ts svar och i faktabanken (delprojekt 3).

## Eriks beslut (2026-10-14)

1. **Vad produktion är bestäms per spel**: någon kombination av publik webb, App Store och Google Play.
2. **Uttrycklig befordran**: push till main ger ny Demo som i dag. Ett bygge går vidare till TestFlight eller Plays interna test bara när Claude ber Computercat befordra en exakt commit.
3. **Godkännande till produktion**: Erik godkänner första produktionssläppet per spel och mål; därefter får spelets ägare släppa själv.
4. **Android ingår i modellen** från början.
5. **Upplägg A**: Computercat styr. Byggen körs i GitHub Actions i spelrepot, startade av Computercat.
6. **Vidgade behörigheter godkända**: GitHub-appen får Actions read/write och Organization secrets write; Coolify-token får `deploy`.

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

1. Teststeg (`testflight`, `play_internal`) kräver att `commit_sha` har ett lyckat Demo-bygge (Coolify-bygget med samma commit har status `finished`).
2. Produktionssteg kräver ett `succeeded`-släpp i målets teststeg för samma commit (för `web`: lyckat Demo-bygge).
3. Första produktionssläppet per spel och mål får status `awaiting_approval` och kräver Eriks godkännande. Därefter får spelets ägare (`GameAccess::isOwner`) befordra till produktion; Erik får ett Slack-besked.
4. Högst ett pågående släpp per spel, mål och steg.
5. Mål som inte finns i `release_targets` kan inte befordras.
6. Ett spel med öppna engångssteg för målet kan inte befordras dit (se nedan).

### Engångssteg per spel och butik

Tabell `release_setup_steps` (spel, mål, nyckel, status `open`/`done`, `done_by`, `done_at`, instruktion). Skapas när ett mål läggs till:

- `app_store`: registrera bundle-id (automatiskt via API), skapa appen i App Store Connect med det bundle-id:t (Erik, manuellt), fyll i appens integritetsuppgifter och åldersgräns (Erik, manuellt; vilka som krävs före första inskick bekräftas i etapp 2).
- `play_store`: skapa appen i Play Console med paketnamnet, fyll i innehållsklassning, dataskydd, målgrupp och butikstext, aktivera Play App Signing, och (om spiken bekräftar kravet) ladda upp första AAB manuellt.
- `web`: välj den publika adressen (`request_live` finns redan och larmar Erik; Erik sätter domänen).

Bundle-id och paketnamn: `co.humblebrag.<slug-utan-bindestreck>` (formatet bekräftas i etapp 2; Apple tillåter bindestreck, Android inte, därför samma form för båda).

### `next` i varje svar

`get_release_status` svarar per mål med Demo-commit, senaste släpp per steg, öppna engångssteg och `next`, en mening om vad Claude ska göra (t.ex. "Commit abc123 är testad i Demo. Be om TestFlight med promote_release(target: app_store, stage: testflight)." eller "Väntar på Erik: skapa appen i App Store Connect."). Texterna finns i en enda klass så att faktabankens artiklar och API:t inte glider isär; ett test jämför dem.

## Del 2: byggen och hemligheter

### Byggflöden i spelrepot

En ny byggguideversion lägger till `.github/workflows/release-ios.yml`, `.github/workflows/release-android.yml`, `ios/`- och `android/`-projekten (Capacitor) och fastlane-konfiguration. Flödena:

- startas bara med `workflow_dispatch` och indata `commit_sha` och `release_uuid`, checkar ut exakt den commiten, och kör `npm ci --ignore-scripts` och samma säkerhetskontroll som Coolify (delprojekt 3) innan något byggs;
- iOS: `macos`-runner, fastlane `beta` (som Tocco), uppladdning till TestFlight; byggnummer sätts från släppet;
- Android: Linux-runner, `bundleRelease`, uppladdning till spåret `qa` via API;
- rapporterar inte själva till Computercat; Computercat läser körningens status via GitHub API och butikernas status via Apples och Googles API.

Före varje start kontrollerar Computercat att flödesfilen i repot på den commiten har byggguidens sha256 (via Contents API). Avviker den vägrar Computercat starta och släppet blir `failed` med orsak. Spelrepot kan alltså inte byta ut släppflödet.

Workflow-filer skrivs av spelets Claude (CLAUDE.md steg 1), inte av Computercat. Computercat behöver därför inte GitHub-behörigheten Workflows.

### Hemligheter

Organisationshemligheter i GitHub med synlighet "selected repositories":

| Hemlighet | Innehåll |
|---|---|
| `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8_BASE64` | App Store Connect API-nyckel (teamnivå, roll App Manager eller den roll spiken visar krävs) |
| `IOS_DIST_CERT_P12_BASE64`, `IOS_DIST_CERT_PASSWORD` | distributionscertifikat |
| `PLAY_SERVICE_ACCOUNT_JSON` | Googles servicekonto |
| `ANDROID_UPLOAD_KEYSTORE_BASE64`, `ANDROID_UPLOAD_KEYSTORE_PASSWORD`, `ANDROID_UPLOAD_KEY_ALIAS` | en gemensam uppladdningsnyckel för alla spel (Play App Signing äger den riktiga signeringsnyckeln) |

Originalen ligger i 1Password-valvet (Erik lägger in dem; Claude läser aldrig värdena). Erik skapar organisationshemligheterna en gång; Computercat lägger bara till nya spelrepon i listan över repon som får läsa dem. Computercat behöver också App Store Connect-nyckeln och Googles servicekonto för att läsa status och skicka in för granskning: de ligger i prod som filer i `storage/app/private/` (läge 600), som GitHub-nyckeln i delprojekt 2.

Befintlig per-spel-konfiguration för App Store Connect (`games.settings.app_store_connect`, används för köp i appen) påverkas inte.

### Publik webb

En andra Coolify-app per spel, `spel-<slug>-prod`, i ett eget Coolify-projekt "Spel produktion", utan basic auth, med `is_auto_deploy_enabled: false`, samma byggkommando och säkerhetsspärr som Demo, och domänen Erik satt. Vid befordran sätter Computercat appens commit till den befordrade och startar ett bygge (kräver token-förmågan `deploy`). Coolify-spärren från delprojekt 2 utökas: muterande anrop bara mot appar i Demo- eller produktionsprojektet som Computercat själv skapat.

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

| Etapp | Innehåll | Spike före bygge |
|---|---|---|
| **1. Modell och webbproduktion** | `release_targets`, `releases`, `release_setup_steps`, spärrar, tre MCP-verktyg, Filament, godkännande, publik Coolify-app, bevakning, faktabanksartiklar | Coolify: låsa en app till en commit och starta bygge med `deploy`-token; nytt projekt; att Demo-bygget för en commit kan slås upp |
| **2. iOS** | byggguide med Capacitor iOS och `release-ios.yml`, bundle-id via API, organisationshemligheter, TestFlight-status, inskick till App Store | App Store Connect: nyckelns roll, bundle-id, profil, uppladdning, status för bearbetning, krav före första inskick (en engångsapp i App Store Connect som Erik skapar) |
| **3. Android** | Capacitor Android och `release-android.yml`, Play-uppladdning, befordran till produktion | Play: kontotyp och testkrav, första uppladdning, servicekontots behörigheter |

Varje etapp får egen plan, egen spike mot riktiga tjänster och eget godkännande innan den går live.

## Säkerhet

- Spelrepot kan inte byta ut släppflödet (sha256-kontroll före start) eller hoppa över säkerhetskontrollen (den hämtas från Computercat).
- Signeringsnycklar finns bara som GitHub-organisationshemligheter för spelrepon och i 1Password. Computercat läser dem aldrig; det lägger bara till repon i listan.
- Produktionsspärren ligger i Computercat, inte i spelrepot. En kapad staff-session kan bara befordra egna spel och bara till produktion efter Eriks första godkännande.
- Coolify-token med `deploy` begränsas i kod till appar som Computercat skapat i de två projekten.

## Tester

Varje spärr får ett test; verktygen testas för behörighet, validering och revisionslogg; klienterna mot fejkar byggda på spikresultaten (aldrig gissade svar).

## Utanför scope

- Android-specifika butiksfunktioner utöver spåren `qa` och `production` (stegvis utrullning, andra spår).
- Godkännande via Slack-knappar.
- Att flytta befintliga appar (Tocco) till det här flödet.
- Automatisk generering av skärmdumpar och butikstexter.
