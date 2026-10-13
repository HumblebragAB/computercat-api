<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ComputercatServer;
use App\Mcp\Tools\GameTool;
use App\Models\McpAuditLog;
use App\Models\StaffUser;
use Laravel\Passport\Passport;

// Gäller alla verktyg i servern, också de som läggs till senare.
class ToolInvariantsTest extends McpTestCase
{
    /** @return list<class-string<GameTool>> */
    private function gameToolClasses(): array
    {
        $classes = array_values(array_filter(ComputercatServer::toolClasses(), fn ($c) => is_subclass_of($c, GameTool::class)));

        // Synligt i testutskriften i stället för ett tyst grönt test utan kontroller.
        if ($classes === []) {
            $this->markTestSkipped('Servern har inga GameTool-verktyg ännu.');
        }

        return $classes;
    }

    public function test_server_has_tools(): void
    {
        $this->assertNotEmpty(ComputercatServer::toolClasses());
    }

    /** Varje verktyg som tar game nekar icke-medlemmar och loggar nekandet. */
    public function test_every_game_tool_denies_non_members(): void
    {
        $this->game('glosis');
        $outsider = $this->staffMember($this->game('ordjakt'));

        foreach ($this->gameToolClasses() as $class) {
            $name = (new $class)->name();

            $result = $this->callTool($outsider, $name, ['game' => 'glosis']);

            $this->assertFalse($result['ok'], "{$name} svarade ok för icke-medlem");
            $this->assertStringContainsString('Game not found', $result['text'], $name);
            $this->assertTrue(McpAuditLog::where('tool', $name)->where('outcome', 'denied')->exists(), "{$name} loggade inte nekandet");
        }
    }

    /** Varje GameTool kräver game i sitt schema. */
    public function test_every_game_tool_requires_game_in_schema(): void
    {
        foreach ($this->gameToolClasses() as $class) {
            $schema = (new $class)->toArray()['inputSchema'];

            $this->assertSame('string', $schema['properties']['game']['type'] ?? null, $class);
            $this->assertContains('game', $schema['required'] ?? [], $class);
        }
    }

    /** Inget verktyg skickar ut hemligheter ur settings. */
    public function test_no_tool_leaks_game_secrets(): void
    {
        $secret = 'SECRET-'.bin2hex(random_bytes(8));
        $game = $this->game('ordjakt', ['settings' => ['revenuecat' => ['webhook_secret' => $secret], 'anthropic' => ['api_key' => $secret]]]);
        $user = $this->staffMember($game);

        foreach (ComputercatServer::toolClasses() as $class) {
            $name = (new $class)->name();
            $result = $this->callTool($user, $name, ['game' => 'ordjakt']);
            $this->assertStringNotContainsString($secret, $result['text'], $name);
        }
    }

    /** Inga raderingsverktyg och inga verktyg för pengar eller spelardata. */
    public function test_forbidden_tool_names_do_not_exist(): void
    {
        foreach (ComputercatServer::toolClasses() as $class) {
            $name = (new $class)->name();
            $this->assertDoesNotMatchRegularExpression('/delete|remove|destroy|product|purchase|user|player|settings|token/i', $name);
        }
    }

    /** toolClasses() är samma lista som servern faktiskt exponerar. */
    public function test_tool_classes_match_tools_list(): void
    {
        Passport::actingAs(StaffUser::findOrFail($this->staffMember()->id), ['mcp:use'], 'mcp');

        $listed = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->json('result.tools.*.name');
        $expected = array_map(fn ($class) => (new $class)->name(), ComputercatServer::toolClasses());

        $this->assertEqualsCanonicalizing($expected, $listed);
    }
}
