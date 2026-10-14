<?php

namespace Tests\Feature\Mcp;

use App\Models\ProvisioningRun;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsNoHttpInTransaction;
use Tests\Support\CreatesProvisioningRuns;
use Tests\Support\ProvisioningFakes;

class RequestLiveToolTest extends McpTestCase
{
    use AssertsNoHttpInTransaction, CreatesProvisioningRuns;

    private \App\Models\Game $kattsvep;

    private \App\Models\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ProvisioningFakes::configure();
        $this->startHttpTransactionGuard();
        $this->kattsvep = $this->game('kattsvep', ['is_listed' => false]);
        $this->owner = $this->staffMember();
        $this->kattsvep->members()->attach($this->owner, ['role' => 'owner']);
        $this->provisioningRun(['slug' => 'kattsvep', 'game_id' => $this->kattsvep->id, 'status' => ProvisioningRun::SUCCEEDED, 'demo_url' => 'https://kattsvep.apps.humblebrag.se']);
    }

    protected function tearDown(): void
    {
        $this->assertNoHttpInTransaction();
        parent::tearDown();
    }

    public function test_owner_notifies_erik_with_demo_url_and_domain_and_nothing_changes(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);

        $result = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se']);
        $again = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'kattsvep.se']);

        $this->assertTrue($result['ok'], $result['text']);
        $this->assertTrue($again['ok']);
        $this->assertStringContainsString('already', $again['text']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'https://kattsvep.apps.humblebrag.se') && str_contains($r['text'], 'kattsvep.se'));
        $this->assertFalse($this->kattsvep->fresh()->is_listed);
    }

    public function test_invalid_domain_is_rejected_without_slack(): void
    {
        Http::fake();

        $this->assertFalse($this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'https://x.se/path'])['ok']);
        $this->assertFalse($this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => '<!channel>'])['ok']);
        Http::assertNothingSent();
    }

    public function test_non_string_domain_is_a_validation_error_without_slack(): void
    {
        Http::fake();

        $result = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => ['x.se']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Validation failed', $result['text']);
        Http::assertNothingSent();
    }

    public function test_different_domains_same_day_send_one_slack_post(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('ok')]);

        $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'a.se']);
        $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep', 'domain' => 'b.se']);

        Http::assertSentCount(1);
    }

    public function test_non_owner_and_game_without_demo_are_refused(): void
    {
        Http::fake();
        $member = $this->staffMember($this->kattsvep);
        $this->game('ordjakt')->members()->attach($this->owner, ['role' => 'owner']);

        $this->assertStringContainsString('Only the game owner', $this->callTool($member, 'request_live', ['game' => 'kattsvep'])['text']);
        $this->assertStringContainsString('finished Demo', $this->callTool($this->owner, 'request_live', ['game' => 'ordjakt'])['text']);
        Http::assertNothingSent();
    }

    public function test_slack_failure_is_an_error(): void
    {
        Http::fake([ProvisioningFakes::SLACK => Http::response('nope', 500)]);

        $result = $this->callTool($this->owner, 'request_live', ['game' => 'kattsvep']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not notify Erik', $result['text']);
    }
}
