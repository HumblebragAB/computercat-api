---
id: process-ide-till-live
title: Från idé till Demo och Live
topic: process
tags: [process, demo, live, request_live, provisionering, status]
summary: create_game ger repo och Demo, varje push till main bygger Demo. Live-förfrågan (request_live) är ersatt av get_release_status och promote_release. Följ upp med get_provisioning_status.
updated: "2026-10-14"
---

## Regel

1. `create_game` skapar repo, Demo-adress, CORS och en SiteOps-post; övervakningen startar först när Demo lever. Vänta tills `get_provisioning_status` visar `succeeded`.
2. I spelrepot: följ `CLAUDE.md` steg 1 (grundkoden), sedan spelet. Varje push till main bygger Demo. Pusha varje färdigt steg direkt, se `process-pusha-varje-steg`.
3. Följ upp med `get_provisioning_status` tills `demo.state` är `live`, och kontrollera Demo-adressen med basic auth.
4. När spelet är klart för riktiga spelare: fråga `get_release_status`, som säger vad som gäller och vad nästa steg är. Produktion kan ännu inte begäras med `promote_release` i den här versionen. Be Erik om Live med `request_live`, som skickar en förfrågan till honom och inget annat. Erik godkänner det första produktionssläppet per mål; inget går live automatiskt. Se `process-slappflode`.

## Varför

Demo är skyddad med basic auth och syns inte för allmänheten. Live är ett beslut som en människa tar.

## Så gör du

- Vad varje `demo.state` betyder och vad du gör i varje läge står i `process-provisioning-status`.
- Ett spel som inte ska finnas kvar arkiveras med `archive_game` (repot arkiveras och raderas aldrig, men Demo-sajten tas bort, övervakningen stängs av och spelet inaktiveras).
- Fråga en människa när något faller två gånger på samma sätt, i stället för att försöka igen i oändlighet.

## Källa

- app/Mcp/Tools/CreateGameTool.php
- app/Mcp/Tools/GetProvisioningStatusTool.php
- app/Mcp/Tools/RequestLiveTool.php
- app/Mcp/Tools/PromoteReleaseTool.php
- app/Mcp/Tools/ArchiveGameTool.php
