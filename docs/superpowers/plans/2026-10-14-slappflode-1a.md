# Delprojekt 4, etapp 1a: släppflödets modell, spärrar och godkännande — implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Computercat vet för varje spel vilka produktionsmål det har, vilken commit som har ett lyckat Demo-bygge, vilka släpp som begärts och vilka engångssteg som är öppna. `promote_release` skapar ett släpp bara om alla nio spärrar släpper igenom det, första produktionssläppet per spel och mål godkänns av en platform admin i Filament, och varje svar säger vad spelets Claude ska göra härnäst. Inget byggs eller deployas ännu.

**Architecture:** Fyra nya tabeller (`releases`, `demo_builds`, `release_setup_steps` plus kolumner på `games`) och tunna Eloquent-modeller. Reglerna bor i `App\Releases` (`ReleaseGate` för spärrarna, `ReleaseService` för transaktionen med lås, godkännande och TTL, `ReleaseNext` för alla `next`-texter, `ReleaseStatus` för statussvaret). Tre MCP-verktyg (`GameTool`) använder dem; `request_live` blir en hänvisning. Demo-kvitton skrivs av `DemoBuildRecorder` från `get_provisioning_status`, ett schemalagt kommando var femte minut och nattkontrollen. Godkännande sker bara på en Filament-sida under `GameResource`.

**Tech Stack:** Laravel 12, PHP 8.4, laravel/mcp 1.0.2, Filament 3.3 (Livewire), PHPUnit 11 (klasser, inte Pest), SQLite i minnet för test, MySQL i prod.

**Spec:** `docs/superpowers/specs/2026-10-14-slappflode-design.md` (Del 1, Del 3 "MCP-verktyg", "Godkännande", "Filament", "Bevakning", "Dokumentation", tabellen "Etapper" rad 1a). Läs den före varje task. Bilagan `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` får ett nytt avsnitt i Task 1. Spec och plan ligger på grenen `docs/slapp-spec` (worktree `/Users/erik/Web/computercat-api/.claude/worktrees/docs-slapp-spec`) tills den är mergad; läs dem därifrån.

**Beroenden:** Delprojekt 2 (provisionering) är live och är grunden. Faktabanken (`feat/faktabank`, delprojekt 3 etapp A) är inte mergad; Task 11 är skriven så att den fungerar oavsett i vilken ordning grenarna mergas.

---

## Global Constraints

**Arbetssätt**
- Implementationen görs i en ny worktree: `~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/slappflode-1a --db` (grenen har migrationer, därför egen databas). Sista raden på stdout är sökvägen, nedan `$WT`. Absoluta sökvägar, huvudkatalogen rörs aldrig, `EnterWorktree` används inte.
- Testkommandon körs som `cd $WT && php artisan test --filter=<Klass>`. Hela sviten körs i Task 12.
- Commit-meddelanden på svenska, utan Co-Authored-By-rader. Kommentarer och artisan-utskrifter på svenska; MCP-verktygens svar och felmeddelanden på engelska, utom `next` och `instruction` som är på svenska (Ruling R5).
- Ingen åtgärd mot Coolify, Forge, Slack eller prod utan Eriks uttryckliga OK via AskUserQuestion, med undantag för det som Erik godkänner i Task 1. Deploy av etapp 1a till prod ingår inte i planen och kräver eget OK (migrationerna körs på prod, backfillen i Task 8 skriver till `games`).
- `security-first-mindset` invokeras i början av Task 4 (hotmodell för spärrarna) och i Task 12 (review gate). `completion-gate` passeras i Task 12 innan något kallas klart.
- Etapp 1a är inte en "stor lansering" i CLAUDE.md:s mening ännu (inget går live, ingen kund informeras), men första gången migrationerna och backfillen körs på prod ska `release_targets` och `release_setup_steps` kontrolleras mot `mcp_audit_logs` (Task 8, steg 6).

**Eriks beslut och spec-värden (ordagrant)**
- Produktionsmål: `web`, `app_store`, `play_store`. Steg: `web` har `demo` och `web_production`; `app_store` har `testflight` och `app_store`; `play_store` har `play_internal` och `play_production`. Demo är gemensamt första steg.
- `releases.status`: `requested`, `awaiting_approval`, `building`, `processing`, `succeeded`, `failed`, `cancelled`. Pågående status (har `active_key`): `requested`, `awaiting_approval`, `building`, `processing`.
- Unikt index på `(game_id, target, stage, active_key)`; `active_key` är satt för pågående status och null annars. Befordran sker i en transaktion med `lockForUpdate` på spelets rad; "första släppet" beräknas i samma transaktion.
- Spärr 3: första produktionssläppet per spel och mål (ingen rad med status `succeeded` för spel, mål och produktionssteg) får status `awaiting_approval` och godkänns av en platform admin i Filament, aldrig via MCP. Godkänner Erik ett släpp han själv begärt loggas det som `self_approved`. Därefter får spelets ägare (`GameAccess::isOwner`) befordra till produktion; Erik får ett Slack-besked.
- Spärr 7: spärr 1 och 2 kontrolleras igen när ett släpp godkänns och när bygget startas. Ett släpp som stått i `awaiting_approval` i 7 dagar blir `cancelled`.
- Spärr 8: produktionssteg kräver uttrycklig `commit_sha`; standardvalet (senaste commit med lyckat Demo-bygge) gäller bara teststeg.
- Spärr 9: brytare `releases_enabled` per spel och globalt (konfig). Avslagen brytare stoppar nya befordringar och nya byggstarter.
- Verktyg: `get_release_status(game)` (medlem), `promote_release(game, target, stage, commit_sha?)` (ägare), `set_release_targets(game, targets)` (platform admin). Alla ärver `GameTool`, är revisionsloggade och klarar `ToolInvariantsTest` (förbjudna ord i namn: `delete|remove|destroy|product|purchase|user|player|settings|token`).
- Godkännande via Slack-knappar är utanför scope. Slack-meddelandet länkar till Filament.
- Kvittot i `demo_builds` (spel, commit, deployment-uuid, tid) skrivs av `get_provisioning_status`, ett pollningsjobb var femte minut och nattkontrollen.
- Engångssteg per mål (nycklar och text i Task 2): `app_store`: bundle-id (automatiskt via API, etapp 2), skapa appen (Erik), integritetsuppgifter och åldersgräns (Erik). `play_store`: skapa appen, innehållsklassning/dataskydd/målgrupp/butikstext, Play App Signing. `web`: Erik sätter den publika adressen.
- Bundle-id och paketnamn: `co.humblebrag.<slug-utan-bindestreck>`, sparas på spelet med unikt index; krockar två slugs vägrar `set_release_targets`.

**Rulings i denna plan (Erik ska känna till dem)**
- **R1: etapp 1a bygger inget.** `promote_release` för `web_production`, `testflight`, `app_store`, `play_internal` och `play_production` skapar släppraden och stannar i `requested` (efter godkännande, om ett krävdes) eller `awaiting_approval`. `next` säger uttryckligen att bygget kommer i etapp 1b (web), 2 (iOS) eller 3 (Android). Ingen kod i 1a startar GitHub Actions, Coolify-`deploy` eller App Store/Play-anrop. `requested` och `awaiting_approval` som ingen rör inom 7 dagar blir `cancelled` (samma TTL, så att ett släpp utan byggsteg inte blockerar sin plats för alltid).
- **R2: alla befordringar kräver ägare** (`GameAccess::isOwner`), inte bara produktionssteg. Medlemmar läser status.
- **R3: `request_live` skrev aldrig något till databasen** (bara Slack med dygnsdeduplicering i cachen). Det enda beständiga spåret är `mcp_audit_logs` (rader med `tool = request_live`, `outcome = ok`, `game_id`). Backfillen sätter `release_targets` till `["web"]` (sammanslaget med befintliga mål) för exakt de spelen och skapar det öppna engångssteget `web_public_address`. Följd: backfillen ger ingen produktionsrätt; spelet kan inte befordras förrän Erik satt adressen, och första produktionssläppet kräver hans godkännande ändå. Finns inga sådana loggrader händer ingenting.
- **R4: brytaren.** Globalt: miljövariabeln `RELEASES_ENABLED` (läses via `config('releases.enabled')`; allt utom ett sant värde stänger, så en felstavning stänger i stället för att öppna). Att slå av kräver `.env`-ändring och `config:cache` på prod men ingen kod-deploy. Per spel: kolumnen `games.releases_enabled`, som platform admin växlar på Filament-sidan utan deploy.
- **R5: språk.** `next`-texter och engångsstegens `instruction` är på svenska (specens exempel, och samma språk som faktabankens artiklar som testet jämför dem mot). Övriga verktygssvar och fel är engelska.
- **R6: `targets` är en kommaseparerad sträng** (`"web,app_store"`, tom sträng = inga mål), inte en lista, eftersom `AuditLogger::summarize` bara loggar "[list: N items]" för listor och revisionsloggen då inte skulle visa vilka mål som sattes.
- **R7: bundle-id-kolumnen (`games.bundle_id`, unik) skapas i 1a** och sätts av `set_release_targets` när `app_store` eller `play_store` väljs. Formatet bekräftas i etapp 2; ändras det då, ändras bara en funktion (`SetReleaseTargetsTool::bundleIdFor`). Krocktest mot andra spels sparade id, inte mot hypotetiska id.
- **R8: Coolifys `commit`.** Spiken (Task 1) ska bekräfta att `commit` i bygglistan är ett fullständigt 40-tecken-SHA för webhook-byggen. Koden i Task 3 förutsätter det: ett kvitto skrivs bara för `status = finished` med ett `commit` som matchar `^[0-9a-f]{40}$` (efter `strtolower`); annat (`HEAD`, kort hash, `null`) ignoreras och loggas en gång. Visar spiken att webhook-byggen har kort hash eller `HEAD`: stanna och fråga Erik.
- **R9: det som inte byggs i 1a** (och varför): kontrollen att produktionswebben serverar den släppta commiten (ingen produktionsweb finns, etapp 1b), "vilken version av släppflödet som kördes" i revisionsloggen (inget flöde körs, etapp 2/3), `CLAUDE.md` i ny byggguideversion (delprojekt 3 etapp B), och engångssteget `play_first_aab` (skapas först om etapp 3:s spike bekräftar kravet).
- **R10: Filament-sidan är bara för platform admin** (inte alla staff). Spelets medlemmar läser samma information via `get_release_status`.
- **R11: `get_release_status` gör inga externa anrop.** Den läser kvittona i databasen, som kan vara upp till fem minuter gamla (pollningen) eller färskare om `get_provisioning_status` anropats.

**Värden**
- Commit-format: `^[0-9a-f]{40}\z` (gemener). `promote_release` avvisar versaler, kortare och längre värden och `HEAD`.
- TTL: `config('releases.ttl_days')` = 7 (både `awaiting_approval` och `requested`, mätt på `updated_at`). Fastnat bygge: `config('releases.stuck_minutes')` = 120. Påminnelse om öppet engångssteg: `config('releases.setup_reminder_days')` = 7. Pollens sidstorlek: `config('releases.demo_poll_take')` = 20.
- Pollning: `computercat:poll-demo-builds` var femte minut (`*/5 * * * *`), `withoutOverlapping(10)`. Nattkontrollen är den befintliga `computercat:check-provisioning` (06:15).
- Inget HTTP-anrop sker inne i en databastransaktion (Slack skickas efter commit); varje testklass som kan nå HTTP använder `AssertsNoHttpInTransaction`.

---

## Review Focus

De fem fel som specen innebär men som lätt passerar oupptäckta. Varje rad har ett test i den task som äger koden.

1. **Två samtidiga befordringar, eller ett nytt släpp medan ett står i kö.** Utan både unikt index och lås kan två `promote_release` skapa två pågående släpp för samma plats, och ett släpp som aldrig byggs (1a) blockerar platsen för alltid. Test: `ReleaseModelTest::test_second_active_release_for_same_slot_violates_unique_index` (Task 2), `ReleaseServiceTest::test_second_promote_while_one_is_pending_is_refused` och `test_stale_pending_release_is_cancelled_and_frees_the_slot` (Task 5).
2. **"Första produktionssläppet" räknas fel.** Ett tidigare misslyckat, avbrutet eller avslaget produktionssläpp får inte göra nästa släpp "icke-första", och ett lyckat får inte ignoreras. Test: `ReleaseServiceTest::test_failed_or_cancelled_production_release_does_not_end_the_approval_requirement` (Task 5).
3. **Fel commit-format och kvitton som inte matchar.** 39 eller 41 tecken, versaler, `HEAD`, en commit som bara finns i ett annat spels kvitto, och ett kvitto som försvann mellan begäran och godkännande. Test: `ReleaseGateTest::test_commit_must_be_a_full_lowercase_sha`, `test_receipt_of_another_game_does_not_count` (Task 4), `ReleaseServiceTest::test_approval_rechecks_the_receipt` och `test_start_rechecks_everything` (Task 5).
4. **Brytaren stängs mitt i flödet, eller spelet arkiveras/ändras medan ett släpp väntar.** Brytaren ska stoppa godkännande och byggstart, inte bara nya begäran; ett arkiverat spel (`is_active = false`) och ett mål som tagits bort får inte släppas fram. Test: `ReleaseGateTest::test_switch_off_blocks_everything` och `test_archived_game_is_refused` (Task 4), `SetReleaseTargetsToolTest::test_removing_a_target_with_a_pending_release_is_refused` (Task 7).
5. **Godkännande av fel person eller efter TTL.** En icke-admin ska aldrig kunna godkänna (varken via MCP, som saknar vägen, eller Filament-anrop), godkännande efter 7 dagar ska avbryta släppet, och `self_approved` ska sättas exakt när godkännaren är den som begärde. Test: `ReleaseServiceTest::test_only_platform_admin_can_approve`, `test_approval_after_ttl_cancels_the_release`, `test_self_approval_is_flagged` (Task 5), `GameReleasesPageTest::test_non_admin_staff_gets_403` (Task 9).

Dessutom pinnas två gränsfall i sina tasks: ett `commit` från Coolify som inte är 40 hex skapar inget kvitto (Task 3), och backfillen rör bara spel med en `ok`-rad för `request_live` och går att köra två gånger (Task 8).

---

## Filstruktur

| Fil | Ansvar |
|---|---|
| `config/releases.php` | brytare och tidsgränser |
| `database/migrations/2026_10_16_10000{1..5}_*.php` | `games`-kolumner, `releases`, `demo_builds`, `release_setup_steps`, backfill |
| `app/Models/Release.php`, `DemoBuild.php`, `ReleaseSetupStep.php` | modeller (status-konstanter, `active_key`-krok, TTL, redigering av `last_error`) |
| `app/Models/Game.php` (ändras) | casts, relationer, `releaseTargets()` |
| `app/Releases/ReleaseCatalog.php` | mål, steg, etapper, commit-format (ren data) |
| `app/Releases/SetupSteps.php` | engångsstegens definitioner och synkning |
| `app/Releases/ReleaseSwitch.php` | brytaren |
| `app/Releases/ReleaseGate.php` | spärrarna 1, 2, 5, 6, 8, 9 (+ arkiverat spel) |
| `app/Releases/ReleaseRefused.php` | undantag med engelskt meddelande |
| `app/Releases/ReleaseService.php` | `promote`, `approve`, `reject`, `assertCanStart` |
| `app/Releases/ReleaseNotifier.php` | Slack-meddelanden |
| `app/Releases/ReleaseNext.php` | alla `next`-texter |
| `app/Releases/ReleaseStatus.php` | status per spel och mål |
| `app/Releases/DemoBuildRecorder.php` | skriver kvitton ur Coolifys bygglista |
| `app/Provisioning/Clients/CoolifyClient.php` (ändras) | `recentDeployments()` med `commit` |
| `app/Console/Commands/PollDemoBuilds.php`, `routes/console.php` | femminuterspollen |
| `app/Console/Commands/CheckProvisioning.php` (ändras) | kvitton och släppbevakning i nattkontrollen |
| `app/Mcp/Tools/GetReleaseStatusTool.php`, `PromoteReleaseTool.php`, `SetReleaseTargetsTool.php` | verktygen |
| `app/Mcp/Tools/RequestLiveTool.php` (skrivs om), `app/Mcp/ComputercatServer.php` (ändras) | hänvisning och registrering |
| `app/Filament/Resources/GameResource/Pages/GameReleases.php`, vy `game-releases.blade.php` | godkännande och engångssteg |
| `resources/faktabank/process/process-slappflode.md`, `process-slapp-engangssteg.md` | artiklar |
| `tests/Support/CreatesReleases.php` | testhjälpare |

---

### Task 1: Coolify-spike — hur `commit` står i bygglistan

**Files:**
- Modify: `docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md` (nytt avsnitt "Spikresultat etapp 1a")
- Create: `$WT` (worktree) och scratch-skript under `$SCRATCH` (inte i repot)

**Interfaces:**
- Produces: bekräftade fakta S26–S29 i bilagan som Task 3 bygger på: (S26) formatet på `commit`, (S27) största `take` som API:t accepterar och att `take=20` fungerar, (S28) fältnamnen i en bygglistepost, (S29) hur långt tillbaka byggen syns.

Allt i spiken är **läsning** (HTTP GET mot Coolify från Forge-servern, `SELECT` i Coolifys databas). Inga byggen startas, inget ändras. Hemligheter skrivs aldrig ut: skriptet skriver bara nyckelnamn, status, uuid, tider och `commit`, aldrig `logs` och aldrig token.

- [ ] **Steg 1: Skapa worktreen.**

```bash
~/.claude/bin/wt-start /Users/erik/Web/computercat-api feat/slappflode-1a --db
```

Spara sista raden som `$WT`. Skapa scratch-katalogen: `SCRATCH=/private/tmp/claude-501/-Users-erik-Web-computercat-api/5b8f3b1f-f19f-42b2-81b3-fb8d3e22fb44/scratchpad/s1a && mkdir -p $SCRATCH`.

- [ ] **Steg 2: Fråga Erik (AskUserQuestion) innan något körs mot prod.** Texten: "Spiken för släppflödet läser Coolifys bygglista för Kattsvep (GET /deployments/applications/{uuid} med take 20, 100 och 1000, via Forge-servern med Computercats Coolify-token, utan att skriva ut token eller loggar) och kör en SELECT mot Coolifys databas på apps.humblebrag.se (gruppering av application_deployment_queues på status och längd på commit, plus äldsta och nyaste rad). Inget startas, inget ändras. OK?" Utan OK: stanna.

- [ ] **Steg 3: Läs bygglistan via API:t.** Skriv skriptet till `$SCRATCH/s26.php` (tinker-uttryck utan `<?php`):

```php
$uuid = App\Models\ProvisioningRun::provision()->where('slug', 'kattsvep')->value('coolify_app_uuid');
$base = rtrim(config('provisioning.coolify.url'), '/').'/api/v1/deployments/applications/'.$uuid;
foreach ([20, 100, 1000] as $take) {
    $r = Illuminate\Support\Facades\Http::withToken(config('provisioning.coolify.token'))->acceptJson()->get($base, ['skip' => 0, 'take' => $take]);
    echo "take={$take} status=".$r->status().' count='.$r->json('count').' listed='.count($r->json('deployments') ?? [])."\n";
}
$r = Illuminate\Support\Facades\Http::withToken(config('provisioning.coolify.token'))->acceptJson()->get($base, ['skip' => 0, 'take' => 20]);
echo 'fields='.implode(',', array_keys($r->json('deployments.0') ?? []))."\n";
foreach ($r->json('deployments') ?? [] as $d) {
    $c = $d['commit'] ?? null;
    echo $d['deployment_uuid'].' '.$d['status'].' '.$d['created_at'].' commit='.var_export($c, true).' len='.strlen((string) $c).' hex40='.var_export(is_string($c) && preg_match('/^[0-9a-f]{40}$/', $c) === 1, true)."\n";
}
```

Kör (skriptet skickas som base64 så att citattecken inte krockar):

```bash
B64=$(base64 < $SCRATCH/s26.php | tr -d '\n')
~/.claude/bin/ssh-claude --via-home forge@46.246.48.182 "cd /home/computercat/api.computercat.co/current && echo $B64 | base64 -d | sudo -n -u computercat php8.4 artisan tinker --execute=\"\$(cat)\""
```

Förväntat: tre rader `take=… status=200 …`, en rad `fields=…` som innehåller `deployment_uuid`, `status`, `created_at` och `commit`, och en rad per bygge. Anteckna: (S27) vilka `take` som gav 200 och hur många poster som kom (kräver `take=20` att ge 200; ger `take=1000` 4xx eller en tyst avkortning, anteckna gränsen), (S28) fältlistan, (S26) om alla `finished`-byggen har `hex40=true`.

- [ ] **Steg 4: Läs retention och commit-format i Coolifys databas (SELECT).**

```bash
~/.claude/bin/ssh-claude --via-home erik@apps.humblebrag.se "sudo -n docker exec coolify-db psql -U coolify -d coolify -c \"select status, length(commit) as len, (commit = 'HEAD') as is_head, count(*) as n, min(created_at) as first, max(created_at) as last from application_deployment_queues group by 1,2,3 order by n desc;\""
```

Förväntat: `finished`-rader med `len = 40`. Anteckna (S29) äldsta `first` mot dagens datum, alltså hur långt tillbaka byggen finns, och om rader med `is_head = t` eller andra `len` förekommer (manuella omstarter, förhandsvisningar).

- [ ] **Steg 5: Avgör.** Alla `finished`-byggen som kom från push (webhook) har `len = 40` och är gemener: gå vidare (R8 gäller som den står). Förekommer `HEAD`, kort hash eller `null` på `finished`-rader som kommer från pushar: **stanna och fråga Erik** (AskUserQuestion) innan Task 3 skrivs; spärr 1 kan då inte jämföra mot `commit_sha` utan ett annat sätt att få fram SHA (till exempel GitHubs API, vilket är ett eget beslut).

- [ ] **Steg 6: Skriv resultaten i bilagan.** Lägg till sist i `$WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md`, under rubriken `## Spikresultat etapp 1a (2026-10-14, Coolify v4.4.6)`, fyra punkter i samma stil som S1–S25:

```markdown
## Spikresultat etapp 1a (2026-10-14, Coolify v4.4.6)

Läsning från Forge-servern med prodtoken (read+write) och SELECT i Coolifys databas. Inget startades.

- S26: `commit` i `GET /deployments/applications/{uuid}` är <fyll i verkligt utfall, t.ex. "ett fullständigt 40-tecken-SHA i gemener för varje bygge som kom från en push; manuella omstarter har <värde>">.
- S27: `take=20` ger 200 med <N> poster; `take=100` ger <utfall>; `take=1000` ger <utfall>.
- S28: fälten i en post är: <ordagrann fältlista från steg 3>.
- S29: äldsta bygget i databasen är från <datum> (<N> dagar), totalt <N> rader; <anteckning om gallring om någon syns>.
```

Ersätt varje `<…>` med det uppmätta värdet (inga hakparenteser får stå kvar) och kontrollera: `grep -n '<' $WT/docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md | grep 'S2[6-9]'` ska ge noll rader.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add docs/superpowers/specs/bilagor/2026-10-13-coolify-api.md && git commit -m "Spike: hur Coolifys bygglista visar commit (S26-S29)"
```

---

### Task 2: Datamodellen — tabeller, modeller, engångssteg

**Files:**
- Create: `config/releases.php`
- Create: `database/migrations/2026_10_16_100001_add_release_columns_to_games_table.php`, `2026_10_16_100002_create_releases_table.php`, `2026_10_16_100003_create_demo_builds_table.php`, `2026_10_16_100004_create_release_setup_steps_table.php`
- Create: `app/Models/Release.php`, `app/Models/DemoBuild.php`, `app/Models/ReleaseSetupStep.php`
- Create: `app/Releases/ReleaseCatalog.php`, `app/Releases/SetupSteps.php`, `app/Releases/ReleaseSwitch.php`
- Modify: `app/Models/Game.php`
- Create: `tests/Support/CreatesReleases.php`
- Test: `tests/Feature/Releases/ReleaseModelTest.php`

**Interfaces:**
- Produces:
  - `config('releases.enabled'|'ttl_days'|'stuck_minutes'|'setup_reminder_days'|'demo_poll_take')`.
  - `Game::releaseTargets(): array<int,string>`, `Game::releases(): HasMany`, `Game::setupSteps(): HasMany`, `Game::demoBuilds(): HasMany`; kolumnerna `release_targets` (array-cast), `releases_enabled` (bool), `bundle_id` (?string).
  - `Release` med konstanterna `REQUESTED`, `AWAITING_APPROVAL`, `BUILDING`, `PROCESSING`, `SUCCEEDED`, `FAILED`, `CANCELLED`, `ACTIVE` (lista), `isActive(): bool`, `scopeActive()`, `static expireStale(): Illuminate\Database\Eloquent\Collection` (avbryter och returnerar släpp som stått för länge), relationerna `game()`, `requester()`, `approver()`.
  - `DemoBuild` med `static latestFor(Game $game): ?DemoBuild`.
  - `ReleaseSetupStep` med `OPEN = 'open'`, `DONE = 'done'`, relationerna `game()`, `doneBy()`.
  - `ReleaseCatalog::TARGETS`, `::PROMOTABLE_STAGES`, `::STAGES`, `::BUILD_ETAPP`, `::testStage(string $target): string`, `::productionStage(string $target): string`, `::isPromotable(string $target, string $stage): bool`, `::isProduction(string $target, string $stage): bool`, `::isCommit(string $value): bool`.
  - `SetupSteps::DEFINITIONS` (target => key => instruction), `SetupSteps::sync(Game $game): void`.
  - `ReleaseSwitch::disabledReason(Game $game): ?string`.
  - Testhjälparen `Tests\Support\CreatesReleases`: `sha(string $char = 'a'): string`, `demoBuild(Game, ?string $sha = null, $deployedAt = null): DemoBuild`, `release(Game, array $attrs = []): Release`, `releasableGame(string $slug = 'kattsvep', array $targets = ['web']): Game`, `ownerOf(Game): User`, `adminUser(): User`.

- [ ] **Steg 1: Skriv testerna.** Skapa `tests/Support/CreatesReleases.php`:

```php
<?php

namespace Tests\Support;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\SetupSteps;
use Illuminate\Support\Str;

// Gemensamma byggstenar för släpptesterna.
trait CreatesReleases
{
    protected function sha(string $char = 'a'): string
    {
        return str_repeat($char, 40);
    }

    protected function demoBuild(Game $game, ?string $sha = null, $deployedAt = null): DemoBuild
    {
        return DemoBuild::create([
            'game_id' => $game->id,
            'commit_sha' => $sha ?? $this->sha(),
            'deployment_uuid' => (string) Str::uuid(),
            'deployed_at' => $deployedAt ?? now(),
        ]);
    }

    protected function release(Game $game, array $attrs = []): Release
    {
        return Release::create(array_merge([
            'game_id' => $game->id,
            'target' => 'web',
            'stage' => 'web_production',
            'commit_sha' => $this->sha(),
            'status' => Release::REQUESTED,
        ], $attrs));
    }

    /** Aktivt spel med mål och med alla engångssteg satta som klara. */
    protected function releasableGame(string $slug = 'kattsvep', array $targets = ['web']): Game
    {
        $game = Game::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_active' => true, 'settings' => []]);
        $game->forceFill(['release_targets' => $targets, 'is_active' => true, 'releases_enabled' => true])->save();
        SetupSteps::sync($game);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => ReleaseSetupStep::DONE]);

        return $game->fresh();
    }

    protected function ownerOf(Game $game): User
    {
        $user = User::factory()->staff()->create();
        $game->members()->attach($user, ['role' => 'owner']);

        return $user;
    }

    protected function adminUser(): User
    {
        return User::factory()->staff(true)->create();
    }
}
```

Skapa `tests/Feature/Releases/ReleaseModelTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseCatalog;
use App\Releases\SetupSteps;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseModelTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []]);
    }

    public function test_new_games_default_to_no_targets_and_releases_on(): void
    {
        $fresh = $this->game->fresh();

        $this->assertSame([], $fresh->releaseTargets());
        $this->assertTrue($fresh->releases_enabled);
        $this->assertNull($fresh->bundle_id);
    }

    public function test_active_key_follows_status(): void
    {
        $release = $this->release($this->game);
        $this->assertSame('active', $release->active_key);

        $release->update(['status' => Release::SUCCEEDED]);
        $this->assertNull($release->fresh()->active_key);

        $release->update(['status' => Release::BUILDING]);
        $this->assertSame('active', $release->fresh()->active_key);
    }

    public function test_second_active_release_for_same_slot_violates_unique_index(): void
    {
        $this->release($this->game);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->release($this->game, ['status' => Release::AWAITING_APPROVAL]);
    }

    public function test_finished_releases_and_other_slots_do_not_conflict(): void
    {
        $this->release($this->game, ['status' => Release::SUCCEEDED]);
        $this->release($this->game, ['status' => Release::FAILED]);
        $this->release($this->game, ['status' => Release::CANCELLED]);
        $this->release($this->game);
        $this->release($this->game, ['stage' => 'testflight', 'target' => 'app_store']);

        $this->assertSame(5, Release::count());
    }

    public function test_last_error_is_redacted_on_save(): void
    {
        $release = $this->release($this->game, ['last_error' => 'upload failed ghp_'.str_repeat('a', 36)]);

        $this->assertStringNotContainsString('ghp_', $release->fresh()->last_error);
        $this->assertStringContainsString('[REDACTED]', $release->fresh()->last_error);
    }

    public function test_uuid_is_generated(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->release($this->game)->uuid);
    }

    public function test_demo_build_is_unique_per_game_and_deployment(): void
    {
        DemoBuild::create(['game_id' => $this->game->id, 'commit_sha' => $this->sha(), 'deployment_uuid' => 'dep-1', 'deployed_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);
        DemoBuild::create(['game_id' => $this->game->id, 'commit_sha' => $this->sha('b'), 'deployment_uuid' => 'dep-1', 'deployed_at' => now()]);
    }

    public function test_latest_demo_build_is_the_newest_by_time(): void
    {
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $newest = $this->demoBuild($this->game, $this->sha('b'), now());
        $this->demoBuild($this->game, $this->sha('c'), now()->subDay());

        $this->assertSame($newest->id, DemoBuild::latestFor($this->game)->id);
        $this->assertNull(DemoBuild::latestFor(Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true, 'settings' => []])));
    }

    public function test_sync_creates_steps_for_every_target_and_is_idempotent_and_keeps_done_steps(): void
    {
        $this->game->forceFill(['release_targets' => ['web', 'app_store', 'play_store']])->save();

        SetupSteps::sync($this->game);
        ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'web_public_address')->update(['status' => ReleaseSetupStep::DONE]);
        SetupSteps::sync($this->game);

        $expected = array_sum(array_map('count', SetupSteps::DEFINITIONS));
        $this->assertSame($expected, ReleaseSetupStep::where('game_id', $this->game->id)->count());
        $this->assertSame(ReleaseSetupStep::DONE, ReleaseSetupStep::where('key', 'web_public_address')->value('status'));
        $this->assertSame(ReleaseSetupStep::OPEN, ReleaseSetupStep::where('key', 'appstore_create_app')->value('status'));
    }

    public function test_catalog_knows_stages_and_commit_format(): void
    {
        $this->assertSame('demo', ReleaseCatalog::testStage('web'));
        $this->assertSame('testflight', ReleaseCatalog::testStage('app_store'));
        $this->assertSame('play_production', ReleaseCatalog::productionStage('play_store'));
        $this->assertTrue(ReleaseCatalog::isPromotable('web', 'web_production'));
        $this->assertFalse(ReleaseCatalog::isPromotable('web', 'demo'));
        $this->assertFalse(ReleaseCatalog::isPromotable('web', 'testflight'));
        $this->assertFalse(ReleaseCatalog::isPromotable('nope', 'web_production'));
        $this->assertTrue(ReleaseCatalog::isProduction('app_store', 'app_store'));
        $this->assertFalse(ReleaseCatalog::isProduction('app_store', 'testflight'));
        $this->assertFalse(ReleaseCatalog::isProduction('nope', 'app_store'));
        $this->assertTrue(ReleaseCatalog::isCommit($this->sha()));
        foreach ([substr($this->sha(), 1), $this->sha().'a', strtoupper($this->sha('f')), 'HEAD', '', str_repeat('g', 40), $this->sha()."\n"] as $bad) {
            $this->assertFalse(ReleaseCatalog::isCommit($bad), var_export($bad, true));
        }
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter=ReleaseModelTest`
Expected: FAIL (klasserna och tabellerna finns inte).

- [ ] **Steg 3: Konfig och migrationer.**

`config/releases.php`:

```php
<?php

// Släppflödet (spec 2026-10-14, delprojekt 4). Värdena hamnar aldrig i verktygssvar.
return [
    // Global brytare. Allt utom ett sant värde stänger, så att en felstavning stänger i stället för att öppna.
    'enabled' => filter_var(env('RELEASES_ENABLED', true), FILTER_VALIDATE_BOOL),

    // Ett släpp i awaiting_approval eller requested utan beslut/byggstart blir cancelled efter så här många dygn.
    'ttl_days' => 7,

    // building/processing längre än så här larmar nattkontrollen.
    'stuck_minutes' => 120,

    // Öppna engångssteg äldre än så här påminns det om.
    'setup_reminder_days' => 7,

    // Hur många byggen pollningen läser per spel (Coolifys lista visar bara de senaste).
    'demo_poll_take' => 20,
];
```

`database/migrations/2026_10_16_100001_add_release_columns_to_games_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // JSON kan inte ha ett litteralt standardvärde i MySQL; modellen sätter [] för nya spel.
            $table->json('release_targets')->nullable()->after('is_listed');
            $table->boolean('releases_enabled')->default(true)->after('release_targets');
            $table->string('bundle_id', 80)->nullable()->unique()->after('releases_enabled');
        });

        // Befintliga spel får en tom lista, aldrig null, så att koden inte behöver skilja på de två.
        DB::table('games')->update(['release_targets' => '[]']);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['bundle_id']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['release_targets', 'releases_enabled', 'bundle_id']);
        });
    }
};
```

`database/migrations/2026_10_16_100002_create_releases_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('target', 16);
            $table->string('stage', 24);
            $table->string('commit_sha', 40);
            $table->string('status', 20);
            // 'active' medan släppet är pågående, annars null. Det unika indexet nedan
            // tillåter flera null (även i MySQL) men bara ett pågående släpp per plats.
            $table->string('active_key', 8)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('self_approved')->default(false);
            // GitHub run-id, Apple build-id och version, Play version code, Coolify deployment-uuid (etapp 1b och framåt).
            $table->json('external')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'target', 'stage', 'active_key']);
            $table->index(['game_id', 'target', 'stage', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
```

`database/migrations/2026_10_16_100003_create_demo_builds_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kvitto per lyckat Demo-bygge. Coolifys bygglista visar bara de senaste byggena.
        Schema::create('demo_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('deployment_uuid', 64);
            $table->timestamp('deployed_at');
            $table->timestamps();
            $table->unique(['game_id', 'deployment_uuid']);
            $table->index(['game_id', 'commit_sha']);
            $table->index(['game_id', 'deployed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_builds');
    }
};
```

`database/migrations/2026_10_16_100004_create_release_setup_steps_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_setup_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('target', 16);
            $table->string('key', 40);
            $table->string('status', 8)->default('open');
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->text('instruction');
            $table->timestamps();
            $table->unique(['game_id', 'target', 'key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_setup_steps');
    }
};
```

- [ ] **Steg 4: Modeller och ren data.**

`app/Models/Release.php`:

```php
<?php

namespace App\Models;

use App\Provisioning\Http\Redactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Release extends Model
{
    public const REQUESTED = 'requested';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const BUILDING = 'building';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Pågående status: bara ett släpp per spel, mål och steg får ha någon av dem. */
    public const ACTIVE = [self::REQUESTED, self::AWAITING_APPROVAL, self::BUILDING, self::PROCESSING];

    protected $fillable = [
        'uuid', 'game_id', 'target', 'stage', 'commit_sha', 'status', 'requested_by', 'approved_by',
        'approved_at', 'self_approved', 'external', 'last_error',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'self_approved' => 'boolean', 'external' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Release $release) {
            $release->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Release $release) {
            // Indexet på (game_id, target, stage, active_key) är spärr 4: nyckeln följer alltid statusen.
            $release->active_key = in_array($release->status, self::ACTIVE, true) ? 'active' : null;

            // last_error visas för spelets medlemmar: kända hemligheter bort och högst 4 KB.
            if (is_string($release->last_error)) {
                $release->last_error = Redactor::limit(Redactor::scrubSecrets($release->last_error));
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Spärr 7: ett släpp i awaiting_approval eller requested som ingen rört på
     * config('releases.ttl_days') dygn blir cancelled. Anropas före varje
     * kontroll som läser pågående släpp, så att en utgången plats frigörs.
     *
     * @return Collection<int, Release> de släpp som avbröts nu
     */
    public static function expireStale(): Collection
    {
        $days = (int) config('releases.ttl_days');
        $cutoff = now()->subDays($days);
        $expired = new Collection;

        $candidates = static::whereIn('status', [self::AWAITING_APPROVAL, self::REQUESTED])->where('updated_at', '<', $cutoff)->pluck('id');
        foreach ($candidates as $id) {
            DB::transaction(function () use ($id, $cutoff, $days, $expired) {
                // Om statusen hunnit ändras (godkänt, startat) lämnas släppet ifred.
                $release = static::whereKey($id)->lockForUpdate()->first();
                if ($release !== null && in_array($release->status, [self::AWAITING_APPROVAL, self::REQUESTED], true) && $release->updated_at->lt($cutoff)) {
                    $release->update(['status' => self::CANCELLED, 'last_error' => "Cancelled: no decision or build start within {$days} days."]);
                    $expired->push($release);
                }
            });
        }

        return $expired;
    }
}
```

`app/Models/DemoBuild.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kvitto per lyckat Demo-bygge (spärr 1). Skrivs bara av DemoBuildRecorder.
class DemoBuild extends Model
{
    protected $fillable = ['game_id', 'commit_sha', 'deployment_uuid', 'deployed_at'];

    protected function casts(): array
    {
        return ['deployed_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public static function latestFor(Game $game): ?DemoBuild
    {
        return static::where('game_id', $game->id)->orderByDesc('deployed_at')->orderByDesc('id')->first();
    }
}
```

`app/Models/ReleaseSetupStep.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleaseSetupStep extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    protected $fillable = ['game_id', 'target', 'key', 'status', 'done_by', 'done_at', 'instruction'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
```

`app/Releases/ReleaseCatalog.php`:

```php
<?php

namespace App\Releases;

// Ren data: vilka mål och steg som finns (spec Del 1, "Steg per mål").
final class ReleaseCatalog
{
    public const TARGETS = ['web', 'app_store', 'play_store'];

    /** Steg som kan begäras med promote_release. `demo` är inget släpp: det är ett push-bygge. */
    public const PROMOTABLE_STAGES = ['testflight', 'play_internal', 'web_production', 'app_store', 'play_production'];

    public const STAGES = [
        'web' => ['test' => 'demo', 'production' => 'web_production'],
        'app_store' => ['test' => 'testflight', 'production' => 'app_store'],
        'play_store' => ['test' => 'play_internal', 'production' => 'play_production'],
    ];

    /** I vilken etapp stegets bygge kommer (R1: etapp 1a bygger inget). */
    public const BUILD_ETAPP = [
        'web_production' => '1b',
        'testflight' => '2',
        'app_store' => '2',
        'play_internal' => '3',
        'play_production' => '3',
    ];

    public static function testStage(string $target): string
    {
        return self::STAGES[$target]['test'] ?? throw new \InvalidArgumentException("Unknown target: {$target}");
    }

    public static function productionStage(string $target): string
    {
        return self::STAGES[$target]['production'] ?? throw new \InvalidArgumentException("Unknown target: {$target}");
    }

    public static function isPromotable(string $target, string $stage): bool
    {
        return in_array($stage, self::PROMOTABLE_STAGES, true)
            && in_array($stage, self::STAGES[$target] ?? [], true);
    }

    public static function isProduction(string $target, string $stage): bool
    {
        return isset(self::STAGES[$target]) && self::STAGES[$target]['production'] === $stage;
    }

    /** Fullständigt SHA i gemener. \z, inte $, så att en avslutande radbrytning inte släpps igenom. */
    public static function isCommit(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}\z/', $value) === 1;
    }
}
```

`app/Releases/SetupSteps.php`:

```php
<?php

namespace App\Releases;

use App\Models\Game;
use App\Models\ReleaseSetupStep;

// Engångssteg per spel och butik (spec Del 1). Skapas när ett mål läggs till.
// play_first_aab saknas medvetet: det skapas först om etapp 3:s spike bekräftar kravet (R9).
final class SetupSteps
{
    /** @var array<string, array<string, string>> */
    public const DEFINITIONS = [
        'web' => [
            'web_public_address' => 'Erik sätter spelets publika webbadress (domänen) för produktionswebben.',
        ],
        'app_store' => [
            'appstore_bundle_id' => 'Computercat registrerar spelets bundle-id i App Store Connect via API (byggs i etapp 2).',
            'appstore_create_app' => 'Erik skapar appen i App Store Connect med spelets bundle-id.',
            'appstore_privacy_and_age' => 'Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect.',
        ],
        'play_store' => [
            'play_create_app' => 'Erik skapar appen i Play Console med spelets paketnamn.',
            'play_store_listing' => 'Erik fyller i innehållsklassning, dataskydd, målgrupp och butikstext i Play Console.',
            'play_app_signing' => 'Erik aktiverar Play App Signing för appen i Play Console.',
        ],
    ];

    /** Skapar saknade steg för spelets mål. Klara och öppna steg lämnas orörda; inget raderas. */
    public static function sync(Game $game): void
    {
        foreach ($game->releaseTargets() as $target) {
            foreach (self::DEFINITIONS[$target] ?? [] as $key => $instruction) {
                ReleaseSetupStep::firstOrCreate(
                    ['game_id' => $game->id, 'target' => $target, 'key' => $key],
                    ['status' => ReleaseSetupStep::OPEN, 'instruction' => $instruction],
                );
            }
        }
    }
}
```

`app/Releases/ReleaseSwitch.php`:

```php
<?php

namespace App\Releases;

use App\Models\Game;

// Spärr 9: brytaren globalt (R4) och per spel.
final class ReleaseSwitch
{
    public static function disabledReason(Game $game): ?string
    {
        if (config('releases.enabled') !== true) {
            return 'Releases are switched off globally (RELEASES_ENABLED).';
        }
        if (! $game->releases_enabled) {
            return 'Releases are switched off for this game.';
        }

        return null;
    }
}
```

Ändra `app/Models/Game.php`: lägg till i `casts()`-arrayen `'release_targets' => 'array', 'releases_enabled' => 'boolean',`, lägg till `protected $attributes = ['release_targets' => '[]'];` direkt efter `$hidden`-raden, och lägg till metoderna sist i klassen (före avslutande `}`):

```php
    /** @return list<string> */
    public function releaseTargets(): array
    {
        return array_values($this->release_targets ?? []);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function setupSteps(): HasMany
    {
        return $this->hasMany(ReleaseSetupStep::class);
    }

    public function demoBuilds(): HasMany
    {
        return $this->hasMany(DemoBuild::class);
    }
```

`release_targets`, `releases_enabled` och `bundle_id` läggs medvetet **inte** i `$fillable`; de skrivs med `forceFill` av tjänsterna.

- [ ] **Steg 5: Kör testerna.**

Run: `cd $WT && php artisan test --filter=ReleaseModelTest`
Expected: PASS (9 tester).

- [ ] **Steg 6: Kör hela sviten för regressioner i `Game`.**

Run: `cd $WT && php artisan test`
Expected: PASS.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add config/releases.php database/migrations app/Models app/Releases tests/Support/CreatesReleases.php tests/Feature/Releases && git commit -m "Släppflöde 1a: tabeller, modeller och engångssteg"
```

---

### Task 3: Demo-kvitton — Coolify-klient, recorder, pollning och nattkontroll

**Files:**
- Modify: `app/Provisioning/Clients/CoolifyClient.php` (ny metod `recentDeployments`)
- Create: `app/Releases/DemoBuildRecorder.php`
- Create: `app/Console/Commands/PollDemoBuilds.php`
- Modify: `routes/console.php`
- Modify: `app/Mcp/Tools/GetProvisioningStatusTool.php`
- Modify: `app/Console/Commands/CheckProvisioning.php`
- Modify: `tests/Support/ProvisioningFakes.php` (`coolifyDeployments` får `commit`)
- Test: `tests/Feature/Releases/DemoBuildRecorderTest.php`, tillägg i `tests/Feature/Mcp/ProvisioningStatusToolTest.php` och `tests/Feature/Console/CheckProvisioningTest.php`

**Interfaces:**
- Consumes: `DemoBuild`, `ReleaseCatalog::isCommit()` (Task 2), `ProvisioningRun`, `CoolifyClient::assertKnown` (privat, finns).
- Produces:
  - `CoolifyClient::recentDeployments(string $uuid, int $take = 10): array` med poster `array{deployment_uuid: string, status: string, created_at: string, commit: ?string}`, nyast först; kastar `ExternalApiException` vid fel form och `CoolifyGuardException` för okänt uuid (samma spärr som `latestDeployment`).
  - `DemoBuildRecorder::recordForRun(ProvisioningRun $run): int` (antal nya kvitton), `DemoBuildRecorder::recordAll(): array{recorded: int, failed: array<string, string>}` (slug => redigerat felmeddelande).
  - Kommandot `computercat:poll-demo-builds` (exitkod 1 om något spel inte kunde läsas).
  - `ProvisioningFakes::coolifyDeployments(array $deployments)` tar poster `[deployment_uuid, status, created_at?, commit?]`; `commit` är som standard 40 `a`.

- [ ] **Steg 1: Uppdatera fejken (spikens form, S22/S26).** I `tests/Support/ProvisioningFakes.php`, ändra docblocken och raden `'commit' => 'abc123',` i `coolifyDeployments`:

```php
     * @param  list<array{0: string, 1: string, 2?: string, 3?: string}>  $deployments  [deployment_uuid, status, created_at, commit]
     */
    public static function coolifyDeployments(array $deployments): array
    {
        return [
            'count' => count($deployments),
            'deployments' => array_map(fn (array $d) => [
                'deployment_uuid' => $d[0],
                'status' => $d[1],
                'created_at' => $d[2] ?? '2026-10-14T08:00:00.000000Z',
                'commit' => $d[3] ?? str_repeat('a', 40),
                'logs' => 'Building... token=hemligt',
            ], $deployments),
        ];
    }
```

Kontrollera att inget test förväntar sig `abc123`: `cd $WT && grep -rn "abc123" tests/Feature/Provisioning tests/Feature/Mcp tests/Feature/Console` ska inte ge någon träff som rör Coolify.

- [ ] **Steg 2: Skriv testerna.** `tests/Feature/Releases/DemoBuildRecorderTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\Http\ExternalApiException;
use App\Releases\DemoBuildRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class DemoBuildRecorderTest extends ProvisioningTestCase
{
    use CreatesReleases;

    private const LIST_URL = 'coolify.test/api/v1/deployments/applications/app-1*';

    private Game $game;

    private ProvisioningRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []]);
        $this->run = $this->provisioningRun([
            'slug' => 'kattsvep', 'game_id' => $this->game->id, 'status' => ProvisioningRun::SUCCEEDED,
            'coolify_app_uuid' => 'app-1', 'demo_url' => 'https://kattsvep.apps.humblebrag.se',
        ]);
    }

    private function fakeList(array $rows): void
    {
        Http::fake([self::LIST_URL => Http::response(ProvisioningFakes::coolifyDeployments($rows)), ProvisioningFakes::SLACK => Http::response('ok')]);
    }

    private function recorder(): DemoBuildRecorder
    {
        return app(DemoBuildRecorder::class);
    }

    public function test_client_returns_commit_and_validates_its_type(): void
    {
        $this->fakeList([['dep-2', 'finished', '2026-10-14T09:00:00.000000Z', $this->sha('b')], ['dep-1', 'in_progress']]);

        $rows = app(CoolifyClient::class)->recentDeployments('app-1', 20);

        $this->assertSame(['deployment_uuid' => 'dep-2', 'status' => 'finished', 'created_at' => '2026-10-14T09:00:00.000000Z', 'commit' => $this->sha('b')], $rows[0]);
        Http::assertSent(fn ($r) => $r->url() === 'https://coolify.test/api/v1/deployments/applications/app-1?skip=0&take=20');

        Http::fake([self::LIST_URL => Http::response(['count' => 1, 'deployments' => [['deployment_uuid' => 'd', 'status' => 'finished', 'created_at' => 'x', 'commit' => 123]]])]);
        $this->expectException(ExternalApiException::class);
        app(CoolifyClient::class)->recentDeployments('app-1');
    }

    public function test_client_refuses_unknown_uuid_without_a_request(): void
    {
        Http::fake();

        try {
            app(CoolifyClient::class)->recentDeployments('okand');
            $this->fail('Förväntade CoolifyGuardException.');
        } catch (\App\Provisioning\Clients\CoolifyGuardException) {
            Http::assertNothingSent();
        }
    }

    public function test_only_finished_builds_with_a_full_sha_get_a_receipt(): void
    {
        $this->fakeList([
            ['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', $this->sha('a')],
            ['dep-2', 'failed', '2026-10-14T08:10:00.000000Z', $this->sha('b')],
            ['dep-3', 'in_progress', '2026-10-14T08:20:00.000000Z', $this->sha('c')],
            ['dep-4', 'finished', '2026-10-14T08:30:00.000000Z', 'abc123'],
            ['dep-5', 'finished', '2026-10-14T08:40:00.000000Z', 'HEAD'],
        ]);

        $this->assertSame(1, $this->recorder()->recordForRun($this->run));

        $this->assertSame(['dep-1'], DemoBuild::pluck('deployment_uuid')->all());
        $this->assertSame($this->sha('a'), DemoBuild::first()->commit_sha);
        $this->assertSame($this->game->id, DemoBuild::first()->game_id);
    }

    public function test_recording_is_idempotent_and_normalizes_case(): void
    {
        $this->fakeList([['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', strtoupper($this->sha('f'))]]);

        $this->assertSame(1, $this->recorder()->recordForRun($this->run));
        $this->assertSame(0, $this->recorder()->recordForRun($this->run));

        $this->assertSame(1, DemoBuild::count());
        $this->assertSame($this->sha('f'), DemoBuild::first()->commit_sha);
    }

    public function test_runs_that_are_not_succeeded_provisions_with_a_game_are_skipped(): void
    {
        $this->fakeList([['dep-1', 'finished']]);
        $this->run->update(['status' => ProvisioningRun::FAILED]);

        $this->assertSame(0, $this->recorder()->recordForRun($this->run));
        Http::assertNothingSent();
    }

    public function test_poll_command_records_for_all_games_and_reports_failures_once_per_day(): void
    {
        $other = Game::create(['slug' => 'ordjakt', 'name' => 'Ordjakt', 'is_active' => true, 'settings' => []]);
        $this->provisioningRun(['slug' => 'ordjakt', 'game_id' => $other->id, 'status' => ProvisioningRun::SUCCEEDED, 'coolify_app_uuid' => 'app-2', 'demo_url' => 'https://ordjakt.apps.humblebrag.se']);
        Http::fake([
            self::LIST_URL => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished']])),
            'coolify.test/api/v1/deployments/applications/app-2*' => Http::response(['message' => 'boom'], 500),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);

        $this->artisan('computercat:poll-demo-builds')->assertExitCode(1);
        $this->artisan('computercat:poll-demo-builds')->assertExitCode(1);

        $this->assertSame(1, DemoBuild::where('game_id', $this->game->id)->count());
        $this->assertSame(0, DemoBuild::where('game_id', $other->id)->count());
        $slack = Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK);
        $this->assertCount(1, $slack);
        $this->assertStringContainsString('ordjakt', $slack->first()[0]['text']);
    }

    public function test_poll_command_is_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'computercat:poll-demo-builds'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }

    public function test_poll_command_refuses_when_provisioning_is_not_configured(): void
    {
        config(['provisioning.coolify.token' => '']);
        Http::fake();

        $this->artisan('computercat:poll-demo-builds')->expectsOutputToContain('COOLIFY_TOKEN')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
```

Lägg till i `tests/Feature/Mcp/ProvisioningStatusToolTest.php` (ny testmetod efter `test_finished_build_and_answering_demo_is_live_and_enables_monitoring_once`) och importera `App\Models\DemoBuild`:

```php
    // Spärr 1: statusanropet skriver kvittot, utan att ändra svaret.
    public function test_status_call_records_a_demo_receipt_for_a_finished_build(): void
    {
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'finished', '2026-10-14T08:00:00.000000Z', str_repeat('c', 40)]])),
            'https://kattsvep.apps.humblebrag.se' => Http::response('<html></html>'),
            'siteops.test/api/v1/sites/42/environments/7' => Http::response(ProvisioningFakes::siteopsEnvironmentUpdated(42, 'https://kattsvep.apps.humblebrag.se')),
            'siteops.test/api/v1/sites/42/environments' => Http::response(ProvisioningFakes::siteopsEnvironments('https://kattsvep.apps.humblebrag.se', monitored: false)),
        ]);

        $this->assertSame('live', $demo['state']);
        $this->assertArrayNotHasKey('receipts_error', $demo);
        $this->assertSame([str_repeat('c', 40)], DemoBuild::pluck('commit_sha')->all());
    }

    public function test_receipt_failure_is_shown_but_does_not_break_the_status(): void
    {
        $calls = 0;
        $demo = $this->demoFor([
            'coolify.test/api/v1/deployments/applications/app-1*' => function () use (&$calls) {
                // Första anropet är latestDeployment, andra är recentDeployments.
                return ++$calls === 1
                    ? Http::response(ProvisioningFakes::coolifyDeployments([['dep-1', 'failed']]))
                    : Http::response(['message' => 'boom'], 500);
            },
            'https://kattsvep.apps.humblebrag.se' => Http::response('Bad Gateway', 502),
        ]);

        $this->assertSame('deploy_failed', $demo['state']);
        $this->assertStringContainsString('Could not record Demo build receipts', $demo['receipts_error']);
    }
```

Lägg till i `tests/Feature/Console/CheckProvisioningTest.php` (ny metod före `test_is_scheduled_nightly`) och importera `App\Models\DemoBuild`:

```php
    public function test_nightly_check_records_demo_receipts(): void
    {
        $this->run->update(['game_id' => \App\Models\Game::create(['slug' => 'kattsvep', 'name' => 'Kattsvep', 'is_active' => true, 'settings' => []])->id]);
        $this->fakeHealthy();

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        $this->assertSame([str_repeat('a', 40)], DemoBuild::pluck('commit_sha')->all());
    }
```

- [ ] **Steg 3: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter='DemoBuildRecorderTest|test_status_call_records|test_receipt_failure|test_nightly_check_records'`
Expected: FAIL (metoden och klassen finns inte).

- [ ] **Steg 4: `CoolifyClient::recentDeployments`.** Lägg till direkt före kommentaren `// Asynkron i Coolify (DeleteResourceJob)…` i `app/Provisioning/Clients/CoolifyClient.php`:

```php
    /**
     * De senaste byggena, nyast först, med commit (S26-S28 i bilaga A). Samma spärr
     * och samma formkontroll som latestDeployment; commit är string eller null.
     * Coolifys lista visar bara de senaste byggena (S29), därför sparas kvitton.
     *
     * @return list<array{deployment_uuid: string, status: string, created_at: string, commit: ?string}>
     */
    public function recentDeployments(string $uuid, int $take = 10): array
    {
        $this->assertKnown($uuid);
        $path = '/deployments/applications/'.$uuid.'?'.http_build_query(['skip' => 0, 'take' => max(1, min($take, 100))]);
        $deployments = $this->coolify('GET', $path)->json('deployments');
        if (! is_array($deployments) || ! array_is_list($deployments)) {
            throw new ExternalApiException('Coolify', 'GET', $path, 200, 'response lacks deployments[] (expected a list)');
        }

        $out = [];
        foreach ($deployments as $deployment) {
            if (! is_array($deployment)) {
                throw new ExternalApiException('Coolify', 'GET', $path, 200, 'deployment entry is not an object');
            }
            $status = $deployment['status'] ?? null;
            if (! in_array($status, self::DEPLOYMENT_STATUSES, true)) {
                throw new ExternalApiException('Coolify', 'GET', $path, 200, 'unknown deployment status: '.(is_scalar($status) ? (string) $status : gettype($status)));
            }
            foreach (['deployment_uuid', 'created_at'] as $field) {
                if (! is_string($deployment[$field] ?? null) || $deployment[$field] === '') {
                    throw new ExternalApiException('Coolify', 'GET', $path, 200, "deployment lacks {$field}");
                }
            }
            $commit = $deployment['commit'] ?? null;
            if ($commit !== null && ! is_string($commit)) {
                throw new ExternalApiException('Coolify', 'GET', $path, 200, 'deployment commit is not a string');
            }

            $out[] = ['deployment_uuid' => $deployment['deployment_uuid'], 'status' => $status, 'created_at' => $deployment['created_at'], 'commit' => $commit];
        }

        return $out;
    }

```

Om spiken (S27) visade att `take=20` inte accepteras: stanna och fråga Erik; `min($take, 100)` ska ändras till det verifierade taket.

- [ ] **Steg 5: `DemoBuildRecorder`.** `app/Releases/DemoBuildRecorder.php`:

```php
<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\ProvisioningRun;
use App\Provisioning\Clients\CoolifyClient;
use App\Provisioning\SafeMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Skriver ett kvitto per lyckat Demo-bygge (spärr 1), eftersom Coolifys bygglista
// bara visar de senaste. Ett kvitto kräver status finished och ett fullständigt
// commit-SHA (R8, S26); allt annat ignoreras och loggas en gång per bygge.
final class DemoBuildRecorder
{
    public function __construct(private CoolifyClient $coolify) {}

    /** @return int antal nya kvitton */
    public function recordForRun(ProvisioningRun $run): int
    {
        if ($run->kind !== ProvisioningRun::KIND_PROVISION
            || $run->status !== ProvisioningRun::SUCCEEDED
            || $run->game_id === null
            || $run->coolify_app_uuid === null) {
            return 0;
        }

        $created = 0;
        foreach ($this->coolify->recentDeployments($run->coolify_app_uuid, (int) config('releases.demo_poll_take')) as $deployment) {
            if ($deployment['status'] !== 'finished') {
                continue;
            }

            $commit = is_string($deployment['commit']) ? strtolower($deployment['commit']) : null;
            if ($commit === null || ! ReleaseCatalog::isCommit($commit)) {
                if (Cache::add('demo-build-skipped:'.$deployment['deployment_uuid'], true, 7 * 86400)) {
                    Log::warning('Demo-bygge utan fullständigt commit-SHA, inget kvitto skrivs', ['game_id' => $run->game_id, 'deployment' => $deployment['deployment_uuid']]);
                }

                continue;
            }

            try {
                $build = DemoBuild::firstOrCreate(
                    ['game_id' => $run->game_id, 'deployment_uuid' => $deployment['deployment_uuid']],
                    ['commit_sha' => $commit, 'deployed_at' => Carbon::parse($deployment['created_at'])],
                );
            } catch (UniqueConstraintViolationException) {
                // En annan process (pollen, statusanropet) hann före: kvittot finns.
                continue;
            }
            $created += $build->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @return array{recorded: int, failed: array<string, string>} slug => redigerat fel */
    public function recordAll(): array
    {
        $recorded = 0;
        $failed = [];

        $runs = ProvisioningRun::provision()->where('status', ProvisioningRun::SUCCEEDED)
            ->whereNotNull('game_id')->whereNotNull('coolify_app_uuid')->orderBy('id')->get();
        foreach ($runs as $run) {
            try {
                $recorded += $this->recordForRun($run);
            } catch (Throwable $e) {
                $failed[$run->slug] = $e instanceof SafeMessage ? $e->getMessage() : get_class($e);
            }
        }

        return ['recorded' => $recorded, 'failed' => $failed];
    }
}
```

- [ ] **Steg 6: Kommandot och schemat.** `app/Console/Commands/PollDemoBuilds.php`:

```php
<?php

namespace App\Console\Commands;

use App\Provisioning\ProvisioningConfig;
use App\Releases\DemoBuildRecorder;
use App\Support\SlackAlert;
use Illuminate\Console\Command;

// Spärr 1 behöver ett kvitto per lyckat Demo-bygge, och Coolifys lista visar bara de
// senaste. Kommandot läser listan för varje spel med Demo och sparar nya kvitton.
class PollDemoBuilds extends Command
{
    protected $signature = 'computercat:poll-demo-builds';

    protected $description = 'Sparar kvitton för lyckade Demo-byggen ur Coolifys bygglista';

    public function handle(DemoBuildRecorder $recorder, SlackAlert $slack): int
    {
        $missing = ProvisioningConfig::missing();
        if ($missing !== []) {
            $this->error('Provisioneringen är inte konfigurerad, saknas: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $result = $recorder->recordAll();
        foreach ($result['failed'] as $slug => $message) {
            $this->line("FEL {$slug}: {$message}");
            // Samma nyckel skickas högst en gång per dygn (SlackAlert), så pollen tjatar inte.
            $slack->send("poll-demo-builds:{$slug}", 'computercat:poll-demo-builds kunde inte läsa byggen för `'.SlackAlert::escape($slug).'`: '.SlackAlert::escape($message));
        }
        $this->info("{$result['recorded']} nya kvitton, ".count($result['failed']).' fel.');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
```

Lägg sist i `routes/console.php`:

```php

// Släppflödet (spec 2026-10-14, spärr 1): kvitton på lyckade Demo-byggen, eftersom Coolifys lista bara visar de senaste.
Schedule::command('computercat:poll-demo-builds')->everyFiveMinutes()->withoutOverlapping(10);
```

- [ ] **Steg 7: Koppla in i `get_provisioning_status`.** I `app/Mcp/Tools/GetProvisioningStatusTool.php`, lägg till `use App\Releases\DemoBuildRecorder;` bland importerna och ersätt blocket

```php
        $result['monitoring'] = $run->fresh()->monitoring_enabled_at === null ? 'pending' : 'enabled';

        return $result;
```

med

```php
        $result['monitoring'] = $run->fresh()->monitoring_enabled_at === null ? 'pending' : 'enabled';

        // Spärr 1: lyckade byggen sparas som kvitton. Ett fel här ändrar inte Demo-läget, men döljs inte.
        try {
            app(DemoBuildRecorder::class)->recordForRun($run);
        } catch (\Throwable $e) {
            $ref = (string) Str::uuid();
            Log::warning('get_provisioning_status kunde inte skriva Demo-kvitton', ['ref' => $ref, 'run' => $run->uuid, 'exception' => $e]);
            $result['receipts_error'] = $e instanceof SafeMessage ? 'Could not record Demo build receipts: '.Redactor::limit(Redactor::scrubSecrets($e->getMessage())) : "Could not record Demo build receipts (ref {$ref}).";
        }

        return $result;
```

- [ ] **Steg 8: Koppla in i nattkontrollen.** I `app/Console/Commands/CheckProvisioning.php`: lägg till `use App\Releases\DemoBuildRecorder;`, lägg till egenskapen `private DemoBuildRecorder $receipts;` under `private DemoMonitoring $monitoring;`, ändra signaturen till `handle(GitHubAppClient $github, CoolifyClient $coolify, SiteOpsClient $siteops, SlackAlert $slack, DemoMonitoring $monitoring, DemoBuildRecorder $receipts): int`, lägg till `$this->receipts = $receipts;` efter `$this->monitoring = $monitoring;`, och lägg till sist i `checkRun` (efter raden `$this->checkDeployment($key, $run, $coolify);`):

```php

        // Spärr 1: nattkontrollen är det tredje stället som skriver Demo-kvitton (skrivs också med --dry-run, det är lokalt).
        $this->check("{$key}:receipts", "Demo-kvitton för {$run->slug}", function () use ($run) {
            $this->receipts->recordForRun($run);

            return null;
        });
```

Uppdatera klass-kommentaren överst med en rad: ` * Nattkontrollen skriver också Demo-kvitton (spärr 1) och bevakar släppen (checkReleases, Task 10).`

- [ ] **Steg 9: Kör testerna.**

Run: `cd $WT && php artisan test --filter='DemoBuildRecorderTest|ProvisioningStatusToolTest|CheckProvisioningTest|CoolifyClientTest'`
Expected: PASS. Faller ett befintligt test på antal HTTP-anrop (`assertSentCount`) eftersom recorder-anropet tillkommit: justera det testets förväntade antal med en kommentar om kvitto-anropet.

- [ ] **Steg 10: Commit.**

```bash
cd $WT && git add app/Provisioning/Clients/CoolifyClient.php app/Releases/DemoBuildRecorder.php app/Console/Commands app/Mcp/Tools/GetProvisioningStatusTool.php routes/console.php tests && git commit -m "Släppflöde 1a: Demo-kvitton från statusanrop, pollning och nattkontroll"
```

---

### Task 4: Spärrarna — `ReleaseGate` och brytaren

**Files:**
- Create: `app/Releases/ReleaseGate.php`
- Test: `tests/Feature/Releases/ReleaseGateTest.php`

**Interfaces:**
- Consumes: `ReleaseSwitch`, `ReleaseCatalog`, `DemoBuild`, `Release`, `ReleaseSetupStep`, `Game::releaseTargets()` (Task 2).
- Produces: `ReleaseGate::check(Game $game, string $target, string $stage, ?string $commit): ?string` — `null` om släppet får skapas, godkännas eller startas, annars ett engelskt skäl. Samma funktion används vid begäran, godkännande och byggstart (spärr 7). Ordning: spelet aktivt, brytare (9), mål i listan (5), steget kan befordras, öppna engångssteg (6), commit finns och har rätt format (8), kvitto för teststeg (1), produktionssteg: lyckat teststeg för samma commit eller, för `web`, Demo-kvitto (2).

- [ ] **Steg 1: Invokera `security-first-mindset`** och gör hotmodellen för spärrarna: vad kan en kapad staff-session eller en felaktig klient göra (skicka commit från ett annat spel, race, manipulerade strängar i `target`/`stage`)? Resultatet är redan inbyggt i testerna nedan; lägg till ett test om hotmodellen hittar något som saknas.

- [ ] **Steg 2: Skriv testerna.** `tests/Feature/Releases/ReleaseGateTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseGate;
use App\Releases\SetupSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

// En test per spärr (spec Del 1). Numren i metodnamnen är specens.
class ReleaseGateTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store']);
    }

    private function check(string $target, string $stage, ?string $commit): ?string
    {
        return ReleaseGate::check($this->game->fresh(), $target, $stage, $commit);
    }

    public function test_switch_off_blocks_everything(): void // spärr 9
    {
        $this->demoBuild($this->game);

        config(['releases.enabled' => false]);
        $this->assertStringContainsString('switched off globally', (string) $this->check('app_store', 'testflight', null));

        config(['releases.enabled' => true]);
        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->assertStringContainsString('switched off for this game', (string) $this->check('app_store', 'testflight', $this->sha()));

        $this->game->forceFill(['releases_enabled' => true])->save();
        $this->assertNull($this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_global_switch_fails_closed_on_a_non_boolean_value(): void // spärr 9, felstavad miljövariabel
    {
        $this->demoBuild($this->game);
        config(['releases.enabled' => 'yes']);

        $this->assertNotNull($this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_target_must_be_in_release_targets(): void // spärr 5
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('not enabled', (string) $this->check('play_store', 'play_internal', $this->sha()));
        $this->assertStringContainsString('not enabled', (string) $this->check('nonsense', 'testflight', $this->sha()));
    }

    public function test_stage_must_belong_to_the_target(): void
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('cannot be promoted', (string) $this->check('web', 'testflight', $this->sha()));
        $this->assertStringContainsString('cannot be promoted', (string) $this->check('web', 'demo', $this->sha()));
        $this->assertStringContainsString('cannot be promoted', (string) $this->check('app_store', 'web_production', $this->sha()));
    }

    public function test_open_setup_steps_block_the_target(): void // spärr 6
    {
        $this->demoBuild($this->game);
        ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'appstore_create_app')->update(['status' => ReleaseSetupStep::OPEN]);

        $reason = (string) $this->check('app_store', 'testflight', $this->sha());

        $this->assertStringContainsString('Setup is not finished for app_store', $reason);
        $this->assertStringContainsString('App Store Connect', $reason);
        // Ett annat mål påverkas inte.
        $this->assertNull($this->check('web', 'web_production', $this->sha()));
    }

    public function test_test_stage_needs_a_demo_receipt_for_the_commit(): void // spärr 1
    {
        $this->assertStringContainsString('No successful Demo build exists yet', (string) $this->check('app_store', 'testflight', null));
        $this->assertStringContainsString('has no successful Demo build', (string) $this->check('app_store', 'testflight', $this->sha('b')));

        $this->demoBuild($this->game, $this->sha('b'));

        $this->assertNull($this->check('app_store', 'testflight', $this->sha('b')));
    }

    public function test_receipt_of_another_game_does_not_count(): void // spärr 1
    {
        $other = $this->releasableGame('ordjakt', ['web']);
        $this->demoBuild($other, $this->sha('c'));

        $this->assertStringContainsString('has no successful Demo build', (string) $this->check('app_store', 'testflight', $this->sha('c')));
    }

    public function test_production_stage_needs_a_succeeded_test_stage_for_the_same_commit(): void // spärr 2
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::BUILDING]);
        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $this->assertStringContainsString('has not succeeded in testflight', (string) $this->check('app_store', 'app_store', $this->sha()));

        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED]);
        $this->assertNull($this->check('app_store', 'app_store', $this->sha()));
    }

    public function test_web_production_uses_the_demo_receipt_as_its_test_step(): void // spärr 2
    {
        $this->assertStringContainsString('no successful Demo build', (string) $this->check('web', 'web_production', $this->sha()));

        $this->demoBuild($this->game);

        $this->assertNull($this->check('web', 'web_production', $this->sha()));
    }

    public function test_production_requires_an_explicit_commit(): void // spärr 8
    {
        $this->demoBuild($this->game);

        $this->assertStringContainsString('commit_sha is required for production stages', (string) $this->check('web', 'web_production', null));
        $this->assertStringContainsString('commit_sha is required for production stages', (string) $this->check('app_store', 'app_store', null));
    }

    public function test_commit_must_be_a_full_lowercase_sha(): void
    {
        $this->demoBuild($this->game);

        foreach ([substr($this->sha(), 1), $this->sha().'a', strtoupper($this->sha('f')), 'HEAD', 'abc123', $this->sha()."\n"] as $bad) {
            $this->assertStringContainsString('40-character', (string) $this->check('app_store', 'testflight', $bad), var_export($bad, true));
        }
    }

    public function test_archived_game_is_refused(): void
    {
        $this->demoBuild($this->game);
        $this->game->update(['is_active' => false]);

        $this->assertStringContainsString('not active', (string) $this->check('app_store', 'testflight', $this->sha()));
    }

    public function test_setup_steps_are_created_open_for_new_targets(): void
    {
        $game = Game::create(['slug' => 'nytt', 'name' => 'Nytt', 'is_active' => true, 'settings' => []]);
        $game->forceFill(['release_targets' => ['web']])->save();
        SetupSteps::sync($game);
        $this->demoBuild($game);

        $this->assertStringContainsString('Setup is not finished for web', (string) ReleaseGate::check($game->fresh(), 'web', 'web_production', $this->sha()));
    }
}
```

- [ ] **Steg 3: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter=ReleaseGateTest`
Expected: FAIL (`ReleaseGate` finns inte).

- [ ] **Steg 4: Implementera.** `app/Releases/ReleaseGate.php`:

```php
<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;

// Spärrarna 1, 2, 5, 6, 8 och 9 (spec Del 1). Samma kontroll körs när ett släpp
// skapas, godkänns och startas (spärr 7). Spärr 3 (godkännande) och 4 (lås och
// unikt index) ligger i ReleaseService, eftersom de kräver transaktionen.
final class ReleaseGate
{
    /** @return string|null skälet till att släppet inte får gå vidare, annars null */
    public static function check(Game $game, string $target, string $stage, ?string $commit): ?string
    {
        if (! $game->is_active) {
            return 'The game is not active (archived games cannot be released).';
        }

        if (($off = ReleaseSwitch::disabledReason($game)) !== null) { // 9
            return $off;
        }

        if (! in_array($target, $game->releaseTargets(), true)) { // 5
            return "Target {$target} is not enabled for this game; ask a platform admin to run set_release_targets.";
        }

        if (! ReleaseCatalog::isPromotable($target, $stage)) {
            return "Stage {$stage} cannot be promoted for target {$target}.";
        }

        $open = ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)
            ->where('status', ReleaseSetupStep::OPEN)->orderBy('id')->first();
        if ($open !== null) { // 6
            return "Setup is not finished for {$target}: {$open->instruction}";
        }

        $production = ReleaseCatalog::isProduction($target, $stage);
        if ($commit === null) { // 8 (produktion) och tomt Demo-läge (test)
            return $production
                ? 'commit_sha is required for production stages.'
                : 'No successful Demo build exists yet; push to main and wait for get_provisioning_status to show demo.state live.';
        }
        if (! ReleaseCatalog::isCommit($commit)) {
            return 'commit_sha must be a full 40-character lower-case hex SHA.';
        }

        $test = ReleaseCatalog::testStage($target);
        $hasReceipt = DemoBuild::where('game_id', $game->id)->where('commit_sha', $commit)->exists();

        if (! $production) { // 1
            return $hasReceipt ? null : "Commit {$commit} has no successful Demo build.";
        }

        // 2
        if ($test === 'demo') {
            return $hasReceipt ? null : "Commit {$commit} has no successful Demo build, so it cannot go to {$stage}.";
        }

        $tested = Release::where('game_id', $game->id)->where('target', $target)->where('stage', $test)
            ->where('commit_sha', $commit)->where('status', Release::SUCCEEDED)->exists();

        return $tested ? null : "Commit {$commit} has not succeeded in {$test}; promote it there first.";
    }
}
```

- [ ] **Steg 5: Kör testerna.**

Run: `cd $WT && php artisan test --filter=ReleaseGateTest`
Expected: PASS (13 tester).

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Releases/ReleaseGate.php tests/Feature/Releases/ReleaseGateTest.php && git commit -m "Släppflöde 1a: spärrarna 1, 2, 5, 6, 8 och 9"
```

---

### Task 5: `ReleaseService` — lås, godkännande, TTL och Slack

**Files:**
- Create: `app/Releases/ReleaseRefused.php`, `app/Releases/ReleaseService.php`, `app/Releases/ReleaseNotifier.php`
- Test: `tests/Feature/Releases/ReleaseServiceTest.php`, `tests/Feature/Releases/ReleaseNotifierTest.php`

**Interfaces:**
- Consumes: `ReleaseGate::check` (Task 4), `Release::expireStale()`, `DemoBuild::latestFor`, `GameAccess::isOwner`, `SlackAlert`.
- Produces:
  - `final class ReleaseRefused extends \RuntimeException` (meddelandet är engelskt och får visas för anroparen).
  - `ReleaseService::promote(Game $game, User $user, string $target, string $stage, ?string $commit): Release` — kastar `ReleaseRefused`. Skapar släppet med status `awaiting_approval` (första produktionssläppet) eller `requested`.
  - `ReleaseService::approve(Release $release, User $admin): Release`, `::reject(Release $release, User $admin): Release`, `::assertCanStart(Release $release): void` (alla kastar `ReleaseRefused`).
  - `ReleaseNotifier::afterPromote(Release $release): ?string` (SlackAlert-konstant eller null för teststeg), `::afterApprove(Release $release): ?string` (bara för `self_approved`), `::approvalUrl(Game $game): string`.

- [ ] **Steg 1: Skriv testerna.** `tests/Feature/Releases/ReleaseServiceTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseServiceTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store']);
        $this->owner = $this->ownerOf($this->game);
    }

    private function service(): ReleaseService
    {
        return app(ReleaseService::class);
    }

    private function promote(string $target, string $stage, ?string $commit = null, ?User $user = null): Release
    {
        return $this->service()->promote($this->game->fresh(), $user ?? $this->owner, $target, $stage, $commit);
    }

    private function assertRefused(callable $call, string $needle): void
    {
        try {
            $call();
            $this->fail('Förväntade ReleaseRefused.');
        } catch (ReleaseRefused $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_test_stage_defaults_to_the_latest_receipt_and_is_requested(): void
    {
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $this->demoBuild($this->game, $this->sha('b'), now());

        $release = $this->promote('app_store', 'testflight');

        $this->assertSame(Release::REQUESTED, $release->status);
        $this->assertSame($this->sha('b'), $release->commit_sha);
        $this->assertSame($this->owner->id, $release->requested_by);
        $this->assertNull($release->approved_at);
    }

    public function test_an_explicit_commit_wins_over_the_default(): void
    {
        $this->demoBuild($this->game, $this->sha('a'), now()->subHour());
        $this->demoBuild($this->game, $this->sha('b'), now());

        $this->assertSame($this->sha('a'), $this->promote('app_store', 'testflight', $this->sha('a'))->commit_sha);
    }

    public function test_only_the_owner_may_promote(): void
    {
        $this->demoBuild($this->game);
        $member = User::factory()->staff()->create();
        $this->game->members()->attach($member, ['role' => 'member']);

        $this->assertRefused(fn () => $this->promote('app_store', 'testflight', null, $member), 'Only the game owner');
        $this->assertSame(0, Release::count());
        // Platform admin räknas som ägare (GameAccess::isOwner).
        $this->assertSame(Release::REQUESTED, $this->promote('app_store', 'testflight', null, $this->adminUser())->status);
    }

    public function test_first_production_release_awaits_approval_and_later_ones_do_not(): void // spärr 3
    {
        $this->demoBuild($this->game);

        $first = $this->promote('web', 'web_production', $this->sha());
        $this->assertSame(Release::AWAITING_APPROVAL, $first->status);

        $first->update(['status' => Release::SUCCEEDED]);
        $second = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::REQUESTED, $second->status);
    }

    public function test_failed_or_cancelled_production_release_does_not_end_the_approval_requirement(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['status' => Release::FAILED]);
        $this->release($this->game, ['status' => Release::CANCELLED]);

        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_a_succeeded_release_on_another_target_or_stage_does_not_count_as_first(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::SUCCEEDED]);
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'app_store', 'status' => Release::SUCCEEDED]);

        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_second_promote_while_one_is_pending_is_refused(): void // spärr 4
    {
        $this->demoBuild($this->game);
        $this->promote('app_store', 'testflight');

        $this->assertRefused(fn () => $this->promote('app_store', 'testflight'), 'already pending');
        $this->assertSame(1, Release::count());
    }

    public function test_different_slots_can_be_pending_at_once(): void
    {
        $this->demoBuild($this->game);
        $this->promote('app_store', 'testflight');
        $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(2, Release::active()->count());
    }

    public function test_stale_pending_release_is_cancelled_and_frees_the_slot(): void // spärr 7, TTL
    {
        $this->demoBuild($this->game);
        $old = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($old->id)->update(['updated_at' => now()->subDays(8)]);

        $new = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::CANCELLED, $old->fresh()->status);
        $this->assertNull($old->fresh()->active_key);
        $this->assertStringContainsString('within 7 days', $old->fresh()->last_error);
        $this->assertSame(Release::AWAITING_APPROVAL, $new->status);
    }

    public function test_ttl_does_not_touch_a_release_that_is_exactly_inside_the_window(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(6)->subHours(23)]);

        $this->assertSame(0, Release::expireStale()->count());
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_only_platform_admin_can_approve(): void // spärr 3
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->approve($release, $this->owner), 'Only a platform admin');
        $this->assertRefused(fn () => $this->service()->approve($release, User::factory()->staff()->create()), 'Only a platform admin');
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);

        $admin = $this->adminUser();
        $approved = $this->service()->approve($release, $admin);

        $this->assertSame(Release::REQUESTED, $approved->status);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertFalse($approved->self_approved);
    }

    public function test_self_approval_is_flagged(): void
    {
        $this->demoBuild($this->game);
        $admin = $this->adminUser();
        $this->game->members()->attach($admin, ['role' => 'member']);
        $release = $this->promote('web', 'web_production', $this->sha(), $admin);

        $approved = $this->service()->approve($release, $admin);

        $this->assertTrue($approved->self_approved);
        $this->assertTrue($approved->fresh()->self_approved);
    }

    public function test_approval_rechecks_the_receipt(): void // spärr 7
    {
        $build = $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        $build->delete();

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'no successful Demo build');
        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_approval_rechecks_the_switch_and_open_setup_steps(): void // spärr 7, 9, 6
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->game->forceFill(['releases_enabled' => false])->save();
        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'switched off');

        $this->game->forceFill(['releases_enabled' => true])->save();
        \App\Models\ReleaseSetupStep::where('game_id', $this->game->id)->where('key', 'web_public_address')->update(['status' => 'open']);
        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'Setup is not finished');

        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_approval_after_ttl_cancels_the_release(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(8)]);

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'not awaiting approval');
        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_only_an_awaiting_release_can_be_approved_or_rejected(): void
    {
        $this->demoBuild($this->game);
        $release = $this->release($this->game, ['status' => Release::REQUESTED]);

        $this->assertRefused(fn () => $this->service()->approve($release, $this->adminUser()), 'not awaiting approval');
        $this->assertRefused(fn () => $this->service()->reject($release, $this->adminUser()), 'not awaiting approval');
    }

    public function test_reject_cancels_and_frees_the_slot(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->reject($release, $this->owner), 'Only a platform admin');
        $rejected = $this->service()->reject($release, $this->adminUser());

        $this->assertSame(Release::CANCELLED, $rejected->status);
        $this->assertNull($rejected->active_key);
        $this->assertSame(Release::AWAITING_APPROVAL, $this->promote('web', 'web_production', $this->sha())->status);
    }

    public function test_start_rechecks_everything(): void // spärr 7: bygget startar
    {
        $build = $this->demoBuild($this->game);
        $release = $this->promote('app_store', 'testflight');

        $this->service()->assertCanStart($release);

        config(['releases.enabled' => false]);
        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'switched off');
        config(['releases.enabled' => true]);

        $build->delete();
        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'no successful Demo build');
    }

    public function test_start_requires_requested_status_and_an_approved_first_production_release(): void
    {
        $this->demoBuild($this->game);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertRefused(fn () => $this->service()->assertCanStart($release), 'not in requested state');

        // Ett requested-släpp utan godkännande och utan tidigare lyckat produktionssläpp får aldrig starta.
        $release->forceFill(['status' => Release::REQUESTED])->save();
        $this->assertRefused(fn () => $this->service()->assertCanStart($release->fresh()), 'has not been approved');

        $release->forceFill(['status' => Release::AWAITING_APPROVAL])->save();
        $this->service()->approve($release->fresh(), $this->adminUser());
        $this->service()->assertCanStart($release->fresh());
    }

    public function test_start_of_a_later_production_release_needs_no_new_approval(): void
    {
        $this->demoBuild($this->game);
        $this->release($this->game, ['status' => Release::SUCCEEDED]);
        $release = $this->promote('web', 'web_production', $this->sha());

        $this->assertSame(Release::REQUESTED, $release->status);
        $this->service()->assertCanStart($release);
    }

    public function test_unexpected_target_or_stage_values_are_refused_not_fatal(): void
    {
        $this->demoBuild($this->game);

        $this->assertRefused(fn () => $this->promote('nope', 'web_production', $this->sha()), 'not enabled');
        $this->assertRefused(fn () => $this->promote('web', 'nope', $this->sha()), 'cannot be promoted');
        $this->assertSame(0, Release::count());
    }
}
```

`tests/Feature/Releases/ReleaseNotifierTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Release;
use App\Releases\ReleaseNotifier;
use App\Releases\ReleaseService;
use App\Support\SlackAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class ReleaseNotifierTest extends TestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    public function test_first_production_release_asks_erik_to_approve_with_a_filament_link(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        $result = app(ReleaseNotifier::class)->afterPromote($release);

        $this->assertSame(SlackAlert::SENT, $result);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK
            && str_contains($r['text'], 'godkännande')
            && str_contains($r['text'], 'kattsvep')
            && str_contains($r['text'], $this->sha())
            && str_contains($r['text'], '/admin/games/'.$game->id.'/releases'));
    }

    public function test_later_production_release_informs_erik_without_asking_for_approval(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $this->release($game, ['status' => Release::SUCCEEDED]);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        app(ReleaseNotifier::class)->afterPromote($release);

        Http::assertSent(fn ($r) => str_contains($r['text'], 'Produktionssläpp begärt') && ! str_contains($r['text'], 'väntar på godkännande'));
    }

    public function test_test_stage_sends_nothing(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'app_store', 'testflight', null);

        $this->assertNull(app(ReleaseNotifier::class)->afterPromote($release));
        Http::assertNothingSent();
    }

    public function test_slack_failure_is_reported_not_thrown(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('nope', 500)]);
        $game = $this->releasableGame('kattsvep');
        $this->demoBuild($game);
        $release = app(ReleaseService::class)->promote($game, $this->ownerOf($game), 'web', 'web_production', $this->sha());

        $this->assertSame(SlackAlert::FAILED, app(ReleaseNotifier::class)->afterPromote($release));
    }

    public function test_only_self_approval_notifies(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $admin = $this->adminUser();
        $release = app(ReleaseService::class)->promote($game, $admin, 'web', 'web_production', $this->sha());
        $approved = app(ReleaseService::class)->approve($release, $admin);

        $this->assertSame(SlackAlert::SENT, app(ReleaseNotifier::class)->afterApprove($approved));
        Http::assertSent(fn ($r) => str_contains($r['text'], 'self_approved'));

        $other = $this->releasableGame('ordjakt');
        $this->demoBuild($other);
        $second = app(ReleaseService::class)->promote($other, $this->ownerOf($other), 'web', 'web_production', $this->sha());
        $approvedByOther = app(ReleaseService::class)->approve($second, $this->adminUser());

        $this->assertNull(app(ReleaseNotifier::class)->afterApprove($approvedByOther));
    }
}
```

Anmärkning för genomföraren: `ReleaseNotifierTest` anropar notifieraren efter `promote`, aldrig inne i en transaktion; `startHttpTransactionGuard` bevisar det.

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter='ReleaseServiceTest|ReleaseNotifierTest'`
Expected: FAIL (klasserna finns inte).

- [ ] **Steg 3: Implementera.** `app/Releases/ReleaseRefused.php`:

```php
<?php

namespace App\Releases;

// Ett släpp som inte får skapas, godkännas eller startas. Meddelandet är engelskt
// och skrivs av ReleaseGate/ReleaseService, aldrig av användarinmatning, så det får visas.
final class ReleaseRefused extends \RuntimeException {}
```

`app/Releases/ReleaseService.php`:

```php
<?php

namespace App\Releases;

use App\Mcp\GameAccess;
use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Befordran, godkännande och byggstart. Allt som skriver ett släpp går genom
// här. Inget HTTP-anrop sker i transaktionerna; Slack skickas av anroparen efteråt.
final class ReleaseService
{
    /** @throws ReleaseRefused */
    public function promote(Game $game, User $user, string $target, string $stage, ?string $commit): Release
    {
        if (! GameAccess::isOwner($user, $game)) { // R2
            throw new ReleaseRefused('Only the game owner can promote a release.');
        }

        // Utanför transaktionen: en utgången plats ska vara fri när låset tas.
        Release::expireStale();

        try {
            return DB::transaction(function () use ($game, $user, $target, $stage, $commit) {
                // Spärr 4: spelets rad låses, så två samtidiga befordringar köas efter varandra.
                // (SQLite i test ignorerar låset; det unika indexet är då det som håller.)
                $locked = Game::query()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

                $production = ReleaseCatalog::isProduction($target, $stage);
                if ($commit === null && ! $production) { // spärr 8: standardvalet gäller bara teststeg
                    $commit = DemoBuild::latestFor($locked)?->commit_sha;
                }

                if (($why = ReleaseGate::check($locked, $target, $stage, $commit)) !== null) {
                    throw new ReleaseRefused($why);
                }

                if (Release::active()->where('game_id', $locked->id)->where('target', $target)->where('stage', $stage)->exists()) {
                    throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
                }

                // Spärr 3, beräknat i samma transaktion som skrivningen.
                $first = $production && ! Release::where('game_id', $locked->id)->where('target', $target)
                    ->where('stage', $stage)->where('status', Release::SUCCEEDED)->exists();

                return Release::create([
                    'game_id' => $locked->id,
                    'target' => $target,
                    'stage' => $stage,
                    'commit_sha' => $commit,
                    'status' => $first ? Release::AWAITING_APPROVAL : Release::REQUESTED,
                    'requested_by' => $user->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReleaseRefused("A {$stage} release for {$target} is already pending; wait for it or ask Erik to cancel it.");
        }
    }

    /** @throws ReleaseRefused */
    public function approve(Release $release, User $admin): Release
    {
        $this->assertAdmin($admin);
        Release::expireStale();

        return DB::transaction(function () use ($release, $admin) {
            $locked = $this->lockedAwaiting($release);

            // Spärr 7: samma kontroll som vid begäran, nu mot dagens läge.
            if (($why = ReleaseGate::check($locked->game, $locked->target, $locked->stage, $locked->commit_sha)) !== null) {
                throw new ReleaseRefused($why);
            }

            $self = $locked->requested_by !== null && (int) $locked->requested_by === (int) $admin->id;
            $locked->update(['status' => Release::REQUESTED, 'approved_by' => $admin->id, 'approved_at' => now(), 'self_approved' => $self]);
            if ($self) {
                Log::notice('Release self-approved', ['release' => $locked->uuid, 'game_id' => $locked->game_id, 'user_id' => $admin->id]);
            }

            return $locked;
        });
    }

    /** @throws ReleaseRefused */
    public function reject(Release $release, User $admin): Release
    {
        $this->assertAdmin($admin);
        Release::expireStale();

        return DB::transaction(function () use ($release) {
            $locked = $this->lockedAwaiting($release);
            $locked->update(['status' => Release::CANCELLED, 'last_error' => 'Rejected by a platform admin.']);

            return $locked;
        });
    }

    /**
     * Anropas av bygget (etapp 1b, 2, 3) precis innan det startar. Spärr 7 och 9.
     *
     * @throws ReleaseRefused
     */
    public function assertCanStart(Release $release): void
    {
        Release::expireStale();

        $fresh = Release::with('game')->find($release->getKey());
        if ($fresh === null || $fresh->status !== Release::REQUESTED) {
            throw new ReleaseRefused('Release is not in requested state.');
        }

        if (($why = ReleaseGate::check($fresh->game, $fresh->target, $fresh->stage, $fresh->commit_sha)) !== null) {
            throw new ReleaseRefused($why);
        }

        // Ett produktionssläpp startar bara om en admin godkänt det, eller om målet redan haft ett lyckat produktionssläpp.
        if (ReleaseCatalog::isProduction($fresh->target, $fresh->stage) && $fresh->approved_at === null
            && ! Release::where('game_id', $fresh->game_id)->where('target', $fresh->target)->where('stage', $fresh->stage)->where('status', Release::SUCCEEDED)->exists()) {
            throw new ReleaseRefused('The first production release has not been approved by a platform admin.');
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isPlatformAdmin()) {
            throw new ReleaseRefused('Only a platform admin can approve or reject a release.');
        }
    }

    private function lockedAwaiting(Release $release): Release
    {
        $locked = Release::with('game')->whereKey($release->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== Release::AWAITING_APPROVAL) {
            throw new ReleaseRefused("Release is {$locked->status}, not awaiting approval.");
        }

        return $locked;
    }
}
```

`app/Releases/ReleaseNotifier.php`:

```php
<?php

namespace App\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Support\SlackAlert;

// Slack-meddelanden för släppflödet (spec: ett släpp i awaiting_approval ger ett
// meddelande med länk till Filament; vid senare produktionssläpp får Erik ett besked).
// Anropas efter att transaktionen är klar och kastar aldrig: ett misslyckat larm
// syns i resultatet (SlackAlert::FAILED), inte som ett fel i flödet.
final class ReleaseNotifier
{
    public function __construct(private SlackAlert $slack) {}

    // Task 9 byter till GameResource::getUrl('releases', …) när sidan finns.
    public function approvalUrl(Game $game): string
    {
        return url('/admin/games/'.$game->getKey().'/releases');
    }

    /** @return string|null SlackAlert-konstant, eller null för teststeg (inget skickas) */
    public function afterPromote(Release $release): ?string
    {
        if (! ReleaseCatalog::isProduction($release->target, $release->stage)) {
            return null;
        }

        $game = $release->game;
        $what = '`'.SlackAlert::escape($game->slug).'` '.SlackAlert::escape($release->target).'/'.SlackAlert::escape($release->stage).', commit '.SlackAlert::escape($release->commit_sha);
        $text = $release->status === Release::AWAITING_APPROVAL
            ? "Släpp väntar på godkännande: {$what}. Godkänn eller avslå i Filament: ".$this->approvalUrl($game)
            : "Produktionssläpp begärt av spelets ägare: {$what} (första släppet är redan godkänt). ".$this->approvalUrl($game);

        return $this->slack->send("release-requested:{$release->uuid}", $text);
    }

    /** Bara om godkännaren är den som begärde släppet. */
    public function afterApprove(Release $release): ?string
    {
        if (! $release->self_approved) {
            return null;
        }

        $game = $release->game;

        return $this->slack->send("release-approved:{$release->uuid}", 'Släpp godkänt av samma person som begärde det (self_approved): `'.SlackAlert::escape($game->slug).'` '.SlackAlert::escape($release->target).'/'.SlackAlert::escape($release->stage).', commit '.SlackAlert::escape($release->commit_sha).'.');
    }
}
```

- [ ] **Steg 4: Kör testerna.**

Run: `cd $WT && php artisan test --filter='ReleaseServiceTest|ReleaseNotifierTest'`
Expected: PASS.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Releases tests/Feature/Releases && git commit -m "Släppflöde 1a: befordran med lås, godkännande, TTL och Slack"
```

---

### Task 6: `next`-texterna och `get_release_status`

**Files:**
- Create: `app/Releases/ReleaseNext.php`, `app/Releases/ReleaseStatus.php`, `app/Mcp/Tools/GetReleaseStatusTool.php`
- Modify: `app/Mcp/ComputercatServer.php`
- Test: `tests/Feature/Releases/ReleaseStatusTest.php`, `tests/Feature/Mcp/GetReleaseStatusToolTest.php`

**Interfaces:**
- Consumes: `ReleaseCatalog`, `ReleaseSwitch`, `DemoBuild`, `Release`, `ReleaseSetupStep` (Task 2).
- Produces:
  - `ReleaseNext::TEMPLATES` (state => mall med `{platshållare}`), `ReleaseNext::for(string $state, array $vars = []): string` (kastar `InvalidArgumentException` för okänt state och `LogicException` om en platshållare saknar värde).
  - `ReleaseStatus::forGame(Game $game): array` (`game`, `releases_enabled`, `release_targets`, `demo`, `targets`, `next`), `ReleaseStatus::describe(Release $release): array{state: string, next: string}` (för `promote_release`).
  - MCP-verktyget `get_release_status`.

Tillstånden (`state`) är: `releases_disabled`, `no_targets`, `no_demo_build`, `setup_open`, `ready_for_test`, `ready_for_production`, `awaiting_approval`, `queued_for_build`, `in_progress`, `succeeded`, `failed`, `cancelled`.

- [ ] **Steg 1: Skriv testerna.** `tests/Feature/Releases/ReleaseStatusTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Releases\ReleaseNext;
use App\Releases\ReleaseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesReleases;
use Tests\TestCase;

class ReleaseStatusTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private function status(Game $game): array
    {
        return ReleaseStatus::forGame($game->fresh());
    }

    public function test_game_without_targets_says_so(): void
    {
        $game = Game::create(['slug' => 'g', 'name' => 'G', 'is_active' => true, 'settings' => []]);

        $status = $this->status($game);

        $this->assertSame([], $status['release_targets']);
        $this->assertSame(ReleaseNext::for('no_targets'), $status['next']);
        $this->assertNull($status['demo']);
    }

    public function test_switch_off_overrides_everything(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game);
        $game->forceFill(['releases_enabled' => false])->save();

        $status = $this->status($game);

        $this->assertFalse($status['releases_enabled']);
        $this->assertSame(ReleaseNext::for('releases_disabled'), $status['next']);
        $this->assertSame('releases_disabled', $status['targets']['web']['state']);
    }

    public function test_open_setup_step_is_next(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->where('key', 'appstore_create_app')->update(['status' => 'open']);
        $this->demoBuild($game);

        $target = $this->status($game)['targets']['app_store'];

        $this->assertSame('setup_open', $target['state']);
        $this->assertStringContainsString('skapar appen i App Store Connect', $target['next']);
        $this->assertSame('open', collect($target['setup_steps'])->firstWhere('key', 'appstore_create_app')['status']);
    }

    public function test_no_demo_build_yet(): void
    {
        $game = $this->releasableGame();

        $this->assertSame('no_demo_build', $this->status($game)['targets']['web']['state']);
    }

    public function test_web_goes_straight_from_demo_to_production(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game, $this->sha('b'));

        $target = $this->status($game)['targets']['web'];

        $this->assertSame('ready_for_production', $target['state']);
        $this->assertStringContainsString('Commit '.$this->sha('b').' är testad i Demo', $target['next']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production, commit_sha: '.$this->sha('b').')', $target['next']);
    }

    public function test_app_store_flow_ready_for_test_then_production_then_done(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        $this->demoBuild($game, $this->sha('b'));

        $this->assertSame('ready_for_test', $this->status($game)['targets']['app_store']['state']);
        $this->assertStringContainsString('promote_release(target: app_store, stage: testflight)', $this->status($game)['targets']['app_store']['next']);

        $this->release($game, ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $target = $this->status($game)['targets']['app_store'];
        $this->assertSame('ready_for_production', $target['state']);
        $this->assertStringContainsString('testad i testflight', $target['next']);

        $this->release($game, ['target' => 'app_store', 'stage' => 'app_store', 'commit_sha' => $this->sha('b'), 'status' => Release::SUCCEEDED]);
        $this->assertSame('succeeded', $this->status($game)['targets']['app_store']['state']);
    }

    public function test_release_states_map_to_texts(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $cases = [
            [Release::AWAITING_APPROVAL, 'awaiting_approval', 'godkännas i Filament'],
            [Release::REQUESTED, 'queued_for_build', 'etapp 1b'],
            [Release::BUILDING, 'in_progress', 'pågår (building)'],
            [Release::PROCESSING, 'in_progress', 'pågår (processing)'],
        ];
        foreach ($cases as [$status, $state, $text]) {
            $release = $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => $status]);
            $target = $this->status($game)['targets']['web'];
            $this->assertSame($state, $target['state'], $status);
            $this->assertStringContainsString($text, $target['next'], $status);
            $this->assertStringContainsString($release->uuid, $target['next']);
            $release->update(['status' => Release::CANCELLED]);
        }
    }

    public function test_failed_and_cancelled_latest_release_show_the_reason(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::FAILED, 'last_error' => 'build exploded']);

        $target = $this->status($game)['targets']['web'];
        $this->assertSame('failed', $target['state']);
        $this->assertStringContainsString('build exploded.', $target['next']);

        $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::CANCELLED]);
        $this->assertSame('cancelled', $this->status($game)['targets']['web']['state']);
    }

    public function test_status_exposes_demo_and_releases_without_internal_ids(): void
    {
        $game = $this->releasableGame();
        $this->demoBuild($game, $this->sha('b'));
        $release = $this->release($game, ['commit_sha' => $this->sha('b'), 'status' => Release::AWAITING_APPROVAL]);

        $status = $this->status($game);

        $this->assertSame($this->sha('b'), $status['demo']['commit_sha']);
        $latest = $status['targets']['web']['stages']['web_production']['latest_release'];
        $this->assertSame($release->uuid, $latest['release']);
        $this->assertFalse($latest['approved']);
        $this->assertArrayNotHasKey('id', $latest);
        $this->assertArrayNotHasKey('requested_by', $latest);
    }

    public function test_next_templates_fail_loud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReleaseNext::for('no_such_state');
    }

    public function test_next_template_with_a_missing_variable_fails_loud(): void
    {
        $this->expectException(\LogicException::class);
        ReleaseNext::for('ready_for_test', ['commit' => 'x']);
    }

    public function test_every_template_placeholder_is_a_known_variable(): void
    {
        $known = ['commit', 'stage', 'target', 'release', 'status', 'error', 'instruction', 'etapp', 'test_stage'];
        foreach (ReleaseNext::TEMPLATES as $state => $template) {
            preg_match_all('/\{([a-z_]+)\}/', $template, $m);
            $this->assertEmpty(array_diff($m[1], $known), $state);
        }
    }
}
```

`tests/Feature/Mcp/GetReleaseStatusToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use Tests\Support\CreatesReleases;

class GetReleaseStatusToolTest extends McpTestCase
{
    use CreatesReleases;

    public function test_member_reads_status_with_next(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $member = $this->staffMember($game);

        $result = $this->callTool($member, 'get_release_status', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(['web'], $json['release_targets']);
        $this->assertSame('ready_for_production', $json['targets']['web']['state']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production', $json['next']);
        $this->assertTrue(McpAuditLog::where('tool', 'get_release_status')->where('outcome', 'ok')->exists());
    }

    public function test_non_member_gets_game_not_found(): void
    {
        $this->releasableGame('kattsvep');
        $outsider = $this->staffMember($this->game('ordjakt'));

        $result = $this->callTool($outsider, 'get_release_status', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Game not found', $result['text']);
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter='ReleaseStatusTest|GetReleaseStatusToolTest'`
Expected: FAIL.

- [ ] **Steg 3: Implementera `ReleaseNext`.** `app/Releases/ReleaseNext.php`:

```php
<?php

namespace App\Releases;

// ALLA `next`-texter finns här och bara här (spec: "i en enda klass så att faktabankens
// artiklar och API:t inte glider isär"). Faktabankens process-artiklar citerar varje mall
// ordagrant och ReleaseFactbankSyncTest jämför dem. Ändra en mall: ändra artikeln i samma commit.
final class ReleaseNext
{
    public const TEMPLATES = [
        'releases_disabled' => 'Släpp är avstängda just nu. Vänta tills Erik har slagit på dem igen.',
        'no_targets' => 'Spelet har inga produktionsmål. Be Erik sätta dem med set_release_targets.',
        'no_demo_build' => 'Det finns inget lyckat Demo-bygge ännu. Pusha till main och följ get_provisioning_status tills demo.state är live.',
        'setup_open' => 'Väntar på ett engångssteg: {instruction}',
        'ready_for_test' => 'Commit {commit} är testad i Demo. Be om {stage} med promote_release(target: {target}, stage: {stage}).',
        'ready_for_production' => 'Commit {commit} är testad i {test_stage}. Be om produktion med promote_release(target: {target}, stage: {stage}, commit_sha: {commit}).',
        'awaiting_approval' => 'Väntar på Erik: släppet {release} av commit {commit} ska godkännas i Filament.',
        'queued_for_build' => 'Släppet {release} av commit {commit} är registrerat, men bygget för {stage} byggs först i etapp {etapp}. Inget mer att göra nu.',
        'in_progress' => 'Släppet {release} av commit {commit} pågår ({status}). Fråga igen med get_release_status.',
        'succeeded' => 'Släppet {release} av commit {commit} är klart i {stage}. Inget mer att göra.',
        'failed' => 'Släppet {release} av commit {commit} misslyckades: {error} Åtgärda orsaken och begär ett nytt släpp med promote_release(target: {target}, stage: {stage}).',
        'cancelled' => 'Släppet {release} av commit {commit} avbröts. Begär ett nytt med promote_release(target: {target}, stage: {stage}).',
    ];

    /** @param array<string, string> $vars */
    public static function for(string $state, array $vars = []): string
    {
        $template = self::TEMPLATES[$state] ?? throw new \InvalidArgumentException("Unknown next state: {$state}");

        $text = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($vars, $state) {
            return $vars[$m[1]] ?? throw new \LogicException("Template {$state} needs variable {$m[1]}.");
        }, $template);

        return (string) $text;
    }
}
```

- [ ] **Steg 4: Implementera `ReleaseStatus`.** `app/Releases/ReleaseStatus.php`:

```php
<?php

namespace App\Releases;

use App\Models\DemoBuild;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;

// Det get_release_status svarar (R11: bara databasen, inga externa anrop).
final class ReleaseStatus
{
    public static function forGame(Game $game): array
    {
        $targets = $game->releaseTargets();
        $demo = DemoBuild::latestFor($game);
        $disabled = ReleaseSwitch::disabledReason($game);

        $perTarget = [];
        foreach ($targets as $target) {
            $perTarget[$target] = self::forTarget($game, $target, $demo, $disabled);
        }

        if ($disabled !== null) {
            $next = ReleaseNext::for('releases_disabled');
        } elseif ($targets === []) {
            $next = ReleaseNext::for('no_targets');
        } else {
            $next = collect($perTarget)->map(fn (array $t, string $name) => "[{$name}] {$t['next']}")->implode(' ');
        }

        return [
            'game' => $game->slug,
            'releases_enabled' => $disabled === null,
            'release_targets' => $targets,
            'demo' => $demo === null ? null : ['commit_sha' => $demo->commit_sha, 'deployed_at' => $demo->deployed_at->toIso8601String()],
            'targets' => $perTarget,
            'next' => $next,
        ];
    }

    /** @return array{state: string, next: string} */
    public static function describe(Release $release): array
    {
        [$state, $vars] = self::releaseState($release);

        return ['state' => $state, 'next' => ReleaseNext::for($state, $vars)];
    }

    private static function forTarget(Game $game, string $target, ?DemoBuild $demo, ?string $disabled): array
    {
        $steps = ReleaseSetupStep::where('game_id', $game->id)->where('target', $target)->orderBy('id')->get();
        $open = $steps->firstWhere('status', ReleaseSetupStep::OPEN);
        $test = ReleaseCatalog::testStage($target);
        $prod = ReleaseCatalog::productionStage($target);
        $testRelease = $test === 'demo' ? null : self::latest($game, $target, $test);
        $prodRelease = self::latest($game, $target, $prod);

        [$state, $vars] = match (true) {
            $disabled !== null => ['releases_disabled', []],
            $open !== null => ['setup_open', ['instruction' => $open->instruction]],
            $demo === null => ['no_demo_build', []],
            default => self::flowState($game, $target, $demo, $testRelease, $prodRelease, $test, $prod),
        };

        $stages = [];
        if ($test !== 'demo') {
            $stages[$test] = ['latest_release' => self::present($testRelease)];
        }
        $stages[$prod] = ['latest_release' => self::present($prodRelease)];

        return [
            'state' => $state,
            'next' => ReleaseNext::for($state, $vars),
            'setup_steps' => $steps->map(fn (ReleaseSetupStep $s) => ['key' => $s->key, 'status' => $s->status, 'instruction' => $s->instruction])->values()->all(),
            'stages' => $stages,
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function flowState(Game $game, string $target, DemoBuild $demo, ?Release $testRelease, ?Release $prodRelease, string $test, string $prod): array
    {
        // Något som pågår går först: det är det Claude ska vänta på.
        foreach ([$prodRelease, $testRelease] as $release) {
            if ($release !== null && $release->isActive()) {
                return self::releaseState($release);
            }
        }

        // Webbens teststeg är Demo-bygget; för butikerna är det senaste lyckade släppet i teststeget.
        $tested = $test === 'demo'
            ? $demo->commit_sha
            : Release::where('game_id', $game->id)->where('target', $target)->where('stage', $test)
                ->where('status', Release::SUCCEEDED)->orderByDesc('id')->value('commit_sha');

        if ($tested === null) {
            if ($testRelease !== null) { // misslyckat eller avbrutet
                return self::releaseState($testRelease);
            }

            return ['ready_for_test', ['commit' => $demo->commit_sha, 'stage' => $test, 'target' => $target]];
        }

        if ($prodRelease !== null && $prodRelease->commit_sha === $tested) {
            return self::releaseState($prodRelease);
        }

        return ['ready_for_production', ['commit' => $tested, 'test_stage' => $test === 'demo' ? 'Demo' : $test, 'target' => $target, 'stage' => $prod]];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function releaseState(Release $release): array
    {
        $state = match ($release->status) {
            Release::AWAITING_APPROVAL => 'awaiting_approval',
            Release::REQUESTED => 'queued_for_build',
            Release::BUILDING, Release::PROCESSING => 'in_progress',
            Release::SUCCEEDED => 'succeeded',
            Release::FAILED => 'failed',
            Release::CANCELLED => 'cancelled',
        };

        return [$state, [
            'release' => $release->uuid,
            'commit' => $release->commit_sha,
            'status' => $release->status,
            'stage' => $release->stage,
            'target' => $release->target,
            'etapp' => ReleaseCatalog::BUILD_ETAPP[$release->stage] ?? '?',
            'error' => $release->last_error === null || $release->last_error === '' ? 'orsak saknas.' : rtrim($release->last_error, '.').'.',
        ]];
    }

    private static function latest(Game $game, string $target, string $stage): ?Release
    {
        return Release::where('game_id', $game->id)->where('target', $target)->where('stage', $stage)->orderByDesc('id')->first();
    }

    private static function present(?Release $release): ?array
    {
        if ($release === null) {
            return null;
        }

        return [
            'release' => $release->uuid,
            'commit_sha' => $release->commit_sha,
            'status' => $release->status,
            'approved' => $release->approved_at !== null,
            'self_approved' => $release->self_approved,
            'external' => $release->external,
            'last_error' => $release->last_error,
            'created_at' => $release->created_at->toIso8601String(),
        ];
    }
}
```

- [ ] **Steg 5: Verktyget.** `app/Mcp/Tools/GetReleaseStatusTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Releases\ReleaseStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class GetReleaseStatusTool extends GameTool
{
    protected string $name = 'get_release_status';

    protected string $description = 'Show where a game is in the release flow: its production targets (web, app_store, play_store), the latest commit with a successful Demo build, the latest release per stage, open one-time setup steps, and "next" (what to do now, in Swedish). Reads Computercat\'s own records; receipts of Demo builds can be up to five minutes old. Any member.';

    public function schema(JsonSchema $schema): array
    {
        return $this->gameSchema($schema);
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        return $this->json(ReleaseStatus::forGame($game));
    }
}
```

I `app/Mcp/ComputercatServer.php`: lägg till `Tools\GetReleaseStatusTool::class,` direkt efter `Tools\RequestLiveTool::class,`.

- [ ] **Steg 6: Kör testerna.**

Run: `cd $WT && php artisan test --filter='ReleaseStatusTest|GetReleaseStatusToolTest|ToolInvariantsTest'`
Expected: PASS.

- [ ] **Steg 7: Commit.**

```bash
cd $WT && git add app/Releases app/Mcp tests && git commit -m "Släppflöde 1a: next-texter och get_release_status"
```

---

### Task 7: `promote_release` och `set_release_targets`

**Files:**
- Create: `app/Mcp/Tools/PromoteReleaseTool.php`, `app/Mcp/Tools/SetReleaseTargetsTool.php`
- Modify: `app/Mcp/ComputercatServer.php`
- Test: `tests/Feature/Mcp/PromoteReleaseToolTest.php`, `tests/Feature/Mcp/SetReleaseTargetsToolTest.php`

**Interfaces:**
- Consumes: `ReleaseService::promote`, `ReleaseNotifier::afterPromote`, `ReleaseStatus::describe`/`forGame`, `SetupSteps::sync`, `ReleaseCatalog`, `ReleaseRefused` (Task 4–6).
- Produces: MCP-verktygen `promote_release(game, target, stage, commit_sha?)` och `set_release_targets(game, targets)` (kommaseparerad sträng, R6). `SetReleaseTargetsTool::bundleIdFor(Game $game): string` (publik statisk).

- [ ] **Steg 1: Skriv testerna.** `tests/Feature/Mcp/PromoteReleaseToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\Release;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class PromoteReleaseToolTest extends McpTestCase
{
    use AssertsNoHttpInTransaction, CreatesReleases;

    private \App\Models\Game $game;

    private \App\Models\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
        $this->game = $this->releasableGame('kattsvep', ['web', 'app_store']);
        $this->owner = $this->ownerOf($this->game);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    private function promote(array $args, ?\App\Models\User $user = null): array
    {
        return $this->callTool($user ?? $this->owner, 'promote_release', ['game' => 'kattsvep'] + $args);
    }

    public function test_test_stage_defaults_to_the_latest_demo_commit_and_stops_at_requested(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'app_store', 'stage' => 'testflight']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame('requested', $json['status']);
        $this->assertSame($this->sha('b'), $json['commit_sha']);
        $this->assertSame('queued_for_build', $json['state']);
        // R1: inget byggs i 1a, och svaret säger det.
        $this->assertStringContainsString('etapp 2', $json['next']);
        $this->assertNull($json['notification']);
        Http::assertNothingSent();
    }

    public function test_first_production_release_awaits_approval_and_notifies_erik(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $json = json_decode($result['text'], true);
        $this->assertSame('awaiting_approval', $json['status']);
        $this->assertSame('sent', $json['notification']);
        $this->assertStringContainsString('godkännas i Filament', $json['next']);
        Http::assertSentCount(1);
        $log = McpAuditLog::where('tool', 'promote_release')->latest('id')->first();
        $this->assertSame('ok', $log->outcome);
        $this->assertSame($this->sha('b'), $log->arguments['commit_sha']);
        $this->assertSame('web_production', $log->arguments['stage']);
    }

    public function test_later_production_release_is_requested_and_still_tells_erik(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));
        $this->release($this->game, ['status' => Release::SUCCEEDED]);

        $json = json_decode($this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')])['text'], true);

        $this->assertSame('requested', $json['status']);
        $this->assertStringContainsString('etapp 1b', $json['next']);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Produktionssläpp begärt'));
    }

    public function test_slack_failure_does_not_fail_the_promotion_but_is_visible(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('nope', 500)]);
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('b')]);

        $this->assertTrue($result['ok']);
        $this->assertSame('failed', json_decode($result['text'], true)['notification']);
        $this->assertSame(1, Release::count());
    }

    public function test_only_the_owner_may_promote(): void
    {
        $this->demoBuild($this->game);
        $member = $this->staffMember($this->game);

        $result = $this->promote(['target' => 'app_store', 'stage' => 'testflight'], $member);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only the game owner', $result['text']);
        $this->assertSame(0, Release::count());
    }

    public function test_validation_errors(): void
    {
        $this->demoBuild($this->game);

        foreach ([
            ['target' => 'nonsense', 'stage' => 'testflight'],
            ['target' => 'web', 'stage' => 'demo'],
            ['target' => ['web'], 'stage' => 'web_production'],
            ['stage' => 'web_production'],
            ['target' => 'web'],
            ['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => ['x']],
        ] as $args) {
            $result = $this->promote($args);
            $this->assertFalse($result['ok'], json_encode($args));
            $this->assertStringContainsString('Validation failed', $result['text'], json_encode($args));
        }
        $this->assertSame(0, Release::count());
    }

    public function test_gate_refusals_are_errors_without_side_effects(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));

        $cases = [
            [['target' => 'web', 'stage' => 'web_production'], 'commit_sha is required for production stages'],
            [['target' => 'web', 'stage' => 'web_production', 'commit_sha' => 'HEAD'], '40-character'],
            [['target' => 'web', 'stage' => 'web_production', 'commit_sha' => $this->sha('c')], 'no successful Demo build'],
            [['target' => 'web', 'stage' => 'testflight'], 'cannot be promoted'],
            [['target' => 'play_store', 'stage' => 'play_internal'], 'not enabled'],
            [['target' => 'app_store', 'stage' => 'app_store', 'commit_sha' => $this->sha('b')], 'has not succeeded in testflight'],
        ];
        foreach ($cases as [$args, $needle]) {
            $result = $this->promote($args);
            $this->assertFalse($result['ok'], json_encode($args));
            $this->assertStringContainsString($needle, $result['text'], json_encode($args));
        }
        $this->assertSame(0, Release::count());
        Http::assertNothingSent();
    }

    public function test_empty_commit_sha_counts_as_omitted(): void
    {
        $this->demoBuild($this->game, $this->sha('b'));

        $result = $this->promote(['target' => 'app_store', 'stage' => 'testflight', 'commit_sha' => '']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame($this->sha('b'), json_decode($result['text'], true)['commit_sha']);
    }

    public function test_switch_off_stops_new_promotions(): void
    {
        $this->demoBuild($this->game);
        $this->game->forceFill(['releases_enabled' => false])->save();

        $result = $this->promote(['target' => 'app_store', 'stage' => 'testflight']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('switched off', $result['text']);
        $this->assertSame(0, Release::count());
    }

    public function test_second_call_while_pending_is_refused(): void
    {
        $this->demoBuild($this->game);
        $this->promote(['target' => 'app_store', 'stage' => 'testflight']);

        $again = $this->promote(['target' => 'app_store', 'stage' => 'testflight']);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already pending', $again['text']);
        $this->assertSame(1, Release::count());
    }
}
```

`tests/Feature/Mcp/SetReleaseTargetsToolTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use Tests\Support\CreatesReleases;

class SetReleaseTargetsToolTest extends McpTestCase
{
    use CreatesReleases;

    private Game $game;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = $this->game('kattsvep');
        $this->admin = $this->adminUser();
    }

    private function set(string $targets, ?User $user = null, string $slug = 'kattsvep'): array
    {
        return $this->callTool($user ?? $this->admin, 'set_release_targets', ['game' => $slug, 'targets' => $targets]);
    }

    public function test_admin_sets_targets_and_gets_setup_steps_and_a_bundle_id(): void
    {
        $result = $this->set('web,app_store');

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(['web', 'app_store'], $json['release_targets']);
        $this->assertSame('co.humblebrag.kattsvep', $json['bundle_id']);
        $this->assertContains('web_public_address', array_column($json['open_setup_steps'], 'key'));
        $this->assertContains('appstore_create_app', array_column($json['open_setup_steps'], 'key'));
        $this->assertStringContainsString('Väntar på ett engångssteg', $json['next']);
        $this->assertSame(['web', 'app_store'], $this->game->fresh()->releaseTargets());
        $this->assertSame('co.humblebrag.kattsvep', $this->game->fresh()->bundle_id);
    }

    public function test_web_only_sets_no_bundle_id(): void
    {
        $this->set('web');

        $this->assertNull($this->game->fresh()->bundle_id);
    }

    public function test_audit_log_keeps_the_targets(): void
    {
        $this->set('web, app_store');

        $log = \App\Models\McpAuditLog::where('tool', 'set_release_targets')->latest('id')->first();
        $this->assertSame('web, app_store', $log->arguments['targets']);
    }

    public function test_only_platform_admin_may_set_targets(): void
    {
        $owner = $this->ownerOf($this->game);

        $result = $this->set('web', $owner);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Only a platform admin', $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
    }

    public function test_invalid_input_is_a_validation_error(): void
    {
        foreach (['ios', 'web,nonsense', 'web;app_store', str_repeat('web,', 40)] as $bad) {
            $result = $this->set($bad);
            $this->assertFalse($result['ok'], $bad);
            $this->assertStringContainsString('Validation failed', $result['text'], $bad);
        }
        $result = $this->callTool($this->admin, 'set_release_targets', ['game' => 'kattsvep', 'targets' => ['web']]);
        $this->assertStringContainsString('Validation failed', $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
    }

    public function test_duplicates_collapse_and_empty_string_clears(): void
    {
        $this->set('web,web');
        $this->assertSame(['web'], $this->game->fresh()->releaseTargets());

        $result = $this->set('');

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertSame([], $this->game->fresh()->releaseTargets());
        $this->assertStringContainsString('inga produktionsmål', json_decode($result['text'], true)['next']);
    }

    public function test_calling_twice_keeps_done_steps(): void
    {
        $this->set('web');
        ReleaseSetupStep::where('key', 'web_public_address')->update(['status' => 'done']);

        $this->set('web,play_store');

        $this->assertSame('done', ReleaseSetupStep::where('key', 'web_public_address')->value('status'));
        $this->assertSame(1, ReleaseSetupStep::where('key', 'web_public_address')->count());
        $this->assertTrue(ReleaseSetupStep::where('key', 'play_create_app')->exists());
    }

    public function test_removing_a_target_with_a_pending_release_is_refused(): void
    {
        $this->set('web,app_store');
        $this->release($this->game, ['target' => 'app_store', 'stage' => 'testflight', 'status' => Release::REQUESTED]);

        $result = $this->set('web');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pending release', $result['text']);
        $this->assertSame(['web', 'app_store'], $this->game->fresh()->releaseTargets());

        // En avslutad release hindrar inte.
        Release::query()->update(['status' => Release::CANCELLED]);
        $this->assertTrue($this->set('web')['ok']);
    }

    public function test_colliding_bundle_ids_are_refused(): void
    {
        $this->set('app_store');
        $this->game('kattsv-ep');

        $result = $this->set('play_store', null, 'kattsv-ep');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bundle id', $result['text']);
        $this->assertSame([], Game::where('slug', 'kattsv-ep')->first()->releaseTargets());
        $this->assertNull(Game::where('slug', 'kattsv-ep')->value('bundle_id'));
        // Spelet utan mobila mål krockar inte.
        $this->assertTrue($this->set('web', null, 'kattsv-ep')['ok']);
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter='PromoteReleaseToolTest|SetReleaseTargetsToolTest'`
Expected: FAIL.

- [ ] **Steg 3: Implementera `PromoteReleaseTool`.** `app/Mcp/Tools/PromoteReleaseTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use App\Releases\ReleaseCatalog;
use App\Releases\ReleaseNotifier;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Releases\ReleaseStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Etapp 1a bygger inget (R1): verktyget skapar släppraden och svarar med var den
// står och vad som händer härnäst. Slack skickas efter att transaktionen är klar.
class PromoteReleaseTool extends GameTool
{
    protected string $name = 'promote_release';

    protected string $description = 'Promote an exact commit to the next release stage: testflight or play_internal (test), or web_production, app_store or play_production (production). Test stages default to the latest commit with a successful Demo build; production stages need an explicit commit_sha that already succeeded in the test stage. The first production release per game and target must be approved by Erik in Filament. Only the game owner. In this version the release is registered but not built yet; the answer says when building arrives. Check get_release_status first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'target' => $schema->string()->enum(ReleaseCatalog::TARGETS)->description('Production target: web, app_store or play_store.')->required(),
            'stage' => $schema->string()->enum(ReleaseCatalog::PROMOTABLE_STAGES)->description('Stage to promote to. Must belong to the target (web: web_production; app_store: testflight, app_store; play_store: play_internal, play_production).')->required(),
            'commit_sha' => $schema->string()->description('Full 40-character lower-case commit SHA. Optional for test stages, required for production stages.'),
        ];
    }

    // Slack-anropet får inte ske inne i en databastransaktion; ReleaseService har sin egen.
    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        // Tom sträng behandlas som utelämnad (som i övriga verktyg).
        if (array_key_exists('commit_sha', $args) && is_string($args['commit_sha']) && trim($args['commit_sha']) === '') {
            $args['commit_sha'] = null;
        }

        $validator = Validator::make($args, [
            'target' => ['bail', 'required', 'string', Rule::in(ReleaseCatalog::TARGETS)],
            'stage' => ['bail', 'required', 'string', Rule::in(ReleaseCatalog::PROMOTABLE_STAGES)],
            'commit_sha' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }
        $data = $validator->validated();

        try {
            $release = app(ReleaseService::class)->promote($game, $user, $data['target'], $data['stage'], $data['commit_sha'] ?? null);
        } catch (ReleaseRefused $e) {
            return Response::error($e->getMessage());
        }

        $described = ReleaseStatus::describe($release);

        return $this->json([
            'release' => $release->uuid,
            'target' => $release->target,
            'stage' => $release->stage,
            'commit_sha' => $release->commit_sha,
            'status' => $release->status,
            'state' => $described['state'],
            'next' => $described['next'],
            // Visar om Erik fick Slack-beskedet (sent, duplicate, failed, not_configured); null för teststeg.
            'notification' => app(ReleaseNotifier::class)->afterPromote($release),
        ]);
    }
}
```

- [ ] **Steg 4: Implementera `SetReleaseTargetsTool`.** `app/Mcp/Tools/SetReleaseTargetsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\ReleaseCatalog;
use App\Releases\ReleaseStatus;
use App\Releases\SetupSteps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

class SetReleaseTargetsTool extends GameTool
{
    protected string $name = 'set_release_targets';

    protected string $description = 'Set which production targets a game has: any of web, app_store, play_store, as a comma-separated string ("web,app_store"; empty string = none). Creates the one-time setup steps for each target and, for app_store/play_store, the game\'s bundle id (co.humblebrag.<slug without hyphens>). Only a platform admin. Removing a target that has a pending release is refused.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            // En sträng, inte en lista: revisionsloggen visar bara "[list: N items]" för listor (R6).
            'targets' => $schema->string()->description('Comma-separated targets: web, app_store, play_store. Empty string clears all targets.')->required(),
        ];
    }

    public static function bundleIdFor(Game $game): string
    {
        return 'co.humblebrag.'.str_replace('-', '', $game->slug);
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        if (! $user->isPlatformAdmin()) {
            return Response::error('Only a platform admin can set release targets.');
        }

        $validator = Validator::make($args, ['targets' => ['present', 'string', 'max:100']]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', $validator->validated()['targets'])), fn (string $t) => $t !== '')));
        $unknown = array_values(array_diff($targets, ReleaseCatalog::TARGETS));
        if ($unknown !== []) {
            return $this->validationFailed(['targets' => 'Unknown target(s): '.implode(', ', array_map(fn ($t) => mb_substr($t, 0, 30), $unknown)).'. Allowed: '.implode(', ', ReleaseCatalog::TARGETS).'.']);
        }

        $removed = array_diff($game->releaseTargets(), $targets);
        foreach ($removed as $target) {
            if (Release::active()->where('game_id', $game->id)->where('target', $target)->exists()) {
                return Response::error("Cannot remove {$target}: the game has a pending release for it. Wait for it to finish or cancel it first.");
            }
        }

        $needsBundleId = array_intersect($targets, ['app_store', 'play_store']) !== [];
        $bundleId = null;
        if ($needsBundleId && $game->bundle_id === null) {
            $bundleId = self::bundleIdFor($game);
            if (Game::where('bundle_id', $bundleId)->whereKeyNot($game->id)->exists()) {
                return Response::error("The bundle id {$bundleId} is already used by another game (slugs that differ only by hyphens collide). Ask Erik to pick a different id.");
            }
        }

        $game->forceFill(['release_targets' => $targets] + ($bundleId !== null ? ['bundle_id' => $bundleId] : []))->save();
        SetupSteps::sync($game);

        $game = $game->fresh();
        $status = ReleaseStatus::forGame($game);

        return $this->json([
            'saved' => true,
            'release_targets' => $game->releaseTargets(),
            'bundle_id' => $game->bundle_id,
            'open_setup_steps' => ReleaseSetupStep::where('game_id', $game->id)->whereIn('target', $targets)
                ->where('status', ReleaseSetupStep::OPEN)->orderBy('id')->get(['target', 'key', 'instruction'])->toArray(),
            'next' => $status['next'],
        ]);
    }
}
```

I `app/Mcp/ComputercatServer.php`: lägg till `Tools\PromoteReleaseTool::class,` och `Tools\SetReleaseTargetsTool::class,` efter `Tools\GetReleaseStatusTool::class,`, och utöka `$instructions` med meningen ` Follow a game from Demo to production with get_release_status (it says what to do next) and promote_release.`

- [ ] **Steg 5: Kör testerna.**

Run: `cd $WT && php artisan test --filter='PromoteReleaseToolTest|SetReleaseTargetsToolTest|ToolInvariantsTest'`
Expected: PASS. `ToolInvariantsTest` bevisar att de nya verktygen nekar icke-medlemmar, kräver `game`, inte läcker hemligheter och har tillåtna namn.

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add app/Mcp tests && git commit -m "Släppflöde 1a: promote_release och set_release_targets"
```

---

### Task 8: `request_live` blir en hänvisning, och backfill

**Files:**
- Modify (skrivs om): `app/Mcp/Tools/RequestLiveTool.php`
- Create: `database/migrations/2026_10_16_100005_backfill_release_targets_for_live_requests.php`
- Modify (skrivs om): `tests/Feature/Mcp/RequestLiveToolTest.php`
- Test: `tests/Feature/Releases/BackfillLiveRequestsTest.php`

**Interfaces:**
- Consumes: kolumnerna från Task 2, `mcp_audit_logs`.
- Produces: `request_live` svarar `{requested: false, message, next}` och gör ingenting (inget Slack, ingen skrivning). Migrationen sätter `release_targets` och det öppna steget `web_public_address` för spel med en `ok`-rad för `request_live` i revisionsloggen (R3).

- [ ] **Steg 1: Verifiera antagandet i R3 innan något skrivs.** Kör:

```bash
cd $WT && grep -rn "request_live" app database routes | grep -v "^app/Mcp/Tools/RequestLiveTool.php"
cd $WT && grep -rln "mcp_audit_logs\|McpAuditLog" app routes | head
```

Förväntat: ingen annan kod som läser eller skriver en "Live-begäran" (bara faktabankens artikel och bilagor), och ingen kod som rensar `mcp_audit_logs`. Hittas en rensning eller en annan registrering av Live-begäran: stanna och fråga Erik (AskUserQuestion) om backfillens källa.

- [ ] **Steg 2: Skriv testerna.** Ersätt `tests/Feature/Mcp/RequestLiveToolTest.php` med:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

// request_live är en tunn hänvisning till promote_release (spec Del 1): den ändrar och skickar ingenting.
class RequestLiveToolTest extends McpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Http::fake();
    }

    public function test_member_gets_a_pointer_and_nothing_happens(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);

        $result = $this->callTool($member, 'request_live', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertFalse($json['requested']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production', $json['message']);
        $this->assertStringContainsString('get_release_status', $json['message']);
        Http::assertNothingSent();
        $this->assertSame([], $game->fresh()->releaseTargets());
    }

    public function test_a_given_domain_is_not_silently_dropped(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);

        $json = json_decode($this->callTool($member, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se'])['text'], true);

        $this->assertStringContainsString('kattsvep.se was not recorded', $json['message']);
        $this->assertStringContainsString('Erik sets the public address', $json['message']);
    }

    public function test_non_member_is_denied_and_the_call_is_logged(): void
    {
        $this->game('kattsvep');
        $outsider = $this->staffMember($this->game('ordjakt'));

        $result = $this->callTool($outsider, 'request_live', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertTrue(McpAuditLog::where('tool', 'request_live')->where('outcome', 'denied')->exists());
    }
}
```

`tests/Feature/Releases/BackfillLiveRequestsTest.php`:

```php
<?php

namespace Tests\Feature\Releases;

use App\Models\Game;
use App\Models\McpAuditLog;
use App\Models\ReleaseSetupStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLiveRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function log(Game $game, string $tool, string $outcome): void
    {
        McpAuditLog::create(['user_id' => null, 'game_id' => $game->id, 'tool' => $tool, 'arguments' => [], 'outcome' => $outcome]);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_10_16_100005_backfill_release_targets_for_live_requests.php'))->up();
    }

    private function newGame(string $slug, array $extra = []): Game
    {
        return Game::create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true, 'settings' => []] + $extra);
    }

    public function test_only_games_with_an_ok_live_request_get_web_and_an_open_address_step(): void
    {
        $asked = $this->newGame('asked');
        $failedAsk = $this->newGame('failedask');
        $otherTool = $this->newGame('othertool');
        $never = $this->newGame('never');
        $this->log($asked, 'request_live', 'ok');
        $this->log($failedAsk, 'request_live', 'validation_error');
        $this->log($failedAsk, 'request_live', 'denied');
        $this->log($otherTool, 'get_game', 'ok');

        $this->migrate();

        $this->assertSame(['web'], $asked->fresh()->releaseTargets());
        $this->assertSame('open', ReleaseSetupStep::where('game_id', $asked->id)->where('key', 'web_public_address')->value('status'));
        foreach ([$failedAsk, $otherTool, $never] as $game) {
            $this->assertSame([], $game->fresh()->releaseTargets(), $game->slug);
            $this->assertSame(0, ReleaseSetupStep::where('game_id', $game->id)->count(), $game->slug);
        }
    }

    public function test_it_merges_with_existing_targets_and_can_run_twice(): void
    {
        $game = $this->newGame('asked');
        $game->forceFill(['release_targets' => ['app_store']])->save();
        $this->log($game, 'request_live', 'ok');
        $this->log($game, 'request_live', 'ok');

        $this->migrate();
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'done']);
        $this->migrate();

        $this->assertSame(['app_store', 'web'], $game->fresh()->releaseTargets());
        $this->assertSame(1, ReleaseSetupStep::where('game_id', $game->id)->count());
        // Ett redan klart steg öppnas inte igen.
        $this->assertSame('done', ReleaseSetupStep::where('game_id', $game->id)->value('status'));
    }

    public function test_no_log_rows_means_no_change(): void
    {
        $game = $this->newGame('quiet');

        $this->migrate();

        $this->assertSame([], $game->fresh()->releaseTargets());
    }
}
```

- [ ] **Steg 3: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter='RequestLiveToolTest|BackfillLiveRequestsTest'`
Expected: FAIL.

- [ ] **Steg 4: Skriv om `RequestLiveTool`.** `app/Mcp/Tools/RequestLiveTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

// Ersatt av promote_release (spec Del 1). Verktyget finns kvar som tunn väg in så att
// inget befintligt flöde slutar fungera, men ändrar och skickar ingenting.
class RequestLiveTool extends GameTool
{
    protected string $name = 'request_live';

    protected string $description = 'Replaced by promote_release. Does nothing except point you to promote_release(target: web, stage: web_production) and get_release_status. Kept so old instructions still get an answer.';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->gameSchema($schema),
            'domain' => $schema->string()->description('Ignored. Erik sets the public address as a one-time setup step.'),
        ];
    }

    protected function transactional(): bool
    {
        return false;
    }

    protected function run(Game $game, array $args, User $user): Response
    {
        $message = 'request_live is replaced. Call get_release_status to see what to do next, and promote_release(target: web, stage: web_production, commit_sha: <commit with a successful Demo build>) to ask for a production release. The first production release per game is approved by Erik; nothing is changed by this call.';

        $domain = $args['domain'] ?? null;
        if (is_string($domain) && trim($domain) !== '') {
            // Aldrig tyst: en angiven domän sparas inte längre här.
            $message .= ' The domain '.mb_substr(trim($domain), 0, 100).' was not recorded; Erik sets the public address as a setup step.';
        }

        return $this->json(['requested' => false, 'message' => $message]);
    }
}
```

- [ ] **Steg 5: Backfill-migrationen.** `database/migrations/2026_10_16_100005_backfill_release_targets_for_live_requests.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// request_live skrev aldrig något till databasen (bara Slack). Det enda beständiga spåret
// är revisionsloggen. Spel med en lyckad request_live får målet web och det öppna
// engångssteget web_public_address (plan 2026-10-14-slappflode-1a, R3). Ingen produktionsrätt
// följer av det: adressen måste sättas och första produktionssläppet godkännas av Erik.
// Texten i steget är en ögonblicksbild av SetupSteps::DEFINITIONS['web'] vid skrivtillfället;
// migrationer läser aldrig appens klasser.
return new class extends Migration
{
    private const WEB_STEP_KEY = 'web_public_address';

    private const WEB_STEP_TEXT = 'Erik sätter spelets publika webbadress (domänen) för produktionswebben.';

    public function up(): void
    {
        $gameIds = DB::table('mcp_audit_logs')->where('tool', 'request_live')->where('outcome', 'ok')
            ->whereNotNull('game_id')->distinct()->pluck('game_id');

        foreach ($gameIds as $gameId) {
            $game = DB::table('games')->where('id', $gameId)->first(['id', 'release_targets']);
            if ($game === null) {
                continue;
            }

            $targets = json_decode((string) $game->release_targets, true);
            $targets = is_array($targets) ? $targets : [];
            if (! in_array('web', $targets, true)) {
                $targets[] = 'web';
            }
            DB::table('games')->where('id', $game->id)->update(['release_targets' => json_encode(array_values($targets))]);

            // Unikt på (game_id, target, key): körs migrationen igen rörs ett klart steg inte.
            DB::table('release_setup_steps')->insertOrIgnore([
                'game_id' => $game->id,
                'target' => 'web',
                'key' => self::WEB_STEP_KEY,
                'status' => 'open',
                'instruction' => self::WEB_STEP_TEXT,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Avsiktligt tom: en återställning skulle också ta bort mål som Erik satt efteråt.
    }
};
```

- [ ] **Steg 6: Kör testerna.**

Run: `cd $WT && php artisan test --filter='RequestLiveToolTest|BackfillLiveRequestsTest|ToolInvariantsTest'`
Expected: PASS.

- [ ] **Steg 7: Kontroll mot verklig data inför deploy (ingen åtgärd nu).** Skriv i commit-meddelandets brödtext att prod-kontrollen görs vid deploy: `SELECT game_id, count(*) FROM mcp_audit_logs WHERE tool='request_live' AND outcome='ok' GROUP BY game_id` ska stämma med vilka spel Erik vet har bett om Live innan migrationen körs. Detta är en läsning som kräver Eriks OK och ingår i deploy-beslutet, inte i planen.

- [ ] **Steg 8: Commit.**

```bash
cd $WT && git add app/Mcp/Tools/RequestLiveTool.php database/migrations tests && git commit -m "Släppflöde 1a: request_live blir en hänvisning, backfill av web-mål"
```

---

### Task 9: Filament-sidan — godkännande och engångssteg

**Files:**
- Create: `app/Filament/Resources/GameResource/Pages/GameReleases.php`, `resources/views/filament/resources/game-resource/pages/game-releases.blade.php`
- Modify: `app/Filament/Resources/GameResource.php` (route `releases` och tabellåtgärd)
- Modify: `app/Releases/ReleaseNotifier.php` (`approvalUrl` pekar på resursens riktiga URL)
- Test: `tests/Feature/Filament/GameReleasesPageTest.php`

**Interfaces:**
- Consumes: `ReleaseService::approve/reject`, `ReleaseNotifier::afterApprove`, `ReleaseRefused` (Task 5), `Game::releases()/setupSteps()` (Task 2), `ReleaseStatus::forGame` (Task 6).
- Produces: Filament-sidan `GameResource::getUrl('releases', ['record' => $game])` med Livewire-metoderna `approve(int $releaseId)`, `reject(int $releaseId)`, `markStepDone(int $stepId)`, `toggleGameSwitch()`. Alla kräver platform admin.

- [ ] **Steg 1: Skriv testerna.** `tests/Feature/Filament/GameReleasesPageTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GameResource;
use App\Filament\Resources\GameResource\Pages\GameReleases;
use App\Models\Game;
use App\Models\Release;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;
use Tests\TestCase;

class GameReleasesPageTest extends TestCase
{
    use CreatesReleases, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);
        $this->game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($this->game);
    }

    private function awaiting(?User $requester = null): Release
    {
        return $this->release($this->game, ['status' => Release::AWAITING_APPROVAL, 'requested_by' => $requester?->id]);
    }

    private function page(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(GameReleases::class, ['record' => $this->game->getKey()]);
    }

    public function test_non_admin_staff_gets_403(): void
    {
        $staff = User::factory()->staff()->create();
        $this->game->members()->attach($staff, ['role' => 'owner']);

        $this->actingAs($staff)->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertForbidden();
        $this->actingAs($staff);
        Livewire::test(GameReleases::class, ['record' => $this->game->getKey()])->assertForbidden();
    }

    public function test_admin_sees_releases_steps_and_the_switch(): void
    {
        ReleaseSetupStep::where('key', 'web_public_address')->update(['status' => 'open']);
        $release = $this->awaiting();

        $html = $this->actingAs($this->adminUser())->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertOk()->getContent();

        $this->assertStringContainsString($release->commit_sha, $html);
        $this->assertStringContainsString('awaiting_approval', $html);
        $this->assertStringContainsString('web_public_address', $html);
    }

    public function test_html_in_stored_values_is_escaped(): void
    {
        $this->release($this->game, ['status' => Release::FAILED, 'last_error' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->adminUser())->get(GameResource::getUrl('releases', ['record' => $this->game]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_admin_approves_and_it_is_recorded(): void
    {
        $admin = $this->adminUser();
        $release = $this->awaiting($this->ownerOf($this->game));

        $this->page($admin)->call('approve', $release->id);

        $fresh = $release->fresh();
        $this->assertSame(Release::REQUESTED, $fresh->status);
        $this->assertSame($admin->id, $fresh->approved_by);
        $this->assertFalse($fresh->self_approved);
        Http::assertNothingSent();
    }

    public function test_approving_your_own_request_is_flagged_and_reported(): void
    {
        $admin = $this->adminUser();
        $release = $this->awaiting($admin);

        $this->page($admin)->call('approve', $release->id);

        $this->assertTrue($release->fresh()->self_approved);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'self_approved'));
    }

    public function test_a_refused_approval_changes_nothing_and_does_not_crash(): void
    {
        $release = $this->awaiting();
        $this->game->forceFill(['releases_enabled' => false])->save();

        $this->page($this->adminUser())->call('approve', $release->id);

        $this->assertSame(Release::AWAITING_APPROVAL, $release->fresh()->status);
    }

    public function test_release_of_another_game_cannot_be_approved_from_this_page(): void
    {
        $other = $this->releasableGame('ordjakt', ['web']);
        $this->demoBuild($other);
        $foreign = $this->release($other, ['status' => Release::AWAITING_APPROVAL]);

        $this->page($this->adminUser())->call('approve', $foreign->id);

        $this->assertSame(Release::AWAITING_APPROVAL, $foreign->fresh()->status);
    }

    public function test_admin_rejects(): void
    {
        $release = $this->awaiting();

        $this->page($this->adminUser())->call('reject', $release->id);

        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_admin_marks_a_step_done_but_only_for_this_game(): void
    {
        $admin = $this->adminUser();
        $mine = ReleaseSetupStep::where('game_id', $this->game->id)->first();
        $mine->update(['status' => 'open']);
        $other = $this->releasableGame('ordjakt', ['web']);
        $theirs = ReleaseSetupStep::where('game_id', $other->id)->first();
        $theirs->update(['status' => 'open']);

        $page = $this->page($admin);
        $page->call('markStepDone', $mine->id);
        $page->call('markStepDone', $theirs->id);

        $this->assertSame('done', $mine->fresh()->status);
        $this->assertSame($admin->id, $mine->fresh()->done_by);
        $this->assertNotNull($mine->fresh()->done_at);
        $this->assertSame('open', $theirs->fresh()->status);
    }

    public function test_admin_toggles_the_game_switch(): void
    {
        $page = $this->page($this->adminUser());

        $page->call('toggleGameSwitch');
        $this->assertFalse($this->game->fresh()->releases_enabled);

        $page->call('toggleGameSwitch');
        $this->assertTrue($this->game->fresh()->releases_enabled);
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter=GameReleasesPageTest`
Expected: FAIL (sidan och routen finns inte).

- [ ] **Steg 3: Sidan.** `app/Filament/Resources/GameResource/Pages/GameReleases.php`:

```php
<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use App\Models\ReleaseSetupStep;
use App\Models\User;
use App\Releases\ReleaseNotifier;
use App\Releases\ReleaseRefused;
use App\Releases\ReleaseService;
use App\Releases\ReleaseStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

// Godkännande av första produktionssläppet och avbockning av engångssteg (R10: bara platform admin).
// Godkännande sker bara här, aldrig via MCP. Varje Livewire-metod kontrollerar behörigheten själv,
// eftersom metoderna kan anropas av vem som helst som kan mounta sidan.
class GameReleases extends Page
{
    use InteractsWithRecord;

    protected static string $resource = GameResource::class;

    protected static string $view = 'filament.resources.game-resource.pages.game-releases';

    protected static ?string $title = 'Releases';

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->isPlatformAdmin() === true;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getStatus(): array
    {
        return ReleaseStatus::forGame($this->getRecord()->fresh());
    }

    public function getReleases()
    {
        return $this->getRecord()->releases()->orderByDesc('id')->limit(50)->get();
    }

    public function getSteps()
    {
        return $this->getRecord()->setupSteps()->orderBy('target')->orderBy('id')->get();
    }

    public function approve(int $releaseId): void
    {
        $admin = $this->admin();
        $release = $this->getRecord()->releases()->whereKey($releaseId)->first();
        if ($release === null) {
            Notification::make()->danger()->title('Okänt släpp för det här spelet.')->send();

            return;
        }

        try {
            $approved = app(ReleaseService::class)->approve($release, $admin);
        } catch (ReleaseRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        app(ReleaseNotifier::class)->afterApprove($approved);
        Notification::make()->success()->title('Släppet är godkänt.')->send();
    }

    public function reject(int $releaseId): void
    {
        $admin = $this->admin();
        $release = $this->getRecord()->releases()->whereKey($releaseId)->first();
        if ($release === null) {
            Notification::make()->danger()->title('Okänt släpp för det här spelet.')->send();

            return;
        }

        try {
            app(ReleaseService::class)->reject($release, $admin);
        } catch (ReleaseRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Släppet är avslaget.')->send();
    }

    public function markStepDone(int $stepId): void
    {
        $admin = $this->admin();
        $step = $this->getRecord()->setupSteps()->whereKey($stepId)->first();
        if ($step === null) {
            Notification::make()->danger()->title('Okänt engångssteg för det här spelet.')->send();

            return;
        }

        $step->update(['status' => ReleaseSetupStep::DONE, 'done_by' => $admin->id, 'done_at' => now()]);
        Notification::make()->success()->title('Engångssteget är avbockat.')->send();
    }

    public function toggleGameSwitch(): void
    {
        $this->admin();
        $game = $this->getRecord();
        $game->forceFill(['releases_enabled' => ! $game->releases_enabled])->save();
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
```

`resources/views/filament/resources/game-resource/pages/game-releases.blade.php`:

```blade
<x-filament-panels::page>
    @php($status = $this->getStatus())
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Status</x-slot>
            <dl class="space-y-1 text-sm">
                <div><dt class="inline font-medium">Mål:</dt> <dd class="inline">{{ $status['release_targets'] === [] ? 'inga' : implode(', ', $status['release_targets']) }}</dd></div>
                <div><dt class="inline font-medium">Senaste Demo-commit:</dt> <dd class="inline font-mono">{{ $status['demo']['commit_sha'] ?? 'inget lyckat Demo-bygge' }}</dd></div>
                <div><dt class="inline font-medium">Brytare för spelet:</dt> <dd class="inline">{{ $record->releases_enabled ? 'på' : 'av' }}</dd></div>
                <div><dt class="inline font-medium">Global brytare (RELEASES_ENABLED):</dt> <dd class="inline">{{ config('releases.enabled') === true ? 'på' : 'av' }}</dd></div>
                <div><dt class="inline font-medium">Nästa:</dt> <dd class="inline">{{ $status['next'] }}</dd></div>
            </dl>
            <div class="mt-3">
                <x-filament::button color="gray" wire:click="toggleGameSwitch" wire:confirm="Slå om brytaren för det här spelet?">
                    {{ $record->releases_enabled ? 'Stäng av släpp för spelet' : 'Slå på släpp för spelet' }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Engångssteg</x-slot>
            @forelse ($this->getSteps() as $step)
                <div class="flex items-center justify-between gap-4 border-b border-gray-200 py-2 text-sm dark:border-white/10">
                    <div>
                        <span class="font-mono">{{ $step->target }}/{{ $step->key }}</span>
                        <span class="ml-2">{{ $step->status === 'done' ? '[klart]' : '[öppet]' }}</span>
                        <div>{{ $step->instruction }}</div>
                        @if ($step->status === 'done')
                            <div class="text-xs text-gray-500">{{ $step->done_at?->toDateTimeString() }} av {{ $step->doneBy?->email ?? 'okänd' }}</div>
                        @endif
                    </div>
                    @if ($step->status === 'open')
                        <x-filament::button size="sm" wire:click="markStepDone({{ $step->id }})" wire:confirm="Har steget gjorts?">Bocka av</x-filament::button>
                    @endif
                </div>
            @empty
                <p class="text-sm">Inga engångssteg. Sätt mål med set_release_targets.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Släpp</x-slot>
            @forelse ($this->getReleases() as $release)
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 py-2 text-sm dark:border-white/10">
                    <div>
                        <div><span class="font-mono">{{ $release->target }}/{{ $release->stage }}</span> <span class="ml-2 font-medium">{{ $release->status }}</span>
                            @if ($release->self_approved) <span class="ml-2">[self_approved]</span> @endif</div>
                        <div class="font-mono text-xs">{{ $release->commit_sha }}</div>
                        <div class="text-xs text-gray-500">{{ $release->created_at->toDateTimeString() }} av {{ $release->requester?->email ?? 'okänd' }}@if ($release->approved_at), godkänt {{ $release->approved_at->toDateTimeString() }} av {{ $release->approver?->email ?? 'okänd' }}@endif</div>
                        @if ($release->last_error)
                            <div class="text-xs">{{ $release->last_error }}</div>
                        @endif
                    </div>
                    @if ($release->status === 'awaiting_approval')
                        <div class="flex gap-2">
                            <x-filament::button size="sm" wire:click="approve({{ $release->id }})" wire:confirm="Godkänn commit {{ $release->commit_sha }} till {{ $release->stage }}?">Godkänn</x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="reject({{ $release->id }})" wire:confirm="Avslå släppet?">Avslå</x-filament::button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm">Inga släpp ännu.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
```

I `app/Filament/Resources/GameResource.php`: lägg till i `getPages()` raden `'releases' => Pages\GameReleases::route('/{record}/releases'),` efter `devkit`-raden, och lägg till i tabellens `->actions([...])` efter `devkit`-åtgärden:

```php
                Tables\Actions\Action::make('releases')
                    ->label('Releases')
                    ->icon('heroicon-o-rocket-launch')
                    ->visible(fn () => auth()->user()?->isPlatformAdmin() === true)
                    ->url(fn (Game $record) => static::getUrl('releases', ['record' => $record])),
```

I `app/Releases/ReleaseNotifier.php`: byt `approvalUrl` till

```php
    public function approvalUrl(Game $game): string
    {
        return GameResource::getUrl('releases', ['record' => $game->getKey()], true, 'admin');
    }
```

och lägg tillbaka `use App\Filament\Resources\GameResource;`.

- [ ] **Steg 4: Kör testerna.**

Run: `cd $WT && php artisan test --filter='GameReleasesPageTest|ReleaseNotifierTest|GameDevKitTest'`
Expected: PASS. Om `assertForbidden()` på Livewire-testet inte stämmer med Filament 3.3:s beteende: kontrollera i `vendor/filament/filament/src/Pages/Page.php` (rad ~86, `canAccess`) hur 403 kastas och byt `assertForbidden()` mot det som Livewire-testet faktiskt ger (`assertStatus(403)` eller `expectException(HttpException::class)`); testets poäng är att en icke-admin aldrig når sidan.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Filament app/Releases/ReleaseNotifier.php resources/views/filament tests/Feature/Filament/GameReleasesPageTest.php && git commit -m "Släppflöde 1a: Filament-sida för godkännande och engångssteg"
```

---

### Task 10: Bevakning i nattkontrollen

**Files:**
- Modify: `app/Console/Commands/CheckProvisioning.php`
- Test: `tests/Feature/Console/CheckReleasesTest.php`

**Interfaces:**
- Consumes: `Release::expireStale()`, `Release`, `ReleaseSetupStep`, `config('releases.*')` (Task 2).
- Produces: `CheckProvisioning::checkReleases()` (privat) som lägger till avvikelser med nycklarna `release:{uuid}:expired`, `release:{uuid}:stuck` och `setup:{slug}:{target}`. Avvikelserna larmar i Slack som övriga.

- [ ] **Steg 1: Skriv testerna.** `tests/Feature/Console/CheckReleasesTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Release;
use App\Models\ReleaseSetupStep;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Provisioning\ProvisioningTestCase;
use Tests\Support\CreatesReleases;
use Tests\Support\ProvisioningFakes;

class CheckReleasesTest extends ProvisioningTestCase
{
    use CreatesReleases;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'coolify.test/api/v1/projects/demo-project-uuid/production' => Http::response(ProvisioningFakes::coolifyProject([])),
            ProvisioningFakes::SLACK => Http::response('ok'),
        ]);
    }

    public function test_healthy_releases_are_silent(): void
    {
        $game = $this->releasableGame();
        $this->release($game, ['status' => Release::BUILDING]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_stuck_build_is_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::BUILDING]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(121)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain($release->uuid)->assertExitCode(1);
        Http::assertSent(fn ($r) => $r->url() === ProvisioningFakes::SLACK && str_contains($r['text'], 'building'));
    }

    public function test_a_build_just_inside_the_limit_is_not_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::PROCESSING]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subMinutes(119)]);

        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }

    public function test_expired_pending_release_is_cancelled_and_reported(): void
    {
        $game = $this->releasableGame();
        $release = $this->release($game, ['status' => Release::AWAITING_APPROVAL]);
        Release::whereKey($release->id)->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('avbröts')->assertExitCode(1);

        $this->assertSame(Release::CANCELLED, $release->fresh()->status);
    }

    public function test_old_open_setup_steps_are_reminded_once_per_game_and_target(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'open', 'created_at' => now()->subDays(8)]);

        $this->artisan('computercat:check-provisioning')->expectsOutputToContain('kattsvep')->assertExitCode(1);

        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === ProvisioningFakes::SLACK));
    }

    public function test_recent_open_steps_and_steps_of_removed_targets_are_ignored(): void
    {
        $game = $this->releasableGame('kattsvep', ['app_store']);
        ReleaseSetupStep::where('game_id', $game->id)->update(['status' => 'open']); // nya
        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);

        ReleaseSetupStep::where('game_id', $game->id)->update(['created_at' => now()->subDays(30)]);
        $game->forceFill(['release_targets' => []])->save(); // målet är borttaget
        $this->artisan('computercat:check-provisioning')->expectsOutput('0 avvikelse(r).')->assertExitCode(0);
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter=CheckReleasesTest`
Expected: FAIL.

- [ ] **Steg 3: Implementera.** I `app/Console/Commands/CheckProvisioning.php`: lägg till `use App\Models\Release;` och `use App\Models\ReleaseSetupStep;`, anropa `$this->checkReleases();` direkt efter `$this->checkStuck();` i `handle`, och lägg till metoden före `check()`:

```php
    // Släppflödet (spec Del 3, "Bevakning"). Kontrollen att produktionswebben serverar den
    // släppta commiten byggs i etapp 1b, när det finns en produktionsweb.
    private function checkReleases(): void
    {
        // Utgångna släpp avbryts (spärr 7); Erik får veta det eftersom ingen annan märker det.
        foreach (Release::expireStale() as $release) {
            $this->deviations["release:{$release->uuid}:expired"] = "Släppet {$release->uuid} ({$release->target}/{$release->stage}, commit {$release->commit_sha}) avbröts: inget beslut eller bygge inom ".config('releases.ttl_days').' dygn.';
        }

        $cutoff = now()->subMinutes((int) config('releases.stuck_minutes'));
        foreach (Release::with('game')->whereIn('status', [Release::BUILDING, Release::PROCESSING])->where('updated_at', '<', $cutoff)->get() as $release) {
            $this->deviations["release:{$release->uuid}:stuck"] = "Släppet {$release->uuid} ({$release->game->slug} {$release->target}/{$release->stage}) har stått i {$release->status} sedan {$release->updated_at}.";
        }

        $reminderCutoff = now()->subDays((int) config('releases.setup_reminder_days'));
        $open = ReleaseSetupStep::with('game')->where('status', ReleaseSetupStep::OPEN)->where('created_at', '<', $reminderCutoff)->orderBy('id')->get();
        foreach ($open->groupBy(fn (ReleaseSetupStep $s) => $s->game_id.':'.$s->target) as $steps) {
            $game = $steps->first()->game;
            $target = $steps->first()->target;
            // Steg för ett mål som tagits bort ska inte påminna.
            if (! in_array($target, $game->releaseTargets(), true)) {
                continue;
            }
            $this->deviations["setup:{$game->slug}:{$target}"] = "Spelet {$game->slug} har haft öppna engångssteg för {$target} i över ".config('releases.setup_reminder_days').' dagar: '.$steps->pluck('key')->implode(', ').'.';
        }
    }

```

- [ ] **Steg 4: Kör testerna.**

Run: `cd $WT && php artisan test --filter='CheckReleasesTest|CheckProvisioningTest'`
Expected: PASS.

- [ ] **Steg 5: Commit.**

```bash
cd $WT && git add app/Console/Commands/CheckProvisioning.php tests/Feature/Console/CheckReleasesTest.php && git commit -m "Släppflöde 1a: nattkontrollen bevakar släpp och engångssteg"
```

---

### Task 11: Faktabanksartiklar och testet som jämför dem med `next`

**Files:**
- Create: `resources/faktabank/process/process-slappflode.md`, `resources/faktabank/process/process-slapp-engangssteg.md`
- Test: `tests/Feature/ReleaseFactbankSyncTest.php`

**Interfaces:**
- Consumes: `ReleaseNext::TEMPLATES` (Task 6), `SetupSteps::DEFINITIONS` (Task 2).
- Produces: två artiklar som citerar varje `next`-mall och varje engångssteg ordagrant, och ett test som fäller sviten om de glider isär.

Faktabanken finns på `feat/faktabank` och är inte mergad. Artiklarna följer dess format (front matter, H2-rubrikerna `Regel`, `Varför`, `Så gör du`, `Källa` i den ordningen, `updated` citerad, källrader som finns i repot). Testet läser filerna direkt och är därför oberoende av `FactBank`-klassen; en andra test använder `FactBank` bara om klassen finns. Katalogen `resources/faktabank/process/` skapas av denna task om den inte finns, och merge mot `feat/faktabank` kolliderar inte (andra filnamn).

- [ ] **Steg 1: Skriv testet.** `tests/Feature/ReleaseFactbankSyncTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Releases\ReleaseNext;
use App\Releases\SetupSteps;
use Tests\TestCase;

// Spec Del 1: "Texterna för next finns i en enda klass så att faktabankens artiklar och
// API:t inte glider isär; ett test jämför dem." Testet läser filerna direkt, så det
// fungerar både före och efter att faktabanken (feat/faktabank) är mergad.
class ReleaseFactbankSyncTest extends TestCase
{
    private function processArticles(): array
    {
        $files = glob(resource_path('faktabank/process/*.md')) ?: [];
        $this->assertNotEmpty($files, 'resources/faktabank/process saknar artiklar; släppflödets artiklar ska finnas.');

        return array_combine(array_map('basename', $files), array_map('file_get_contents', $files));
    }

    private function allText(): string
    {
        return implode("\n", $this->processArticles());
    }

    public function test_the_two_release_articles_exist(): void
    {
        $articles = $this->processArticles();

        $this->assertArrayHasKey('process-slappflode.md', $articles);
        $this->assertArrayHasKey('process-slapp-engangssteg.md', $articles);
    }

    public function test_every_next_state_and_its_exact_text_is_in_the_articles(): void
    {
        $text = $this->allText();

        foreach (ReleaseNext::TEMPLATES as $state => $template) {
            $this->assertStringContainsString("`{$state}`", $text, "Artikeln saknar tillståndet {$state}.");
            $this->assertStringContainsString($template, $text, "Artikelns text för {$state} skiljer sig från ReleaseNext.");
        }
    }

    public function test_every_setup_step_and_its_instruction_is_in_the_articles(): void
    {
        $text = $this->allText();

        foreach (SetupSteps::DEFINITIONS as $target => $steps) {
            foreach ($steps as $key => $instruction) {
                $this->assertStringContainsString("`{$key}`", $text, "Artikeln saknar engångssteget {$key} ({$target}).");
                $this->assertStringContainsString($instruction, $text, "Artikelns text för {$key} skiljer sig från SetupSteps.");
            }
        }
    }

    public function test_articles_mention_the_tools_and_the_approval_rule(): void
    {
        $text = $this->allText();

        foreach (['get_release_status', 'promote_release', 'set_release_targets', 'Filament'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
    }

    // Hänvisningen får inte peka på det ersatta verktyget som sättet att gå live. Artikeln
    // process-ide-till-live finns bara om faktabanken är mergad; då ska den ha uppdaterats.
    public function test_the_old_go_live_article_does_not_send_claude_to_the_replaced_tool(): void
    {
        $path = resource_path('faktabank/process/process-ide-till-live.md');
        if (! is_file($path)) {
            $this->markTestSkipped('process-ide-till-live.md finns inte (feat/faktabank är inte mergad).');
        }

        $this->assertStringContainsString('promote_release', (string) file_get_contents($path), 'Uppdatera steg 4 i process-ide-till-live: Live går via promote_release.');
    }

    // Faktabankens egen validering (front matter, rubriker, källrader) gäller bara om klassen finns.
    public function test_articles_pass_the_factbank_validation_when_it_exists(): void
    {
        if (! class_exists(\App\Services\FactBank::class)) {
            $this->markTestSkipped('FactBank finns inte (feat/faktabank är inte mergad).');
        }

        $ids = array_column((new \App\Services\FactBank)->all('process'), 'id');

        $this->assertContains('process-slappflode', $ids);
        $this->assertContains('process-slapp-engangssteg', $ids);
    }
}
```

- [ ] **Steg 2: Kör och se att det faller.**

Run: `cd $WT && php artisan test --filter=ReleaseFactbankSyncTest`
Expected: FAIL (katalogen saknar artiklar). De två `markTestSkipped` visas som skipped.

- [ ] **Steg 3: Skriv artiklarna.** Nämn inte verktyget för Live-förfrågan vid namn och använd inte ordet "request_live" (faktabankens sökning rankar artikeln `process-ide-till-live` på det; `FactBankContentTest` på `feat/faktabank` förväntar sig den artikeln först för den frågan). Varje mall står på en egen rad, ordagrant, utan radbrytning mitt i.

`resources/faktabank/process/process-slappflode.md`:

````markdown
---
id: process-slappflode
title: Släppflödet från Demo till telefon och produktion
topic: process
tags: [process, slapp, release, promote_release, get_release_status, testflight, produktion, godkannande]
summary: get_release_status säger vad som gäller och vad nästa steg är. promote_release befordrar en exakt commit; första produktionssläppet per mål godkänns av Erik i Filament.
updated: "2026-10-14"
---

## Regel

1. Fråga `get_release_status` först. Svaret har en rad `next` som säger vad du ska göra nu; gör det och inget annat.
2. En ny commit på main bygger Demo som vanligt. Den går vidare först när du ber om det med `promote_release(target, stage, commit_sha?)`. Bara spelets ägare kan göra det.
3. Teststeg (`testflight`, `play_internal`) tar som standard senaste commit med lyckat Demo-bygge. Produktionssteg (`web_production`, `app_store`, `play_production`) kräver att du anger `commit_sha`, och den commiten måste ha lyckats i teststeget (för webben: ha ett lyckat Demo-bygge).
4. Det första produktionssläppet per spel och mål godkänns av Erik i Filament. Du kan inte godkänna det själv och ska inte försöka. Därefter kan ägaren släppa utan nytt godkännande.
5. Mål (`web`, `app_store`, `play_store`) och engångssteg sätts av Erik. Ett mål med öppna engångssteg kan inte befordras.

## Varför

Produktion är ett beslut som en människa tar första gången. Computercat stoppar det som inte är tillåtet och säger vad nästa steg är, så att du inte behöver gissa. Samma text står i API-svaret och här.

## Så gör du

Tillstånden i `get_release_status` och vad `next` säger i varje (texten är ordagrant den som API:t returnerar, `{...}` fylls i av API:t):

- `releases_disabled`: Släpp är avstängda just nu. Vänta tills Erik har slagit på dem igen.
- `no_targets`: Spelet har inga produktionsmål. Be Erik sätta dem med set_release_targets.
- `no_demo_build`: Det finns inget lyckat Demo-bygge ännu. Pusha till main och följ get_provisioning_status tills demo.state är live.
- `setup_open`: Väntar på ett engångssteg: {instruction}
- `ready_for_test`: Commit {commit} är testad i Demo. Be om {stage} med promote_release(target: {target}, stage: {stage}).
- `ready_for_production`: Commit {commit} är testad i {test_stage}. Be om produktion med promote_release(target: {target}, stage: {stage}, commit_sha: {commit}).
- `awaiting_approval`: Väntar på Erik: släppet {release} av commit {commit} ska godkännas i Filament.
- `queued_for_build`: Släppet {release} av commit {commit} är registrerat, men bygget för {stage} byggs först i etapp {etapp}. Inget mer att göra nu.
- `in_progress`: Släppet {release} av commit {commit} pågår ({status}). Fråga igen med get_release_status.
- `succeeded`: Släppet {release} av commit {commit} är klart i {stage}. Inget mer att göra.
- `failed`: Släppet {release} av commit {commit} misslyckades: {error} Åtgärda orsaken och begär ett nytt släpp med promote_release(target: {target}, stage: {stage}).
- `cancelled`: Släppet {release} av commit {commit} avbröts. Begär ett nytt med promote_release(target: {target}, stage: {stage}).

Ett släpp som ingen rört på sju dygn avbryts av sig självt. Fråga en människa när något fastnar i stället för att försöka igen i oändlighet.

## Källa

- app/Releases/ReleaseNext.php
- app/Mcp/Tools/GetReleaseStatusTool.php
- app/Mcp/Tools/PromoteReleaseTool.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
````

`resources/faktabank/process/process-slapp-engangssteg.md`:

````markdown
---
id: process-slapp-engangssteg
title: Engångssteg före första släpp till en butik eller domän
topic: process
tags: [process, engangssteg, appstore, play, domän, bundle-id, release]
summary: Varje produktionsmål har engångssteg som Erik eller Computercat gör en gång per spel. Öppna steg stoppar befordran till målet.
updated: "2026-10-14"
---

## Regel

1. Du kan inte göra engångsstegen. Säg till Erik att de väntar och vänta; `get_release_status` visar vilket steg som är öppet.
2. Gissa aldrig att ett steg är klart. Bara Erik markerar det som klart i Filament.
3. Försök inte komma runt ett öppet steg genom att befordra ett annat mål eller steg.

## Varför

App Store Connect och Google Play låter inte ett API skapa själva appen, och den publika adressen är ett beslut. Sådant görs en gång för hand, och först därefter är det meningsfullt att bygga.

## Så gör du

Stegen per mål och deras text (ordagrant som i `get_release_status`):

- `web_public_address`: Erik sätter spelets publika webbadress (domänen) för produktionswebben.
- `appstore_bundle_id`: Computercat registrerar spelets bundle-id i App Store Connect via API (byggs i etapp 2).
- `appstore_create_app`: Erik skapar appen i App Store Connect med spelets bundle-id.
- `appstore_privacy_and_age`: Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect.
- `play_create_app`: Erik skapar appen i Play Console med spelets paketnamn.
- `play_store_listing`: Erik fyller i innehållsklassning, dataskydd, målgrupp och butikstext i Play Console.
- `play_app_signing`: Erik aktiverar Play App Signing för appen i Play Console.

Bundle-id och paketnamn är `co.humblebrag.` följt av spelets slug utan bindestreck.

## Källa

- app/Releases/SetupSteps.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
- app/Mcp/Tools/GetReleaseStatusTool.php
````

Om faktabankens validering (efter merge) avvisar något i artiklarna (tagg-längd, sammanfattning över 200 tecken, `updated`-format): rätta artikeln, inte valideringen.

- [ ] **Steg 4: Kör testerna.**

Run: `cd $WT && php artisan test --filter=ReleaseFactbankSyncTest`
Expected: PASS (de två faktabank-beroende testerna skipped om `feat/faktabank` inte är mergad).

- [ ] **Steg 5 (om faktabanken är mergad till main när du når hit): kör faktabankens egna tester.**

Run: `cd $WT && git merge-base --is-ancestor feat/faktabank main && php artisan test --filter='FactBank|FactTools'`
Expected: PASS, inklusive `FactBankContentTest::test_common_questions_find_the_right_article` (`request_live` ska fortfarande ge `process-ide-till-live` först). Är grenen inte mergad: hoppa över steget och skriv i commit-meddelandet att `process-ide-till-live` steg 4 måste uppdateras vid merge (testet `test_the_old_go_live_article_…` fäller då, med avsikt).

- [ ] **Steg 6: Commit.**

```bash
cd $WT && git add resources/faktabank tests/Feature/ReleaseFactbankSyncTest.php && git commit -m "Släppflöde 1a: faktabanksartiklar och test mot next-texterna"
```

---

### Task 12: Verifiering, säkerhetsgranskning och överlämning

**Files:**
- Inga nya filer (rättningar görs i den task som äger koden).

**Interfaces:**
- Consumes: allt ovan.

- [ ] **Steg 1: Hela sviten och stilen.**

Run: `cd $WT && php artisan test && vendor/bin/pint --test`
Expected: PASS, och pint utan avvikelser. Avvikelser: `vendor/bin/pint` på de ändrade filerna och commita som `Stil: pint`.

- [ ] **Steg 2: Klass-svep (syskon).** Kör och läs resultatet:

```bash
cd $WT && grep -rn "latestDeployment\|recentDeployments" app | grep -v "^app/Provisioning/Clients/CoolifyClient.php"
cd $WT && grep -rn "Release::create\|Release::.*update(\|->status = " app | grep -v "^app/Models/Release.php"
cd $WT && grep -rn "request_live" app resources docs/superpowers/specs | grep -v "^docs/superpowers/specs/2026-10-14-slappflode-design.md"
```

Förväntat: (1) kvittoskrivarna är exakt tre (statusverktyget, `PollDemoBuilds`/`DemoBuildRecorder`, `CheckProvisioning`); (2) ingen skrivning av `Release.status` utanför `ReleaseService`, `Release::expireStale` och testerna (annars kan en väg gå förbi spärrarna); (3) kvarvarande hänvisningar till `request_live` i spec-dokument eller artiklar som beskriver det som sättet att gå live. Allt som avviker: fixa i den task som äger koden, eller ställ en AskUserQuestion om det inte går att fixa direkt.

- [ ] **Steg 3: Säkerhetsgranskning.** Invokera `security-first-mindset` och kör dess review gate på hela diffen (`cd $WT && git diff main...HEAD`) med en angripares ögon. Frågor som ska besvaras mot koden, inte mot minnet: Kan en medlem utan ägarroll skapa ett släpp (nej, `ReleaseService::promote`)? Kan någon utom en platform admin godkänna (nej, `assertAdmin` och Filament-sidans `canAccess` plus `admin()` i varje metod; MCP saknar vägen)? Kan `commit_sha` från ett annat spel slinka igenom (nej, kvitton och släpp filtreras på `game_id`)? Läcker `last_error` eller Slack-texter hemligheter (nej: `Redactor` i `saving`-kroken, `SlackAlert::escape` och `scrubSecrets`)? Skickas något HTTP i en transaktion (nej: `AssertsNoHttpInTransaction` i alla klasser som når HTTP)? Startar något ett bygge eller en deploy (nej: `test_client_cannot_start_a_build` i `CoolifyClientTest` gäller fortfarande, och inget i planen lägger till en sådan metod)?

- [ ] **Steg 4: `completion-gate`.** Invokera `completion-gate` mot specen (etapp 1a-raden) och denna plan. Blockerande findings stoppar. Icke-blockerande buntas till **en** AskUserQuestion till Erik före överlämning.

- [ ] **Steg 5: Överlämning.** Rapportera till Erik (en sammanfattning på högst ett stycke): vad som finns och att inget går live; att deploy av 1a (migrationer, backfill, ny schemalagd pollning) är ett eget beslut som kräver hans OK; att spikens S26–S29 står i bilagan; att faktabankens artikel `process-ide-till-live` måste uppdateras när `feat/faktabank` mergas (testet fäller annars, med avsikt). Merge sker med `~/.claude/bin/wt-finish $WT` först när Erik sagt till; ingen push eller deploy görs av planen.

---

## Täckning mot specen (självgranskning)

| Specens krav (etapp 1a) | Task |
|---|---|
| `release_targets`, `releases_enabled`, bundle-id | 2, 7 |
| `releases`, `demo_builds`, `release_setup_steps` | 2 |
| Kvitton: `get_provisioning_status`, femminuterspoll, nattkontroll | 3 |
| Spärr 1, 2, 5, 6, 8, 9 | 4 |
| Spärr 3 (godkännande, `self_approved`), 4 (lås och unikt index), 7 (omkontroll, TTL) | 5 |
| `next` i en klass, `get_release_status` | 6 |
| `promote_release`, `set_release_targets`, audit, `ToolInvariantsTest` | 6, 7 |
| `request_live` som hänvisning, backfill | 8 |
| Filament: släpp, engångssteg, godkännande, brytare | 9 |
| Slack-meddelanden | 5, 9 |
| Bevakning i nattkontrollen | 10 (produktionswebben: 1b, R9) |
| Faktabanksartiklar och test mot `next` | 11 |
| Coolify-spike om `commit` | 1 |
| Inget byggs i 1a | R1, Task 7 (`next` säger etapp) |
