---
id: security-csp
title: Content-Security-Policy och externa resurser
topic: security
tags: [csp, typsnitt, font, extern, cdn, bild, stilmall, policy]
summary: Byggguidens CSP tillåter bara samma ursprung (och Computercats API för anrop). Externa skript, stilmallar, bilder och typsnitt fäller bygget.
updated: "2026-10-14"
build_guide: [v2, v3]
---

## Regel

Allt spelet laddar ska komma från samma ursprung som spelet. Ändra aldrig policyn i `src/lib/computercat/csp.ts` och lägg aldrig till en egen CSP-tagg.

## Varför

Policyn är den här, med Computercats API-ursprung (ur `NEXT_PUBLIC_COMPUTERCAT_URL`, annars `https://api.computercat.co`) i `connect-src`:

```
default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' <API-ursprung>; object-src 'none'; base-uri 'self'; form-action 'self'
```

`'unsafe-inline'` för skript krävs av Next.js-exportens inline-skript. Policyn sätts som `<meta>` i `src/app/layout.tsx`, bara i produktionsbygget: `next build` sätter alltid `NODE_ENV=production`, medan `npm run dev` slipper policyn.

`scripts/cc-gate.mjs` kontrollerar varje HTML-sida i `out/`: exakt en CSP-tagg, med exakt den policyn. Metataggen gäller bara resurser som webbläsaren hittar efter den, och React/Next lyfter `<script async src>`, `<link rel=stylesheet>` med flera ovanför den. Därför kontrollerar skriptet också varje resursadress i HTML separat. En adress med `http:` eller `https:`, en protokolllös adress (`//`) och andra scheman fäller. `data:` tillåts bara i `<img>` och `<source>`. `<link>` kontrolleras bara när `rel` laddar något (`stylesheet`, `preload`, `modulepreload`, `prefetch`, `icon`, `apple-touch-icon`, `manifest`); `canonical` och `alternate` får peka utåt, liksom vanliga länkar (`<a href>`).

`test/security.test.ts` visar att skriptet godkänner exakt den policy som `csp.ts` ger. En ändring av policyn kräver en ny byggguideversion.

## Så gör du

- Lägg bilder, typsnitt, ljud och stilar i spelets egna filer och peka på dem med en sökväg utan schema (`/a.png`, `./a.png`, `a.png`).
- Hämta aldrig typsnitt, skript eller stilmallar från ett CDN eller en annan domän. Typsnitt och CDN behandlas i `stack-nytt-beroende`.
- Anrop till Computercat går genom `src/lib/computercat/`; andra ursprung finns inte i `connect-src`.
- Behöver spelet en extern resurs som inte går att lägga i repot: fråga en människa. Policyn ändras bara i en ny byggguideversion i computercat-api.
- Fel från spärren och hur de rättas: `security-ratta-sparrfel`.

## Källa

- src/lib/computercat/csp.ts
- resources/byggguide/v2/files/src/app/layout.tsx
- resources/byggguide/v2/files/scripts/cc-gate.mjs
- test/security.test.ts
- resources/byggguide/v2/files/CLAUDE.md
