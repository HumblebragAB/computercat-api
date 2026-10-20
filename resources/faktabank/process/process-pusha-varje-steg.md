---
id: process-pusha-varje-steg
title: Pusha varje färdigt steg till Demo direkt
topic: process
tags: [push, pusha, demo, main, samla, vänta]
summary: "Pusha varje färdigt steg till main direkt när npm run check är grön, så att Demo visar det. Håll aldrig färdigt arbete tillbaka. TestFlight är det som samlas ihop."
updated: "2026-10-20"
---

## Regel

1. När ett steg är klart (en meny, en splash, en ny bana, en rättning) och `npm run check` är grön: committa och pusha till `main` direkt. Följ upp med `get_provisioning_status` tills `demo.state` är `live`.
2. Håll aldrig färdigt arbete opushat för att det ska följa med något som fortfarande byggs. "Det går upp tillsammans med nästa del" är fel. Det som är klart ska synas på Demo nu.
3. Det som samlas ihop är TestFlight. En push till `main` bygger bara Demo, aldrig appen. En ny TestFlight-version (versionshöjning och `promote_release`) görs när flera steg tillsammans är värda att testa på en telefon.

## Varför

Demo är där människorna ser spelet. Visar Demo en gammal version tittar de på fel sak och ger återkoppling på något som redan är ändrat. Ett Demo-bygge körs i Coolify och kostar ingenting. Ett TestFlight-bygge kör på en macOS-maskin i GitHub Actions, som räknas tio gånger mot Humblebrags kvot. Därför är det TestFlight som ska samlas ihop, och Demo som ska uppdateras ofta.

## Så gör du

- Säg vad som ligger på Demo när du har pushat. Visa inte bara en bild av något som inte är pushat.
- Kan ett steg inte pushas än för att det är halvfärdigt: dela upp det, så att den färdiga delen kan gå upp utan den ofärdiga.
- Vad som gäller för TestFlight står i `process-slappflode`.

## Källa

- docs/superpowers/specs/2026-10-20-ios-bygge-plattform-beslut.md
- app/Mcp/ComputercatServer.php
