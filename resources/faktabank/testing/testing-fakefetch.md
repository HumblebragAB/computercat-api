---
id: testing-fakefetch
title: Testa Computercat-flöden med fakeFetch
topic: testing
tags: [fakefetch, fetch, mock, integrationstest, nätverk, api]
summary: Computercat-flöden testas med en falsk fetch (test/fakeFetch.ts eller en stubbad global fetch), aldrig mot det riktiga API:t. Kontrollera alltid unmatched.
updated: "2026-10-14"
---

## Regel

Testa allt som pratar med Computercat mot en falsk fetch, aldrig mot det riktiga API:t. Använd `test/fakeFetch.ts` för klientanrop, och en stubbad global `fetch` när hela sidan testas. Kontrollera att inga anrop blev utan svar.

## Varför

`fakeFetch` svarar bara på adresser under `BASE` (`https://api.test/api/v1`) och kastar för alla andra bas-URL:er, så ett test kan inte råka nå det riktiga API:t. Ett anrop som saknar svar hamnar i `unmatched` och kastar som ett nätverksfel. Kontrollerar testet inte `unmatched` kan ett anrop till fel sökväg passera som ett vanligt nätverksfel.

## Så gör du

1. Ange svaren per nyckel `"METOD /sökväg"`, relativt `BASE`. Svaren förbrukas i ordning och det sista upprepas. Ett svar kan vara en `Response` (bygg den med `json(status, body)`), strängen `'network-error'`, eller en funktion som får anropet och returnerar något av dem.
2. Skicka `f.fetch` till klienten med `createComputercat({ config: { game, baseUrl: BASE }, fetch: f.fetch, storage: memoryStorage() })`. Behövs en inloggad spelare från start: `storedSession(game)` ger en lagring med en sparad session.
3. Kontrollera resultatet, anropen i `f.calls` (metod, sökväg, headers, body) och att `f.unmatched` är tom.

```ts
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { createComputercat } from './index';
import { memoryStorage } from './storage';

const f = fakeFetch({
  'POST /auth/anonymous': [json(201, { user: { id: 9, display_name: 'Player-9', is_anonymous: true, created_at: null }, token: '9|t' })],
  'POST /games/kattsvep/leaderboards/daily-clicks': [json(201, { data: { rank: null, score: 14, metadata: null, submitted_at: 'x', user: { id: 9, display_name: 'Player-9' } } })],
});
const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: memoryStorage() });
const { flushed } = cc.recordScore('daily-clicks', 14, { round_ms: 10000 });
expect(await flushed).toEqual({ sent: 1, dropped: [], remaining: 0 });
expect(f.unmatched).toEqual([]);
```

4. För hela sidan (`// @vitest-environment jsdom`), gör som `test/integration.test.tsx`: en tillståndsfull falsk server bakom `vi.stubGlobal('fetch', fetchStub)`, `NEXT_PUBLIC_COMPUTERCAT_GAME` satt med `vi.stubEnv`, `localStorage.clear()` före varje test och `vi.resetModules()` för att simulera en omladdning. Där testas också offline: servern kastar `TypeError('Failed to fetch')`, ett synligt meddelande visas, resultatet ligger kvar i kön och skickas när `online` utlöses.
5. Vad ett spel ska ha för tester totalt står i `testing-vad-som-testas`.

## Källa

- test/fakeFetch.ts
- test/integration.test.tsx
- src/lib/computercat/index.test.ts
- src/lib/computercat/leaderboards.test.ts
