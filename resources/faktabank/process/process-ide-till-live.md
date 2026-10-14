---
id: process-ide-till-live
title: Från idé till Demo och Live
topic: process
tags: [process, demo, live, request_live, provisionering, status]
summary: create_game ger repo och Demo, varje push till main bygger Demo, och request_live ber Erik om publicering. Följ upp med get_provisioning_status.
updated: "2026-10-14"
---

## Regel

1. `create_game` skapar repo, Demo-adress, CORS och övervakning. Vänta tills `get_provisioning_status` visar `succeeded`.
2. I spelrepot: följ `CLAUDE.md` steg 1 (grundkoden), sedan spelet. Varje push till main bygger Demo.
3. Följ upp med `get_provisioning_status` tills `demo.state` är `live`, och kontrollera Demo-adressen med basic auth.
4. När spelet är klart för riktiga spelare: `request_live`. Erik beslutar; inget går live automatiskt.

## Varför

Demo är skyddad med basic auth och syns inte för allmänheten. Live är ett beslut som en människa tar.

## Så gör du

- `demo.state` betyder: `awaiting_code` (ingen kod pushad än), `building`, `live`, `deploy_failed` (bygget föll; kör `npm run check` lokalt), `deployed_not_answering` och `unknown` (Coolify kunde inte läsas; försök igen och fråga en människa om det består).
- Ett spel som inte ska finnas kvar arkiveras med `archive_game` (repot arkiveras, inget raderas).
- Fråga en människa när något faller två gånger på samma sätt, i stället för att försöka igen i oändlighet.

## Källa

- app/Mcp/Tools/CreateGameTool.php
- app/Mcp/Tools/GetProvisioningStatusTool.php
- app/Mcp/Tools/RequestLiveTool.php
- app/Mcp/Tools/ArchiveGameTool.php
