# {{name}}

Ett Computercat-spel byggt med Next.js (statisk export), React, Tailwind, Zustand och framer-motion, enligt Computercats byggguide v1.

## Idé

<!-- computercat:idea:start -->
_Idén fylls i av create_game när spelet skapas._
<!-- computercat:idea:end -->

## Kom igång

Saknar repot `package.json` är det nytt: följ steg 1 i `CLAUDE.md`. Grundkoden hämtas med Computercat-verktyget `get_build_guide` och pushas till `main`.

```bash
npm ci --ignore-scripts      # installationsskript körs aldrig
cp .env.example .env.local   # sätt NEXT_PUBLIC_COMPUTERCAT_GAME till spelets slug
npm run dev                   # http://localhost:3000
```

Utan `NEXT_PUBLIC_COMPUTERCAT_GAME` går spelet att spela, men en gul banner säger att inget skickas till Computercat.

## Test och kontroll

```bash
npm test        # enhetstester (vitest), inget nätverk
npm run check   # typkontroll, lint, test, bygge och kontroll av out/
```

`npm run check` ska vara grön, och `npm audit --omit=dev --audit-level=high` får inte ge något (high eller critical i produktionsberoenden), innan något pushas.

## Backend

Backend är Computercat (`https://api.computercat.co/api/v1`). Spelet har ingen egen server, databas eller API-route. Allt som pratar med Computercat ligger i `src/lib/computercat/`:

- anonym spelare skapas automatiskt; token sparas i `localStorage` (giltig 90 dagar) och förnyas vid start via `POST /auth/refresh` när den är äldre än 24 h (saknas endpointen, eller är nätet nere, behålls token); vid 401 skapas en ny anonym spelare,
- resultat köas lokalt först och skickas i bakgrunden (vid start, när nätet kommer tillbaka och varje minut),
- topplistor (`leaderboards.get/me/submit`), sparningar med version (`saves.get/put/sync`) och anti-cheat-bevis (`createProof`).

Topplistor, achievements, innehållspooler med mera konfigureras i Computercat, inte i koden. Den som har Computercat-connectorn i Claude gör det med verktygen `get_game` och `upsert_*`; integrationsmanualen fås med `get_integration_guide`.

### Exemplets topplista

Klickracet skickar till topplistan `daily-clicks` (dagens flest klick, högst vinner). Den skapas med `upsert_leaderboard_type`:

```json
{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}
```

Dagliga perioder räknas av servern i UTC.

### Anti-cheat

Kräver spelet bevis (`require_proof`) skickas `metadata.proof` från `createProof`. Servern jämför bevisets tid med resultatet; för resultat som inte är tider ska spelets `proof_time_tolerance` vara 0.

## Beroenden: `overrides` för vite

`package.json` har `overrides: { "vite": "7.3.7" }`. Utan den drar vitest 4.1.11 in en vite-version som gör att en ren `npm ci --ignore-scripts` fallerar. Raden tas bort i en senare byggguideversion när vitest har ett vite-intervall som installeras rent.

## Miljövariabler

| Variabel | Krävs | Standard |
|---|---|---|
| `NEXT_PUBLIC_COMPUTERCAT_GAME` | ja | ingen standard (sätts som build-variabel på Coolify) |
| `NEXT_PUBLIC_COMPUTERCAT_URL` | nej | `https://api.computercat.co/api/v1` |

Värdena bakas in i den publika koden vid bygget. Lägg aldrig hemligheter i `NEXT_PUBLIC_*`.

## Deploy

Push till `main` bygger och deployar Demo automatiskt (Coolify, nixpacks, statiska filer från `out/` via nginx, Node 22 enligt `engines`). Första pushen, grundkoden, startar första bygget. Demo-adressen är `https://<slug>.apps.humblebrag.se` bakom basic auth. Status följs med Computercat-verktyget `get_provisioning_status`.

## Idé-markörerna

De två `computercat:idea`-raderna under "Idé" skrevs av `create_game` i computercat-api. Låt dem stå kvar och citera dem inte någon annanstans i den här filen; `test/docs.test.ts` kontrollerar att de finns exakt en gång var.
