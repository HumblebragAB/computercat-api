# Spelplattformen: från idé till spelbar Demo — design

Datum: 2026-10-13
Status: utkast för granskning (Erik)

## Mål

Mattias ska bara behöva komma med en spelidé. Tekniken ska lösa sig: Claude bygger spelet, backend finns redan, hostingen sätts upp automatiskt. Ingen handpåläggning från Erik per nytt spel, utom vid gång till Live.

Mattias arbetar i Claude Code-molnsessioner (inte lokalt), så allt måste nås därifrån.

## Beslut (fattade i brainstorm 2026-10-13)

1. **Computercat API är enda backend.** Inga egna backends eller databaser per spel. Julklappsspel är ett befintligt undantag som inte ändras.
2. **Coolify (apps.humblebrag.se) hostar bara spelens frontend**, statiska byggen. Inga databaser för spel där.
3. **Computercat är kontrollpunkten.** Den håller GitHub-, Coolify- och SiteOps-nycklarna och provisionerar åt Mattias. Han får aldrig egna infra-credentials.
4. **Gränssnittet är en remote MCP-server i Computercat**, tillagd som custom connector i claude.ai. Den når molnsessioner i Claude Code och vanliga Claude-chatten; connector-trafiken går via Anthropic och behöver inte miljöns nätverkslista. (Källor: code.claude.com/docs/en/claude-code-on-the-web.md, cloud-environments.md.)
5. **Spelspecifik serverlogik:** bredare byggstenar täcker det mesta; behövs mer öppnar Mattias Claude en PR mot computercat-api som Erik granskar.
6. **Live-gång** går via Erik (`request_live` larmar, Erik flyttar).

## Helheten

```
Mattias i Claude (molnsession / chatt)
        │  MCP (OAuth, per användare)
        ▼
Computercat API (Forge) ──► GitHub App (HumblebragAB)   [delprojekt 2]
   │  data + konfig      ──► Coolify API (Demo-projekt)  [delprojekt 2]
   │                     ──► SiteOps API                 [delprojekt 2]
   ▼
Spelets frontend på Coolify (*.apps.humblebrag.se) ──HTTPS/CORS──► Computercat REST /api/v1
```

Flödet för Mattias när allt är byggt:

1. "Nytt spel: Ordjakt" → `create_game` skapar spelpost, repo från mallen, Coolify-app i Demo med basic auth, SiteOps-registrering och CORS-origin. Svar: repo-URL + Demo-URL.
2. Molnsession på repot. Claude bygger spelet och konfigurerar backend via MCP-verktyg.
3. Push till main → Coolify bygger om Demo.
4. Serverlogik som saknas → PR mot computercat-api, Erik granskar.
5. `request_live` → Slack-larm till Erik, som flyttar till Live och lägger på domän.

## Uppdelning

| Delprojekt | Innehåll | Värde när det är klart |
|---|---|---|
| **1. Grunden** (denna spec, detaljerad nedan) | OAuth + MCP-server, medlemskap per spel, konfigverktyg för befintliga byggstenar, CORS per spel, revisionslogg, Dev Kit utan personlig token | Mattias Claude konfigurerar ett befintligt spel själv |
| **2. Provisionering** (egen spec senare) | Mallrepo, `create_game` (GitHub App, Coolify, SiteOps) som stegvis saga med omkörbara steg, `request_live` | Nytt spel från en mening till spelbar Demo-URL |
| **3. Nya byggstenar** (egen spec senare) | Dokument per spel med samlingsregler och kvoter; AI-mallar med dagsbudget och nödbrytare | Färre PR:er för vanliga idéer |

Delprojekt 2 och 3 specas när 1 är levererat. Det som står om dem här är riktning, inte krav.

---

# Delprojekt 1: Grunden

## Omfattning

**Ingår**

- Passport 13 + `laravel/mcp` 1.x, OAuth för MCP med Dynamic Client Registration.
- MCP-server på `https://api.computercat.co/mcp`.
- Medlemskap per spel och en personallista (staff) som styr vem som alls får använda MCP.
- MCP-verktyg för att läsa och konfigurera befintliga byggstenar (se verktygslistan).
- Tillåtna origins per spel (CORS).
- Revisionslogg över varje MCP-anrop.
- Dev Kit: personlig token bort ur manualen; manualgeneratorn flyttas till en service som MCP också använder.

**Ingår inte**

- Att skapa spel via MCP (delprojekt 2; spel skapas i Filament tills dess).
- Produkter, köp, ägarskap, RevenueCat (pengar; rörs bara av Erik).
- `games.settings` (bär hemligheter, t.ex. `revenuecat.webhook_secret`).
- Spelardata: användare, topplisteposter, sparningar, achievements per spelare.
- Radering av något. Avaktivering sker med `is_active: false`.
- Glosis-specifika saker (röster, attest, skanning).

## Förutsättningar att verifiera först

Dessa är inte bekräftade och verifieras i början av implementationen, innan kod skrivs som beror på dem:

1. Att `laravel/mcp`:s transport fungerar mot en claude.ai custom connector, inklusive redirect-URI:n Claude använder. Verifieras med en minimal server mot en riktig connector innan resten byggs.
2. Att Passport 13 och Sanctum 4 kan leva i samma app. Krocken sitter i modellen, inte bara i guards: båda paketen har en `HasApiTokens`-trait med `tokens()`, `createToken()` m.fl., och `User` använder Sanctums (`app/Models/User.php:15`). Planerad lösning: en separat modell `StaffUser extends User` som bara använder Passports trait, bakom en egen provider `staff` för guarden `mcp`. `User` behåller Sanctums trait orörd, så `$user->tokens()->delete()` i `AuthController` fortsätter radera Sanctum-tokens. Verifieras med ett spike-test innan resten byggs.
3. Att `laravel/mcp` eller Passport 13 levererar en authorize-vy (Passport 13 har ingen egen). Annars byggs en enkel Blade-vy.
4. Vilken plan Humblebrag har i claude.ai. På Team/Enterprise lägger en Owner till connectorn för organisationen; på Pro/Max lägger var och en till den själv.

Faller 1 eller 2: stanna och fråga Erik innan en alternativ väg väljs.

## Datamodell

**`game_user`** (ny pivot)

| kolumn | typ | not |
|---|---|---|
| game_id | fk games, cascade | |
| user_id | fk users, cascade | |
| role | string: `owner` \| `member` | v1 behandlar båda lika; rollen finns för delprojekt 2 |
| timestamps | | |

Unik på (game_id, user_id). Hanteras i Filament med en relation manager "Medlemmar" på spelet.

**`games.allowed_origins`** (ny kolumn, json, nullable). Lista av origins, t.ex. `["https://ordjakt.apps.humblebrag.se"]`.

**`mcp_audit_logs`** (ny tabell)

| kolumn | typ | not |
|---|---|---|
| user_id | fk users, nullOnDelete | |
| game_id | fk games, nullable, nullOnDelete | null för `list_games` |
| tool | string | |
| arguments | json | sammanfattning, inte trunkerad rå-JSON: skalära fält som de är, strängar kapade till 200 tecken, listor ersatta med antal poster. Alltid giltig JSON |
| outcome | string: `ok` \| `validation_error` \| `denied` \| `error` | |
| created_at | timestamp | |

Raden skrivs utanför verktygets transaktion, så att misslyckade och nekade anrop också hamnar i loggen. Visas skrivskyddat i Filament. Gallras inte i v1.

**Personal (staff) och plattformsadmin.** Två nya boolean-kolumner på `users`: `is_staff` och `is_platform_admin`, default false. De ersätter den hårdkodade e-postlistan i `User::canAccessPanel()` (`app/Models/User.php:17-23`).

Skälet: spelare finns i samma `users`-tabell och kan registrera vilken ledig e-postadress som helst utan verifiering (`AuthController::register`, `unique:users,email`). En e-postlista är därför ingen identitet. Den luckan finns redan idag för panelen, och MCP skulle göra den större.

- Flaggorna sätts bara med ett artisan-kommando (`computercat:staff {email} --grant|--revoke [--admin]`) som Erik kör, aldrig via API:t och inte via `$fillable`.
- Migrationen sätter inga flaggor själv. Erik kör kommandot för sitt och Mattias konto efter att ha kontrollerat att det är rätt konton (id, skapat-datum).
- `is_banned` nekar alltid, oavsett flaggor.
- `canAccessPanel()` blir `is_staff && ! is_banned`. Panelen och MCP använder samma kontroll.

## OAuth och MCP-server

- `composer require laravel/passport:^13 laravel/mcp:^1`. Passport-nycklar genereras med `passport:keys` och lagras utanför git med behörighet 600.
- Guard `mcp` (driver `passport`, provider `staff` → `StaffUser`, se förutsättning 2). Sanctums `auth:sanctum` på `/api/v1` ändras inte.
- `Mcp::oauthRoutes()` med registrering påslagen. `config('mcp.redirect_domains')` låses till Claudes domän(er) i stället för standardvärdet `*`; exakt värde bestäms i förutsättning 1.
- Passports authorize-sida kräver inloggad webbsession. En route med namnet `login` som redirectar till Filaments `/admin/login` krävs (Laravel 12 kastar annars). Rundturen authorize → login → tillbaka till authorize via `intended` testas uttryckligen.
- **Grind på två ställen:** (a) en middleware `EnsureStaff` läggs på Passports authorize-routes (via Passports middleware-konfiguration, eller genom att registrera routes själv om det inte går), (b) samma middleware på `/mcp`. Tas flaggan bort, eller blir kontot bannat, slutar en redan utfärdad token fungera på nästa anrop.
- Access token 1 h, refresh token 30 dagar. Scope `mcp:use` (det enda paketet skapar).
- Rate limit: en egen namngiven limiter, `mcp`, 120 anrop/minut per användare. `/mcp` ligger utanför `api`-gruppen och omfattas inte av dess throttle.

## MCP-verktyg

Gemensamt för alla verktyg som tar `game`:

- `game` är spelets slug.
- Användaren måste vara medlem i spelet (eller plattformsadmin, se nedan). Annars blir svaret "Game not found", samma som för ett spel som inte finns, så att andra spels slugs inte kan listas ut.
- Alla skrivverktyg är upserts med slug/nyckel som identitet.
- Verktygen når även inaktiva spel som användaren är medlem i, så att ett spel kan konfigureras innan det aktiveras.
- **Validering skärps, den kopieras inte.** Panelens regler sitter idag kedjade på Filament-komponenter och täcker inte allt. De flyttas till en regelklass per byggsten (`App\Rules\Config\LeaderboardTypeRules` osv.) som både relation managern och verktyget anropar. Nya regler som också gäller i panelen:
  - slug/nyckel: `^[a-z0-9][a-z0-9_.-]*\z` med dagens maxlängder (50 för topplistor och achievements, 100 för övriga), unik per spel (idag ger en dubblett ett 500-fel från DB:n). Formatet kontrolleras bara när värdet är nytt eller ändras, så befintliga poster med äldre slugs går att redigera.
  - `max_entries_per_period`: minst 1, högst 10 000
  - game event: `ends_at` efter `starts_at`; `settings` är ett JSON-objekt på högst 16 KB som **ersätter** det tidigare värdet (det skickas orört till spelare via `GameEventController`)
  - remote config: `value` måste gå att tolka som `value_type` (idag sparas ogiltig JSON och blir `null` vid läsning)
  - content pool: högst 5000 poster (finns redan) och högst 512 KB serialiserat (nytt)
- Varje anrop skrivs till revisionsloggen, också nekade och misslyckade.

**Plattformsadmin.** `is_platform_admin` ger tillgång till alla spel utan medlemskap (Erik). Mattias får tillgång genom medlemskap.

Obs: i Filament ser all staff fortfarande alla spel. Medlemskapet begränsar alltså bara MCP. Det är avsiktligt: MCP-tokens lever i claude.ai och är den yta som oftast kan kapas, så det är där skadeverkan ska vara minst. Att även begränsa panelen per spel ingår inte här.

| Verktyg | Gör | Not |
|---|---|---|
| `list_games` | Spel användaren når: slug, namn, aktiv, Demo/Live-URL om känd | |
| `get_game` | Hela konfigurationen: topplistetyper, achievements, pooler (nyckel, antal poster, aktiv, slumpad åtkomst), remote config, events, tillåtna origins, base URL | Aldrig `settings`, aldrig tokens |
| `get_integration_guide` | Integrationsmanualen i markdown för spelet, utan token. Valfritt `features`; utelämnat härleds det som Dev Kit gör idag | Samma service som Dev Kit. Manualen läser anti-cheat-inställningar ur `settings` och skriver ut dem; det är avsiktligt och inte hemligt |
| `upsert_leaderboard_type` | slug, name, sort_direction, score_label, period, max_entries_per_period, is_active | |
| `upsert_achievement` | slug, name, description, icon, sort_order, is_secret, is_active | |
| `upsert_content_pool` | pool_key, content (array), starts_at, random_access, is_active | 5000 poster, 512 KB |
| `upsert_remote_config` | key, value, value_type, description, is_active | |
| `upsert_game_event` | slug, name, description, event_type, starts_at, ends_at, settings, is_active | `settings` här är eventets, inte spelets; ersätts helt |
| `set_allowed_origins` | Ersätter spelets lista | Valideras, se CORS |

Inga raderingsverktyg. Inga verktyg för produkter, köp, `games.settings` eller spelardata.

**Känd lucka:** topplistornas anti-cheat-inställningar ligger i `games.settings` och kan därför inte sättas via MCP i v1. Claude kan läsa dem i manualen men får be Erik ändra dem i Filament. Ett eget verktyg för just de nycklarna kan läggas till i delprojekt 3.

## CORS per spel

Laravels `HandleCors` läser config, och config kan inte läsa databasen. Därför läggs en middleware före `HandleCors` som för varje API-request slår ihop:

- den statiska listan i `config/cors.php` (oförändrad: Tocco, localhost m.fl.),
- unionen av `allowed_origins` för alla spel, aktiva som inaktiva.

Middlewaren läggs globalt med `$middleware->prepend(...)` i `bootstrap/app.php`, eftersom `HandleCors` är global och preflight-anrop (`OPTIONS`) annars aldrig passerar den. Unionen cachas och nyckeln rensas när ett spel sparas.

Inaktiva spel tas med eftersom aktiva spel listas publikt i `GET /games`. Ett spel under utveckling ska kunna vara inaktivt och ändå nå API:t från sin Demo-URL. Det är ofarligt: `ResolveGame` svarar ändå 404 för inaktiva spel på spelbundna routes. Det är medvetet en union och inte "bara detta spels origins": `/auth/anonymous` och `/auth/me` är inte spelbundna, och API:t använder bearer-tokens utan cookies (`supports_credentials: false`), så CORS är här en hygiengräns, inte en behörighetsgräns.

Validering av en origin per spel (de statiska posterna i `config/cors.php`, t.ex. `https://*.tocco.app`, berörs inte): `https://`, bara schema + host (+ ev. port), ingen path, inga jokertecken. `http://` tillåts bara för localhost, som redan är globalt tillåtet. Ogiltig origin ger valideringsfel; den sparas aldrig delvis.

## Dev Kit

- `getDevToken()` och tokenraden i manualen tas bort. Manualen pekar på anonym inloggning för test också.
- Manualgenereringen flyttas från `GameDevKit` till en service (t.ex. `App\Services\IntegrationGuide`) som både sidan och `get_integration_guide` använder.
- Befintliga tokens med namnet `devkit` återkallas med ett kommando som först skriver ut antalet och listar ägarna, och raderar bara efter bekräftelse. Körs i prod först efter Eriks OK. (Tokens går ut efter 7 dagar, så antalet bör vara litet.)

## Felhantering

- Valideringsfel returneras som MCP-verktygsfel med fältvisa meddelanden, så att Claude kan rätta och försöka igen.
- Nekad åtkomst ser ut som "Game not found".
- Oväntade fel loggas med verktyg, användare och trunkerade argument, och returneras som ett generiskt fel med ett referens-id som också står i loggen.
- Ingen tyst fallback: misslyckas ett upsert skrivs ingenting (transaktion). Revisionsraden skrivs ändå, utanför transaktionen.

## Test

PHPUnit-klasser som resten av projektet, i `tests/Feature/Mcp/`.

- Per verktyg: lyckat anrop, valideringsfel, icke-medlem får "Game not found", okänd slug ger "Game not found", inaktivt spel där användaren är medlem fungerar.
- **Invariant: varje registrerat verktyg som tar `game` nekar icke-medlemmar.** Testet itererar över serverns verktygslista, så ett nytt verktyg utan kontroll fäller testet automatiskt.
- **Invariant: inget verktygssvar innehåller spelets `settings` eller en token.** Testet sätter ett känt hemligt värde i `settings.revenuecat.webhook_secret` (en nyckel manualen inte skriver ut) och söker efter det i alla läsverktygs svar.
- **Invariant: panel och MCP validerar lika.** Samma ogiltiga indata ger fel både via Filament-formuläret och via upsert-verktyget.
- OAuth: spelare (icke-staff) kan inte slutföra authorize; en token nekas på nästa anrop när `is_staff` tas bort eller kontot bannas; en spelare som registrerar en e-postadress som liknar en staff-adress får ingen åtkomst.
- Sanctum efter Passport: `DELETE /auth/me` raderar fortfarande användarens Sanctum-tokens; anonym inloggning och `auth:sanctum`-routes fungerar som förut.
- CORS: origin från ett aktivt och ett inaktivt spel tillåts, också i preflight (`OPTIONS`); okänd origin nekas; ogiltiga origins valideras bort; cachen rensas vid sparning.
- Befintlig testsvit ska vara grön, särskilt `/api/v1` med Sanctum efter att Passport lagts till.
- Före klart: en riktig koppling från claude.ai till en lokal eller Demo-instans, där Claude listar spel, läser ett spel och lägger till en topplistetyp som sedan syns i Filament.

## Driftsättning

- Nya paket, migrationer, Passport-nycklar och env-variabler på Forge-boxen. `Game` får `allowed_origins` i `$fillable` och casts. Allt i prod kräver Eriks OK enligt produktionsreglerna.
- Connectorn läggs till i claude.ai efter driftsättning (Erik).
- Erik kör `computercat:staff` för sitt och Mattias konto, efter att ha kontrollerat att det är rätt konton. Utan det tappar båda panelåtkomsten när ändringen går live, så det görs i samma driftsättning.
- Mattias läggs till som medlem på sina spel i Filament.

## Säkerhetsmodell (sammanfattning)

- **Vem:** bara konton med `is_staff` (satt av Erik, aldrig via API) och utan ban kan få och använda en MCP-token; kontrolleras vid authorize och vid varje anrop.
- **Vad:** bara spel man är medlem i (eller allt för platform admin); bara konfiguration, aldrig pengar, hemligheter eller spelardata; ingen radering.
- **Skadeverkan vid kapat claude.ai-konto:** konfigurationen i den personens spel kan ändras. Allt syns i revisionsloggen och kan rättas i Filament. Live-appar, andra spel och infra påverkas inte.
- **Spårbarhet:** varje anrop loggas med användare, spel, verktyg och utfall.
