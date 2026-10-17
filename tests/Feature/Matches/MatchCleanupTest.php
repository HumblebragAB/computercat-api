<?php

namespace Tests\Feature\Matches;

use App\Matches\MatchService;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MatchCleanupTest extends TestCase
{
    use RefreshDatabase;

    private MatchService $service;

    private Game $game;

    private User $anna;

    private User $bo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MatchService::class);
        $this->game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);
        $this->anna = User::factory()->create();
        $this->bo = User::factory()->create();
    }

    private function waiting(): GameMatch
    {
        return $this->service->create($this->game, $this->anna, 'reveal', [], 'Anna')['match'];
    }

    private function active(): GameMatch
    {
        $created = $this->service->create($this->game, $this->anna, 'reveal', [], 'Anna');

        return $this->service->join($this->game, $this->bo, $created['token'], 'Bo');
    }

    private function setStatusAge(GameMatch $match, string $status, int $days): void
    {
        DB::table('matches')->where('id', $match->id)->update([
            'status' => $status,
            'updated_at' => now()->subDays($days),
        ]);
    }

    public function test_waiting_match_is_abandoned_after_16_minutes_but_not_14(): void
    {
        $match = $this->waiting();

        $this->travel(14)->minutes();
        $this->artisan('matches:expire')->expectsOutputToContain('0')->assertExitCode(0);
        $this->assertSame(GameMatch::STATUS_WAITING, $match->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('matches:expire')->expectsOutputToContain('1')->assertExitCode(0);
        $fresh = $match->fresh();
        $this->assertSame(GameMatch::STATUS_ABANDONED, $fresh->status);
        $this->assertNull($fresh->join_token_hash);
        $this->assertTrue($fresh->events()->where('type', 'expired')->exists());
    }

    public function test_active_match_is_abandoned_after_31_minutes_without_a_move(): void
    {
        $match = $this->active();

        $this->travel(29)->minutes();
        $this->artisan('matches:expire')->assertExitCode(0);
        $this->assertSame(GameMatch::STATUS_ACTIVE, $match->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('matches:expire')->assertExitCode(0);
        $this->assertSame(GameMatch::STATUS_ABANDONED, $match->fresh()->status);
    }

    public function test_a_move_pushes_the_limit(): void
    {
        $match = $this->active();
        $before = $match->fresh()->expires_at;

        $this->travel(20)->minutes();
        $fresh = $match->fresh();
        $this->service->act($fresh, $this->anna, 'set_secret', ['value' => 42], $fresh->version);
        $this->assertTrue($match->fresh()->expires_at->greaterThan($before));

        // 31 min efter start men bara 11 efter draget.
        $this->travel(11)->minutes();
        $this->artisan('matches:expire')->assertExitCode(0);
        $this->assertSame(GameMatch::STATUS_ACTIVE, $match->fresh()->status);
    }

    public function test_prune_deletes_old_finished_but_not_recent_or_active(): void
    {
        $old = $this->waiting();
        $this->setStatusAge($old, GameMatch::STATUS_FINISHED, 8);
        $oldAbandoned = $this->waiting();
        $this->setStatusAge($oldAbandoned, GameMatch::STATUS_ABANDONED, 8);
        $recent = $this->waiting();
        $this->setStatusAge($recent, GameMatch::STATUS_FINISHED, 6);
        $active = $this->waiting();
        $this->setStatusAge($active, GameMatch::STATUS_ACTIVE, 30);
        $waiting = $this->waiting();
        $this->setStatusAge($waiting, GameMatch::STATUS_WAITING, 30);

        $this->artisan('matches:prune', ['--days' => 7])->expectsOutputToContain('2')->assertExitCode(0);

        $this->assertNull(GameMatch::find($old->id));
        $this->assertNull(GameMatch::find($oldAbandoned->id));
        $this->assertNotNull(GameMatch::find($recent->id));
        $this->assertNotNull(GameMatch::find($active->id));
        $this->assertNotNull(GameMatch::find($waiting->id));
        $this->assertSame(0, DB::table('match_players')->where('match_id', $old->id)->count());
        $this->assertSame(0, DB::table('match_events')->where('match_id', $old->id)->count());
    }

    public function test_prune_deletes_in_chunks_of_500(): void
    {
        $template = $this->waiting();
        $this->setStatusAge($template, GameMatch::STATUS_FINISHED, 8);
        $row = (array) DB::table('matches')->where('id', $template->id)->first();
        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $row['id'] = (string) Str::ulid();
            $row['join_token_hash'] = null;
            $ids[] = $row['id'];
            DB::table('matches')->insert($row);
        }

        $this->artisan('matches:prune')->assertExitCode(0);

        $this->assertSame(0, GameMatch::count());
    }

    public function test_prune_rejects_zero_negative_and_non_integer_days_and_deletes_nothing(): void
    {
        $active = $this->active();
        $finished = $this->waiting();
        $this->setStatusAge($finished, GameMatch::STATUS_FINISHED, 8);

        foreach (['0', '-3', 'abc', '1.5'] as $days) {
            $this->artisan('matches:prune', ['--days' => $days])->assertExitCode(1);
        }

        $this->assertSame(GameMatch::STATUS_ACTIVE, $active->fresh()->status);
        $this->assertNotNull(GameMatch::find($finished->id));
    }
}
