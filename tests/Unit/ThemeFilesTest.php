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
