<?php

namespace Tests\Feature\Mcp;

class SetAllowedOriginsToolTest extends McpTestCase
{
    public function test_replaces_list_normalized(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);

        $this->assertTrue($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => ['https://Ordjakt.apps.humblebrag.se/']])['ok']);
        $this->assertSame(['https://ordjakt.apps.humblebrag.se'], $game->fresh()->allowed_origins);
    }

    public function test_one_bad_origin_rejects_the_whole_list(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);

        $result = $this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => ['https://ok.se', 'http://bad.se']]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['https://old.se'], $game->fresh()->allowed_origins);
    }

    public function test_more_than_20_origins_is_rejected(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);
        $origins = array_map(fn ($i) => "https://spel{$i}.se", range(1, 21));

        $this->assertFalse($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => $origins])['ok']);
        $this->assertSame(['https://old.se'], $game->fresh()->allowed_origins);
    }

    public function test_exactly_20_origins_and_empty_list_are_accepted(): void
    {
        $game = $this->game('ordjakt');
        $user = $this->staffMember($game);
        $origins = array_map(fn ($i) => "https://spel{$i}.se", range(1, 20));

        $this->assertTrue($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => $origins])['ok']);
        $this->assertCount(20, $game->fresh()->allowed_origins);

        $this->assertTrue($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => []])['ok']);
        $this->assertSame([], $game->fresh()->allowed_origins);
    }

    public function test_non_string_origin_and_missing_origins_are_rejected(): void
    {
        $game = $this->game('ordjakt', ['allowed_origins' => ['https://old.se']]);
        $user = $this->staffMember($game);

        $this->assertFalse($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt', 'origins' => [['x']]])['ok']);
        $this->assertFalse($this->callTool($user, 'set_allowed_origins', ['game' => 'ordjakt'])['ok']);
        $this->assertSame(['https://old.se'], $game->fresh()->allowed_origins);
    }
}
