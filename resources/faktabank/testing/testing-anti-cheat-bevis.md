---
id: testing-anti-cheat-bevis
title: Testa anti-cheat-beviset
topic: testing
tags: [anti-cheat, bevis, proof, createProof, topplista, test]
summary: När spelet kräver bevis ska ett test visa att createProof får rundans värden och att beviset skickas i metadata.proof.
updated: "2026-10-14"
---

## Regel

Kräver spelet bevis på spel (`require_proof`), ska ett test visa att resultatet skickas med `metadata.proof` byggt av `createProof(...)` med rundans riktiga värden. Testa inte om `createProof` själv; det gör byggguidens `src/lib/computercat/proof.test.ts`.

## Varför

Med `require_proof` avvisar servern ett resultat utan bevis ("Proof of play is required."). Med bevis kontrollerar den formatet, signaturen, att `|t - score|` är högst `proof_time_tolerance` (standard 1000) och att antalet drag är minst `min_moves` (standard 1). Stämmer något av det inte avvisar servern resultatet, så det ska fångas i ett test och inte på Demo.

## Så gör du

1. Testa spelets kod som bygger beviset: `createProof({ pid, moves, stateHash, elapsedMs })` ger `{ pid, mc, gh, t, sig }`.
   - `pid` och `stateHash` får inte vara `""` eller `"0"`, och `moves` och `elapsedMs` måste vara heltal; annars kastar `createProof` `RangeError`. Testa att spelet aldrig skickar sådana värden, till exempel att en uppmätt tid avrundas till heltal.
   - Är score en tid: testa att `elapsedMs` stämmer med score inom toleransen. Är score inte en tid måste spelets inställning ha `proof_time_tolerance = 0`; annars avvisas beviset med "Proof time does not match score.". Det sätts av en människa, se `security-anti-cheat`.
   - Testa att en runda ger minst så många drag som `min_moves`.
2. Testa att beviset skickas. Med `test/fakeFetch.ts` (se `testing-fakefetch`): låt rundan skicka sitt resultat och kontrollera kroppen i `f.calls`.

```ts
const post = f.calls.find((c) => c.method === 'POST' && c.path === '/games/kattsvep/leaderboards/<typ>');
expect(post?.body).toEqual({
  score: 45000,
  metadata: { proof: createProof({ pid: 'round-1', moves: 18, stateHash: 'abc', elapsedMs: 45000 }) },
});
expect(f.unmatched).toEqual([]);
```

3. Skicka beviset så här i spelet: `recordScore(typ, score, { proof: createProof(...) })`.
4. Exemplet (klickrace) skickar inget bevis. Ta inte dess test som förebild för ett spel med `require_proof`.

## Källa

- src/lib/computercat/proof.ts
- src/lib/computercat/proof.test.ts
- app/Services/AntiCheatService.php
- test/fakeFetch.ts
- test/integration.test.tsx
