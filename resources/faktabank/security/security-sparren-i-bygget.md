---
id: security-sparren-i-bygget
title: Säkerhetsspärren i bygget (cc-gate)
topic: security
tags: [spärr, cc-gate, bygge, coolify, säkerhetskontroll, demo, audit]
summary: Vad scripts/cc-gate.mjs kontrollerar och i vilken ordning, varför repot inte kan stänga av den och vad den inte skyddar mot.
updated: "2026-10-14"
build_guide: [v2, v3]
---

## Regel

Varje Demo-bygge i v2 passerar `scripts/cc-gate.mjs`. Faller en kontroll deployas inget, och Demo visar förra bygget. Rätta orsaken i spelets källa; försök aldrig ta dig förbi spärren. Den fångar misstag och kända mönster, inte avsiktligt skadlig spelkod, och den ersätter inte granskningen före Live.

## Varför

Coolify bygger Demo med ett byggkommando som Computercat äger:

```
npm run build && curl -fsS <adress till scripts/cc-gate.mjs i byggguide v2> -o /tmp/cc-gate.mjs && node /tmp/cc-gate.mjs
```

Skriptet hämtas över https från den låsta byggguiden i Computercat, som kontrollerar filens sha256 innan den skickas. Att ta bort eller ändra skripten eller `check` i spelrepot stänger därför inte av spärren i Coolify. Ett lokalt `npm run check` med en ändrad kopia bevisar ingenting.

Gränsen: `npm run build` kör repots egen kod före kontrollerna och kan i princip påverka byggmiljön. Spärren skyddar mot att en hemlighet, en source map, en dold fil eller en sida utan (eller med kringgången) Content-Security-Policy publiceras på Demo. Spelkod som avsiktligt gör något skadligt i webbläsaren fångas av granskning före Live.

v1 har ingen spärr i bygget; Coolify kör den bara för v2-spel.

## Så gör du

Kör `npm run check` före varje push. Det kör samma `scripts/cc-gate.mjs` sist, så du får samma fel lokalt som Coolify.

Ordningen i skriptet:

1. `out/` måste finnas, annars stannar skriptet direkt.
2. `NEXT_PUBLIC_COMPUTERCAT_URL` (miljön, sedan `.env.production.local`, `.env.local`, `.env.production`, `.env`, annars `https://api.computercat.co/api/v1`) måste vara en giltig adress, annars stannar skriptet.
3. Alla filer i `out/` gås igenom: dolda filer (`.env*`, `.git` med flera; `.well-known` är tillåten) och symboliska länkar fäller.
4. Varje fil: `*.map` fäller, och innehållet söks efter hemligheter (privata nycklar, GitHub-, Slack-, Stripe- och AWS-nycklar, JWT).
5. Varje HTML-sida: externa resurser, `<base>` och `<meta http-equiv="refresh">` fäller, och sidan ska ha exakt en CSP-tagg med byggguidens policy.
6. Finns fynd skrivs alla ut och bygget faller. Felet anger fil och mönstrets namn, aldrig värdet.
7. Sist körs `npm audit --omit=dev --audit-level=high` mot `https://registry.npmjs.org/`. High eller critical i produktionsberoenden fäller. Svarar inte registret görs ett nytt försök efter 10 sekunder.

Hur varje felrad rättas står i `security-ratta-sparrfel`. Policyn beskrivs i `security-csp`.

## Källa

- resources/byggguide/v2/files/scripts/cc-gate.mjs
- resources/byggguide/v2/files/CLAUDE.md
- app/Services/BuildGuide.php
- docs/superpowers/specs/2026-10-14-faktabank-design.md
