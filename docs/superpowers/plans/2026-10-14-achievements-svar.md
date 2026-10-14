# Achievements: synliga svar och trösklar bara ur settings — implementationsplan

**Spec:** `docs/superpowers/specs/2026-10-14-achievements-klient-design.md`, avsnittet "Server". Klientmodulen (byggguide v3) ingår inte här.

**Eriks beslut 2026-10-14:** rimlighetskontrollen använder bara `settings.achievement_thresholds`; Toccos räkne-trösklar flyttas dit i samma ändring. Serverdelen byggs nu, deploy bara på Eriks order.

**Arbetssätt:** worktree `fix/achievements-svar` (sqlite, ingen egen databas; testerna kör SQLite i minnet). Testkommando: `php artisan test --filter=<Klass>`; hela sviten före slutgranskning. Inget mot prod. v1/v2 av byggguiden rörs inte.

## Task 1: `AchievementController::store`

- Trösklar läses bara ur `$game->settings['achievement_thresholds']`. Listan måste vara en array; en tröskel gäller bara om den är ett heltal > 0 (int, eller sträng med bara siffror). Allt annat ger ingen kontroll för den sluggen. Heuristiken med första talet i slugen tas bort.
- `progress.results` räknas bara om det är en array (`is_array`); annars, eller om sparningen saknas eller listan är tom, görs ingen kontroll (som i dag när listan är tom).
- Svaret: `unlocked` (oförändrat), `already_unlocked: int` = antalet aktiva definitioner som redan var upplåsta (som i dag men utan de avvisade; fortfarande ett heltal), `unknown` = begärda slugs utan aktiv definition (unika, i begärd ordning), `rejected` = avvisade av tröskeln. Status 201 som i dag.
- Den döda variabeln `$alreadyUnlocked` tas bort.

Tester (`AchievementTest`): tröskel ur settings avvisar och släpper igenom; trasiga trösklar (`"abc"`, `null`, `[]`, `5.7`, listan som sträng) ger ingen kontroll och inget 500; siffra i slug utan settings-tröskel avvisas inte (`first_12x12`); `results` som sträng och som tal ger 201 utan kontroll (inte 500); blandad omgång (ny, redan upplåst, okänd, inaktiv, avvisad) ger rätt `unlocked`, `already_unlocked`, `unknown`, `rejected`; befintliga tester gröna (svaret får nya fält, inga borttagna).

## Task 2: migration för Tocco

`database/migrations/2026_10_16_100006_move_tocco_achievement_thresholds_to_settings.php`: finns spelet `tocco` läggs `{solve_5: 5, solve_25: 25, solve_100: 100, daily_30: 30, daily_100: 100, streak_3: 3, streak_7: 7, streak_30: 30, weekly_4: 4, weekly_12: 12, zen_10: 10}` in i `settings.achievement_thresholds`. Befintliga nycklar i listan vinner (skrivs aldrig över); övriga `settings` (bland annat krypterade värden) läses och skrivs tillbaka orörda som JSON. `down()` tar bort exakt de nycklar vars värde fortfarande är det migrationen satte. Utan Tocco gör den ingenting.

Det är Tocco-slugs där talet är ett antal (lösta pussel, dagar i rad, veckoutmaningar), så heuristikens krav var ett giltigt minimum och behålls exakt. De fem där talet är minuter eller brädstorlek (`first_12x12`, `first_14x14`, `speed_3min`, `speed_1min`, `speedrun_sub5`) får ingen tröskel, vilket är rättelsen.

Tester (ny `AchievementThresholdsMigrationTest`): Tocco med tomma settings får trösklarna; befintlig tröskel och andra settings-nycklar (också en krypterad sträng) är orörda; utan Tocco ingen ändring; `down()` tar bara bort sina egna värden; körs två gånger ger samma resultat.

## Task 3: integrationsmanualen

`IntegrationGuide`: avsnittet visas när `achievements` begärs uttryckligen, också utan definitioner (då med en rad om att definitioner skapas med `upsert_achievement`). Beskrivningen av `POST` får svarsformen med `unknown` och `rejected`, och en rad om att trösklar (minsta antal resultat i molnsparningen) sätts av Computercat på begäran, inte av spelet. Tabellen visas inte tom när definitioner saknas, och raden "Idempotent — already-unlocked slugs are ignored" skrivs om så att den nämner `unknown` och `rejected`. `defaultFeatures()` ändras inte.

Tester (`IntegrationGuideTest`): uttrycklig begäran utan definitioner ger avsnittet; svarsformen nämner `unknown` och `rejected`; standardval utan definitioner ger inget avsnitt.

## Task 4: granskning

Hela sviten, `security-first-mindset` review gate (rör API-svar och en datamigrering), `completion-gate`. Deploy och `php artisan migrate` i prod ingår inte; frågas separat med rollback (`migrate:rollback --step=1` kör `down()`).
