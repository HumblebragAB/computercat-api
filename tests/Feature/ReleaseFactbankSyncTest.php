<?php

namespace Tests\Feature;

use App\Releases\ReleaseNext;
use App\Releases\SetupSteps;
use App\Services\FactBank;
use Tests\TestCase;

// Spec Del 1: "Texterna för next finns i en enda klass så att faktabankens artiklar och
// API:t inte glider isär; ett test jämför dem." Testet läser artikelfilerna direkt.
class ReleaseFactbankSyncTest extends TestCase
{
    private function processArticles(): array
    {
        $files = glob(resource_path('faktabank/process/*.md')) ?: [];
        $this->assertNotEmpty($files, 'resources/faktabank/process saknar artiklar; släppflödets artiklar ska finnas.');

        return array_combine(array_map('basename', $files), array_map('file_get_contents', $files));
    }

    private function allText(): string
    {
        return implode("\n", $this->processArticles());
    }

    // Varje artikel listar sina tillstånd/steg som rader "- `nyckel`: text". Raderna parsas
    // och jämförs hela, så att en förkortad mall, ett saknat eller omdöpt tillstånd, eller en
    // text under fel nyckel fäller testet. Dubbletter fäller också.
    private function bulletLines(string $file): array
    {
        $text = (string) file_get_contents(resource_path("faktabank/process/{$file}"));
        preg_match_all('/^- `([a-z_]+)`: (.*)$/m', $text, $m, PREG_SET_ORDER);

        $lines = [];
        foreach ($m as $row) {
            $this->assertArrayNotHasKey($row[1], $lines, "{$file}: nyckeln {$row[1]} står på flera rader.");
            $lines[$row[1]] = $row[2];
        }

        return $lines;
    }

    public function test_the_two_release_articles_exist(): void
    {
        $articles = $this->processArticles();

        $this->assertArrayHasKey('process-slappflode.md', $articles);
        $this->assertArrayHasKey('process-slapp-engangssteg.md', $articles);
    }

    public function test_next_states_match_the_article_line_by_line(): void
    {
        $lines = $this->bulletLines('process-slappflode.md');

        $this->assertEqualsCanonicalizing(array_keys(ReleaseNext::TEMPLATES), array_keys($lines), 'Tillstånden i artikeln och ReleaseNext::TEMPLATES skiljer sig.');
        foreach (ReleaseNext::TEMPLATES as $state => $template) {
            $this->assertSame($template, $lines[$state], "Artikelns rad för {$state} skiljer sig från ReleaseNext::TEMPLATES.");
        }
    }

    public function test_setup_steps_match_the_article_line_by_line(): void
    {
        $lines = $this->bulletLines('process-slapp-engangssteg.md');

        $steps = [];
        foreach (SetupSteps::DEFINITIONS as $target => $definitions) {
            foreach ($definitions as $key => $instruction) {
                $steps[$key] = $instruction;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($steps), array_keys($lines), 'Engångsstegen i artikeln och SetupSteps::DEFINITIONS skiljer sig.');
        foreach ($steps as $key => $instruction) {
            $this->assertSame($instruction, $lines[$key], "Artikelns rad för {$key} skiljer sig från SetupSteps::DEFINITIONS.");
        }
    }

    public function test_articles_mention_the_tools_and_the_approval_rule(): void
    {
        $text = $this->allText();

        foreach (['get_release_status', 'promote_release', 'set_release_targets', 'Filament'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
    }

    // Eriks beslut 2026-10-15: "Skicka till TestFlight" betyder samma sak i API:t, faktabanken och spelens CLAUDE.md.
    public function test_testflight_flow_is_verbatim_in_the_article_the_next_text_and_every_ios_build_guide(): void
    {
        $flow = ReleaseNext::TESTFLIGHT_FLOW;
        $line = '/^'.preg_quote($flow, '/').'$/m';

        $this->assertMatchesRegularExpression($line, (string) file_get_contents(resource_path('faktabank/process/process-slappflode.md')));
        $this->assertStringContainsString($flow, ReleaseNext::for('ready_for_testflight', ['commit' => 'x', 'last_version' => 'ingen']));

        // Låsta och olåsta versioner: v3-planen ska få fel innan låsningen, inte efter.
        // Loopen nedan är avsiktligt tom tills v3 (första med ios-block) låses; manifestlistan får aldrig vara tom.
        $manifests = glob(resource_path('byggguide/v*/manifest.json')) ?: [];
        $this->assertNotEmpty($manifests, 'Inga byggguide-manifest hittades; globben är fel.');
        foreach ($manifests as $manifest) {
            if (! isset(json_decode((string) file_get_contents($manifest), true)['ios'])) {
                continue;
            }
            $claude = dirname($manifest).'/files/CLAUDE.md';
            $this->assertFileExists($claude);
            $this->assertMatchesRegularExpression($line, (string) file_get_contents($claude), basename(dirname($manifest)).': CLAUDE.md saknar flödestexten ordagrant.');
        }
    }

    public function test_articles_name_the_version_rule_and_the_tag(): void
    {
        $text = (string) file_get_contents(resource_path('faktabank/process/process-slappflode.md'));

        foreach (['package.json', 'v<version>', 'byggnummer', 'ComputerCat intern', 'TestFlight'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
    }

    // Regel 3 i artikeln får inte lova senaste commit som standard: flödet anger commit_sha.
    public function test_the_article_does_not_promise_a_default_commit_for_testflight(): void
    {
        $text = (string) file_get_contents(resource_path('faktabank/process/process-slappflode.md'));

        $this->assertStringNotContainsString('som standard', $text);
        $this->assertMatchesRegularExpression('/^3\. .*`commit_sha`/m', $text);
    }

    // Den gamla artikeln får inte peka på det ersatta verktyget som sättet att gå live.
    public function test_the_old_go_live_article_points_to_the_release_tools(): void
    {
        $text = (string) file_get_contents(resource_path('faktabank/process/process-ide-till-live.md'));

        $this->assertStringContainsString('get_release_status', $text);
        $this->assertStringContainsString('promote_release', $text);
        $this->assertDoesNotMatchRegularExpression('/\d\. När spelet är klart för riktiga spelare: `request_live`/', $text);
    }

    // Faktabankens egen validering (front matter, rubriker, källrader) och sökningen.
    public function test_articles_pass_the_factbank_validation_and_are_searchable(): void
    {
        $bank = new FactBank;
        $ids = array_column($bank->all('process'), 'id');

        $this->assertContains('process-slappflode', $ids);
        $this->assertContains('process-slapp-engangssteg', $ids);
        $this->assertSame('process-slappflode', $bank->search('get_release_status promote_release')[0]['id']);
        // Sökningen som FactBankContentTest kräver får inte ha flyttat sig.
        $this->assertSame('process-ide-till-live', $bank->search('request_live')[0]['id']);
    }
}
