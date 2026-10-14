---
id: process-provisioning-status
title: Demo-lägen och deploy_failed i get_provisioning_status
topic: process
tags: [provisionering, status, demo, deploy_failed, coolify, bygge, get_provisioning_status]
summary: Vad varje demo.state i get_provisioning_status betyder och vad du gör, från awaiting_code till deploy_failed (även avbrutet bygge) och unknown.
updated: "2026-10-14"
---

## Regel

Följ upp varje push till `main` med `get_provisioning_status` tills `demo.state` är `live`. Läs `next` när den finns och gör det den säger. Vid `deploy_failed`: titta på `latest_deployment.status` innan du letar fel i koden, eftersom ett avbrutet bygge inte är ett fel i koden.

## Varför

`demo` visar om kod är pushad och byggd, och om Demo svarar. Ett fel från Coolify visas, det döljs aldrig. Spel från byggguide v2 och senare (vars manifest har `scripts/cc-gate.mjs`) får en `next`-text vid `deploy_failed`; v1-spel får ingen.

## Så gör du

`demo` är `null` tills provisioneringen har `status` `succeeded`. Fram till dess: följ `provisioning.steps` och `provisioning.last_error`. Efter det har `demo` alltid `state` och `monitoring` (`pending` eller `enabled`; SiteOps-övervakningen slås på första gången Demo lever).

### awaiting_code

Ingen kod är pushad än. `next` säger vad du gör: följ steg 1 i `CLAUDE.md` (`get_build_guide`, skriv filerna, kör kontrollerna) och pusha till `main`. Pushen bygger Demo.

### building

Coolify bygger. `url_status` är `null`. Vänta och fråga igen.

### live

Senaste bygget är klart och Demo-adressen svarar med 200. Kontrollera `demo_url` med inloggningen i `demo_basic_auth`.

### deployed_not_answering

Senaste bygget är klart men Demo-adressen svarar inte med 200. `url_status` visar vilken kod den gav (0 betyder att anslutningen inte gick). Fråga igen; består det, fråga en människa.

### deploy_failed

`latest_deployment.status` är `failed` (bygget föll) eller `cancelled-by-user` (bygget avbröts). `url_status` visar om ett tidigare bygge fortfarande svarar.

För spel från v2 finns `next`:

- Föll bygget: "Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen." Felraderna förklaras i `security-ratta-sparrfel`.
- Avbröts bygget: "Bygget avbröts i Coolify innan det blev klart. Pusha igen för att starta ett nytt bygge; Demo visar förra lyckade bygget tills dess." Leta inte fel i koden.

Kunde guideversionen inte läsas finns `next_error` med en referens i stället för `next`. Läget gäller ändå; ge referensen till en människa.

För v1-spel finns ingen `next`. Föll bygget: kör `npm run check` lokalt, rätta och pusha igen. Är `latest_deployment.status` `cancelled-by-user`: pusha igen.

### unknown

Coolify kunde inte läsas. `error` säger varför, ibland bara med en referens. Försök igen och fråga en människa om det består.

### Övriga fel i svaret

`monitoring_error` (övervakningen kunde inte slås på) och `receipts_error` (kvitton för lyckade byggen kunde inte sparas) ändrar inte `demo.state`, men ska inte ignoreras. Ge dem till en människa.

## Källa

- app/Mcp/Tools/GetProvisioningStatusTool.php
- app/Services/BuildGuide.php
- app/Provisioning/DemoProbe.php
