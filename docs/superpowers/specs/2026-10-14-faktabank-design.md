# Delprojekt 3: faktabank och säkerhetsspärr i bygget

Datum: 2026-10-14. Status: utkast för Eriks granskning (reviderat efter subagentgranskning samma dag).

## Syfte

Spelens Claude (molnsessioner via Computercat-connectorn) ska kunna fråga Computercat om hur vi bygger spel: vilken teknisk lösning som gäller (det som redan finns i vår stack går före nytt), vilka tester ett spel ska ha, hur säkerheten kontrolleras innan något når Demo, och vår best practice. Målet är detsamma som i delprojekt 1 och 2: Mattias kommer med en idé, och tekniken löser sig utan att någon människa behöver svara på samma frågor varje gång.

Säkerhetskontrollerna är inte bara råd. De körs i Coolify-bygget, så att ett spel som faller aldrig deployas till Demo.

Ersätter delprojekt 3 i tidigare specar ("dokument-samlingar och AI-mallar"), som skjuts upp tills ett spel behöver det.

## Eriks beslut (2026-10-14)

1. Faktabanken gäller **spelen** (Spelstudion), inte alla Humblebrag-projekt.
2. Innehållet skrivs som **Markdown i computercat-api**, versionerat i git och deployat med API:t. Ingen redigering i Filament.
3. Säkerhetskontrollerna är en **spärr i bygget**.
4. Upplägg **A**: artiklar i repot och MCP-verktyg för att lista, söka och läsa. Ingen databas, inga embeddings.
5. Fem ämnen, artikelformen *regel, varför, så gör du, källa*, tre MCP-verktyg utan publik REST, byggguide v2 med säkerhetskontroller som Coolify kör.

## Avvikelser från den muntliga designen (kräver Eriks bekräftelse)

1. **Ingen bygglogg i status.** Coolify v4.4.6 lämnar bara ut byggloggar till en token med `read:sensitive` (`DeployController.php`: `makeVisible(['logs'])` när `can_read_sensitive`; `GET /applications/{uuid}/logs` kräver `api.ability:read:sensitive`). Samma behörighet lämnar ut basic auth-lösenord och env-värden, och Computercats token saknar den avsiktligt. I stället säger statusen att bygget föll och att samma kontroller körs lokalt (del 3, "Status vid fel").
2. **Säkerhetsskriptet ägs av Computercat, inte av spelrepot.** Om spärren bara vore `npm run check` ur spelets `package.json` kunde spelets egen kod ändra eller ta bort den. Coolifys byggkommando hämtar därför skriptet från Computercat, kontrollerar dess sha256 och kör det. Spelrepot kan inte stänga av det.
3. **Leveransen delas i tre etapper** (A, B, C nedan), eftersom faktabanken, byggguide v2 och artikelinnehållet har olika risk och olika beroenden.

## Etapper

| Etapp | Innehåll | Kan gå live när |
|---|---|---|
| **A. Faktabanken** | `FactBank`, `list_facts`, `search_facts`, `get_fact`, valideringstester, 4–6 fröartiklar som inte refererar v2 | A:s tester och completion-gate är gröna |
| **B. Byggguide v2 och spärren** | Spike S1–S7, sedan v2-filerna, låsning, Coolifys byggkommando, statustext | Spiken är grön och v2 är låst |
| **C. Resterande artiklar** | 10–20 artiklar till, inklusive de som beskriver v2-spärren | B är live; Erik har granskat innehållet i PR:en |

## Del 1: innehåll

### Ämnen

| Ämne (`topic`) | Innehåll |
|---|---|
| `stack` | Val i ordningen (1) byggguidens stack, (2) Computercats byggstenar för allt som är backend, (3) nytt npm-beroende bara med skriven motivering i commit-meddelandet. Uttryckliga nej: egen backend, API-routes, Firebase/Supabase/liknande, tredjeparts-analytics och spårning utan Eriks beslut, externa typsnitt och CDN-skript. |
| `testing` | Vad som testas och hur: spellogik som rena enhetstester, Computercat-flöden med `test/fakeFetch.ts`, jsdom-integrationstest för vinst och resultat, anti-cheat-bevis. Vad som inte behöver tester. |
| `security` | Vad bygg-spärren kontrollerar och hur varje fel rättas, och vad spärren inte kan se: anti-cheat-inställningar, rimliga poänggränser, vad som får lagras i sparfiler. Spärren hittar kända mönster och ersätter inte granskning. |
| `practice` | Mobil först, tillgänglighet, prestanda, offline-kön, svensk copy utan AI-ton (samma regler som Eriks CLAUDE.md, avsnitt Content). |
| `process` | Idé till Demo till Live (`request_live`), vad `get_provisioning_status` betyder, när Claude ska fråga en människa i stället för att gissa. |

### Artikelformat

En fil per artikel: `resources/faktabank/<topic>/<id>.md`.

```markdown
---
id: stack-nytt-beroende
title: Nytt npm-beroende
topic: stack
tags: [beroende, npm, paket, val]
summary: Ett nytt paket kräver en skriven motivering och att inget i byggguiden eller Computercat redan löser behovet.
updated: "2026-10-14"
build_guide: [v2]
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

Regler som `FactBank` validerar:

- Front matter är blocket mellan de två första raderna som bara innehåller `---`. YAML tolkas med `symfony/yaml` (finns i dag bara indirekt; läggs till uttryckligen i `composer.json`) utan objekt- och PHP-taggar (`Yaml::parse($s, 0)`; flaggor som `PARSE_OBJECT` och `PARSE_CUSTOM_TAGS` används inte). `updated` måste vara en citerad sträng `"ÅÅÅÅ-MM-DD"`; ett ociterat datum (som Symfony tolkar som tidsstämpel) fäller.
- `id` matchar `^[a-z0-9]+(?:-[a-z0-9]+)*$`, högst 80 tecken, är unikt och lika med filnamnet. `topic` är ett av de fem ämnena och lika med katalogen. `title` högst 80 tecken, `summary` högst 200, `tags` 1–8 ord, `build_guide` valfri lista med versioner som finns i `BuildGuide::versions()`. Hela filen högst 12 000 tecken (`mb_strlen`).
- Rubrikerna `## Regel`, `## Varför`, `## Så gör du` och `## Källa` finns i den ordningen utanför kodblock.
- Varje rad under `Källa` som börjar med `resources/`, `app/`, `routes/`, `config/` eller `docs/` pekar på en fil som finns i computercat-api (efter att ett eventuellt `:rad`- eller `#ankare`-suffix tagits bort). Rader som börjar med `src/`, `scripts/` eller `test/` avser spelrepot och måste finnas som `files[].path` i manifestet för varje version i artikelns `build_guide` (eller den senaste låsta om fältet saknas).
- Filer läses via en lista byggd med `glob` under `resources/faktabank`; symlänkar och sökvägar som efter `realpath` hamnar utanför katalogen fäller (samma kontroll som `BuildGuide::read`).

Invariant: **ingen artikel byggs av användarinnehåll.** Faktabanken är en instruktionskanal till spelens Claude, och därför kommer allt innehåll från PR-granskade filer.

### Första utkastet

Claude skriver artiklarna utifrån byggguiden, Computercats API och integrationsmanual (`get_integration_guide`) och de principer i Eriks CLAUDE.md som gäller spel (säkerhet först, fail loud, gissa aldrig externa API:er, copy-regler). Erik granskar innehållet i PR:en innan det går live. Ingen artikel påstår något om ett externt API utan källa. Etapp A innehåller bara artiklar som stämmer för både v1- och v2-spel.

## Del 2: hur Claude frågar

### `FactBank` (app/Services/FactBank.php)

Läser och validerar artiklarna enligt reglerna ovan. En ogiltig artikel är ett fel: tjänsten kastar, och testsviten för computercat-api fäller. Artiklarna läses från disk och indexeras i minnet per request; ingen databas.

Sökning:

- Frågan måste vara giltig UTF-8 (annars valideringsfel), 2–200 tecken.
- Tokenisering: `mb_strtolower`, dela på `/[^\p{L}\p{N}]+/u`, behåll tokens med minst 2 tecken, räkna varje unikt frågeord en gång. `npm-beroende` blir `npm` och `beroende`; `check:security` blir `check` och `security`.
- Matchning per token mot artikelns tokens (samma tokenisering): exakt träff, eller prefixträff när frågeordet har minst 4 tecken (så `beroende` träffar `beroenden`).
- Poäng: 5 per frågeord som träffar `title`, 3 i `tags`, 2 i `summary`, 1 i brödtexten (högst en gång per fält och frågeord).
- Filter: `topic` och `build_guide` (se nedan). Bara poäng över 0, högst 10, sorterat på poäng och sedan `id`.

### MCP-verktyg

Alla tre ärver `PlatformTool` (bara staff, revisionsloggade, fel ger referens utan intern text) och läser bara. Namnen klarar `ToolInvariantsTest`.

| Verktyg | Indata | Svar |
|---|---|---|
| `list_facts` | `topic?`, `build_guide?` | `{topics: [...], facts: [{id, title, topic, summary, tags, build_guide}]}` |
| `search_facts` | `query` (2–200 tecken), `topic?`, `build_guide?` | `{query, results: [{id, title, topic, summary, build_guide, score}]}`; tom lista ger `next`: "Kör list_facts för att se ämnena." |
| `get_fact` | `id` (mönster och högst 80 tecken i schemat) | `{id, title, topic, tags, updated, build_guide, markdown}`; okänt id ger fel med förslag att söka |

`build_guide` (t.ex. `"v1"`) filtrerar bort artiklar vars `build_guide` inte innehåller versionen; artiklar utan fältet gäller alla. Beskrivningarna säger att spelets version står i dess `CLAUDE.md` och ska skickas med, och när verktygen ska användas: innan ett beroende läggs till, innan topplistor och anti-cheat sätts upp, när ett bygge faller, innan `request_live`.

Revisionsloggen får argumenten. Söktexten loggas därför; `AuditLogger::summarize` ska redigera hemligheter i den (kontrolleras i etapp A, och kompletteras med `Redactor::scrubSecrets` om den inte gör det).

Ingen publik REST för faktabanken.

## Del 3: säkerhetsspärren i bygget (byggguide v2, etapp B)

### Versioner

- `v2` blir en ny låst version. `v1` ändras inte; Kattsvep och andra v1-spel bygger som förut, utan ändrat byggkommando.
- `BuildGuide::LATEST` byts från `'v1'` till `'v2'` i samma commit som `FROZEN['v2']` fylls i, aldrig tidigare. `versions()` returnerar bara låsta versioner (en katalog utan `FROZEN`-post syns inte i verktyg och REST). Testerna i `BuildGuideTest` som hårdkodar `v1` parametriseras över de låsta versionerna.
- v2 får egna mallar (`templates/README.md` med "byggguide v2") och en `CLAUDE.md` där alla versionshänvisningar är v2.
- `RepoStep` fryser redan guideversionen per run (`detail['build_guide']`); `CoolifyAppStep` läser samma värde och skickar det till `createApplication($slug, $version)`.

### Innehåll i v2 jämfört med v1

1. **`scripts/check-security.mjs`** (ägs av Computercat, del av manifestet) kontrollerar `out/`:
   - Hemligheter, med exakta mönster och ordgränser: privata nycklar (`-----BEGIN [A-Z ]*PRIVATE KEY-----`), GitHub (`\bgh[pousr]_[A-Za-z0-9]{36}\b`, `\bgithub_pat_[A-Za-z0-9_]{50,}\b`), Slack (`\bxox[abprs]-[A-Za-z0-9-]{10,}\b`, `hooks\.slack\.com/services/T[A-Z0-9]+/`), Stripe (`\b[sr]k_live_[A-Za-z0-9]{20,}\b`), AWS (`\bAKIA[0-9A-Z]{16}\b`), JWT (`\beyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b`). En träff fäller med filnamn och mönstrets namn, aldrig värdet. Spiken S5 visar att mönstren ger noll träffar på riktiga exporter.
   - Inga source maps (`*.map`).
   - CSP i varje `out/**/*.html`: en `<meta http-equiv="Content-Security-Policy">` med exakt policyn nedan, där `connect-src` innehåller ursprunget för `NEXT_PUBLIC_COMPUTERCAT_URL` (standard `https://api.computercat.co`).
2. **CSP-policy** (sätts i `src/app/layout.tsx` och kontrolleras av skriptet): `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' <API-ursprung>; object-src 'none'; base-uri 'self'; form-action 'self'`. `'unsafe-inline'` för skript krävs av Next.js-exportens inline-skript (`self.__next_f.push`); hashar kan inte räknas fram förrän efter exporten och skulle kräva ett eget efterbearbetningssteg, vilket inte är värt det för en statisk sida utan användarinmatning som renderas som HTML. `frame-ancestors` går inte att sätta via meta och ligger utanför v2. S3 verifierar policyn i en riktig webbläsare.
3. **eslint-regler** som förbjuder `dangerouslySetInnerHTML`, `eval`, `new Function`, `setTimeout`/`setInterval` med sträng, och `process.env.NEXT_PUBLIC_*` utöver `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL` (kontrollen av publika variabler sker i källkoden, eftersom Next ersätter dem med sina värden i `out/`).
4. **`"test": "NODE_ENV=test vitest run"`**, så att testerna inte körs med React i produktionsläge om byggmiljön sätter `NODE_ENV=production`.
5. **braces-sårbarheten** (dev-beroende via eslint) rättas med `overrides` i `package.json` till den version som `npm audit` anger vid implementationen; `manifest.overrides` och `BuildGuideTest` följer med.
6. **`npm run check`** kör typkontroll, lint, test, bygge, `check-export.mjs` och `check-security.mjs`, så att spelets Claude får samma fel lokalt som Coolify.
7. **`test/security.test.ts`** kör `check-security.mjs` mot en tillfällig katalog med en planterad nyckel, en `.map`-fil och en HTML-fil utan CSP, och kräver att varje fall fäller med rätt mönsternamn och utan värdet. De planterade strängarna byggs vid körning (`'sk_' + 'live_' + …`), så att GitHubs push protection och gitleaks inte stoppar committen.
8. **`CLAUDE.md`** får avsnittet "Faktabanken" (när man frågar, att spelets version skickas med, att en artikel går före egen bedömning och att stora beslut utan artikel frågas till en människa).

### Coolify kör spärren

För nya appar med v2 sätter `CoolifyClient::createApplication($slug, $version)` `build_command` till ett kommando som Computercat äger, inte spelrepots `npm run check`:

Byggkommandot är en enda rad (Coolify tar en sträng); här radbrutet för läsbarhet. `<S>` och `<A>` är sha256 ur v2-manifestet, inskrivna av Computercat när appen skapas.

```bash
npm run build \
 && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/check-security.mjs -o /tmp/cc-security.mjs \
 && curl -fsS https://api.computercat.co/api/v1/build-guide/v2/files/scripts/cc-audit.mjs -o /tmp/cc-audit.mjs \
 && echo "<S>  /tmp/cc-security.mjs" | sha256sum -c - \
 && echo "<A>  /tmp/cc-audit.mjs" | sha256sum -c - \
 && node /tmp/cc-security.mjs \
 && node /tmp/cc-audit.mjs
```

- Säkerhetsskriptet hämtas från byggguidens publika REST (finns sedan delprojekt 2) och kontrolleras mot sha256 som Computercat skriver in när appen skapas. Spelrepot kan inte ändra det. Är hämtningen eller sha256-kontrollen fel: bygget faller.
- `scripts/cc-audit.mjs` (också i v2-manifestet) kör `npm audit --omit=dev --audit-level=high`. Det skiljer fynd (fäller med paketnamn och allvarlighetsgrad) från nätverksfel mot registret (ett omförsök efter 10 s, sedan fäller det med texten "npm-registret svarade inte"). En ny high i ett produktionsberoende stoppar alla byggen tills paketet uppdateras; en höjning av nivån kräver Eriks beslut.
- Typkontroll, lint och tester körs i spelets `npm run check` lokalt men inte i Coolify, eftersom de gäller kvalitet, inte säkerhet, och skulle förlänga bygget i en kö med `concurrent_builds = 1`. Om S1 visar att det ryms inom rimlig tid kan de läggas till senare.
- Befintliga appar (v1) får inget nytt byggkommando; ett test visar att `createApplication($slug, 'v1')` inte skickar `build_command`.

Gräns, skriven i artikeln `security`: spärren skyddar mot misstag och kända mönster i det som publiceras. Den skyddar inte mot spelkod som avsiktligt gör något skadligt i webbläsaren; det fångas av granskning före Live.

### Status vid fel

`get_provisioning_status` läser `detail['build_guide']` från provisioneringsrunnen. Vid `deploy_failed` och v2 blir `next`: "Bygget föll i Coolify. Kör `npm run check` lokalt (samma säkerhetskontroller som Coolify), slå upp felet med `search_facts` och pusha igen. Pekar felet på npm-registret: vänta och pusha igen." Vid v1 eller saknat värde behålls dagens text.

### Spike före låsning av v2

Med en engångsapp som städas på sparade id:n (samma mönster som spiken i delprojekt 2), mot den ofrusna v2-katalogen:

- **S1:** Coolifys byggkommando ovan fungerar i nixpacks static-läge: `out/` publiceras, byggtid och RAM-topp noteras (1 vCPU, 4 GB, `concurrent_builds = 1`) och jämförs med Coolifys timeout för deployment.
- **S2:** `npm audit` och `curl` mot `api.computercat.co` når nätet från byggmiljön, `sha256sum` finns i nixpacks-bilden, och `NODE_ENV` i byggmiljön noteras.
- **S3:** CSP-policyn låter exemplet ladda och skicka en tid till topplistan i en riktig webbläsare utan CSP-fel i konsolen.
- **S4:** förutsatt ett tidigare lyckat bygge: en planterad nyckel i `public/` ger ett `failed` bygge, och Demo visar fortfarande förra bygget.
- **S5:** `check-security.mjs` ger noll träffar på `out/` från v1-exemplet och från Kattsvep.
- **S6:** ett spelrepo som tagit bort `check-security.mjs` eller ändrat `package.json` fäller ändå i Coolify (skriptet hämtas från Computercat).
- **S7:** `npm run check` med `NODE_ENV=production` i miljön är grön (testskriptet sätter `NODE_ENV=test`).

Faller en punkt: stanna och fråga Erik.

## Säkerhet

- Faktabanken innehåller inga hemligheter och inget kundspecifikt. Verktygen är bara läsande och bara för staff. `get_fact` slår upp id i ett index byggt från `resources/faktabank`, och id valideras mot mönstret i schemat innan uppslag.
- Söktext i revisionsloggen redigeras (del 2).
- Säkerhetsskriptet i bygget kan inte ändras från spelrepot (sha256 från Computercat). Spärren hittar kända mönster; den ersätter inte granskning.
- `npm audit` i bygget är tidsberoende och kan stoppa byggen när en ny sårbarhet publiceras. Det är avsiktligt.

## Tester

- **A:** `FactBank` (giltiga artiklar, varje valideringsregel fäller med tydligt fel, källhänvisningar med och utan suffix, symlänk och `realpath`, ociterat datum, rubrik i kodblock, sökningens tokenisering, prefixträff, poäng och ordning, ogiltig UTF-8, filter på `build_guide`); verktygen (staff, icke-staff nekas och loggas, okänt id, okänt ämne, revisionslogg med redigerad söktext); innehållstest av alla artiklar i ordinarie svit.
- **B:** `computercat:byggguide:verify v2`; `test/security.test.ts` i guiden; `FROZEN`-hash; parametriserade `BuildGuideTest`; `CoolifyClient` skickar byggkommandot med rätt sha256 för v2 och inget för v1; statustexten för v1 och v2.
- **C:** innehållstestet täcker de nya artiklarna; Erik granskar texten.

## Utanför scope

- Faktabank för andra projekttyper än spel.
- Redigering i Filament, databaslagring, semantisk sökning.
- Byggloggar i status (kräver `read:sensitive`).
- Typkontroll, lint och tester i Coolify-bygget (kan läggas till efter S1).
- Att flytta Kattsvep till v2.
- Dokument-samlingar och AI-mallar (tidigare delprojekt 3).
