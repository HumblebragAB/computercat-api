---
id: security-lint-regler
title: Säkerhetsreglerna i lint
topic: security
tags: [lint, eslint, dangerouslySetInnerHTML, eval, process.env, regel]
summary: Lint förbjuder dangerouslySetInnerHTML, eval, new Function, timers med sträng och process.env utöver tre namn. Stäng aldrig av en regel.
updated: "2026-10-14"
build_guide: [v2]
---

## Regel

Stäng aldrig av en säkerhetsregel i `eslint.config.mjs` (inga `eslint-disable`, ingen ändrad konfiguration). Behövs något som regeln förbjuder: fråga faktabanken och sedan en människa.

## Varför

Allt i `NEXT_PUBLIC_*` bakas in i det som publiceras. `npm run check` kör lint, och ett lintfel stoppar kedjan.

## Så gör du

I hela repot (utom `.next/`, `out/`, `build/`, `next-env.d.ts` och `.claude/`) fäller:

- `dangerouslySetInnerHTML` som JSX-attribut, som nyckel i ett props-objekt (med identifierare eller sträng, även i ett spritt objekt), vid tilldelning (`el.dangerouslySetInnerHTML = …`) och vid läsning med hakparentes. Rendera texten som text.
- `eval` (`no-eval`) och `new Function` (`no-new-func`).
- `setTimeout` och `setInterval` med en sträng (`no-implied-eval`). Skicka en funktion: `setTimeout(() => …, 10)` är tillåtet.
- `process.env.NEXT_PUBLIC_*` med något annat namn än `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`.

I `src/**/*.{ts,tsx}` fäller dessutom varje åtkomst till `process.env` som inte är en direkt punktläsning av `NODE_ENV`, `NEXT_PUBLIC_COMPUTERCAT_GAME` eller `NEXT_PUBLIC_COMPUTERCAT_URL`. Det gäller även `globalThis.process.env`, `window.process.env` och `process['env']`:

- Tillåtet: `process.env.NODE_ENV`, `process.env.NEXT_PUBLIC_COMPUTERCAT_GAME`, `process.env.NEXT_PUBLIC_COMPUTERCAT_URL`.
- Fäller: hakparentes (`process.env['NEXT_PUBLIC_COMPUTERCAT_GAME']`), destrukturering (`const { X } = process.env`), alias (`const env = process.env`) och spridning (`{ ...process.env }`).

`test/eslintRules.test.ts` lintar varje fall ovan med projektets riktiga konfiguration och visar att det fäller. Felmeddelandena säger vad som gäller, till exempel `Läs bara process.env.NODE_ENV, process.env.NEXT_PUBLIC_COMPUTERCAT_GAME och process.env.NEXT_PUBLIC_COMPUTERCAT_URL, direkt med punkt (byggguide v2). Inga alias, destrukturering, spridning eller hakparentes.`

Behöver spelet en ny publik variabel eller HTML från en källa: det rör säkerhet, så fråga en människa innan du bygger.

## Källa

- resources/byggguide/v2/files/eslint.config.mjs
- test/eslintRules.test.ts
- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v2/files/package.json
