<?php

namespace Tests\Feature\Matches;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatchModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeMatch(): GameMatch
    {
        $game = Game::create(['slug' => 'duell', 'name' => 'Duell', 'is_active' => true]);

        return GameMatch::create([
            'game_id' => $game->id,
            'ruleset' => 'test',
            'options' => [],
            'status' => GameMatch::STATUS_WAITING,
            'state' => [],
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function test_hidden_is_encrypted_at_rest(): void
    {
        $match = $this->makeMatch();

        $player = GameMatchPlayer::create([
            'match_id' => $match->id,
            'user_id' => User::factory()->create()->id,
            'seat' => 0,
            'name' => 'Spelare 1',
            'hidden_state' => ['secret' => 42],
        ]);

        $raw = DB::table('match_players')->value('hidden_state');

        $this->assertNull(json_decode($raw, true));
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertSame('{"secret":42}', Crypt::decryptString($raw));
        $this->assertSame(['secret' => 42], $player->fresh()->hidden_state);
        $this->assertArrayNotHasKey('hidden_state', $player->fresh()->toArray());
    }

    public function test_deleting_match_cascades(): void
    {
        $match = $this->makeMatch();

        GameMatchPlayer::create([
            'match_id' => $match->id,
            'user_id' => User::factory()->create()->id,
            'seat' => 0,
            'name' => 'Spelare 1',
        ]);
        GameMatchEvent::create([
            'match_id' => $match->id,
            'seq' => 1,
            'type' => 'joined',
            'payload' => [],
        ]);

        $this->assertNotNull(GameMatchEvent::first()->created_at);
        $this->assertCount(1, $match->players);
        $this->assertCount(1, $match->events);

        $match->delete();

        $this->assertSame(0, DB::table('match_players')->count());
        $this->assertSame(0, DB::table('match_events')->count());
    }

    public function test_open_scope_returns_waiting_and_active_only(): void
    {
        $match = $this->makeMatch();
        $match->replicate()->fill(['status' => GameMatch::STATUS_ACTIVE])->save();
        $match->replicate()->fill(['status' => GameMatch::STATUS_FINISHED])->save();

        $this->assertSame(2, GameMatch::open()->count());
    }
}
