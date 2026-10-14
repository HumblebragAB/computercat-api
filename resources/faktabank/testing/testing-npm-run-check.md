---
id: testing-npm-run-check
title: npm run check före varje push
topic: testing
tags: [check, push, typkontroll, lint, bygge, kontroll]
summary: npm run check kör typkontroll, lint, test, bygge, kontrollen av out/ och säkerhetsspärren. Den ska vara grön före varje push, och byggguidens testfiler ändras inte.
updated: "2026-10-14"
build_guide: [v2]
---

## Regel

Kör `npm run check` före varje push till `main`, tillsammans med `npm audit --omit=dev --audit-level=high`. Båda ska gå igenom. Ändra eller ta inte bort testfilerna som följer med byggguiden.

## Varför

`npm run check` kör samma `scripts/cc-gate.mjs` som Coolify kör efter bygget, så du får samma fel lokalt som Coolify. En push till `main` deployar Demo automatiskt, och faller en kontroll i Coolify deployas inget.

Testfilerna i `test/` och `src/lib/computercat/` är byggguidens filer. Varje fil har en `sha256` i manifestet, och byggguidens filer ändras aldrig för hand eller lagas för att få något grönt.

## Så gör du

1. `npm run check` kör i den här ordningen och stannar vid första fel:
   1. `npm run typecheck` (`tsc --noEmit`)
   2. `npm run lint` (`eslint`)
   3. `npm test` (`NODE_ENV=test vitest run`)
   4. `npm run build` med `NEXT_TELEMETRY_DISABLED=1`
   5. `node scripts/check-export.mjs`: `out/index.html` finns, och är `NEXT_PUBLIC_COMPUTERCAT_GAME` satt finns slugen i en JS-fil under `out/_next`
   6. `node scripts/cc-gate.mjs`: säkerhetsspärren (hemligheter, source maps, dolda filer, CSP, externa resurser, sedan `npm audit`)
2. Kör sedan `npm audit --omit=dev --audit-level=high`. Vid high eller critical: `npm audit fix` eller uppdatera paketet. Tysta aldrig.
3. Testerna som följer med byggguiden ändras inte och tas inte bort, bland annat:
   - `test/security.test.ts` (säkerhetsdelen i `cc-gate.mjs`)
   - `test/ccAudit.test.ts` (audit-delen i `cc-gate.mjs`)
   - `test/eslintRules.test.ts` (säkerhetsreglerna i eslint)
   - `test/docs.test.ts` (idé-markörerna i `README.md` och reglerna i `CLAUDE.md`)
   - testerna bredvid klientkoden i `src/lib/computercat/`
4. Undantaget är exemplets test `src/game/exampleDocs.test.ts`, som tas bort tillsammans med exemplet (se `stack-samma-upplagg`).
5. Blir en kontroll röd utan att du ändrat något: stanna och beskriv felet. Felet hör hemma i computercat-api. Faller bygget i Coolify: se `security-ratta-sparrfel`.

## Källa

- resources/byggguide/v2/files/package.json
- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v2/manifest.json
- scripts/check-export.mjs
- test/security.test.ts
- test/ccAudit.test.ts
- test/eslintRules.test.ts
- test/docs.test.ts
