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

    public function test_silkscreen_ar_woff2_med_licens(): void
    {
        $f = $this->publicFile('fonts/silkscreen/silkscreen-latin-400-normal.woff2');
        $this->assertFileExists($f);
        $this->assertSame('wOF2', substr(file_get_contents($f), 0, 4));
        $this->assertStringContainsString('SIL Open Font License', file_get_contents($this->publicFile('fonts/silkscreen/OFL.txt')));
    }

    public function test_temat_definierar_font_face_och_alla_tokens(): void
    {
        $css = file_get_contents($this->publicFile('css/cc-theme.css'));

        $this->assertStringContainsString("font-family: 'IBM Plex Mono'", $css);
        $this->assertSame(4, substr_count($css, '@font-face'));
        $this->assertStringContainsString("font-family: 'Silkscreen'", $css);
        foreach (['--cc-ink: #111111', '--cc-paper: #fbfaf6', '--cc-desk: #e9e6df', '--cc-dot: #b9b4a8',
            '--cc-muted: #55524b', '--cc-line: #111111', "--cc-font: 'IBM Plex Mono'", "--cc-font-display: 'Silkscreen'"] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        foreach (['--cc-amber', '--cc-surface', '--cc-bg:', '--cc-text:', '--cc-danger', '--cc-ok', '--cc-warn'] as $gammal) {
            $this->assertStringNotContainsString($gammal, $css);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $css);
    }
}
