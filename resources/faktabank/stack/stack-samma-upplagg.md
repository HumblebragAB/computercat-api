---
id: stack-samma-upplagg
title: Samma upplägg i varje spel
topic: stack
tags: [upplägg, struktur, underhåll, byggguide, exempel, mappar, grundkod]
summary: Byggguidens filer i src/lib/computercat/ och scripts/ ändras aldrig i spelet, exemplet byts ut enligt CLAUDE.md och serverlogik som saknas blir en PR mot computercat-api.
updated: "2026-10-14"
build_guide: [v2]
---

## Regel

Varje spel har samma grundkod från byggguiden. Ändra aldrig klientkoden i `src/lib/computercat/` eller skripten i `scripts/` i spelet. Byt ut exemplet på det sätt som `CLAUDE.md` beskriver, och lös det som saknas på servern med en PR mot computercat-api.

## Varför

Grundkoden (Computercat-klienten, konfiguration, tester, säkerhetskontroller och ett exempelspel) kommer från byggguiden, och varje fil har en `sha256` i manifestet. När alla spel har samma filer kan en rättning göras en gång, i en ny byggguideversion i computercat-api, i stället för i varje spel för sig.

## Så gör du

1. Behövs en ändring i `src/lib/computercat/` eller `scripts/`: beskriv den och föreslå den för en ny byggguideversion i computercat-api. Laga aldrig byggguidens filer i spelet för att få något grönt.
2. Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api. Bygg inte runt det.
3. Topplistor, achievements, pooler med mera konfigureras med Computercat-connectorns verktyg (`get_game`, `upsert_*`). Topplistor skapas med `upsert_leaderboard_type`.
4. När exemplet (klickrace) ersätts av spelet:
   - skapa spelets egna topplistor med `upsert_leaderboard_type` och byt `LEADERBOARD_TYPE` i `src/game/constants.ts`
   - ta bort `src/game/exampleDocs.test.ts` tillsammans med exemplet
   - byt `title` i `src/app/layout.tsx`
   - byt `name` i `package.json` och kör sedan `npm install --package-lock-only`
   - byt lagringsnyckeln `spelmall-click` (prefixet i `src/game/clickStore.ts`) till spelets namn
5. Låt de två raderna `computercat:idea` i `README.md` vara kvar. `test/docs.test.ts` kontrollerar att det finns exakt en start- och en slutmarkör, i rätt ordning och på egna rader.

## Källa

- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v2/manifest.json
- test/docs.test.ts
- docs/superpowers/plans/2026-10-14-faktabank-c.md
