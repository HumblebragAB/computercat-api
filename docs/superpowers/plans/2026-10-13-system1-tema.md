# System 1-tema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Byt Fosfor-utseendet mot System 1 (Mac 1984, 1-bit, bara ljust) på samma ytor, utan att något beteende försvinner.

**Architecture:** Samma infrastruktur som Fosfor: `cc-theme.css` (tokens och `@font-face`), Filaments `->colors()`/`->font()`/`->darkMode(false)`, små statiska CSS/JS-filer i `public/`, `ThemeAsset::url()`.

**Tech Stack:** Laravel 12, Filament v3.3.49 (förkompilerad CSS), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-13-system1-tema-design.md` (och Fosfor-specen för det som behålls).

**Arbetskatalog:** `/Users/erik/Web/computercat-api/.claude/worktrees/design-system1-tema`. Testkommando: `php artisan test --compact`.

## Förberedelse

`composer install`, `php artisan passport:keys` (bara i worktreen, gitignorerat, Erik har godkänt för förra worktreen; samma lokala syfte), en egen tom `database/database.sqlite` + `php artisan migrate --force`, och en testanvändare (staff, admin) skapad med tinker. Spara id:t. Kör hela sviten som utgångsläge.

## Global Constraints

- Tokens: `--cc-ink #111111`, `--cc-paper #fbfaf6`, `--cc-desk #e9e6df`, `--cc-dot #b9b4a8`, `--cc-muted #55524b`, `--cc-line #111111`, `--cc-font`, `--cc-font-display`.
- Fosfor-tokens (`--cc-amber`, `--cc-amber-hover`, `--cc-ok`, `--cc-warn`, `--cc-danger`, `--cc-surface`, `--cc-text`, `--cc-bg`) får inte finnas kvar någonstans i `public/` eller `resources/`.
- Förbjudet i berörda filer: allt från Fosfors svep plus `#f2b544`, `#0d0b08`, `#15110a`, `#efe6d2`, `#a0824a`.
- Typsnitt: Plex Mono (finns) och Silkscreen 400 latin från `@fontsource/silkscreen@5.2.5`. Silkscreen aldrig under 11px.
- AA (4.5:1) för all text. Inget från tredje part. Bara klasser som finns i `public/css/filament/filament/app.css` i egna vyer.
- Login-JS och easter egg-triggers ändras inte. Authorize-formulären, CSRF och escaping är orörda. Unsubscribe får inget `@csrf`.

## Review Focus

1. **Filament i ljust läge:** ringar och skuggor (`ring-gray-950/5`, `shadow-sm`) på fönsterytor ger dubbla ramar. Kontrolleras visuellt i task 8.
2. **Primärknapp med dubbelram:** texten måste vara ink på paper i alla tillstånd (hover, disabled, laddning). Kontrollerat med test i task 2 (CSS-regel) och visuellt.
3. **Felmeddelanden utan färg:** `⚠` måste synas och skärmläsaren ska inte läsa upp den. Test i task 2.
4. **Login efter misslyckad inloggning:** en katt, den rör sig. Task 8.
5. **Silkscreen med å/ä/ö** i rubriker som "Översikt" och "Användare". Task 1 (cmap) och task 8.

---

### Task 1: Typsnitt och tokens

**Files:** Create `public/fonts/silkscreen/silkscreen-latin-400-normal.woff2`, `public/fonts/silkscreen/OFL.txt`. Rewrite `public/css/cc-theme.css`. Modify `tests/Unit/ThemeFilesTest.php`.

- [ ] **Step 1: Uppdatera testet (rött)**. I `ThemeFilesTest` lägg till Silkscreen i fonttestet och byt tokenlistan:

```php
    public function test_silkscreen_ar_woff2_med_licens(): void
    {
        $f = $this->publicFile('fonts/silkscreen/silkscreen-latin-400-normal.woff2');
        $this->assertFileExists($f);
        $this->assertSame('wOF2', substr(file_get_contents($f), 0, 4));
        $this->assertStringContainsString('SIL Open Font License', file_get_contents($this->publicFile('fonts/silkscreen/OFL.txt')));
    }
```

I `test_temat_definierar_font_face_och_alla_tokens`: `assertSame(4, substr_count($css, '@font-face'))`, `assertStringContainsString("font-family: 'Silkscreen'", $css)` och tokenlistan

```php
['--cc-ink: #111111', '--cc-paper: #fbfaf6', '--cc-desk: #e9e6df', '--cc-dot: #b9b4a8',
 '--cc-muted: #55524b', '--cc-line: #111111', "--cc-font: 'IBM Plex Mono'", "--cc-font-display: 'Silkscreen'"]
```

plus `foreach (['--cc-amber', '--cc-surface', '--cc-bg:', '--cc-text:', '--cc-danger', '--cc-ok', '--cc-warn'] as $gammal) $this->assertStringNotContainsString($gammal, $css);`

- [ ] **Step 2:** `php artisan test --compact --filter=ThemeFilesTest` → FAIL.
- [ ] **Step 3: Hämta Silkscreen**

```bash
mkdir -p public/fonts/silkscreen
curl -fsSL -o public/fonts/silkscreen/silkscreen-latin-400-normal.woff2 https://cdn.jsdelivr.net/npm/@fontsource/silkscreen@5.2.5/files/silkscreen-latin-400-normal.woff2
curl -fsSL -o public/fonts/silkscreen/OFL.txt https://cdn.jsdelivr.net/npm/@fontsource/silkscreen@5.2.5/LICENSE
head -c 4 public/fonts/silkscreen/silkscreen-latin-400-normal.woff2; echo
python3 -I -c "from fontTools.ttLib import TTFont; f=TTFont('public/fonts/silkscreen/silkscreen-latin-400-normal.woff2'); c=f.getBestCmap(); print(all(ord(x) in c for x in 'åäöÅÄÖ'))"
```

Expected: `wOF2` och `True` (finns inte fontTools: `pip3 install --user fonttools` är OK, eller hoppa över och verifiera visuellt i task 8, och ledgerför det).

- [ ] **Step 4: Skriv om `cc-theme.css`**. Behåll de tre Plex Mono-`@font-face`-blocken oförändrade, lägg till:

```css
@font-face {
    font-family: 'Silkscreen';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('../fonts/silkscreen/silkscreen-latin-400-normal.woff2') format('woff2');
}
```

ersätt kommentaren överst med `/* Computer Cat, System 1-tema (Mac 1984, 1-bit): typsnitt och tokens. Enda källan för färgerna. */` och `:root` med:

```css
:root {
    --cc-font: 'IBM Plex Mono', ui-monospace, Menlo, monospace;
    --cc-font-display: 'Silkscreen', 'IBM Plex Mono', monospace;
    --cc-ink: #111111;
    --cc-paper: #fbfaf6;
    --cc-desk: #e9e6df;
    --cc-dot: #b9b4a8;
    --cc-muted: #55524b;
    --cc-line: #111111;
}
```

- [ ] **Step 5:** testet grönt. **Commit** `System 1: Silkscreen och 1-bit-tokens`.

---

### Task 2: Admin

**Files:** Modify `app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/AvatarProviders/InitialsAvatarProvider.php`, `resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php`. Rewrite `public/css/cc-admin.css`. Modify `tests/Feature/Filament/ThemeTest.php`, `tests/Feature/ThemeSweepTest.php`.

- [ ] **Step 1: Tester (röda).** I `ThemeTest`:
  - `test_gray_skalan_klarar_aa_dar_filament_anvander_den_som_text`: byt till ljust läge, alltså `foreach ([500, 600] as $fg) foreach ([50] as $bg)` plus mot `'#ffffff'`.
  - `test_mork_knapptext_klarar_aa_pa_alla_fargade_knappar` → döp om till `test_inverterad_text_klarar_aa` med paret `contrast('#fbfaf6', '#111111')`.
  - `test_info_fargen_ar_temad_och_inte_filament_bla` → döp om till `test_alla_semantiska_farger_ar_bläck`: för `primary, danger, success, warning, info` gäller `filament()->getPanel('admin')->getColors()[$c][500] === Color::hex('#111111')[500]`.
  - Nytt: `test_panelen_ar_alltid_ljus`: `$this->assertFalse(filament()->getPanel('admin')->hasDarkMode());`
  - `test_adminsidor_hamtar_inget_fran_andra_hostar` är oförändrat.

  I `ThemeSweepTest`: lägg `#f2b544`, `#0d0b08`, `#15110a`, `#efe6d2`, `#a0824a`, `--cc-amber` och `--cc-surface` i `FORBJUDET`. Byt namn på `test_kontroller_och_login_css_utan_kanda_kontrastfallor` till `test_felmeddelanden_har_varningstecken_och_login_css_ar_ren`, med:

```php
        $this->assertMatchesRegularExpression("#\\.fi-fo-field-wrp-error-message::before\\s*\\{[^}]*content:\\s*'⚠ '\\s*/\\s*''#u", $admin);
        $this->assertStringNotContainsString('.fi-btn-primary', $login);
        $this->assertDoesNotMatchRegularExpression('#::placeholder[^}]*opacity#', $login);
```

och ta bort indeterminate-assertionen, eftersom Filaments vita streck på ink ger 18:1.

- [ ] **Step 2:** `--filter='ThemeTest|ThemeSweepTest'` → FAIL.
- [ ] **Step 3: Provider.** Ersätt `GRAY` med:

```php
    /** Neutral varm gray-skala för System 1. 50 = paper, 200 ≈ desk, 950 = ink. */
    public const GRAY = [
        50 => '#fbfaf6',
        100 => '#f3f1ea',
        200 => '#e9e6df',
        300 => '#cfcac0',
        400 => '#8f8a80',
        500 => '#6b675e',
        600 => '#55524b',
        700 => '#3d3a35',
        800 => '#2a2825',
        900 => '#1c1b19',
        950 => '#111111',
    ];
```

`->colors([...])`: `primary`, `danger`, `warning`, `success` och `info` sätts alla till `Color::hex('#111111')`, och `gray` till `self::GRAY`. `->darkMode(true, true)` → `->darkMode(false)`. Uppdatera kommentaren ovanför login-hooken om det behövs, men inget annat.

Kontrollera kontrasten för gray-500 mot gray-50 innan du går vidare: `#6b675e` på `#fbfaf6` ska vara ≥4.5 (≈5.4). Om testet faller: mörka 500 till `#625e56`.

- [ ] **Step 4: Avatar.** I `InitialsAvatarProvider` byt `fill="#2e2412"` → `fill="#111111"` och `fill="#f2b544"` → `fill="#fbfaf6"`, och ändra kommentaren "i Fosfor-färger" → "i System 1-färger".
- [ ] **Step 5: Dev-kit.** Byt i `game-dev-kit.blade.php`: `var(--cc-amber)` → `var(--cc-ink)`, `var(--cc-bg)` → `var(--cc-paper)`, `var(--cc-ok)` → `var(--cc-ink)` (med `text-decoration: underline` på `.copied`, så att tillståndet syns utan färg), `rgba(242,181,68,.06)` → `var(--cc-desk)` och `var(--cc-line)`/`var(--cc-muted)` står kvar. Hover på kopieringsknappen inverteras (paper på ink).
- [ ] **Step 6: Skriv om `cc-admin.css`.** Läs först selektorerna i vendor:

```bash
grep -rhoE "fi-(section|ta|modal|dropdown|topbar|sidebar|btn|badge|input|fo-field-wrp|checkbox|toggle|header|wi-stats-overview-stat|ta-header|section-header)[a-z-]*" vendor/filament/*/resources/views | sort -u > /tmp/fi-klasser.txt   # använd scratchpad
```

Skriv sedan filen med de här blocken. Varje selektor ska finnas i listan, och varje block har en kommentar som förklarar varför:

```css
/* Computer Cat, System 1 (Mac 1984, 1-bit) för Filament i ljust läge. Färger och typsnitt
   kommer från AdminPanelProvider och cc-theme.css. Selektorerna är lästa ur Filament v3.3. */

/* -- Skrivbord -- */
body.fi-body {
    background-color: var(--cc-desk);
    background-image: radial-gradient(var(--cc-dot) 1px, transparent 1px);
    background-size: 4px 4px;
    color: var(--cc-ink);
}

/* -- Fönster: paper, 2px bläckram, hård skugga. Filaments ringar och skuggor nollas så att det inte blir dubbla ramar. -- */
.fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-modal-window, .fi-dropdown-panel {
    background: var(--cc-paper);
    border: 2px solid var(--cc-ink);
    border-radius: 0;
    box-shadow: 4px 4px 0 var(--cc-ink);
    --tw-ring-shadow: 0 0 #0000;
}

/* -- Titellist: randig, rubriktexten på paper i Silkscreen -- */
.fi-section-header, .fi-ta-header, .fi-modal-header {
    background-image: repeating-linear-gradient(0deg, var(--cc-paper) 0 2px, var(--cc-ink) 2px 3px);
    border-bottom: 2px solid var(--cc-ink);
}
.fi-section-header-heading, .fi-ta-header-heading, .fi-modal-heading {
    display: inline-block;
    background: var(--cc-paper);
    padding: 0 .6rem;
    font-family: var(--cc-font-display);
    font-weight: 400;
    font-size: .8rem;
    letter-spacing: .02em;
}
.fi-section-header-description { background: var(--cc-paper); padding: 0 .6rem; display: inline-block; color: var(--cc-muted); }

/* -- Menyrad och sidomeny -- */
.fi-topbar > nav { background: var(--cc-paper); border-bottom: 2px solid var(--cc-ink); box-shadow: none; --tw-ring-shadow: 0 0 #0000; }
.fi-sidebar { background: var(--cc-paper); border-right: 2px solid var(--cc-ink); }
.fi-sidebar-header { border-bottom: 2px solid var(--cc-ink); box-shadow: none; --tw-ring-shadow: 0 0 #0000; }
.fi-sidebar-header .fi-logo { font-family: var(--cc-font-display); font-weight: 400; font-size: 1rem; letter-spacing: .04em; color: var(--cc-ink); }
.fi-sidebar-item-button { border-radius: 0; }
.fi-sidebar-item.fi-active .fi-sidebar-item-button { background: var(--cc-ink); }
.fi-sidebar-item.fi-active .fi-sidebar-item-label,
.fi-sidebar-item.fi-active .fi-sidebar-item-icon { color: var(--cc-paper); }
.fi-sidebar-group-label { font-family: var(--cc-font-display); font-weight: 400; font-size: .7rem; color: var(--cc-muted); }

/* -- Knappar: Mac-knapp med 2px ram och rundade hörn. Primär = standardknapp med dubbelram; danger = inverterad. -- */
.fi-btn { border-radius: 8px; border: 2px solid var(--cc-ink); box-shadow: none; --tw-ring-shadow: 0 0 #0000; }
.fi-btn.fi-color-custom:not(.fi-btn-outlined):not(label),
.fi-btn.fi-color-custom:not(.fi-btn-outlined):not(label) .fi-btn-icon { background: var(--cc-paper); color: var(--cc-ink); }
.fi-btn.fi-color-primary:not(.fi-btn-outlined):not(label) { box-shadow: 0 0 0 2px var(--cc-paper), 0 0 0 4px var(--cc-ink); }
.fi-btn.fi-color-danger:not(.fi-btn-outlined):not(label),
.fi-btn.fi-color-danger:not(.fi-btn-outlined):not(label) .fi-btn-icon { background: var(--cc-ink); color: var(--cc-paper); }
.fi-btn:not(.fi-color-danger):hover { background: var(--cc-desk); }

/* -- Badges: färgade inverteras, gray har bara ram -- */
.fi-badge { border-radius: 0; }
.fi-badge.fi-color-custom { background: var(--cc-ink); color: var(--cc-paper); --tw-ring-color: var(--cc-ink); }
.fi-badge.fi-color-custom .fi-badge-icon { color: var(--cc-paper); }
.fi-badge.fi-color-gray { background: var(--cc-paper); color: var(--cc-ink); --tw-ring-color: var(--cc-ink); }

/* -- Fält -- */
.fi-input-wrp { border-radius: 0; background: #fff; --tw-ring-color: var(--cc-ink); }
.fi-input-wrp:focus-within { box-shadow: 0 0 0 2px var(--cc-paper), 0 0 0 4px var(--cc-ink); }

/* -- Felmeddelanden: inget rött i 1-bit, därför ett varningstecken -- */
.fi-fo-field-wrp-error-message { color: var(--cc-ink); font-weight: 600; }
.fi-fo-field-wrp-error-message::before { content: '⚠ ' / ''; }

/* -- Tabeller -- */
.fi-ta-header-cell-label { font-family: var(--cc-font-display); font-weight: 400; font-size: .7rem; color: var(--cc-ink); }
.fi-ta-row { border-top: 1px solid var(--cc-ink) !important; }  /* slår Filaments divide-gray-200 (0,4,0) */
.fi-ta-row:hover { background: var(--cc-desk); }
.fi-ta-row.fi-selected, .fi-ta-row:has(.fi-ta-record-checkbox:checked) { background: var(--cc-ink); color: var(--cc-paper); }

/* -- Sidrubrik -- */
.fi-header-heading { font-family: var(--cc-font-display); font-weight: 400; letter-spacing: .02em; }
```

Det som inte finns i klasslistan tas bort eller rättas. `fi-ta-header-heading` och `fi-selected` är sannolika kandidater, så kontrollera dem. Ingen `!important` utöver den kommenterade.

- [ ] **Step 7:** `--filter='ThemeTest|ThemeSweepTest|GameDevKitTest'` → PASS. **Commit** `System 1: admin i 1-bit, bara ljust läge`.

---

### Task 3: Login

**Files:** Modify `resources/views/filament/pages/auth/login.blade.php`, `public/css/cc-login.css`. Test: `tests/Feature/Filament/LoginPageTest.php`.

- [ ] **Step 1: Test (rött).** I `test_login_laddar_sina_egna_filer...`: byt `assertStringContainsString('ready_', $html)` mot `assertStringContainsString('class="cc-titlebar"', $html)` och `assertStringContainsString('Computer Cat</span>', $html)`, och lägg till `assertStringNotContainsString('ready_', $html)`. Alla `id`-assertions står kvar.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Markup.** I vyn ersätts header-blocket och footern. Inuti `.cc-login-card`, direkt efter katt-banan:

```blade
            <div class="cc-titlebar"><span class="cc-titlebar-close" aria-hidden="true"></span><span class="cc-titlebar-text">Computer Cat</span></div>

            <div class="cc-window-body">
                <p class="cc-welcome">Välkommen. Logga in för att fortsätta.<span class="cc-blink-cursor" aria-hidden="true">_</span></p>

                <div class="cc-form-area">
                    …formuläret oförändrat…
                </div>
            </div>
```

Ta bort `.cc-login-header` och `.cc-login-footer`. `id="login-card"`, båda katt-banorna med `wire:ignore` och nedre banan sist står kvar.

- [ ] **Step 4: CSS.** I `cc-login.css`:
  - `:root`-aliasen: `--cc-cat-fur: var(--cc-ink); --cc-cat-eye: var(--cc-paper); --cc-cat-glint: var(--cc-ink); --cc-cat-nose: var(--cc-paper); --cc-cat-whisker: var(--cc-muted); --cc-fly: var(--cc-ink);`
  - `body.fi-body`: desk med prickmönster (samma som admin).
  - `.cc-login-card`: `background: var(--cc-paper); border: 2px solid var(--cc-ink); box-shadow: 4px 4px 0 var(--cc-ink); padding: 0;` (bredd och z-index oförändrade).
  - Nya regler:

```css
.cc-titlebar { position: relative; height: 24px; display: flex; align-items: center; justify-content: center;
    border-bottom: 2px solid var(--cc-ink); padding: 3px 4px; box-sizing: border-box; background-clip: content-box;
    background-image: repeating-linear-gradient(0deg, var(--cc-paper) 0 2px, var(--cc-ink) 2px 3px); }
.cc-titlebar-text { background: var(--cc-paper); padding: 0 10px; font-family: var(--cc-font-display); font-size: 12px; }
.cc-titlebar-close { position: absolute; left: 10px; width: 12px; height: 12px; border: 2px solid var(--cc-ink); background: var(--cc-paper); box-sizing: border-box; }
.cc-window-body { padding: 1.5rem 1.75rem 1.75rem; }
.cc-welcome { margin: 0 0 1.25rem; color: var(--cc-muted); font-family: var(--cc-font); font-size: .8rem; text-align: center; }
```

  - `.cc-cat-track` står oförändrad (top −48px), så katten går på titellisten.
  - Formulär: etiketter i ink och Plex Mono, normalt skiftläge (stryk `text-transform: uppercase` och spärrningen). `.fi-input` får vit bakgrund och ink-text. `.fi-input-wrp` radie 0 med `--tw-ring-color: var(--cc-ink)`, och fokus med dubbelram som admin.
  - Knappen (`.cc-form-area .fi-btn.fi-color-primary`): paper, ink-text, 2px ink-ram, radie 8px, dubbelram, normalt skiftläge, `font-weight: 600`. Formulärets actions högerställs med `.cc-form-area .fi-form-actions { justify-content: flex-end; }`. Stryk `fullWidth` endast via CSS: `.cc-form-area .fi-btn { width: auto; }`. Hover: desk.
  - Checkbox ikryssad: ink.
  - Zzz, pratbubbla och dess pil: paper eller ink enligt spec, 2px ram, 11px.
  - Stryk de gamla reglerna för `.cc-login-header`, `.cc-logo-*`, `.cc-subtitle`, `.cc-login-footer`.
  - Kontroll: `grep -nE "cc-amber|cc-surface|cc-bg\b|cc-text\b|cc-danger|cc-ok" public/css/cc-login.css || echo REN`.
- [ ] **Step 5:** `--filter=LoginPageTest` → PASS. **Commit** `System 1: login som Mac-fönster med svart pixelkatt`.

---

### Task 4: Easter eggs

**Files:** Modify `public/js/cc-eggs.js`.

- [ ] Byt `CC` till `{ ink: '#111111', paper: '#fbfaf6', desk: '#e9e6df', muted: '#55524b', font: "'IBM Plex Mono', ui-monospace, monospace" }`, och ersätt varje användning av de gamla nycklarna:
  - Matrix: fade `'rgba(251,250,246,0.12)'`, glyfer `CC.ink`.
  - Pixelkattens `box-shadow`-färger: päls `#f2b544` → `#111111`, ögon `#0d0b08` → `#fbfaf6`, glint `#efe6d2` → `#111111`, nos `#e8664a` → `#fbfaf6`, morrhår `#a0824a` → `#55524b`. Kontrollera antalet träffar före och efter med grep.
  - Talbubblor: `background: CC.paper; border: 2px solid CC.ink; color: CC.ink`, 11px.
  - Nyan: `colors` → `['#111111', '#55524b']`, och `dot.style.cssText` får `border:2px solid ${color};background:${Math.random() > .5 ? color : 'transparent'};box-sizing:border-box;`.
  - Hacker: overlayen ersätts av en inverterad blinkning: `document.documentElement.style.filter = flashes % 2 === 0 ? 'invert(1)' : ''`. Den återställs till `''` när glitchen är slut. Overlayen behålls som tom (oförändrad timing). Meddelandet: `background: CC.paper; color: CC.ink; border: 2px solid CC.ink; box-shadow: 4px 4px 0 CC.ink; font-family: CC.font; font-size: 1.2rem; padding: 1.25rem 1.75rem;`. Undertexten är `CC.muted`.
- [ ] Kontroll:

```bash
grep -nE "#f2b544|#0d0b08|#15110a|#efe6d2|#a0824a|#e8664a|#f6c76e|CC\.(amber|bg|surface|text|ok|danger)" public/js/cc-eggs.js || echo REN
node --check public/js/cc-eggs.js
```

- [ ] **Commit** `System 1: easter eggs i 1-bit`.

---

### Task 5: MCP-godkännandet

**Files:** Modify `resources/views/vendor/mcp/authorize.blade.php` (`<head>` och omslag), `tests/Feature/Mcp/StaffAccessTest.php`.

- [ ] **Test (rött)** i `test_authorize_page_uses_the_theme_and_is_always_dark`: döp om till `test_authorize_page_uses_the_theme`. Lägg till `assertStringContainsString('class="titlebar"', $html)` och `assertStringContainsString('--cc-ink', $html)`. `prefers-color-scheme` får fortfarande inte finnas.
- [ ] **Läs vyns `<body>` först.** Lägg titellisten som första barn i `<main class="card">`: `<div class="titlebar"><span class="close" aria-hidden="true"></span><span class="t">Godkänn {{ $client->name }}</span></div>`. Använd exakt det uttryck för klientnamnet som `<h1>` redan använder, och ha kvar `<h1>` men visuellt dold (`.sr-only`) så att skärmläsare och befintliga tester behåller rubriken. Resten av markupen är orörd.
- [ ] **CSS**: ersätt `<style>` med paper-, ink- och desk-varianten. Body = desk med prickar. `.card` = paper, 2px ram och 4px skugga, padding 0. `.titlebar` som login. Innehållet i en `.inner { padding: 20px 22px }`. **Lägg inte till element runt formulären.** Stoppa i stället padding på `.card > :not(.titlebar)` med `margin: 0 22px`, och ge sista barnet `margin-bottom: 22px`. `.actions { justify-content: flex-end }`, `.actions form { flex: 0 0 auto }`, Avbryt-formuläret först. Om formulärens DOM-ordning är Avbryt → Godkänn passar det redan. Kontrollera och använd annars `order`. Knappar som Mac-knappar, och `.primary` med dubbelram. Alla `var()` har fallback.
- [ ] Diff-kontroll som i Fosfor-planen: inga ändrade rader med `form`, `csrf`, `method`, `name=` utöver titellisten.
- [ ] `php artisan test --compact tests/Feature/Mcp` → PASS. **Commit** `System 1: MCP-godkännandet som Mac-dialog`.

---

### Task 6: Intresse-sidorna

**Files:** Modify `resources/views/interest/result.blade.php`, `unsubscribe.blade.php` (bara `<style>`), `tests/Feature/ThemeSweepTest.php`.

- [ ] **Test (rött)**: `test_intresse_sidorna_anvander_temats_typsnitt_med_ljusa_farger` kontrollerar `#fbfaf6`, `#111111` och `'Silkscreen'` i stället för `#fbf7ee`/`#8a5a00`.
- [ ] **CSS** (båda filerna):

```css
        /* System 1 för spelare: papper och bläck. Typsnitten från cc-theme.css, färgerna lokala. */
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
               background: #fbfaf6; color: #111111; font: 18px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-family: 'Silkscreen', 'IBM Plex Mono', monospace; font-weight: 400; font-size: 24px; line-height: 1.3; margin: 0 0 14px; }
        p { color: #111111; }
        a { color: #111111; font-weight: 700; text-underline-offset: 3px; }
        button { font: 600 16px/1 'IBM Plex Mono', ui-monospace, monospace; background: #fbfaf6; color: #111111;
                 border: 2px solid #111111; border-radius: 8px; padding: 10px 20px; cursor: pointer;
                 box-shadow: 0 0 0 2px #fbfaf6, 0 0 0 4px #111111; }
        button:hover { background: #e9e6df; }
        button:focus-visible, a:focus-visible { outline: 2px dashed #111111; outline-offset: 6px; }
```

- [ ] `--filter='ThemeSweepTest|InterestSignupTest'` → PASS. **Commit** `System 1: spelarsidorna på papper`.

---

### Task 7: Svep efter syskon och hela sviten

- [ ] `grep -rnE "cc-amber|cc-surface|--cc-bg\b|--cc-text\b|cc-danger|cc-ok|cc-warn|#f2b544|#0d0b08|#15110a|Fosfor" app resources public/css public/js tests | grep -v "public/css/filament"`: varje träff åtgärdas. Kommentarer som nämner Fosfor uppdateras till System 1.
- [ ] `php artisan test --compact` → allt grönt.

### Task 8: Headless-verifiering

Återanvänd skripten från Fosfor-verifieringen (`scratchpad/verify/*.mjs`) med host `computercat-api-design-system1-tema.test`, nya skärmdumpar i `scratchpad/verify/s1/`:
- **Login:** en katt, den rör sig, drag och fall fungerar, en misslyckad inloggning ger ⚠-fel och en katt. Mobil 390 px utan horisontell scroll.
- **Admin:** dashboard, lista, redigera (primär- och danger-knapp), modal (Radera, men bekräfta inte), toast, dev-kit, röster och ai-usage.
- **Easter eggs:** alla fem.
- **Authorize** (ny klient via tinker) och **intresse** confirm och unsubscribe live (sätt en känd `token_hash` med `InterestSignup::hashToken`).
- **Kontrastsvep** över synliga textnoder på alla sidor: 0 under 4.5.
- **Inga främmande domäner.**
- Kontrollera att `document.fonts.check("12px Silkscreen", "Översikt")` är true.
