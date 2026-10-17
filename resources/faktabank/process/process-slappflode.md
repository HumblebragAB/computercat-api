---
id: process-slappflode
title: Släppflödet från Demo till telefon och produktion
topic: process
tags: [process, slapp, promote_release, get_release_status, testflight, version, package.json, byggnummer]
summary: "TestFlight byggs bara när version i package.json höjs: höj, pusha, vänta på grönt Demo, anropa promote_release. get_release_status säger nästa steg. Erik godkänner första produktionssläppet."
updated: "2026-10-16"
---

## Regel

1. Fråga `get_release_status` först. Svaret har en rad `next` som säger vad du ska göra nu; gör det och inget annat.
2. En ny commit på main bygger Demo som vanligt. Ingen app byggs vid en vanlig push. Commiten går vidare först när du ber om det med `promote_release(target, stage, commit_sha?)`. Bara spelets ägare kan göra det.
3. TestFlight byggs bara när versionen höjs. `version` i `package.json` (MAJOR.MINOR.PATCH) är det enda stället versionen sätts. Den ska vara högre än den senast släppta till TestFlight och inte använd förut; varje ny commit till TestFlight får en ny version. Samma commit kan skickas igen med samma version om släppet misslyckades. Ange alltid `commit_sha` för den commit som ska byggas.
4. Computercat sätter taggen `v<version>` på commiten i spelrepot, väljer byggnumret och bygger appen. Ändra eller flytta aldrig taggen och sätt aldrig byggnummer själv.
5. Produktionssteg (`web_production`, `app_store`, `play_production`) kräver att du anger `commit_sha`, och den commiten måste ha lyckats i teststeget (för webben: ha ett lyckat Demo-bygge). Det första produktionssläppet per spel och mål godkänns av Erik i Filament; du kan inte godkänna det själv och ska inte försöka.
6. Mål (`web`, `app_store`, `play_store`) och engångssteg sätts av Erik och Computercat. Ett öppet engångssteg stoppar de steg det gäller.
7. Produktionsstegen öppnas etapp för etapp. Så länge `get_release_status` inte föreslår produktion går det inte att begära den, och du ska inte försöka.

## Varför

Produktion är ett beslut som en människa tar första gången. Computercat stoppar det som inte är tillåtet och säger vad nästa steg är, så att du inte behöver gissa. Samma text står i API-svaret och här.

Ett iOS-bygge tar en macOS-maskin i GitHub Actions och ett byggnummer hos Apple, så det görs bara när en ny version faktiskt ska testas på telefon. Taggen visar i efterhand exakt vilken commit som blev vilken version.

## Så gör du

### Skicka till TestFlight

Ordagrant samma text som `next` i `get_release_status` och som spelets `CLAUDE.md`:

Skicka till TestFlight (kräver byggguide v3 eller senare i spelet, den med iOS-stöd). En push utan höjd version bygger aldrig appen; bara promote_release efter en versionshöjning gör det. 1. Höj version i package.json (MAJOR.MINOR.PATCH): högre än den senast släppta till TestFlight och inte använd förut. 2. Kör npm run check, committa och pusha till main. 3. Vänta tills Demo-bygget för commiten är grönt (get_provisioning_status visar demo.state live). 4. Anropa promote_release(target: app_store, stage: testflight, commit_sha: <commiten>). 5. Följ get_release_status tills släppet är klart. Computercat sätter taggen v<version>, väljer byggnummer, bygger appen och lägger den i TestFlight-gruppen ComputerCat intern.

Svarar `promote_release` att versionen är för låg eller att taggen redan finns: höj `version` som svaret säger och börja om från steg 1. Svarar det att byggguiden inte kan byggas för iOS: uppgradera spelet till v3 enligt avsnittet "Uppgradera ett v2-spel till v3" i v3:s `CLAUDE.md` och skapa iOS-projektet (`process-ios-capacitor`).

### Tillstånden

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
- `succeeded_testflight`: Släppet {release} (version {version}, bygge {build}) finns i TestFlight för gruppen ComputerCat intern. Installera det med TestFlight-appen. För en ny version gäller flödet nedan. {flow}
- `failed_testflight`: Släppet {release} (version {version}) av commit {commit} misslyckades: {error} Samma commit kan skickas igen med promote_release(target: app_store, stage: testflight, commit_sha: {commit}). Ändrar du koden: {flow}

Ett släpp som väntar på godkännande eller byggstart i sju dygn avbryts av sig självt. Fråga en människa när något fastnar i stället för att försöka igen i oändlighet.

## Källa

- app/Releases/ReleaseNext.php
- app/Mcp/Tools/GetReleaseStatusTool.php
- app/Mcp/Tools/PromoteReleaseTool.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
- app/Releases/TestFlight/TestFlightPreflight.php
- app/Releases/TestFlight/TestFlightBuilder.php
