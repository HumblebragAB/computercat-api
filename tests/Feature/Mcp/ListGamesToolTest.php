<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ComputercatServer;
use App\Mcp\Tools\ListGamesTool;
use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;

class ListGamesToolTest extends McpTestCase
{
    public function test_lists_only_member_games(): void
    {
        $this->game('glosis');
        $user = $this->staffMember($this->game('ordjakt', ['is_active' => false, 'allowed_origins' => ['https://ordjakt.example']]));

        $result = $this->callTool($user, 'list_games');

        $this->assertTrue($result['ok']);
        $games = json_decode($result['text'], true)['games'];
        $this->assertSame(['ordjakt'], array_column($games, 'slug'));
        $this->assertFalse($games[0]['is_active']);
        $this->assertSame(['https://ordjakt.example'], $games[0]['allowed_origins']);
        $this->assertSame(['slug', 'name', 'is_active', 'allowed_origins'], array_keys($games[0]));
    }

    public function test_platform_admin_sees_all_games_sorted(): void
    {
        $this->game('ordjakt');
        $this->game('glosis');
        $admin = User::factory()->staff(admin: true)->create();

        $slugs = array_column(json_decode($this->callTool($admin, 'list_games')['text'], true)['games'], 'slug');

        $this->assertSame(\App\Models\Game::orderBy('slug')->pluck('slug')->all(), $slugs);
        $this->assertContains('glosis', $slugs);
    }

    public function test_call_is_logged_without_game(): void
    {
        $user = $this->staffMember($this->game('ordjakt'));

        $this->callTool($user, 'list_games');

        $log = McpAuditLog::where('tool', 'list_games')->sole();
        $this->assertSame('ok', $log->outcome);
        $this->assertSame($user->id, $log->user_id);
        $this->assertNull($log->game_id);
    }

    // EnsureStaff stoppar icke-staff före verktyget; verktyget nekar ändå själv.
    public function test_tool_itself_denies_non_staff(): void
    {
        $this->game('ordjakt');
        $player = StaffUser::findOrFail(User::factory()->create()->id);

        ComputercatServer::actingAs($player, 'mcp')->tool(ListGamesTool::class)
            ->assertHasErrors()->assertDontSee('ordjakt');

        $this->assertSame('denied', McpAuditLog::where('tool', 'list_games')->sole()->outcome);
    }

    public function test_mcp_is_rate_limited_per_user(): void
    {
        $user = $this->staffMember();
        $other = $this->staffMember();

        for ($i = 0; $i < 120; $i++) {
            $this->callTool($user, 'list_games');
        }

        \Laravel\Passport\Passport::actingAs(StaffUser::findOrFail($user->id), ['mcp:use'], 'mcp');
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(429);

        // Gränsen gäller per användare.
        $this->assertTrue($this->callTool($other, 'list_games')['ok']);
    }
}
