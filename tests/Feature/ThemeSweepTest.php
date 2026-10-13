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
