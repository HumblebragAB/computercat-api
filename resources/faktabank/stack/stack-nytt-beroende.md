---
id: stack-nytt-beroende
title: Nytt npm-beroende
topic: stack
tags: [beroende, npm, paket, bibliotek, installera, dependency, library]
summary: Ett nytt npm-paket kräver att inget i byggguiden eller Computercat redan löser behovet, och en skriven motivering i commit-meddelandet.
updated: "2026-10-14"
---

## Regel

Lägg bara till ett npm-paket när varken byggguidens grundkod eller Computercats byggstenar löser behovet. Motiveringen skrivs i commit-meddelandet: vilket behov, vilka alternativ du uteslöt och varför.

## Varför

Spelen byggs som statisk export och deployas automatiskt vid varje push. Ett paket med en sårbarhet eller ett installationsskript blir en del av allt som når spelarna. `npm audit --omit=dev --audit-level=high` måste vara utan träff före varje push.

## Så gör du

1. Kontrollera `stack-valordning` och `get_integration_guide`.
2. Välj ett välunderhållet paket som fungerar utan installationsskript: installationsskript körs aldrig, varken lokalt eller i Coolify (`npm ci --ignore-scripts`).
3. Installera med `npm install --save-exact --ignore-scripts <paket>`, kör sedan `npm run check` och `npm audit --omit=dev --audit-level=high`.
4. Skriv motiveringen i commit-meddelandet.
5. Tredjeparts-analytics, spårning och reklam kräver Eriks beslut (spec delprojekt 3). Externa typsnitt och CDN-skript är inte tillåtna: bygget ska klara sig utan nätverk och använder inte `next/font/google`. Fråga i stället för att lägga till.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- resources/byggguide/v1/files/package.json
- docs/superpowers/specs/2026-10-14-faktabank-design.md
