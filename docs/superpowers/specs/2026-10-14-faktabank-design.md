# Delprojekt 3: faktabank och säkerhetsspärr i bygget

Datum: 2026-10-14. Status: utkast för Eriks granskning.

## Syfte

Spelens Claude (molnsessioner via Computercat-connectorn) ska kunna fråga Computercat om hur vi bygger spel: vilken teknisk lösning som gäller (det som redan finns i vår stack går före nytt), vilka tester ett spel ska ha, hur säkerheten kontrolleras innan något når Demo, och vår best practice. Målet är detsamma som för delprojekt 1 och 2: Mattias kommer med en idé, och tekniken löser sig utan att någon människa behöver svara på samma frågor varje gång.

Säkerhetskontrollerna ska inte bara vara råd. De körs i bygget, så att ett spel som faller aldrig deployas till Demo.

Ersätter delprojekt 3 i tidigare specar ("dokument-samlingar och AI-mallar"), som skjuts upp tills ett spel behöver det.

## Eriks beslut (2026-10-14)

1. Faktabanken gäller **spelen** (Spelstudion), inte alla Humblebrag-projekt.
2. Innehållet skrivs som **Markdown i computercat-api**, versionerat i git och deployat med API:t. Ingen redigering i Filament.
3. Säkerhetskontrollerna är en **spärr i bygget**: Coolify kör samma kontroll som spelets Claude kör lokalt.
4. Upplägg **A**: artiklar i repot och MCP-verktyg för att lista, söka och läsa. Ingen databas, inga embeddings.
5. Fem ämnen och artikelformen *regel, varför, så gör du, källa* (designdel 1). MCP-verktygen och ingen publik REST (designdel 2). Byggguide v2 med säkerhetskontroller som Coolify kör (designdel 3).

## Avvikelse från designdel 3 (kräver Eriks bekräftelse)

Designdel 3 sa att `get_provisioning_status` skulle visa slutet av byggloggen när Demo-bygget faller. Coolify v4.4.6 lämnar bara ut loggar till en token med `read:sensitive` (`DeployController.php`: `makeVisible(['logs'])` när `can_read_sensitive`; `GET /applications/{uuid}/logs` kräver `api.ability:read:sensitive`). Samma behörighet lämnar ut basic auth-lösenord och env-värden. Computercats token saknar den avsiktligt (minsta privilegium, spec delprojekt 2).

Specen gör därför så här i stället: vid `deploy_failed` säger statusen att bygget föll i Coolify och att samma kontroll körs lokalt med `npm run check`, som ger exakt samma fel eftersom Coolify kör samma kommando. Ingen bredare token behövs.

## Del 1: innehåll

### Ämnen

| Ämne (`topic`) | Innehåll |
|---|---|
| `stack` | Val i ordningen (1) byggguidens stack, (2) Computercats byggstenar för allt som är backend, (3) nytt npm-beroende bara med skriven motivering i commit-meddelandet. Uttryckliga nej: egen backend, API-routes, Firebase/Supabase/liknande, tredjeparts-analytics och spårning utan Eriks beslut, externa typsnitt och CDN-skript. |
| `testing` | Vad som testas och hur: spellogik som rena enhetstester, Computercat-flöden med `test/fakeFetch.ts`, jsdom-integrationstest för vinst och resultat, anti-cheat-bevis. Vad som inte behöver tester. |
| `security` | Vad bygg-spärren kontrollerar (del 3), hur varje fel rättas, och vad spärren inte kan se: anti-cheat-inställningar, rimliga poänggränser, vad som får lagras i sparfiler. |
| `practice` | Mobil först, tillgänglighet, prestanda, offline-kön, svensk copy utan AI-ton (samma regler som Eriks CLAUDE.md, avsnitt Content). |
| `process` | Idé till Demo till Live (`request_live`), vad `get_provisioning_status` betyder, när Claude ska fråga en människa i stället för att gissa. |

### Artikelformat

En fil per artikel: `resources/faktabank/<topic>/<id>.md`.

```markdown
---
id: stack-nytt-beroende
title: Nytt npm-beroende
topic: stack
tags: [beroenden, npm, val]
summary: Ett nytt paket kräver en skriven motivering och att inget i byggguiden eller Computercat redan löser behovet.
updated: 2026-10-14
build_guide: [v2]        # valfritt; utelämnat = gäller alla versioner
---

## Regel
…
## Varför
…
## Så gör du
…
## Källa
- resources/byggguide/v2/files/package.json
```

Fältregler: `id` matchar `^[a-z0-9]+(?:-[a-z0-9]+)*$`, är unikt och lika med filnamnet; `topic` är ett av de fem ämnena och lika med katalogen; `title` högst 80 tecken; `summary` högst 200; `tags` 1–8 korta ord; `updated` ett datum; hela filen högst 12 000 tecken. Rubrikerna `Regel`, `Varför`, `Så gör du` och `Källa` finns i den ordningen.

Varje rad under `Källa` som ser ut som en sökväg (börjar med `resources/`, `app/`, `routes/`, `config/` eller `docs/`) ska peka på en fil som finns i computercat-api. Ett test kontrollerar det.

### Första utkastet

Claude skriver 15–25 artiklar utifrån byggguiden, Computercats API och integrationsmanual (`get_integration_guide`), och de principer i Eriks CLAUDE.md som gäller spel (säkerhet först, fail loud, gissa aldrig externa API:er, copy-regler). Erik granskar innehållet i PR:en innan det går live. Ingen artikel får påstå något om ett externt API som inte har en källa.

## Del 2: hur Claude frågar

### `FactBank` (app/Services/FactBank.php)

Läser `resources/faktabank/**/*.md`, tolkar front matter med `symfony/yaml` (finns i dag bara indirekt; läggs till uttryckligen i `composer.json`) och validerar fältreglerna ovan. En ogiltig artikel är ett fel: tjänsten kastar, och testsviten fäller bygget av computercat-api. Inget cachas i databasen; artiklarna läses från disk per anrop (det är några tiotal små filer). En cachad indexering i minnet per request räcker.

Sökning (`search`): frågan delas i ord (gemener, `mb_strtolower`, å/ä/ö behålls, ord kortare än 2 tecken ignoreras). Poäng per artikel: 5 per ord som finns i `title`, 3 i `tags`, 2 i `summary`, 1 i brödtexten. Bara artiklar med poäng över 0 returneras, högst 10, sorterade på poäng och sedan `id`. Inget stemming-bibliotek; artiklarna skrivs med de ord Claude troligen söker på, och `tags` används för synonymer.

### MCP-verktyg

Alla tre ärver `PlatformTool` (bara staff, revisionsloggade, fel ger referens utan intern text) och läser bara.

| Verktyg | Indata | Svar |
|---|---|---|
| `list_facts` | `topic?` (ett av de fem) | `{topics: [...], facts: [{id, title, topic, summary, tags}]}` |
| `search_facts` | `query` (2–200 tecken), `topic?` | `{query, results: [{id, title, topic, summary, score}]}`; tom lista ger `next` med förslag att köra `list_facts` |
| `get_fact` | `id` | `{id, title, topic, tags, updated, build_guide, markdown}`; okänt id ger fel med förslag att söka |

Beskrivningarna säger när verktyget ska användas: innan ett beroende läggs till, innan topplistor och anti-cheat sätts upp, när `npm run check` faller, innan `request_live`.

Ingen publik REST för faktabanken.

### Koppling till byggguiden

Byggguide v2:s `CLAUDE.md` får ett avsnitt "Faktabanken" med beslutspunkterna ovan och regeln att en artikel går före egen bedömning. Står ingenting i faktabanken och beslutet är stort (nytt beroende utanför listan, ny typ av data till Computercat): beskriv och fråga en människa.

## Del 3: säkerhetsspärren i bygget (byggguide v2)

### Version

`v2` blir en ny låst version (`BuildGuide::FROZEN['v2']`). `v1` ändras inte; Kattsvep och andra v1-spel bygger som förut. `get_build_guide` utan version och `create_game` använder senaste versionen, alltså v2 när den är låst.

### Innehåll i v2 jämfört med v1

1. **`npm run check` får ett sista steg `check:security`** (`node scripts/check-security.mjs` plus `npm audit --omit=dev --audit-level=high`):
   - **Hemligheter i `out/`:** sök i alla filer efter privata nycklar (`-----BEGIN … PRIVATE KEY-----`), GitHub-token (`gh[pousr]_`, `github_pat_`), Slack-token och webhook-URL:er (`xox[abprs]-`, `hooks.slack.com/services/`), Stripe-nycklar (`sk_live_`, `rk_live_`), AWS-nyckel-id (`AKIA`) och JWT (`eyJ…\.eyJ…\.`). En träff fäller med filnamn och mönstrets namn, aldrig träffens värde.
   - **Inga source maps** (`*.map`) i `out/`.
   - **Bara godkända publika variabler:** i `out/` får `NEXT_PUBLIC_` bara förekomma som `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL`. Lokalt kontrolleras också `.env*`-filerna mot samma lista.
   - **CSP:** `out/index.html` har en `<meta http-equiv="Content-Security-Policy">` med minst `default-src 'self'`, `connect-src 'self' https://api.computercat.co`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`. Inga `http:`-källor och ingen `'unsafe-eval'`. Om `script-src` kräver `'unsafe-inline'` för Next.js inline-skript avgörs i spiken (se nedan); hashar föredras om de går att räkna fram vid exporten.
   - **eslint:** regler som förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function` och `setTimeout`/`setInterval` med sträng.
2. **braces-sårbarheten** (dev-beroende via eslint) rättas med `overrides` i `package.json` till en version utan känd sårbarhet, och lås-filen uppdateras.
3. **`CLAUDE.md`** får avsnittet "Faktabanken" och hänvisar till v2 i alla steg.
4. **Ett negativt test i byggguiden:** `test/security.test.ts` kör `check-security.mjs` mot en tillfällig katalog med en planterad nyckel, en `.map`-fil, en otillåten `NEXT_PUBLIC_`-variabel och en HTML-fil utan CSP, och kräver att varje fall fäller med rätt mönsternamn och utan att visa värdet.

### Coolify kör spärren

`CoolifyClient::createApplication()` sätter `build_command: "npm run check"` för nya appar, utöver `install_command: "npm ci --ignore-scripts"`. Nixpacks kör då hela kontrollen i bygget, och `out/` finns kvar för den statiska bilden. Faller kontrollen blir bygget `failed`, och Demo fortsätter visa förra lyckade bygget.

Befintliga appar (Kattsvep) får inte `build_command` i efterhand. Det kräver ett eget beslut när spelet flyttas till v2.

### Status vid fel

`get_provisioning_status` vid `deploy_failed` (Demo-appen skapad med v2) får `next`: "Bygget föll i Coolify. Coolify kör `npm run check`; kör samma kommando lokalt för att se felet, slå upp felet med `search_facts`, rätta och pusha igen." Ingen bygglogg hämtas (se Avvikelse ovan).

### Spike före låsning av v2

Innan `v2` läggs i `FROZEN` verifieras mot riktiga tjänster, med en engångsapp som städas på sparade id:n (samma mönster som spiken i delprojekt 2):

- S1: Nixpacks med `build_command: "npm run check"` bygger, kör vitest och eslint (dev-beroenden installerade) och publicerar `out/`. Byggtid och RAM-topp noteras (servern har 1 vCPU och 4 GB).
- S2: `npm audit` når registret från Coolifys byggmiljö.
- S3: CSP-varianten som väljs låter exemplet ladda och skicka en tid till topplistan i en riktig webbläsare utan CSP-fel i konsolen.
- S4: ett avsiktligt fel (planterad nyckel i `public/`) ger ett `failed` bygge och Demo visar fortfarande förra bygget.

Faller en punkt: stanna och fråga Erik.

## Säkerhet

- Faktabanken innehåller inga hemligheter och inget kundspecifikt. Verktygen är bara läsande och bara för staff.
- `get_fact` returnerar filinnehåll från en vitlista (FactBank-index byggt från `resources/faktabank`); id valideras mot mönstret innan uppslag, så ingen sökväg från anroparen når filsystemet.
- Spärren i bygget ersätter inte granskning: den hittar kända mönster. Artikeln `security` säger det uttryckligen.
- `npm audit` i bygget är tidsberoende: en sårbarhet som publiceras i morgon kan fälla nästa bygge. Det är avsiktligt (fail loud); artikeln beskriver hur man uppdaterar paketet.

## Tester

- `FactBank`: giltiga artiklar läses; varje fältregel fäller med tydligt fel; källhänvisningar som inte finns fäller; sökningens poäng och ordning; tom fråga och för kort fråga.
- Verktygen: staff får svar, icke-staff nekas och loggas, okänt id, okänt ämne, revisionslogg per anrop.
- Innehållstest: alla artiklar i `resources/faktabank` klarar valideringen (körs i ordinarie svit).
- Byggguide v2: `computercat:byggguide:verify v2` grön (samma kommando som för v1), `test/security.test.ts` i guiden, `FROZEN`-hash.
- `CoolifyClient`: `build_command` skickas för nya appar.

## Utanför scope

- Faktabank för andra projekttyper än spel.
- Redigering i Filament, databaslagring, semantisk sökning.
- Byggloggar i status (kräver `read:sensitive`).
- Att flytta Kattsvep till v2.
- Dokument-samlingar och AI-mallar (tidigare delprojekt 3).
