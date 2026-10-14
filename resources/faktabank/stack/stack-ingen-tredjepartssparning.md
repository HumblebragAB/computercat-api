---
id: stack-ingen-tredjepartssparning
title: Ingen tredjeparts-analytics, spårning eller reklam
topic: stack
tags: [analytics, spårning, tracking, reklam, annonser, tredjepart, statistik]
summary: Tredjeparts-analytics, spårning och reklam läggs inte till utan Eriks beslut. Computercat har ingen analytics-byggsten, och spelhändelser är inte spårning.
updated: "2026-10-14"
---

## Regel

Lägg inte till tredjeparts-analytics, spårning eller reklam i spelet. Det kräver Eriks beslut. Fråga en människa i stället för att lägga till det.

## Varför

Faktabankens stackregler säger uttryckligen nej till tredjeparts-analytics, spårning och reklam utan Eriks beslut. Computercat är spelets backend, och dess spel-API (`routes/api.php`) har ingen route för analytics, spårning eller reklam. Det finns alltså ingen Computercat-byggsten att använda i stället; frågan går till en människa.

## Så gör du

1. Vill du mäta hur spelet används, eller visa reklam: stanna och fråga en människa. Bygg inget eget och lägg inte till ett paket eller skript från tredje part.
2. Förväxla inte spelhändelser med spårning. `upsert_game_event` skapar en händelse i spelet (`event_type` är `leaderboard`, `challenge` eller `seasonal`) med `starts_at` och `ends_at`. Spelet läser aktiva händelser med `GET /v1/games/{slug}/events` och en händelse med `GET /v1/games/{slug}/events/{slug}`. Händelserna går från Computercat till spelet; de skickar inget om spelaren tillbaka. På samma sätt ger `GET /v1/games/{slug}/stats/me` den inloggade spelarens egen statistik (antal spel, bästa resultat, antal achievements, streaks).
3. Se `stack-nytt-beroende` för hur ett nytt paket motiveras och `stack-valordning` för ordningen mellan stacken, Computercats byggstenar och nya beroenden.

## Källa

- docs/superpowers/specs/2026-10-14-faktabank-design.md
- docs/game-integration-guide.md
- app/Mcp/Tools/UpsertGameEventTool.php
- app/Http/Controllers/Api/V1/GameEventController.php
- app/Http/Controllers/Api/V1/PlayerStatsController.php
- routes/api.php
