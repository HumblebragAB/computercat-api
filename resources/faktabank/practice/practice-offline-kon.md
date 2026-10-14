---
id: practice-offline-kon
title: Resultat köas lokalt och skickas i bakgrunden
topic: practice
tags: [offline, kö, recordScore, flushed, resultat, topplista, bakgrund]
summary: recordScore köar resultatet lokalt och skickar det i bakgrunden. Visa flushed-resultatet, dölj aldrig fel, visa aldrig påhittad topplistedata och fånga att recordScore kan kasta.
updated: "2026-10-14"
---

## Regel

Skicka resultat med `recordScore(typ, score, metadata?)` och visa för spelaren vad som hände med dem. Visa `flushed`-resultatet, dölj aldrig ett fel och visa aldrig påhittad topplistedata. `recordScore` kastar när resultatet inte kan sparas lokalt; fånga det och visa det.

## Varför

Resultatet sparas först i en kö i webbläsarens lagring (`computercat:<spel>:score-queue`) och skickas sedan. Kan det inte sparas lokalt (lagringen är full eller blockerad) kastar `recordScore` hellre än att resultatet försvinner tyst. Servern lägger resultatet i den period som gäller när det tas emot (UTC), så ett resultat som är äldre än 24 h kastas i stället för att hamna på fel dags lista.

## Så gör du

1. Anropa `recordScore` i ett `try`. Den returnerar `{ item, flushed }`. Vid fel: visa felet, till exempel med `describeError(e)`.
2. `flushed` är ett löfte om `{ sent, dropped, remaining }`. Gör som exemplet i `src/components/ClickGame.tsx`:
   - `dropped` inte tom: Computercat tog inte emot resultatet. Visa skälen (`dropped[].reason`).
   - `remaining` över 0: resultatet ligger kvar i kön och skickas när nätet är tillbaka. Säg det till spelaren.
   - annars: resultatet är skickat. Hämta topplistan igen.
   - Avvisas löftet: visa felet med `describeError(e)`.
3. Kön töms av `BackgroundSync`, som `src/app/layout.tsx` monterar: vid start, vid händelsen `online` och varje minut. Du behöver inte bygga någon egen omsändning.
4. Vad som händer med en post i kön:
   - nätverksfel, 5xx, 401, 408 och 429 är tillfälliga: posten ligger kvar och kön försöker igen senare, i samma ordning
   - andra 4xx och oväntade fel: posten tas bort och hamnar i `dropped` med skälet
   - äldre än 24 h: posten tas bort och hamnar i `dropped` med skälet "äldre än 24 h"
5. Kan topplistan inte hämtas: visa felet (exemplet visar "Offline: kunde inte nå Computercat. Det du spelar sparas lokalt."), inte en påhittad lista.
6. Hur offline testas står i `testing-fakefetch`.

## Källa

- src/lib/computercat/queue.ts
- src/lib/computercat/index.ts
- src/lib/computercat/errors.ts
- src/lib/computercat/background.ts
- src/components/BackgroundSync.tsx
- src/components/ClickGame.tsx
- src/components/Leaderboard.tsx
- test/integration.test.tsx
