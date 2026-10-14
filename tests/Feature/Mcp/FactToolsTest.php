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

    private function decode(array $result): array
    {
        $this->assertTrue($result['ok'], $result['text']);

        return json_decode($result['text'], true);
    }

    public function test_list_facts_returns_topics_and_summaries(): void
    {
        $json = $this->decode($this->callTool($this->staffMember(), 'list_facts'));

        $this->assertSame(FactBank::TOPICS, $json['topics']);
        $this->assertSame(['nytt-beroende', 'spellogik'], array_column($json['facts'], 'id'));
        $this->assertSame(['id', 'title', 'topic', 'summary', 'tags', 'build_guide'], array_keys($json['facts'][0]));
        $this->assertSame(['spellogik'], array_column($this->decode($this->callTool($this->staffMember(), 'list_facts', ['topic' => 'testing']))['facts'], 'id'));
        $this->assertSame(['nytt-beroende'], array_column($this->decode($this->callTool($this->staffMember(), 'list_facts', ['build_guide' => 'v2']))['facts'], 'id'));
    }

    public function test_search_facts_ranks_and_suggests_list_when_empty(): void
    {
        $json = $this->decode($this->callTool($this->staffMember(), 'search_facts', ['query' => 'npm-beroende']));
        $this->assertSame('nytt-beroende', $json['results'][0]['id']);
        $this->assertArrayHasKey('score', $json['results'][0]);
        $this->assertArrayNotHasKey('markdown', $json['results'][0]);

        $empty = $this->decode($this->callTool($this->staffMember(), 'search_facts', ['query' => '--']));
        $this->assertSame([], $empty['results']);
        $this->assertStringContainsString('list_facts', $empty['next']);
    }

    public function test_get_fact_returns_markdown_and_rejects_path_like_ids(): void
    {
        $json = $this->decode($this->callTool($this->staffMember(), 'get_fact', ['id' => 'spellogik']));
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
        // Många MCP-klienter skickar null för valfria fält.
        $this->assertTrue($this->callTool($staff, 'search_facts', ['query' => 'npm', 'topic' => null, 'build_guide' => null])['ok']);
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
        // Byggs vid körning så att push protection och gitleaks inte stoppar committen.
        foreach (['ghp_'.str_repeat('A', 36), 'sk_'.'live_'.str_repeat('a', 24), 'AKIA'.str_repeat('B', 16)] as $i => $token) {
            $this->callTool($this->staffMember(), 'search_facts', ['query' => "nyckel {$token}"]);

            $logged = McpAuditLog::where('tool', 'search_facts')->orderByDesc('id')->first()->arguments['query'];
            $this->assertStringNotContainsString($token, $logged);
            $this->assertStringContainsString('nyckel', $logged);
        }
    }
}
