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
2. Gissa aldrig att ett steg är klart. Bara en platform admin markerar ett steg som klart, i Filament. Bundle-id sätts av Computercat själv (etapp 2) och kan inte bockas av för hand.
3. Ett mål med ett öppet steg kan inte befordras alls, varken teststeget eller produktionssteget. Försök inte komma runt det.

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


## Källa

- app/Releases/SetupSteps.php
- app/Mcp/Tools/SetReleaseTargetsTool.php
- app/Mcp/Tools/GetReleaseStatusTool.php
