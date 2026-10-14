# Faktabanken, etapp A: implementationsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Spelens Claude kan lista, söka och läsa granskade faktaartiklar i Computercat via MCP-verktygen `list_facts`, `search_facts` och `get_fact`.

**Architecture:** Artiklar är Markdown med YAML-front matter i `resources/faktabank/<topic>/<id>.md`. `App\Services\FactBank` läser, validerar och söker (ingen databas). Tre `PlatformTool`-verktyg exponerar den via MCP. En ogiltig artikel fäller testsviten.

**Tech Stack:** Laravel 12, laravel/mcp 1.0.2, symfony/yaml 8, PHPUnit (SQLite i test).

**Spec:** `docs/superpowers/specs/2026-10-14-faktabank-design.md` (etapp A). Läs den före varje task.

## Global Constraints

- Ämnen, exakt: `stack`, `testing`, `security`, `practice`, `process`.
- `id`: `^[a-z0-9]+(?:-[a-z0-9]+)*\z`, högst 80 tecken, lika med filnamnet utan `.md`, unikt.
- `topic` lika med katalognamnet. `title` högst 80 tecken, `summary` högst 200, `tags` 1–8 strängar, `updated` citerad sträng `ÅÅÅÅ-MM-DD`, `build_guide` valfri lista med versioner i `BuildGuide::versions()`.
- Hela filen högst 12 000 tecken (`mb_strlen`).
- H2-rubrikerna utanför kodblock är exakt `Regel`, `Varför`, `Så gör du`, `Källa`, i den ordningen.
- YAML tolkas med `Yaml::parse($text, 0)` (inga objekt- eller PHP-taggar).
- Källrader med prefix `resources/`, `app/`, `routes/`, `config/`, `docs/` ska finnas i computercat-api; med prefix `src/`, `scripts/`, `test/` ska finnas i manifestet för varje version i artikelns `build_guide` (eller `BuildGuide::LATEST` om fältet saknas). Suffix `:rad`, `:rad-rad` och `#ankare` tas bort före kontrollen.
- Sökning: `mb_strtolower`, split på `/[^\p{L}\p{N}]+/u`, tokens med minst 2 tecken, unika frågeord; träff är exakt token eller prefix när frågeordet har minst 4 tecken; poäng 5 titel, 3 taggar, 2 sammanfattning, 1 brödtext; högst 10, sorterat på poäng fallande och sedan `id`.
- Verktygen ärver `PlatformTool`, bara läsning, svar under 50 000 tecken.
- Ingen artikel byggs av användarinnehåll. Etapp A:s artiklar gäller både v1 och v2 och refererar inte v2.
- Commit-meddelanden på svenska, utan Co-Authored-By.

## Review Focus

- En symlänk i `resources/faktabank` som pekar utanför katalogen: ska fälla laddningen, aldrig läsas (Task 1 test).
- Söktext med en hemlighet (t.ex. en GitHub-token som Claude klistrat in): ska redigeras i revisionsloggen (Task 3 test).
- Söktext med bara skiljetecken (`"--"`) eller bara ord kortare än 2 tecken: tomt resultat med `next`, inget fel (Task 2 och 3 test).
- `get_fact` med ett id som ser ut som en sökväg (`../../.env`, `stack/x`): valideringsfel före uppslag (Task 3 test).
- En artikel med ociterat datum eller en `## Regel` inuti ett kodblock: ociterat datum fäller, rubrik i kodblock räknas inte (Task 1 test).

---

### Task 1: `FactBank` läser och validerar artiklar

**Files:**
- Modify: `composer.json`, `composer.lock` (symfony/yaml från require-dev till require)
- Create: `app/Services/FactBank.php`, `app/Services/FactBankInvalid.php`
- Test: `tests/Unit/FactBankTest.php`

**Interfaces:**
- Produces:
  - `final class App\Services\FactBank`, konstruktor `__construct(?string $root = null)` (standard `resource_path('faktabank')`).
  - `public const TOPICS = ['stack', 'testing', 'security', 'practice', 'process'];`
  - `public const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';`
  - `public function all(?string $topic = null, ?string $buildGuide = null): array` lista av artiklar sorterade på `topic` och `id`.
  - `public function find(string $id): ?array`
  - En artikel är `array{id: string, title: string, topic: string, tags: list<string>, summary: string, updated: string, build_guide: ?list<string>, markdown: string}`.
  - `final class App\Services\FactBankInvalid extends \RuntimeException` (fel i en artikel; meddelandet nämner filen relativt `resources/faktabank`).

- [ ] **Steg 1: Flytta symfony/yaml till require.**

```bash
composer require symfony/yaml:^8.1
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p) if($p["name"]==="symfony/yaml") echo "require ",$p["version"],PHP_EOL;'
```

Förväntat: `require v8.1.x`.

- [ ] **Steg 2: Skriv testerna.** Testerna bygger artiklar i en temporär katalog och skickar katalogen till konstruktorn.

```php
<?php

namespace Tests\Unit;

use App\Services\FactBank;
use App\Services\FactBankInvalid;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FactBankTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/faktabank-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function article(string $topic, string $id, array $overrides = [], ?string $body = null): string
    {
        $front = array_merge([
            'id' => $id,
            'title' => 'Rubrik för '.$id,
            'topic' => $topic,
            'tags' => '[test, exempel]',
            'summary' => 'En kort sammanfattning.',
            'updated' => '"2026-10-14"',
        ], $overrides);
        $yaml = collect($front)->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");
        $body ??= "## Regel\nGör så.\n\n## Varför\nDärför.\n\n## Så gör du\nSå här.\n\n## Källa\n- app/Services/BuildGuide.php\n";
        File::ensureDirectoryExists("{$this->root}/{$topic}");
        $path = "{$this->root}/{$topic}/{$id}.md";
        File::put($path, "---\n{$yaml}\n---\n\n{$body}");

        return $path;
    }

    private function bank(): FactBank
    {
        return new FactBank($this->root);
    }

    private function assertInvalid(string $needle): void
    {
        try {
            $this->bank()->all();
            $this->fail('Ingen FactBankInvalid kastades.');
        } catch (FactBankInvalid $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_valid_articles_are_read_and_sorted(): void
    {
        $this->article('testing', 'b-artikel');
        $this->article('stack', 'a-artikel', ['build_guide' => '[v1]']);

        $all = $this->bank()->all();

        $this->assertSame(['a-artikel', 'b-artikel'], array_column($all, 'id'));
        $this->assertSame(['test', 'exempel'], $all[0]['tags']);
        $this->assertSame(['v1'], $all[0]['build_guide']);
        $this->assertNull($all[1]['build_guide']);
        $this->assertSame('2026-10-14', $all[0]['updated']);
        $this->assertStringStartsWith('## Regel', ltrim($all[0]['markdown']));
        $this->assertSame('a-artikel', $this->bank()->find('a-artikel')['id']);
        $this->assertNull($this->bank()->find('saknas'));
    }

    public function test_filters_by_topic_and_build_guide(): void
    {
        $this->article('stack', 'alla');
        $this->article('stack', 'bara-v1', ['build_guide' => '[v1]']);
        $this->article('testing', 'test-alla');

        $this->assertSame(['alla', 'bara-v1'], array_column($this->bank()->all('stack'), 'id'));
        $this->assertSame(['alla', 'bara-v1', 'test-alla'], array_column($this->bank()->all(null, 'v1'), 'id'));
        $this->assertSame(['alla', 'test-alla'], array_column($this->bank()->all(null, 'v2'), 'id'));
    }

    public function test_id_must_match_file_name_and_pattern(): void
    {
        $this->article('stack', 'ratt-namn', ['id' => 'annat-namn']);
        $this->assertInvalid('stack/ratt-namn.md');
    }

    public function test_id_pattern_is_enforced(): void
    {
        $this->article('stack', 'Versaler');
        $this->assertInvalid('id');
    }

    public function test_duplicate_id_in_two_topics_is_invalid(): void
    {
        $this->article('stack', 'samma');
        $this->article('testing', 'samma');
        $this->assertInvalid('samma');
    }

    public function test_topic_must_match_directory(): void
    {
        $this->article('stack', 'fel-amne', ['topic' => 'testing']);
        $this->assertInvalid('topic');
    }

    public function test_unknown_topic_directory_is_invalid(): void
    {
        File::makeDirectory($this->root.'/okant');
        File::put($this->root.'/okant/x.md', "---\nid: x\n---\n");
        $this->assertInvalid('okant');
    }

    public function test_unquoted_date_is_invalid(): void
    {
        $this->article('stack', 'datum', ['updated' => '2026-10-14']);
        $this->assertInvalid('updated');
    }

    public function test_field_limits(): void
    {
        $this->article('stack', 'lang-rubrik', ['title' => str_repeat('a', 81)]);
        $this->assertInvalid('title');
    }

    public function test_tags_must_be_one_to_eight_strings(): void
    {
        $this->article('stack', 'taggar', ['tags' => '[]']);
        $this->assertInvalid('tags');
    }

    public function test_unknown_build_guide_version_is_invalid(): void
    {
        $this->article('stack', 'version', ['build_guide' => '[v9]']);
        $this->assertInvalid('build_guide');
    }

    public function test_file_over_12000_characters_is_invalid(): void
    {
        $this->article('stack', 'stor', [], "## Regel\n".str_repeat('å', 12000)."\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        $this->assertInvalid('12000');
    }

    public function test_headings_must_be_exact_and_ordered(): void
    {
        $this->article('stack', 'rubriker', [], "## Varför\nx\n## Regel\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        $this->assertInvalid('rubrik');
    }

    public function test_heading_inside_code_block_does_not_count(): void
    {
        $this->article('stack', 'kodblock', [], "## Regel\n```markdown\n## Regel\n```\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");

        $this->assertSame(['kodblock'], array_column($this->bank()->all(), 'id'));
    }

    public function test_missing_front_matter_is_invalid(): void
    {
        File::ensureDirectoryExists($this->root.'/stack');
        File::put($this->root.'/stack/utan.md', "## Regel\nx\n");
        $this->assertInvalid('front matter');
    }

    // Utan PARSE_CONSTANT blir taggen null (verifierat med symfony/yaml 8.1); inget körs.
    public function test_yaml_php_tags_are_not_executed(): void
    {
        $this->article('stack', 'tagg', ['summary' => '!php/const PHP_VERSION']);
        $this->assertInvalid('summary');
    }

    public function test_source_in_api_repo_must_exist_with_suffix_stripped(): void
    {
        $this->article('stack', 'kalla-ok', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php:12-14\n- `app/Mcp/ComputercatServer.php#tools`\n");
        $this->assertSame(['kalla-ok'], array_column($this->bank()->all(), 'id'));

        $this->article('stack', 'kalla-fel', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Finns/Inte.php\n");
        $this->assertInvalid('app/Finns/Inte.php');
    }

    public function test_game_repo_source_must_be_in_the_build_guide_manifest(): void
    {
        $this->article('testing', 'spelkalla', ['build_guide' => '[v1]'], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- test/fakeFetch.ts\n");
        $this->assertSame(['spelkalla'], array_column($this->bank()->all(), 'id'));

        $this->article('testing', 'spelkalla-fel', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- src/finns/inte.ts\n");
        $this->assertInvalid('src/finns/inte.ts');
    }

    public function test_symlink_is_invalid(): void
    {
        File::ensureDirectoryExists($this->root.'/stack');
        $outside = sys_get_temp_dir().'/faktabank-utanfor-'.bin2hex(random_bytes(4)).'.md';
        File::put($outside, "---\nid: lank\n---\n");
        symlink($outside, $this->root.'/stack/lank.md');

        try {
            $this->assertInvalid('länk');
        } finally {
            @unlink($outside);
        }
    }
}
```

- [ ] **Steg 3: Kör testerna, förväntat FAIL** (`Class "App\Services\FactBank" not found`).

Run: `php artisan test --filter=FactBankTest`

- [ ] **Steg 4: Implementera.**

`app/Services/FactBankInvalid.php`:

```php
<?php

namespace App\Services;

// En faktaartikel bryter mot formatet (spec delprojekt 3, "Artikelformat").
final class FactBankInvalid extends \RuntimeException {}
```

`app/Services/FactBank.php`:

```php
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
            if ($topic === '.' || $topic === '..') {
                continue;
            }
            $dir = $rootReal.'/'.$topic;
            if (is_link($dir) || ! is_dir($dir) || ! in_array($topic, self::TOPICS, true)) {
                throw new FactBankInvalid("Okänt ämne eller oväntad fil i faktabanken: {$topic}");
            }
            foreach (scandir($dir) as $name) {
                if ($name === '.' || $name === '..') {
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
        $this->assertHeadings($rel, $body);
        $this->assertSources($rel, $body, $buildGuide ?? [BuildGuide::LATEST]);

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

    private function assertHeadings(string $rel, string $body): void
    {
        $withoutCode = (string) preg_replace('/^```.*?^```[^\n]*$/ms', '', $body);
        preg_match_all('/^## (.+?)\s*$/m', $withoutCode, $m);
        if ($m[1] !== self::HEADINGS) {
            throw new FactBankInvalid("{$rel}: rubrikerna ska vara exakt ## ".implode(', ## ', self::HEADINGS).' i den ordningen (hittade: '.implode(', ', $m[1]).').');
        }
    }

    /** @param list<string> $versions */
    private function assertSources(string $rel, string $body, array $versions): void
    {
        $section = substr($body, (int) strrpos($body, '## Källa'));
        preg_match_all('/^- +`?([^`\s]+)`?/m', $section, $m);

        foreach ($m[1] as $source) {
            $path = (string) preg_replace('/(#.*|:\d+(?:-\d+)?)$/', '', $source);
            if (array_filter(self::API_PREFIXES, fn ($p) => str_starts_with($path, $p)) !== []) {
                if (str_contains($path, '..') || ! is_file(base_path($path))) {
                    throw new FactBankInvalid("{$rel}: källan {$path} finns inte i computercat-api.");
                }
            } elseif (array_filter(self::GAME_PREFIXES, fn ($p) => str_starts_with($path, $p)) !== []) {
                foreach ($versions as $version) {
                    if (! in_array($path, array_column(BuildGuide::manifest($version)['files'], 'path'), true)) {
                        throw new FactBankInvalid("{$rel}: källan {$path} finns inte i byggguide {$version}.");
                    }
                }
            }
        }
    }
}
```

- [ ] **Steg 5: Kör testerna, förväntat PASS.** `php artisan test --filter=FactBankTest`. Kör sedan `vendor/bin/pint app/Services/FactBank.php app/Services/FactBankInvalid.php tests/Unit/FactBankTest.php`.

- [ ] **Steg 6: Commit.**

```bash
git add composer.json composer.lock app/Services/FactBank.php app/Services/FactBankInvalid.php tests/Unit/FactBankTest.php
git commit -m "Faktabanken: FactBank läser och validerar artiklar"
```

---

### Task 2: Sökning

**Files:**
- Modify: `app/Services/FactBank.php`
- Test: `tests/Unit/FactBankSearchTest.php`

**Interfaces:**
- Consumes: `FactBank` från Task 1.
- Produces:
  - `public static function tokens(string $text): list<string>` (kastar `\InvalidArgumentException` vid ogiltig UTF-8).
  - `public function search(string $query, ?string $topic = null, ?string $buildGuide = null): list<array>` där varje träff är artikeln plus `score: int`, högst 10.

- [ ] **Steg 1: Skriv testerna.**

```php
<?php

namespace Tests\Unit;

use App\Services\FactBank;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FactBankSearchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/faktabank-sok-'.bin2hex(random_bytes(6));
        $this->put('stack', 'nytt-beroende', 'Nytt npm-beroende', '[beroende, npm, paket]', 'Kräver motivering.', 'Skriv varför i commit-meddelandet.');
        $this->put('testing', 'spellogik', 'Testa spellogiken', '[vitest, enhetstest]', 'Ren logik testas utan nätverk.', 'Ett nytt beroende behövs inte för tester.');
        $this->put('security', 'hemligheter', 'Inga hemligheter i klienten', '[nycklar, env]', 'Bara slug och API-adress är publika.', 'check:security körs före push.');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function put(string $topic, string $id, string $title, string $tags, string $summary, string $body): void
    {
        File::ensureDirectoryExists("{$this->root}/{$topic}");
        File::put("{$this->root}/{$topic}/{$id}.md", "---\nid: {$id}\ntitle: {$title}\ntopic: {$topic}\ntags: {$tags}\nsummary: {$summary}\nupdated: \"2026-10-14\"\n---\n## Regel\n{$body}\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
    }

    private function ids(string $query, ?string $topic = null): array
    {
        return array_column((new FactBank($this->root))->search($query, $topic), 'id');
    }

    public function test_tokens_split_on_non_letters_and_keep_swedish_letters(): void
    {
        $this->assertSame(['npm', 'beroende', 'check', 'security', 'så', 'gör'], FactBank::tokens('npm-beroende check:security Så gör a'));
    }

    public function test_invalid_utf8_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FactBank::tokens("\xC3\x28");
    }

    public function test_title_outranks_body(): void
    {
        $results = (new FactBank($this->root))->search('beroende');

        $this->assertSame(['nytt-beroende', 'spellogik'], array_column($results, 'id'));
        $this->assertSame(5 + 3, $results[0]['score']);
        $this->assertSame(1, $results[1]['score']);
    }

    public function test_prefix_match_needs_four_characters(): void
    {
        $this->assertSame(['spellogik'], $this->ids('enhetstes'));
        $this->assertSame([], $this->ids('en'));
    }

    public function test_each_query_word_counts_once(): void
    {
        $once = (new FactBank($this->root))->search('npm')[0]['score'];
        $twice = (new FactBank($this->root))->search('npm npm NPM')[0]['score'];

        $this->assertSame($once, $twice);
    }

    public function test_punctuation_only_and_short_words_give_no_results(): void
    {
        $this->assertSame([], $this->ids('-- ::'));
        $this->assertSame([], $this->ids('a b'));
    }

    public function test_topic_filter_and_tie_order(): void
    {
        $this->assertSame(['nytt-beroende'], $this->ids('beroende', 'stack'));
        $this->put('stack', 'aaa-npm', 'npm', '[x]', 'y', 'z');
        $this->put('stack', 'zzz-npm', 'npm', '[x]', 'y', 'z');
        // nytt-beroende har npm i både rubrik och taggar (8); aaa och zzz bara i rubriken (5), ordnade på id.
        $this->assertSame(['nytt-beroende', 'aaa-npm', 'zzz-npm'], $this->ids('npm', 'stack'));
    }

    public function test_at_most_ten_results(): void
    {
        foreach (range(1, 12) as $i) {
            $this->put('practice', "mobil-{$i}", 'Mobil', '[mobil]', 'mobil', 'mobil');
        }

        $this->assertCount(10, $this->ids('mobil'));
    }
}
```

`test_title_outranks_body`: "beroende" träffar `nytt-beroende` i titel (5) och taggar (3), inte sammanfattningen; `spellogik` bara i brödtexten (1).

- [ ] **Steg 2: Kör, förväntat FAIL** (`Call to undefined method ...::search()`). `php artisan test --filter=FactBankSearchTest`

- [ ] **Steg 3: Implementera i `FactBank`.**

```php
    private const WEIGHTS = ['title' => 5, 'tags' => 3, 'summary' => 2, 'markdown' => 1];

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
            if ($token === $term || (mb_strlen($term) >= 4 && str_starts_with($token, $term))) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Steg 4: Kör `php artisan test --filter='FactBankSearchTest|FactBankTest'`, förväntat PASS.** Pint på ändrade filer.

- [ ] **Steg 5: Commit.**

```bash
git add app/Services/FactBank.php tests/Unit/FactBankSearchTest.php
git commit -m "Faktabanken: sökning med vikter för rubrik, taggar, sammanfattning och text"
```

---

### Task 3: MCP-verktygen och redigerad söktext i revisionsloggen

**Files:**
- Create: `app/Mcp/Tools/ListFactsTool.php`, `app/Mcp/Tools/SearchFactsTool.php`, `app/Mcp/Tools/GetFactTool.php`
- Modify: `app/Mcp/ComputercatServer.php` (lägg till de tre i `$tools` efter `GetBuildGuideFileTool::class`), `app/Mcp/AuditLogger.php` (`shorten()` redigerar hemligheter). Verktygen hämtar tjänsten med `app(FactBank::class)`; ingen bindning behövs.
- Test: `tests/Feature/Mcp/FactToolsTest.php`

**Interfaces:**
- Consumes: `FactBank::all()`, `find()`, `search()`, `TOPICS`, `ID_PATTERN` (Task 1–2); `BuildGuide::VERSION_PATTERN`; `PlatformTool`, `ToolHelpers::json()`, `validationFailed()`; `Redactor::scrubSecrets()`.
- Produces: verktygen `list_facts`, `search_facts`, `get_fact` med svarsformer enligt specen.

Testerna använder en egen faktabankskatalog: `$this->app->instance(FactBank::class, new FactBank($dir))`.

- [ ] **Steg 1: Skriv testerna.**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ComputercatServer;
use App\Mcp\Tools\ListFactsTool;
use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\FactBank;
use Illuminate\Support\Facades\File;

class FactToolsTest extends McpTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/faktabank-mcp-'.bin2hex(random_bytes(6));
        foreach ([['stack', 'nytt-beroende', 'Nytt npm-beroende', null], ['testing', 'spellogik', 'Testa spellogiken', '[v1]']] as [$topic, $id, $title, $bg]) {
            File::ensureDirectoryExists("{$this->root}/{$topic}");
            File::put("{$this->root}/{$topic}/{$id}.md", "---\nid: {$id}\ntitle: {$title}\ntopic: {$topic}\ntags: [npm, test]\nsummary: Sammanfattning av {$id}.\nupdated: \"2026-10-14\"\n".($bg ? "build_guide: {$bg}\n" : '')."---\n## Regel\nText om {$id}.\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        }
        $this->app->instance(FactBank::class, new FactBank($this->root));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function json(array $result): array
    {
        $this->assertTrue($result['ok'], $result['text']);

        return json_decode($result['text'], true);
    }

    public function test_list_facts_returns_topics_and_summaries(): void
    {
        $json = $this->json($this->callTool($this->staffMember(), 'list_facts'));

        $this->assertSame(FactBank::TOPICS, $json['topics']);
        $this->assertSame(['nytt-beroende', 'spellogik'], array_column($json['facts'], 'id'));
        $this->assertSame(['id', 'title', 'topic', 'summary', 'tags', 'build_guide'], array_keys($json['facts'][0]));
        $this->assertSame(['spellogik'], array_column($this->json($this->callTool($this->staffMember(), 'list_facts', ['topic' => 'testing']))['facts'], 'id'));
        $this->assertSame(['nytt-beroende'], array_column($this->json($this->callTool($this->staffMember(), 'list_facts', ['build_guide' => 'v2']))['facts'], 'id'));
    }

    public function test_search_facts_ranks_and_suggests_list_when_empty(): void
    {
        $json = $this->json($this->callTool($this->staffMember(), 'search_facts', ['query' => 'npm-beroende']));
        $this->assertSame('nytt-beroende', $json['results'][0]['id']);
        $this->assertArrayHasKey('score', $json['results'][0]);
        $this->assertArrayNotHasKey('markdown', $json['results'][0]);

        $empty = $this->json($this->callTool($this->staffMember(), 'search_facts', ['query' => '--']));
        $this->assertSame([], $empty['results']);
        $this->assertStringContainsString('list_facts', $empty['next']);
    }

    public function test_get_fact_returns_markdown_and_rejects_path_like_ids(): void
    {
        $json = $this->json($this->callTool($this->staffMember(), 'get_fact', ['id' => 'spellogik']));
        $this->assertSame(['v1'], $json['build_guide']);
        $this->assertStringContainsString('Text om spellogik.', $json['markdown']);

        foreach (['../../.env', 'stack/x', str_repeat('a', 81), 'Versaler'] as $bad) {
            $result = $this->callTool($this->staffMember(), 'get_fact', ['id' => $bad]);
            $this->assertFalse($result['ok'], $bad);
            $this->assertStringContainsString('Validation failed', $result['text']);
        }

        $unknown = $this->callTool($this->staffMember(), 'get_fact', ['id' => 'finns-inte']);
        $this->assertFalse($unknown['ok']);
        $this->assertStringContainsString('search_facts', $unknown['text']);
    }

    public function test_invalid_input_is_a_validation_error(): void
    {
        $staff = $this->staffMember();

        $this->assertFalse($this->callTool($staff, 'list_facts', ['topic' => 'okant'])['ok']);
        $this->assertFalse($this->callTool($staff, 'search_facts', ['query' => 'x'])['ok']);
        $this->assertFalse($this->callTool($staff, 'search_facts', ['query' => str_repeat('a', 201)])['ok']);
        $this->assertFalse($this->callTool($staff, 'search_facts', ['query' => 'npm', 'build_guide' => 'x'])['ok']);
    }

    public function test_non_staff_is_denied_and_logged(): void
    {
        $player = User::factory()->create();

        ComputercatServer::actingAs(StaffUser::findOrFail($player->id), 'mcp')
            ->tool(ListFactsTool::class, [])
            ->assertHasErrors(['Not allowed.']);

        $this->assertSame(['denied'], McpAuditLog::where('tool', 'list_facts')->pluck('outcome')->all());
    }

    public function test_secret_in_query_is_redacted_in_the_audit_log(): void
    {
        $token = 'ghp_'.str_repeat('A', 36);

        $this->callTool($this->staffMember(), 'search_facts', ['query' => "nyckel {$token}"]);

        $logged = McpAuditLog::where('tool', 'search_facts')->first()->arguments['query'];
        $this->assertStringNotContainsString($token, $logged);
        $this->assertStringContainsString('nyckel', $logged);
    }

}
```

Testet att svaren ryms under 50 000 tecken med den riktiga faktabanken läggs till i Task 4, när `resources/faktabank` finns.

- [ ] **Steg 2: Kör, förväntat FAIL** (verktygen finns inte; `tools/call` svarar med okänt verktyg). `php artisan test --filter=FactToolsTest`

- [ ] **Steg 3: Implementera.**

`app/Mcp/Tools/ListFactsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Faktabankens innehållsförteckning (spec delprojekt 3). Bara läsning.
class ListFactsTool extends PlatformTool
{
    protected string $name = 'list_facts';

    protected string $description = 'List the articles in the Computercat fact bank: how we build games (stack choices, tests, security checks before Demo, best practice, process from idea to Live). Use it to see what exists; then read an article with get_fact. Pass build_guide with the version in the game\'s CLAUDE.md (e.g. "v1") to get only articles that apply to that game. Articles override your own judgement; if nothing covers a big decision, ask a human.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(FactBank::TOPICS)->description('Optional topic filter.'),
            'build_guide' => $schema->string()->description('Optional, the game\'s build guide version, e.g. "v1".'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'topic' => ['sometimes', 'string', Rule::in(FactBank::TOPICS)],
            'build_guide' => ['sometimes', 'string', 'regex:'.BuildGuide::VERSION_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $facts = app(FactBank::class)->all($args['topic'] ?? null, $args['build_guide'] ?? null);

        return $this->json([
            'topics' => FactBank::TOPICS,
            'facts' => array_map(fn (array $f) => [
                'id' => $f['id'], 'title' => $f['title'], 'topic' => $f['topic'],
                'summary' => $f['summary'], 'tags' => $f['tags'], 'build_guide' => $f['build_guide'],
            ], $facts),
        ]);
    }
}
```

`app/Mcp/Tools/SearchFactsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BuildGuide;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Response;

// Sökning i faktabanken (spec delprojekt 3). Bara läsning.
class SearchFactsTool extends PlatformTool
{
    protected string $name = 'search_facts';

    protected string $description = 'Search the Computercat fact bank for how we build games. Ask before adding a dependency, before setting up leaderboards or anti-cheat, when a build or check fails, and before request_live. Returns up to 10 articles with id, title and summary; read one with get_fact. Pass build_guide with the version in the game\'s CLAUDE.md. Articles override your own judgement; if nothing covers a big decision, ask a human.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to search for, e.g. "nytt beroende" or "anti-cheat topplista".')->required(),
            'topic' => $schema->string()->enum(FactBank::TOPICS)->description('Optional topic filter.'),
            'build_guide' => $schema->string()->description('Optional, the game\'s build guide version, e.g. "v1".'),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'query' => ['required', 'string', 'min:2', 'max:200', function ($attribute, $value, $fail) {
                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $fail('The query must be valid UTF-8.');
                }
            }],
            'topic' => ['sometimes', 'string', Rule::in(FactBank::TOPICS)],
            'build_guide' => ['sometimes', 'string', 'regex:'.BuildGuide::VERSION_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $results = app(FactBank::class)->search($args['query'], $args['topic'] ?? null, $args['build_guide'] ?? null);
        $out = [
            'query' => $args['query'],
            'results' => array_map(fn (array $f) => [
                'id' => $f['id'], 'title' => $f['title'], 'topic' => $f['topic'],
                'summary' => $f['summary'], 'build_guide' => $f['build_guide'], 'score' => $f['score'],
            ], $results),
        ];
        if ($results === []) {
            $out['next'] = 'No matches. Run list_facts to see the topics and titles.';
        }

        return $this->json($out);
    }
}
```

`app/Mcp/Tools/GetFactTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FactBank;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Response;

// En artikel ur faktabanken (spec delprojekt 3). id slås upp i indexet, aldrig som sökväg.
class GetFactTool extends PlatformTool
{
    protected string $name = 'get_fact';

    protected string $description = 'Read one article from the Computercat fact bank (rule, why, how to do it, sources). Get ids from search_facts or list_facts. Follow the rule; if it does not fit the game, ask a human instead of deviating.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Article id, e.g. "stack-nytt-beroende".')->required(),
        ];
    }

    protected function run(array $args, User $user): Response
    {
        $validator = Validator::make($args, [
            'id' => ['required', 'string', 'max:80', 'regex:'.FactBank::ID_PATTERN],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $fact = app(FactBank::class)->find($args['id']);
        if ($fact === null) {
            return Response::error('No such article. Use search_facts or list_facts to find ids.');
        }

        return $this->json($fact);
    }
}
```

`app/Mcp/ComputercatServer.php`: lägg till efter `Tools\GetBuildGuideFileTool::class,`:

```php
        Tools\ListFactsTool::class,
        Tools\SearchFactsTool::class,
        Tools\GetFactTool::class,
```

`app/Mcp/AuditLogger.php`, i `shorten()` direkt efter UTF-8-kontrollen:

```php
        // Claude kan klistra in en nyckel i ett fritextfält (t.ex. search_facts query).
        $value = \App\Provisioning\Http\Redactor::scrubSecrets($value);
```

(Använd ett `use App\Provisioning\Http\Redactor;` överst i stället för fullt namn, enligt filens stil.)

- [ ] **Steg 4: Kör `php artisan test --filter='FactToolsTest|ToolInvariantsTest|PlatformToolTest|AuditLog'`, förväntat PASS.** `ToolInvariantsTest` kontrollerar att namnen är tillåtna, att verktygen ärver `PlatformTool` och att `tools/list` matchar.

- [ ] **Steg 5: Hela sviten** `php artisan test`, sedan pint på ändrade filer.

- [ ] **Steg 6: Commit.**

```bash
git add app/Mcp/Tools/ListFactsTool.php app/Mcp/Tools/SearchFactsTool.php app/Mcp/Tools/GetFactTool.php app/Mcp/ComputercatServer.php app/Mcp/AuditLogger.php tests/Feature/Mcp/FactToolsTest.php
git commit -m "MCP: list_facts, search_facts och get_fact; hemligheter redigeras i revisionsloggens argument"
```

---

### Task 4: Fröartiklar och innehållstest

**Files:**
- Create: `resources/faktabank/stack/stack-valordning.md`, `resources/faktabank/stack/stack-nytt-beroende.md`, `resources/faktabank/stack/stack-ingen-egen-backend.md`, `resources/faktabank/testing/testing-vad-som-testas.md`, `resources/faktabank/security/security-hemligheter-i-klienten.md`, `resources/faktabank/process/process-ide-till-live.md`
- Test: `tests/Feature/FactBankContentTest.php`; lägg till `test_responses_fit_below_50000_characters_with_the_real_bank` i `tests/Feature/Mcp/FactToolsTest.php`

**Interfaces:**
- Consumes: `FactBank` (validering), byggguide v1:s manifest (`BuildGuide::LATEST` är `v1`).

Artiklarna gäller både v1 och v2 och refererar inte v2. Inget `build_guide`-fält (gäller alla). Källor till spelrepot måste finnas i v1-manifestet.

- [ ] **Steg 1: Skriv testerna.**

`tests/Feature/FactBankContentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\FactBank;
use Tests\TestCase;

// Den riktiga faktabanken måste alltid gå att läsa: en trasig artikel fäller sviten.
class FactBankContentTest extends TestCase
{
    public function test_all_articles_are_valid(): void
    {
        $facts = (new FactBank)->all();

        $this->assertGreaterThanOrEqual(6, count($facts));
        foreach (['stack', 'testing', 'security', 'process'] as $topic) {
            $this->assertNotEmpty((new FactBank)->all($topic), $topic);
        }
    }

    public function test_seed_articles_apply_to_every_build_guide_version(): void
    {
        foreach ((new FactBank)->all() as $fact) {
            $this->assertNull($fact['build_guide'], $fact['id']);
            $this->assertStringNotContainsString('byggguide/v2', $fact['markdown'], $fact['id']);
        }
    }

    public function test_common_questions_find_the_right_article(): void
    {
        $bank = new FactBank;

        $this->assertSame('stack-nytt-beroende', $bank->search('lägga till npm-paket')[0]['id']);
        $this->assertSame('stack-ingen-egen-backend', $bank->search('egen backend databas')[0]['id']);
        $this->assertSame('testing-vad-som-testas', $bank->search('vilka tester')[0]['id']);
        $this->assertSame('security-hemligheter-i-klienten', $bank->search('api-nyckel hemlighet')[0]['id']);
        $this->assertSame('process-ide-till-live', $bank->search('request_live')[0]['id']);
    }
}
```

Lägg till i `FactToolsTest` (samma kod som i Task 3, nu när katalogen finns):

```php
    public function test_responses_fit_below_50000_characters_with_the_real_bank(): void
    {
        $this->app->instance(FactBank::class, new FactBank);
        $staff = $this->staffMember();

        $this->assertLessThan(50000, mb_strlen($this->callTool($staff, 'list_facts')['text']));
        foreach ((new FactBank)->all() as $fact) {
            $this->assertLessThan(50000, mb_strlen($this->callTool($staff, 'get_fact', ['id' => $fact['id']])['text']));
        }
    }
```

- [ ] **Steg 2: Kör, förväntat FAIL** (`Faktabanken saknas`). `php artisan test --filter='FactBankContentTest|FactToolsTest'`

- [ ] **Steg 3: Skriv artiklarna.** Innehållet nedan är utkastet som Erik granskar i PR:en. Ändra inte sakpåståendena utan att kontrollera källan.

`resources/faktabank/stack/stack-valordning.md`:

```markdown
---
id: stack-valordning
title: Ordningen för tekniska val
topic: stack
tags: [stack, val, teknik, bibliotek, lösning]
summary: Välj först det som finns i byggguiden, sedan Computercats byggstenar, och bara därefter något nytt med skriven motivering.
updated: "2026-10-14"
---

## Regel

När spelet behöver något tekniskt väljer du i den här ordningen:

1. Det som redan finns i byggguidens grundkod (Next.js med statisk export, React, zustand, Tailwind, vitest, Computercat-klienten i `src/lib/computercat/`).
2. Computercats byggstenar för allt som rör spelare, data eller tävling: anonym inloggning, topplistor, sparfiler, achievements, streaks, dagligt innehåll, remote config och spelhändelser. Hämta manualen med `get_integration_guide`.
3. Ett nytt npm-paket, bara om 1 och 2 inte räcker. Se artikeln `stack-nytt-beroende`.

## Varför

Varje nytt bibliotek är kod som ska hållas uppdaterad, granskas för sårbarheter och förstås av nästa person. Spelen delar stack så att en lösning i ett spel fungerar i nästa, och så att Computercat kan hålla ordning på spelare och topplistor på ett ställe.

## Så gör du

- Sök i faktabanken (`search_facts`) och i integrationsmanualen innan du väljer.
- Står ett behov inte i någon av dem och beslutet är stort (ny typ av data, ny extern tjänst): beskriv behovet och fråga en människa i stället för att välja själv.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- app/Services/IntegrationGuide.php
- src/lib/computercat/index.ts
```

`resources/faktabank/stack/stack-nytt-beroende.md`:

```markdown
---
id: stack-nytt-beroende
title: Nytt npm-beroende
topic: stack
tags: [beroende, npm, paket, bibliotek, installera]
summary: Ett nytt npm-paket kräver att inget i byggguiden eller Computercat redan löser behovet, och en skriven motivering i commit-meddelandet.
updated: "2026-10-14"
---

## Regel

Lägg bara till ett npm-paket när varken byggguidens grundkod eller Computercats byggstenar löser behovet. Motiveringen skrivs i commit-meddelandet: vilket behov, vilka alternativ du uteslöt och varför.

## Varför

Spelen byggs som statisk export och deployas automatiskt vid varje push. Ett paket med en sårbarhet eller ett installationsskript blir en del av allt som når spelarna. `npm audit --omit=dev --audit-level=high` måste vara utan träff före varje push.

## Så gör du

1. Kontrollera `stack-valordning` och `get_integration_guide`.
2. Välj ett välunderhållet paket utan installationsskript som behövs. Installationer körs alltid med `npm ci --ignore-scripts`.
3. Installera med exakt version, kör `npm run check` och `npm audit --omit=dev --audit-level=high`.
4. Skriv motiveringen i commit-meddelandet.
5. Tredjeparts-analytics, spårning, reklam och externa typsnitt eller CDN-skript kräver Eriks beslut. Fråga i stället för att lägga till.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- resources/byggguide/v1/files/package.json
```

`resources/faktabank/stack/stack-ingen-egen-backend.md`:

```markdown
---
id: stack-ingen-egen-backend
title: Ingen egen backend
topic: stack
tags: [backend, server, databas, api, firebase, supabase]
summary: Computercat är spelets enda backend. Bygg aldrig egna API-routes, databaser eller tjänster som Firebase eller Supabase.
updated: "2026-10-14"
---

## Regel

All serverlogik och all lagring går genom Computercat via klienten i `src/lib/computercat/`. Spelet har inga API-routes, ingen egen databas och inga tjänster som Firebase eller Supabase.

## Varför

Spelet är en statisk export utan server. Computercat har inloggning, topplistor med anti-cheat, sparfiler och spelardata på ett ställe, med samma säkerhet och övervakning för alla spel.

## Så gör du

- Konfigurera byggstenarna med connectorns verktyg (`get_game`, `upsert_leaderboard_type`, `upsert_achievement`, `upsert_content_pool`, `upsert_remote_config`, `upsert_game_event`).
- Behövs serverlogik som byggstenarna saknar: beskriv behovet och föreslå en PR mot computercat-api. Bygg inte runt det i spelet.

## Källa

- resources/byggguide/v1/files/CLAUDE.md
- app/Mcp/ComputercatServer.php
- src/lib/computercat/index.ts
```

`resources/faktabank/testing/testing-vad-som-testas.md`:

```markdown
---
id: testing-vad-som-testas
title: Vilka tester ett spel ska ha
topic: testing
tags: [tester, vitest, enhetstest, integrationstest, jsdom]
summary: Spellogik som enhetstester, Computercat-flöden med fakeFetch och ett jsdom-test från första drag till skickat resultat.
updated: "2026-10-14"
---

## Regel

Ett spel har minst:

1. Enhetstester för spellogiken (regler, poäng, tid, vinst och förlust) som rena funktioner utan nätverk.
2. Tester för det spelet skickar till Computercat (resultat, sparfiler, anti-cheat-bevis) med `test/fakeFetch.ts`, aldrig mot det riktiga API:t.
3. Ett integrationstest i jsdom som spelar en hel omgång och kontrollerar att resultatet köas och skickas.

`npm run check` ska vara grön före varje push.

## Varför

Demo deployas automatiskt vid varje push till main. Testerna är det som hindrar att en trasig regel eller ett resultat som aldrig skickas når spelarna.

## Så gör du

- Lägg spellogik i rena moduler (t.ex. `src/game/`) så att den går att testa utan React.
- Utgå från grundkodens tester för Computercat-klienten; ändra dem inte, de följer byggguiden.
- Skriv integrationstestet efter mönstret i `test/integration.test.tsx`: gör spelplanen liten så att en omgång går fort.
- Test behövs inte för ren layout eller text.

## Källa

- test/fakeFetch.ts
- test/integration.test.tsx
- src/lib/computercat/queue.ts
- resources/byggguide/v1/files/CLAUDE.md
```

`resources/faktabank/security/security-hemligheter-i-klienten.md`:

```markdown
---
id: security-hemligheter-i-klienten
title: Inga hemligheter i klienten
topic: security
tags: [hemlighet, nyckel, api-nyckel, env, token]
summary: Allt i spelet är publikt. Bara spelets slug och Computercats adress får ligga i NEXT_PUBLIC-variabler; nycklar hör aldrig hemma i spelrepot.
updated: "2026-10-14"
---

## Regel

Spelets kod och allt i `out/` kan läsas av vem som helst. Därför:

- Bara `NEXT_PUBLIC_COMPUTERCAT_GAME` och `NEXT_PUBLIC_COMPUTERCAT_URL` får användas som publika variabler.
- API-nycklar, tokens, lösenord och privata nycklar får aldrig finnas i repot, i `.env`-filer som committas eller i koden.
- Spelarens token från Computercat sparas bara av klienten i `src/lib/computercat/`.

## Varför

En nyckel i en statisk sida är publicerad i samma ögonblick som Demo byggs, och kan inte tas tillbaka. Computercat behöver ingen hemlighet i spelet: anonym inloggning ger varje spelare en egen token.

## Så gör du

- Behöver spelet en tjänst som kräver nyckel: det hör hemma i Computercat. Beskriv behovet och fråga en människa.
- Har en nyckel ändå hamnat i en commit: säg det direkt till en människa. Nyckeln måste bytas, att ta bort den ur koden räcker inte.

## Källa

- resources/byggguide/v1/files/dot.env.example
- src/lib/computercat/auth.ts
- src/lib/computercat/config.ts
```

`resources/faktabank/process/process-ide-till-live.md`:

```markdown
---
id: process-ide-till-live
title: Från idé till Demo och Live
topic: process
tags: [process, demo, live, request_live, provisionering, status]
summary: create_game ger repo och Demo, varje push till main bygger Demo, och request_live ber Erik om publicering. Följ upp med get_provisioning_status.
updated: "2026-10-14"
---

## Regel

1. `create_game` skapar repo, Demo-adress, CORS och övervakning. Vänta tills `get_provisioning_status` visar `succeeded`.
2. I spelrepot: följ `CLAUDE.md` steg 1 (grundkoden), sedan spelet. Varje push till main bygger Demo.
3. Följ upp med `get_provisioning_status` tills `demo.state` är `live`, och kontrollera Demo-adressen med basic auth.
4. När spelet är klart för riktiga spelare: `request_live`. Erik beslutar; inget går live automatiskt.

## Varför

Demo är skyddad med basic auth och syns inte för allmänheten. Live är ett beslut som en människa tar.

## Så gör du

- `demo.state` betyder: `awaiting_code` (ingen kod pushad än), `building`, `live`, `deploy_failed` (bygget föll; kör `npm run check` lokalt) och `deployed_not_answering`.
- Ett spel som inte ska finnas kvar arkiveras med `archive_game` (repot arkiveras, inget raderas).
- Fråga en människa när något faller två gånger på samma sätt, i stället för att försöka igen i oändlighet.

## Källa

- app/Mcp/Tools/CreateGameTool.php
- app/Mcp/Tools/GetProvisioningStatusTool.php
- app/Mcp/Tools/RequestLiveTool.php
- app/Mcp/Tools/ArchiveGameTool.php
```

Kontrollera före commit att varje sakpåstående stämmer med källan (t.ex. `demo.state`-värdena mot `GetProvisioningStatusTool.php`, verktygsnamnen mot `ComputercatServer.php`, publika variabler mot `dot.env.example` och `config.ts`). Avviker något: rätta artikeln, inte koden.

- [ ] **Steg 4: Kör `php artisan test --filter='FactBankContentTest|FactToolsTest'`, förväntat PASS.** Faller `test_common_questions_find_the_right_article`: justera artikelns `tags` (inte testet) tills rätt artikel kommer först.

- [ ] **Steg 5: Hela sviten** `php artisan test`.

- [ ] **Steg 6: Commit.**

```bash
git add resources/faktabank tests/Feature/FactBankContentTest.php tests/Feature/Mcp/FactToolsTest.php
git commit -m "Faktabanken: sex fröartiklar och innehållstest"
```

---

### Task 5: Svit, säkerhetsgranskning och completion-gate

- [ ] **Steg 1:** `vendor/bin/pint --test` på grenens ändrade PHP-filer (`git diff --name-only --diff-filter=AM main...HEAD -- '*.php' | xargs vendor/bin/pint --test`) och `php artisan test`. Allt grönt.
- [ ] **Steg 2:** Invokera `security-first-mindset` och kör dess review gate mot `git diff main...HEAD`. Läs särskilt: att `get_fact` aldrig bygger en sökväg av anroparens id, att symlänkar fäller, att YAML tolkas utan taggar, att söktext redigeras i revisionsloggen och att verktygen nekar icke-staff.
- [ ] **Steg 3:** Invokera `completion-gate`. Bevis: testsviten, verktygens svar mot den riktiga faktabanken (kör `list_facts` och `search_facts` via `callTool` i en tinker- eller testkörning och visa svaren), och att de sex artiklarna klarar valideringen.
- [ ] **Steg 4:** Committa eventuella fixar.

### Task 6: Deploy (kräver Eriks OK)

- [ ] **Steg 1:** Fråga Erik (AskUserQuestion): merge till main, push (Forge deployar, `composer install` hämtar symfony/yaml som produktionsberoende), `queue:restart`, röktest. Ingen migration. Rollback: `git revert` och push.
- [ ] **Steg 2:** Efter OK: `~/.claude/bin/wt-finish <worktree>`, kontrollera `git log origin/main..main` (bara grenens commits), `git push origin main`.
- [ ] **Steg 3:** Vänta tills `current/app/Services/FactBank.php` finns på servern, kör `queue:restart`, kontrollera `curl -s -o /dev/null -w '%{http_code}' https://api.computercat.co/api/v1/games` → 200 och på servern `php8.4 artisan tinker --execute="echo count((new App\Services\FactBank)->all());"` → 6.
- [ ] **Steg 4:** Kör en engångs-routine i molnmiljön Spelstudio med bara connectorn: "Använd search_facts för att ta reda på om du får lägga till ett npm-paket för animationer i ett Computercat-spel, läs artikeln med get_fact och svara med regeln och dess källor." Förväntat: routinen hittar `stack-nytt-beroende` och citerar regeln.
