<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProvisioningFakes;

// request_live är en tunn hänvisning till promote_release (spec Del 1): den ändrar och skickar ingenting.
class RequestLiveToolTest extends McpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        Http::fake();
    }

    public function test_member_gets_a_pointer_and_nothing_happens(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);

        $result = $this->callTool($member, 'request_live', ['game' => 'kattsvep']);

        $this->assertTrue($result['ok'], $result['text']);
        $json = json_decode($result['text'], true);
        $this->assertFalse($json['requested']);
        $this->assertStringContainsString('promote_release(target: web, stage: web_production', $json['message']);
        $this->assertStringContainsString('get_release_status', $json['message']);
        Http::assertNothingSent();
        $this->assertSame([], $game->fresh()->releaseTargets());
    }

    public function test_a_given_domain_is_not_silently_dropped(): void
    {
        $game = $this->game('kattsvep');
        $member = $this->staffMember($game);

        $json = json_decode($this->callTool($member, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se'])['text'], true);

        $this->assertStringContainsString('kattsvep.se was not recorded', $json['message']);
        $this->assertStringContainsString('Erik sets the public address', $json['message']);
    }

    public function test_non_member_is_denied_and_the_call_is_logged(): void
    {
        $this->game('kattsvep');
        $outsider = $this->staffMember($this->game('ordjakt'));

        $result = $this->callTool($outsider, 'request_live', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertTrue(McpAuditLog::where('tool', 'request_live')->where('outcome', 'denied')->exists());
    }
}
