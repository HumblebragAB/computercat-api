---
id: stack-valordning
title: Ordningen för tekniska val
topic: stack
tags: [stack, val, teknik, bibliotek, lösning]
summary: Välj först det som finns i byggguiden, sedan Computercats byggstenar, och bara därefter något nytt med skriven motivering.
updated: "2026-10-14"
---

## Regel

När spelet behöver något tekniskt väljer du i den här ordningen:

1. Det som redan finns i byggguidens grundkod (Next.js med statisk export, React, zustand, Tailwind, vitest, Computercat-klienten i `src/lib/computercat/`).
2. Computercats byggstenar för allt som rör spelare, data eller tävling: anonym inloggning, topplistor, sparfiler, achievements, streaks, dagligt innehåll, remote config och spelhändelser. Hämta manualen med `get_integration_guide`.
3. Ett nytt npm-paket, bara om 1 och 2 inte räcker. Se artikeln `stack-nytt-beroende`.

## Varför

Varje nytt bibliotek är kod som ska hållas uppdaterad, granskas för sårbarheter och förstås av nästa person. Spelen delar stack så att en lösning i ett spel fungerar i nästa, och så att Computercat kan hålla ordning på spelare och topplistor på ett ställe.

## Så gör du

- Sök i faktabanken (`search_facts`) och i integrationsmanualen innan du väljer.
- Står ett behov inte i någon av dem och beslutet är stort (ny typ av data, ny extern tjänst): beskriv behovet och fråga en människa i stället för att välja själv.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- app/Services/IntegrationGuide.php
- src/lib/computercat/index.ts
