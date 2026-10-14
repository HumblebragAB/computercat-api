---
id: security-ratta-sparrfel
title: Bygget föll i spärren, rätta felet
topic: security
tags: [bygget föll, fel, cc-gate, check-security, cc-audit, deploy_failed, spärr]
summary: Varje felrad från scripts/cc-gate.mjs och hur den rättas. Rätta alltid källan, aldrig out/, och tysta aldrig ett fynd.
updated: "2026-10-14"
build_guide: [v2]
---

## Regel

Rätta orsaken i spelets källa (inte i `out/`) och kör `npm run check` igen tills det är grönt. Ändra aldrig `scripts/`, `src/lib/computercat/` eller policyn för att få det grönt, och tysta aldrig ett audit-fynd.

## Varför

`scripts/cc-gate.mjs` körs både i `npm run check` och i Coolifys bygge. Ett fel lokalt är samma fel som i Coolify, där inget deployas och Demo visar förra bygget. Skriptet skriver alla fynd och sist `check-security: <antal> fynd. Rätta källan (inte out/) och kör npm run check igen.`

## Så gör du

Visar `get_provisioning_status` `deploy_failed`: kör `npm run check` lokalt och leta upp felraden nedan.

### Säkerhetsdelen (`check-security: <fil>: …`)

- `hemlighet (<namn>)`: ett värde som liknar en nyckel ligger i den publicerade filen. Namnen är `private-key`, `github-token`, `github-pat`, `slack-token`, `slack-webhook`, `stripe-live-key`, `aws-access-key` och `jwt`. Ta bort värdet ur källan. En nyckel som har committats måste bytas; se `security-hemligheter-i-klienten`.
- `source map (*.map publiceras inte)`: ta bort det som lägger `.map`-filer i exporten. Grundkodens `next.config.ts` skapar inga.
- `dold fil (.env, .git m.fl. publiceras inte)`: en fil eller katalog vars namn börjar med punkt hamnar i `out/`. Ta bort den ur det som exporteras. Bara katalogen `.well-known` är tillåten.
- `symbolisk länk (tillåts inte i out/)`: ersätt länken med en vanlig fil.
- `CSP saknas (<meta http-equiv="Content-Security-Policy">)`: taggen sätts i `src/app/layout.tsx`, bara i produktionsbygget. Ta inte bort den där, och låt varje sida använda rotlayouten.
- `mer än en CSP-tagg`: lägg aldrig till en egen CSP-tagg; layouten sätter den enda.
- `CSP avviker från byggguidens policy`: policyn har ändrats, eller `NEXT_PUBLIC_COMPUTERCAT_URL` skiljer sig mellan bygget och kontrollen. Ändra inte `src/lib/computercat/csp.ts`. Skriptet läser variabeln i samma ordning som Next vid bygget: miljön, sedan `.env.production.local`, `.env.local`, `.env.production`, `.env`.
- `extern resurs (<element attribut>: <problem>)`: en resurs pekar utanför samma ursprung. Problemet är ett av `extern adress till <ursprung>`, `protokolllös adress (//)`, `schemat <schema>: tillåts inte`, `ogiltig extern adress` eller `otillåtet tecken (blanksteg, styrtecken eller backslash)`. Lägg resursen i spelets egna filer och peka på den med en sökväg utan schema. Se `security-csp` och, för typsnitt och CDN, `stack-nytt-beroende`.
- `<base> tillåts inte`: ta bort `<base>`.
- `<meta http-equiv="refresh"> tillåts inte`: ta bort taggen.

Två fel stoppar skriptet innan filerna kontrolleras:

- `check-security: out/ saknas. Kör npm run build först.`: kör `npm run build` (eller hela `npm run check`).
- `check-security: NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress.`: adressen måste börja med `https://` (`http://` bara mot `localhost` och `127.0.0.1`) och får inte innehålla `*`. Utan variabeln används `https://api.computercat.co/api/v1`. Fel schema ger redan i bygget `NEXT_PUBLIC_COMPUTERCAT_URL måste börja med https:// (http bara mot localhost).` från `csp.ts`.

### Beroendedelen (`cc-audit: …`)

- `cc-audit: <paket> (high)` eller `(critical)`, följt av `cc-audit: uppdatera paketen (npm audit fix eller en ny version) och pusha igen. Tysta aldrig fyndet.`: gör det som står.
- `cc-audit: npm audit misslyckades (<kod>). Kör npm audit --omit=dev lokalt och läs felet.`: gör det som står.
- `cc-audit: npm audit avslutade med kod <kod> utan high eller critical i rapporten.`: kör `npm audit --omit=dev --audit-level=high` lokalt och läs utdata. Går felet inte att förklara: stanna och beskriv det för en människa.
- `cc-audit: npm-registret svarade inte (<kod>); nytt försök om 10 s.` följt av `cc-audit: npm-registret svarade inte (två försök). Vänta och pusha igen.`: vänta och pusha igen.

`npm audit` utan `--omit=dev` visar en high i braces via eslint. Den finns bara i utvecklingsberoenden, ingen rättad version finns och fyndet är accepterat. Spärren kör `--omit=dev` och fäller inte på den.

## Källa

- resources/byggguide/v2/files/scripts/cc-gate.mjs
- resources/byggguide/v2/files/CLAUDE.md
- resources/byggguide/v2/files/src/app/layout.tsx
- resources/byggguide/v2/files/src/lib/computercat/csp.ts
- resources/byggguide/v2/files/next.config.ts
