---
id: stack-ingen-egen-backend
title: Ingen egen backend
topic: stack
tags: [backend, server, databas, api, firebase, supabase, database]
summary: Computercat är spelets enda backend. Bygg aldrig egna API-routes, databaser eller tjänster som Firebase eller Supabase.
updated: "2026-10-14"
---

## Regel

All serverlogik och all lagring går genom Computercat via klienten i `src/lib/computercat/`. Spelet har inga API-routes, ingen egen databas och inga tjänster som Firebase eller Supabase.

## Varför

Spelet är en statisk export utan server. Computercat har inloggning, topplistor med anti-cheat, sparfiler och spelardata på ett ställe, med samma säkerhet och övervakning för alla spel.

## Så gör du

- Konfigurera byggstenarna med connectorns verktyg (`get_game`, `upsert_leaderboard_type`, `upsert_achievement`, `upsert_content_pool`, `upsert_remote_config`, `upsert_game_event`).
- Behövs serverlogik som byggstenarna saknar: beskriv behovet och föreslå en PR mot computercat-api. Bygg inte runt det i spelet.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- app/Mcp/ComputercatServer.php
- src/lib/computercat/index.ts
