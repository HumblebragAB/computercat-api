---
id: process-fraga-manniska
title: När du ska fråga en människa
topic: process
tags: [fråga, människa, erik, beslut, gissa, stanna]
summary: Fråga en människa i stället för att gissa när serverlogik saknas, vid stora beslut som ingen artikel täcker, när byggguidens kontroller faller utan din ändring och när en lint-regel står i vägen.
updated: "2026-10-17"
build_guide: [v1, v2, v3]
---

## Regel

Gissa inte och bygg inte runt ett hinder. I lägena nedan stannar du, beskriver läget och frågar en människa innan du fortsätter. I v1 finns inte faktabanksavsnittet och lint-reglerna i `CLAUDE.md`; punkterna 2 och 4 gäller v2 och v3.

## Varför

I v2 och v3 säger spelets `CLAUDE.md` att en artikel går före din egen bedömning, och att ett stort beslut som ingen artikel täcker frågas till en människa innan du bygger. Byggguidens filer och Computercats server ändras i computercat-api, inte i spelet, så ett fel där kan du inte laga på rätt sätt själv.

## Så gör du

1. **Serverlogik som byggstenarna inte har:** beskriv behovet och föreslå en PR mot computercat-api. Bygg inte runt det.
2. **Ett stort beslut som ingen artikel täcker:** ny tjänst, ny arkitektur, ett beroende med stor påverkan, något som rör säkerhet, personuppgifter eller pengar. Sök först med `search_facts`; finns ingen artikel, fråga en människa innan du bygger. Ordningen mellan stacken, byggstenarna och nya beroenden står i `stack-valordning`.
3. **Ett steg i grundkoden faller** (verktyget svarar med fel, en kontroll blir röd utan att du ändrat något, `sha256` stämmer inte efter ny hämtning): stanna och beskriv felet. Laga aldrig byggguidens filer för att få det grönt; felet hör hemma i computercat-api.
4. **En lint-regel står i vägen** (`dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med en sträng, `process.env.NEXT_PUBLIC_*` utöver de två tillåtna): stäng aldrig av regeln. Fråga faktabanken och sedan en människa.
5. **Verktygen säger "ask Erik":** när gränsen för antal Demo-spel är nådd (`create_game`), och när ett spel som är listat (Live) ska arkiveras (`archive_game`). Svarar `promote_release` med en `warning` om att Erik inte fick beskedet i Slack: säg till Erik direkt att släppet väntar.
6. **Live:** `request_live` ber Erik ta ett spel live, och det första produktionssläppet per mål godkänns av Erik. Vägen dit står i `process-ide-till-live`.
7. Nycklar och lösenord hör aldrig hemma i `NEXT_PUBLIC_*` eller i repot. Behöver spelet en hemlighet: se `security-hemligheter-i-klienten` och fråga.

## Källa

- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v3/files/CLAUDE.md
- resources/byggguide/v1/files/CLAUDE.md
- app/Mcp/Tools/CreateGameTool.php
- app/Mcp/Tools/ArchiveGameTool.php
- app/Mcp/Tools/PromoteReleaseTool.php
- app/Mcp/Tools/RequestLiveTool.php
