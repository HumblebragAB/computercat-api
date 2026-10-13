# System 1-tema: tidig Mac i 1-bit för Computer Cat API

Datum: 2026-10-13
Branch: `design/system1-tema`
Ersätter utseendet från `2026-10-13-fosfor-tema-design.md`. Infrastrukturen därifrån behålls (se "Det som behålls").

## Bakgrund

Erik ville ha riktning B, "System 1", men svaret registrerades som A ("Fosfor"), och Fosfor gick live. Den här specen
byter utseendet till System 1 i full storlek, enligt mockupen som Erik godkände 2026-10-13
(`system1-full.html`: login, admin med lista, MCP-godkännandet och spelarsidan).

**Klart när:** alla ytor från Fosfor-specen har System 1-utseendet, inget beteende har försvunnit, all text klarar
WCAG AA (4.5:1) och inget laddas från tredje part.

## Beslut (fattade med Erik)

| Fråga | Beslut |
|---|---|
| Riktning | B, "System 1": Macintosh 1984 i 1-bit, svart bläck på papper |
| Ljust/mörkt | Bara ljust. Admin tvingas till ljust läge (`->darkMode(false)`) |
| Ytor | Samma som Fosfor: admin, login, easter eggs, MCP-godkännandet, intresse-sidorna. Mailet är orört |
| Teknik | Samma som Fosfor: statisk CSS i `public/`, Filament-API:t, ingen Vite i prod |

## Det som behålls från Fosfor

`ThemeAsset::url()`, att alla tokens ligger i `public/css/cc-theme.css`, `->font()` med `LocalFontProvider`,
IBM Plex Mono som självhostad brödtext, login-JS:en oförändrad i `cc-login.js` med `wire:ignore` och head-hook
scopad till `Login::class`, `InitialsAvatarProvider` (bara färgerna byts), loggans fördröjda navigering för hacker
mode, svep-testerna (neon, tredjepartsresurser, färgklasser som saknas i `app.css`) och den riktiga
Röster-etiketten.

## Designtokens

Tokens döps om till semantiska namn, så att nästa tema inte behöver byta namn igen. Fosfors `--cc-amber`,
`--cc-amber-hover`, `--cc-ok`, `--cc-warn`, `--cc-danger` och `--cc-surface` tas bort.

| Token | Värde | Användning | Kontrast |
|---|---|---|---|
| `--cc-ink` | `#111111` | text, ramar, skuggor, inverterade ytor | 18.1 mot paper, 15.2 mot desk |
| `--cc-paper` | `#fbfaf6` | fönster och kort | – |
| `--cc-desk` | `#e9e6df` | skrivbordet (sidbakgrund) | – |
| `--cc-dot` | `#b9b4a8` | skrivbordets prickmönster, dekor | ingen text |
| `--cc-muted` | `#55524b` | sekundärtext | 7.5 mot paper, 6.3 mot desk, 7.8 mot vitt |
| `--cc-line` | `#111111` | ramar (alias för ink) | – |
| `--cc-font` | `'IBM Plex Mono', ui-monospace, Menlo, monospace` | brödtext | – |
| `--cc-font-display` | `'Silkscreen', 'IBM Plex Mono', monospace` | fönstertitlar, tabellrubriker, varumärket | – |

**Silkscreen** (OFL, pixeltypsnitt) läggs till självhostat från `@fontsource/silkscreen@5.2.5`, subset latin
(U+0000–00FF, alltså med å, ä och ö), vikt 400, med OFL-licensen. Det används bara i rubriker, och aldrig under 11px.

**Inverterat** (aktivt menyval, markerad rad, "Live"-badge, danger-knapp): `--cc-paper` på `--cc-ink`, 18.1:1.

## Ytor

### 1. Admin

- `->darkMode(false)`. Ljust läge är det enda.
- `->colors()`:
  - `primary`, `danger`, `success`, `warning` och `info`: `Color::hex('#111111')`. Det är 1-bit, så betydelse visas
    med form (fylld, ram, ikon) och inte med färg.
  - `gray`: neutral varm skala 50–950. 50 = paper, 200 ≈ desk, 950 = ink. Kravet är att gray-500 och gray-600 klarar
    4.5:1 mot vitt och mot gray-50. Det verifieras med test.
- `cc-admin.css` skrivs om:
  - **Skrivbord:** `.fi-body` får desk med prickmönster (`radial-gradient` 1px, 4px raster).
  - **Fönster:** `.fi-section`, `.fi-ta-ctn`, `.fi-wi-stats-overview-stat`, `.fi-modal-window` och
    `.fi-dropdown-panel` får paper, 2px ram i ink, 4px hård skugga i ink och radie 0.
  - **Titellist:** sektionernas och tabellernas rubrikrad (`.fi-section-header`, `.fi-ta-header`) och modalens
    rubrik får randig bakgrund (1px ink var 3:e px). Rubriktexten får en paper-bakgrund med padding, i Silkscreen.
    Exakta selektorer läses ur Filaments vyer i implementationen.
  - **Menyrad:** topbaren får paper med 2px underkant. Varumärket i sidomenyn sätts i Silkscreen.
  - **Sidomeny:** paper, 2px högerkant. Aktivt menyval är inverterat. Grupprubriker i Silkscreen och muted.
  - **Knappar:**
    - Primär: paper med 2px ram, radie 8px och dubbelram (`box-shadow: 0 0 0 2px paper, 0 0 0 4px ink`), som Macens
      standardknapp.
    - Danger: inverterad (paper-text på ink).
    - Gray och outlined: paper med 2px ram.
    - Ingen knapp har vit text på en ljus yta.
  - **Badges:** färgade badges är inverterade, gray-badges har bara ram.
  - **Tabeller:** rubriker i Silkscreen, 1px ink-linjer, markerad rad inverterad, hover i desk.
  - **Fält:** vitt fält med 2px ink-ram, radie 0. Fokus: 2px ink-ram plus dubbelram som på knappen.
  - **Felmeddelanden** (`.fi-fo-field-wrp-error-message`): ink-text med `⚠` framför (`content: '⚠ ' / ''`), eftersom
    det inte finns något rött.
  - **Checkbox och toggle:** ikryssad box är ink med paper-bock, och den delvis markerade är ink med paper-streck.
    Filaments vita bock på ink ger redan 18:1. Fosfors amber-overrides tas bort.
- `InitialsAvatarProvider`: paper-initialer på ink, i Plex Mono.
- `game-dev-kit.blade.php`: tokens byts till de nya namnen (`--cc-ink`, `--cc-paper`, `--cc-muted`, `--cc-line`).

### 2. Login

- Kortet blir ett fönster: titellist "Computer Cat" med stängruta, paper-yta, 2px ram och 4px skugga, på skrivbordet.
- Katten blir svart på papper: `--cc-cat-fur` = ink, ögon = paper, glint = ink, nos = paper, morrhår = muted.
  Flugan blir ink. JS:en är oförändrad.
- Rubriken "COMPUTERCAT // admin_panel" ersätts av titellisten och en rad muted välkomsttext. Footern `> ready_`
  tas bort, och markören `.cc-blink-cursor` flyttas till slutet av välkomsttexten.
  *Avvikelse från mockupen:* `id`-elementen som JS:en använder (`login-card`, `cat-track`, `cat-track-bottom`,
  `the-cat`, `the-fly`, `cat-zzz`) står kvar exakt.
- Logga in-knappen är en standardknapp med dubbelram, högerställd.
- Pratbubblor och Zzz: paper med 2px ink-ram och Plex Mono, minst 11px.

### 3. Easter eggs (`cc-eggs.js`)

Triggers och flöde är oförändrade. Bara paletten byts (`CC`-objektet får ink, paper, desk och muted).
- **Matrix:** ink-glyfer på paper. Fade-rektangeln blir `rgba(251,250,246,0.12)`.
- **Pixelkatten (meow) och idle-katten:** svart katt (ink) med paper-ögon, samma som på login.
- **Nyan:** spåret blir 1-bit-rutor i ink och muted, med varannan ruta ihålig (bara ram).
- **Hacker mode:** glitchen inverterar sidan (`filter: invert(1)` blinkar). "ACCESS_GRANTED" visas som en
  Mac-varningsdialog: paper-fönster med ink-ram, skugga och Plex Mono.
- **Talbubblor:** paper, 2px ink-ram, 11px.

### 4. MCP-godkännandet

Sidan blir en Mac-dialog: fönster med titellist "Godkänn <klientnamn>" och stängruta, på skrivbordet. Avbryt står
till vänster och Godkänn är standardknapp med dubbelram till höger. Inline-CSS:en har fallbacks i `var()` så att
sidan renderar även utan `cc-theme.css`. **Får inte ändras** (samma som Fosfor): formulären, metoderna, `@csrf`,
`{{ }}`, att `logo_uri`/`client_uri` döljs och att redirect-hosten visas. Klientnamnet i titeln escapas med `{{ }}`.
Ändringar i markup är bara tillåtna i omslaget: titellistens element och knappordningen via CSS (`order`/flex) och
inte genom att flytta formulären.

### 5. Intresse-sidorna

Paper-bakgrund, ink-text, rubriken i Silkscreen (minst 22px), brödtext i systemets sans-serif och länkar i ink med
understrykning. Knappen ("Ta bort min adress") är en Mac-knapp: paper, 2px ink-ram, radie 8px och dubbelram. Logik
och texter är orörda, och inget `@csrf` läggs till.

### 6. Mail

Orört.

## Utanför scope

Mörkt läge, nya features och ändrad deploy.

## Risker

- **Filament i ljust läge** har andra klasser och ytor än i mörkt (`bg-white`, `ring-gray-950/5`, `shadow-sm`).
  Fönsterstilen kan krocka med Filaments egna ringar, och då blir det dubbla ramar. Ringar på fönsterytor nollas
  därför uttryckligen.
- **Silkscreen** är smalt i små storlekar och används därför bara i rubriker från 11px.
- **1-bit tar bort färg som betydelse.** Därför: ⚠ på fel, inverterad danger-knapp och inverterade färgade badges.

## Test och verifiering

- Befintliga tester uppdateras till nya tokens och färger:
  - ThemeFilesTest: tokens plus Silkscreen-filen.
  - ThemeTest: gray-kontrast för ljust läge, panelens primary och info är ink, `darkMode` är av.
  - ThemeSweepTest: Fosfors amber-värden `#f2b544`, `#0d0b08` och `#15110a` läggs till som förbjudna i berörda
    filer, och intresse-sidornas färger kontrolleras.
  - De befintliga testerna för login, authorize och intresse ska vara gröna.
- Nya tester: felmeddelandet har en `⚠`-regel och panelen har `darkMode` av.
- Hela sviten ska vara grön.
- Headless-webbläsare (Playwright) på worktreens Herd-domän, med samma kontroller som för Fosfor plus ett automatiskt
  kontrastsvep över alla synliga textnoder. Skärmdumpar visas för Erik.
