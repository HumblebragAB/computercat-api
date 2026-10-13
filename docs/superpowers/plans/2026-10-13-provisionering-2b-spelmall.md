# Spelplattformen delprojekt 2b: spelmallen `HumblebragAB/spelmall` — implementationsplan

> **För agenter som genomför planen:** OBLIGATORISK SUB-SKILL: använd superpowers:subagent-driven-development (rekommenderas) eller superpowers:executing-plans för att genomföra planen task för task. Stegen har checkbox-syntax (`- [ ]`) för att följa upp.

**Mål:** Ett mallrepo som `create_game` kan generera spelrepon ur. Direkt efter första deploy ska ett genererat repo bygga till statiska filer på Coolify, logga in en anonym spelare mot Computercat, skicka ett resultat till en daglig topplista (köat lokalt om nätet saknas) och visa topplistan. Spelets Claude ska av `CLAUDE.md` veta att Computercat är backend och hur topplistan konfigureras via connectorn.

**Arkitektur:** Next.js App Router med `output: 'export'`; all kod som pratar med Computercat körs i webbläsaren. `src/lib/computercat/` är en fabrik (`createComputercat`) som får `fetch`, lagring och klocka injicerade, så att hela klienten testas med vitest och en falsk `fetch` utan nätverk. Ovanpå fabriken ligger en lat singleton (`getComputercat`) som läser `NEXT_PUBLIC_COMPUTERCAT_GAME`/`NEXT_PUBLIC_COMPUTERCAT_URL`. Exempelspelet (klickrace, 10 sekunder) använder en Zustand-store för spelstatus och klientens kö för resultat.

**Teknik:** Next.js 16.1.6, React 19.2.3, Tailwind 4.2.0 (`@tailwindcss/postcss`), Zustand 5.0.11, framer-motion 12.34.3, TypeScript 5.9.3, vitest 4.1.1, ESLint 9.39.3 + eslint-config-next 16.1.6. Versionerna är exakt de som Toccos `package-lock.json` har låst (hämtat 2026-10-13), här låsta utan `^`. Node 22 (se Globala villkor).

**Spec:** `docs/superpowers/specs/2026-10-13-spelplattform-provisionering-design.md`, avsnitt "2b. Spelmallen `HumblebragAB/spelmall`".

**Beroenden och ordning:** 2b beror inte på 2a eller 2c och kan köras parallellt med 2a. 2c:s spike (2c Task 1) kräver att `HumblebragAB/spelmall` finns på GitHub med `is_template: true` och idé-markörerna, alltså att Task 12 här är gjord (med Eriks OK), och att Erik har lagt till `spelmall` i GitHub App-installationen "Humblebrag Spelstudio". Ordningen över planerna: förutsättningarna → 2a och 2b → deploy av 2a + Task 12 här, båda med Eriks OK → 2c Task 1 (spike) → resten av 2c → 2d. Spiken verifierar också att Coolifys nixpacks bygger mallen med Node 22 (2c S20).

## Globala villkor

- **Var koden bor.** Nytt repo `~/Web/spelmall`. Det ligger under `~/Web`, så worktree-tvånget gäller: Task 1 skapar repot med en tom start-commit på `main` (bara git-kommandon i huvudkatalogen), sedan sker allt arbete i worktreen `/Users/erik/Web/spelmall/.claude/worktrees/feat-spelmall` via `~/.claude/bin/wt-start`. Huvudkatalogen får inga filändringar.
- **Inget utåt utan Eriks OK.** Inget GitHub-repo skapas, inget pushas och ingenting markeras som mall förrän Task 12, som kräver Eriks uttryckliga OK (AskUserQuestion). Ingen task rör Computercat i produktion: alla tester går mot en falsk `fetch`, och webbläsarkontrollen i Task 11 körs mot en stängd lokal port.
- **Spelar-API:t är facit.** Klientens anrop och svarstolkning ska stämma med controllers i `computercat-api` (citerade nedan), inte med Toccos klient. Toccos klient har tre fel som inte får kopieras: den läser `data` ur `GET /auth/me` (servern svarar `user`), den behandlar topplisteposter som platta (`user_id`, `display_name`, servern svarar `user: {id, display_name}`), och den slänger token vid *alla* fel, även nätverksfel.
- **Fakta ur Computercat (verifierade i koden 2026-10-13):**
  - `POST /api/v1/auth/anonymous` (throttle `auth`, 10/min per IP) svarar **201** `{"user": {"id", "display_name", "is_anonymous", "created_at"}, "token": "<id>|<text>"}` — `AuthController.php:16-37`, `UserResource.php:12-18`. `display_name` i body är valfri (`AuthController.php:18-24`).
  - `GET /api/v1/auth/me` svarar `{"user": {...}}` — `AuthController.php:106-111`. Sanctum-token löper ut efter 7 dygn (`config/sanctum.php:50`); manualen säger "On 401, create a new anonymous user and re-sync" (`IntegrationGuide.php:153`, `:177`).
  - Topplistor kräver inloggning även för läsning (`routes/api.php:108-117`, gruppen `auth:sanctum`). `GET /games/{game}/leaderboards/{type}` → `{"data": [Entry], "period_key"}` (`LeaderboardController.php:21-35`); `GET .../{type}/{periodKey}` samma form (`:37-50`); `GET .../{type}/me` → `{"data": Entry|null, "rank"?, "period_key"}` (`:81-100`); `POST .../{type}` body `{"score": int>=0, "metadata"?: object}` → **201** `{"data": Entry}` (`:52-79`), throttle `score-submit` 30/min per användare (`AppServiceProvider.php:97-99`). Entry = `{"rank", "score", "metadata", "submitted_at", "user": {"id", "display_name"}}` (`LeaderboardEntryResource.php:12-21`).
  - 404-texter: `"Leaderboard type not found."` (`LeaderboardController.php:25`), `"Game not found."` för okänt eller inaktivt spel (`ResolveGame.php:17-20`), `"Save not found."` (`GameSaveController.php:32-34`).
  - Period för `daily` räknas av servern med `Carbon::now()->format('Y-m-d')` i appens tidszon `UTC` (`LeaderboardService.php:13-21`, `config/app.php:68`). Klienten räknar **aldrig** ut periodnyckeln själv; den läser `period_key` ur svaret. Servern sparar bästa resultat per spelare och period (`LeaderboardService.php:42-75`), så en dubblett är ofarlig.
  - Anti-cheat: `metadata.proof = {pid, mc, gh, t, sig}`; `mc` och `t` måste vara heltal och `pid`, `gh`, `sig` sanna i PHP (alltså inte `""` eller `"0"`) (`AntiCheatService.php:71-79`); `sig` = FNV-1a 32-bit över **bytes** i `"{pid}|{mc}|{gh}|{t}"` i base 36 (`AntiCheatService.php:119-135`, `IntegrationGuide.php:264-273`); `|t − score|` får vara högst `proof_time_tolerance` (standard 1000) (`AntiCheatService.php:90-94`).
  - Sparningar: `GET /games/{game}/saves/{key}` → `{"data": Save}` eller 404 `"Save not found."`; `PUT` body `{"data": object (required, alltså inte tomt), "version": int>=0, "checksum"?: string<=64}`, max 512 000 byte → 413 (`GameSaveController.php:41-95`). Finns sparningen måste `version` vara lika med serverns, annars **409** `{"message", "server_version", "data": Save}` (`:64-72`); ny sparning får version 1 oavsett skickad version (`:80-89`). Nyckeln `ownership` tas bort av servern (`:59-62`). Save = `{"save_key", "data", "version", "checksum", "saved_at"}` (`GameSaveResource.php:12-18`).
  - CORS släpper `http://localhost:<port>` och `127.0.0.1:<port>` (`config/cors.php`, `allowed_origins_patterns`), så `npm run dev` fungerar mot API:t utan ändring.
- **Node 22.** Nixpacks väljer Node 18 som standard; versionen kan sättas med `engines.node`, `NIXPACKS_NODE_VERSION` eller `.nvmrc`, bara major-version (nixpacks.com/docs/providers/node, läst 2026-10-13). Next.js 16 kräver Node ≥ 20.9. Därför `"engines": {"node": "22.x"}` i `package.json`. Att Coolifys nixpacks verkligen tar Node 22 verifieras i 2c:s spik (S20).
- **Bygget behöver inget nätverk.** Ingen `next/font/google`, ingen `fetch` i serverkomponenter eller vid bygget, inga bilder via Next-optimering (`images.unoptimized`). Typsnitt är systemtypsnitt.
- **`NEXT_PUBLIC_*` läses med bokstavliga namn** (`process.env.NEXT_PUBLIC_COMPUTERCAT_GAME`), aldrig `process.env[namn]`, annars bakar Next inte in värdet. Inga hemligheter i `NEXT_PUBLIC_*`.
- **Fail loud.** Ingen påhittad topplistedata, inga tysta fallbacks. Saknad konfiguration visas som banner; serverfel visas med serverns meddelande; 4xx/5xx loggas med metod, sökväg, request- och svarskropp (max 4 KB vardera, aldrig token).
- **Klienten har inga raderingsanrop** (`HttpMethod` saknar `DELETE`).
- Kommentarer, UI-text och testnamn på svenska. Commit-meddelanden på svenska, utan Co-Authored-By-rader.
- `npm run check` (typkontroll, lint, test, bygge, exportkontroll) ska vara grön före varje commit från och med Task 8.

## Granskningsfokus

Fem fel som är lätta att missa; varje rad har ett test i den task som äger koden.

1. **Offline tolkas som utloggad.** Ett nätverksfel eller 5xx mot `GET /auth/me` får inte slänga token och skapa en ny anonym spelare (spelaren tappar då sin plats på topplistan och kön skickas som en annan person). Bara 401 byter spelare, och bara en gång per anrop. Test i Task 3 ("nätverksfel behåller token", "500 behåller token", "två 401 i rad ger fel, ingen loop").
2. **Fel svarsform från `/auth/me`.** Servern svarar `{"user": …}`. En klient som läser `data` (som Tocco) kraschar tyst och skapar en ny spelare vid varje sidladdning. Test i Task 3 ("verify läser user ur GET /auth/me och skapar ingen ny spelare").
3. **Anti-cheat-signatur över UTF-16 i stället för UTF-8.** `charCodeAt` ger samma signatur som PHP bara för ASCII; ett `pid` med `å` (svenska speltitlar) avvisas då med "Proof signature mismatch." Test i Task 4 med en testvektor räknad med serverns PHP-algoritm: `kattsvep-å|1|x|1` → `wt2f7k`.
4. **Kön skickar dubbletter eller fastnar på ett giftigt resultat.** Två samtidiga `flush` får inte skicka samma resultat två gånger, ett 422 (anti-cheat) får inte blockera resten av kön för alltid, och ett nätverksfel får inte kasta något. Test i Task 5 ("två samtidiga flush skickar varje resultat en gång", "422 kastas synligt och blockerar inte kön", "nätverksfel stoppar och behåller ordningen").
5. **Idé-markören i README försvinner eller dubbleras.** `create_game` (2c, steget `repo`) skriver idén mellan två markörer; ändras mallens README så att markörerna saknas, dubbleras eller byter ordning går provisioneringen sönder först i produktion. Test i Task 10 ("README har exakt en idé-markör av varje slag, i rätt ordning").

---

## Gränssnitt mot 2c: idé-markören i `README.md`

Detta är kontraktet som `computercat-api` (2c, steget `repo`) skriver mot. Det dokumenteras även i mallens README (Task 10) och vaktas av `test/docs.test.ts`.

- Fil: `README.md` i repots rot, gren `main`.
- Startmarkör: en egen rad som är exakt `<!-- computercat:idea:start -->`.
- Slutmarkör: en egen rad som är exakt `<!-- computercat:idea:end -->`.
- Varje markör förekommer exakt en gång, start före slut.
- Skrivaren ersätter allt **mellan** markörraderna med `"\n" + idé + "\n"` och behåller markörerna, så att omskrivning vid `retry_provisioning` ger samma resultat (idempotent).
- Idén skrivs som Markdown-text. Före skrivning ersätts `<!--` med `&lt;!--` och `-->` med `--&gt;`, så att idén inte kan stänga eller öppna en markör.
- Saknas en markör eller förekommer den mer än en gång: steget `repo` misslyckas med "README.md i <repo> saknar idé-markörerna" och skriver ingenting (aldrig en tilläggning någon annanstans i filen).
- Mallens innehåll mellan markörerna är `_Idén fylls i av create_game när spelet skapas._`.
- Notering till 2c: `POST /repos/{template}/generate` skapar innehållet asynkront; `GET /repos/HumblebragAB/<slug>/contents/README.md` kan ge 404 de första sekunderna och ska försökas igen innan steget fallerar. Skrivning sker med `PUT /repos/HumblebragAB/<slug>/contents/README.md` med `sha` från GET-svaret och `branch: main`.

## Miljövariabler (gränssnitt mot Coolify i 2c)

| Variabel | Krävs | Standard | Sätts av |
|---|---|---|---|
| `NEXT_PUBLIC_COMPUTERCAT_GAME` | ja (annars banner, inget skickas) | — | 2c steget `coolify_env` som build-variabel; lokalt i `.env.local` |
| `NEXT_PUBLIC_COMPUTERCAT_URL` | nej | `https://api.computercat.co/api/v1` | bara vid test mot annan miljö; måste vara `https://` (eller `http://localhost`/`127.0.0.1`) |

## Filstruktur

Alla sökvägar relativt `/Users/erik/Web/spelmall/.claude/worktrees/feat-spelmall`.

| Fil | Ansvar |
|---|---|
| `package.json`, `package-lock.json` | Låsta versioner, `engines.node`, skript inkl. `check` |
| `next.config.ts` | `output: 'export'`, `images.unoptimized` |
| `tsconfig.json`, `postcss.config.mjs`, `eslint.config.mjs`, `vitest.config.ts` | Som Tocco, plus undantag för `.claude/` och `out/` |
| `.gitignore`, `.env.example` | Ignorerar `.env*` utom exemplet, `/out/`, `/.claude/worktrees/` |
| `src/lib/computercat/config.ts` | Läser och validerar `NEXT_PUBLIC_*` |
| `src/lib/computercat/storage.ts` | `localStorage` med minnes-fallback och JSON-hjälpare |
| `src/lib/computercat/http.ts` | `requestJson`, `ApiError`, `NetworkError`, loggning |
| `src/lib/computercat/auth.ts` | Anonym spelare, token, 401 → ny spelare, `verify` |
| `src/lib/computercat/leaderboards.ts` | Läsa, min placering, skicka |
| `src/lib/computercat/proof.ts` | FNV-1a-signatur och `createProof` |
| `src/lib/computercat/queue.ts` | Offline-kö för resultat |
| `src/lib/computercat/background.ts` | Töm kön vid start, `online` och var 60:e sekund |
| `src/lib/computercat/saves.ts` | `get`, `put` med version, `sync` med merge |
| `src/lib/computercat/errors.ts` | `describeError` för UI-text |
| `src/lib/computercat/index.ts` | `createComputercat`, `getComputercat`, re-exporter |
| `src/lib/computercat/*.test.ts` | Enhetstester per modul |
| `test/fakeFetch.ts` | Falsk `fetch`, `json()`, `storedSession()` |
| `test/docs.test.ts` | README-markörer, CLAUDE.md-innehåll, inget nätverk i `src/app` |
| `src/game/constants.ts`, `src/game/clickStore.ts`, `src/game/clickStore.test.ts` | Exempelspelets logik |
| `src/components/ClickGame.tsx`, `Leaderboard.tsx`, `ConfigBanner.tsx`, `BackgroundSync.tsx` | Exempelspelets UI |
| `src/app/layout.tsx`, `src/app/page.tsx`, `src/app/globals.css` | Sidan |
| `scripts/check-export.mjs` | Kontrollerar `out/index.html` och att slugen bakats in |
| `README.md`, `CLAUDE.md` | För människor och för spelets Claude |

---

### Task 1: Repo, worktree och projektskelett

**Filer:**
- Skapa: `package.json`, `next.config.ts`, `tsconfig.json`, `postcss.config.mjs`, `eslint.config.mjs`, `vitest.config.ts`, `.gitignore`, `src/app/layout.tsx`, `src/app/page.tsx`, `src/app/globals.css`
- Genereras: `package-lock.json`

**Gränssnitt:**
- Konsumerar: inget.
- Producerar: repo `~/Web/spelmall` med `main`; worktree `feat-spelmall`; alias `@/*` → `src/*`; skripten `dev`, `build`, `lint`, `test`, `typecheck` (`check` kommer i Task 8).

- [ ] **Steg 1: Skapa repot med en tom start-commit (bara git i huvudkatalogen)**

```bash
mkdir /Users/erik/Web/spelmall
git -C /Users/erik/Web/spelmall init -b main
git -C /Users/erik/Web/spelmall commit --allow-empty -m "Startpunkt för spelmallen"
git -C /Users/erik/Web/spelmall log --oneline
```

Förväntat: en commit, "Startpunkt för spelmallen".

- [ ] **Steg 2: Skapa worktreen**

```bash
~/.claude/bin/wt-start /Users/erik/Web/spelmall feat/spelmall --no-herd --no-build
```

Förväntat: sista raden är `/Users/erik/Web/spelmall/.claude/worktrees/feat-spelmall`. Vägrar skriptet för att `main` saknar upstream eller av annat skäl: stanna och fråga Erik med AskUserQuestion (skapa inte worktreen för hand). Alla följande sökvägar är absoluta i worktreen; kalla den `$WT` nedan.

- [ ] **Steg 3: `package.json`**

```json
{
  "name": "spelmall",
  "version": "0.1.0",
  "private": true,
  "engines": {
    "node": "22.x"
  },
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "lint": "eslint",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "framer-motion": "12.34.3",
    "next": "16.1.6",
    "react": "19.2.3",
    "react-dom": "19.2.3",
    "zustand": "5.0.11"
  },
  "devDependencies": {
    "@tailwindcss/postcss": "4.2.0",
    "@types/node": "20.19.33",
    "@types/react": "19.2.14",
    "@types/react-dom": "19.2.3",
    "eslint": "9.39.3",
    "eslint-config-next": "16.1.6",
    "tailwindcss": "4.2.0",
    "typescript": "5.9.3",
    "vitest": "4.1.1"
  }
}
```

- [ ] **Steg 4: Konfigurationsfiler**

`next.config.ts`:

```ts
import type { NextConfig } from 'next';

// Statisk export: Coolify bygger med nixpacks och serverar out/ med nginx.
// Ingen server, inga API-routes, ingen bildoptimering.
const nextConfig: NextConfig = {
  output: 'export',
  images: { unoptimized: true },
};

export default nextConfig;
```

`tsconfig.json`:

```json
{
  "compilerOptions": {
    "target": "ES2017",
    "lib": ["dom", "dom.iterable", "esnext"],
    "allowJs": true,
    "skipLibCheck": true,
    "strict": true,
    "noEmit": true,
    "esModuleInterop": true,
    "module": "esnext",
    "moduleResolution": "bundler",
    "resolveJsonModule": true,
    "isolatedModules": true,
    "jsx": "react-jsx",
    "incremental": true,
    "plugins": [{ "name": "next" }],
    "paths": { "@/*": ["./src/*"] }
  },
  "include": ["next-env.d.ts", "**/*.ts", "**/*.tsx", ".next/types/**/*.ts", ".next/dev/types/**/*.ts", "**/*.mts"],
  "exclude": ["node_modules", ".claude", "out"]
}
```

`postcss.config.mjs`:

```js
const config = {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};

export default config;
```

`eslint.config.mjs`:

```js
import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', '.claude/**']),
]);
```

`vitest.config.ts`:

```ts
import { defineConfig } from 'vitest/config';
import path from 'path';

export default defineConfig({
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts', 'test/**/*.test.ts'],
  },
});
```

`.gitignore`:

```gitignore
/node_modules
/coverage
/.next/
/out/
/build
.DS_Store
*.pem
npm-debug.log*
.env*
!.env.example
*.tsbuildinfo
next-env.d.ts
/.claude/worktrees/
```

- [ ] **Steg 5: Minsta sida (ersätts av exemplet i Task 9)**

`src/app/globals.css`:

```css
@import "tailwindcss";

html, body {
  height: 100%;
  margin: 0;
}

body {
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  background-color: #f5f0e8;
  color: #1f1a14;
  -webkit-font-smoothing: antialiased;
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
```

`src/app/layout.tsx`:

```tsx
import type { Metadata, Viewport } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'Spelmall',
  description: 'Computercat-spel byggt från HumblebragAB/spelmall.',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#f5f0e8',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="sv" className="h-full">
      <body className="h-full antialiased">{children}</body>
    </html>
  );
}
```

`src/app/page.tsx`:

```tsx
export default function Home() {
  return <main className="p-8 text-2xl font-bold">Spelmall</main>;
}
```

- [ ] **Steg 6: Installera och kontrollera versionerna**

```bash
cd $WT && npm install
npm ls next react react-dom zustand framer-motion tailwindcss vitest typescript --depth=0
```

Förväntat: `next@16.1.6`, `react@19.2.3`, `react-dom@19.2.3`, `zustand@5.0.11`, `framer-motion@12.34.3`, `tailwindcss@4.2.0`, `vitest@4.1.1`, `typescript@5.9.3`, inga `invalid`/`missing`.

- [ ] **Steg 7: Bygg och kontrollera exporten**

```bash
cd $WT && NEXT_TELEMETRY_DISABLED=1 npm run build && test -f out/index.html && echo EXPORT_OK
npm run typecheck && npm run lint && npx vitest run --passWithNoTests
```

Förväntat: bygget listar `○ /` som statisk, sedan `EXPORT_OK`; typkontroll och lint utan fel; vitest "No test files found, exiting with code 0".

- [ ] **Steg 8: Commit**

```bash
cd $WT && git add -A && git status --short && git commit -m "Projektskelett: Next.js statisk export, Tailwind, Zustand, framer-motion, vitest"
```

Kontrollera i `git status --short` att `node_modules`, `out` och `.next` inte finns med.

---

### Task 2: Konfiguration, lagring och HTTP

**Filer:**
- Skapa: `src/lib/computercat/config.ts`, `storage.ts`, `http.ts`, `test/fakeFetch.ts`
- Test: `src/lib/computercat/config.test.ts`, `storage.test.ts`, `http.test.ts`

**Gränssnitt:**
- Konsumerar: inget.
- Producerar:
  - `DEFAULT_BASE_URL`, `type ComputercatConfig = { game: string; baseUrl: string }`, `type ConfigResult`, `parseConfig(raw)`, `readConfig()`
  - `interface KeyValueStore`, `memoryStorage()`, `browserStorage()`, `readJson(storage, key, guard)`, `writeJson(storage, key, value)`
  - `type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH'`, `type FetchLike`, `class ApiError {status, body, method, path}`, `class NetworkError {method, path}`, `requestJson<T>(fetch, baseUrl, method, path, {token?, body?})`, `preview(value)`, `isRecord(value)`
  - Testhjälp: `BASE`, `json(status, body)`, `fakeFetch(routes)` → `{fetch, calls, unmatched}`, `storedSession(game, token?)`

- [ ] **Steg 1: Skriv de fallerande testerna**

`test/fakeFetch.ts`:

```ts
import type { FetchLike } from '@/lib/computercat/http';
import { memoryStorage, type KeyValueStore } from '@/lib/computercat/storage';

export const BASE = 'https://api.test/api/v1';

export type FakeRequest = {
  method: string;
  path: string;
  headers: Record<string, string>;
  body: unknown;
};

type Outcome = Response | 'network-error';
export type Reply = Outcome | ((req: FakeRequest) => Outcome);

export function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

/**
 * Falsk fetch. Nyckeln är "METOD /sökväg" relativt BASE. Svaren förbrukas i
 * ordning; det sista upprepas. Ett anrop utan svar hamnar i `unmatched` och
 * kastar som ett nätverksfel, så testet ska alltid kontrollera `unmatched`.
 */
export function fakeFetch(routes: Record<string, Reply[]>) {
  const calls: FakeRequest[] = [];
  const unmatched: string[] = [];
  const queues = new Map(Object.entries(routes).map(([k, v]) => [k, [...v]]));

  const fetch: FetchLike = async (input, init) => {
    if (!input.startsWith(BASE)) throw new Error(`fakeFetch: oväntad bas-URL ${input}`);
    const method = (init.method ?? 'GET').toUpperCase();
    const path = input.slice(BASE.length);
    const headers = (init.headers ?? {}) as Record<string, string>;
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined;
    const req: FakeRequest = { method, path, headers, body };
    calls.push(req);

    const key = `${method} ${path}`;
    const queue = queues.get(key);
    if (!queue || queue.length === 0) {
      unmatched.push(key);
      throw new TypeError(`fakeFetch: inget svar för ${key}`);
    }
    const reply = queue.length > 1 ? queue.shift()! : queue[0];
    const outcome = typeof reply === 'function' ? reply(req) : reply;
    if (outcome === 'network-error') throw new TypeError('Failed to fetch');
    return outcome.clone();
  };

  return { fetch, calls, unmatched };
}

export function storedSession(game: string, token = '1|stored'): KeyValueStore {
  const storage = memoryStorage();
  storage.setItem(
    `computercat:${game}:session`,
    JSON.stringify({ token, userId: 7, displayName: 'Player-7' }),
  );
  return storage;
}
```

`src/lib/computercat/config.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DEFAULT_BASE_URL, parseConfig } from './config';

describe('parseConfig', () => {
  it('använder standardadressen när URL saknas', () => {
    expect(parseConfig({ game: 'kattsvep' })).toEqual({
      ok: true,
      config: { game: 'kattsvep', baseUrl: DEFAULT_BASE_URL },
    });
  });

  it('tar bort avslutande snedstreck från en egen adress', () => {
    const r = parseConfig({ game: 'kattsvep', url: 'https://staging.example.se/api/v1/' });
    expect(r).toEqual({ ok: true, config: { game: 'kattsvep', baseUrl: 'https://staging.example.se/api/v1' } });
  });

  it('saknat spel ger ett synligt fel, inte ett påhittat spel', () => {
    const r = parseConfig({ game: '  ' });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.error).toContain('NEXT_PUBLIC_COMPUTERCAT_GAME');
  });

  it('avvisar en slug som skulle ändra URL:en', () => {
    expect(parseConfig({ game: 'kattsvep/../admin' }).ok).toBe(false);
    expect(parseConfig({ game: 'Kattsvep' }).ok).toBe(false);
  });

  it('kräver https utom mot localhost', () => {
    expect(parseConfig({ game: 'kattsvep', url: 'http://api.computercat.co/api/v1' }).ok).toBe(false);
    expect(parseConfig({ game: 'kattsvep', url: 'http://localhost:8000/api/v1' }).ok).toBe(true);
    expect(parseConfig({ game: 'kattsvep', url: 'inte en adress' }).ok).toBe(false);
  });

  it('readConfig läser variablerna med bokstavliga namn så att Next bakar in dem', () => {
    const src = readFileSync(resolve(process.cwd(), 'src/lib/computercat/config.ts'), 'utf8');
    expect(src).toContain('process.env.NEXT_PUBLIC_COMPUTERCAT_GAME');
    expect(src).toContain('process.env.NEXT_PUBLIC_COMPUTERCAT_URL');
    expect(src).not.toMatch(/process\.env\[/);
  });
});
```

`src/lib/computercat/storage.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { browserStorage, memoryStorage, readJson, writeJson } from './storage';

const isNumberBox = (v: unknown): v is { n: number } =>
  typeof v === 'object' && v !== null && typeof (v as { n?: unknown }).n === 'number';

afterEach(() => vi.restoreAllMocks());

describe('storage', () => {
  it('skriver och läser JSON', () => {
    const s = memoryStorage();
    writeJson(s, 'k', { n: 3 });
    expect(readJson(s, 'k', isNumberBox)).toEqual({ n: 3 });
  });

  it('trasig JSON ignoreras synligt och tas bort', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = memoryStorage();
    s.setItem('k', '{inte json');
    expect(readJson(s, 'k', isNumberBox)).toBeNull();
    expect(s.getItem('k')).toBeNull();
    expect(warn).toHaveBeenCalled();
  });

  it('värde med fel form ignoreras', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = memoryStorage();
    s.setItem('k', JSON.stringify({ n: 'tre' }));
    expect(readJson(s, 'k', isNumberBox)).toBeNull();
  });

  it('utan webbläsare blir det minneslagring med en varning', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const s = browserStorage();
    s.setItem('a', 'b');
    expect(s.getItem('a')).toBe('b');
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('localStorage'));
  });
});
```

`src/lib/computercat/http.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { ApiError, NetworkError, preview, requestJson } from './http';

afterEach(() => vi.restoreAllMocks());

describe('requestJson', () => {
  it('skickar JSON med Accept, Content-Type och Bearer-token', async () => {
    const f = fakeFetch({ 'POST /x': [json(201, { data: { ok: true } })] });
    const res = await requestJson<{ data: { ok: boolean } }>(f.fetch, BASE, 'POST', '/x', { token: 't1', body: { score: 3 } });
    expect(res.data.ok).toBe(true);
    expect(f.calls[0].headers).toEqual({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: 'Bearer t1',
    });
    expect(f.calls[0].body).toEqual({ score: 3 });
  });

  it('GET utan body skickar ingen Content-Type och ingen token', async () => {
    const f = fakeFetch({ 'GET /y': [json(200, {})] });
    await requestJson(f.fetch, BASE, 'GET', '/y');
    expect(f.calls[0].headers).toEqual({ Accept: 'application/json' });
  });

  it('4xx ger ApiError med serverns meddelande och loggar request och svar', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'POST /x': [json(422, { message: 'Score is implausibly low.' })] });
    const p = requestJson(f.fetch, BASE, 'POST', '/x', { token: 'hemlig', body: { score: 1 } });
    await expect(p).rejects.toMatchObject({ name: 'ApiError', status: 422, message: 'Score is implausibly low.' });
    expect(error).toHaveBeenCalledWith('[computercat] POST /x → 422', {
      request: '{"score":1}',
      response: '{"message":"Score is implausibly low."}',
    });
    expect(JSON.stringify(error.mock.calls)).not.toContain('hemlig');
  });

  it('fel som inte är JSON (502 från proxyn) ger ApiError med texten', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /y': [new Response('<html>Bad gateway</html>', { status: 502 })] });
    const err = await requestJson(f.fetch, BASE, 'GET', '/y').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).message).toBe('HTTP 502');
    expect((err as ApiError).body).toBe('<html>Bad gateway</html>');
  });

  it('nätverksfel ger NetworkError, inte ApiError', async () => {
    const f = fakeFetch({ 'GET /y': ['network-error'] });
    await expect(requestJson(f.fetch, BASE, 'GET', '/y')).rejects.toBeInstanceOf(NetworkError);
  });

  it('loggen kortas till 4 KB', () => {
    const out = preview('a'.repeat(10_000));
    expect(out.startsWith('a'.repeat(4096))).toBe(true);
    expect(out).toContain('(+5904 tecken)');
  });
});
```

- [ ] **Steg 2: Kör testerna och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat
```

Förväntat: FAIL, "Failed to resolve import ./config" (och motsvarande för `storage`, `http`).

- [ ] **Steg 3: Implementera**

`src/lib/computercat/config.ts`:

```ts
export const DEFAULT_BASE_URL = 'https://api.computercat.co/api/v1';

// Samma tecken som spelslugs i Computercat; hindrar att slugen ändrar URL:en.
const GAME_SLUG = /^[a-z0-9][a-z0-9-]{0,49}$/;

export type ComputercatConfig = { game: string; baseUrl: string };
export type ConfigResult = { ok: true; config: ComputercatConfig } | { ok: false; error: string };

export function parseConfig(raw: { game?: string; url?: string }): ConfigResult {
  const game = raw.game?.trim() ?? '';
  if (!game) {
    return {
      ok: false,
      error: 'NEXT_PUBLIC_COMPUTERCAT_GAME saknas. Spelet fungerar lokalt, men inga resultat skickas till Computercat.',
    };
  }
  if (!GAME_SLUG.test(game)) {
    return { ok: false, error: `NEXT_PUBLIC_COMPUTERCAT_GAME har ogiltigt format: "${game}".` };
  }

  const url = (raw.url?.trim() || DEFAULT_BASE_URL).replace(/\/+$/, '');
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return { ok: false, error: `NEXT_PUBLIC_COMPUTERCAT_URL är ingen giltig adress: "${url}".` };
  }
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol !== 'https:' && !(local && parsed.protocol === 'http:')) {
    return { ok: false, error: 'NEXT_PUBLIC_COMPUTERCAT_URL måste börja med https:// (http bara mot localhost).' };
  }

  return { ok: true, config: { game, baseUrl: url } };
}

export function readConfig(): ConfigResult {
  // Next.js bakar bara in NEXT_PUBLIC_* när namnet står bokstavligt i koden.
  return parseConfig({
    game: process.env.NEXT_PUBLIC_COMPUTERCAT_GAME,
    url: process.env.NEXT_PUBLIC_COMPUTERCAT_URL,
  });
}
```

`src/lib/computercat/storage.ts`:

```ts
export interface KeyValueStore {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export function memoryStorage(): KeyValueStore {
  const map = new Map<string, string>();
  return {
    getItem: (k) => map.get(k) ?? null,
    setItem: (k, v) => {
      map.set(k, v);
    },
    removeItem: (k) => {
      map.delete(k);
    },
  };
}

/** localStorage om det går att skriva till, annars minne (med en synlig varning). */
export function browserStorage(): KeyValueStore {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      const probe = '__computercat_probe';
      window.localStorage.setItem(probe, '1');
      window.localStorage.removeItem(probe);
      return window.localStorage;
    }
  } catch {
    // Privat läge eller blockerad lagring: faller till minne nedan.
  }
  console.warn('[computercat] localStorage är inte tillgängligt; token och kö finns bara tills sidan laddas om.');
  return memoryStorage();
}

export function readJson<T>(storage: KeyValueStore, key: string, guard: (v: unknown) => v is T): T | null {
  const raw = storage.getItem(key);
  if (raw === null) return null;
  try {
    const value: unknown = JSON.parse(raw);
    if (guard(value)) return value;
  } catch {
    // Trasig JSON hanteras som fel form nedan.
  }
  console.warn(`[computercat] ogiltigt värde i ${key} ignoreras`);
  storage.removeItem(key);
  return null;
}

export function writeJson(storage: KeyValueStore, key: string, value: unknown): void {
  try {
    storage.setItem(key, JSON.stringify(value));
  } catch (e) {
    console.error(`[computercat] kunde inte spara ${key}`, e);
  }
}
```

`src/lib/computercat/http.ts`:

```ts
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH';
export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export const TIMEOUT_MS = 10_000;
const LOG_LIMIT = 4096;

export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  readonly method: HttpMethod;
  readonly path: string;

  constructor(status: number, message: string, body: unknown, method: HttpMethod, path: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
    this.method = method;
    this.path = path;
  }
}

export class NetworkError extends Error {
  readonly method: HttpMethod;
  readonly path: string;

  constructor(method: HttpMethod, path: string, cause: unknown) {
    super(`Kunde inte nå Computercat (${method} ${path})`, { cause });
    this.name = 'NetworkError';
    this.method = method;
    this.path = path;
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function preview(value: unknown): string {
  if (value === undefined || value === null) return '';
  const s = typeof value === 'string' ? value : JSON.stringify(value);
  return s.length > LOG_LIMIT ? `${s.slice(0, LOG_LIMIT)}…(+${s.length - LOG_LIMIT} tecken)` : s;
}

export async function requestJson<T>(
  fetchImpl: FetchLike,
  baseUrl: string,
  method: HttpMethod,
  path: string,
  opts: { token?: string | null; body?: unknown } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;

  let res: Response;
  let text: string;
  try {
    res = await fetchImpl(`${baseUrl}${path}`, {
      method,
      headers,
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    text = await res.text();
  } catch (e) {
    throw new NetworkError(method, path, e);
  }

  let body: unknown = null;
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = text;
    }
  }

  if (res.ok) return body as T;

  const message = isRecord(body) && typeof body.message === 'string' ? body.message : `HTTP ${res.status}`;
  // 401 och 409 är väntade vägar (ny spelare, versionskonflikt); övriga fel är fel.
  const log = res.status === 401 || res.status === 409 ? console.warn : console.error;
  log(`[computercat] ${method} ${path} → ${res.status}`, { request: preview(opts.body), response: preview(body) });
  throw new ApiError(res.status, message, body, method, path);
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/lib/computercat && npm run typecheck
```

Förväntat: `Test Files 3 passed (3)`, `Tests 16 passed (16)`; typkontroll utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: konfiguration, lagring och HTTP med synliga fel"
```

---

### Task 3: Anonym spelare och token

**Filer:**
- Skapa: `src/lib/computercat/auth.ts`
- Test: `src/lib/computercat/auth.test.ts`

**Gränssnitt:**
- Konsumerar: `requestJson`, `ApiError`, `HttpMethod`, `FetchLike`, `readJson`, `writeJson`, `KeyValueStore`, `isRecord` (Task 2).
- Producerar: `type Session = { token: string; userId: number; displayName: string }`, `type Authed = <T>(method: HttpMethod, path: string, body?: unknown) => Promise<T>`, `createAuth({fetch, baseUrl, game, storage})` → `{ getSession(): Promise<Session>; current(): Session | null; authed: Authed; verify(): Promise<Session> }`. Lagringsnyckel `computercat:<game>:session`.

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/lib/computercat/auth.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError, NetworkError } from './http';
import { memoryStorage } from './storage';

const GAME = 'kattsvep';
const KEY = `computercat:${GAME}:session`;
const anonymous = (id: number, token: string) =>
  json(201, { user: { id, display_name: `Player-${id}`, is_anonymous: true, created_at: '2026-10-13T10:00:00Z' }, token });

afterEach(() => vi.restoreAllMocks());

describe('createAuth', () => {
  it('skapar en anonym spelare första gången och sparar token', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [anonymous(42, '5|ny')] });
    const storage = memoryStorage();
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    const s = await auth.getSession();
    expect(s).toEqual({ token: '5|ny', userId: 42, displayName: 'Player-42' });
    expect(JSON.parse(storage.getItem(KEY)!)).toEqual(s);
    expect(f.unmatched).toEqual([]);
  });

  it('återanvänder sparad token mellan sidladdningar', async () => {
    const f = fakeFetch({});
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    expect((await auth.getSession()).token).toBe('1|stored');
    expect(f.calls).toHaveLength(0);
  });

  it('två samtidiga anrop skapar bara en spelare', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [anonymous(42, '5|ny'), anonymous(43, '6|dubblett')] });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: memoryStorage() });
    const [a, b] = await Promise.all([auth.getSession(), auth.getSession()]);
    expect(a.token).toBe('5|ny');
    expect(b.token).toBe('5|ny');
    expect(f.calls.filter((c) => c.path === '/auth/anonymous')).toHaveLength(1);
  });

  it('verify läser user ur GET /auth/me och skapar ingen ny spelare', async () => {
    const f = fakeFetch({
      'GET /auth/me': [json(200, { user: { id: 7, display_name: 'Mia', is_anonymous: true, created_at: null } })],
    });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    const s = await auth.verify();
    expect(s).toEqual({ token: '1|stored', userId: 7, displayName: 'Mia' });
    expect(f.calls.map((c) => c.path)).toEqual(['/auth/me']);
  });

  it('401 ger en ny anonym spelare och anropet görs om en gång', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /games/kattsvep/leaderboards/daily': [
        json(401, { message: 'Unauthenticated.' }),
        json(200, { data: [], period_key: '2026-10-13' }),
      ],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const storage = storedSession(GAME, '1|gammal');
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    const res = await auth.authed<{ period_key: string }>('GET', '/games/kattsvep/leaderboards/daily');
    expect(res.period_key).toBe('2026-10-13');
    expect(f.calls.map((c) => `${c.method} ${c.path} ${c.headers.Authorization ?? '-'}`)).toEqual([
      'GET /games/kattsvep/leaderboards/daily Bearer 1|gammal',
      'POST /auth/anonymous -',
      'GET /games/kattsvep/leaderboards/daily Bearer 6|ny',
    ]);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('6|ny');
  });

  it('nätverksfel behåller token (offline är inte utloggad)', async () => {
    const f = fakeFetch({ 'GET /auth/me': ['network-error'] });
    const storage = storedSession(GAME);
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.verify()).rejects.toBeInstanceOf(NetworkError);
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('1|stored');
    expect(f.calls.some((c) => c.path === '/auth/anonymous')).toBe(false);
  });

  it('500 behåller token', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const f = fakeFetch({ 'GET /auth/me': [json(500, { message: 'Server Error' })] });
    const storage = storedSession(GAME);
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.verify()).rejects.toMatchObject({ status: 500 });
    expect(JSON.parse(storage.getItem(KEY)!).token).toBe('1|stored');
  });

  it('två 401 i rad ger fel, ingen loop', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const f = fakeFetch({
      'GET /auth/me': [json(401, { message: 'Unauthenticated.' })],
      'POST /auth/anonymous': [anonymous(43, '6|ny')],
    });
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
    const err = await auth.verify().catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(401);
    expect(f.calls.filter((c) => c.path === '/auth/anonymous')).toHaveLength(1);
    expect(f.calls.filter((c) => c.path === '/auth/me')).toHaveLength(2);
  });

  it('oväntat svar från /auth/anonymous ger tydligt fel och sparar inget', async () => {
    const f = fakeFetch({ 'POST /auth/anonymous': [json(201, { data: { id: 1 } })] });
    const storage = memoryStorage();
    const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage });
    await expect(auth.getSession()).rejects.toThrow('POST /auth/anonymous');
    expect(storage.getItem(KEY)).toBeNull();
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat/auth.test.ts
```

Förväntat: FAIL, "Failed to resolve import ./auth".

- [ ] **Steg 3: Implementera**

`src/lib/computercat/auth.ts`:

```ts
import { ApiError, isRecord, requestJson, type FetchLike, type HttpMethod } from './http';
import { readJson, writeJson, type KeyValueStore } from './storage';

export type Session = { token: string; userId: number; displayName: string };
export type Authed = <T>(method: HttpMethod, path: string, body?: unknown) => Promise<T>;

type ApiUser = { id: number; display_name: string; is_anonymous: boolean };

function isApiUser(v: unknown): v is ApiUser {
  return isRecord(v) && typeof v.id === 'number' && typeof v.display_name === 'string' && typeof v.is_anonymous === 'boolean';
}

function isSession(v: unknown): v is Session {
  return isRecord(v) && typeof v.token === 'string' && v.token.length > 0 && typeof v.userId === 'number' && typeof v.displayName === 'string';
}

export type AuthDeps = { fetch: FetchLike; baseUrl: string; game: string; storage: KeyValueStore };

export function createAuth(deps: AuthDeps) {
  const key = `computercat:${deps.game}:session`;
  let session: Session | null = readJson(deps.storage, key, isSession);
  let creating: Promise<Session> | null = null;

  function remember(next: Session): Session {
    session = next;
    writeJson(deps.storage, key, next);
    return next;
  }

  async function createAnonymous(): Promise<Session> {
    // AuthController::anonymous svarar 201 {user, token} (AuthController.php:34-37).
    const res = await requestJson<unknown>(deps.fetch, deps.baseUrl, 'POST', '/auth/anonymous', { body: {} });
    if (!isRecord(res) || typeof res.token !== 'string' || !isApiUser(res.user)) {
      throw new Error('Oväntat svar från POST /auth/anonymous (token eller user saknas)');
    }
    return remember({ token: res.token, userId: res.user.id, displayName: res.user.display_name });
  }

  /** Sparad spelare, annars en ny anonym. Samtidiga anrop delar på samma skapande. */
  function getSession(): Promise<Session> {
    if (session) return Promise.resolve(session);
    if (!creating) {
      creating = createAnonymous().finally(() => {
        creating = null;
      });
    }
    return creating;
  }

  /** Glöm token bara om den fortfarande är den som fick 401 (ett parallellt anrop kan redan ha bytt). */
  function discard(stale: Session): void {
    if (session?.token === stale.token) {
      session = null;
      deps.storage.removeItem(key);
    }
  }

  const authed: Authed = async <T>(method: HttpMethod, path: string, body?: unknown): Promise<T> => {
    const first = await getSession();
    try {
      return await requestJson<T>(deps.fetch, deps.baseUrl, method, path, { token: first.token, body });
    } catch (e) {
      // Bara 401 betyder utgången token (Sanctum, 7 dygn). Nätverksfel och 5xx behåller spelaren.
      if (!(e instanceof ApiError) || e.status !== 401) throw e;
      discard(first);
      const fresh = await getSession();
      return requestJson<T>(deps.fetch, deps.baseUrl, method, path, { token: fresh.token, body });
    }
  };

  /** Kontrollera token vid start. AuthController::me svarar {user}, inte {data} (AuthController.php:106-111). */
  async function verify(): Promise<Session> {
    const res = await authed<unknown>('GET', '/auth/me');
    const user = isRecord(res) ? res.user : undefined;
    if (!isApiUser(user)) throw new Error('Oväntat svar från GET /auth/me (user saknas)');
    const current = await getSession();
    return remember({ ...current, userId: user.id, displayName: user.display_name });
  }

  return { getSession, current: () => session, authed, verify };
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/lib/computercat && npm run typecheck
```

Förväntat: `Tests 25 passed (25)`; typkontroll utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: anonym spelare, ny spelare bara vid 401"
```

---

### Task 4: Topplistor och anti-cheat-bevis

**Filer:**
- Skapa: `src/lib/computercat/leaderboards.ts`, `src/lib/computercat/proof.ts`
- Test: `src/lib/computercat/leaderboards.test.ts`, `src/lib/computercat/proof.test.ts`

**Gränssnitt:**
- Konsumerar: `Authed` (Task 3), `isRecord` (Task 2).
- Producerar:
  - `type LeaderboardEntry = { rank: number | null; score: number; metadata: Record<string, unknown> | null; submitted_at: string; user: { id: number; display_name: string } }`
  - `type LeaderboardPage = { entries: LeaderboardEntry[]; periodKey: string }`, `type MyRank = { entry: LeaderboardEntry | null; rank: number | null; periodKey: string }`
  - `assertScore(score)`, `createLeaderboards(game, authed)` → `{ get(type, periodKey?), me(type), submit(type, score, metadata?) }`
  - `type PlayProof = { pid; mc; gh; t; sig }`, `fnv1aSignature(pid, mc, gh, t)`, `createProof({pid, moves, stateHash, elapsedMs})`

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/lib/computercat/leaderboards.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { createLeaderboards } from './leaderboards';

const GAME = 'kattsvep';
const entry = {
  rank: 1,
  score: 31,
  metadata: null,
  submitted_at: '2026-10-13T09:00:00Z',
  user: { id: 7, display_name: 'Mia' },
};

function setup(routes: Parameters<typeof fakeFetch>[0]) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
  return { f, lb: createLeaderboards(GAME, auth.authed) };
}

describe('createLeaderboards', () => {
  it('hämtar aktuell period inloggad och tar period_key från servern', async () => {
    const { f, lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks': [json(200, { data: [entry], period_key: '2026-10-13' })],
    });
    const page = await lb.get('daily-clicks');
    expect(page).toEqual({ entries: [entry], periodKey: '2026-10-13' });
    expect(f.calls[0].headers.Authorization).toBe('Bearer 1|stored');
  });

  it('hämtar en given period med kodade sökvägssegment', async () => {
    const { f, lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks/2026-10-12': [json(200, { data: [], period_key: '2026-10-12' })],
    });
    expect((await lb.get('daily-clicks', '2026-10-12')).periodKey).toBe('2026-10-12');
    expect(f.unmatched).toEqual([]);
  });

  it('min placering är null när spelaren inte har något resultat', async () => {
    const { lb } = setup({
      'GET /games/kattsvep/leaderboards/daily-clicks/me': [json(200, { data: null, period_key: '2026-10-13' })],
    });
    expect(await lb.me('daily-clicks')).toEqual({ entry: null, rank: null, periodKey: '2026-10-13' });
  });

  it('skickar score och metadata och läser 201 {data}', async () => {
    const { f, lb } = setup({
      'POST /games/kattsvep/leaderboards/daily-clicks': [json(201, { data: entry })],
    });
    expect(await lb.submit('daily-clicks', 31, { round_ms: 10000 })).toEqual(entry);
    expect(f.calls[0].body).toEqual({ score: 31, metadata: { round_ms: 10000 } });
  });

  it('avvisar negativa och icke-heltal innan något skickas', async () => {
    const { f, lb } = setup({});
    await expect(lb.submit('daily-clicks', -1)).rejects.toBeInstanceOf(RangeError);
    await expect(lb.submit('daily-clicks', 1.5)).rejects.toBeInstanceOf(RangeError);
    expect(f.calls).toHaveLength(0);
  });
});
```

`src/lib/computercat/proof.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { createProof, fnv1aSignature } from './proof';

// Vektorerna är räknade 2026-10-13 med AntiCheatService::fnv1aSignature (PHP, byte för byte).
describe('fnv1aSignature', () => {
  it('matchar servern för ASCII', () => {
    expect(fnv1aSignature('round-1', 18, 'abc', 45000)).toBe('1uamwfr');
    expect(fnv1aSignature('2026-10-13', 42, 'k9', 10000)).toBe('afwtcv');
  });

  it('matchar servern för icke-ASCII (UTF-8-bytes, inte UTF-16)', () => {
    expect(fnv1aSignature('kattsvep-å', 1, 'x', 1)).toBe('wt2f7k');
  });
});

describe('createProof', () => {
  it('bygger beviset och avvisar värden som PHP ser som falska eller icke-heltal', () => {
    expect(createProof({ pid: 'round-1', moves: 18, stateHash: 'abc', elapsedMs: 45000 })).toEqual({
      pid: 'round-1',
      mc: 18,
      gh: 'abc',
      t: 45000,
      sig: '1uamwfr',
    });
    expect(() => createProof({ pid: '0', moves: 1, stateHash: 'x', elapsedMs: 1 })).toThrow(RangeError);
    expect(() => createProof({ pid: 'p', moves: 1, stateHash: '', elapsedMs: 1 })).toThrow(RangeError);
    expect(() => createProof({ pid: 'p', moves: 1.5, stateHash: 'x', elapsedMs: 1 })).toThrow(RangeError);
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat/leaderboards.test.ts src/lib/computercat/proof.test.ts
```

Förväntat: FAIL, "Failed to resolve import ./leaderboards" och "./proof".

- [ ] **Steg 3: Implementera**

`src/lib/computercat/leaderboards.ts`:

```ts
import type { Authed } from './auth';
import { isRecord } from './http';

export type LeaderboardEntry = {
  rank: number | null;
  score: number;
  metadata: Record<string, unknown> | null;
  submitted_at: string;
  user: { id: number; display_name: string };
};
export type LeaderboardPage = { entries: LeaderboardEntry[]; periodKey: string };
export type MyRank = { entry: LeaderboardEntry | null; rank: number | null; periodKey: string };

const seg = encodeURIComponent;

/** LeaderboardController::store kräver integer|min:0 (LeaderboardController.php:59-62). */
export function assertScore(score: number): void {
  if (!Number.isSafeInteger(score) || score < 0) {
    throw new RangeError(`score måste vara ett heltal ≥ 0, fick ${score}`);
  }
}

export function createLeaderboards(game: string, authed: Authed) {
  const base = `/games/${seg(game)}/leaderboards`;

  return {
    /** Utan periodKey: serverns aktuella period (UTC). Periodnyckeln räknas aldrig ut i klienten. */
    async get(type: string, periodKey?: string): Promise<LeaderboardPage> {
      const path = periodKey ? `${base}/${seg(type)}/${seg(periodKey)}` : `${base}/${seg(type)}`;
      const res = await authed<unknown>('GET', path);
      if (!isRecord(res) || !Array.isArray(res.data) || typeof res.period_key !== 'string') {
        throw new Error(`Oväntat svar från GET ${path}`);
      }
      return { entries: res.data as LeaderboardEntry[], periodKey: res.period_key };
    },

    async me(type: string): Promise<MyRank> {
      const path = `${base}/${seg(type)}/me`;
      const res = await authed<unknown>('GET', path);
      if (!isRecord(res) || typeof res.period_key !== 'string') throw new Error(`Oväntat svar från GET ${path}`);
      return {
        entry: (res.data as LeaderboardEntry | null) ?? null,
        rank: typeof res.rank === 'number' ? res.rank : null,
        periodKey: res.period_key,
      };
    },

    /** Servern behåller bästa resultat per spelare och period, så en dubblett är ofarlig. */
    async submit(type: string, score: number, metadata?: Record<string, unknown>): Promise<LeaderboardEntry> {
      assertScore(score);
      const path = `${base}/${seg(type)}`;
      const res = await authed<unknown>('POST', path, metadata ? { score, metadata } : { score });
      if (!isRecord(res) || !isRecord(res.data)) throw new Error(`Oväntat svar från POST ${path}`);
      return res.data as LeaderboardEntry;
    },
  };
}
```

`src/lib/computercat/proof.ts`:

```ts
/**
 * Bevis på spel för topplistor med anti-cheat (AntiCheatService.php:69-103).
 *
 * Skicka som metadata.proof. Servern kontrollerar signaturen och att |t - score|
 * är högst proof_time_tolerance (standard 1000). För topplistor där score inte
 * är en tid måste spelets anti_cheat-inställning ha proof_time_tolerance = 0,
 * annars avvisas beviset med "Proof time does not match score."
 */
export type PlayProof = { pid: string; mc: number; gh: string; t: number; sig: string };

/** FNV-1a 32-bit över UTF-8-bytes, base 36; samma som AntiCheatService::fnv1aSignature. */
export function fnv1aSignature(pid: string, mc: number, gh: string, t: number): string {
  const bytes = new TextEncoder().encode(`${pid}|${mc}|${gh}|${t}`);
  let h = 0x811c9dc5;
  for (const b of bytes) {
    h ^= b;
    h = Math.imul(h, 0x01000193);
  }
  return (h >>> 0).toString(36);
}

// PHP:s `! $pid` är sant för "" och "0" (AntiCheatService.php:77).
const phpTruthy = (s: string) => s !== '' && s !== '0';

export function createProof(input: { pid: string; moves: number; stateHash: string; elapsedMs: number }): PlayProof {
  const { pid, moves, stateHash, elapsedMs } = input;
  if (!phpTruthy(pid) || !phpTruthy(stateHash)) throw new RangeError('pid och stateHash får inte vara "" eller "0"');
  if (!Number.isSafeInteger(moves) || !Number.isSafeInteger(elapsedMs)) {
    throw new RangeError('moves och elapsedMs måste vara heltal');
  }
  return { pid, mc: moves, gh: stateHash, t: elapsedMs, sig: fnv1aSignature(pid, moves, stateHash, elapsedMs) };
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/lib/computercat && npm run typecheck
```

Förväntat: `Tests 33 passed (33)`; typkontroll utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: topplistor och anti-cheat-bevis över UTF-8"
```

---

### Task 5: Offline-kö för resultat och bakgrundssynk

**Filer:**
- Skapa: `src/lib/computercat/queue.ts`, `src/lib/computercat/background.ts`
- Test: `src/lib/computercat/queue.test.ts`, `src/lib/computercat/background.test.ts`

**Gränssnitt:**
- Konsumerar: `ApiError` (Task 2), `assertScore` (Task 4), `readJson`, `writeJson`, `KeyValueStore`, `isRecord`.
- Producerar:
  - `type QueuedScore = { id: string; type: string; score: number; metadata: Record<string, unknown> | null; queuedAt: number }`
  - `type FlushResult = { sent: number; dropped: { item: QueuedScore; reason: string }[]; remaining: number }`
  - `DEFAULT_MAX_AGE_MS = 86_400_000`, `createScoreQueue({storage, game, submit, now?, maxAgeMs?})` → `{ enqueue(type, score, metadata?), flush(): Promise<FlushResult>, pending(): QueuedScore[] }`. Lagringsnyckel `computercat:<game>:score-queue`.
  - `startBackgroundSync(flush, target, intervalMs = 60_000)` → `stop()`.

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/lib/computercat/queue.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, NetworkError } from './http';
import { createScoreQueue } from './queue';
import { memoryStorage } from './storage';

const GAME = 'kattsvep';
const ok = () => Promise.resolve({});
const rejected422 = () =>
  Promise.reject(new ApiError(422, 'Score exceeds maximum allowed value.', {}, 'POST', '/games/kattsvep/leaderboards/daily-clicks'));
const offline = () => Promise.reject(new NetworkError('POST', '/games/kattsvep/leaderboards/daily-clicks', new TypeError('Failed to fetch')));

afterEach(() => vi.restoreAllMocks());

describe('createScoreQueue', () => {
  it('kön överlever en omladdning och töms en gång', async () => {
    const storage = memoryStorage();
    const first = createScoreQueue({ storage, game: GAME, submit: offline });
    first.enqueue('daily-clicks', 31, { round_ms: 10000 });
    expect((await first.flush()).remaining).toBe(1);

    const submit = vi.fn(ok);
    const second = createScoreQueue({ storage, game: GAME, submit });
    expect(second.pending()).toHaveLength(1);
    expect(await second.flush()).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(submit).toHaveBeenCalledWith('daily-clicks', 31, { round_ms: 10000 });
    expect(JSON.parse(storage.getItem(`computercat:${GAME}:score-queue`)!)).toEqual([]);
  });

  it('två samtidiga flush skickar varje resultat en gång', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    const submit = vi.fn(async () => {
      await gate;
      return {};
    });
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 5);
    q.enqueue('daily-clicks', 6);
    const both = Promise.all([q.flush(), q.flush()]);
    release();
    const [a, b] = await both;
    expect(submit).toHaveBeenCalledTimes(2);
    expect(a.sent + b.sent).toBe(2);
    expect(q.pending()).toEqual([]);
  });

  it('422 kastas synligt och blockerar inte kön', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const submit = vi.fn().mockImplementationOnce(rejected422).mockImplementationOnce(ok);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 999999);
    q.enqueue('daily-clicks', 12);
    const r = await q.flush();
    expect(r.sent).toBe(1);
    expect(r.remaining).toBe(0);
    expect(r.dropped).toHaveLength(1);
    expect(r.dropped[0].reason).toBe('422 Score exceeds maximum allowed value.');
    expect(error).toHaveBeenCalledWith(expect.stringContaining('999999'));
  });

  it('nätverksfel stoppar och behåller ordningen', async () => {
    const submit = vi.fn(offline);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 1);
    q.enqueue('daily-clicks', 2);
    const r = await q.flush();
    expect(r).toEqual({ sent: 0, dropped: [], remaining: 2 });
    expect(submit).toHaveBeenCalledTimes(1);
    expect(q.pending().map((i) => i.score)).toEqual([1, 2]);
  });

  it('401 och 429 behålls för nästa försök', async () => {
    const submit = vi
      .fn()
      .mockImplementationOnce(() => Promise.reject(new ApiError(401, 'Unauthenticated.', {}, 'POST', '/x')))
      .mockImplementationOnce(() => Promise.reject(new ApiError(429, 'Too Many Attempts.', {}, 'POST', '/x')));
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit });
    q.enqueue('daily-clicks', 1);
    expect((await q.flush()).remaining).toBe(1);
    expect((await q.flush()).remaining).toBe(1);
  });

  it('för gamla resultat kastas synligt', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    let t = Date.parse('2026-10-13T08:00:00Z');
    const submit = vi.fn(ok);
    const q = createScoreQueue({ storage: memoryStorage(), game: GAME, submit, now: () => t });
    q.enqueue('daily-clicks', 3);
    t += 25 * 60 * 60 * 1000;
    const r = await q.flush();
    expect(r.dropped.map((d) => d.reason)).toEqual(['äldre än 24 h']);
    expect(submit).not.toHaveBeenCalled();
    expect(warn).toHaveBeenCalled();
  });
});
```

`src/lib/computercat/background.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { startBackgroundSync } from './background';

afterEach(() => vi.useRealTimers());

describe('startBackgroundSync', () => {
  it('tömmer direkt, när nätet kommer tillbaka och med jämna mellanrum, och slutar vid stop', () => {
    vi.useFakeTimers();
    const flush = vi.fn(() => Promise.resolve());
    const target = new EventTarget();
    const stop = startBackgroundSync(flush, target, 60_000);
    expect(flush).toHaveBeenCalledTimes(1);
    target.dispatchEvent(new Event('online'));
    expect(flush).toHaveBeenCalledTimes(2);
    vi.advanceTimersByTime(60_000);
    expect(flush).toHaveBeenCalledTimes(3);
    stop();
    target.dispatchEvent(new Event('online'));
    vi.advanceTimersByTime(120_000);
    expect(flush).toHaveBeenCalledTimes(3);
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat/queue.test.ts src/lib/computercat/background.test.ts
```

Förväntat: FAIL, "Failed to resolve import ./queue" och "./background".

- [ ] **Steg 3: Implementera**

`src/lib/computercat/queue.ts`:

```ts
import { ApiError, isRecord } from './http';
import { assertScore } from './leaderboards';
import { readJson, writeJson, type KeyValueStore } from './storage';

export type QueuedScore = {
  id: string;
  type: string;
  score: number;
  metadata: Record<string, unknown> | null;
  queuedAt: number;
};
export type DroppedScore = { item: QueuedScore; reason: string };
export type FlushResult = { sent: number; dropped: DroppedScore[]; remaining: number };

/**
 * Servern lägger resultatet i den period som gäller när det tas emot (UTC), inte när
 * det spelades. Ett dygn gammalt resultat skulle hamna på fel dags lista, så det kastas.
 */
export const DEFAULT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

export type QueueDeps = {
  storage: KeyValueStore;
  game: string;
  submit: (type: string, score: number, metadata: Record<string, unknown> | undefined) => Promise<unknown>;
  now?: () => number;
  maxAgeMs?: number;
};

function isQueue(v: unknown): v is QueuedScore[] {
  return (
    Array.isArray(v) &&
    v.every(
      (i) =>
        isRecord(i) &&
        typeof i.id === 'string' &&
        typeof i.type === 'string' &&
        typeof i.score === 'number' &&
        typeof i.queuedAt === 'number' &&
        (i.metadata === null || isRecord(i.metadata)),
    )
  );
}

/** Fel som gäller just det här resultatet: försök aldrig igen. 401, 408 och 429 är tillfälliga. */
function isPermanent(e: unknown): e is ApiError {
  return e instanceof ApiError && e.status >= 400 && e.status < 500 && ![401, 408, 429].includes(e.status);
}

export function createScoreQueue(deps: QueueDeps) {
  const key = `computercat:${deps.game}:score-queue`;
  const now = deps.now ?? Date.now;
  const maxAge = deps.maxAgeMs ?? DEFAULT_MAX_AGE_MS;
  let items: QueuedScore[] = readJson(deps.storage, key, isQueue) ?? [];
  let seq = 0;
  let chain: Promise<unknown> = Promise.resolve();

  const persist = () => writeJson(deps.storage, key, items);
  const remove = (id: string) => {
    items = items.filter((i) => i.id !== id);
    persist();
  };

  function enqueue(type: string, score: number, metadata: Record<string, unknown> | null = null): QueuedScore {
    assertScore(score);
    const t = now();
    const item: QueuedScore = {
      id: `${t.toString(36)}-${(seq++).toString(36)}-${Math.random().toString(36).slice(2, 8)}`,
      type,
      score,
      metadata,
      queuedAt: t,
    };
    items = [...items, item];
    persist();
    return item;
  }

  async function run(): Promise<FlushResult> {
    const result: FlushResult = { sent: 0, dropped: [], remaining: 0 };
    for (const item of [...items]) {
      if (now() - item.queuedAt > maxAge) {
        remove(item.id);
        result.dropped.push({ item, reason: 'äldre än 24 h' });
        console.warn(`[computercat] resultat ${item.score} till ${item.type} var för gammalt och skickades inte`);
        continue;
      }
      try {
        await deps.submit(item.type, item.score, item.metadata ?? undefined);
        remove(item.id);
        result.sent++;
      } catch (e) {
        if (!isPermanent(e)) break; // offline, 5xx, 401 efter ny spelare, 429: försök senare, i samma ordning
        remove(item.id);
        const reason = `${e.status} ${e.message}`;
        result.dropped.push({ item, reason });
        console.error(`[computercat] resultat ${item.score} till ${item.type} avvisades: ${reason}`);
      }
    }
    result.remaining = items.length;
    return result;
  }

  /** Körningar läggs på kö efter varandra, så samma resultat skickas aldrig två gånger parallellt. */
  function flush(): Promise<FlushResult> {
    const p = chain.then(run);
    chain = p.catch(() => undefined);
    return p;
  }

  return { enqueue, flush, pending: () => [...items] };
}
```

`src/lib/computercat/background.ts`:

```ts
export type OnlineTarget = {
  addEventListener(type: 'online', listener: () => void): void;
  removeEventListener(type: 'online', listener: () => void): void;
};

/** Töm kön nu, när nätet kommer tillbaka och med jämna mellanrum. Returnerar stop(). */
export function startBackgroundSync(
  flush: () => Promise<unknown>,
  target: OnlineTarget,
  intervalMs = 60_000,
): () => void {
  const run = () => {
    void flush();
  };
  run();
  target.addEventListener('online', run);
  const id = setInterval(run, intervalMs);
  return () => {
    target.removeEventListener('online', run);
    clearInterval(id);
  };
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/lib/computercat && npm run typecheck
```

Förväntat: `Tests 40 passed (40)`; typkontroll utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: offline-kö för resultat och bakgrundssynk"
```

---

### Task 6: Sparningar med version

**Filer:**
- Skapa: `src/lib/computercat/saves.ts`
- Test: `src/lib/computercat/saves.test.ts`

**Gränssnitt:**
- Konsumerar: `Authed` (Task 3), `ApiError`, `isRecord` (Task 2).
- Producerar: `type CloudSave<T> = { save_key: string; data: T; version: number; checksum: string | null; saved_at: string | null }`, `type PutResult<T> = { ok: true; save: CloudSave<T> } | { ok: false; conflict: CloudSave<T> }`, `createSaves(game, authed)` → `{ get<T>(key), put<T>(key, data, version), sync<T>(key, local, merge) }`.

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/lib/computercat/saves.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json, storedSession } from '../../../test/fakeFetch';
import { createAuth } from './auth';
import { ApiError } from './http';
import { createSaves } from './saves';

const GAME = 'kattsvep';
const PATH = '/games/kattsvep/saves/main';
type Data = { best: number };
const save = (version: number, best: number) => ({
  save_key: 'main',
  data: { best },
  version,
  checksum: null,
  saved_at: '2026-10-13T09:00:00Z',
});
const conflict = (version: number, best: number) =>
  json(409, { message: 'Version conflict. Fetch latest save and merge.', server_version: version, data: save(version, best) });
const merge = (a: Data, b: Data): Data => ({ best: Math.max(a.best, b.best) });

function setup(routes: Parameters<typeof fakeFetch>[0]) {
  const f = fakeFetch(routes);
  const auth = createAuth({ fetch: f.fetch, baseUrl: BASE, game: GAME, storage: storedSession(GAME) });
  return { f, saves: createSaves(GAME, auth.authed) };
}

afterEach(() => vi.restoreAllMocks());

describe('createSaves', () => {
  it('saknad sparning ger null', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { saves } = setup({ [`GET ${PATH}`]: [json(404, { message: 'Save not found.' })] });
    expect(await saves.get('main')).toBeNull();
  });

  it('okänt spel är ett fel, inte en tom sparning', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { saves } = setup({ [`GET ${PATH}`]: [json(404, { message: 'Game not found.' })] });
    await expect(saves.get('main')).rejects.toBeInstanceOf(ApiError);
  });

  it('put skickar data och version och returnerar ny version', async () => {
    const { f, saves } = setup({ [`PUT ${PATH}`]: [json(200, { data: save(4, 9) })] });
    expect(await saves.put('main', { best: 9 }, 3)).toEqual({ ok: true, save: save(4, 9) });
    expect(f.calls[0].body).toEqual({ data: { best: 9 }, version: 3 });
  });

  it('409 ger serverns sparning som konflikt', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { saves } = setup({ [`PUT ${PATH}`]: [conflict(5, 12)] });
    expect(await saves.put('main', { best: 9 }, 3)).toEqual({ ok: false, conflict: save(5, 12) });
  });

  it('tomt data-objekt avvisas innan anropet (servern kräver required|array)', async () => {
    const { f, saves } = setup({});
    await expect(saves.put('main', {}, 0)).rejects.toBeInstanceOf(RangeError);
    expect(f.calls).toHaveLength(0);
  });

  it('sync utan molnsparning skickar lokalt med version 0', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(404, { message: 'Save not found.' })],
      [`PUT ${PATH}`]: [json(200, { data: save(1, 4) })],
    });
    expect((await saves.sync<Data>('main', { best: 4 }, merge)).version).toBe(1);
    expect(f.calls[1].body).toEqual({ data: { best: 4 }, version: 0 });
  });

  it('sync slår ihop med molnet och skickar med molnets version', async () => {
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [json(200, { data: save(4, 10) })],
    });
    await saves.sync<Data>('main', { best: 4 }, merge);
    expect(f.calls[1].body).toEqual({ data: { best: 10 }, version: 3 });
  });

  it('sync vid 409 slår ihop med serverns kopia och försöker en gång till', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { f, saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [conflict(4, 15), json(200, { data: save(5, 15) })],
    });
    expect((await saves.sync<Data>('main', { best: 4 }, merge)).version).toBe(5);
    expect(f.calls[2].body).toEqual({ data: { best: 15 }, version: 4 });
  });

  it('sync som krockar två gånger ger fel', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { saves } = setup({
      [`GET ${PATH}`]: [json(200, { data: save(3, 10) })],
      [`PUT ${PATH}`]: [conflict(4, 15), conflict(5, 16)],
    });
    await expect(saves.sync<Data>('main', { best: 4 }, merge)).rejects.toThrow('krockade två gånger');
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat/saves.test.ts
```

Förväntat: FAIL, "Failed to resolve import ./saves".

- [ ] **Steg 3: Implementera**

`src/lib/computercat/saves.ts`:

```ts
import type { Authed } from './auth';
import { ApiError, isRecord } from './http';

/** GameSaveResource.php:12-18. Nyckeln `ownership` tas bort av servern (GameSaveController.php:59-62). */
export type CloudSave<T> = {
  save_key: string;
  data: T;
  version: number;
  checksum: string | null;
  saved_at: string | null;
};
export type PutResult<T> = { ok: true; save: CloudSave<T> } | { ok: false; conflict: CloudSave<T> };

const seg = encodeURIComponent;

function asSave<T>(v: unknown, what: string): CloudSave<T> {
  if (!isRecord(v) || !isRecord(v.data) || typeof v.version !== 'number') throw new Error(`Oväntat svar från ${what}`);
  return v as unknown as CloudSave<T>;
}

export function createSaves(game: string, authed: Authed) {
  const base = `/games/${seg(game)}/saves`;

  async function get<T>(key: string): Promise<CloudSave<T> | null> {
    const path = `${base}/${seg(key)}`;
    try {
      const res = await authed<unknown>('GET', path);
      return asSave<T>(isRecord(res) ? res.data : undefined, `GET ${path}`);
    } catch (e) {
      // Bara "Save not found." betyder tom sparning; "Game not found." är felkonfiguration.
      if (e instanceof ApiError && e.status === 404 && e.message === 'Save not found.') return null;
      throw e;
    }
  }

  /** version = den version du senast läste (0 om ingen sparning finns). Servern ger version + 1. */
  async function put<T extends Record<string, unknown>>(key: string, data: T, version: number): Promise<PutResult<T>> {
    if (Object.keys(data).length === 0) {
      throw new RangeError('data får inte vara ett tomt objekt (servern kräver required|array)');
    }
    if (!Number.isSafeInteger(version) || version < 0) throw new RangeError(`ogiltig version ${version}`);
    const path = `${base}/${seg(key)}`;
    try {
      const res = await authed<unknown>('PUT', path, { data, version });
      return { ok: true, save: asSave<T>(isRecord(res) ? res.data : undefined, `PUT ${path}`) };
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && isRecord(e.body)) {
        return { ok: false, conflict: asSave<T>(e.body.data, `PUT ${path} (409)`) };
      }
      throw e;
    }
  }

  /** Hämta, slå ihop, skicka; vid 409 slå ihop med serverns kopia och försök en gång till. */
  async function sync<T extends Record<string, unknown>>(
    key: string,
    local: T,
    merge: (local: T, cloud: T) => T,
  ): Promise<CloudSave<T>> {
    const cloud = await get<T>(key);
    const merged = cloud ? merge(local, cloud.data) : local;
    const first = await put(key, merged, cloud?.version ?? 0);
    if (first.ok) return first.save;
    const second = await put(key, merge(merged, first.conflict.data), first.conflict.version);
    if (second.ok) return second.save;
    throw new Error(`Sparningen ${key} krockade två gånger i rad; försök igen senare`);
  }

  return { get, put, sync };
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/lib/computercat && npm run typecheck
```

Förväntat: `Tests 49 passed (49)`; typkontroll utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: sparningar med version och sammanslagning vid 409"
```

---

### Task 7: Fasaden `createComputercat`, `getComputercat` och felbeskrivningar

**Filer:**
- Skapa: `src/lib/computercat/index.ts`, `src/lib/computercat/errors.ts`
- Test: `src/lib/computercat/index.test.ts`, `src/lib/computercat/errors.test.ts`

**Gränssnitt:**
- Konsumerar: allt i Task 2–6.
- Producerar: `createComputercat({config, fetch?, storage?, now?})` → `{ config, auth, leaderboards, saves, queue, recordScore(type, score, metadata?) → { item, flushed } }`; `type Computercat`; `getComputercat()` → `{ ok: true; client } | { ok: false; error }`; `describeError(e)`; re-export av `readConfig`, `startBackgroundSync`, `createProof`, `ApiError`, `NetworkError` och typerna.

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/lib/computercat/errors.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { describeError } from './errors';
import { ApiError, NetworkError } from './http';

const api = (status: number, message: string) => new ApiError(status, message, { message }, 'GET', '/x');

describe('describeError', () => {
  it('saknad topplista pekar på upsert_leaderboard_type', () => {
    expect(describeError(api(404, 'Leaderboard type not found.'))).toContain('upsert_leaderboard_type');
  });

  it('okänt spel pekar på NEXT_PUBLIC_COMPUTERCAT_GAME', () => {
    expect(describeError(api(404, 'Game not found.'))).toContain('NEXT_PUBLIC_COMPUTERCAT_GAME');
  });

  it('offline säger att spelet sparas lokalt', () => {
    expect(describeError(new NetworkError('GET', '/x', new TypeError('Failed to fetch')))).toContain('Offline');
  });

  it('övriga fel visar status och serverns meddelande', () => {
    expect(describeError(api(500, 'Server Error'))).toBe('Computercat svarade 500: Server Error');
  });
});
```

`src/lib/computercat/index.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BASE, fakeFetch, json } from '../../../test/fakeFetch';
import { memoryStorage } from './storage';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('computercat', () => {
  it('import gör inga nätverksanrop och getComputercat vägrar utanför webbläsaren', async () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    const mod = await import('./index');
    expect(fetchSpy).not.toHaveBeenCalled();
    const r = mod.getComputercat();
    expect(r.ok).toBe(false);
  });

  it('recordScore köar, skapar spelare och skickar till topplistan', async () => {
    const { createComputercat } = await import('./index');
    const f = fakeFetch({
      'POST /auth/anonymous': [
        json(201, { user: { id: 9, display_name: 'Player-9', is_anonymous: true, created_at: null }, token: '9|t' }),
      ],
      'POST /games/kattsvep/leaderboards/daily-clicks': [
        json(201, { data: { rank: null, score: 14, metadata: null, submitted_at: 'x', user: { id: 9, display_name: 'Player-9' } } }),
      ],
    });
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, fetch: f.fetch, storage: memoryStorage() });
    const { item, flushed } = cc.recordScore('daily-clicks', 14, { round_ms: 10000 });
    expect(item.score).toBe(14);
    expect(await flushed).toEqual({ sent: 1, dropped: [], remaining: 0 });
    expect(f.unmatched).toEqual([]);
  });

  it('standard-fetch slås upp vid anropet, inte vid skapandet (undviker "Illegal invocation")', async () => {
    const { createComputercat } = await import('./index');
    const cc = createComputercat({ config: { game: 'kattsvep', baseUrl: BASE }, storage: memoryStorage() });
    const later = vi.fn(async () =>
      json(201, { user: { id: 1, display_name: 'P', is_anonymous: true, created_at: null }, token: '1|x' }),
    );
    vi.stubGlobal('fetch', later);
    await cc.auth.getSession();
    expect(later).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/lib/computercat/index.test.ts src/lib/computercat/errors.test.ts
```

Förväntat: FAIL, "Failed to resolve import ./index" och "./errors".

- [ ] **Steg 3: Implementera**

`src/lib/computercat/errors.ts`:

```ts
import { ApiError, NetworkError } from './http';

/** Text för spelaren/utvecklaren. Meddelandena matchar LeaderboardController.php:25 och ResolveGame.php:20. */
export function describeError(e: unknown): string {
  if (e instanceof NetworkError) return 'Offline: kunde inte nå Computercat. Det du spelar sparas lokalt.';
  if (e instanceof ApiError) {
    if (e.status === 404 && e.message === 'Leaderboard type not found.') {
      return 'Topplistan finns inte i Computercat ännu. Skapa den med upsert_leaderboard_type.';
    }
    if (e.status === 404 && e.message === 'Game not found.') {
      return 'Spelet finns inte eller är inaktivt i Computercat (kontrollera NEXT_PUBLIC_COMPUTERCAT_GAME).';
    }
    if (e.status === 429) return 'För många anrop just nu. Försök igen om en minut.';
    return `Computercat svarade ${e.status}: ${e.message}`;
  }
  return e instanceof Error ? e.message : String(e);
}
```

`src/lib/computercat/index.ts`:

```ts
import { createAuth } from './auth';
import { readConfig, type ComputercatConfig } from './config';
import type { FetchLike } from './http';
import { createLeaderboards } from './leaderboards';
import { createScoreQueue } from './queue';
import { createSaves } from './saves';
import { browserStorage, type KeyValueStore } from './storage';

export { readConfig, parseConfig, DEFAULT_BASE_URL, type ComputercatConfig } from './config';
export { ApiError, NetworkError } from './http';
export { describeError } from './errors';
export { startBackgroundSync } from './background';
export { createProof, fnv1aSignature, type PlayProof } from './proof';
export type { Session } from './auth';
export type { LeaderboardEntry, LeaderboardPage, MyRank } from './leaderboards';
export type { QueuedScore, FlushResult } from './queue';
export type { CloudSave, PutResult } from './saves';

export type ComputercatOptions = {
  config: ComputercatConfig;
  fetch?: FetchLike;
  storage?: KeyValueStore;
  now?: () => number;
};

export function createComputercat(opts: ComputercatOptions) {
  const { game, baseUrl } = opts.config;
  // Slå upp globalThis.fetch vid varje anrop: en lagrad referens till window.fetch ger "Illegal invocation".
  const fetchImpl: FetchLike = opts.fetch ?? ((input, init) => globalThis.fetch(input, init));
  const storage = opts.storage ?? browserStorage();

  const auth = createAuth({ fetch: fetchImpl, baseUrl, game, storage });
  const leaderboards = createLeaderboards(game, auth.authed);
  const saves = createSaves(game, auth.authed);
  const queue = createScoreQueue({ storage, game, submit: leaderboards.submit, now: opts.now });

  return {
    config: opts.config,
    auth,
    leaderboards,
    saves,
    queue,
    /** Lokalt först: köa resultatet och försök skicka direkt. `flushed` säger vad som hände. */
    recordScore(type: string, score: number, metadata?: Record<string, unknown>) {
      const item = queue.enqueue(type, score, metadata ?? null);
      return { item, flushed: queue.flush() };
    },
  };
}

export type Computercat = ReturnType<typeof createComputercat>;
export type ComputercatResult = { ok: true; client: Computercat } | { ok: false; error: string };

let instance: ComputercatResult | null = null;

/** Klienten för den här sidan. Bara i webbläsaren; statisk export kör ingen kod på servern. */
export function getComputercat(): ComputercatResult {
  if (typeof window === 'undefined') {
    return { ok: false, error: 'getComputercat() anropades utanför webbläsaren.' };
  }
  if (instance) return instance;
  const cfg = readConfig();
  if (!cfg.ok) console.error(`[computercat] ${cfg.error}`);
  instance = cfg.ok ? { ok: true, client: createComputercat({ config: cfg.config }) } : { ok: false, error: cfg.error };
  return instance;
}
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run && npm run typecheck && npm run lint
```

Förväntat: `Test Files 11 passed (11)`, `Tests 56 passed (56)`; typkontroll och lint utan fel.

- [ ] **Steg 5: Commit**

```bash
cd $WT && git add -A && git commit -m "Computercat-klient: fasad, singleton per sida och felbeskrivningar"
```

---

### Task 8: Exportkontroll och `npm run check`

**Filer:**
- Skapa: `scripts/check-export.mjs`
- Ändra: `package.json` (skriptet `check`)

**Gränssnitt:**
- Konsumerar: `npm run build` (Task 1).
- Producerar: `npm run check` = typkontroll, lint, test, bygge, exportkontroll. Används av CLAUDE.md och Task 11.

- [ ] **Steg 1: Skriv kontrollen**

`scripts/check-export.mjs`:

```js
// Kontrollerar att den statiska exporten finns och, om NEXT_PUBLIC_COMPUTERCAT_GAME
// är satt, att slugen faktiskt bakats in i klientkoden (annars skickar spelet inget).
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const fail = (msg) => {
  console.error(`check-export: ${msg}`);
  process.exit(1);
};

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });

if (!existsSync('out/index.html')) {
  fail('out/index.html saknas. Är output: "export" satt i next.config.ts?');
}

const game = process.env.NEXT_PUBLIC_COMPUTERCAT_GAME?.trim();
if (game) {
  const baked = walk('out/_next')
    .filter((f) => f.endsWith('.js'))
    .some((f) => readFileSync(f, 'utf8').includes(game));
  if (!baked) fail(`slugen "${game}" finns inte i någon JS-fil under out/_next; variabeln har inte bakats in.`);
}

console.log(`check-export: OK (out/index.html${game ? `, slug "${game}" inbakad` : ', ingen slug satt'})`);
```

- [ ] **Steg 2: Lägg till skriptet**

I `package.json`, under `scripts`, lägg till efter `typecheck`:

```json
    "check": "npm run typecheck && npm run lint && npm test && NEXT_TELEMETRY_DISABLED=1 npm run build && node scripts/check-export.mjs"
```

- [ ] **Steg 3: Kör**

```bash
cd $WT && npm run check
NEXT_PUBLIC_COMPUTERCAT_GAME=finns-inte-i-koden-xyz node scripts/check-export.mjs; echo "exit=$?"
```

Förväntat: första kommandot slutar med `check-export: OK (out/index.html, ingen slug satt)`. Andra: `check-export: slugen "finns-inte-i-koden-xyz" finns inte …` och `exit=1` (bevisar att kontrollen kan falla; bygget gjordes utan slug).

- [ ] **Steg 4: Commit**

```bash
cd $WT && git add -A && git commit -m "npm run check: typkontroll, lint, test, bygge och exportkontroll"
```

---

### Task 9: Exempelspelet (klickrace med daglig topplista)

**Filer:**
- Skapa: `src/game/constants.ts`, `src/game/clickStore.ts`, `src/components/ClickGame.tsx`, `src/components/Leaderboard.tsx`, `src/components/ConfigBanner.tsx`, `src/components/BackgroundSync.tsx`
- Ändra: `src/app/page.tsx`, `src/app/layout.tsx`
- Test: `src/game/clickStore.test.ts`

**Gränssnitt:**
- Konsumerar: `getComputercat`, `readConfig`, `describeError`, `startBackgroundSync`, `LeaderboardPage` (Task 7).
- Producerar: `LEADERBOARD_TYPE = 'daily-clicks'`; `ROUND_MS = 10_000`; `useClickStore` med `phase`, `clicks`, `startedAt`, `lastScore`, `best`, `start(now)`, `click(now)`, `finish()`, `reset()`; persist-nyckel `spelmall-click` (bara `best`).
- Topplistan som Claude skapar via connectorn (dokumenteras i Task 10): `upsert_leaderboard_type` med `{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}` (fälten enligt `UpsertLeaderboardTypeTool.php:16-27`).

- [ ] **Steg 1: Skriv de fallerande testerna**

`src/game/clickStore.test.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Zustand persist läser localStorage när storen skapas, alltså före importerna nedan.
vi.hoisted(() => {
  const map = new Map<string, string>();
  (globalThis as { localStorage?: unknown }).localStorage = {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, String(v)),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: () => null,
    length: 0,
  };
});

import { ROUND_MS, useClickStore } from './clickStore';

const T0 = 1_000_000;

beforeEach(() => {
  useClickStore.setState({ phase: 'idle', clicks: 0, startedAt: null, lastScore: null, best: 0 });
});

describe('useClickStore', () => {
  it('klick räknas inte innan rundan startat', () => {
    useClickStore.getState().click(T0);
    expect(useClickStore.getState().clicks).toBe(0);
  });

  it('en runda räknar klick och ger resultatet', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 100);
    s.click(T0 + 200);
    s.click(T0 + 300);
    expect(useClickStore.getState().finish()).toBe(3);
    expect(useClickStore.getState()).toMatchObject({ phase: 'finished', lastScore: 3, best: 3 });
  });

  it('klick efter tio sekunder räknas inte', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + ROUND_MS);
    expect(useClickStore.getState().clicks).toBe(0);
  });

  it('finish två gånger ger resultatet bara en gång', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 1);
    expect(useClickStore.getState().finish()).toBe(1);
    expect(useClickStore.getState().finish()).toBeNull();
  });

  it('bästa resultat behålls över rundor', () => {
    const s = useClickStore.getState();
    s.start(T0);
    for (let i = 1; i <= 5; i++) s.click(T0 + i);
    useClickStore.getState().finish();
    s.start(T0 + 20_000);
    s.click(T0 + 20_001);
    useClickStore.getState().finish();
    expect(useClickStore.getState().best).toBe(5);
  });

  it('bara bästa resultatet sparas lokalt', () => {
    const s = useClickStore.getState();
    s.start(T0);
    s.click(T0 + 1);
    useClickStore.getState().finish();
    const saved = JSON.parse(localStorage.getItem('spelmall-click')!);
    expect(saved.state).toEqual({ best: 1 });
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run src/game
```

Förväntat: FAIL, "Failed to resolve import ./clickStore".

- [ ] **Steg 3: Implementera logiken**

`src/game/constants.ts`:

```ts
/**
 * Topplistan som exemplet skickar till. Skapas i Computercat med connector-verktyget
 * upsert_leaderboard_type (se CLAUDE.md). Byt namn när exemplet ersätts av spelet.
 */
export const LEADERBOARD_TYPE = 'daily-clicks';
```

`src/game/clickStore.ts`:

```ts
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

export const ROUND_MS = 10_000;

type Phase = 'idle' | 'running' | 'finished';

type ClickState = {
  phase: Phase;
  clicks: number;
  startedAt: number | null;
  lastScore: number | null;
  best: number;
  start: (now: number) => void;
  click: (now: number) => void;
  /** Avslutar rundan och ger resultatet, eller null om ingen runda pågick. */
  finish: () => number | null;
  reset: () => void;
};

export const useClickStore = create<ClickState>()(
  persist(
    (set, get) => ({
      phase: 'idle',
      clicks: 0,
      startedAt: null,
      lastScore: null,
      best: 0,
      start(now) {
        if (get().phase === 'running') return;
        set({ phase: 'running', clicks: 0, startedAt: now, lastScore: null });
      },
      click(now) {
        const s = get();
        if (s.phase !== 'running' || s.startedAt === null || now - s.startedAt >= ROUND_MS) return;
        set({ clicks: s.clicks + 1 });
      },
      finish() {
        const s = get();
        if (s.phase !== 'running') return null;
        set({ phase: 'finished', startedAt: null, lastScore: s.clicks, best: Math.max(s.best, s.clicks) });
        return s.clicks;
      },
      reset() {
        set({ phase: 'idle', clicks: 0, startedAt: null });
      },
    }),
    {
      name: 'spelmall-click',
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ best: s.best }),
      // Den statiska HTML:en renderas utan localStorage; läs in efter mount så att hydreringen stämmer.
      skipHydration: true,
    },
  ),
);
```

- [ ] **Steg 4: Kör testerna**

```bash
cd $WT && npx vitest run src/game
```

Förväntat: `Tests 6 passed (6)`.

- [ ] **Steg 5: Komponenterna**

`src/components/ConfigBanner.tsx`:

```tsx
import { readConfig } from '@/lib/computercat';

/** Syns när NEXT_PUBLIC_COMPUTERCAT_* saknas eller är fel. Värdena bakas in vid bygget. */
export function ConfigBanner() {
  const cfg = readConfig();
  if (cfg.ok) return null;
  return (
    <div role="alert" className="rounded-lg bg-amber-100 p-3 text-sm text-amber-950">
      {cfg.error}
    </div>
  );
}
```

`src/components/BackgroundSync.tsx`:

```tsx
'use client';

import { useEffect } from 'react';
import { getComputercat, startBackgroundSync } from '@/lib/computercat';

/** Kontrollerar spelaren och tömmer resultatkön vid start, vid "online" och varje minut. */
export function BackgroundSync() {
  useEffect(() => {
    const cc = getComputercat();
    if (!cc.ok) return;
    cc.client.auth.verify().catch((e: unknown) => console.warn('[computercat] kunde inte kontrollera spelaren', e));
    return startBackgroundSync(() => cc.client.queue.flush(), window);
  }, []);
  return null;
}
```

`src/components/Leaderboard.tsx`:

```tsx
'use client';

import { useEffect, useState } from 'react';
import { LEADERBOARD_TYPE } from '@/game/constants';
import { describeError, getComputercat, type LeaderboardPage } from '@/lib/computercat';

type BoardState = { kind: 'loading' } | { kind: 'error'; message: string } | { kind: 'ok'; page: LeaderboardPage };

async function loadBoard(): Promise<BoardState> {
  const cc = getComputercat();
  if (!cc.ok) return { kind: 'error', message: cc.error };
  try {
    return { kind: 'ok', page: await cc.client.leaderboards.get(LEADERBOARD_TYPE) };
  } catch (e) {
    return { kind: 'error', message: describeError(e) };
  }
}

export function Leaderboard({ refreshKey }: { refreshKey: number }) {
  const [state, setState] = useState<BoardState>({ kind: 'loading' });

  useEffect(() => {
    let cancelled = false;
    loadBoard().then((next) => {
      if (!cancelled) setState(next);
    });
    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  return (
    <section aria-labelledby="topplista" className="flex flex-col gap-2">
      <h2 id="topplista" className="text-xl font-semibold">
        Dagens topplista
      </h2>
      {state.kind === 'loading' && <p className="text-sm text-stone-600">Hämtar…</p>}
      {state.kind === 'error' && (
        <p role="alert" className="rounded-lg bg-red-100 p-3 text-sm text-red-900">
          {state.message}
        </p>
      )}
      {state.kind === 'ok' &&
        (state.page.entries.length === 0 ? (
          <p className="text-sm text-stone-600">Ingen har spelat {state.page.periodKey} (UTC) än.</p>
        ) : (
          <ol className="flex flex-col gap-1">
            {state.page.entries.map((e) => (
              <li key={e.user.id} className="flex justify-between rounded-lg bg-white/70 px-3 py-2">
                <span>
                  {e.rank ?? '–'}. {e.user.display_name}
                </span>
                <span className="font-mono">{e.score}</span>
              </li>
            ))}
          </ol>
        ))}
    </section>
  );
}
```

`src/components/ClickGame.tsx`:

```tsx
'use client';

import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { ROUND_MS, useClickStore } from '@/game/clickStore';
import { LEADERBOARD_TYPE } from '@/game/constants';
import { describeError, getComputercat } from '@/lib/computercat';

function sendScore(score: number, setStatus: (s: string) => void, onScoreSent: () => void): void {
  const cc = getComputercat();
  if (!cc.ok) {
    setStatus(`Inte skickat: ${cc.error}`);
    return;
  }
  const { flushed } = cc.client.recordScore(LEADERBOARD_TYPE, score, { round_ms: ROUND_MS });
  setStatus('Sparat lokalt, skickas till topplistan…');
  flushed.then(
    (r) => {
      if (r.dropped.length > 0) {
        setStatus(`Computercat tog inte emot resultatet: ${r.dropped.map((d) => d.reason).join('; ')}`);
      } else if (r.remaining > 0) {
        setStatus(`Offline: ${r.remaining} resultat i kö, skickas när nätet är tillbaka.`);
      } else {
        setStatus('Skickat till topplistan.');
        onScoreSent();
      }
    },
    (e: unknown) => setStatus(describeError(e)),
  );
}

export function ClickGame({ onScoreSent }: { onScoreSent: () => void }) {
  const phase = useClickStore((s) => s.phase);
  const clicks = useClickStore((s) => s.clicks);
  const startedAt = useClickStore((s) => s.startedAt);
  const lastScore = useClickStore((s) => s.lastScore);
  const best = useClickStore((s) => s.best);
  const [now, setNow] = useState(0);
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    void useClickStore.persist.rehydrate();
  }, []);

  useEffect(() => {
    if (phase !== 'running') return;
    const id = setInterval(() => {
      const t = Date.now();
      setNow(t);
      const s = useClickStore.getState();
      if (s.startedAt !== null && t - s.startedAt >= ROUND_MS) {
        const score = s.finish();
        if (score !== null) sendScore(score, setStatus, onScoreSent);
      }
    }, 100);
    return () => clearInterval(id);
  }, [phase, onScoreSent]);

  function handlePress() {
    const t = Date.now();
    if (phase === 'running') {
      useClickStore.getState().click(t);
      return;
    }
    setStatus(null);
    setNow(t);
    useClickStore.getState().start(t);
  }

  const remainingMs =
    phase === 'running' && startedAt !== null ? Math.min(ROUND_MS, Math.max(0, ROUND_MS - (now - startedAt))) : ROUND_MS;

  return (
    <section className="flex flex-col items-center gap-4">
      <div className="flex w-full justify-between text-sm text-stone-600">
        <span>Tid kvar: {Math.ceil(remainingMs / 1000)} s</span>
        <span>Bästa: {best}</span>
      </div>
      <motion.button
        type="button"
        onClick={handlePress}
        whileTap={{ scale: 0.92 }}
        aria-label={phase === 'running' ? 'Klicka' : 'Starta en runda'}
        className="h-48 w-48 rounded-full bg-amber-500 text-4xl font-bold text-white shadow-lg active:bg-amber-600"
      >
        {phase === 'running' ? clicks : 'Start'}
      </motion.button>
      {lastScore !== null && phase !== 'running' && <p className="text-lg">Du fick {lastScore} klick.</p>}
      {status && (
        <p role="status" className="text-center text-sm text-stone-700">
          {status}
        </p>
      )}
    </section>
  );
}
```

- [ ] **Steg 6: Sidan och layouten**

`src/app/page.tsx`:

```tsx
'use client';

import { useCallback, useState } from 'react';
import { ClickGame } from '@/components/ClickGame';
import { ConfigBanner } from '@/components/ConfigBanner';
import { Leaderboard } from '@/components/Leaderboard';

export default function Home() {
  const [refreshKey, setRefreshKey] = useState(0);
  const onScoreSent = useCallback(() => setRefreshKey((k) => k + 1), []);

  return (
    <main className="mx-auto flex min-h-full max-w-md flex-col gap-8 px-4 py-10">
      <ConfigBanner />
      <header className="flex flex-col gap-2">
        <h1 className="text-3xl font-bold">Klickrace</h1>
        <p className="text-stone-700">
          Klicka så många gånger du kan på tio sekunder. Ditt bästa resultat per dygn (UTC) hamnar på topplistan.
        </p>
      </header>
      <ClickGame onScoreSent={onScoreSent} />
      <Leaderboard refreshKey={refreshKey} />
    </main>
  );
}
```

I `src/app/layout.tsx`: lägg till importen `import { BackgroundSync } from '@/components/BackgroundSync';` och ersätt `<body …>{children}</body>` med:

```tsx
      <body className="h-full antialiased">
        {children}
        <BackgroundSync />
      </body>
```

- [ ] **Steg 7: Kör hela kontrollen**

```bash
cd $WT && npm run check
```

Förväntat: `Tests 62 passed (62)`, bygget listar `○ /`, sist `check-export: OK (out/index.html, ingen slug satt)`. Lint får inte ge `react-hooks`-fel; ger regeln `react-hooks/set-state-in-effect` fel på någon komponent, flytta `setState` in i en `.then`/callback som i `Leaderboard` (stäng inte av regeln).

- [ ] **Steg 8: Commit**

```bash
cd $WT && git add -A && git commit -m "Exempelspel: klickrace med daglig topplista, offline-kö och synliga fel"
```

---

### Task 10: README, CLAUDE.md och `.env.example`

**Filer:**
- Skapa: `README.md`, `CLAUDE.md`, `.env.example`
- Test: `test/docs.test.ts`

**Gränssnitt:**
- Konsumerar: `LEADERBOARD_TYPE` (Task 9), `npm run check` (Task 8).
- Producerar: idé-markörerna enligt "Gränssnitt mot 2c" ovan; spelets Claude-instruktioner.

- [ ] **Steg 1: Skriv de fallerande testerna**

`test/docs.test.ts`:

```ts
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const read = (p: string) => readFileSync(resolve(root, p), 'utf8');
const walk = (dir: string): string[] =>
  readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });

// Kontraktet med computercat-api (2c, steget repo). Ändras det här måste 2c ändras samtidigt.
const START = '<!-- computercat:idea:start -->';
const END = '<!-- computercat:idea:end -->';

describe('README och CLAUDE.md', () => {
  it('README har exakt en idé-markör av varje slag, i rätt ordning, på egna rader', () => {
    const lines = read('README.md').split('\n');
    const starts = lines.flatMap((l, i) => (l === START ? [i] : []));
    const ends = lines.flatMap((l, i) => (l === END ? [i] : []));
    expect(starts).toHaveLength(1);
    expect(ends).toHaveLength(1);
    expect(starts[0]).toBeLessThan(ends[0]);
    expect(read('README.md').split(START)).toHaveLength(2);
    expect(read('README.md').split(END)).toHaveLength(2);
  });

  it('CLAUDE.md innehåller specens regler och verktyg', () => {
    const md = read('CLAUDE.md');
    for (const s of [
      'Bygg aldrig en egen backend',
      'get_game',
      'upsert_',
      'get_integration_guide',
      'upsert_leaderboard_type',
      'daily-clicks',
      'main',
      'get_provisioning_status',
      'computercat-api',
      'npm run check',
    ]) {
      expect(md, s).toContain(s);
    }
  });

  it('sidan gör inga nätverksanrop vid bygget', () => {
    for (const f of walk(resolve(root, 'src/app'))) {
      const src = readFileSync(f, 'utf8');
      expect(src, f).not.toContain('fetch(');
      expect(src, f).not.toContain('next/font/google');
    }
  });
});
```

- [ ] **Steg 2: Kör och se dem falla**

```bash
cd $WT && npx vitest run test/docs.test.ts
```

Förväntat: FAIL, "ENOENT: no such file or directory, open '…/README.md'" (två tester), det tredje passerar.

- [ ] **Steg 3: Skriv `README.md`**

~~~markdown
# Spelmall

Ett Computercat-spel byggt med Next.js (statisk export), React, Tailwind, Zustand och framer-motion.

## Idé

<!-- computercat:idea:start -->
_Idén fylls i av create_game när spelet skapas._
<!-- computercat:idea:end -->

## Kom igång

```bash
npm ci
cp .env.example .env.local   # sätt NEXT_PUBLIC_COMPUTERCAT_GAME till spelets slug
npm run dev                   # http://localhost:3000
```

Utan `NEXT_PUBLIC_COMPUTERCAT_GAME` går spelet att spela, men en gul banner säger att inget skickas till Computercat.

## Test och kontroll

```bash
npm test        # enhetstester (vitest), inget nätverk
npm run check   # typkontroll, lint, test, bygge och kontroll av out/
```

`npm run check` ska vara grön innan något pushas.

## Backend

Backend är Computercat (`https://api.computercat.co/api/v1`). Spelet har ingen egen server, databas eller API-route. Allt som pratar med Computercat ligger i `src/lib/computercat/`:

- anonym spelare skapas automatiskt; token sparas i `localStorage`; vid 401 skapas en ny anonym spelare,
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

## Miljövariabler

| Variabel | Krävs | Standard |
|---|---|---|
| `NEXT_PUBLIC_COMPUTERCAT_GAME` | ja | — (sätts som build-variabel på Coolify) |
| `NEXT_PUBLIC_COMPUTERCAT_URL` | nej | `https://api.computercat.co/api/v1` |

Värdena bakas in i den publika koden vid bygget. Lägg aldrig hemligheter i `NEXT_PUBLIC_*`.

## Deploy

Push till `main` bygger och deployar Demo automatiskt (Coolify, nixpacks, statiska filer från `out/` via nginx, Node 22 enligt `engines`). Demo-adressen är `https://<slug>.apps.humblebrag.se` bakom basic auth. Status följs med Computercat-verktyget `get_provisioning_status`.

## För den som underhåller mallen

Raderna `<!-- computercat:idea:start -->` och `<!-- computercat:idea:end -->` är ett kontrakt med `create_game` i computercat-api, som skriver spelets idé mellan dem. De ska finnas exakt en gång var, på egna rader, start före slut. `test/docs.test.ts` vaktar det.
~~~

- [ ] **Steg 4: Skriv `CLAUDE.md`**

~~~markdown
# CLAUDE.md

Det här är ett Computercat-spel skapat från mallen `HumblebragAB/spelmall`. Spelets idé står under "Idé" i `README.md`; läs den först. Klickracet i `src/game/` och `src/components/` är ett exempel som ska ersättas av spelet.

## Regler

- **Backend är Computercat. Bygg aldrig en egen backend, databas eller API-route.** All kommunikation går genom `src/lib/computercat/`.
- **Konfigurera topplistor, achievements, pooler m.m. med Computercat-connectorns verktyg** (`get_game`, `upsert_*`). **Hämta integrationsmanualen med `get_integration_guide`** innan du använder en byggsten du inte använt förut.
- **Pusha till `main` när en ändring är klar och testad; det deployar Demo automatiskt.** Följ upp med `get_provisioning_status` och kontrollera Demo-adressen.
- **Behövs serverlogik som byggstenarna inte har: beskriv behovet och föreslå en PR mot computercat-api, bygg inte runt det.**

## Köra och testa lokalt

```bash
npm ci
cp .env.example .env.local    # NEXT_PUBLIC_COMPUTERCAT_GAME=<spelets slug>
npm run dev                    # http://localhost:3000 (localhost är tillåtet i Computercats CORS)
npm test                       # enhetstester, inget nätverk
npm run check                  # typkontroll, lint, test, bygge, kontroll av out/
```

Kör `npm run check` innan varje push till `main`. Bygget är en statisk export (`output: 'export'`): inga API-routes, inga serverkomponenter som hämtar data, ingen `next/font/google` (bygget ska klara sig utan nätverk).

## Computercat i koden

- `getComputercat()` ger klienten i webbläsaren, eller ett fel om `NEXT_PUBLIC_COMPUTERCAT_GAME` saknas.
- Resultat: `recordScore(typ, score, metadata?)` köar lokalt och skickar i bakgrunden. Visa `flushed`-resultatet för spelaren; dölj aldrig fel och visa aldrig påhittad topplistedata.
- Topplistor: `leaderboards.get(typ)` (aktuell period enligt servern, UTC), `leaderboards.me(typ)`.
- Sparningar: `saves.sync(nyckel, lokalt, merge)` hämtar, slår ihop och skickar med version.
- Anti-cheat: `createProof(...)` i `metadata.proof` om spelet kräver bevis.

## Exemplets topplista

Exemplet skickar till `daily-clicks`. Skapa den innan du testar på Demo:

`upsert_leaderboard_type` med `{"game": "<slug>", "slug": "daily-clicks", "name": "Dagens klick", "sort_direction": "desc", "score_label": "Klick", "period": "daily"}`

När exemplet ersätts: skapa spelets egna topplistor på samma sätt och byt `LEADERBOARD_TYPE` i `src/game/constants.ts`.

## Säkerhet

Allt i `NEXT_PUBLIC_*` hamnar i den publika koden. Lägg aldrig nycklar eller lösenord där eller i repot.
~~~

- [ ] **Steg 5: Skriv `.env.example`**

```dotenv
# Spelets slug i Computercat. Sätts automatiskt som build-variabel på Coolify (Demo).
NEXT_PUBLIC_COMPUTERCAT_GAME=
# Valfri. Standard: https://api.computercat.co/api/v1
# NEXT_PUBLIC_COMPUTERCAT_URL=
```

- [ ] **Steg 6: Kör hela kontrollen**

```bash
cd $WT && npm run check && git status --short
```

Förväntat: `Tests 65 passed (65)`, `check-export: OK …`; `git status` visar `README.md`, `CLAUDE.md`, `.env.example`, `test/docs.test.ts` (inte `.env.local`).

- [ ] **Steg 7: Commit**

```bash
cd $WT && git add -A && git commit -m "README med idé-markörer för create_game, CLAUDE.md för spelets Claude"
```

---

### Task 11: Slutverifiering lokalt (utan nätverk mot Computercat)

**Filer:** inga nya.

**Gränssnitt:**
- Konsumerar: allt ovan.
- Producerar: bevis att bygget inte behöver nätverk, att slugen bakas in och att spelet fungerar offline i en riktig webbläsare.

- [ ] **Steg 1: Bygge utan nätverk**

```bash
cd $WT && rm -rf out .next
sandbox-exec -p '(version 1)(allow default)(deny network-outbound (remote ip))' \
  env NEXT_TELEMETRY_DISABLED=1 NEXT_PUBLIC_COMPUTERCAT_GAME=spelmall-verify-7f3k NEXT_PUBLIC_COMPUTERCAT_URL=http://127.0.0.1:9/api/v1 \
  npm run build
NEXT_PUBLIC_COMPUTERCAT_GAME=spelmall-verify-7f3k node scripts/check-export.mjs
grep -rl "127.0.0.1:9/api/v1" out/_next | head -1
```

Förväntat: bygget lyckas med `○ /`; `check-export: OK (out/index.html, slug "spelmall-verify-7f3k" inbakad)`; `grep` hittar en JS-fil. Faller bygget med ett nätverksfel i sandlådan: bygget hämtar något utifrån; hitta och ta bort det (det bryter mot Globala villkor). Vägrar `sandbox-exec` själv att starta (inte Next): notera det och lita på att steg 2 och testet "sidan gör inga nätverksanrop vid bygget" täcker samma sak.

- [ ] **Steg 2: Spela offline i webbläsaren**

```bash
cd $WT && python3 -m http.server 4173 -d out
```

Öppna `http://localhost:4173` (Chrome-tillägget eller manuellt) och kontrollera:
1. Ingen gul banner (slugen är satt).
2. Topplistan visar "Offline: kunde inte nå Computercat. Det du spelar sparas lokalt." (porten 9 är stängd; ingen påhittad lista).
3. Tryck Start, klicka några gånger, vänta tio sekunder: "Du fick N klick." och statusraden "Offline: 1 resultat i kö, skickas när nätet är tillbaka."
4. I konsolen: `JSON.parse(localStorage.getItem('computercat:spelmall-verify-7f3k:score-queue')).length` ger `1`.
5. Ladda om sidan: "Bästa: N" står kvar och kön har fortfarande 1 post.

Stoppa servern med Ctrl-C.

- [ ] **Steg 3: Banner utan slug**

```bash
cd $WT && rm -rf out .next && NEXT_TELEMETRY_DISABLED=1 npm run build && grep -c "NEXT_PUBLIC_COMPUTERCAT_GAME saknas" out/index.html
```

Förväntat: `1` (bannern finns i den statiska HTML:en).

- [ ] **Steg 4: Hela kontrollen en sista gång och läs diffen**

```bash
cd $WT && npm run check && git log --oneline main..HEAD && git status --short
```

Förväntat: grönt, 10 commits sedan `main`, ren working tree. Läs hela `git diff main..HEAD` en gång med en angripares ögon (token i loggar, `NEXT_PUBLIC_*` med hemligheter, `dangerouslySetInnerHTML`, `DELETE`-anrop) och kör `completion-gate`.

- [ ] **Steg 5: Avsluta worktreen**

```bash
~/.claude/bin/wt-finish /Users/erik/Web/spelmall/.claude/worktrees/feat-spelmall
git -C /Users/erik/Web/spelmall log --oneline | head -3
```

Förväntat: `main` i `~/Web/spelmall` innehåller commitsen; worktree och branch är borttagna. Ingenting är pushat.

---

### Task 12: GitHub-repo, push och mallmarkering — KRÄVER ERIKS OK

Detta är den enda åtgärden i planen som syns utåt. **Fråga Erik med AskUserQuestion innan steg 1** och beskriv exakt: skapar privat repo `HumblebragAB/spelmall`, pushar `main`, sätter `is_template: true`. Gå vidare bara på ett uttryckligt ja.

**Filer:** inga.

**Gränssnitt:**
- Konsumerar: `main` i `~/Web/spelmall` (Task 11).
- Producerar: `HumblebragAB/spelmall` (privat, `is_template: true`, default branch `main`), som 2c anropar med `POST /repos/HumblebragAB/spelmall/generate`.

- [ ] **Steg 1: Kontrollera att namnet är ledigt**

```bash
gh api repos/HumblebragAB/spelmall --jq .full_name 2>&1 | head -1
```

Förväntat: `gh: Not Found (HTTP 404)`. Finns repot redan: stanna och fråga Erik.

- [ ] **Steg 2: Skapa och pusha**

```bash
gh repo create HumblebragAB/spelmall --private \
  --description "Mall för Computercat-spel: Next.js statisk export, Computercat-klient, CLAUDE.md" \
  --source /Users/erik/Web/spelmall --remote origin --push
```

Förväntat: `✓ Created repository HumblebragAB/spelmall on GitHub` och `✓ Pushed commits to …`.

- [ ] **Steg 3: Markera som mall**

```bash
gh api -X PATCH repos/HumblebragAB/spelmall -F is_template=true --jq '{private, is_template, default_branch}'
```

Förväntat: `{"default_branch":"main","is_template":true,"private":true}`. (`-F` skickar ett riktigt booleskt värde; `-f` skulle skicka strängen `"true"`.)

- [ ] **Steg 4: Kontrollera att lokalt och GitHub är samma**

```bash
git -C /Users/erik/Web/spelmall fetch origin && git -C /Users/erik/Web/spelmall status -sb | head -1
```

Förväntat: `## main...origin/main` utan `ahead`/`behind`.

Kvar för Erik efter denna task (förutsättning 3 i specen, görs i GitHubs UI): lägga till `spelmall` i GitHub App-installationen "Humblebrag Spelstudio".

---

## Till spiken före 2c

Inarbetat 2026-10-13: specens "Ej verifierat" punkt 8 och 2c:s spikpunkt S20 kontrollerar i byggloggen att Coolifys nixpacks bygger mallen med Node 22 enligt `engines.node: "22.x"` (Next.js 16 kräver ≥ 20.9; nixpacks standard är 18). Faller det är reservvägen build-variabeln `NIXPACKS_NODE_VERSION=22` per app (nixpacks.com/docs/providers/node), som 2c i så fall sätter i steget `coolify_env` efter Eriks beslut. Mallen ändras inte för det.
