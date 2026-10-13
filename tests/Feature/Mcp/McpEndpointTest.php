<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function callListGames()
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_games', 'arguments' => (object) []],
        ]);
    }

    public function test_tool_call_runs_as_the_authenticated_user(): void
    {
        $staff = StaffUser::findOrFail(User::factory()->staff()->create()->id);
        Passport::actingAs($staff, ['mcp:use'], 'mcp');

        $response = $this->callListGames();

        $response->assertOk();
        $this->assertFalse($response->json('result.isError'));
        $this->assertSame(['games' => []], json_decode($response->json('result.content.0.text'), true));
        $this->assertSame($staff->id, McpAuditLog::where('tool', 'list_games')->sole()->user_id);
    }

    public function test_token_without_mcp_scope_is_refused(): void
    {
        $staff = StaffUser::findOrFail(User::factory()->staff()->create()->id);
        Passport::actingAs($staff, [], 'mcp');

        $response = $this->callListGames();

        $response->assertForbidden();
        $this->assertNull($response->json('result'));
    }

    public function test_sanctum_token_does_not_open_mcp(): void
    {
        $token = User::factory()->create()->createToken('anonymous')->plainTextToken;

        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);
    }
}
