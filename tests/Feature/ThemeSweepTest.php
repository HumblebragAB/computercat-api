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
        'rgba(0,240,255', 'rgba(57,255,20', 'fonts.googleapis.com', 'fonts.bunny.net',
        // Fosfor (amber) ersattes av System 1.
        '#f2b544', '#0d0b08', '#15110a', '#efe6d2', '#a0824a', 'rgba(242,181,68', 'rgba(13,11,8', '--cc-amber', '--cc-surface'];

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

    public function test_fargklasser_i_egna_filament_vyer_finns_i_den_forkompilerade_css_en(): void
    {
        // Prod bygger ingen Tailwind: en färgklass som saknas i Filaments app.css gör
        // ingenting, och då slår ljuslägets färg igenom i mörkt läge (t.ex. 2.7:1).
        $root = dirname(__DIR__, 2);
        $css = file_get_contents("$root/public/css/filament/filament/app.css");
        $vyer = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/resources/views/filament"));
        $saknas = [];
        foreach ($vyer as $fil) {
            if (! str_ends_with($fil->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('#(?<![\w:-])((?:dark:)?(?:text|bg|border|ring|divide)-(?:gray|primary|danger|warning|success|info|white|black)(?:-\d{2,3})?(?:/\d+)?)(?![\w-])#', file_get_contents($fil), $m);
            foreach (array_unique($m[1]) as $klass) {
                $selektor = '.'.str_replace([':', '/'], ['\\:', '\\/'], $klass);
                if (! preg_match('#'.preg_quote($selektor, '#').'(?![\w\\\\/-])#', $css)) {
                    $saknas[] = $fil->getFilename().": $klass";
                }
            }
        }
        $this->assertSame([], $saknas);
    }

    public function test_felmeddelanden_har_varningstecken_och_login_css_ar_ren(): void
    {
        $root = dirname(__DIR__, 2);
        $admin = file_get_contents("$root/public/css/cc-admin.css");
        $login = file_get_contents("$root/public/css/cc-login.css");

        // 1-bit har inget rött: felmeddelanden markeras med ett varningstecken som skärmläsare hoppar över.
        $this->assertMatchesRegularExpression("#\\.fi-fo-field-wrp-error-message::before\\s*\\{[^}]*content:[^;]*/\\s*''#u", $admin);
        // Placeholder med opacity sänker kontrasten; fi-btn-primary finns inte i Filament v3.
        $this->assertStringNotContainsString('.fi-btn-primary', $login);
        $this->assertDoesNotMatchRegularExpression('#::placeholder[^}]*opacity#', $login);
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
            $this->assertStringContainsString('#fbfaf6', $html);
            $this->assertStringContainsString('#111111', $html);
            $this->assertStringContainsString("'Silkscreen'", $html);
            $this->assertStringNotContainsString('#8a5a00', $html);
        }
    }
}
