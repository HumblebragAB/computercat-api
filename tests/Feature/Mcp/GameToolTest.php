<?php

namespace Tests\Feature\Mcp;

use App\Models\McpAuditLog;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Mcp\Fixtures\FixtureGameTool;
use Tests\Feature\Mcp\Fixtures\FixtureServer;

// Basklassens beteende, via ett testverktyg (de riktiga GameTool-verktygen
// täcks av ToolInvariantsTest).
class GameToolTest extends McpTestCase
{
    private function callFixture(User $user, array $args)
    {
        return FixtureServer::actingAs(StaffUser::findOrFail($user->id), 'mcp')->tool(FixtureGameTool::class, $args);
    }

    private function lastLog(): McpAuditLog
    {
        return McpAuditLog::where('tool', 'fixture_game_tool')->latest('id')->firstOrFail();
    }

    public function test_member_gets_result_and_ok_is_logged(): void
    {
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);

        $this->callFixture($user, ['game' => 'ordjakt'])->assertHasNoErrors()->assertSee('"name": "Ändrad"');

        $this->assertSame('Ändrad', $game->fresh()->name);
        $log = $this->lastLog();
        $this->assertSame(['ok', $user->id, $game->id], [$log->outcome, $log->user_id, $log->game_id]);
    }

    public function test_non_member_unknown_and_non_string_game_are_denied_alike(): void
    {
        $this->game('glosis');
        $user = $this->staffMember($this->game('ordjakt'));

        foreach ([['game' => 'glosis'], ['game' => 'finns-inte'], ['game' => ['ordjakt']], []] as $args) {
            $this->callFixture($user, $args)->assertHasErrors(['Game not found.']);
            $log = $this->lastLog();
            $this->assertSame('denied', $log->outcome);
            $this->assertNull($log->game_id);
        }
        $this->assertNotSame('Ändrad', \App\Models\Game::where('slug', 'glosis')->value('name'));
    }

    public function test_validation_failure_rolls_back_and_is_logged(): void
    {
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);

        $this->callFixture($user, ['game' => 'ordjakt', 'mode' => 'invalid'])
            ->assertHasErrors(["Validation failed:\n- name: The name is wrong."]);

        $this->assertSame('Ordjakt', $game->fresh()->name);
        $this->assertSame('validation_error', $this->lastLog()->outcome);
    }

    public function test_thrown_validation_exception_is_a_validation_error(): void
    {
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);

        $this->callFixture($user, ['game' => 'ordjakt', 'mode' => 'throw_validation'])
            ->assertHasErrors(['- key: The key is wrong.']);

        $this->assertSame('Ordjakt', $game->fresh()->name);
        $this->assertSame('validation_error', $this->lastLog()->outcome);
    }

    public function test_exception_rolls_back_hides_details_and_logs_reference(): void
    {
        Log::spy();
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);

        $response = $this->callFixture($user, ['game' => 'ordjakt', 'mode' => 'boom']);

        $response->assertHasErrors(['Unexpected error (ref '])->assertDontSee('internal detail');
        $this->assertSame('Ordjakt', $game->fresh()->name);
        $this->assertSame('error', $this->lastLog()->outcome);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => $msg === 'MCP tool failed'
            && $ctx['tool'] === 'fixture_game_tool' && $ctx['exception'] instanceof \RuntimeException)->once();
    }

    public function test_audit_failure_does_not_change_the_answer(): void
    {
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);
        \Illuminate\Support\Facades\Schema::drop('mcp_audit_logs');

        $this->callFixture($user, ['game' => 'ordjakt'])->assertHasNoErrors();
        $this->callFixture($user, ['game' => 'glosis'])->assertHasErrors(['Game not found.']);
    }
}
