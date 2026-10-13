# Fosfor-tema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Byt neon-cyberpunk-looken mot ett amber-terminaltema ("Fosfor") i admin, login, MCP-godkännandet och på intresse-sidorna. Alla katt- och easter egg-beteenden ska finnas kvar.

**Architecture:** En enda tokenfil (`public/css/cc-theme.css`) med `@font-face` för en självhostad IBM Plex Mono och `:root`-tokens. Filament matas via `->colors()` och `->font()`. Resten är små statiska CSS/JS-filer i `public/`, cache-bustade med `App\Support\ThemeAsset::url()`. Ingen Vite-build i prod.

**Tech Stack:** Laravel 12, Filament v3.3.49 (förkompilerad CSS i `public/css/filament/`), Livewire 3, PHPUnit-klasser med `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-10-13-fosfor-tema-design.md`

**Arbetskatalog:** `/Users/erik/Web/computercat-api/.claude/worktrees/design-fosfor-tema` (branch `design/fosfor-tema`). Rör aldrig huvudkatalogen. Testkommando: `php artisan test --compact <filter>`.

## Förberedelse (före task 1)

Worktreen saknar Passport-nycklar, så de 59 testerna i `tests/Feature/Mcp` faller med `Invalid key supplied` redan innan något har ändrats. Med Eriks godkännande körs `php artisan passport:keys` **i worktreen**. Filerna `storage/oauth-*.key` är gitignorerade och lokala. Kör sedan `php artisan test --compact` och notera utgångsläget, som ska vara helt grönt. Nya röda tester efter en task är regressioner.

## Global Constraints

- Mörka tokens: `--cc-bg #0d0b08`, `--cc-surface #15110a`, `--cc-line #2e2412`, `--cc-text #efe6d2`, `--cc-amber #f2b544`, `--cc-amber-hover #f6c76e`, `--cc-muted #a0824a`, `--cc-ok #9fd36b`, `--cc-warn #f08a24`, `--cc-danger #e8664a`.
- Ljusa tokens (intresse-sidorna): bakgrund `#fbf7ee`, text `#2a1f0c`, accent `#8a5a00`, dämpad text `#6b6150`, knapptext `#fbf7ee` på `#8a5a00`.
- Färgade knappar och badges har mörk text (`--cc-bg`), aldrig vit.
- All text och alla knappar klarar WCAG AA (4.5:1).
- Inga resurser från tredje part: varken `fonts.googleapis.com`, `fonts.bunny.net` eller något CDN i runtime.
- Förbjudet i berörda filer efter planen: `Press Start`, `JetBrains`, `#00f0ff`, `#ff00aa`, `#39ff14`, `#0a0a1a`, `#12122a`, `rgba(0,240,255`, `rgba(57,255,20`.
- Minsta typsnittsstorlek i UI och easter eggs: 11px.
- Ingen ändring i deployen. Inga nya features.
- `cc-eggs.js` och katten på login: **samma beteenden och triggers**, bara utseendet ändras.
- Authorize-vyn: formulär, metoder, `@csrf`, `{{ }}`-escaping och att `logo_uri`/`client_uri` döljs är orört.
- Intresse-sidorna: logiken och de texter `InterestSignupTest` kontrollerar är orörda. Unsubscribe-formuläret får **inte** `@csrf`.

## Review Focus

1. **Misslyckad inloggning:** Livewire morphar om login-komponenten. Katten ska finnas kvar som en enda katt med sin position och sitt beteende (`wire:ignore`). Testas i task 4 (assertion på `wire:ignore`) och i task 9 (webbläsaren).
2. **`public_path` utan filer** (`StaffAccessTest::withoutBuiltAssets`): sidan ska rendera utan varning. Testas i task 1 och via befintliga authorize-tester i task 7.
3. **Filaments vita knapptext på amber:** den ska bli mörk på primär-, danger-, success- och warning-knappar, men gray-knappar och outlined-knappar ska vara orörda. Testas med kontrastskriptet i task 3 och visuellt i task 9.
4. **Login-JS laddas bara på login:** en vanlig adminsida får inte länka `cc-login.js`. Testas i task 4.
5. **å, ä och ö i Plex Mono:** svenska tecken får inte falla tillbaka till ett annat typsnitt. Testas i task 9 med "Användare" och "Översikt" i sidomenyn.

---

### Task 1: ThemeAsset-helper

**Files:**
- Create: `app/Support/ThemeAsset.php`
- Test: `tests/Unit/ThemeAssetTest.php`

**Interfaces:**
- Produces: `App\Support\ThemeAsset::url(string $path): string`. Returnerar `asset($path).'?v='.filemtime(public_path($path))` om filen finns, annars `asset($path)`. Den ger aldrig en varning.

- [ ] **Step 1: Skriv det fallerande testet**

```php
<?php

namespace Tests\Unit;

use App\Support\ThemeAsset;
use Tests\TestCase;

class ThemeAssetTest extends TestCase
{
    public function test_versionerar_med_filemtime_nar_filen_finns(): void
    {
        $dir = sys_get_temp_dir().'/cc-theme-'.uniqid();
        mkdir($dir.'/css', 0777, true);
        file_put_contents($dir.'/css/x.css', 'a{}');
        touch($dir.'/css/x.css', 1700000000);
        $this->app->usePublicPath($dir);

        $this->assertSame(asset('css/x.css').'?v=1700000000', ThemeAsset::url('css/x.css'));
    }

    public function test_saknad_fil_ger_url_utan_version_och_utan_varning(): void
    {
        $this->app->usePublicPath(sys_get_temp_dir().'/cc-theme-tom-'.uniqid());

        $this->assertSame(asset('css/x.css'), ThemeAsset::url('css/x.css'));
    }
}
```

- [ ] **Step 2: Kör testet och se det falla**

Run: `php artisan test --compact --filter=ThemeAssetTest`
Expected: FAIL, `Class "App\Support\ThemeAsset" not found`

- [ ] **Step 3: Implementera**

```php
<?php

namespace App\Support;

/**
 * URL till en statisk temafil i public/ med cache-bust. Produktionen bygger inga
 * Vite-assets, så temat ligger som vanliga filer. En saknad fil ger en URL utan
 * version i stället för en varning (testerna byter public_path till en tom katalog).
 */
class ThemeAsset
{
    public static function url(string $path): string
    {
        $file = public_path($path);

        return is_file($file) ? asset($path).'?v='.filemtime($file) : asset($path);
    }
}
```

- [ ] **Step 4: Kör testet och se det gå igenom**

Run: `php artisan test --compact --filter=ThemeAssetTest`
Expected: PASS (2 tester)

- [ ] **Step 5: Commit**

```bash
git add app/Support/ThemeAsset.php tests/Unit/ThemeAssetTest.php
git commit -m "ThemeAsset: cache-bustade URL:er till statiska temafiler"
```

---

### Task 2: Typsnitt och tokenfil

**Files:**
- Create: `public/fonts/plex-mono/ibm-plex-mono-latin-{400,500,600}-normal.woff2`, `public/fonts/plex-mono/OFL.txt`
- Create: `public/css/cc-theme.css`
- Test: `tests/Unit/ThemeFilesTest.php`

**Interfaces:**
- Produces: `public/css/cc-theme.css` med `@font-face` (family `'IBM Plex Mono'`) och alla mörka tokens i Global Constraints på `:root`. Senare tasks använder `var(--cc-*)` och länkar filen med `ThemeAsset::url('css/cc-theme.css')`.

- [ ] **Step 1: Skriv det fallerande testet**

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ThemeFilesTest extends TestCase
{
    private function publicFile(string $path): string
    {
        return dirname(__DIR__, 2).'/public/'.$path;
    }

    public function test_typsnittsfilerna_ar_woff2_och_licensen_finns(): void
    {
        foreach ([400, 500, 600] as $w) {
            $f = $this->publicFile("fonts/plex-mono/ibm-plex-mono-latin-{$w}-normal.woff2");
            $this->assertFileExists($f);
            $this->assertSame('wOF2', substr(file_get_contents($f), 0, 4), "$f är inte woff2");
        }
        $this->assertStringContainsString('SIL Open Font License', file_get_contents($this->publicFile('fonts/plex-mono/OFL.txt')));
    }

    public function test_temat_definierar_font_face_och_alla_tokens(): void
    {
        $css = file_get_contents($this->publicFile('css/cc-theme.css'));

        $this->assertStringContainsString("font-family: 'IBM Plex Mono'", $css);
        $this->assertSame(3, substr_count($css, '@font-face'));
        foreach (['--cc-bg: #0d0b08', '--cc-surface: #15110a', '--cc-line: #2e2412', '--cc-text: #efe6d2',
            '--cc-amber: #f2b544', '--cc-amber-hover: #f6c76e', '--cc-muted: #a0824a', '--cc-ok: #9fd36b',
            '--cc-warn: #f08a24', '--cc-danger: #e8664a'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $css);
    }
}
```

- [ ] **Step 2: Kör testet och se det falla**

Run: `php artisan test --compact --filter=ThemeFilesTest`
Expected: FAIL, filen finns inte

- [ ] **Step 3: Hämta typsnitten (en gång, versionslåst) och checka in dem**

```bash
mkdir -p public/fonts/plex-mono
for w in 400 500 600; do
  curl -fsSL -o public/fonts/plex-mono/ibm-plex-mono-latin-$w-normal.woff2 \
    https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@5.2.7/files/ibm-plex-mono-latin-$w-normal.woff2
done
curl -fsSL -o public/fonts/plex-mono/OFL.txt https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@5.2.7/LICENSE
head -c 4 public/fonts/plex-mono/ibm-plex-mono-latin-400-normal.woff2; echo   # ska skriva wOF2
```

Subsetet `latin` täcker U+0000–00FF, alltså även å, ä och ö.

- [ ] **Step 4: Skriv `public/css/cc-theme.css`**

```css
/* Computer Cat, Fosfor-tema: typsnitt och tokens. Enda källan för färgerna.
   Laddas av admin och login (Filament ->font()), MCP-godkännandet och intresse-sidorna. */

@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('../fonts/plex-mono/ibm-plex-mono-latin-400-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url('../fonts/plex-mono/ibm-plex-mono-latin-500-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('../fonts/plex-mono/ibm-plex-mono-latin-600-normal.woff2') format('woff2');
}

:root {
    --cc-font: 'IBM Plex Mono', ui-monospace, Menlo, monospace;
    --cc-bg: #0d0b08;
    --cc-surface: #15110a;
    --cc-line: #2e2412;
    --cc-text: #efe6d2;
    --cc-amber: #f2b544;
    --cc-amber-hover: #f6c76e;
    --cc-muted: #a0824a;
    --cc-ok: #9fd36b;
    --cc-warn: #f08a24;
    --cc-danger: #e8664a;
}
```

- [ ] **Step 5: Kör testet och se det gå igenom**

Run: `php artisan test --compact --filter=ThemeFilesTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add public/fonts/plex-mono public/css/cc-theme.css tests/Unit/ThemeFilesTest.php
git commit -m "Fosfor: självhostad IBM Plex Mono och tokenfil"
```

---

### Task 3: Admin-panelen

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (colors, font, render-hooks)
- Rewrite: `public/css/cc-admin.css`
- Create: `tests/Support/contrast.php` (kontrastfunktion som testerna delar)
- Test: `tests/Feature/Filament/ThemeTest.php`

**Interfaces:**
- Consumes: `ThemeAsset::url()`, `cc-theme.css`
- Produces: `App\Providers\Filament\AdminPanelProvider::GRAY` (public const array 50–950), som kontrasttestet läser.

- [ ] **Step 1: Skriv de fallerande testerna**

`tests/Support/contrast.php`:

```php
<?php

namespace Tests\Support;

/** WCAG-kontrast mellan två hex-färger. */
function contrast(string $a, string $b): float
{
    $lum = function (string $hex): float {
        $c = array_map(fn ($i) => hexdec(substr(ltrim($hex, '#'), $i, 2)) / 255, [0, 2, 4]);
        $c = array_map(fn ($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}
```

`tests/Feature/Filament/ThemeTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Tests\Support\contrast;

require_once __DIR__.'/../../Support/contrast.php';

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_adminsidor_laddar_temat_och_inget_fran_tredje_part(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('css/cc-theme.css?v=', $html);
        $this->assertStringContainsString('css/cc-admin.css?v=', $html);
        $this->assertStringContainsString('js/cc-eggs.js?v=', $html);
        $this->assertStringContainsString("'IBM Plex Mono'", $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_gray_skalan_klarar_aa_dar_filament_anvander_den_som_text(): void
    {
        $g = AdminPanelProvider::GRAY;

        // Filament i mörkt läge: dämpad text gray-400/500 på gray-900/950.
        foreach ([400, 500] as $fg) {
            foreach ([900, 950] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, contrast($g[$fg], $g[$bg]), "gray-$fg på gray-$bg");
            }
        }
    }

    public function test_mork_knapptext_klarar_aa_pa_alla_fargade_knappar(): void
    {
        foreach (['#f2b544', '#f6c76e', '#e8664a', '#9fd36b', '#f08a24'] as $bg) {
            $this->assertGreaterThanOrEqual(4.5, contrast('#0d0b08', $bg), "mörk text på $bg");
        }
    }
}
```

- [ ] **Step 2: Kör testerna och se dem falla**

Run: `php artisan test --compact --filter=ThemeTest`
Expected: FAIL (`?v=` saknas, `GRAY` är inte definierad)

- [ ] **Step 3: Ändra `AdminPanelProvider`**

Lägg till `use App\Support\ThemeAsset;` och `use Filament\FontProviders\LocalFontProvider;`. Lägg till konstanten i klassen:

```php
    /** Varm gray-skala för Fosfor-temat. 950/900 = --cc-bg/--cc-surface, 500 = --cc-muted. */
    public const GRAY = [
        50 => '#faf6ee',
        100 => '#f2ead9',
        200 => '#e2d5bb',
        300 => '#c9b48c',
        400 => '#b39a6a',
        500 => '#a0824a',
        600 => '#6b5733',
        700 => '#4a3c23',
        800 => '#2e2412',
        900 => '#15110a',
        950 => '#0d0b08',
    ];
```

Ersätt `->colors([...])` och de två render-hooks med:

```php
            ->colors([
                'primary' => Color::hex('#f2b544'),
                'gray' => self::GRAY,
                'danger' => Color::hex('#e8664a'),
                'warning' => Color::hex('#f08a24'),
                'success' => Color::hex('#9fd36b'),
            ])
            ->font('IBM Plex Mono', ThemeAsset::url('css/cc-theme.css'), LocalFontProvider::class)
            ->darkMode(true, true)
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.e(ThemeAsset::url('css/cc-admin.css')).'">'
            )
            ->renderHook(
                'panels::body.end',
                fn () => '<script src="'.e(ThemeAsset::url('js/cc-eggs.js')).'"></script>'
            )
```

`->font()` med en URL utvärderas när panelen registreras. Det fungerar eftersom `public_path` är satt då.

- [ ] **Step 4: Skriv om `public/css/cc-admin.css`**

```css
/* Computer Cat, Fosfor-tema för Filament. Färger och typsnitt kommer från
   AdminPanelProvider (->colors, ->font) och cc-theme.css. Här ligger bara det
   Filament inte har inställningar för. Selektorerna är lästa ur Filament v3.3. */

/* -- Bakgrund och brödtext: varmvit i stället för Filaments vita -- */
.dark body.fi-body { background: var(--cc-bg); color: var(--cc-text); }
.dark .dark\:text-white { color: var(--cc-text); }
.dark .dark\:text-gray-200 { color: var(--cc-text); }

/* -- Färgade knappar: mörk text, Filament sätter vit (1.5–3.3:1). Badges behöver inget:
      de har custom-400-text på 10 % tint (8.3–12.8:1). Toggle-knappar (label) undantas. -- */
.fi-btn.fi-color-custom:not(.fi-btn-outlined):not(label),
.fi-btn.fi-color-custom:not(.fi-btn-outlined):not(label) .fi-btn-icon {
    color: var(--cc-bg);
}
.dark .fi-btn.fi-color-primary:not(.fi-btn-outlined):hover { background: var(--cc-amber-hover); }
.fi-btn { border-radius: 2px; letter-spacing: .04em; }

/* -- Ikryssad checkbox: Filaments vita bock på amber är 1.9:1, mörk bock ger 10.7:1 -- */
.dark .fi-checkbox-input:checked {
    background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='%230d0b08' xmlns='http://www.w3.org/2000/svg'%3e%3cpath d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/%3e%3c/svg%3e");
}

/* -- Raka hörn på ytor och fält -- */
.fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-modal-window,
.fi-input-wrp, .fi-dropdown-panel, .fi-badge { border-radius: 2px; }

/* -- Sidomeny -- */
.dark .fi-sidebar { background: var(--cc-surface); border-right: 1px solid var(--cc-line); }
.fi-sidebar-header .fi-logo {
    color: var(--cc-amber);
    font-weight: 600;
    letter-spacing: .2em;
    text-transform: uppercase;
    font-size: .95rem;
}
.fi-sidebar-item-button { border-radius: 0; }
.fi-sidebar-item.fi-active .fi-sidebar-item-button { background: var(--cc-line); }
.fi-sidebar-item.fi-active .fi-sidebar-item-label { color: var(--cc-amber); }
.fi-sidebar-item.fi-active .fi-sidebar-item-label::before { content: '> ' / ''; }
.fi-sidebar-group-label { text-transform: uppercase; letter-spacing: .12em; font-size: .7rem; }

/* -- Topbar -- */
.dark .fi-topbar > nav { background: var(--cc-bg); box-shadow: none; border-bottom: 1px solid var(--cc-line); }

/* -- Tabeller: versala rubriker, streckade radlinjer -- */
.fi-ta-header-cell-label { text-transform: uppercase; letter-spacing: .1em; font-size: .7rem; }
/* Filaments divide-white/5 har specificitet 0,4,0, därav !important. */
.dark .fi-ta-row { border-top: 1px dashed var(--cc-line) !important; }
.dark .fi-ta-row:hover { background: rgba(242, 181, 68, .04); }

/* -- Sidrubrik -- */
.fi-header-heading { letter-spacing: .02em; }
.fi-header-heading::before { content: '// ' / ''; color: var(--cc-muted); }
```

Verifiera att varje selektor finns innan du går vidare. Det som inte ger träff tas bort eller rättas:

```bash
for s in fi-sidebar-item-button fi-sidebar-item-label fi-sidebar-group-label fi-ta-header-cell-label fi-ta-row fi-header-heading fi-wi-stats-overview-stat fi-input-wrp fi-dropdown-panel fi-modal-window fi-logo fi-link fi-btn-outlined; do
  printf '%s ' $s; grep -rl "$s" vendor/filament/*/resources/views | head -1 || echo SAKNAS
done
grep -o '\.dark\\:text-gray-200:is' public/css/filament/filament/app.css | head -1
```

- [ ] **Step 5: Kör testerna**

Run: `php artisan test --compact --filter=ThemeTest`
Expected: PASS (3 tester)

- [ ] **Step 6: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php public/css/cc-admin.css tests/Support/contrast.php tests/Feature/Filament/ThemeTest.php
git commit -m "Fosfor: admin-panelen får amber, varm gray-skala och självhostat typsnitt"
```

---

### Task 4: Login-sidan

**Files:**
- Modify: `resources/views/filament/pages/auth/login.blade.php` (bara markup kvar)
- Create: `public/css/cc-login.css`, `public/js/cc-login.js`
- Test: `tests/Feature/Filament/LoginPageTest.php`

**Interfaces:**
- Consumes: `ThemeAsset::url()`, tokens från `cc-theme.css` (laddas redan via `->font()` på login)

- [ ] **Step 1: Skriv de fallerande testerna**

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_laddar_sina_egna_filer_och_skyddar_katten_mot_morph(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('css/cc-login.css?v=', $html);
        $this->assertMatchesRegularExpression('#<script[^>]+js/cc-login\.js\?v=\d+"[^>]*defer#', $html);
        $this->assertMatchesRegularExpression('#id="cat-track"[^>]*wire:ignore|wire:ignore[^>]*id="cat-track"#', $html);
        $this->assertMatchesRegularExpression('#id="cat-track-bottom"[^>]*wire:ignore|wire:ignore[^>]*id="cat-track-bottom"#', $html);
        $this->assertStringContainsString('wire:submit="authenticate"', $html);
        $this->assertStringContainsString('ready_', $html);
        $this->assertStringNotContainsString('cc-particle', $html);
        $this->assertStringContainsString('css/cc-theme.css?v=', $html);
        // Filament skriver själv ut <style>-block, så vi kontrollerar att login-vyns egen CSS/JS inte längre är inline.
        $this->assertStringNotContainsString('.cc-cat-body {', $html);
        $this->assertStringNotContainsString("getElementById('the-cat')", $html);
    }

    public function test_vanliga_adminsidor_laddar_inte_login_skriptet(): void
    {
        $this->actingAs(User::factory()->staff()->create());

        $this->get('/admin')->assertOk()->assertDontSee('cc-login.js', false);
    }
}
```

- [ ] **Step 2: Kör testerna och se dem falla**

Run: `php artisan test --compact --filter=LoginPageTest`
Expected: FAIL (`cc-login.css` saknas)

- [ ] **Step 3: Flytta ut JS:en oförändrad**

Kopiera innehållet mellan `<script>` och `</script>` (rad 432–1246 i nuvarande fil) byte för byte till `public/js/cc-login.js`. Ändra ingenting i logiken. Kontrollera efteråt:

```bash
sed -n '432,1246p' resources/views/filament/pages/auth/login.blade.php > /tmp/cc-login-orig.js  # använd scratchpad i stället för /tmp om det finns
diff <(sed -n '432,1246p' resources/views/filament/pages/auth/login.blade.php) public/js/cc-login.js && echo IDENTISK
```

Radnumren ska stämma med `<script>`-taggens rad + 1 till `</script>`-raden − 1. Kontrollera med `grep -n '<script>\|</script>'` först.

Skriptet startar med `document.addEventListener('DOMContentLoaded', …)`. Ett `defer`-skript körs före `DOMContentLoaded`, så det fungerar oförändrat.

- [ ] **Step 4: Flytta ut CSS:en och ändra stilen**

Kopiera innehållet mellan `<style>` och `</style>` (rad 62–428) till `public/css/cc-login.css` och gör sedan följande ändringar:

1. **Ta bort** `@import url(...)`, `:root { --cc-... }`-blocket, `.cc-scanlines`-blocket, `.cc-particles`/`.cc-particle`/`@keyframes particleFloat` och `.cc-card-border-top`.
2. **Lägg först i filen** kattens alias, så att katten behåller färgen även när JS:en flyttar den till `body`:

```css
/* Login, Fosfor. Tokens kommer från cc-theme.css (laddas via Filaments ->font()). */
:root {
    --cc-cat-fur: var(--cc-amber);
    --cc-cat-eye: var(--cc-bg);
    --cc-cat-glint: var(--cc-text);
    --cc-cat-nose: var(--cc-danger);
    --cc-cat-whisker: var(--cc-muted);
    --cc-fly: var(--cc-ok);
}
```

3. **I katt-, ben-, blink- och svansreglerna** (`.cc-cat-body`, `.cc-cat-legs`, `.cc-cat.* .cc-cat-legs`, `@keyframes legWalk/groomPaw/tailSpin/pounceAnim*`, `.cc-cat-blink`) ersätts, bara inom de reglerna:
   - `var(--cc-text)` → `var(--cc-cat-fur)`
   - `var(--cc-dark)` → `var(--cc-cat-eye)`
   - `var(--cc-primary)` → `var(--cc-cat-glint)`
   - `var(--cc-secondary)` → `var(--cc-cat-nose)`
   - `var(--cc-muted)` → `var(--cc-cat-whisker)`
4. **Ersätt** följande block helt:

```css
body.fi-body { background: var(--cc-bg) !important; min-height: 100vh; }

.cc-login-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    font-family: var(--cc-font);
    overflow: hidden;
    padding: 0 16px;
}

.cc-login-card {
    position: relative; width: 100%; max-width: 420px;
    background: var(--cc-surface);
    border: 1px solid var(--cc-line);
    padding: 2.5rem 2rem 1.5rem; z-index: 10;
}

.cc-cat-zzz {
    position: absolute; top: -18px; right: -6px;
    font-family: var(--cc-font); font-weight: 600;
    font-size: 11px; color: var(--cc-amber);
    opacity: 0; pointer-events: none;
}

.cc-fly {
    position: absolute; width: 4px; height: 4px;
    background: var(--cc-fly);
    opacity: 0; pointer-events: none;
}

.cc-login-header { text-align: center; margin-bottom: 2rem; }
.cc-logo-text {
    font-family: var(--cc-font); font-weight: 600;
    font-size: 1.15rem; color: var(--cc-text); letter-spacing: .25em;
}
.cc-logo-accent { color: var(--cc-amber); }
.cc-subtitle {
    font-family: var(--cc-font);
    font-size: .75rem; color: var(--cc-muted);
    margin-top: .5rem; letter-spacing: .12em;
}

.cc-form-area .fi-fo-field-wrp label {
    font-family: var(--cc-font) !important;
    color: var(--cc-muted) !important; font-size: .7rem !important;
    letter-spacing: .12em !important; text-transform: uppercase !important;
}
.cc-form-area .fi-input {
    background: var(--cc-bg) !important;
    color: var(--cc-text) !important;
    font-family: var(--cc-font) !important;
}
.cc-form-area .fi-input-wrp {
    border-radius: 0 !important;
    --tw-ring-color: var(--cc-line) !important;
}
.cc-form-area .fi-input-wrp:focus-within { --tw-ring-color: var(--cc-amber) !important; }
.cc-form-area .fi-input::placeholder { color: var(--cc-muted) !important; opacity: .7; }
.cc-form-area .fi-btn-primary,
.cc-form-area .fi-btn.fi-color-primary {
    background: var(--cc-amber) !important;
    color: var(--cc-bg) !important;
    font-family: var(--cc-font) !important; font-weight: 600 !important;
    font-size: .8rem !important; letter-spacing: .15em !important;
    text-transform: uppercase;
    padding: .75rem 1.5rem !important;
    border-radius: 0 !important;
}
.cc-form-area .fi-btn-primary:hover,
.cc-form-area .fi-btn.fi-color-primary:hover { background: var(--cc-amber-hover) !important; }
.cc-form-area .fi-checkbox-input:checked {
    background-color: var(--cc-amber) !important;
    border-color: var(--cc-amber) !important;
}
.cc-form-area .fi-link { font-family: var(--cc-font) !important; font-size: .75rem !important; }

.cc-login-footer {
    text-align: center; margin-top: 1.5rem;
    font-family: var(--cc-font);
    font-size: .75rem; color: var(--cc-muted);
}
.cc-blink-cursor { color: var(--cc-amber); animation: cursorBlink 1s steps(1) infinite; }
@keyframes cursorBlink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }

.cc-speech {
    position: absolute; top: -30px; left: 50%;
    transform: translateX(-50%);
    background: var(--cc-surface); border: 1px solid var(--cc-amber);
    padding: 2px 8px;
    font-family: var(--cc-font);
    font-size: 11px; color: var(--cc-amber);
    white-space: nowrap; opacity: 0;
    transition: opacity .3s; pointer-events: none; z-index: 50;
}
.cc-speech.show { opacity: 1; }
.cc-speech::after {
    content: ''; position: absolute; bottom: -5px; left: 50%;
    transform: translateX(-50%);
    border-left: 4px solid transparent; border-right: 4px solid transparent;
    border-top: 4px solid var(--cc-amber);
}
```

5. Ta bort det sista blocket med `--tw-ring-color: rgba(0,240,255,0.3)` och det gamla `.cc-form-area .fi-input:focus`-blocket (glow och `--cc-primary`). Fokus hanteras av `.fi-input-wrp:focus-within` ovan.
6. Kontrollera:

```bash
grep -nE "Press Start|JetBrains|rgba\(0,240,255|rgba\(57,255,20|#0a0a1a|--cc-(primary|secondary|tertiary|dark)\b|text-shadow|0 0 [0-9]+px" public/css/cc-login.css || echo REN
```

Expected: `REN`

- [ ] **Step 5: Skriv om blade-vyn till bara markup**

```blade
<div>
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-login.css') }}">
    <script src="{{ \App\Support\ThemeAsset::url('js/cc-login.js') }}" defer></script>

    <div class="cc-login-wrapper">
        <div class="cc-login-card" id="login-card">
            {{-- Katten flyttas av cc-login.js mellan banorna och till body. wire:ignore
                 hindrar Livewire från att morpha om den vid t.ex. misslyckad inloggning. --}}
            <div class="cc-cat-track" id="cat-track" wire:ignore>
                <div class="cc-cat" id="the-cat">
                    <div class="cc-cat-body"></div>
                    <div class="cc-cat-legs"></div>
                    <div class="cc-cat-blink"></div>
                    <div class="cc-cat-zzz" id="cat-zzz"></div>
                </div>
                <div class="cc-fly" id="the-fly"></div>
            </div>

            <div class="cc-login-header">
                <div class="cc-logo-text">COMPUTER<span class="cc-logo-accent">CAT</span></div>
                <div class="cc-subtitle">// admin_panel</div>
            </div>

            <div class="cc-form-area">
                <x-filament-panels::form wire:submit="authenticate">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            </div>

            <div class="cc-login-footer">
                <span class="cc-blink-cursor">&gt;</span> ready_
            </div>

            <div class="cc-cat-track cc-cat-track-bottom" id="cat-track-bottom" wire:ignore></div>
        </div>
    </div>

</div>
```

Filament-resetsen (`.fi-simple-layout`, `.fi-simple-main-ctn`, `.fi-simple-main`, `.fi-simple-header`) följde med i kopian i steg 4 och står kvar i `cc-login.css`. `body.fi-body` ersattes i steg 4.

Kontrollera att JS:en inte letar efter element som togs bort:

```bash
grep -nE "cc-particle|cc-scanlines|cc-card-border-top" public/js/cc-login.js || echo "inga beroenden"
```

Får du en träff ska elementet stå kvar i markupen (tomt). Logiken ändras inte.

- [ ] **Step 6: Kör testerna**

Run: `php artisan test --compact --filter=LoginPageTest`
Expected: PASS (2 tester)

- [ ] **Step 7: Commit**

```bash
git add resources/views/filament/pages/auth/login.blade.php public/css/cc-login.css public/js/cc-login.js tests/Feature/Filament/LoginPageTest.php
git commit -m "Fosfor: login i amber, katten i egen fil och skyddad mot Livewire-morph"
```

---

### Task 5: Easter eggs

**Files:**
- Modify: `public/js/cc-eggs.js`
- Test: täcks av neon-svepet i task 8. Beteendet verifieras i webbläsaren i task 9.

Inga ändringar i triggers, timers, tangentsekvenser eller flöde. Bara färger, tecken, typsnitt och storlekar.

- [ ] **Step 1: Lägg in en palett överst i IIFE:n** (direkt efter `(function() {` och `'use strict';` om det finns):

```js
    // Fosfor-paletten, samma värden som cc-theme.css.
    const CC = { bg: '#0d0b08', surface: '#15110a', text: '#efe6d2', amber: '#f2b544',
                 muted: '#a0824a', ok: '#9fd36b', danger: '#e8664a',
                 font: "'IBM Plex Mono', ui-monospace, monospace" };
```

- [ ] **Step 2: Matrix-regnet** (cirka rad 43–57)

```js
        const cats = ['=^.^=', '/\\_/\\', 'meow', '^..^', '01', 'ฅ', '>^<', 'purr'];
```

`ctx.fillStyle = 'rgba(10,10,26,0.05)';` → `ctx.fillStyle = 'rgba(13,11,8,0.08)';`
`ctx.fillStyle = '#00f0ff';` → `ctx.fillStyle = CC.amber;`
`ctx.font = fontSize + 'px monospace';` → `ctx.font = fontSize + 'px ' + CC.font;`

Kontrollera att `ฅ` renderas i Plex Mono eller fallback. Gör det inte det, stryk tecknet.

- [ ] **Step 3: Pixelkatten (meow) och dess ben** (cirka rad 85–150): färglitteralerna i `box-shadow`-strängarna ersätts mekaniskt, bara i pixelkattens och benens strängar:

```bash
# Kontrollera först antalet träffar per färg, och kör sedan ersättningen.
grep -c '#e0e0ff' public/js/cc-eggs.js
sed -i '' -e "s/#e0e0ff/#f2b544/g" -e "s/#7878a0/#a0824a/g" public/js/cc-eggs.js
```

`#0a0a1a`, `#00f0ff` och `#ff00aa` i kattens ögon och nos ersätts för hand: ögat (`#0a0a1a`) → `#0d0b08`, glinten (`#00f0ff`) → `#efe6d2`, nosen (`#ff00aa`) → `#e8664a`. Gör det i kattens `box-shadow`, inte i andra strängar (se steg 4–6).

- [ ] **Step 4: Talbubblan "Meow!" och idle-bubblan** (två `style.cssText`, cirka rad 162 och 286). Ersätt båda strängarna med:

```js
'position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:' + CC.surface + ';border:1px solid ' + CC.amber + ';color:' + CC.amber + ';padding:2px 8px;font-family:' + CC.font + ';font-size:11px;white-space:nowrap;border-radius:0;'
```

Idle-bubblan behåller sina egna `opacity:0;transition:opacity 0.3s;` i slutet. Lägg till dem efter den nya strängen, så att fade-in-beteendet är oförändrat.

- [ ] **Step 5: Nyan-spåret** (cirka rad 185–200)

```js
        const colors = ['#f2b544', '#f6c76e', '#e8a23a', '#d98e2b', '#efe6d2', '#a0824a'];
```

I `dot.style.cssText`: ta bort `box-shadow:0 0 6px ${color};`.

- [ ] **Step 6: Hacker mode** (cirka rad 226–258)
  - Glitch-overlayen: de två `rgba(...)`-färgerna i cyan och magenta byts mot `'rgba(242,181,68,0.08)'` och `'rgba(13,11,8,0.35)'` (samma växling, samma timing).
  - Meddelandet:

```js
                msg.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:99999;font-family:' + CC.font + ';font-weight:600;font-size:1.6rem;letter-spacing:.2em;color:' + CC.amber + ';background:' + CC.bg + ';border:1px solid ' + CC.amber + ';padding:1rem 1.5rem;text-align:center;pointer-events:none;';
                msg.innerHTML = '&gt; ACCESS_GRANTED<br><span style="font-size:.8rem;letter-spacing:.1em;color:' + CC.text + ';">welcome back, operator</span>';
```

- [ ] **Step 7: Idle-katten**: om den ritas med egna `box-shadow`-färger täcks den av steg 3. Kontrollera:

```bash
grep -nE "Press Start|JetBrains|#00f0ff|#ff00aa|#39ff14|#0a0a1a|#12122a|#e0e0ff|#7878a0|rgba\(0,240,255|rgba\(57,255,20|rgba\(255,0,170|0\.4rem|text-shadow|0 0 [0-9]+px" public/js/cc-eggs.js || echo REN
node --check public/js/cc-eggs.js && echo SYNTAX-OK
```

Expected: `REN` och `SYNTAX-OK`

- [ ] **Step 8: Commit**

```bash
git add public/js/cc-eggs.js
git commit -m "Fosfor: easter eggs i amber, samma triggers och beteenden"
```

---

### Task 6: Dev-kit-sidan

**Files:**
- Modify: `resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php` (cirka rad 46–95)
- Test: befintligt `tests/Feature/Filament/GameDevKitTest.php` och neon-svepet i task 8

- [ ] **Step 1: Byt tokens**

```bash
f=resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php
grep -nE "var\(--cc-|rgba\(0,240,255|0 0 [0-9]+px|#[0-9a-fA-F]{6}|JetBrains" $f
```

Kända träffar: rad 46–95, bland annat `rgba(0,240,255,…)` på rad 61, 70, 71, 76 och 77, glow `box-shadow: 0 0 10px` på rad 61, `var(--cc-bg, #0a0a1a)` på rad 60 och `var(--cc-muted, #7878a0)` på rad 93.

Ersätt:
- `var(--cc-primary, #00f0ff)` → `var(--cc-amber)`
- `var(--cc-tertiary, #39ff14)` → `var(--cc-ok)`
- `var(--cc-secondary, …)` (om den finns) → `var(--cc-danger)`
- `'JetBrains Mono', monospace` → `var(--cc-font)`
- `var(--cc-bg, #0a0a1a)` → `var(--cc-bg)`, `var(--cc-muted, #7878a0)` → `var(--cc-muted)`
- `rgba(0,240,255,…)` som bakgrund → `rgba(242,181,68,.06)`, som ram → `var(--cc-line)`
- `box-shadow: 0 0 …`-glow tas bort helt
- Övriga hårdkodade hexfärger (neon eller den gamla bakgrunden) → närmaste token: bakgrund till `var(--cc-bg)`/`var(--cc-surface)`, text till `var(--cc-text)`, dämpat till `var(--cc-muted)` och ramar till `var(--cc-line)`.

Ingen fallback i `var()` behövs, eftersom `cc-theme.css` alltid laddas i panelen. Kör grepen igen efteråt. Endast `var(--cc-…)` utan fallback får finnas kvar.

- [ ] **Step 2: Kör testerna**

Run: `php artisan test --compact --filter=GameDevKitTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php
git commit -m "Fosfor: dev-kit-sidan använder temats tokens"
```

---

### Task 7: MCP-godkännandet

**Files:**
- Modify: `resources/views/vendor/mcp/authorize.blade.php` (bara `<head>`/`<style>` och knappklasser)
- Test: `tests/Feature/Mcp/StaffAccessTest.php` (lägg till ett test)

- [ ] **Step 1: Skriv det fallerande testet** (i `StaffAccessTest`, efter `test_authorize_page_renders_without_vite_and_escapes_client_name`)

```php
    public function test_authorize_page_uses_the_theme_and_is_always_dark(): void
    {
        $this->actingAs($this->staff());

        $html = $this->get($this->authorizeUrl('Claude'))->assertOk()->getContent();

        $this->assertStringContainsString('css/cc-theme.css', $html);
        $this->assertStringNotContainsString('prefers-color-scheme', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
    }
```

- [ ] **Step 2: Kör testet och se det falla**

Run: `php artisan test --compact --filter=test_authorize_page_uses_the_theme`
Expected: FAIL (`cc-theme.css` saknas)

- [ ] **Step 3: Byt `<style>`-blocket** och lägg till länken före det. Lägg till en rad i den inledande Blade-kommentaren: "Temat (typsnitt och tokens) kommer från public/css/cc-theme.css på samma domän."

```blade
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-theme.css') }}">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--cc-bg, #0d0b08); color: var(--cc-text, #efe6d2);
               font-family: var(--cc-font, ui-monospace, monospace); font-size: 14px; line-height: 1.6; }
        .card { width: 100%; max-width: 28rem; background: var(--cc-surface, #15110a); border: 1px solid var(--cc-line, #2e2412); padding: 28px 24px; }
        h1 { font-size: 1.15rem; font-weight: 600; letter-spacing: .04em; margin: 0 0 8px; text-align: center; color: var(--cc-amber, #f2b544); }
        p { margin: 0; }
        .muted { color: var(--cc-muted, #a0824a); font-size: .85rem; }
        .center { text-align: center; }
        .box { border: 1px dashed var(--cc-line, #2e2412); padding: 12px 16px; margin: 20px 0 16px; }
        .email { font-weight: 600; overflow-wrap: anywhere; }
        ul { margin: 8px 0 0; padding-left: 20px; }
        .actions { display: flex; gap: 12px; margin-top: 24px; }
        .actions form { flex: 1; }
        button { width: 100%; height: 40px; border-radius: 0; font: inherit; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; cursor: pointer; }
        button:disabled { opacity: .6; cursor: default; }
        button:focus-visible { outline: 2px solid var(--cc-amber, #f2b544); outline-offset: 2px; }
        .secondary { background: transparent; color: var(--cc-text, #efe6d2); border: 1px solid var(--cc-line, #2e2412); }
        .secondary:hover { border-color: var(--cc-muted, #a0824a); }
        .primary { background: var(--cc-amber, #f2b544); color: var(--cc-bg, #0d0b08); border: 1px solid var(--cc-amber, #f2b544); }
        .primary:hover { background: var(--cc-amber-hover, #f6c76e); }
    </style>
```

Fallbackvärdena i `var()` gör att sidan blir rätt även om `cc-theme.css` inte kan laddas, till exempel i testet med tom `public_path`. Klassnamnen `.primary` och `.secondary` finns redan i markupen. **Rör ingenting i `<body>`.**

Kontrollera att endast `<head>` ändrats:

```bash
git diff -U0 resources/views/vendor/mcp/authorize.blade.php | grep '^[-+]' | grep -vE '^(\+\+\+|---)' | grep -nE 'form|csrf|method|name=|\{\{' || echo "inga ändringar i formulär"
```

Expected: endast `<link ... ThemeAsset::url(...)>`-raden får träffa (`{{`).

- [ ] **Step 4: Kör alla MCP-tester**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: PASS (alla, även `test_authorize_page_renders_without_vite_and_escapes_client_name` och `OAuthEndToEndTest`)

- [ ] **Step 5: Commit**

```bash
git add resources/views/vendor/mcp/authorize.blade.php tests/Feature/Mcp/StaffAccessTest.php
git commit -m "Fosfor: MCP-godkännandet i amber-terminal, formulären orörda"
```

---

### Task 8: Spelarytor, mail, städning och neon-svep

**Files:**
- Modify: `resources/views/interest/result.blade.php`, `resources/views/interest/unsubscribe.blade.php` (bara `<head>`/`<style>`)
- Modify (eventuellt): `resources/views/mail/interest-confirmation.blade.php`
- Delete: `resources/views/welcome.blade.php`
- Test: `tests/Feature/ThemeSweepTest.php`. Befintliga `InterestSignupTest` ska vara gröna.

- [ ] **Step 1: Skriv det fallerande svep-testet**

```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ThemeSweepTest extends TestCase
{
    private const FILER = [
        'public/css/cc-admin.css',
        'public/css/cc-login.css',
        'public/css/cc-theme.css',
        'public/js/cc-login.js',
        'public/js/cc-eggs.js',
        'resources/views/filament/pages/auth/login.blade.php',
        'resources/views/filament/resources/game-resource/pages/game-dev-kit.blade.php',
        'resources/views/vendor/mcp/authorize.blade.php',
        'resources/views/interest/result.blade.php',
        'resources/views/interest/unsubscribe.blade.php',
    ];

    private const FORBJUDET = ['Press Start', 'JetBrains', '#00f0ff', '#ff00aa', '#39ff14', '#0a0a1a', '#12122a',
        'rgba(0,240,255', 'rgba(57,255,20', 'fonts.googleapis.com', 'fonts.bunny.net'];

    public function test_inga_rester_av_neon_temat_eller_tredjepartstypsnitt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::FILER as $fil) {
            $innehall = file_get_contents("$root/$fil");
            foreach (self::FORBJUDET as $ord) {
                $this->assertStringNotContainsStringIgnoringCase($ord, $innehall, "$fil innehåller $ord");
            }
        }
    }

    public function test_den_oanvanda_welcome_vyn_ar_borta(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/welcome.blade.php');
    }

    public function test_intresse_sidorna_anvander_temats_typsnitt_med_ljusa_farger(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['result', 'unsubscribe'] as $vy) {
            $html = file_get_contents("$root/resources/views/interest/$vy.blade.php");
            $this->assertStringContainsString("ThemeAsset::url('css/cc-theme.css')", $html);
            $this->assertStringContainsString('#fbf7ee', $html);
            $this->assertStringContainsString('#8a5a00', $html);
        }
    }
}
```

- [ ] **Step 2: Kör testet och se det falla**

Run: `php artisan test --compact --filter=ThemeSweepTest`
Expected: FAIL (welcome finns, intresse-sidorna saknar temat)

- [ ] **Step 3: Intresse-sidorna.** Läs `unsubscribe.blade.php` först. I båda filerna ersätts `<style>` med följande, och länken läggs före det:

```blade
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-theme.css') }}">
    <style>
        /* Ljus variant av Fosfor för spelare. Typsnittet från cc-theme.css, färgerna lokala. */
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
               background: #fbf7ee; color: #2a1f0c; font: 18px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 460px; padding: 32px 24px; text-align: center; }
        h1 { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 600; font-size: 26px; line-height: 1.25;
             letter-spacing: .01em; margin: 0 0 12px; }
        h1::before { content: '> ' / ''; color: #8a5a00; }
        p { color: #2a1f0c; }
        .muted { color: #6b6150; }
        a { color: #8a5a00; font-weight: 700; text-underline-offset: 3px; }
        button { font: 600 15px/1 'IBM Plex Mono', ui-monospace, monospace; letter-spacing: .06em; background: #8a5a00;
                 color: #fbf7ee; border: 0; padding: 12px 20px; cursor: pointer; }
        button:hover { background: #6f4800; }
        button:focus-visible, a:focus-visible { outline: 2px solid #8a5a00; outline-offset: 3px; }
    </style>
```

Klasser som `unsubscribe.blade.php` redan använder och som saknas ovan behålls med de ljusa färgerna. **Rör ingenting i `<body>`.** Inget `@csrf` läggs till.

Kontrast: `#fbf7ee` på `#6f4800` (hover) ≈ 7.6:1.

- [ ] **Step 4: Mailet.** Kör skillen `naturlig-svenska` på texten i `interest-confirmation.blade.php`. Ändra bara det som skaver. Om skillen inte hittar något lämnas filen orörd. Variablerna `$sprak`, `$spel`, `$bekrafta` och `$avregistrera` ska stå kvar, och texterna som `InterestSignupTest` kontrollerar får inte ändras:

```bash
grep -n "assertSee\|assertSeeText\|InterestConfirmation" tests/Feature/Api/V1/InterestSignupTest.php
```

- [ ] **Step 5: Ta bort welcome**

```bash
grep -rn "welcome" routes app tests config bootstrap resources/views | grep -v "welcome back" | grep -v ThemeSweepTest || echo "inga referenser"
git rm resources/views/welcome.blade.php
```

- [ ] **Step 6: Kör testerna**

Run: `php artisan test --compact --filter='ThemeSweepTest|InterestSignupTest'`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A resources/views/interest resources/views/mail tests/Feature/ThemeSweepTest.php
git commit -m "Fosfor: ljus variant för intresse-sidorna, bort med oanvänd welcome-vy"
```

---

### Task 9: Hela sviten och verifiering i webbläsaren

**Files:** inga nya (bara fixar om något hittas, med test först)

- [ ] **Step 1: Hela sviten**

Run: `php artisan test --compact`
Expected: alla gröna. Är något rött: fixa det med ett test först och ny commit.

- [ ] **Step 2: Webbläsare** på `https://computercat-api-design-fosfor-tema.test` (Herd). Skapa en staff-användare lokalt via tinker om det behövs och spara id:t, så att den kan tas bort exakt efteråt.

Kontrollera och ta skärmdumpar:
1. **Login:** katten går, sover (Zzz), jagar flugan och kan dras och släppas (faller, landar och klättrar tillbaka).
2. **Misslyckad inloggning** med fel lösenord: felmeddelandet visas, det finns **en** katt (`document.querySelectorAll('#the-cat').length === 1`) och den fortsätter röra sig.
3. **Dashboard:** varmvit text, amber-accent, `> ` på aktivt menyval, "Användare" och "Översikt" i Plex Mono (DevTools → Computed → Rendered Fonts).
4. **Lista** (Spel): versala rubriker och streckade rader.
5. **Formulär** (redigera spel): primärknapp med mörk text på amber, och en danger-knapp (radera, men klicka inte) med mörk text.
6. **Dev-kit-sidan.**
7. **Easter eggs:** Konami (↑↑↓↓←→←→BA), skriv `meow`, skriv `nyan` och rör musen, klicka varumärket 7 gånger, och vänta 60 sekunder på idle-katten. Alla fem ska fungera och se ut enligt spec.
8. **Authorize-sidan** (via `OAuthEndToEndTest`-flödet eller en lokal auth request) och **intresse-sidorna** (`/api/v1/interest/...`, anmäl lokalt och öppna bekräftelselänken från mail-loggen).
9. Nätverksfliken: inga anrop till andra domäner än den lokala.

- [ ] **Step 3: Städa testdata** med de id:n du sparade, och inget annat.

- [ ] **Step 4: Commit** (om fixar gjordes) och därefter `completion-gate`.
