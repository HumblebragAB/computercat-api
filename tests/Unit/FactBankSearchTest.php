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
        $this->makeArticle('stack', 'nytt-beroende', 'Nytt npm-beroende', '[beroende, npm, paket]', 'Kräver motivering.', 'Skriv varför i commit-meddelandet.');
        $this->makeArticle('testing', 'spellogik', 'Testa spellogiken', '[vitest, enhetstest]', 'Ren logik testas utan nätverk.', 'Ett nytt beroende behövs inte för tester.');
        $this->makeArticle('security', 'hemligheter', 'Inga hemligheter i klienten', '[nycklar, env]', 'Bara slug och API-adress är publika.', 'check:security körs före push.');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function makeArticle(string $topic, string $id, string $title, string $tags, string $summary, string $body): void
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

    // Prefix åt båda hållen när båda orden har minst 4 tecken: "beroenden" träffar "beroende".
    public function test_prefix_match_both_ways_needs_four_characters(): void
    {
        $this->assertSame(['spellogik'], $this->ids('enhetstes'));
        $this->assertSame(['nytt-beroende', 'spellogik'], $this->ids('beroenden'));
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
        $this->makeArticle('stack', 'aaa-npm', 'npm', '[x]', 'y', 'z');
        $this->makeArticle('stack', 'zzz-npm', 'npm', '[x]', 'y', 'z');
        // nytt-beroende har npm i både rubrik och taggar (8); aaa och zzz bara i rubriken (5), ordnade på id.
        $this->assertSame(['nytt-beroende', 'aaa-npm', 'zzz-npm'], $this->ids('npm', 'stack'));
    }

    public function test_at_most_ten_results(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeArticle('practice', "mobil-{$i}", 'Mobil', '[mobil]', 'mobil', 'mobil');
        }

        $this->assertCount(10, $this->ids('mobil'));
    }
}
