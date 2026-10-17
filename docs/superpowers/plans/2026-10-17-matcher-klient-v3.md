# Matcher, klientmodulen i byggguide v3 — implementationsplan

> **För agenter:** OBLIGATORISK SUB-SKILL: superpowers:subagent-driven-development. Stegen är checkboxar (`- [ ]`).

**Mål:** Ett spel byggt från byggguide v3 kan skapa, gå med i, spela och bevaka en match genom `client.matches`, utan egen nätverkskod.

**Arkitektur:** En ny modul `matches.ts` ovanpå klientens befintliga `authed` (401 → ny anonym spelare, ett nytt försök). Polling med `If-None-Match` kräver rubriker och 304, som `requestJson` inte klarar; därför en ny exporterad funktion i `http.ts` (`requestJsonMeta`) och en syskonfunktion `authedMeta` i `auth.ts`. `requestJson` och `authed` ändras inte.

**Teknik:** TypeScript 5.9, vitest 4.1, jsdom 29.1.1 (byggguide v3), `test/fakeFetch.ts`.

**Spec:** `docs/superpowers/specs/2026-10-17-matcher-design.md` (avsnitten API, Klient). Serverns verkliga beteende: `app/Http/Controllers/Api/V1/MatchController.php`, `app/Matches/MatchView.php`, och manualavsnittet "Turn-based Matches" i `app/Services/IntegrationGuide.php`.

**Samordning:** v3 ägs av sessionen `computercat-api-c0` (grenen `plan/byggguide-v3`). Arbetet görs på grenen `feat/v3-matches`, grenad från `plan/byggguide-v3` @ c5bfc0f (efter dess Task 7) i worktreen `.claude/worktrees/feat-v3-matches`. Grenen rör bara `resources/byggguide/v3/` och den här planen; serverändringarna (manualens fragmentlänk, tomma payloads som `{}`) görs på en egen gren från main (`fix/matcher-fragment`). Den sessionen tar in grenen före låsning.

**Testkörning (v3:s sätt):** `files/` har inga `node_modules`. `GUIDE_DEV=/var/folders/q7/_9t78k211td4_40t5p9ngqfr0000gn/T/byggguide-verify-gu75s64njdln` (skapad med `verify v3 --keep`). Loop: `rsync -a "$WT/resources/byggguide/v3/files/" "$GUIDE_DEV/" && cd "$GUIDE_DEV" && NODE_ENV=test npx vitest run <fil>`, och `npx eslint <fil>` / `npx tsc --noEmit` där. Lint och typkontroll: `php artisan computercat:byggguide:verify v3` (kör `npm run check`). Vitest kör i miljön `node`: `document`, `window`, `location` och `history` finns inte i testerna.

## Globala villkor

- Rör inte: `achievements.ts`, `queue.ts`, `scripts/check-ios.mjs`, `scripts/ios-setup.mjs`, `test/iosSetup.test.ts`, `eslint.config.mjs`, `test/eslintRules.test.ts`. I `index.ts` bara import, konstruktion i `createComputercat` och export; `flushAll`/`onFlushed` orörda. I v3:s `CLAUDE.md` rörs inte avsnitten som testas mot `ReleaseNext::TESTFLIGHT_FLOW` eller spararkontraktet.
- Identifieraren `process` får inte förekomma i `matches.ts` (lint R6), inte heller som egenskaps- eller fältnamn.
- Manifestet redigeras aldrig för hand: `php artisan computercat:byggguide:hash v3`.
- Pollintervall 1500 ms. Backoff 5000 ms vid nätfel och 5xx. Paus när `document.visibilityState === 'hidden'`, omedelbar poll när fliken blir synlig igen.
- Fetch för poll: `cache: 'no-store'` och egen `If-None-Match`.
- Inbjudningslänken: `${origin}/#join=${token}` (fragment, inte query: hamnar inte i serverloggar eller Referer). Läsaren godtar också `?join=` för bakåtkompatibilitet och tar alltid bort token ur adressen med `history.replaceState`.
- Felkoder från servern (exakt): `not_your_turn`, `wrong_phase`, `already_locked`, `already_ready`, `out_of_range`, `invalid_payload`, `unknown_action`, `stale`, `invite_expired`, `own_match`, `too_many_active`, `ruleset_not_allowed`, `rate_limited`.
- Fail loud: `watch` stannar aldrig tyst. Varje fel går till `onError` med en typad orsak; terminala fel (404, 410, matchen `abandoned`) avslutar bevakningen och sägs i `onError`/`onChange`.
- Svenska kommentarer och felmeddelanden, som resten av klienten.
- Webbläsarglobaler (`document`, `window`, `location`, `history`) läses lat vid anrop (`typeof document !== 'undefined'`), aldrig vid import, så att statisk export och testmiljön `node` fungerar. Testerna skickar alltid in `doc`, `loc`, `hist` och `origin`.
- PHP:s tomma array blir JSON `[]`: `payload`, `state` och `you` som är `[]` normaliseras till `{}`; andra icke-objekt avvisas. `at` är `string | null`.
- En pollning vars `version` är lägre än den högsta kända kastas (ingen `onChange`). Inget handtag anropas efter `stop()`.
- v3:s `CLAUDE.md`, avsnittet med uppgraderingslistan (R10, testas av `test_the_upgrade_list_is_exactly_the_files_v3_adds_or_changes`), får och måste uppdateras med `http.ts`, `http.test.ts`, `auth.ts`, `auth.test.ts`, `matches.ts`, `matches.test.ts`.

## Fokus för granskningen

1. **Två polls samtidigt** (fliken blir synlig medan en poll pågår): högst en poll i taget per match; den andra hoppas över. Test i Task 3.
2. **304 efter ett eget drag**: svaret från `act` bär ETag; nästa poll med den ETag:en ska ge 304 utan att `onChange` anropas en gång till. Test i Task 3.
3. **409 `stale`**: `act` hämtar ögonblicksbilden på nytt, anropar `onChange` och kastar `MatchError` med koden, så att spelet kan visa läget och låta spelaren försöka igen. Ingen automatisk omsändning av draget. Test i Task 2.
4. **Token i adressen**: efter `readJoinToken()` finns ingen `join=` kvar i `location`. Test i Task 4.
5. **Oväntad svarsform** (fält saknas, fel typ): `Error('Oväntat svar från …')`, aldrig ett halvt objekt till spelet. Men `payload: []` (motspelaren lämnade, revansch begärd) är giltigt och blir `{}`. Test i Task 2.
6. **Gammal poll efter eget drag**: en poll som startade före `act` och landar efter ger lägre `version` och kastas. Test i Task 3.

---

## Task 1: `requestJsonMeta` och `authedMeta`

**Filer:** ändra `files/src/lib/computercat/http.ts`, `files/src/lib/computercat/auth.ts`; test `files/src/lib/computercat/http.test.ts`, `files/src/lib/computercat/auth.test.ts` (nya fall, befintliga orörda).

**Producerar:**
- `export type MetaResult<T> = { status: number; body: T | null; etag: string | null }`
- `export async function requestJsonMeta<T>(fetchImpl, baseUrl, method, path, opts: { token?: string | null; body?: unknown; headers?: Record<string, string>; noStore?: boolean; signal?: AbortSignal }): Promise<MetaResult<T>>`. Samma timeout, nätfel (`NetworkError`), loggning och `ApiError` som `requestJson`. 2xx ger `{status, body, etag}`; 304 ger `{status: 304, body: null, etag}` utan fel och utan loggning; övriga statusar kastar `ApiError` precis som `requestJson`. `noStore` sätter `cache: 'no-store'`. `etag` läses ur `res.headers.get('ETag')`. Egen `signal` kombineras med timeouten (`AbortSignal.any` om den finns, annars en egen `AbortController`).
- Dela inte kod genom att ändra `requestJson`; en privat hjälpare som båda använder är tillåten bara om `requestJson`s befintliga tester är gröna oförändrade.
- `auth.ts`: `authedMeta: <T>(method, path, opts?: { body?: unknown; headers?: Record<string,string>; noStore?: boolean; signal?: AbortSignal }) => Promise<MetaResult<T>>`, med samma 401-logik som `authed`. Läggs till i returobjektet.

- [ ] Tester (304-svar byggs som `new Response(null, {status: 304, headers: {ETag: '"3-11"'}})`, inte med `json()`; `fakeFetch` registrerar inte `cache`, så testet lindar fetch för att fånga `init.cache`; `fakeFetch` själv ändras inte): 304 ger `status 304`, `body null`, ingen `console.error`/`warn`; avbruten `signal` ger `NetworkError`; 200 med `ETag: "3-11"` ger `etag '"3-11"'`; `If-None-Match` och `cache: 'no-store'` skickas; 409 kastar `ApiError` med body; nätfel ger `NetworkError`; `authedMeta` på 401 byter spelare och försöker en gång. Befintliga `http.test.ts`/`auth.test.ts` gröna utan ändring.
- [ ] Rött, implementera, grönt (`npm test -- http auth`). Commit: "Byggguide v3: requestJsonMeta och authedMeta för matcher".

## Task 2: `matches.ts` — anropen och typerna

**Filer:** skapa `files/src/lib/computercat/matches.ts`, `files/src/lib/computercat/matches.test.ts`.

**Konsumerar:** `Authed`, `authedMeta` (Task 1), `isRecord`, `ApiError`.

**Producerar:**
- Typer: `MatchPlayer = { seat: 0 | 1; name: string; connected: boolean }`, `MatchEvent = { seq: number; type: string; seat: 0 | 1 | null; payload: Record<string, unknown>; at: string | null }`, `MatchSnapshot = { id: string; ruleset: string; status: 'waiting' | 'active' | 'finished' | 'abandoned'; version: number; yourSeat: 0 | 1; players: MatchPlayer[]; state: Record<string, unknown>; you: Record<string, unknown>; events: MatchEvent[]; etag: string | null }`, `ActiveMatch = { id; ruleset; status; yourSeat }`.
- `class MatchError extends Error { code: string; status: number; body: unknown }`. Alla 4xx från matchvägarna med `code` i body blir `MatchError` (409 stale, 410 invite_expired, 422 regelfel, 429 rate_limited/too_many_active, 403). 404 blir `MatchError` med `code: 'not_found'`. Validering (422 med `errors`) blir `MatchError` med `code: 'validation'`.
- `createMatches(game: string, deps: { authed: Authed; authedMeta: AuthedMeta })` → `{ create(ruleset, options?, name?): Promise<{ match: MatchSnapshot; joinToken: string }>; join(token, name?): Promise<MatchSnapshot>; get(id, since?): Promise<MatchSnapshot>; act(snapshot: MatchSnapshot, type: string, payload?: Record<string, unknown>): Promise<MatchSnapshot>; leave(id); rematch(id); invite(id): Promise<{ match; joinToken }>; active(): Promise<ActiveMatch[]>; watch(...) }` (`watch` i Task 3).
- Vägarna och kropparna exakt som i `MatchController`: `POST /games/{game}/matches {ruleset, options, name?}` (201 `{match, join_token}`), `POST …/matches/join {token, name?}`, `GET …/matches/{id}?since=`, `POST …/matches/{id}/actions {type, payload, version}`, `POST …/leave`, `POST …/rematch`, `POST …/invite` (`{match, join_token}`), `GET …/matches/active` (`{data: [{id, ruleset, status, your_seat}]}`). Sökvägssegment med `encodeURIComponent`.
- `act` skickar `version: snapshot.version`. Vid `stale` hämtar den matchen (`get`), och kastar sedan `MatchError('stale')` med den nya ögonblicksbilden i `MatchError.snapshot`. Misslyckas omhämtningen kastas ändå `MatchError('stale')` utan `snapshot` (originalfelet döljs inte; omhämtningsfelet loggas med `console.warn`).
- Formkontroll av varje svar (alla fält i `MatchSnapshot`, `your_seat` → `yourSeat`). Fel form: `Error('Oväntat svar från <METOD> <väg>')`.
- `assertName(name)` speglar serverns regel: trim, sedan `/^[^\p{C}]{1,20}$/u` (räknar kodpunkter som PHP, inte UTF-16-enheter; ZWJ-emoji avvisas som på servern) och kastar `RangeError` synkront, så ett ogiltigt namn aldrig blir en 422.
- `create` får ingen ETag från servern (bara de andra POST-svaren har en); `etag` är då `null`.

- [ ] Tester med `fakeFetch` (vägnycklar med frågesträng, t.ex. `GET …/ID?since=5`, eller funktionssvar): varje väg skickar rätt metod, sökväg och kropp; `create` ger `joinToken`; `act` skickar aktuell `version`; `stale` ger ny `get` och `MatchError` med `snapshot`; 404 ger `not_found`; 410 ger `invite_expired`; 429 med `rate_limited` och med `too_many_active` skiljs åt; ogiltigt namn kastar `RangeError` utan anrop; svar som saknar `version` ger "Oväntat svar"; händelse med `payload: []` och `you: []` blir `{}`; `at: null` godtas; namn med 20 emoji (kodpunkter) godtas, 21 avvisas; `unmatched` tom i alla test.
- [ ] Rött, implementera, grönt. Commit: "Byggguide v3: matches.ts — anrop och typer".

## Task 3: `watch`

**Filer:** ändra `matches.ts`, `matches.test.ts`.

**Producerar:** `watch(id: string, handlers: { onChange(s: MatchSnapshot): void; onError(e: WatchError): void }, opts?: { intervalMs?: number; backoffMs?: number; doc?: Pick<Document, 'visibilityState' | 'addEventListener' | 'removeEventListener'> }): { stop(): void; pollNow(): void }`.
- `WatchError = { kind: 'network' | 'server' | 'rate_limited' | 'gone' | 'shape'; error: unknown; terminal: boolean }`. `rate_limited` (429): vänta `Retry-After` sekunder (annars `backoffMs`), icke-terminal. (`auth` finns inte: `authedMeta` byter spelare vid 401, och då svarar matchen 404 = `gone`; det dokumenteras i CLAUDE.md.) `gone` = 404/410 eller status `abandoned` (terminal). `network` och 5xx: icke-terminal, nästa poll efter `backoffMs`, och `onError` anropas vid första felet i en serie och sedan högst var 10:e sekund (så att spelet kan visa "tappat kontakten" utan att spammas). När pollen lyckas igen anropas `onChange` med ögonblicksbilden även om den är oförändrad, så att spelet kan släcka sin banner.
- Första pollen direkt. Sedan var `intervalMs` (1500). ETag sparas från varje svar (också från `act`, `join`, `create`, `invite` för samma id, via en intern karta) och skickas som `If-None-Match`. 304 → inget `onChange`. `since` skickas med senaste kända `seq`; `onChange` får en ögonblicksbild där `events` är den sammanslagna listan (tidigare + nya, unika på `seq`, sorterade).
- Högst en poll i taget. `visibilitychange` till `hidden` pausar och avbryter en pågående poll (`AbortController`, så att en hängande fetch i iOS Safari inte blockerar). Tillbaka till `visible`, `pageshow` och `online` pollar direkt. `stop()` tar bort lyssnarna, timern och avbryter pågående poll; inga handtag anropas efter `stop`.
- En pollning med `version` lägre än högsta kända (från poll eller `act`) kastas.
- Status `finished`: `onChange`, sedan fortsatt bevakning med 5000 ms intervall (revansch kan komma) så att den delade gränsen på 120/min inte äts upp. Händelsen `rematch {match_id}` i nya händelser: `onChange` med snapshoten och sedan stopp; spelet byter själv till `watch(nytt id)`. Efter omladdning hittar `active()` den nya matchen.

- [ ] Tester med vitest fake timers och en falsk `doc` (miljön är `node`): första poll direkt; gammal poll med lägre version efter `act` ger inget `onChange`; 429 med `Retry-After: 3` väntar 3000 ms; dold flik avbryter pågående poll; `finished` pollas var 5000 ms; `rematch`-händelse ger `onChange` och stopp; 304 ger inget `onChange`; ETag från `act` används i nästa poll; nätfel ger `onError({kind:'network', terminal:false})` en gång, backoff 5000 ms, sedan återhämtning med `onChange`; 404 ger `gone` terminal och inga fler anrop; `abandoned` ger `gone` terminal; dold flik ger inga anrop, synlig ger direkt poll; två `pollNow` samtidigt ger ett anrop; `stop` stoppar allt; händelser slås ihop utan dubbletter.
- [ ] Rött, implementera, grönt. Commit: "Byggguide v3: matches.watch med ETag, paus och backoff".

## Task 4: Inbjudningslänken

**Filer:** ändra `matches.ts`, `matches.test.ts`.

**Producerar:** `inviteUrl(token: string, origin?: string): string` (standard `window.location.origin` bara när den börjar med `https://` eller `http://localhost`; annars, t.ex. `capacitor://localhost` i iOS-appen, kastas `Error` som säger att spelets publika webbadress måste skickas in; resultat `${origin}/#join=${encodeURIComponent(token)}`) och `readJoinToken(loc?: Location, hist?: History): string | null`. Läser `#join=` och, för bakåtkompatibilitet, `?join=`. Godtar bara base64url med 43 tecken (`/^[A-Za-z0-9_-]{43}$/`); annat ger `null`. Tar alltid bort `join` ur adressen med `history.replaceState` (behåller övriga parametrar och fragment), också när värdet var ogiltigt.

- [ ] Tester (med inskickade `loc`/`hist`/`origin`): `inviteUrl` med `capacitor://localhost` kastar, med explicit https-origin fungerar; fragment läses och tas bort; query läses och tas bort med andra parametrar kvar; ogiltig token ger `null` och tas ändå bort; ingen token ger `null` och inget `replaceState`.
- [ ] Rött, implementera, grönt. Commit: "Byggguide v3: inbjudningslänk med fragment".

## Task 5: Klienten, texterna och manifestet

**Filer:** ändra `files/src/lib/computercat/index.ts` (import, `matches: createMatches(game, { authed: auth.authed, authedMeta: auth.authedMeta })`, export av typer, `MatchError`, `inviteUrl`, `readJoinToken`), `files/src/lib/computercat/index.test.ts` (klienten har `matches`), v3:s `files/CLAUDE.md` (ett avsnitt "Matcher" efter "Achievements"), `manifest.json` (bara via hash-kommandot). Serverändringarna ingår inte här (grenen `fix/matcher-fragment`).

Uppgraderingslistan (R10) i v3:s `CLAUDE.md` får de sex filerna från de globala villkoren.

CLAUDE.md-avsnittet (svenska, kort; i iOS-appen måste `inviteUrl` få spelets publika webbadress, och `readJoinToken` gäller bara webben; en utgången spelartoken ger en ny anonym spelare och den gamla matchen svarar då `gone`): när matcher används; `client.matches.create/join/act/watch`; spelet visar alltid ett synligt läge vid `onError` och vid `gone`; QR med `inviteUrl`; läs `readJoinToken()` vid start; efter omladdning: `matches.active()` och `invite(id)` för en ny QR om matchen väntar; `stale` betyder visa nya läget och låt spelaren göra om draget; anropa aldrig vägarna direkt.

- [ ] Kör: guidens vitest enligt testkörningen ovan, `php artisan computercat:byggguide:hash v3`, `php artisan computercat:byggguide:verify v3`, `php artisan test --filter='BuildGuideV3Test|BuildGuideTest|ReleaseFactbankSyncTest'`, och lint av guidens filer som v3-planen gör det. Allt grönt.
- [ ] Commit: "Byggguide v3: matcher i klienten, CLAUDE.md och manifest".

## Avslut

- [ ] Hela `php artisan test` grön. Meddela `computercat-api-c0` grenens namn och HEAD. Ingen merge till main härifrån; v3-sessionen tar in grenen och låser.
- [ ] completion-gate för klientmodulen.
