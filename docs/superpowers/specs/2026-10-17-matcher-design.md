# Matcher: turbaserade spel för två spelare

Datum: 2026-10-17. Status: förslag för Eriks granskning. Upphov: Reveal (gissa motspelarens tal), det första spelet där två mobiler spelar mot varandra. Spelets egen design står i `~/App/Reveal/docs/2026-10-17-reveal-design.md`.

## Syfte

Två spelare på var sin mobil ska kunna spela en match mot varandra genom Computercat, utan egen backend per spel. Servern är domare: den håller dold information (till exempel varje spelares hemliga tal), avgör vems tur det är, prövar varje drag mot spelets regler och berättar för varje spelare bara det den får veta.

## Läget

- Det finns ingen realtid, inga rum och inga matcher i dag. `BROADCAST_CONNECTION=log`; kö och cache går mot databasen.
- Spelare är anonyma Sanctum-användare (`POST /auth/anonymous`, token 90 dagar, `config/sanctum.php:61`). Spelvägarna ligger under `auth:sanctum`, `TrackLastSeen` och `ResolveGame` (`routes/api.php:124`).
- Spelets CSP släpper bara `connect-src 'self' <API>` (`byggguide/v2/.../csp.ts:24`). Allt måste alltså gå över `api.computercat.co`.
- Global gräns `throttle:60,1` (`bootstrap/app.php:27`). Polling en gång i sekunden når den gränsen direkt; matchvägarna behöver egna gränser.
- Spelspecifik serverkod har prejudikat: Glosis vägar med `->where('game', 'glosis')` (`routes/api.php:83-113`).

## Beslut som Erik tagit

- Synken byggs in i Computercat, inte som egen tjänst eller hostad realtidstjänst.
- **Generell grund + regler per spel.** Rum, anslutning, turordning, dold state och händelselogg är gemensamma. Varje spels regler är en egen PHP-klass.
- **Polling**, inte websockets. Turbaserat, ingen ny infrastruktur, ingen CSP-ändring.
- Man går med via **QR-kod** (som pekar på en anslutningslänk).

## Modell

### Tabeller

`matches`
- `id` ULID (publikt id i vägarna).
- `game_id`, `ruleset` (sträng, t.ex. `reveal`), `options` JSON (validerad av regelklassen).
- `status`: `waiting` → `active` → `finished` | `abandoned`.
- `join_token`: 32 slumpade byte, base64url. Lagras som sha256-hash; klartexten finns bara i svaret till den som skapar matchen. Ogiltig efter att andra spelaren gått med eller efter 15 minuter.
- `state` JSON: publik state som båda spelarna får se.
- `version` (int, ökar vid varje ändring), `last_action_at`, `expires_at`, tidsstämplar.

`match_players`
- `match_id`, `user_id`, `seat` (0 eller 1), unik på (`match_id`, `seat`) och (`match_id`, `user_id`).
- `name`: visningsnamn för just den här matchen, 1–20 tecken, trimmat, utan kontrolltecken. Ogiltigt namn ger 422 (aldrig tyst null). Saknas det blir det `Spelare 1`/`Spelare 2` efter säte. Kontots `display_name` används inte; v2-klienten kan inte sätta det och matchnamnet ska inte bli kontoövergripande.
- `hidden` JSON med Laravels `encrypted:array`-cast: spelarens dolda state (Reveals hemliga tal). Lämnar aldrig servern via någon resurs.
- `last_seen_at`: skrivs vid poll, men högst var femte sekund per spelare (jämför med lagrat värde innan skrivning).

`match_events` (append-only)
- `match_id`, `seq` (1, 2, 3 … per match, unik), `type`, `seat` (nullable), `payload` JSON (publik), `created_at`.

### Regelklasser

Interface `App\Matches\Rules\MatchRules`:

```php
public function validateOptions(array $options): array;          // normaliserade options eller ValidationException
public function start(MatchContext $ctx): void;                  // när andra spelaren gått med
public function act(MatchContext $ctx, int $seat, string $type, array $payload): void;
```

`MatchContext` ger regelklassen läs/skriv på publik `state`, varje spelares `hidden`, `emit(type, seat, payload)` för händelser, och `finish()`. Regelklassen kastar `IllegalMove` (blir 422 med en kod, t.ex. `not_your_turn`, `out_of_range`) vid ogiltigt drag. Registret är en config-map `config/matches.php`: `ruleset => klass`, och vilka spel som får använda vilka rulesets (`'reveal' => ['games' => ['reveal']]`). Ett spel som försöker skapa en match med ett ruleset det inte har får 403.

Reveals regler (`RevealRules`) står i Reveal-specen; här bara det matchgrunden kräver av dem.

## API

Alla under `/games/{game}/matches`, med `auth:sanctum`, `TrackLastSeen`, `ResolveGame`. Den globala gränsen ersätts av egna:

| Väg | Gör | Gräns |
|---|---|---|
| `POST /matches` `{ruleset, options, name?}` | skapar match, skaparen får säte 0 | `match-create` 10/min per användare |
| `POST /matches/join` `{token, name?}` | går med som säte 1, startar matchen | `match-join` 10/min per användare **och** 30/min per IP |
| `POST /matches/{id}/rematch` | begär revansch; när båda begärt skapas en ny match | `match-action` |
| `GET /matches/{id}?since={seq}` | ögonblicksbild + händelser efter `seq` | `match-poll` 120/min per användare |
| `POST /matches/{id}/actions` `{type, payload, version}` | ett drag | `match-action` 60/min per användare |
| `POST /matches/{id}/leave` | lämnar; matchen blir `abandoned` | `match-action` |
| `GET /matches/active` | spelarens pågående matcher i spelet (återanslutning efter omladdning) | `match-poll` |

**Svarsform** (samma för GET och efter POST): `{id, ruleset, status, version, your_seat, players: [{seat, name, connected}], state, you, events: [{seq, type, seat, payload, at}]}`. `you` är regelklassens vy av den egna spelarens dolda state (Reveal: ditt eget tal). Motspelarens `hidden` finns aldrig i svaret.

**Polling**: ETag är `"{version}-{c0}{c1}"`, där `c0`/`c1` är 1 eller 0 för om säte 0 och 1 är anslutna. Klienten skickar `If-None-Match`; oförändrad ETag ger `304` utan body. Eftersom anslutningsstatus ingår i ETag får klienten veta när motspelaren tappar kontakten, även utan drag. En poll kostar en läsning av matchen och spelarna, plus högst en skrivning av `last_seen_at` var femte sekund. `since` begränsar händelserna; utan `since` kommer de 200 senaste.

**Databas**: radlåset (`lockForUpdate`) kräver MySQL/Postgres i produktion. Planen verifierar produktionens drivrutin via Forge innan den byggs, inte genom att läsa `.env`. Testerna på sqlite serialiserar ändå, så låstestet körs som ett 409-test på `version`, inte som ett test av låset. Gränsräknarna (`CACHE_STORE=database`) ger en skrivning per anrop; vid två spelare och 1,5 sekunder är det runt 80 skrivningar i minuten per match. Planen mäter det mot ett lokalt riktmärke och flyttar matchgränserna till Redis om det behövs.

**Revansch**: `rematch` är tillåten bara i `finished`. Första anropet ger händelsen `rematch_requested {seat}`. När båda begärt skapas en ny match med samma spelare, säten, namn och `options`, utan `join_token`, och den gamla matchen får händelsen `rematch {match_id}`. Klienterna byter match när de ser händelsen. Den gamla matchen räknas inte längre som aktiv, så taket på fem påverkas inte.

**Drag**: transaktion med `lockForUpdate()` på matchraden. `version` i anropet måste vara matchens nuvarande, annars `409 {code: "stale", version}` och klienten pollar om. Det gör dubbelklick och två snabba drag ofarliga.

**Åtkomst**: en användare som inte sitter i matchen får `404`, samma som ett id som inte finns. Ingen uppräkning av matcher.

**Anslutningslänk**: `POST /matches` svarar med `join_token`. Klienten bygger själv länken `https://<spelets origin>/?join=<token>` och visar den som QR. Servern känner inte till spelets origin och behöver inte göra det.

## Livscykel och städning

- `waiting` utan motspelare i 15 minuter → `abandoned`.
- `active` utan drag i 30 minuter → `abandoned`. Varje `act` flyttar `expires_at`.
- `connected` i svaret = motspelaren pollat de senaste 10 sekunderna. Klienten visar "Väntar på Anna… (tappat kontakten)" när den är falsk. Ingen automatisk förlust vid frånkoppling i v1.
- Kommandot `matches:expire` (var femte minut) sätter `abandoned`. `matches:prune --days=7` (dagligen) raderar avslutade och övergivna matcher med spelare och händelser. Dolda värden nollställs redan när matchen avslutas eller överges.
- En användare har högst 5 matcher i `waiting`/`active` per spel; den sjätte ger 429 med `code: "too_many_active"`.

## Invarianter (testas, inte bara tänks)

1. **Inget läckage av dold state.** Ett test kör en hel Reveal-match drag för drag och kontrollerar efter varje svar till varje säte att JSON-trädet inte har nyckeln `hidden` och att motspelarens hemliga tal bara förekommer i fält som regelklassen uttryckligen avslöjat (rundslutets `reveal`-händelse). Resursen är en vitlista, inte `toArray()` på modellen.
2. **Händelseloggen är sanningen.** `seq` är luckfri per match, och att spela upp händelserna ger samma publika `state` som den lagrade (test per ruleset).
3. **Bara den vars tur det är kan göra ett drag**, och ett avvisat drag ändrar varken `version`, `state` eller loggen.
4. **Åtkomst.** Tredje användare: 404 på alla vägar. Andra spels slug: 404.

## Klient (byggguiden)

Ny modul `src/lib/computercat/matches.ts`: `create`, `join`, `get`, `act`, `leave`, `active`, och `watch(id, onChange)` som pollar var 1,5 sekund med `If-None-Match`, pausar när fliken är dold (`visibilitychange`), backar till 5 sekunder vid nätfel och rapporterar `onError` synligt i stället för att tyst stå still. Formkontroll av svaret som `leaderboards.ts`. Tester med `fakeFetch`: 304, 409 stale, 404, backoff, paus vid dold flik.

Byggguidens `CLAUDE.md` får ett stycke om matcher: spelet visar alltid ett synligt läge när `watch` rapporterar fel.

## Integrationsmanual och MCP

- `IntegrationGuide::FEATURES` får `matches`. Avsnittet visas när spelet har minst ett ruleset i `config/matches.php`.
- Inga MCP-verktyg i v1: rulesets är kod och kopplas till spel i config. `get_game` visar vilka rulesets spelet har.

## Utanför scope

- Fler än två spelare, åskådare, matchmaking mot främlingar.
- Websockets/Reverb. Grunden ändras inte av att push läggs till senare: `version` och `since` fungerar likadant.
- Topplistor och achievements från matcher (kan läggas till genom att regelklassen anropar befintliga tjänster).
- Filament-vy för matcher.

## Ordning

1. Server: tabeller, grund, `RevealRules`, vägar, gränser, städning, invarianttester. Bakåtkompatibelt, inget befintligt spel påverkas.
2. Klientmodulen går in i **byggguide v3** (Eriks beslut 2026-10-17). v3 byggs just nu i worktreen `plan-byggguide-v3` av en annan session, med planen `docs/superpowers/plans/2026-10-16-byggguide-v3.md` (achievements + Capacitor iOS). Planen för matcher börjar med att stämma av med den sessionen: modulen läggs till som egna commits i v3:s gren innan v3 låses, och v3-planens scope uppdateras med Eriks beslut. Arbetet rör inte v3:s achievements-filer.
3. Reveal byggs på v3.

## Val jag gjort (Claude), för granskning

- Namn per match i stället för kontots `display_name`.
- ETag med anslutningsstatus, `last_seen_at` högst var femte sekund.
- Revansch som generell väg i grunden, inte i Reveals regler.
- Länkens token är 32 slumpbyte och hashas i databasen. En QR-kod behöver ingen kod som kan läsas upp.
- Tidsgränserna: 15 min väntan, 30 min utan drag, gallring efter 7 dagar, högst 5 aktiva matcher per spelare och spel.
