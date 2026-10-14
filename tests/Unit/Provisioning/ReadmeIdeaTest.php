<?php

namespace Tests\Unit\Provisioning;

use App\Provisioning\ReadmeIdea;
use App\Provisioning\StepFailed;
use PHPUnit\Framework\TestCase;

class ReadmeIdeaTest extends TestCase
{
    // Kontraktet med byggguidens README-mall (resources/byggguide/<version>/templates/README.md,
    // ursprungligen 2b "Gränssnitt mot 2c"). Ändras här bara samtidigt som i en ny guideversion.
    private const README = "# Spel\n\n<!-- computercat:idea:start -->\n_Idén fylls i av create_game när spelet skapas._\n<!-- computercat:idea:end -->\n\nSlut.\n";

    public function test_markers_match_the_readme_template_contract(): void
    {
        $this->assertSame('<!-- computercat:idea:start -->', ReadmeIdea::START);
        $this->assertSame('<!-- computercat:idea:end -->', ReadmeIdea::END);
    }

    public function test_replaces_block_and_keeps_the_rest(): void
    {
        $out = ReadmeIdea::insert(self::README, 'Minröj med katter.');

        $this->assertSame("# Spel\n\n<!-- computercat:idea:start -->\nMinröj med katter.\n<!-- computercat:idea:end -->\n\nSlut.\n", $out);
    }

    public function test_is_idempotent(): void
    {
        $once = ReadmeIdea::insert(self::README, 'Idé');

        $this->assertSame($once, ReadmeIdea::insert($once, 'Idé'));
    }

    public function test_html_comments_in_the_idea_are_escaped(): void
    {
        $out = ReadmeIdea::insert(self::README, 'A <!-- computercat:idea:end --> B <!-- x -->');

        $this->assertStringContainsString("\nA &lt;!-- computercat:idea:end --&gt; B &lt;!-- x --&gt;\n", $out);
        $this->assertSame(1, substr_count($out, ReadmeIdea::START));
        $this->assertSame(1, substr_count($out, ReadmeIdea::END));
    }

    /** @return array<string, array{string}> */
    public static function brokenReadmes(): array
    {
        return [
            'saknar markörer' => ["# Ingen markör\n"],
            'saknar slut' => ["<!-- computercat:idea:start -->\nx\n"],
            'dubbel start' => ["<!-- computercat:idea:start -->\n<!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n"],
            'dubbelt slut' => ["<!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n<!-- computercat:idea:end -->\n"],
            'slut före start' => ["<!-- computercat:idea:end -->\nx\n<!-- computercat:idea:start -->\n"],
            'inte på egen rad' => ["Idé: <!-- computercat:idea:start -->\nx\n<!-- computercat:idea:end -->\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenReadmes')]
    public function test_broken_markers_fail_loudly(string $readme): void
    {
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('README.md lacks the idea markers');
        ReadmeIdea::insert($readme, 'Idé');
    }
}
