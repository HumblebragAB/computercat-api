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

    public function test_the_two_release_articles_exist(): void
    {
        $articles = $this->processArticles();

        $this->assertArrayHasKey('process-slappflode.md', $articles);
        $this->assertArrayHasKey('process-slapp-engangssteg.md', $articles);
    }

    public function test_every_next_state_and_its_exact_text_is_in_the_articles(): void
    {
        $text = $this->allText();

        foreach (ReleaseNext::TEMPLATES as $state => $template) {
            $this->assertStringContainsString("`{$state}`", $text, "Artikeln saknar tillståndet {$state}.");
            $this->assertStringContainsString($template, $text, "Artikelns text för {$state} skiljer sig från ReleaseNext.");
        }
    }

    public function test_every_setup_step_and_its_instruction_is_in_the_articles(): void
    {
        $text = $this->allText();

        foreach (SetupSteps::DEFINITIONS as $target => $steps) {
            foreach ($steps as $key => $instruction) {
                $this->assertStringContainsString("`{$key}`", $text, "Artikeln saknar engångssteget {$key} ({$target}).");
                $this->assertStringContainsString($instruction, $text, "Artikelns text för {$key} skiljer sig från SetupSteps.");
            }
        }
    }

    public function test_articles_mention_the_tools_and_the_approval_rule(): void
    {
        $text = $this->allText();

        foreach (['get_release_status', 'promote_release', 'set_release_targets', 'Filament'] as $needle) {
            $this->assertStringContainsString($needle, $text, $needle);
        }
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
