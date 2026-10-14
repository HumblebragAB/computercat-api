# Faktabank etapp C: resterande artiklar

Spec: `docs/superpowers/specs/2026-10-14-faktabank-design.md` (rad C i "Etapper", "Del 1: innehåll", "Artikelformat"). Etapp B (byggguide v2) är live sedan 2026-10-14. Erik granskar innehållet i en PR innan det går live.

**Eriks riktning (2026-10-14):** bara regler som gäller tekniken i spelen. Målet är att spelen håller sig till stacken, är tekniskt säkra, har tester som går att köra och är uppsatta på samma sätt så att underhållet blir enkelt. Ingen copy, design eller tillgänglighet i den här etappen.

## Global Constraints

- Format och validering enligt specens "Artikelformat". Källor under `## Källa` är fulla sökvägar: `resources/…`, `app/…`, `routes/…`, `config/…`, `docs/…` (filer i computercat-api), eller `src/…`, `scripts/…`, `test/…` (spelrepot; måste finnas i manifestet för varje version i artikelns `build_guide`, annars senaste låsta). Byggguidens rotfiler citeras som `resources/byggguide/v2/files/<fil>`. Inga artikel-id under Källa; hänvisningar till andra artiklar görs med id i brödtexten.
- Varje påstående har stöd i källan. Det som inte står i källan skrivs inte.
- `build_guide`: utelämnas bara när varje källa är lika i v1 och v2 och regeln stämmer för båda. Annars `[v2]` (eller `[v1, v2]` med en mening om skillnaden).
- Svenska, kort, regel först. Inga tankstreck (— eller –) i brödtext. `updated: "2026-10-14"`, högst 12 000 tecken.
- Upprepa inte "bygget ska klara sig utan nätverk" (motsäger npm audit i spärren).
- Inget användarinnehåll i artiklarna.

## Uppgift 0: testet för fröartiklarna

`tests/Feature/FactBankContentTest::test_seed_articles_apply_to_every_build_guide_version` gäller alla artiklar och fäller v2-artiklar. Begränsa det till de åtta fröartiklarnas id (lista i testet), så att etapp A:s löfte står kvar och etapp C kan ha `build_guide: [v2]`.

## Artiklar (13 nya, en ändrad)

| id | topic | build_guide | Kärna | Källor (minst) |
|---|---|---|---|---|
| security-sparren-i-bygget | security | v2 | Vad `cc-gate.mjs` kontrollerar och i vilken ordning, att repot inte kan stänga av den (kommandot ägs av Computercat), gränsen: fångar misstag och kända mönster, inte avsiktligt skadlig kod, ersätter inte granskning före Live | resources/byggguide/v2/files/scripts/cc-gate.mjs, resources/byggguide/v2/files/CLAUDE.md, app/Services/BuildGuide.php |
| security-ratta-sparrfel | security | v2 | Varje felrad och hur den rättas: hemlighet (mönsternamn), source map, dold fil, symlänk, CSP saknas, mer än en CSP-tagg, CSP avviker, extern resurs, `<base>`, `<meta http-equiv="refresh">`, `out/` saknas, ogiltig `NEXT_PUBLIC_COMPUTERCAT_URL`, audit high/critical, `npm audit misslyckades`, kod utan high, registret svarar inte. braces-fyndet i dev-beroenden är accepterat. Titel/taggar med "bygget föll" | resources/byggguide/v2/files/scripts/cc-gate.mjs, resources/byggguide/v2/files/CLAUDE.md |
| security-csp | security | v2 | Policyn, varför externa skript, stilmallar, bilder och typsnitt fäller, att resurser läggs i `public/` eller importeras, att policyn inte ändras och bara renderas i produktionsbygget. Länka `stack-nytt-beroende` för typsnitt/CDN i stället för att upprepa. Taggar "typsnitt", "font", "extern" | src/lib/computercat/csp.ts, resources/byggguide/v2/files/src/app/layout.tsx, resources/byggguide/v2/files/scripts/cc-gate.mjs, test/security.test.ts |
| security-lint-regler | security | v2 | Förbjudet i `src/`: `dangerouslySetInnerHTML` (även tilldelning och hakparentes), `eval`, `new Function`, `setTimeout`/`setInterval` med sträng (`no-implied-eval`), `process.env` utöver direkt punktläsning av `NODE_ENV`, `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL` (inga alias, ingen destrukturering, ingen hakparentes). Stäng aldrig av en regel; fråga faktabanken och sedan en människa | resources/byggguide/v2/files/eslint.config.mjs, test/eslintRules.test.ts, resources/byggguide/v2/files/CLAUDE.md |
| security-anti-cheat | security | – | Klienten är aldrig betrodd. Servern kontrollerar resultat mot spelets inställningar (`min_score`, `max_score`, `require_proof`, `min_moves`, tidstolerans) som en människa sätter i Filament; `get_game` visar dem inte. Spelets Claude föreslår rimliga gränser och ber en människa sätta dem, och skickar `createProof(...)` i `metadata.proof` när spelet kräver bevis | app/Services/AntiCheatService.php, app/Filament/Resources/GameResource.php, src/lib/computercat/proof.ts, docs/game-integration-guide.md |
| security-sparfiler | security | – | Vad sparningar är (storleksgräns, checksumma, version), merge vid konflikt, inga hemligheter; personuppgifter kräver en människas beslut | app/Http/Controllers/Api/V1/GameSaveController.php, src/lib/computercat/saves.ts, docs/game-integration-guide.md |
| stack-ingen-tredjepartssparning | stack | – | Ingen tredjeparts-analytics, spårning eller reklam utan Eriks beslut; Computercats händelser och byggstenar används i stället (verifieras: finns events-byggstenen och vad den gör) | docs/superpowers/specs/2026-10-14-faktabank-design.md, app/Mcp/Tools/UpsertGameEventTool.php (verifieras), resources/byggguide/v2/files/CLAUDE.md |
| stack-samma-upplagg | stack | v2 | Samma upplägg i varje spel för underhåll: `src/lib/computercat/` och `scripts/` ändras aldrig i spelet (ny byggguideversion i stället), spelets kod i `src/game/`, topplistor skapas med `upsert_leaderboard_type`, exemplet byts ut enligt CLAUDE.md (konstanter, titel, paketnamn, lagringsnyckel, `computercat:idea`-raderna), serverlogik som saknas blir en PR mot computercat-api | resources/byggguide/v2/files/CLAUDE.md, resources/byggguide/v2/manifest.json, test/docs.test.ts |
| testing-npm-run-check | testing | v2 | Vad `npm run check` kör i ordning (typkontroll, lint, test, bygge, `out/`, spärren); grönt före varje push; testerna som följer med byggguiden (`test/security.test.ts`, `test/ccAudit.test.ts`, `test/eslintRules.test.ts`, `test/docs.test.ts`) ändras eller tas inte bort | resources/byggguide/v2/files/package.json, resources/byggguide/v2/files/CLAUDE.md, test/security.test.ts, test/ccAudit.test.ts, test/eslintRules.test.ts |
| testing-fakefetch | testing | – | Computercat-flöden testas med `test/fakeFetch.ts`, aldrig mot riktiga API:t; hur ett flöde sätts upp | test/fakeFetch.ts, test/integration.test.tsx |
| testing-anti-cheat-bevis | testing | – | Testa att bevis skapas och skickas när spelet kräver det (`createProof`) | src/lib/computercat/proof.ts, src/lib/computercat/proof.test.ts |
| practice-offline-kon | practice | – | Resultat köas lokalt och skickas i bakgrunden; visa `flushed`, dölj aldrig fel, visa aldrig påhittad topplistedata, `recordScore` kastar vid full lagring | src/lib/computercat/queue.ts, src/components/BackgroundSync.tsx, src/lib/computercat/index.ts |
| process-provisioning-status | process | – | Varje `demo.state` och vad Claude gör (awaiting_code, building, live, deployed_not_answering, deploy_failed inkl. `cancelled-by-user`, unknown); i v2 ger `deploy_failed` en `next`-text, i v1 inte. Ingen punktlista i formatet "- `nyckel`: text" | app/Mcp/Tools/GetProvisioningStatusTool.php |
| process-fraga-manniska | process | – | När Claude frågar en människa: serverlogik som saknas, nytt beroende med stor påverkan, säkerhet, personuppgifter, pengar, samma fel två gånger, en lint-regel som behöver stängas av (v2). Länka `stack-valordning`, `process-ide-till-live`, `security-hemligheter-i-klienten` med id | resources/byggguide/v2/files/CLAUDE.md, resources/byggguide/v1/files/CLAUDE.md |
| process-ide-till-live (ändras) | process | – | Tillståndslistan flyttas till `process-provisioning-status`; här blir det en hänvisning med id | befintliga källor |

## Uppgifter

0. Testet för fröartiklarna (ovan). TDD: kör testet före och efter.
1. Källkontroll per artikel innan text skrivs; exakta namn och felrader ur källan.
2. Skriv artiklarna, en commit per ämne.
3. Sökfrågor som assertions i `test_common_questions_find_the_right_article`: "bygget föll" → security-ratta-sparrfel, "extern font" → security-csp, "anti cheat" → security-anti-cheat, "deploy_failed" → process-provisioning-status, "analytics" → stack-ingen-tredjepartssparning. Titel och taggar justeras tills rätt artikel kommer först. Full svit grön.
4. Innehållsgranskning av en oberoende agent mot källorna (varje påstående).
5. completion-gate, sedan PR till main för Eriks granskning. Live först efter hans godkännande och order.
