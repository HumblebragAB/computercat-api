<?php

namespace App\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

// Faktabanken för spelens Claude (spec delprojekt 3). Artiklarna är PR-granskad
// Markdown i resources/faktabank/<topic>/<id>.md; inget kommer från användare.
// En ogiltig artikel är ett fel, aldrig en tyst överhoppning.
final class FactBank
{
    public const TOPICS = ['stack', 'testing', 'security', 'practice', 'process'];

    public const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private const HEADINGS = ['Regel', 'Varför', 'Så gör du', 'Källa'];

    private const MAX_CHARS = 12000;

    private const API_PREFIXES = ['resources/', 'app/', 'routes/', 'config/', 'docs/'];

    private const GAME_PREFIXES = ['src/', 'scripts/', 'test/'];

    private const WEIGHTS = ['title' => 5, 'tags' => 3, 'summary' => 2, 'markdown' => 1];

    private string $root;

    /** @var list<array>|null */
    private ?array $facts = null;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? resource_path('faktabank');
    }

    /** @return list<array> */
    public function all(?string $topic = null, ?string $buildGuide = null): array
    {
        return array_values(array_filter($this->load(), fn (array $f) => ($topic === null || $f['topic'] === $topic)
            && ($buildGuide === null || $f['build_guide'] === null || in_array($buildGuide, $f['build_guide'], true))));
    }

    public function find(string $id): ?array
    {
        foreach ($this->load() as $fact) {
            if ($fact['id'] === $id) {
                return $fact;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Texten är inte giltig UTF-8.');
        }
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            throw new \InvalidArgumentException('Texten kunde inte delas i ord.');
        }

        return array_values(array_unique(array_filter($parts, fn (string $t) => mb_strlen($t) >= 2)));
    }

    /** @return list<array> */
    public function search(string $query, ?string $topic = null, ?string $buildGuide = null): array
    {
        $terms = self::tokens($query);
        if ($terms === []) {
            return [];
        }

        $results = [];
        foreach ($this->all($topic, $buildGuide) as $fact) {
            $fields = [
                'title' => self::tokens($fact['title']),
                'tags' => self::tokens(implode(' ', $fact['tags'])),
                'summary' => self::tokens($fact['summary']),
                'markdown' => self::tokens($fact['markdown']),
            ];
            $score = 0;
            foreach ($terms as $term) {
                foreach (self::WEIGHTS as $field => $weight) {
                    if (self::matches($term, $fields[$field])) {
                        $score += $weight;
                    }
                }
            }
            if ($score > 0) {
                $results[] = $fact + ['score' => $score];
            }
        }

        usort($results, fn (array $a, array $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        return array_slice($results, 0, 10);
    }

    /** @param list<string> $tokens */
    private static function matches(string $term, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === $term || (mb_strlen($term) >= 4 && mb_strlen($token) >= 4
                && (str_starts_with($token, $term) || str_starts_with($term, $token)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array> */
    private function load(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }

        $rootReal = realpath($this->root);
        if ($rootReal === false || ! is_dir($rootReal)) {
            throw new FactBankInvalid("Faktabanken saknas: {$this->root}");
        }

        $facts = [];
        foreach (scandir($rootReal) as $topic) {
            if ($topic === '.' || $topic === '..' || $topic === '.DS_Store') {
                continue;
            }
            $dir = $rootReal.'/'.$topic;
            if (is_link($dir) || ! is_dir($dir) || ! in_array($topic, self::TOPICS, true)) {
                throw new FactBankInvalid("Okänt ämne eller oväntad fil i faktabanken: {$topic}");
            }
            foreach (scandir($dir) as $name) {
                // .DS_Store skapas av Finder på macOS och är aldrig en artikel.
                if ($name === '.' || $name === '..' || $name === '.DS_Store') {
                    continue;
                }
                $facts[] = $this->parse($rootReal, $topic, $name);
            }
        }

        $ids = array_column($facts, 'id');
        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));
        if ($duplicates !== []) {
            throw new FactBankInvalid('Samma id i flera artiklar: '.implode(', ', $duplicates));
        }

        usort($facts, fn (array $a, array $b) => [$a['topic'], $a['id']] <=> [$b['topic'], $b['id']]);

        return $this->facts = $facts;
    }

    private function parse(string $rootReal, string $topic, string $name): array
    {
        $rel = "{$topic}/{$name}";
        $file = "{$rootReal}/{$rel}";
        $real = realpath($file);
        if (is_link($file) || $real === false || ! str_starts_with($real, $rootReal.'/') || ! is_file($real)) {
            throw new FactBankInvalid("{$rel}: symbolisk länk eller utanför faktabanken.");
        }
        if (! str_ends_with($name, '.md')) {
            throw new FactBankInvalid("{$rel}: bara .md-filer får ligga i faktabanken.");
        }

        $text = (string) file_get_contents($real);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new FactBankInvalid("{$rel}: inte giltig UTF-8.");
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new FactBankInvalid("{$rel}: längre än 12000 tecken.");
        }
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', str_replace("\r\n", "\n", $text), $m) !== 1) {
            throw new FactBankInvalid("{$rel}: saknar front matter mellan två rader med ---.");
        }

        try {
            $meta = Yaml::parse($m[1], 0);
        } catch (ParseException $e) {
            throw new FactBankInvalid("{$rel}: ogiltig YAML ({$e->getMessage()}).");
        }
        if (! is_array($meta)) {
            throw new FactBankInvalid("{$rel}: front matter är inte en mappning.");
        }

        $id = substr($name, 0, -3);
        $fail = fn (string $field, string $why) => throw new FactBankInvalid("{$rel}: {$field} {$why}.");

        if (($meta['id'] ?? null) !== $id) {
            $fail('id', 'måste vara lika med filnamnet');
        }
        if (strlen($id) > 80 || preg_match(self::ID_PATTERN, $id) !== 1) {
            $fail('id', 'följer inte mönstret '.self::ID_PATTERN);
        }
        if (($meta['topic'] ?? null) !== $topic) {
            $fail('topic', 'måste vara lika med katalogen');
        }
        $title = $meta['title'] ?? null;
        if (! is_string($title) || $title === '' || mb_strlen($title) > 80) {
            $fail('title', 'saknas eller är längre än 80 tecken');
        }
        $summary = $meta['summary'] ?? null;
        if (! is_string($summary) || $summary === '' || mb_strlen($summary) > 200) {
            $fail('summary', 'saknas eller är längre än 200 tecken');
        }
        $tags = $meta['tags'] ?? null;
        if (! is_array($tags) || ! array_is_list($tags) || count($tags) < 1 || count($tags) > 8
            || array_filter($tags, fn ($t) => ! is_string($t) || $t === '' || mb_strlen($t) > 40) !== []) {
            $fail('tags', 'ska vara 1–8 korta strängar');
        }
        $updated = $meta['updated'] ?? null;
        if (! is_string($updated) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $updated) !== 1) {
            $fail('updated', 'ska vara en citerad sträng "ÅÅÅÅ-MM-DD"');
        }
        $buildGuide = $meta['build_guide'] ?? null;
        if ($buildGuide !== null && (! is_array($buildGuide) || ! array_is_list($buildGuide) || $buildGuide === []
            || array_diff($buildGuide, BuildGuide::versions()) !== [])) {
            $fail('build_guide', 'ska vara en lista med versioner som finns ('.implode(', ', BuildGuide::versions()).')');
        }

        $body = $m[2];
        $prose = self::withoutCode($body);
        $this->assertHeadings($rel, $prose);
        $this->assertSources($rel, $prose, $buildGuide ?? [BuildGuide::LATEST]);

        return [
            'id' => $id,
            'title' => $title,
            'topic' => $topic,
            'tags' => $tags,
            'summary' => $summary,
            'updated' => $updated,
            'build_guide' => $buildGuide,
            'markdown' => $body,
        ];
    }

    // Tar bort kodblock (``` och ~~~). Ett ostängt staket gör resten av texten till kod,
    // så att en rubrik efter det aldrig räknas och artikeln fäller i rubrikkontrollen.
    private static function withoutCode(string $body): string
    {
        $out = [];
        $fence = null;
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^(```|~~~)/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1];

                    continue;
                }
                if ($fence === $m[1]) {
                    $fence = null;

                    continue;
                }
            }
            if ($fence === null) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private function assertHeadings(string $rel, string $text): void
    {
        preg_match_all('/^## (.+?)\s*$/m', $text, $m);
        if ($m[1] !== self::HEADINGS) {
            throw new FactBankInvalid("{$rel}: rubrikerna ska vara exakt ## ".implode(', ## ', self::HEADINGS).' i den ordningen (hittade: '.implode(', ', $m[1]).').');
        }
    }

    /** @param list<string> $versions */
    private function assertSources(string $rel, string $text, array $versions): void
    {
        // $text är utan kodblock och har exakt en "## Källa", sist (assertHeadings körs först).
        $section = substr($text, (int) strpos($text, "## Källa\n"));
        preg_match_all('/^- +`?([^`\s]+)`?/m', $section, $m);
        if ($m[1] === []) {
            throw new FactBankInvalid("{$rel}: ## Källa måste ha minst en rad som börjar med \"- \".");
        }

        foreach ($m[1] as $source) {
            if (str_starts_with($source, 'https://')) {
                continue;
            }
            $path = (string) preg_replace('/(#.*|:\d+(?:-\d+)?)$/', '', $source);
            if (array_filter(self::API_PREFIXES, fn ($p) => str_starts_with($path, $p)) !== []) {
                if (str_contains($path, '..') || ! is_file(base_path($path))) {
                    throw new FactBankInvalid("{$rel}: källan \"{$path}\" finns inte i computercat-api (skriv sökvägen utan avslutande skiljetecken).");
                }
            } elseif (array_filter(self::GAME_PREFIXES, fn ($p) => str_starts_with($path, $p)) !== []) {
                foreach ($versions as $version) {
                    if (! in_array($path, array_column(BuildGuide::manifest($version)['files'], 'path'), true)) {
                        throw new FactBankInvalid("{$rel}: källan \"{$path}\" finns inte i byggguide {$version}.");
                    }
                }
            } else {
                throw new FactBankInvalid("{$rel}: källan \"{$source}\" har okänt prefix (tillåtet: ".implode(', ', [...self::API_PREFIXES, ...self::GAME_PREFIXES]).' eller https://).');
            }
        }
    }
}
