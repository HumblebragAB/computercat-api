<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;
use Tests\Feature\Mcp\Fixtures\FixturePlatformTool;
use Tests\Feature\Mcp\Fixtures\FixtureServer;

class PlatformToolTest extends McpTestCase
{
    private function callPlatform(User $user, array $args = [])
    {
        return FixtureServer::actingAs(StaffUser::findOrFail($user->id), 'mcp')->tool(FixturePlatformTool::class, $args);
    }

    private function outcomes(): array
    {
        return McpAuditLog::where('tool', 'fixture_platform_tool')->orderBy('id')->pluck('outcome')->all();
    }

    public function test_outcomes_are_logged_with_arguments_and_without_game(): void
    {
        $user = $this->staffMember();

        $this->callPlatform($user, ['mode' => 'ok'])->assertHasNoErrors();
        $this->callPlatform($user, ['mode' => 'invalid'])->assertHasErrors(['The slug is wrong.']);
        $this->callPlatform($user, ['mode' => 'throw_validation'])->assertHasErrors(['The slug is wrong.']);
        $this->callPlatform($user, ['mode' => 'boom'])->assertHasErrors(['Unexpected error (ref '])->assertDontSee('internal detail');

        $this->assertSame(['ok', 'validation_error', 'validation_error', 'error'], $this->outcomes());
        $this->assertNull(McpAuditLog::where('tool', 'fixture_platform_tool')->first()->game_id);
        $this->assertSame(['mode' => 'ok'], McpAuditLog::where('tool', 'fixture_platform_tool')->first()->arguments);
    }

    public function test_non_staff_is_denied_and_logged(): void
    {
        $player = User::factory()->create();

        $this->callPlatform($player)->assertHasErrors(['Not allowed.']);

        $this->assertSame(['denied'], $this->outcomes());
    }
}
