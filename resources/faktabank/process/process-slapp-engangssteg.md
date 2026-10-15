---
id: process-slapp-engangssteg
title: Engångssteg före första släpp till en butik eller domän
topic: process
tags: [process, engångssteg, appstore, play, bundle-id, release, testflight, profil]
summary: Varje produktionsmål har engångssteg som Erik eller Computercat gör en gång per spel. Öppna steg stoppar befordran till målet.
updated: "2026-10-15"
---

## Regel

1. Du kan inte göra engångsstegen. Säg till Erik att de väntar och vänta; `get_release_status` visar vilket steg som är öppet.
2. Gissa aldrig att ett steg är klart. Bundle-id, profil och TestFlight-grupp gör Computercat själv; appen i App Store Connect skapar Erik, och Computercat ser själv när den finns. Övriga steg bockar en platform admin av i Filament.
3. Ett öppet steg stoppar de steg det gäller. Integritetsuppgifter och åldersgräns stoppar bara App Store, inte TestFlight. Försök inte komma runt ett steg.

## Varför

App Store Connect och Google Play låter inte ett API skapa själva appen, och den publika adressen är ett beslut. Sådant görs en gång för hand, och först därefter är det meningsfullt att bygga.

Spelets bundle-id är `se.computercat.<slug utan bindestreck>`. Det är samma värde som `appId` i spelets `capacitor.config.ts`; `get_release_status` visar det.

## Så gör du

Stegen per mål och deras text (ordagrant som i `get_release_status`):

- `web_public_address`: Erik sätter spelets publika webbadress (domänen) för produktionswebben.
- `appstore_bundle_id`: Computercat registrerar bundle-id {bundle_id} i App Store Connect via API.
- `appstore_profile`: Computercat skapar App Store-profilen för {bundle_id} via API.
- `appstore_create_app`: Erik skapar appen i App Store Connect (Appar, +, Ny app): plattform iOS, namn {name}, primärspråk svenska, bundle-id {bundle_id}, SKU {sku}, användaråtkomst full åtkomst. Är namnet upptaget väljer Erik ett annat. Computercat ser själv när appen finns.
- `appstore_testflight_group`: Computercat skapar den interna TestFlight-gruppen ComputerCat intern och lägger till testarna via API.
- `appstore_privacy_and_age`: Erik fyller i appens integritetsuppgifter och åldersgräns i App Store Connect. Krävs före App Store, inte före TestFlight.
- `play_create_app`: Erik skapar appen i Play Console med spelets paketnamn.
- `play_store_listing`: Erik fyller i innehållsklassning, dataskydd, målgrupp och butikstext i Play Console.
- `play_app_signing`: Erik aktiverar Play App Signing för appen i Play Console.

## Källa

- app/Releases/SetupSteps.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
- app/Mcp/Tools/GetReleaseStatusTool.php
