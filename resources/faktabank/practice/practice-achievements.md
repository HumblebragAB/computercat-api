---
id: practice-achievements
title: Achievements rapporteras via klienten, hela listan vid varje start
topic: practice
tags: [achievements, upplåsning, report, upsert_achievement, rimlighetskontroll, unknown, rejected]
summary: Skapa definitionerna med upsert_achievement, spara molnsparningen först, rapportera med achievements.report och hela listan vid varje start. Visa unknown, rejected och dropped.
updated: "2026-10-16"
build_guide: [v3]
---

## Regel

Rapportera achievements bara med `achievements.report(slugs)` i `src/lib/computercat/`. Spara framstegen i molnsparningen med `saves.sync('main', …)` innan du rapporterar, och rapportera hela listan med spelarens upplåsningar vid varje start. Visa `unknown`, `rejected` och `dropped` för spelaren eller i utvecklingsläget; dölj dem aldrig.

## Varför

Servern låser upp en gång per spelare och slug, så att rapportera hela listan igen är ofarligt. Rimlighetskontrollen läser spelarens molnsparning `main` och räknar elementen i `progress.results`, och jämför med trösklarna i spelets `settings.achievement_thresholds`. Trösklarna sätts av en människa i Computercat, syns inte i `get_game` och sätts inte med något verktyg. Rapporterar spelet innan sparningen är uppladdad kan en upplåsning få `rejected` fast spelaren klarat villkoret; den tas ur kön och kommer med nästa gång hela listan rapporteras. Därför sparas molnsparningen först. En felstavad slug, eller en definition som inte är skapad, syns i `unknown` i stället för att försvinna tyst.

## Så gör du

1. Skapa definitionerna med `upsert_achievement` (slug högst 50 tecken, `icon` högst 20). Behöver en achievement en tröskel (minsta antal resultat): fråga en människa. Använd inga siffror i en slug som inte är en tröskel.
2. När ett villkor uppfylls i spelet: spara först framstegen i molnsparningen med `saves.sync('main', …)`, med ett element per avklarat resultat i `progress.results` (sparningen får vara högst 512 KB). Anropa sedan `achievements.report([slug])` i ett `try`. Den kastar `RangeError` för en ogiltig slug (högst 50 tecken, gemener, siffror, `-`, `_` och `.`, först en bokstav eller siffra, som `upsert_achievement`) och `Error` när kön inte kan sparas lokalt.
3. Vid start: `achievements.report(allaUpplåsta)`.
4. `flushed` ger `{ sent, unlocked, unknown, rejected, dropped, remaining }`:
   - `unknown`: skapa definitionen med `upsert_achievement`, eller rätta stavningen i spelet
   - `rejected`: spelarens molnsparning har för få resultat för tröskeln, eller var inte uppladdad än; den kommer med nästa gång hela listan rapporteras
   - `dropped`: Computercat tog inte emot omgången (till exempel 422); visa skälet
   - `remaining` över 0: köad lokalt (`computercat:<spel>:achievement-queue`), skickas när nätet är tillbaka; nätverksfel, 5xx, 401, 408 och 429 väntar
5. Kön skickas i omgångar om högst 10 och töms också i bakgrunden: `BackgroundSync` anropar `flushAll()` vid start, vid `online` och varje minut. Vill spelet visa bakgrundens svar: `onFlushed(cb)`.
6. `achievements.list()` ger definitionerna (hemliga har `is_secret` sann och `description` `???`), `achievements.mine()` spelarens upplåsta.

## Källa

- src/lib/computercat/achievements.ts
- src/lib/computercat/achievements.test.ts
- src/lib/computercat/saves.ts
- src/lib/computercat/index.ts
- src/components/BackgroundSync.tsx
- app/Http/Controllers/Api/V1/AchievementController.php
- app/Support/ConfigRules/AchievementRules.php
- resources/byggguide/v3/files/CLAUDE.md
