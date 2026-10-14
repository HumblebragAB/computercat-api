<?php

namespace App\Provisioning;

// Byggguidens README-mall har ett block mellan två markörrader som create_game
// fyller med spelets idé. Kontraktet ägs av byggguiden (templates/README.md, från 2b "Gränssnitt mot 2c:
// idé-markören i README.md") och vaktas av BuildGuideTest och, i varje spelrepo, test/docs.test.ts.
final class ReadmeIdea
{
    public const START = '<!-- computercat:idea:start -->';

    public const END = '<!-- computercat:idea:end -->';

    public static function insert(string $readme, string $idea): string
    {
        $start = strpos($readme, self::START);
        $end = strpos($readme, self::END);

        // Exakt en av varje, på egen rad, start före slut. Annars skrivs ingenting:
        // aldrig en tilläggning någon annanstans i filen.
        if (substr_count($readme, self::START) !== 1
            || substr_count($readme, self::END) !== 1
            || $end < $start
            || ! self::ownLine($readme, $start, self::START)
            || ! self::ownLine($readme, $end, self::END)) {
            throw new StepFailed('README.md lacks the idea markers (exactly one start and one end marker, each on its own line, start before end).');
        }

        // Idén får varken öppna eller stänga en HTML-kommentar (och därmed en markör).
        $safe = str_replace(['<!--', '-->'], ['&lt;!--', '--&gt;'], $idea);

        return substr($readme, 0, $start + strlen(self::START))."\n".$safe."\n".substr($readme, $end);
    }

    private static function ownLine(string $text, int $pos, string $marker): bool
    {
        $before = $pos === 0 || $text[$pos - 1] === "\n";
        $afterPos = $pos + strlen($marker);
        $after = $afterPos === strlen($text) || $text[$afterPos] === "\n";

        return $before && $after;
    }
}
