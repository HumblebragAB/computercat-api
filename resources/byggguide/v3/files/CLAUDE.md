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

Lint förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med en sträng och `process.env.NEXT_PUBLIC_*` utöver `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`. I all spelkod (`src/`, men också `lib/`, `app/` och annat i repots rot; undantaget är `scripts/`, `test/`, konfigfiler i roten som `*.config.*` och testfiler) får `process` bara förekomma som `process.env.NODE_ENV`, `process.env.NEXT_PUBLIC_COMPUTERCAT_GAME` eller `process.env.NEXT_PUBLIC_COMPUTERCAT_URL`: inga alias (`const p = process`), ingen destrukturering (`const { env } = process`) och inga omvägar via `globalThis`, `self` eller `window`. Inget i spelkoden får heta `process` (variabel, egenskap eller metod): döp om det, till exempel till `run` eller `step`. Stäng aldrig av en regel. Behövs något av det: fråga faktabanken och sedan en människa.

## Computercat i koden

- `getComputercat()` ger klienten i webbläsaren. Den kastar aldrig: saknas `NEXT_PUBLIC_COMPUTERCAT_GAME` (eller körs den utanför webbläsaren) returnerar den `{ ok: false, error }`, och det felet ska visas för spelaren.
- Spelaren (token) förnyas automatiskt vid start om den är äldre än 24 h (tokens gäller 90 dagar); du behöver inte göra något. `recordScore` kastar om resultatet inte kan sparas lokalt (full lagring): visa det för spelaren.
- Resultat: `recordScore(typ, score, metadata?)` köar lokalt och skickar i bakgrunden. Visa `flushed`-resultatet för spelaren; dölj aldrig fel och visa aldrig påhittad topplistedata.
- Topplistor: `leaderboards.get(typ)` (aktuell period enligt servern, UTC), `leaderboards.me(typ)`.
- Achievements: `achievements.report(slugs)`, `achievements.list()`, `achievements.mine()` (se "Achievements").
- Matcher: `matches.create/join/act/watch/active/invite` för turbaserade tvåspelarmatcher (se "Matcher").
- Sparningar: `saves.sync(nyckel, lokalt, merge)` hämtar, slår ihop och skickar med version.
- Anti-cheat: `createProof(...)` i `metadata.proof` om spelet kräver bevis.
- Bakgrunden: `BackgroundSync` (monterad i `src/app/layout.tsx`) anropar `flushAll()` vid start, vid "online" och varje minut; den tömmer resultatkön och achievementkön. Vill spelet visa vad bakgrunden skickade: `onFlushed(cb)` ger `{ scores, achievements }` efter varje `flushAll()` (inte efter `report()`s egen tömning) och returnerar en funktion som avslutar prenumerationen.

## Achievements

1. Skapa definitionerna med `upsert_achievement` (slug högst 50 tecken, `name`, `description`, `icon` högst 20 tecken). Siffror i en slug betyder ingenting för servern: `forsta-12x12` och `losa-100` är bara namn.
2. Villkoren utvärderas i spelet. Spara framstegen i molnsparningen först med `saves.sync('main', …)` och rapportera sedan: `achievements.report(['forsta-rundan'])`. Computercat kan ha en tröskel för en slug (minsta antal resultat). Den sätts av en människa i Computercat, syns inte i `get_game` och sätts inte med något verktyg. Finns en tröskel räknar servern elementen i listan `progress.results` i sparningen `main`, och färre än tröskeln ger `rejected`. Saknas sparningen, eller är listan tom eller inte en lista, görs ingen kontroll. Behöver en achievement en tröskel: fråga en människa, och spara ett element per avklarat resultat i `progress.results` (sparningen får vara högst 512 KB).
3. Rapportera hela listan med spelarens upplåsningar vid varje start. Det är ofarligt (servern låser upp en gång) och tar med det som avvisades tidigare.
4. `report` kastar `RangeError` direkt för en ogiltig slug (samma regler som `upsert_achievement`: högst 50 tecken, gemener, siffror, `-`, `_` och `.`, först en bokstav eller siffra) och `Error` när kön inte kan sparas lokalt. Fånga det och visa det.
5. `report` ger `{ queued, flushed }`. `flushed` blir `{ sent, unlocked, unknown, rejected, dropped, remaining }`:
   - `unknown`: ingen aktiv definition med den sluggen (felstavad eller inte skapad). Visa det och skapa definitionen med `upsert_achievement`.
   - `rejected`: avvisad av rimlighetskontrollen (spelarens molnsparning har för få resultat för tröskeln). Den tas ur kön och kommer med nästa gång spelet rapporterar hela listan.
   - `dropped`: Computercat tog inte emot omgången (till exempel 422); visa skälet.
   - `unlocked`: nyupplåsta achievements (`slug`, `name`, `description`, `icon`). Visa en notis för varje.
   - `remaining` över 0: köad lokalt; skickas när nätet är tillbaka (offline, 5xx, 401, 408 och 429 väntar).
6. `flushed` gäller bara det här anropets egna slugs: två `report` i samma tick får var sitt resultat. `onFlushed` utlöses bara av `flushAll()` (också bakgrundens) och får inte `report()`s egen tömning, så visa notiserna för upplåsta achievements från `report().flushed`.
7. `achievements.list()` ger definitionerna (`is_secret` sann och `description` `???` för hemliga), `achievements.mine()` spelarens upplåsta.

```ts
import { describeError, getComputercat } from '@/lib/computercat';

const cc = getComputercat();
if (cc.ok) {
  try {
    const { flushed } = cc.client.achievements.report(unlockedSlugs);
    flushed.then(
      (r) => {
        for (const a of r.unlocked) show(`Upplåst: ${a.name}`);
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

## Matcher

För spel där två spelare möts i en turbaserad match över nätet (servern äger reglerna och det dolda läget). `get_integration_guide` visar regelverken och deras drag (actions, payload, state och händelser); ett annat regelverk ger `ruleset_not_allowed`. Saknar manualen avsnittet "Turn-based Matches" för spelet: fråga en människa. Anropa aldrig matchvägarna direkt med `fetch`: gå alltid via `client.matches`, som sköter ETag, `version`, paus, backoff och fel.

1. Skapa: `matches.create(ruleset, options?, namn?)` ger `{ match, joinToken }`. Visa `inviteUrl(joinToken, origin?)` som QR-kod och länk. I iOS-appen måste `origin` vara spelets publika webbadress (`https://…`); `capacitor://localhost` kastar.
2. Gå med: läs `readJoinToken()` vid start (bara på webben; den tar bort token ur adressen) och anropa `matches.join(token, namn?)` om den gav en token.
3. Rita alltid från `watch(id, { onChange, onError })`. `onChange` får varje ny version, oavsett vilket anrop som hämtade den; den kan upprepa en version som spelet redan fick från `act`, vilket är ofarligt. Spara handtaget och anropa `stop()` när spelaren lämnar matchen eller vyn försvinner.
4. Drag: `matches.act(snapshot, typ, payload?)` på den senaste snapshot spelaren såg; spara svaret som det nya läget. `MatchError` med `code` `stale` betyder att matchen hann ändras: rita `e.snapshot` (finns den inte kommer nästa `onChange`) och erbjud draget igen bara om det fortfarande är tillåtet i det nya läget (vid en dubbeltryckning har det första draget redan gått igenom). Drag som båda spelarna gör i samma fas (till exempel att båda låser ett tal eller trycker "redo") krockar ofta: den som kommer andra får `stale` fast draget aldrig gick igenom. Skicka då samma drag igen en gång med `e.snapshot`, om det fortfarande är tillåtet för spelaren där; annars försvinner draget och spelaren väntar för evigt.
5. Visa aldrig en felkod som den är för spelaren. Styr på `e.code` (`not_your_turn`, `out_of_range`, `invite_expired`, `own_match`, `too_many_active` …) och visa spelets egen svenska text; `describeError` duger för nät- och serverfel men inte för `MatchError`.
6. `onError` ger `{ kind, error, terminal }`. Visa ett synligt läge vid varje `onError` (en banner om `terminal` är falskt, tillbaka till menyn om det är sant). Styr på `terminal`, inte på `kind`. `gone` (matchen finns inte, eller `abandoned`) är alltid terminal och ska ge ett meddelande. Det finns ingen `auth`: en utgången spelartoken ger en ny anonym spelare, och den gamla matchen svarar då `gone`.
7. Efter omladdning: `matches.active()` ger spelarens öppna matcher. Väntar en match du skapade (`status` `waiting`, `yourSeat` 0): `matches.invite(id)` ger en ny `joinToken` för en ny QR-kod (den gamla länken slutar gälla). Bevaka sedan matchen med `watch`.
8. Revansch: `matches.rematch(id)` när matchen är `finished`. Händelsen `rematch` (i `events`, med `payload.match_id`) ger ett sista `onChange` och stoppar bevakningen; starta då en ny `watch` på `payload.match_id`. `matches.leave(id)` lämnar matchen.

```ts
import { MatchError, describeError, getComputercat, inviteUrl, readJoinToken, type MatchSnapshot } from '@/lib/computercat';

/** Spelets egna texter. Visa aldrig en felkod som den är. */
function matchText(e: unknown): string {
  if (!(e instanceof MatchError)) return describeError(e);
  switch (e.code) {
    case 'not_your_turn': return 'Vänta, det är inte din tur.';
    case 'invite_expired': return 'Inbjudan gäller inte längre. Be om en ny.';
    case 'own_match': return 'Det här är din egen match. Skicka länken till en vän.';
    case 'too_many_active': return 'Du har redan fem öppna matcher. Avsluta en först.';
    case 'not_found': return 'Matchen finns inte längre.';
    default: return 'Det draget går inte just nu.';
  }
}

const cc = getComputercat();
if (cc.ok) {
  const matches = cc.client.matches;
  let current: MatchSnapshot | null = null;
  let w: { stop(): void; pollNow(): void } | null = null;

  function follow(id: string) {
    w?.stop();
    w = matches.watch(id, {
      onChange: (s) => {
        current = s;
        hideBanner();
        render(s);
        const rematch = s.events.find((e) => e.type === 'rematch');
        if (rematch && typeof rematch.payload.match_id === 'string') follow(rematch.payload.match_id);
      },
      onError: (e) => (e.terminal ? backToMenu(e.kind === 'gone' ? 'Matchen finns inte längre.' : matchText(e.error)) : showBanner(matchText(e.error))),
    });
  }

  const token = readJoinToken();
  if (token) {
    matches.join(token).then((s) => follow(s.id), (e: unknown) => show(matchText(e)));
  }

  async function invite() {
    try {
      const { match, joinToken } = await matches.create('reveal');
      showQr(inviteUrl(joinToken));
      follow(match.id);
    } catch (e) {
      show(matchText(e));
    }
  }

  async function move(payload: Record<string, unknown>) {
    if (!current) return;
    try {
      current = await matches.act(current, 'guess', payload);
      render(current);
    } catch (e) {
      if (e instanceof MatchError && e.code === 'stale') {
        if (e.snapshot) {
          current = e.snapshot;
          render(current);
        }
        // Erbjud draget igen bara om det fortfarande är tillåtet i current.
      } else {
        show(matchText(e));
      }
    }
  }

  function leaveView() {
    w?.stop();
    w = null;
  }
}
```

## iOS (Capacitor)

Spelet kan byggas som iOS-app med Capacitor 8.5.3 (Swift Package Manager). Gör det här en gång, och bara när spelet ska till TestFlight:

1. Anropa `get_release_status` för spelet. `targets.app_store.ios.bundle_id` är spelets bundle-id (`se.computercat.<slug utan bindestreck>`). Saknas `app_store` i `release_targets`, eller är `bundle_id` `null`: stanna och be Erik sätta målet med `set_release_targets`. Hitta aldrig på ett bundle-id.
2. Anropa `get_game` och ta spelets `name`. Det blir appens namn på hemskärmen (1–50 tecken, utan `<`, `>` och `&`).
3. Kör `npm ci --ignore-scripts` och `npm run build`.
4. Kör `node scripts/ios-setup.mjs <bundle_id> "<name>"`. Skriptet skriver `capacitor.config.ts` (`appId` = bundle-id, `webDir: 'out'`), kör Capacitors `cap add ios --packagemanager SPM` ur `node_modules` och lägger in `ITSAppUsesNonExemptEncryption` = `false` i `ios/App/App/Info.plist`. Det körs en gång; finns `ios/` redan vägrar det.
5. Kör `npm run check`. Sista raden ska vara `check-ios: OK`.
6. Committa `capacitor.config.ts` och `ios/` med meddelandet `iOS-projekt (Capacitor)` och pusha till `main`. Coolify bygger Demo som vanligt; `ios/` påverkar inte webbygget.

Regler för `ios/` och `capacitor.config.ts`:

- De tillhör spelet men ändras inte för hand. `scripts/check-ios.mjs` och Computercats bygge kontrollerar `appId`, `PRODUCT_BUNDLE_IDENTIFIER` och `Info.plist`.
- Version och byggnummer sätts aldrig i `ios/`: `CFBundleShortVersionString` och `CFBundleVersion` står kvar som `$(MARKETING_VERSION)` och `$(CURRENT_PROJECT_VERSION)`. Versionen kommer från `package.json`, byggnumret från Computercat.
- Inga certifikat, nycklar, profiler, `ExportOptions.plist` eller byggflöden (`.github/workflows`, fastlane) i repot. Computercat bygger, signerar och laddar upp.
- Faller `ios-setup.mjs` halvvägs, innan `ios/` och `capacitor.config.ts` är committade: ta bort dem och kör `ios-setup` igen (inget raderas automatiskt). Ta aldrig bort ett `ios/` som redan är committat; fråga en människa. Gäller felet `CFBundleDisplayName`: fråga en människa om namnet.
- Nya Capacitor-plugin eller annan inbyggd kod: fråga faktabanken och en människa först.

## Skicka till TestFlight

Skicka till TestFlight (kräver byggguide v3 eller senare i spelet, den med iOS-stöd). En push utan höjd version bygger aldrig appen; bara promote_release efter en versionshöjning gör det. 1. Höj version i package.json (MAJOR.MINOR.PATCH): högre än den senast släppta till TestFlight och inte använd förut. 2. Kör npm run check, committa och pusha till main. 3. Vänta tills Demo-bygget för commiten är grönt (get_provisioning_status visar demo.state live). 4. Anropa promote_release(target: app_store, stage: testflight, commit_sha: <commiten>). 5. Följ get_release_status tills släppet är klart. Computercat sätter taggen v<version>, väljer byggnummer, bygger appen och lägger den i TestFlight-gruppen ComputerCat intern.

`promote_release` tar också `game` (spelets slug), och `commit_sha` är hela SHA:n från `git rev-parse HEAD` (40 tecken, gemener). Kontrollera i punkt 3 att `demo.commit_sha` i `get_release_status` är just den commiten, eftersom `demo.state` `live` kan gälla förra bygget.

Svarar `promote_release` att versionen är för låg eller att taggen redan finns: höj `version` i `package.json` som svaret säger och börja om från punkt 1. Följ alltid `next` i `get_release_status`. iOS-projektet måste finnas (se "iOS (Capacitor)") innan första släppet.

## Uppgradera ett v2-spel till v3

Gäller bara spel vars `CLAUDE.md` har `Byggguide: **v2**`. Ett v1-spel: fråga en människa.

1. Hämta manifestet för spelets nuvarande version och för v3 med `get_build_guide`.
2. För varje fil i v3:s manifest: finns den inte i spelet, eller har spelets fil samma `sha256` som i den gamla versionens manifest (en oförändrad guidefil), skriv v3:s fil exakt och kontrollera `sha256`.
3. En fil som spelet har ändrat (till exempel exemplets filer, `src/app/page.tsx` och `src/app/layout.tsx`) behålls. Står den i listan nedan: för in v3:s ändring för hand, och fråga en människa om det är oklart.
4. `package.json`: behåll spelets `name` och de beroenden spelet har lagt till. Lägg till de paket i v3:s `stack` som saknas, i samma sektion som i v3:s `package.json` (`@capacitor/core` och `@capacitor/ios` under `dependencies`, `@capacitor/cli` under `devDependencies`). Sätt varje paket i `stack` till exakt den versionen, också de som går ned (som `jsdom`). Lägg `node scripts/check-ios.mjs` sist i `check` som i v3:s fil, och kör `npm install --package-lock-only --ignore-scripts`. Ta v3:s `package-lock.json` bara om spelet inte har egna beroenden.
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
- `src/lib/computercat/auth.test.ts`
- `src/lib/computercat/auth.ts`
- `src/lib/computercat/http.test.ts`
- `src/lib/computercat/http.ts`
- `src/lib/computercat/index.test.ts`
- `src/lib/computercat/index.ts`
- `src/lib/computercat/matches.test.ts`
- `src/lib/computercat/matches.ts`
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
