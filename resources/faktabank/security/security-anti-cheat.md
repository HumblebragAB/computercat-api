---
id: security-anti-cheat
title: Anti-cheat för topplistor
topic: security
tags: [anti cheat, anti-cheat, fusk, bevis, proof, topplista, poänggräns]
summary: Servern prövar varje resultat mot spelets anti-cheat-inställningar, som en människa sätter i panelen. Spelet skickar createProof(...) i metadata.proof när bevis krävs.
updated: "2026-10-14"
---

## Regel

Lita aldrig på att klienten skyddar en topplista; det är servern som godkänner eller avvisar resultat. Inställningarna sätts av en människa i Computercats panel, inte av spelets Claude. Föreslå rimliga gränser för spelet och be en människa sätta dem. Kräver spelet bevis: skicka `createProof(...)` i `metadata.proof`.

## Varför

Varje resultat till en topplista prövas av `AntiCheatService` mot `games.settings.anti_cheat`. En poäng som inte är ett heltal ≥ 0 stoppas redan av valideringen (422 med `errors`). Därefter ger ett avvisat resultat 422 med orsaken som `message`. Prövningen i ordning:

1. Under `min_score` (standard 0): `Score is implausibly low.`
2. Över `max_score` (standard 86400): `Score exceeds maximum allowed value.`
3. `require_proof` satt och inget bevis: `Proof of play is required.`
4. Finns ett bevis prövas det även när det inte krävs:
   - Fälten `pid`, `mc` (heltal), `gh`, `t` (heltal) och `sig` måste finnas: annars `Invalid proof format.`
   - Signaturen (`proof_algorithm`, standard `fnv1a`): `Proof signature mismatch.`
   - Bevisets tid `t` (millisekunder) jämförs med poängen: skillnaden får vara högst `proof_time_tolerance` (standard 1000) i poängens enhet. Med `score_unit` `seconds` delas `t` med 1000 först. 0 stänger av kontrollen. Annars: `Proof time does not match score.`
   - `mc` minst `min_moves` (standard 1): `Too few moves.`

Standardvärdena gäller för ett spel utan inställningar och för förvalet `none`: ett poängspel med resultat över 86400 avvisas tills `max_score` höjs, och ett bevis som skickas jämförs med poängen som en tid i millisekunder (tolerans 1000). Ett poängspel som skickar bevis ska därför ha `points` eller `points_with_proof`.

I panelen (Games, sektionen Anti-Cheat) väljer en människa ett förval och kan ändra `min_score`, `max_score`, `require_proof` och `min_moves`. Förvalen:

| Förval | Sätter |
|---|---|
| `none` | inget |
| `time_ms` | `score_unit` ms, `min_score` 5000, `max_score` 86400000, `fnv1a`, `proof_time_tolerance` 1000, `min_moves` 1 |
| `time_seconds` | `score_unit` seconds, `min_score` 5, `max_score` 86400, `fnv1a`, `proof_time_tolerance` 2, `min_moves` 1 |
| `points` | `min_score` 0, `max_score` 1000000, `proof_time_tolerance` 0 |
| `points_with_proof` | `min_score` 0, `max_score` 1000000, `proof_time_tolerance` 0, `require_proof`, `fnv1a`, `min_moves` 1 |

`score_unit`, `proof_algorithm` och `proof_time_tolerance` har inga egna fält i panelen och sätts bara av förvalen. Byte av förval skriver om alla förvalsnycklar, så inget ligger kvar från det förra.

Connectorns verktyg ändrar inte inställningarna, och `get_game` visar aldrig spelets settings. `get_integration_guide` visar förvalet och poängintervallet när förvalet inte är `none`, och dessutom om bevis krävs och hur `t` jämförs när förvalet har bevis (`time_ms`, `time_seconds`, `points_with_proof`).

## Så gör du

1. Läs `get_integration_guide` för spelet och se vad som gäller i dag.
2. Föreslå förval och gränser utifrån vad en riktig spelare kan nå (lägsta rimliga tid, högsta rimliga poäng, minsta antal drag). Skriv förslaget till en människa och be om att det sätts i panelen. Sätt inget själv och anta inte att det är gjort.
3. Kräver spelet bevis: skapa det när rundan är klar och skicka det med resultatet:

```ts
const proof = createProof({ pid: rundansId, moves, stateHash, elapsedMs });
const { flushed } = cc.client.recordScore(typ, score, { proof });
```

   `createProof` kastar `RangeError` om `pid` eller `stateHash` är `""` eller `"0"`, eller om `moves` eller `elapsedMs` inte är heltal.
4. Skicka alltid `elapsedMs` i millisekunder, även när poängen är sekunder; servern räknar om med förvalets `score_unit`. Poängförvalen jämför inte tiden alls. Får ett riktigt resultat ändå `Proof time does not match score.`: kontrollera i `get_integration_guide` att förvalet passar spelets poäng och be en människa byta förval.
5. Visa ett avvisat resultat för spelaren. Dölj det aldrig.

Testa att beviset skapas och skickas: `testing-anti-cheat-bevis`.

## Källa

- app/Services/AntiCheatService.php
- app/Filament/Resources/GameResource.php
- app/Http/Controllers/Api/V1/LeaderboardController.php
- app/Services/IntegrationGuide.php
- app/Mcp/Tools/GetGameTool.php
- src/lib/computercat/proof.ts
- src/lib/computercat/index.ts
- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v1/files/CLAUDE.md
