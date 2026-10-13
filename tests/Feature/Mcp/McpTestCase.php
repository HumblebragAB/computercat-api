<?php

namespace Tests\Feature\Mcp;

use App\Models\Game;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected function staffMember(Game ...$games): User
    {
        $user = User::factory()->staff()->create();
        foreach ($games as $game) {
            $game->members()->attach($user, ['role' => 'member']);
        }

        return $user;
    }

    // glosis skapas av en migration, så spelet kan redan finnas. Finns det
    // skrivs bara de attribut som skickas in.
    protected function game(string $slug = 'ordjakt', array $attrs = []): Game
    {
        $game = Game::firstOrCreate(['slug' => $slug], array_merge(['name' => ucfirst($slug), 'is_active' => true, 'settings' => []], $attrs));

        if (! $game->wasRecentlyCreated && $attrs !== []) {
            $game->update($attrs);
        }

        return $game;
    }

    /**
     * Anropar verktyget via HTTP mot /mcp, så att hela middlewarekedjan körs
     * (auth:mcp, throttle:mcp, scope, EnsureStaff).
     *
     * @return array{ok: bool, text: string}
     */
    protected function callTool(User $user, string $tool, array $args = []): array
    {
        Passport::actingAs(StaffUser::findOrFail($user->id), ['mcp:use'], 'mcp');

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $args],
        ])->assertOk();

        $result = $response->json('result');
        $this->assertIsArray($result, "Inget result för {$tool}: ".$response->getContent());

        return [
            'ok' => ! ($result['isError'] ?? false),
            'text' => collect($result['content'] ?? [])->pluck('text')->implode("\n"),
        ];
    }
}
