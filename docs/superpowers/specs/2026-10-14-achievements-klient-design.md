# Achievements i byggguidens klient

Datum: 2026-10-14. Status: förslag för Eriks granskning. Upphov: Tinker Isle (`HumblebragAB/tinker-isle`), som har sex milstolpar men inte kan skicka dem.

## Syfte

Ett spel byggt från byggguiden ska kunna rapportera upplåsta achievements till Computercat och läsa tillbaka dem, utan att skriva egen nätverkskod. Servern har redan stödet. Det som saknas är modulen i klienten och några svar från servern som i dag är tysta.

## Läget

**Servern finns.** `routes/api.php:135-138` har tre vägar under `auth:sanctum` och `ResolveGame`:

| Väg | Gör | Svar |
|---|---|---|
| `GET /games/{slug}/achievements` | aktiva definitioner, sorterade | `{data: [{id, slug, name, description, icon, is_secret, sort_order}]}`, där `description` är `???` för hemliga (`AchievementDefinitionResource`) |
| `GET /games/{slug}/achievements/me` | spelarens upplåsta | `{data: [{slug, name, description, icon, unlocked_at}]}` (`UserAchievementResource`) |
| `POST /games/{slug}/achievements` | rapportera `{slugs: string[]}` (1–10, max 100 tecken) | `201 {unlocked: [...], already_unlocked: n}` |

Definitioner skapas med MCP-verktyget `upsert_achievement`: slug max 50 tecken, `name`, `description`, `icon` max 20 tecken (`AchievementRules`).

**Klienten saknar stödet.** Byggguide v1 (`resources/byggguide/v1/files/src/lib/computercat/`) har auth, saves, leaderboards, queue och proof, men inga achievements. Ett spel får inte prata med Computercat utanför klienten och får inte ändra den (v1:s `CLAUDE.md`). v1 är låst och v2 (säkerhetsspärren, etapp B) är under arbete, så förslaget gäller **v3**.

**Integrationsmanualen** visar avsnittet bara när spelet har minst en definition (`IntegrationGuide.php:301`). Ett spel utan definitioner får därför ingen dokumentation att utgå från, ens med `features: ["achievements"]`. Det hände Tinker Isle.

## Tysta fel i servern i dag

`AchievementController::store` säger inte vad som hände med varje slug:

1. **Okända och inaktiva slugs ignoreras utan att synas.** Det är testat som avsiktligt (`test_report_unlocks_ignores_unknown_slugs`). En felstavad slug i spelet märks aldrig.
2. **Rimlighetskontrollen räknas som "redan upplåst".** `already_unlocked` beräknas som `count($definitions) - count($unlocked)`. En definition som `checkAchievementPlausibility` avvisar hamnar alltså i `already_unlocked`. Klienten tror att den redan finns.
3. **En sparning med fel form ger 500.** `isset($save->data['progress']['results'])` (rad 56-57) släpper igenom en sträng eller ett tal, och `count()` (rad 74) eller parametern `array $results` (rad 112) kastar TypeError. En klient som behandlar 5xx som "försök senare" fastnar då för alltid.
4. **Rimlighetskontrollen är byggd för en sorts spel.** Den läser `save.data.progress.results` och jämför med första talet i slugen (`complete_100` → 100), eller med `settings.achievement_thresholds`. Ett spel vars sparning har en annan form, eller vars slug råkar innehålla en siffra (`level-2-done`), får upplåsningar avvisade eller släppta utan att veta varför. Kontrollen saknar tester.

## Förslag

### Server (bakåtkompatibelt)

- Rimlighetskontrollen körs bara när `progress.results` är en array (`is_array`); annars hoppas den över. Det tar bort 500-felet.
- `POST` svarar med två nya fält bredvid de gamla: `unknown: string[]` (okända eller inaktiva slugs) och `rejected: string[]` (avvisade av rimlighetskontrollen). `already_unlocked` räknar bara de som verkligen fanns. Fälten är nya, så ett spel som bara läser `unlocked` påverkas inte, men `already_unlocked` byter betydelse (avvisade räknas inte längre in). v1-klienten läser inte fältet; kontrollera före ändringen att inget annat spel gör det.
- Den döda variabeln `$alreadyUnlocked` (rad 61-63) tas bort.
- Vägarna har bara den globala gränsen 60/min (`bootstrap/app.php:27`). Ingen ny gräns föreslås; klienten skickar högst 10 slugs per anrop och tar bort dubbletter.
- Servertester: tröskel ur slug, tröskel ur settings, sparning utan `progress.results`, `results` som inte är en array (sträng, tal), en blandad omgång (ny, redan upplåst, okänd, avvisad) och att avvisade inte räknas i `already_unlocked`.
- Integrationsmanualen visar avsnittet när `achievements` begärs uttryckligen, också utan definitioner, med en rad om att definitionerna skapas med `upsert_achievement`. Utan uttrycklig begäran (`defaultFeatures()`, rad 46) är det som i dag. Test i IntegrationGuide-testerna.

### Klient (byggguide v3, `src/lib/computercat/achievements.ts`)

- `achievements.list()` och `achievements.mine()`: typade svar, med formkontroll som `leaderboards.ts`.
- `achievements.report(slugs)`: kontrollerar slugarna synkront som `assertScore` (icke-tom sträng, högst 50 tecken enligt `AchievementRules`) och kastar `RangeError` vid fel, så att en felaktig slug aldrig fäller en hel omgång med 422. Giltiga slugs köas lokalt (egen nyckel `computercat:<spel>:achievement-queue`, utan dubbletter) och skickas i omgångar om högst 10. Samma regler som resultatkön: offline, 5xx, 401, 408 och 429 väntar, andra 4xx kastas synligt. Ingen åldersgräns, eftersom en upplåsning inte hör till någon period.
- Svaret: `report()` ger `{ flushed: Promise<AchievementFlushResult> }` som `recordScore`, där `AchievementFlushResult = { sent, unlocked, unknown, rejected, dropped, remaining }`. Spelet visar `unknown`, `rejected` och `dropped` (fail loud). Saknas fälten (server före ändringen) räknas allt som skickat.
- `rejected` tas ur kön, eftersom rimlighetskontrollen läser molnsparningen och ett nytt försök direkt ger samma svar. Upplåsningen finns kvar i spelets sparning, och spelet rapporterar hela sin lista vid varje start (idempotent på servern). En upplåsning som avvisades innan sparningen hann upp kommer alltså med nästa gång. Klientens `CLAUDE.md` säger det: spara innan du rapporterar, och rapportera hela listan vid start.
- Bakgrunden: `index.ts` får `flushAll()` som tömmer båda köerna och ger `{ scores: FlushResult, achievements: AchievementFlushResult }`; `BackgroundSync` anropar den i stället för `queue.flush`. Spelet kan prenumerera på resultatet med `onFlushed(cb)`, så att också bakgrundens svar kan visas.
- Typerna har `is_secret`, och `list()` klarar `description: "???"`.
- Tester med `fakeFetch`: ogiltig slug kastar synkront, kö offline, dubbletter tas bort, omgångar om 10, `unknown`/`rejected` syns, 422 kastas synligt, 5xx väntar, `flushAll` tömmer båda köerna, union vid synk.
- `CLAUDE.md` och README i v3 får ett stycke om achievements, och faktabanken en artikel (etapp C-formen).

### Tinker Isle efter v3

1. `upsert_achievement` för de sex milstolparna (`first-log`, `first-plank`, `first-fish`, `forged-blade`, `belt-network`, `first-storm`). Inga siffror i slugarna.
2. Flytta spelet till v3 och rapportera nya upplåsningar via klienten.
3. Rapportera vid start de upplåsningar som redan finns i sparfilen. Det är idempotent på servern.

## Utanför scope

- Achievements i Filament (finns redan).
- Server-side utvärdering av villkor. Manualen säger att de utvärderas i klienten.
- Att flytta befintliga v1-spel till v3.

## Ordning

v2 låses och deployas först (pågående etapp B). Serverändringen kan gå före; den är bakåtkompatibel för fälten (se `already_unlocked` ovan). Klienten blir v3 efter att v2 är live. **v3 kopieras från v2, inte v1**, så att säkerhetsskripten och byggkommandot följer med (v2-planens R4), och får egna värden i `BuildGuide::FROZEN` och `BuildGuideTest::EXPECTED`.

## Öppna frågor till Erik

1. Ska rimlighetskontrollen bara gälla spel som har `settings.achievement_thresholds`? Underlag (`get_game`, 2026-10-14): bara Tocco har achievements, och flera av dess slugs har siffror som inte är antal lösta pussel. Heuristiken tar första talet som tröskel, så `first_12x12` ("lös ditt första 12x12") avvisas tills spelaren löst 12 pussel, `first_14x14` kräver 14, `speed_3min` 3, `weekly_4` 4 och `speedrun_sub5` 5. Avsiktligt rätt är bara `solve_5`, `solve_25`, `solve_100` och möjligen `daily_30`/`daily_100`. Förslag: kontrollen gäller bara trösklar i `settings.achievement_thresholds`, och Toccos fem räkne-trösklar flyttas dit i samma ändring, så att inget som fungerar i dag slutar fungera. Glosis, Tells och Kattsvep har inga achievements.
