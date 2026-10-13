<?php

namespace Tests\Feature\Mcp;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpPingTest extends TestCase
{
    use RefreshDatabase;

    private function callPing()
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'ping', 'arguments' => (object) []],
        ]);
    }

    public function test_ping_answers_with_the_authenticated_user_id(): void
    {
        $staff = StaffUser::findOrFail(User::factory()->staff()->create()->id);
        Passport::actingAs($staff, ['mcp:use'], 'mcp');

        $response = $this->callPing();

        $response->assertOk();
        $this->assertSame('pong '.$staff->id, $response->json('result.content.0.text'));
    }

    public function test_token_without_mcp_scope_is_refused(): void
    {
        $staff = StaffUser::findOrFail(User::factory()->staff()->create()->id);
        Passport::actingAs($staff, [], 'mcp');

        $response = $this->callPing();

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
