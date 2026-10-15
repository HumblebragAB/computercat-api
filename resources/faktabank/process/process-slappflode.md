---
id: process-slappflode
title: Släppflödet från Demo till telefon och produktion
topic: process
tags: [process, slapp, release, promote_release, get_release_status, testflight, produktion, godkännande]
summary: get_release_status säger vad som gäller och vad nästa steg är. promote_release befordrar en exakt commit; första produktionssläppet per mål godkänns av Erik i Filament.
updated: "2026-10-14"
---

## Regel

1. Fråga `get_release_status` först. Svaret har en rad `next` som säger vad du ska göra nu; gör det och inget annat.
2. En ny commit på main bygger Demo som vanligt. Den går vidare först när du ber om det med `promote_release(target, stage, commit_sha?)`. Bara spelets ägare kan göra det.
3. Teststeg (`testflight`, `play_internal`) tar som standard senaste commit med lyckat Demo-bygge. Produktionssteg (`web_production`, `app_store`, `play_production`) kräver att du anger `commit_sha`, och den commiten måste ha lyckats i teststeget (för webben: ha ett lyckat Demo-bygge).
4. Det första produktionssläppet per spel och mål godkänns av Erik i Filament. Du kan inte godkänna det själv och ska inte försöka. Därefter kan ägaren släppa utan nytt godkännande.
5. Mål (`web`, `app_store`, `play_store`) och engångssteg sätts av Erik. Ett mål med öppna engångssteg kan inte befordras.
6. Produktionsstegen öppnas etapp för etapp. Så länge `get_release_status` säger `production_not_open` går det inte att begära produktion, och du ska inte försöka.

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
- `production_not_open`: Commit {commit} är testad i {test_stage}. Produktion byggs först i etapp {etapp}; inget mer att göra nu.
- `awaiting_approval`: Väntar på Erik: släppet {release} av commit {commit} ska godkännas i Filament.
- `queued_for_build`: Släppet {release} av commit {commit} är registrerat, men bygget för {stage} byggs först i etapp {etapp}. Inget mer att göra nu.
- `in_progress`: Släppet {release} av commit {commit} pågår ({status}). Fråga igen med get_release_status.
- `succeeded`: Släppet {release} av commit {commit} är klart i {stage}. Inget mer att göra.
- `failed`: Släppet {release} av commit {commit} misslyckades: {error} Åtgärda orsaken och begär ett nytt släpp med promote_release(target: {target}, stage: {stage}).
- `cancelled`: Släppet {release} av commit {commit} avbröts. Väntar det på godkännande som inte kommer: fråga Erik. Annars begär ett nytt med promote_release(target: {target}, stage: {stage}).
- `ready_for_testflight`: Commit {commit} är testad i Demo. Senast släppta version till TestFlight: {last_version}. {flow}
- `starting`: Släppet {release} (version {version}, bygge {build}) av commit {commit} startar. Fråga igen med get_release_status om en minut.
- `building_testflight`: Släppet {release} (version {version}, bygge {build}) pågår: {phase}. Fråga igen med get_release_status om några minuter.
- `succeeded_testflight`: Släppet {release} (version {version}, bygge {build}) finns i TestFlight för gruppen ComputerCat intern. Installera det med TestFlight-appen. Nästa version: {flow}
- `failed_testflight`: Släppet {release} (version {version}) av commit {commit} misslyckades: {error} Samma commit kan skickas igen med promote_release(target: app_store, stage: testflight, commit_sha: {commit}). Ändrar du koden: {flow}

Ett släpp som väntar på godkännande eller byggstart i sju dygn avbryts av sig självt. Fråga en människa när något fastnar i stället för att försöka igen i oändlighet.

## Källa

- app/Releases/ReleaseNext.php
- app/Mcp/Tools/GetReleaseStatusTool.php
- app/Mcp/Tools/PromoteReleaseTool.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
