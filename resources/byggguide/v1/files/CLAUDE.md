# CLAUDE.md

Det här är ett Computercat-spel. Spelets idé står under "Idé" i `README.md`; läs den först.

Byggguide: **v1**. Grundkoden (Computercat-klienten, konfiguration, tester och ett exempelspel) kommer från Computercats byggguide. Det finns inget mallrepo.

## Steg 1: grundkoden (bara när `package.json` saknas)

Ett nytt spelrepo innehåller bara `README.md` och den här filen. Gör så här innan något annat:

1. Anropa `get_build_guide` i Computercat-connectorn med `{"version": "v1"}`. Svaret listar stacken med exakta versioner, installationskommandot, de obligatoriska kontrollerna och varje fil med `path`, `sha256` och `delivery`.
2. Filer med `delivery: "mcp"`: hämta med `get_build_guide_file` (`{"path": "<path>", "version": "v1"}`) och skriv `content` **exakt** till `path` i repots rot. Filer med `delivery: "download"` (bland annat `package-lock.json`): `curl -fsS "<download_url>" -o "<path>"`.
3. Kontrollera varje fil mot manifestet med `sha256sum <path>` (eller `shasum -a 256 <path>`). Avviker en fil: hämta den igen. Ändra den aldrig för hand.
4. `README.md` finns inte i fillistan; låt den vara. `CLAUDE.md` finns i listan med samma innehåll som den här filen.
5. Kör `npm ci`, sedan `npm run check` och `npm audit --omit=dev --audit-level=high`. Alla ska gå igenom utan att du ändrat något.
6. Committa allt med meddelandet `Grundkod från byggguide v1` och pusha direkt till `main` (ingen annan gren). Coolify bygger då Demo för första gången. Följ upp med `get_provisioning_status` tills `demo.state` är `live`; Demo visar då exemplet (klickrace).
7. Bygg sedan spelet enligt idén och reglerna nedan.

Faller ett steg (verktyget svarar med fel, en kontroll blir röd utan att du ändrat något, `sha256` stämmer inte efter ny hämtning): stanna och beskriv felet. Laga aldrig byggguidens filer för att få det grönt; felet hör hemma i computercat-api.

## Regler

- **Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.** All kommunikation går genom `src/lib/computercat/`.
- **Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg** (`get_game`, `upsert_*`). **Hämta integrationsmanualen med `get_integration_guide`** innan du använder en byggsten du inte använt förut.
- **Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.** Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
- **Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.**
- **Klientkoden i `src/lib/computercat/` kommer från byggguiden.** Ändra den inte i spelet; behövs en ändring hör den hemma i en ny byggguideversion i computercat-api.

## Köra och testa lokalt

```bash
npm ci
cp .env.example .env.local    # NEXT_PUBLIC_COMPUTERCAT_GAME=<spelets slug>
npm run dev                    # http://localhost:3000 (localhost är tillåtet i Computercats CORS)
npm test                       # enhetstester, inget nätverk
npm run check                  # typkontroll, lint, test, bygge, kontroll av out/
```

**Före varje push till `main`:** (1) `npm run check` grön, (2) `npm audit --omit=dev --audit-level=high` utan träff (avslutar med fel vid high eller critical i produktionsberoenden; åtgärda med `npm audit fix` eller uppdatera paketet, tysta aldrig). Bygget är en statisk export (`output: 'export'`): inga API-routes, inga serverkomponenter som hämtar data, ingen `next/font/google` (bygget ska klara sig utan nätverk).

## Computercat i koden

- `getComputercat()` ger klienten i webbläsaren. Den kastar aldrig: saknas `NEXT_PUBLIC_COMPUTERCAT_GAME` (eller körs den utanför webbläsaren) returnerar den `{ ok: false, error }`, och det felet ska visas för spelaren.
- Spelaren (token) förnyas automatiskt vid start om den är äldre än 24 h (tokens gäller 90 dagar); du behöver inte göra något. `recordScore` kastar om resultatet inte kan sparas lokalt (full lagring): visa det för spelaren.
- Resultat: `recordScore(typ, score, metadata?)` köar lokalt och skickar i bakgrunden. Visa `flushed`-resultatet för spelaren; dölj aldrig fel och visa aldrig påhittad topplistedata.
- Topplistor: `leaderboards.get(typ)` (aktuell period enligt servern, UTC), `leaderboards.me(typ)`.
- Sparningar: `saves.sync(nyckel, lokalt, merge)` hämtar, slår ihop och skickar med version.
- Anti-cheat: `createProof(...)` i `metadata.proof` om spelet kräver bevis.

## Exemplets topplista

Exemplet skickar till `daily-clicks`. Skapa den innan du testar på Demo:

`upsert_leaderboard_type` med `{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}`

När exemplet ersätts: skapa spelets egna topplistor på samma sätt, byt `LEADERBOARD_TYPE` i `src/game/constants.ts` och ta bort `src/game/exampleDocs.test.ts` tillsammans med exemplet. Byt också `title` i `src/app/layout.tsx`, `name` i `package.json` (kör sedan `npm install --package-lock-only`) och lagringsnyckeln `spelmall-click` (prefixet i `src/game/clickStore.ts`) till spelets namn.

Låt de två raderna `computercat:idea` i `README.md` vara kvar; `test/docs.test.ts` vaktar dem.

## Säkerhet

Allt i `NEXT_PUBLIC_*` hamnar i den publika koden. Lägg aldrig nycklar eller lösenord där eller i repot.
