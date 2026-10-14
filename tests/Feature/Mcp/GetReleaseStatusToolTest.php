<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use Tests\Support\CreatesReleases;

class GetReleaseStatusToolTest extends McpTestCase
{
    use CreatesReleases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openProductionStages();
    }

    public function test_member_reads_status_with_next(): void
    {
        $game = $this->releasableGame('kattsvep', ['web']);
        $this->demoBuild($game, $this->sha('b'));
        $member = $this->staffMember($game);

        $result = $this->callTool($member, 'get_release_status', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertSame(['web'], $json['release_targets']);
        $this->assertSame('ready_for_production', $json['targets']['web']['state']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production', $json['next']);
        $this->assertTrue(McpAuditLog::where('tool', 'get_release_status')->where('outcome', 'ok')->exists());
    }

    public function test_non_member_gets_game_not_found(): void
    {
        $this->releasableGame('kattsvep');
        $outsider = $this->staffMember($this->game('ordjakt'));

        $result = $this->callTool($outsider, 'get_release_status', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Game not found', $result['text']);
    }
}
