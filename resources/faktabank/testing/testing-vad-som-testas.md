---
id: testing-vad-som-testas
title: Vilka tester ett spel ska ha
topic: testing
tags: [tester, vitest, enhetstest, integrationstest, jsdom, testing]
summary: Spellogik som enhetstester, Computercat-flöden med fakeFetch och ett jsdom-test från första drag till skickat resultat.
updated: "2026-10-14"
---

## Regel

Ett spel har minst:

1. Enhetstester för spellogiken (regler, poäng, tid, vinst och förlust) som rena funktioner utan nätverk.
2. Tester för det spelet skickar till Computercat (resultat, sparfiler, anti-cheat-bevis) med `test/fakeFetch.ts`, aldrig mot det riktiga API:t.
3. Ett integrationstest i jsdom som spelar en hel omgång och kontrollerar att resultatet köas och skickas.

`npm run check` ska vara grön före varje push.

## Varför

Demo deployas automatiskt vid varje push till main. Testerna är det som hindrar att en trasig regel eller ett resultat som aldrig skickas når spelarna.

## Så gör du

- Lägg spellogik i rena moduler (t.ex. `src/game/`) så att den går att testa utan React.
- Utgå från grundkodens tester för Computercat-klienten; ändra dem inte, de följer byggguiden.
- Skriv integrationstestet efter mönstret i `test/integration.test.tsx`: håll omgången kort och stubba fetch med en tillståndsfull falsk server, som `fakeServer` i `test/integration.test.tsx`, så att testet går fort och utan nätverk.
- Test behövs inte för ren layout eller text.

## Källa

- test/fakeFetch.ts
- test/integration.test.tsx
- src/lib/computercat/queue.ts
- resources/byggguide/v1/files/CLAUDE.md
