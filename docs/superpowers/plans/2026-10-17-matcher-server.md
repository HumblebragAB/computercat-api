# Matcher, serverdelen — implementationsplan

> **För agenter:** OBLIGATORISK SUB-SKILL: superpowers:subagent-driven-development (rekommenderas) eller superpowers:executing-plans. Stegen är checkboxar (`- [ ]`).

**Mål:** Computercat kan hålla turbaserade matcher mellan två spelare, med dold state per spelare och regler per spel, och Reveals regler är den första regelklassen.

**Arkitektur:** Tre tabeller (`matches`, `match_players`, `match_events`). Regelklasserna är händelsedrivna: `act()` prövar ett drag mot dold och publik state och emitterar händelser, och en ren reducer `apply()` bygger publik state ur händelserna. Den lagrade `state` är alltså en cache av loggen, och det är kontrollerbart. En `MatchService` äger transaktioner, lås, version och livscykel; controllern är tunn. Polling med ETag.

**Teknik:** Laravel 12, PHP 8.2, Sanctum, PHPUnit på sqlite i minnet.

**Spec:** `docs/superpowers/specs/2026-10-17-matcher-design.md`, plus reglerna i `~/App/Reveal/docs/2026-10-17-reveal-design.md` (avsnittet "Regler").

**Utanför denna plan:** klientmodulen i byggguide v3 (plan 2) och spelet Reveal (plan 3). Deploy bara på Eriks order.

**Arbetssätt:** worktree `docs/matcher` (`~/Web/computercat-api/.claude/worktrees/docs-matcher`), samma gren för koden. Ingen egen databas behövs (testerna kör sqlite i minnet). Testkommando: `php artisan test --filter=<Klass>`; hela sviten före slutgranskning. Kör `composer install` i worktreen först (vendor skiljer sig ett paket från lock).

## Globala villkor

- PHP-klassnamn: `Match` är ett reserverat ord. Modellerna heter `GameMatch`, `GameMatchPlayer`, `GameMatchEvent`; tabellerna `matches`, `match_players`, `match_events`.
- Spelarnamn: 1–20 tecken efter trim, inga kontrolltecken (`/\A[^\p{C}]{1,20}\z/u`; det avvisar också ZWJ, så sammansatta emoji-namn ger 422, vilket accepteras), ogiltigt ger 422. Saknas: `Spelare 1` / `Spelare 2` efter säte.
- `join_token`: `random_bytes(32)` som base64url utan utfyllnad (43 tecken); bara `hash('sha256', $token)` lagras.
- Tidsgränser: `waiting` 15 min, `active` 30 min sedan senaste drag, `connected` = poll inom 10 s, `last_seen_at` skrivs högst var 5:e sekund, gallring efter 7 dagar, högst 5 matcher i `waiting`/`active` per användare och spel.
- Gränser (`AppServiceProvider`, per användar-id): `match-create` 10/min, `match-join` 10/min per användare och 30/min per IP, `match-poll` 120/min, `match-action` 60/min. Matchvägarna tar bort den globala `throttle:60,1` med `->withoutMiddleware('throttle:60,1')`, som Glosis gör (`routes/api.php:111`).
- Felsvar: `{message, code}`. Koder: `not_your_turn`, `wrong_phase`, `already_locked`, `already_ready`, `out_of_range`, `invalid_payload`, `unknown_action`, `stale` (409, med `version`), `invite_expired` (410, samma för okänd, använd och gammal token), `own_match` (409), `too_many_active` (429), `ruleset_not_allowed` (403).
- Resurser byggs med uttryckliga fält, aldrig `toArray()` på en modell (som `GetGameTool`).

## Fokus för granskningen

1. **Tal som inte är heltal.** `"37"`, `37.0`, `37.5`, `true`, `null`, `[]`, `1e2`: alla ger 422 `invalid_payload`. Bara JSON-heltal godtas (`is_int`). Test i Task 3.
2. **Samma drag två gånger** (dubbeltryck, eller nät som skickar om): andra anropet har gammal `version` och får 409 `stale` utan sidoeffekt. Test i Task 4.
3. **Gå med i sin egen match** (skannar sin egen QR): 409 `own_match`, token förbrukas inte. Test i Task 4.
4. **Motspelaren lämnar mitt i en runda**: matchen blir `abandoned`, nästa poll hos den kvarvarande ger status `abandoned` och händelsen `left`, och dolda tal avslöjas inte. Test i Task 4.
5. **Poll mot en match som gallrats eller som man inte sitter i**: 404 utan att skilja fallen åt. Test i Task 7.

---

## Task 0: Förutsättningar i produktion

- [ ] Ta reda på produktionens databasdrivrutin och `CACHE_STORE` för api.computercat.co. Leta i Pejl (`mcp_pejl_search_documentation` "computercat"), i `docs/superpowers/specs/2026-10-13-spelplattform-mcp-design.md`, och sedan läsande via `laravel-forge`-skillen (serverns databastyp, inte sajtens env-fil). Läs aldrig `.env` och kör inget i produktion. Hittas svaret inte, fråga Erik med AskUserQuestion.
- [ ] Är databasen sqlite i produktion: stoppa och fråga Erik (radlåset gör då ingenting, och designen förutsätter MySQL/Postgres). Är cachen databasen: skriv det i slutrapporten för Task 5 som en känd kostnad (ungefär 80 skrivningar i minuten per pågående match). Ingen omläggning till Redis i den här planen.

## Task 1: Tabeller och modeller

**Filer:**
- Skapa: `database/migrations/2026_10_17_100001_create_matches_tables.php`, `app/Models/GameMatch.php`, `app/Models/GameMatchPlayer.php`, `app/Models/GameMatchEvent.php`
- Test: `tests/Feature/Matches/MatchModelTest.php`

**Producerar:**
- `matches`: `id` ulid primärnyckel, `game_id` fk (cascade), `ruleset` string(40), `options` json, `status` string(16) index, `join_token_hash` char(64) nullable unik, `state` json, `version` unsigned int default 0, `last_action_at` timestamp nullable, `expires_at` timestamp index, `rematch_of` ulid nullable, timestamps. Index (`game_id`, `status`).
- `match_players`: id, `match_id` (`foreignUlid('match_id')->constrained('matches')->cascadeOnDelete()`), `user_id` fk (cascade), `seat` tinyint, `name` string(20), `hidden_state` text nullable (inte `hidden`: det krockar med Eloquents `$hidden`), `last_seen_at` timestamp nullable, `rematch_requested` bool default false, timestamps. Unika (`match_id`, `seat`) och (`match_id`, `user_id`).
- `match_events`: id, `match_id` (`foreignUlid`, som ovan), `seq` unsigned int, `type` string(32), `seat` tinyint nullable, `payload` json, `created_at`. Unik (`match_id`, `seq`).
- `GameMatch` (`$table = 'matches'`, `HasUlids`): casts `options`/`state` array, `expires_at`/`last_action_at` datetime; relationer (med `match_id` uttryckligen som främmande nyckel) `game()`, `players()` (sorterad på seat), `events()` (sorterad på seq). Konstanter `STATUS_WAITING|ACTIVE|FINISHED|ABANDONED`. Scope `open()` = waiting eller active.
- `GameMatchPlayer`: cast `hidden_state` => `encrypted:array`, `$hidden = ['hidden_state']`.
- `GameMatchEvent`: `$timestamps = false`, `created_at` sätts vid skapande, cast `payload` array.

- [ ] Test `test_hidden_is_encrypted_at_rest`: spara en spelare med `hidden_state = ['secret' => 42]`, läs råvärdet med `DB::table('match_players')->value('hidden_state')`; det är inte giltig JSON, innehåller inte `secret`, `Crypt::decryptString` ger `{"secret":42}`, och modellen ger tillbaka `['secret' => 42]`.
- [ ] Test `test_deleting_match_cascades`: radera en match, spelare och händelser försvinner.
- [ ] Kör, se rött. Skriv migration och modeller. Kör, se grönt.
- [ ] Commit: "Matcher: tabeller och modeller".

## Task 2: Regelkontraktet och registret

**Filer:**
- Skapa: `app/Matches/Rules/MatchRules.php`, `app/Matches/MatchContext.php`, `app/Matches/IllegalMove.php`, `app/Matches/RulesetRegistry.php`, `config/matches.php`
- Test: `tests/Unit/Matches/MatchContextTest.php`, `tests/Unit/Matches/RulesetRegistryTest.php`

**Producerar:**

```php
interface MatchRules
{
    /** @return array normaliserade options; kastar ValidationException */
    public function validateOptions(array $options): array;
    /** publik startstate innan några händelser */
    public function initialState(array $options): array;
    /** ren reducer: publik state + en händelse → ny publik state. Får inte läsa dold state. */
    public function apply(array $state, string $type, ?int $seat, array $payload): array;
    /** när andra spelaren gått med */
    public function start(MatchContext $ctx): void;
    /** ett drag; kastar IllegalMove */
    public function act(MatchContext $ctx, int $seat, string $type, array $payload): void;
    /** vad spelaren själv får se av sin dolda state */
    public function view(?array $hidden): array;
}
```

- `MatchContext` (konstrueras med regelklass, options, publik state, `array<int, ?array>` dold state per säte): `state(): array`, `options(): array`, `hidden(int $seat): ?array`, `setHidden(int $seat, ?array $hidden): void`, `emit(string $type, ?int $seat, array $payload = []): void` (lägger händelsen i en lista och kör `apply` direkt så att `state()` är aktuell), `finish(): void`, `isFinished(): bool`, `emitted(): list<array{type,seat,payload}>`, `hiddenChanges(): array<int, ?array>`. Contexten rör ingen databas.
- Händelsetyperna `left`, `expired`, `rematch_requested` och `rematch` är grundens egna. Tjänsten skriver dem direkt i loggen, de går aldrig genom `apply`, och regelklasser får inte emittera dem (`emit` kastar `LogicException`). Uppspelning hoppar över dem. Konstant `MatchContext::RESERVED_TYPES`.
- `IllegalMove extends RuntimeException` med `public readonly string $errorCode`.
- `config/matches.php`: `['rulesets' => ['reveal' => ['class' => RevealRules::class, 'games' => ['reveal']]]]`.
- `RulesetRegistry::for(Game $game, string $ruleset): MatchRules` (kastar `AuthorizationException` med kod `ruleset_not_allowed` när spelet inte är listat eller rulesetet saknas), `RulesetRegistry::forGame(Game $game): list<string>`.

- [ ] Tester: `emit` uppdaterar `state()` via `apply` och sparar händelsen; `setHidden` syns i `hiddenChanges`; `for` ger regelklassen för `reveal`/`reveal`, kastar för spelet `tocco` och för okänt ruleset; `forGame` för `reveal` ger `['reveal']` och för `tocco` `[]`. Använd en minimal testregelklass i testet för contexten (RevealRules finns inte än); registret testas mot config som testet sätter med `config([...])`.
- [ ] Rött, implementera, grönt. Commit: "Matcher: regelkontrakt och register".

## Task 3: `RevealRules`

**Filer:**
- Skapa: `app/Matches/Rules/RevealRules.php`
- Test: `tests/Unit/Matches/RevealRulesTest.php`

**Konsumerar:** `MatchRules`, `MatchContext`, `IllegalMove` (Task 2).

**Options:** bara `{min: 1, max: 100, best_of: 3}` godtas; tom array normaliseras till det. Allt annat ger `ValidationException`.

**Publik state** (`initialState`): `{round: 0, phase: "choosing", starter: 0, turn: null, locked: [false, false], ready: [false, false], score: [0, 0], last_chance: false, guesses: [[], []], winner: null}`. `guesses[s]` är listan `{value, result}` som säte `s` gissat på motspelarens tal. **Dold state** per säte: `{secret: int|null}`. `view(hidden)` = `{secret: hidden.secret ?? null}`.

**Händelser** (det enda som ändrar publik state, via `apply`):
- `round_begin {round, starter}` → `round`, `starter`, `phase = choosing`, `turn = null`, `locked`/`ready` = `[false,false]`, `guesses = [[],[]]`, `last_chance = false`.
- `locked {}` (seat) → `locked[seat] = true`.
- `duel_begin {}` → `phase = guessing`, `turn = starter`.
- `guess {value, result}` (seat) → lägg till i `guesses[seat]`; `turn = 1 - seat`.
- `last_chance {}` (seat = den som får sista chansen) → `last_chance = true`, `turn = seat`.
- `round_end {winner, secrets: [s0, s1], guesses: [n0, n1]}` → `phase = round_over`, `turn = null`, `score[winner]++` om winner inte är null.
- `ready {}` (seat) → `ready[seat] = true`.
- `match_end {winner}` → `phase = match_over`, `winner`.

`start(ctx)` (anropas bara av tjänsten när andra spelaren gått med): `setHidden` null för båda, emit `round_begin {round: 1, starter: 0}`. Det är inget drag: `act` med `type: "start"` ger `unknown_action`.

**Drag** (`act`); `value` måste vara `is_int` och inom `[min, max]`, annars `invalid_payload` / `out_of_range`:
- `set_secret {value}`: kräver `phase == choosing` (`wrong_phase`) och `!locked[seat]` (`already_locked`). Spara `secret`, emit `locked`. Är båda låsta: emit `duel_begin`.
- `guess {value}`: kräver `phase == guessing` och `turn == seat` (`not_your_turn`). `result` = `correct` om lika, `higher` om motspelarens tal är större än `value`, annars `lower`. Emit `guess`. Sedan:
  - `correct` och `seat != starter` → rundan till `seat`.
  - `correct` och `seat == starter` → emit `last_chance` med seat `1 - seat`.
  - fel och `last_chance` → rundan till `starter`.
  - `correct` under `last_chance` (seat är då inte startspelaren) → oavgjort, `winner = null`. Den regeln går före punkt 1.
- Rundslut: emit `round_end` med båda `secret` och antal gissningar per säte. Matchen är avgjord när någon har 2 vinster, eller när `round == 5` (då flest vinster, lika = `null`). Avgjord: emit `match_end`, `$ctx->finish()`.
- `ready {}`: kräver `phase == round_over` (`wrong_phase`) och `!ready[seat]` (`already_ready`). Emit `ready`. Båda redo: nollställ dold state, emit `round_begin {round: round + 1, starter: 1 - starter}`.
- Annan `type`: `unknown_action`.

- [ ] Tester med contexten (ingen databas), med exakta värden:
  - `test_options_reject_anything_but_default`: `{max: 1000}`, `{best_of: 5}` ger ValidationException; `[]` ger `{min:1,max:100,best_of:3}`.
  - `test_non_integer_values_rejected`: för `set_secret` och `guess` ger `"37"`, `37.0`, `37.5`, `true`, `null`, `[]` `invalid_payload`; `0` och `101` ger `out_of_range`.
  - `test_higher_lower_correct`: säte 1 har låst 42; säte 0 gissar 50 → `lower`, säte 1 gissar sedan, säte 0 gissar 30 → `higher`, ... 42 → `correct`.
  - `test_non_starter_hit_wins_immediately`: startspelaren 0 missar, säte 1 träffar → `round_end {winner: 1}`.
  - `test_starter_hit_gives_last_chance_then_wins`: säte 0 träffar → `last_chance` till 1, säte 1 missar → `round_end {winner: 0}`.
  - `test_last_chance_hit_is_draw`: säte 0 träffar, säte 1 träffar → `round_end {winner: null}`, `score` oförändrad.
  - `test_start_is_not_an_action`: `act(..., 'start', [])` ger `unknown_action` och ändrar ingenting.
  - `test_turn_and_phase_enforced`: gissning i `choosing` ger `wrong_phase`, säte 1 i säte 0:s tur ger `not_your_turn`, andra `set_secret` ger `already_locked`, andra `ready` ger `already_ready`.
  - `test_starter_alternates`: runda 2 har `starter: 1`, runda 3 `starter: 0`.
  - `test_first_to_two_wins_match`: 2–0 ger `match_end {winner}` och `isFinished()`.
  - `test_five_round_cap`: fem oavgjorda ger `match_end {winner: null}`; 1–1 plus tre oavgjorda likaså; 1–0 plus fyra oavgjorda ger `winner: 0`.
  - `test_secret_only_in_round_end`: genom en hel runda finns motspelarens tal bara i `round_end.secrets` bland emitterade payloads, och aldrig i publik state.
  - `test_replay_equals_state`: fäll alla emitterade händelser från `initialState` med `apply` och jämför med `state()`.
- [ ] Rött, implementera, grönt. Commit: "Matcher: Reveals regler".

## Task 4: `MatchService` och vyn

**Filer:**
- Skapa: `app/Matches/MatchService.php`, `app/Matches/MatchView.php`, `app/Matches/MatchError.php`
- Test: `tests/Feature/Matches/MatchServiceTest.php`

**Konsumerar:** modellerna (Task 1), `RulesetRegistry`, `MatchContext` (Task 2).

**Producerar:**
- `MatchError extends RuntimeException` med `errorCode`, `status` (HTTP) och valfri `extra` array. Controllern översätter den och `IllegalMove` (422) till `{message, code, ...extra}`.
- `MatchService`:
  - `create(Game $game, User $user, string $ruleset, array $options, ?string $name): array{match: GameMatch, token: string}`: kontrollerar taket (`too_many_active`, 429; räknar `open()` för användaren i spelet), validerar options och namn, skapar matchen i `waiting` med `state = initialState`, `expires_at = now()+15 min`, säte 0, `version = 1`.
  - `join(Game $game, User $user, string $token, ?string $name): GameMatch`: slår upp hash i transaktion med `lockForUpdate`. Okänd, inte `waiting`, gått ut eller annat spel: `invite_expired` (410). Skaparen själv: `own_match` (409, token oförbrukad). Taket gäller också den som går med. Annars säte 1, `join_token_hash = null`, `status = active`, `expires_at = now()+30 min`, kör `rules->start`, sparar.
  - `act(GameMatch $match, User $user, string $type, array $payload, int $version): GameMatch`: transaktion, `lockForUpdate` på matchen. Inte `active`: `wrong_phase` (422). `version` olika: `stale` (409, `extra = ['version' => $match->version]`). Bygger contexten, kör `rules->act`. Vid IllegalMove: ingenting sparas. Annars: skriv händelser med löpande `seq`, dold state som ändrats, `state`, `version + 1`, `last_action_at`, `expires_at = now()+30 min`; `finish()` ger `status = finished` och nollställer båda spelarnas `hidden_state` (talen har redan avslöjats i `round_end`).
  - `leave(GameMatch $match, User $user): GameMatch`: transaktion med `lockForUpdate`. `waiting`/`active` → `abandoned`, händelsen `left` med sätet, `version + 1`, båda `hidden_state` nollställs. Redan avslutad: oförändrad (idempotent, 200).
  - `rematch(GameMatch $match, User $user): GameMatch`: transaktion med `lockForUpdate`, bara `finished` (`wrong_phase`). Har sätet redan begärt: ingen ändring, ingen ny händelse. Annars markera `rematch_requested`, skriv `rematch_requested` med sätet, `version + 1`. Båda: skapa en ny match (`active`, samma spelare, säten, namn och options, `rematch_of`, ingen token, `rules->start`), skriv `rematch {match_id}` i den gamla och `version + 1` igen. Varje grundhändelse ökar `version`, annars ser pollande klienter den aldrig (304). Gamla matchen är `finished` och räknas inte mot taket. Returnerar den gamla matchen.
  - `touch(GameMatch $match, User $user): void`: sätter `last_seen_at = now()` om den är äldre än 5 s eller saknas.
  - `memberSeat(GameMatch $match, User $user): ?int`.
- `MatchView::for(GameMatch $match, int $seat, ?int $since): array`: exakt `{id, ruleset, status, version, your_seat, players: [{seat, name, connected}], state, you, events: [{seq, type, seat, payload, at}]}`. `you = rules->view(egen hidden)`. `events` = `seq > since`, högst 200 (de senaste om `since` saknas). `connected` = `last_seen_at` inom 10 s. `MatchView::etag(GameMatch $match): string` = `{version}-{c0}{c1}` (utan citattecken; controllern sätter den med `setEtag`) (saknat säte = 0).

- [ ] Tester:
  - `test_create_and_join_starts_reveal`: efter join är status `active`, `state.round == 1`, `state.phase == choosing`, `version == 2`, och skaparens vy har `players` med namnen.
  - `test_invalid_tokens_all_look_the_same`: okänd, redan använd, gammal (`travel(16)->minutes()`) och annat spels token ger samma kod `invite_expired`.
  - `test_own_match`: skaparen går med i sin egen ger `own_match`, och en annan användare kan sedan gå med med samma token.
  - `test_stale_version_has_no_side_effect`: samma `guess` två gånger med samma `version` ger 409 `stale`, och `version`, antalet händelser och `state` är oförändrade efter det andra anropet.
  - `test_illegal_move_has_no_side_effect`: `not_your_turn` lämnar `version`, händelser och `state` orörda.
  - `test_leave_mid_round`: säte 1 lämnar efter att båda låst; status `abandoned`, sista händelsen `left` med seat 1, och ingen händelse eller vy innehåller säte 1:s tal.
  - `test_rematch_creates_new_match_after_both`: spela en match till slut (hjälpmetod som spelar 2–0), ett anrop ger bara `rematch_requested` och ny `version`, samma säte igen ger ingen ny händelse, två säten ger händelsen `rematch {match_id}` och den nya matchen är `active` med samma namn och säten.
  - `test_cap_five_open_matches`: den sjätte `create` ger `too_many_active`; efter att en lämnats går det igen. (Samtidiga `create` kan gå en över taket. Det accepteras; taket är ett skydd mot skräp, inte en säkerhetsgräns.)
  - `test_leave_and_finish_clear_hidden_state`: efter `leave` och efter `match_end` är `hidden_state` null för båda.
  - `test_name_rules`: `"  Anna  "` blir `Anna`, 21 tecken och `"Ann\u0000a"` ger ValidationException, saknat namn ger `Spelare 1`/`Spelare 2`.
  - `test_touch_throttled`: två `touch` inom 5 s ger en skrivning (jämför `last_seen_at`), efter `travel(6)->seconds()` en till.
- [ ] Rött, implementera, grönt. Commit: "Matcher: tjänst, vy och livscykel".

## Task 5: Vägar, gränser och polling

**Filer:**
- Skapa: `app/Http/Controllers/Api/V1/MatchController.php`
- Ändra: `routes/api.php` (ny egen grupp efter att gruppen från rad 124 stängts, runt rad 164; samma middleware och `->withoutMiddleware([SubstituteBindings::class, 'throttle:60,1'])`), `app/Providers/AppServiceProvider.php` (fyra gränser)
- Test: `tests/Feature/Api/V1/MatchApiTest.php`

**Konsumerar:** `MatchService`, `MatchView` (Task 4).

Vägar under `/games/{game}/matches`, med `auth:sanctum`, `TrackLastSeen`, `ResolveGame`, `->withoutMiddleware('throttle:60,1')` och sin gräns:
- `POST /` (`match-create`) → 201 `{match: MatchView, join_token}`.
- `POST /join` (`match-join`) → 200 `MatchView`.
- `GET /active` (`match-poll`) → `{data: [{id, ruleset, status, your_seat}]}` för spelarens `open()` i spelet.
- `GET /{id}` (`match-poll`): 404 om matchen inte finns, hör till ett annat spel eller användaren inte sitter i den. Annars `touch`, sedan `$response->setEtag(MatchView::etag($match))` och `isNotModified($request)` → 304 utan body (klarar svaga `W/`-etaggar efter nginx gzip), annars 200 med `MatchView`. `since` valideras som heltal ≥ 0.
- `POST /{id}/actions` (`match-action`) `{type: required|string|max:32, payload: present|array, version: required|integer|min:0}` → 200 `MatchView` (`since` = det klienten skickade som `since` i frågesträngen, annars alla nya).
- `POST /{id}/leave`, `POST /{id}/rematch` (`match-action`) → 200 `MatchView`.

`/{id}` begränsas med `->whereUlid('id')` (`HasUlids` ger gemener) så att `active` och `join` inte krockar. Controllern översätter `MatchError`, `IllegalMove` och `AuthorizationException` från registret till JSON enligt de globala villkoren. En 429 från gränserna på matchvägarna får `code: "rate_limited"` (render-hook i `bootstrap/app.php` för `ThrottleRequestsException` när sökvägen matchar `api/v1/games/*/matches*`), så att klienten kan skilja den från `too_many_active`.

- [ ] Tester:
  - Hela flödet över HTTP med två `Sanctum::actingAs`-användare: skapa, gå med, lås, gissa till rundslut.
  - `test_poll_304_until_change`: GET med `If-None-Match` lika med ETag ger 304; efter motspelarens drag 200 med ny ETag.
  - `test_etag_changes_when_opponent_disconnects`: ETag med motspelaren ansluten, `travel(11)->seconds()`, ny poll ger annan ETag och `connected: false`.
  - `test_global_limit_removed_and_poll_limit_applies`: 100 GET inom en minut ger inte 429; den 121:a ger 429.
  - `test_poll_accepts_weak_etag`: `If-None-Match: W/"<etag>"` ger 304.
  - `test_error_shapes`: 429 från gränsen har `code: rate_limited`; 409 `stale` har `version`; 422 `invalid_payload`; 403 `ruleset_not_allowed` för `tocco`; 410 `invite_expired`.
  - `test_since_filters_events`.
- [ ] Rött, implementera, grönt. Commit: "Matcher: API-vägar och polling".

## Task 6: Städning

**Filer:**
- Skapa: `app/Console/Commands/ExpireMatches.php` (`matches:expire`), `app/Console/Commands/PruneMatches.php` (`matches:prune {--days=7}`)
- Ändra: `routes/console.php` (`matches:expire` var femte minut `->withoutOverlapping(5)`, `matches:prune --days=7` dagligen 05:00)
- Test: `tests/Feature/Matches/MatchCleanupTest.php`

`matches:expire`: för varje kandidat (`open()` med `expires_at < now()`), en transaktion med `lockForUpdate` som prövar villkoret igen innan den sätter `abandoned`, `version + 1`, händelse `expired` och nollställer `hidden_state`. Ett drag som just flyttat `expires_at` vinner alltså. `matches:prune`: raderar `finished`/`abandoned` vars `updated_at` är äldre än `--days` (radering på id ur samma fråga, i omgångar om 500). Båda skriver ut antal.

- [ ] Tester: väntande match blir `abandoned` efter 16 min men inte efter 14; aktiv efter 31 min utan drag; ett drag flyttar gränsen; `prune` raderar en 8 dagar gammal avslutad men inte en 6 dagar gammal eller en aktiv; `--days=0` raderar inget aktivt.
- [ ] Rött, implementera, grönt. Commit: "Matcher: utgång och gallring".

## Task 7: Invarianttester över hela matcher

**Filer:**
- Test: `tests/Feature/Matches/MatchInvariantTest.php`

- [ ] `test_no_hidden_state_leaks`: spela en hel match över HTTP (2–1 med en oavgjord runda). Efter varje svar till varje säte: JSON-trädet har ingen nyckel `hidden_state` eller `hidden`, och `you` innehåller bara det egna talet. Kontrollera motspelarens aktuella tal **bara under värdenycklar**: före rundans `round_end` får det förekomma i `state.guesses[*][*].value` och `events[*].payload.value` endast om det redan gissats, och i `events[*].payload.secrets` endast i `round_end`. Inget annat fält får heller bära det dolda värdet; testet listar fältnamn, inte siffror, så `seq`, `version`, `round` och id kan inte ge falsklarm.
- [ ] `test_event_log_replays_to_state`: efter matchen ovan ger `apply` över alla händelser i `seq`-ordning samma `state` som den lagrade, och `seq` är 1..n utan luckor.
- [ ] `test_access`: en tredje användare får 404 på `GET /{id}`, `actions`, `leave` och `rematch`; samma match-id under en annan spelslug ger 404; ett gallrat id ger 404 med samma body.
- [ ] Grönt (eller fixa i Task 3–5). Commit: "Matcher: invarianttester".

## Task 8: Integrationsmanualen och get_game

**Filer:**
- Ändra: `app/Services/IntegrationGuide.php` (`FEATURES` får `'matches' => 'Turn-based Matches'`; `defaultFeatures` tar med `matches` när `RulesetRegistry::forGame` inte är tom; ett avsnitt i `manual()`), `app/Mcp/Tools/GetGameTool.php` (fältet `match_rulesets`)
- Test: befintliga `IntegrationGuideTest` och testet för `get_game` (sök `GetGameTool` i `tests/Feature/Mcp`)

Avsnittet beskriver vägarna, svarsformen, ETag/304, pollintervall 1,5 s och paus när fliken är dold, `version`/409, felkoderna, livscykeln och att spelet måste visa ett synligt läge vid tappad kontakt. Avsnittet säger också att spel byggda från byggguiden använder klientmodulen `matches` (v3) och aldrig anropar vägarna direkt.

- [ ] Tester: `reveal` får `matches` som standardfunktion, `tocco` inte; manualen för `reveal` innehåller `POST /games/reveal/matches` och `If-None-Match`; `get_game` för `reveal` har `match_rulesets: ["reveal"]` och för `tocco` `[]`. MCP-invarianttesterna (enum i `get_integration_guide`) är fortfarande gröna.
- [ ] Rött, implementera, grönt. Kör hela sviten: `php artisan test`. Commit: "Matcher: integrationsmanual och get_game".

## Avslut

- [ ] Hela sviten grön, `./vendor/bin/pint --test` om projektet använder pint (kontrollera `composer.json`).
- [ ] completion-gate. Deploy och `php artisan migrate` i produktion görs bara på Eriks order.
