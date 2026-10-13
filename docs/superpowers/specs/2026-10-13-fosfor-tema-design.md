# Fosfor-tema: ny design för Computer Cat API

Datum: 2026-10-13
Branch: `design/fosfor-tema`

## Mål

Ersätt den daterade neon-cyberpunk-looken (cyan/magenta, scanlines, partiklar, glow, Press Start 2P) med en
lugnare retro: en amber-terminal i stil med en VT220. Personligheten ska vara kvar. Katten på login-sidan och alla
easter eggs behåller sitt beteende, men får ny färg och form.

**Klart när:** alla ytor nedan har den nya stilen, inget beteende har försvunnit, all text och alla knappar klarar
WCAG AA (4.5:1) och ingen yta laddar resurser från tredje part (inte heller Filaments standardfont via Bunny).

## Beslut (fattade med Erik)

| Fråga | Beslut |
|---|---|
| Ytor | Admin-panelen, login, MCP-godkännandet, intresse-sidorna och intressemailet |
| Riktning | A, "Fosfor": amber-terminal på nästan svart, monospace |
| Brödtext i admin | Varmvit. Amber används som accent (rubriker, länkar, aktivt menyval, knappar) |
| Easter eggs | Alla fem finns kvar och får ny stil |
| Spelarytor | En ljusare variant. Mailet förblir ren text |
| Teknik | Statisk CSS i `public/` plus Filament-API:t. Ingen Vite-build i prod |

## Designtokens

Alla tokens finns på ett enda ställe: `public/css/cc-theme.css`. Där ligger `@font-face` för IBM Plex Mono och
`:root`-tokens. Admin och login laddar filen via `->font()`. MCP-godkännandet och intresse-sidorna länkar den
direkt. De tidigare `:root`-blocken i `cc-admin.css` och login-vyn tas bort.

Mörkt (admin, login, MCP-godkännandet):

| Token | Värde | Användning | Kontrast |
|---|---|---|---|
| `--cc-bg` | `#0d0b08` | sidbakgrund | – |
| `--cc-surface` | `#15110a` | kort och sidomeny | – |
| `--cc-line` | `#2e2412` | ramar, ingen text | – |
| `--cc-text` | `#efe6d2` | brödtext | 15.8 mot bg, 15.2 mot surface |
| `--cc-amber` | `#f2b544` | accent och amber-text | 10.7 mot bg |
| `--cc-muted` | `#a0824a` | sekundärtext | 5.4 mot bg, 5.2 mot surface |
| `--cc-ok` | `#9fd36b` | status ok | 11.2 |
| `--cc-warn` | `#f08a24` | varning, skiljer sig från amber | 7.8 |
| `--cc-danger` | `#e8664a` | fel | 6.0 |

Färgade knappar har **mörk text** (`--cc-bg`) på färgen: 10.7 på amber, 12.5 på amber-hover
`#f6c76e`, 6.0 på danger, 11.2 på ok och 7.8 på warn. Filament sätter `text-white` på dessa, vilket ger 1.5–3.3:1,
så det skrivs över (se Admin).

Ljust (intresse-sidorna): bakgrund `#fbf7ee`, text `#2a1f0c` (15.1:1), accent och länkar `#8a5a00` (5.5:1),
dämpad text `#6b6150` (5.7:1) och knappar med `#fbf7ee`-text på `#8a5a00` (5.5:1).

**Typsnitt:** IBM Plex Mono 400/500/600 (OFL), woff2, subset latin, som täcker å, ä och ö. Filerna hämtas från
npm-paketet `@fontsource/ibm-plex-mono` med en låst version och checkas in i `public/fonts/plex-mono/` tillsammans
med OFL-licensfilen. `font-display: swap`. Family-namnet är exakt `'IBM Plex Mono'`.

**Cache-bust:** `App\Support\ThemeAsset::url(string $path)` returnerar `asset($path)` med `?v=<filemtime>`. Saknas filen
returneras `asset($path)` utan version, utan varning. Det krävs eftersom `StaffAccessTest::withoutBuiltAssets()`
byter `public_path` till en tom katalog.

## Ytor

### 1. Admin-panelen (`app/Providers/Filament/AdminPanelProvider.php`, `public/css/cc-admin.css`)

- `->colors()`:
  - `primary`: `Color::hex('#f2b544')`
  - `gray`: handskriven skala 50–950 med varmsvarta toner. 950 ≈ `--cc-bg`, 900 ≈ `--cc-surface`. De nivåer
    Filament använder som text i mörkt läge (gray-400/500 på gray-900/950) ska klara 4.5:1, vilket verifieras med
    ett kontrastskript i implementationen.
  - `danger`: `Color::hex('#e8664a')`
  - `success`: `Color::hex('#9fd36b')`
  - `warning`: `Color::hex('#f08a24')`
- `->font('IBM Plex Mono', ThemeAsset::url('css/cc-theme.css'), LocalFontProvider::class)`. Det tar också bort Filaments
  Inter-anrop till `fonts.bunny.net`.
- `->darkMode(true, true)` står kvar, så mörkt läge är fortfarande påtvingat.
- Render-hooken för `cc-admin.css` använder `ThemeAsset::url()`.
- `cc-admin.css` skrivs om från grunden. Målet är högst cirka 150 rader. Innehåll:
  - Brödtext: `.fi-body` och de vita textklasserna i mörkt läge sätts till `--cc-text`.
  - Knapptext: färgade `.fi-btn` (utom toggle-knappar) får `color: var(--cc-bg)`. Badges behöver inget, eftersom de har custom-400-text på en 10-procentig tint (8.3–12.8:1). Hover på primary blir `#f6c76e`.
  - Karaktär: `> `-markör och ljusare yta på aktivt menyval, radie 0–2px, versala tabellrubriker med spärrning,
    streckade radlinjer och varumärket i sidomenyn i versaler med spärrning, i amber.
  - Bort: scanlines, glow, Press Start 2P, `@import` och `:root`-tokens.
  - Exakta selektorer läses ut ur Filaments förkompilerade CSS och blade-komponenter i implementationen. Bara
    klasser som finns i `public/css/filament/filament/app.css` får användas i egna vyer.
- `game-dev-kit.blade.php`: `var(--cc-primary, #00f0ff)`, `var(--cc-tertiary, #39ff14)` och den hårdkodade
  `'JetBrains Mono'` byts mot de nya tokens (`--cc-amber`, `--cc-ok`) och `inherit`. Inga neon-fallbacks får vara
  kvar. `game-voices` och `ai-usage-overview` behöver inget.

### 2. Login (`resources/views/filament/pages/auth/login.blade.php`)

- Blade-filen behåller bara markup: kortet, kattbanorna, formuläret och footern `> ready_` (texten är oförändrad).
- CSS:en flyttas till `public/css/cc-login.css` och JS:en till `public/js/cc-login.js`. Båda länkas **i vyn** (JS
  med `defer`) via `ThemeAsset::url()`, inte i en global hook, så att de bara laddas på login. **Logiken flyttas
  oförändrad.** Den har ingen Blade eller PHP i script/style och inga Livewire-hooks. Det gäller alla beteenden:
  gå, sitta, sova, jaga flugan, putsa, jaga svansen, kasta sig, kika, falla, beteenden på nedre banan, klättra
  tillbaka, pratbubblor, drag med mus och touch, fysik och golvläge.
- **Livewire-morph:** vid misslyckad inloggning morphar Livewire om komponenten. Det kan återställa kattens klasser
  och position, eller ge två katter om JS:en har flyttat katten till `body` eller nedre banan. Därför får
  kattbanorna (övre och nedre) `wire:ignore`, och bara formulärdelen lämnas åt Livewire.
- Partiklarna (som använder `rand()` vid varje rendering), scanlines och kortets glödande gradientkant tas bort.
  Kortet får en 1px-ram i `--cc-line` på `--cc-surface`.
- Katten och flugan ritas om i amber, pixligt och utan glow. Typsnittsstorlekar som var anpassade för Press Start 2P
  (0.35–0.65rem, rad 310, 339, 376 och 412) skalas om, så att Plex Mono hamnar på minst 11px.

### 3. Easter eggs (`public/js/cc-eggs.js`)

Alla fem behålls med samma triggers: Konami, "meow", "nyan", att klicka på varumärket 7 gånger, och idle-katten
efter 60 sekunder. Skriptet laddas fortfarande på alla panelsidor, login inräknat. Render-hooken använder
`ThemeAsset::url()`. Ändringar:
- **Matrix-regnet:** emoji-tecknen byts mot ASCII- och katt-tecken (till exempel `=^.^=`, `/\_/\`, `meow`,
  `01`), som kan färgas amber med `fillStyle`. Fade-rektangeln får `--cc-bg` i stället för `rgba(10,10,26,.05)`.
- **Nyan-spåret:** en gradient i amber-toner i stället för regnbåge, utan `box-shadow`-glow.
- **Hacker mode:** glitch-overlayen blir amber och mörk i stället för cyan och magenta. "ACCESS GRANTED" sätts i
  Plex Mono utan neon-`text-shadow`.
- **Pixelkatten och idle-katten:** amberpalett. Talbubblorna ("Meow!" i summon-katten och idle-bubblan) skalas om
  från `0.4rem` till minst 11px.

### 4. MCP-godkännandet (`resources/views/vendor/mcp/authorize.blade.php`)

- Inline-CSS:en byts mot de mörka tokens. `cc-theme.css` länkas via `ThemeAsset::url()`. Sidan har fortfarande inget
  `@vite` och inga externa resurser.
- Primärknappen "Godkänn" blir amber med mörk text, och "Neka" får en ram i `--cc-line` med `--cc-text`.
- **Får inte ändras:** formulärens fält, metoder (POST för godkänn, DELETE via `@method` för neka), `@csrf`, att
  alla värden escapas med `{{ }}`, att `logo_uri` och `client_uri` inte visas och att redirect-hosten visas.
- Ljust/mörkt via `prefers-color-scheme` tas bort. Sidan är alltid mörk, som admin.

### 5. Intresse-sidorna (`resources/views/interest/result.blade.php`, `unsubscribe.blade.php`)

- Ljus variant med tokens enligt ovan: Plex Mono i rubriker och systemets sans-serif i brödtext, för att den ska
  vara lätt att läsa för spelare. `cc-theme.css` länkas för `@font-face`. De mörka `:root`-tokens i filen används
  inte här, utan sidan sätter sina egna ljusa värden lokalt.
- Logiken lämnas orörd: länken tillbaka till spelet, `$rubrik`/`$text` och unsubscribe-formuläret. Formuläret
  saknar `@csrf` med avsikt (API-route utan session, CSRF-undantag), och det ska inte läggas till. Texterna som
  `InterestSignupTest` kontrollerar ändras inte.

### 6. Intressemailet (`resources/views/mail/interest-confirmation.blade.php`)

Mailet förblir ren text. Copyn granskas med `naturlig-svenska` och ändras bara där något skaver. Variablerna
`$sprak`, `$spel`, `$bekrafta` och `$avregistrera` ligger kvar.

### 7. Städning

- `resources/views/welcome.blade.php` tas bort. Den är verifierat oanvänd: ingen träff i routes, app, tests,
  config eller bootstrap, och `/` skickas vidare till `/admin/login`.

## Utanför scope

- Ingen ändring av deployen och ingen Vite-build i prod.
- Inga nya features, ingen ny navigation och inga nya widgets.
- Spelens egna webbar och spelklienter berörs inte.

## Risker

- **Filament-uppdateringar** kan byta CSS-klasser, och då slutar overrides i `cc-admin.css` gälla. Färg och
  typsnitt går via `->colors()` och `->font()`. Bara textfärg, knapptext och karaktärsdetaljer är overrides, och
  de samlas med kommentarer i `cc-admin.css`.
- **Livewire-morph på login:** hanteras med `wire:ignore` och testas med en misslyckad inloggning i webbläsaren.
- **Cache:** hanteras med `ThemeAsset::url()` och `filemtime`, som tål att filen saknas.

## Test och verifiering

Testerna är PHPUnit-klasser med `RefreshDatabase`, enligt repots mönster.

- `ThemeAsset::url()`: ger version när filen finns och saknar version (ingen varning) när den saknas.
- `/admin/login` svarar 200, innehåller formuläret, länkar `cc-login.css`, `cc-login.js` och `cc-theme.css`, och
  `wire:ignore` sitter på kattbanan.
- En annan adminsida, för inloggad staff, länkar **inte** `cc-login.js`.
- Authorize: befintliga `StaffAccessTest` och `OAuthEndToEndTest` ska vara gröna. Ett nytt test kontrollerar att
  sidan länkar `cc-theme.css` och inte innehåller `prefers-color-scheme`.
- Intresse-sidorna: befintliga `InterestSignupTest` ska vara grön.
- Neon-svep: ingen av de berörda vyerna eller filerna i `public/css` och `public/js` innehåller
  `fonts.googleapis.com`, `fonts.bunny.net`, `Press Start`, `JetBrains`, `#00f0ff`, `#ff00aa` eller `#39ff14`.
- Hela sviten är grön.
- I webbläsaren på `computercat-api-design-fosfor-tema.test`:
  - Login: katten går, faller, kan dras och klättrar tillbaka. En misslyckad inloggning ger ett felmeddelande, och
    katten finns kvar som en enda katt.
  - Admin: dashboard, en lista, ett formulär med primär- och danger-knapp och dev-kit.
  - Alla fem easter eggs.
  - Authorize-sidan och intresse-sidorna.
  - Skärmdumpar visas för Erik.
- Kontrasten kontrolleras med skript för tokens och gray-skalan.
