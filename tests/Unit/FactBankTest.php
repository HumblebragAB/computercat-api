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
        $this->assertInvalid('id följer inte');
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
        $this->assertInvalid('topic måste');
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
        $this->assertInvalid('updated ska');
    }

    public function test_field_limits(): void
    {
        $this->article('stack', 'lang-rubrik', ['title' => str_repeat('a', 81)]);
        $this->assertInvalid('title saknas');
    }

    public function test_tags_must_be_one_to_eight_strings(): void
    {
        $this->article('stack', 'taggar', ['tags' => '[]']);
        $this->assertInvalid('tags ska');
    }

    public function test_unknown_build_guide_version_is_invalid(): void
    {
        $this->article('stack', 'version', ['build_guide' => '[v9]']);
        $this->assertInvalid('build_guide ska');
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

    public function test_tilde_fence_and_unclosed_fence(): void
    {
        $this->article('stack', 'tilde', [], "## Regel\n~~~\n## Varför\n~~~\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        $this->assertSame(['tilde'], array_column($this->bank()->all(), 'id'));

        $this->article('stack', 'ostangt', [], "## Regel\n```\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        $this->assertInvalid('rubrikerna');
    }

    public function test_source_section_in_code_block_is_ignored(): void
    {
        $this->article('stack', 'kod-kalla', [], "## Regel\n```\n## Källa\n- app/Finns/Inte.php\n```\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app/Services/BuildGuide.php\n");
        $this->assertSame(['kod-kalla'], array_column($this->bank()->all(), 'id'));
    }

    public function test_unknown_source_prefix_and_empty_sources_are_invalid(): void
    {
        $this->article('stack', 'okant-prefix', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- app2/finns/inte.php\n");
        $this->assertInvalid('okänt prefix');
    }

    public function test_empty_source_section_is_invalid(): void
    {
        $this->article('stack', 'utan-kalla', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\nIngen.\n");
        $this->assertInvalid('minst en rad');
    }

    public function test_https_source_and_ds_store_are_accepted(): void
    {
        $this->article('practice', 'lank-kalla', [], "## Regel\nx\n## Varför\nx\n## Så gör du\nx\n## Källa\n- https://developer.mozilla.org/\n");
        File::put($this->root.'/practice/.DS_Store', 'x');
        File::put($this->root.'/.DS_Store', 'x');
        $this->assertSame(['lank-kalla'], array_column($this->bank()->all(), 'id'));
    }

    public function test_symlinked_directory_is_invalid(): void
    {
        $outside = sys_get_temp_dir().'/faktabank-katalog-'.bin2hex(random_bytes(4));
        File::makeDirectory($outside);
        symlink($outside, $this->root.'/stack');

        try {
            $this->assertInvalid('stack');
        } finally {
            File::deleteDirectory($outside);
        }
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
        $this->assertInvalid('summary saknas');
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
