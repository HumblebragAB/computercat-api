<?php

namespace Tests\Feature\Matches;

use App\Matches\IllegalMove;
use App\Matches\MatchError;
use App\Matches\MatchService;
use App\Matches\MatchView;
use App\Matches\Rules\RevealRules;
use App\Matches\RulesetRegistry;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\AssertsNoMatchLeak;
use Tests\TestCase;

class MatchServiceTest extends TestCase
{
    use AssertsNoMatchLeak;
    use RefreshDatabase;

    private MatchService $service;

    private Game $game;

    private User $anna;

    private User $bo;

    protected function setUp(): void
    {
        parent::setUp();
        // Lottningen av startspelare är slump i produktion; testerna förutsätter säte 0.
        app()->instance(RevealRules::STARTER_DRAW_BINDING, fn (): int => 0);
        $this->service = app(MatchService::class);
        $this->game = Game::create(['slug' => 'reveal', 'name' => 'Reveal', 'is_active' => true]);
        $this->anna = User::factory()->create();
        $this->bo = User::factory()->create();
    }

    /** @return array{match: GameMatch, token: string} */
    private function create(?User $user = null, ?string $name = 'Anna'): array
    {
        return $this->service->create($this->game, $user ?? $this->anna, 'reveal', [], $name);
    }

    private function started(): GameMatch
    {
        $token = $this->create()['token'];

        return $this->service->join($this->game, $this->bo, $token, 'Bo');
    }

    private function act(GameMatch $match, int $seat, string $type, array $payload = []): GameMatch
    {
        $match = $match->fresh();

        return $this->service->act($match, $seat === 0 ? $this->anna : $this->bo, $type, $payload, $match->version);
    }

    private function matchError(callable $fn, string $code): MatchError
    {
        try {
            $fn();
        } catch (MatchError $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }
        $this->fail("Expected MatchError {$code}");
    }

    /** Ögonblicksbild av allt ett avvisat anrop inte får röra. */
    private function snapshot(GameMatch $match): array
    {
        $fresh = $match->fresh();

        return [
            $fresh->version,
            $fresh->state,
            $fresh->status,
            GameMatchEvent::where('match_id', $match->id)->count(),
            GameMatchPlayer::where('match_id', $match->id)->orderBy('seat')->get()->map->hidden_state->all(),
        ];
    }

    /** Spelar en hel match där säte 0 vinner 2–0. Säte 0 har talet 10, säte 1 talet 20. */
    private function playToEnd(GameMatch $match): GameMatch
    {
        // Runda 1: säte 0 startar och träffar, säte 1 missar sista chansen.
        $match = $this->act($match, 0, 'set_secret', ['value' => 10]);
        $match = $this->act($match, 1, 'set_secret', ['value' => 20]);
        $match = $this->act($match, 0, 'guess', ['value' => 20]);
        $match = $this->act($match, 1, 'guess', ['value' => 50]);
        $match = $this->act($match, 0, 'ready');
        $match = $this->act($match, 1, 'ready');
        // Runda 2: säte 1 startar och missar, säte 0 träffar.
        $match = $this->act($match, 0, 'set_secret', ['value' => 10]);
        $match = $this->act($match, 1, 'set_secret', ['value' => 20]);
        $match = $this->act($match, 1, 'guess', ['value' => 50]);
        $match = $this->act($match, 0, 'guess', ['value' => 20]);

        $this->assertSame(GameMatch::STATUS_FINISHED, $match->status);

        return $match;
    }

    public function test_create_and_join_starts_reveal(): void
    {
        ['match' => $created, 'token' => $token] = $this->create();
        $this->assertSame(GameMatch::STATUS_WAITING, $created->status);
        $this->assertSame(1, $created->version);
        // Reveal bjuder in med kort kod (invite => code), lagrad som HMAC. Se MatchInviteCodeTest.
        $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}\z/', $token);
        $this->assertSame(hash_hmac('sha256', $token, hash_hmac('sha256', 'match-invite-code', config('app.key'), true)), $created->fresh()->join_token_hash);

        $match = $this->service->join($this->game, $this->bo, $token, 'Bo');

        $this->assertSame(GameMatch::STATUS_ACTIVE, $match->status);
        $this->assertSame(2, $match->version);
        $this->assertNull($match->fresh()->join_token_hash);
        $this->assertSame(1, $match->state['round']);
        $this->assertSame('choosing', $match->state['phase']);

        $view = MatchView::for($match->fresh(), 0, null);
        $this->assertSame(['id', 'ruleset', 'status', 'version', 'your_seat', 'players', 'state', 'you', 'events'], array_keys($view));
        $this->assertSame([['seat' => 0, 'name' => 'Anna'], ['seat' => 1, 'name' => 'Bo']],
            array_map(fn ($p) => ['seat' => $p['seat'], 'name' => $p['name']], $view['players']));
        $this->assertSame(0, $view['your_seat']);
        $this->assertSame(['secret' => null], $view['you']);
        $this->assertSame([['seq' => 1, 'type' => 'round_begin', 'seat' => null, 'payload' => ['round' => 1, 'starter' => 0]]],
            array_map(fn ($e) => array_diff_key($e, ['at' => 1]), $view['events']));
        $this->assertSame(['seq', 'type', 'seat', 'payload', 'at'], array_keys($view['events'][0]));
    }

    public function test_invalid_tokens_all_look_the_same(): void
    {
        $other = Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true]);
        $carl = User::factory()->create();

        $this->matchError(fn () => $this->service->join($this->game, $this->bo, 'okand-token', null), 'invite_expired');

        $used = $this->create()['token'];
        $this->service->join($this->game, $this->bo, $used, null);
        $e = $this->matchError(fn () => $this->service->join($this->game, $carl, $used, null), 'invite_expired');
        $this->assertSame(410, $e->status);

        $old = $this->create()['token'];
        $this->travel(16)->minutes();
        $this->matchError(fn () => $this->service->join($this->game, $this->bo, $old, null), 'invite_expired');

        $fresh = $this->create()['token'];
        $this->matchError(fn () => $this->service->join($other, $this->bo, $fresh, null), 'invite_expired');
    }

    public function test_own_match(): void
    {
        ['match' => $match, 'token' => $token] = $this->create();

        $e = $this->matchError(fn () => $this->service->join($this->game, $this->anna, $token, null), 'own_match');
        $this->assertSame(409, $e->status);
        $this->assertSame(GameMatch::STATUS_WAITING, $match->fresh()->status);
        $this->assertSame(1, GameMatchPlayer::where('match_id', $match->id)->count());

        $joined = $this->service->join($this->game, $this->bo, $token, null);
        $this->assertSame($match->id, $joined->id);
        $this->assertSame(GameMatch::STATUS_ACTIVE, $joined->status);
    }

    public function test_stale_version_has_no_side_effect(): void
    {
        $match = $this->started();
        $this->act($match, 0, 'set_secret', ['value' => 10]);
        $match = $this->act($match, 1, 'set_secret', ['value' => 20]);
        $version = $match->version;

        $this->service->act($match->fresh(), $this->anna, 'guess', ['value' => 50], $version);
        $before = $this->snapshot($match);

        $e = $this->matchError(
            fn () => $this->service->act($match->fresh(), $this->anna, 'guess', ['value' => 50], $version),
            'stale',
        );
        $this->assertSame(409, $e->status);
        $this->assertSame(['version' => $version + 1], $e->extra);
        $this->assertSame($before, $this->snapshot($match));
    }

    public function test_illegal_move_has_no_side_effect(): void
    {
        $match = $this->started();
        $this->act($match, 0, 'set_secret', ['value' => 10]);
        $match = $this->act($match, 1, 'set_secret', ['value' => 20]);
        $before = $this->snapshot($match);

        try {
            $this->act($match, 1, 'guess', ['value' => 50]);
            $this->fail('Expected IllegalMove');
        } catch (IllegalMove $e) {
            $this->assertSame('not_your_turn', $e->errorCode);
        }

        $this->assertSame($before, $this->snapshot($match));
    }

    public function test_act_rejects_when_not_active(): void
    {
        ['match' => $match] = $this->create();
        $e = $this->matchError(fn () => $this->service->act($match->fresh(), $this->anna, 'set_secret', ['value' => 1], 1), 'wrong_phase');
        $this->assertSame(422, $e->status);
    }

    public function test_act_bumps_version_once_and_moves_expiry(): void
    {
        $match = $this->started();
        $this->act($match, 0, 'set_secret', ['value' => 10]);
        $this->travel(10)->minutes();
        // Två händelser (locked + duel_begin) men en version.
        $after = $this->act($match, 1, 'set_secret', ['value' => 20]);

        $this->assertSame(4, $after->version);
        $this->assertSame([1, 2, 3, 4], GameMatchEvent::where('match_id', $match->id)->orderBy('seq')->pluck('seq')->all());
        $this->assertTrue($after->fresh()->expires_at->equalTo(now()->addMinutes(30)->startOfSecond()));
        $this->assertNotNull($after->fresh()->last_action_at);
        $this->assertSame(['secret' => 20], GameMatchPlayer::where('match_id', $match->id)->where('seat', 1)->first()->hidden_state);
    }

    public function test_leave_mid_round(): void
    {
        $match = $this->started();
        $this->act($match, 0, 'set_secret', ['value' => 42]);
        $this->act($match, 1, 'set_secret', ['value' => 77]);
        $versionBefore = $match->fresh()->version;

        $left = $this->service->leave($match->fresh(), $this->bo);

        $this->assertSame(GameMatch::STATUS_ABANDONED, $left->status);
        $this->assertSame($versionBefore + 1, $left->version);
        $last = GameMatchEvent::where('match_id', $match->id)->orderByDesc('seq')->first();
        $this->assertSame('left', $last->type);
        $this->assertSame(1, $last->seat);

        // Dolt nollställt: ingen av dem ser längre något i you, och ingen ser motspelarens tal.
        $this->assertNoLeak(MatchView::for($left->fresh(), 0, null), 77, null);
        $this->assertNoLeak(MatchView::for($left->fresh(), 1, null), 42, null);

        // Idempotent: andra anropet ändrar inget.
        $again = $this->service->leave($left->fresh(), $this->anna);
        $this->assertSame($left->version, $again->version);
        $this->assertSame(GameMatch::STATUS_ABANDONED, $again->status);
    }

    public function test_leave_and_finish_clear_hidden_state(): void
    {
        $match = $this->started();
        $this->act($match, 0, 'set_secret', ['value' => 42]);
        $this->assertNotNull(GameMatchPlayer::where('match_id', $match->id)->where('seat', 0)->first()->hidden_state);
        $this->service->leave($match->fresh(), $this->anna);
        $this->assertSame([null, null], GameMatchPlayer::where('match_id', $match->id)->orderBy('seat')->pluck('hidden_state')->all());

        $finished = $this->playToEnd($this->started());
        $this->assertSame([null, null], GameMatchPlayer::where('match_id', $finished->id)->orderBy('seat')->pluck('hidden_state')->all());
        $this->assertSame('match_end', GameMatchEvent::where('match_id', $finished->id)->orderByDesc('seq')->value('type'));
    }

    public function test_rematch_creates_new_match_after_both(): void
    {
        $this->matchError(fn () => $this->service->rematch($this->started(), $this->anna), 'wrong_phase');

        $match = $this->playToEnd($this->started());
        $version = $match->version;
        $count = GameMatchEvent::where('match_id', $match->id)->count();

        $after = $this->service->rematch($match->fresh(), $this->anna);
        $this->assertSame($version + 1, $after->version);
        $last = GameMatchEvent::where('match_id', $match->id)->orderByDesc('seq')->first();
        $this->assertSame('rematch_requested', $last->type);
        $this->assertSame(0, $last->seat);
        $this->assertSame($count + 1, $last->seq);

        $same = $this->service->rematch($match->fresh(), $this->anna);
        $this->assertSame($version + 1, $same->version);
        $this->assertSame($count + 1, GameMatchEvent::where('match_id', $match->id)->count());
        $this->assertSame(2, GameMatch::count());

        $both = $this->service->rematch($match->fresh(), $this->bo);
        $this->assertSame($version + 3, $both->version);
        $this->assertSame(GameMatch::STATUS_FINISHED, $both->status);
        $events = GameMatchEvent::where('match_id', $match->id)->orderByDesc('seq')->take(2)->get();
        $this->assertSame(['rematch', 'rematch_requested'], $events->pluck('type')->all());
        $this->assertSame(1, $events[1]->seat);

        $new = GameMatch::findOrFail($events[0]->payload['match_id']);
        $this->assertSame(GameMatch::STATUS_ACTIVE, $new->status);
        $this->assertSame($match->id, $new->rematch_of);
        $this->assertNull($new->join_token_hash);
        $this->assertSame($match->options, $new->options);
        $this->assertSame(1, $new->state['round']);
        $this->assertSame(
            [[0, $this->anna->id, 'Anna'], [1, $this->bo->id, 'Bo']],
            $new->players()->get()->map(fn ($p) => [$p->seat, $p->user_id, $p->name])->all(),
        );
        $this->assertSame('round_begin', GameMatchEvent::where('match_id', $new->id)->value('type'));

        // Ingen andra revansch av samma match.
        $this->service->rematch($match->fresh(), $this->bo);
        $this->assertSame(3, GameMatch::count());
    }

    public function test_cap_five_open_matches(): void
    {
        $matches = [];
        for ($i = 0; $i < 5; $i++) {
            $matches[] = $this->create()['match'];
        }

        $e = $this->matchError(fn () => $this->create(), 'too_many_active');
        $this->assertSame(429, $e->status);

        // Taket gäller också den som går med.
        $token = $this->create($this->bo)['token'];
        $this->matchError(fn () => $this->service->join($this->game, $this->anna, $token, null), 'too_many_active');

        // Andra spel räknas inte.
        $other = Game::create(['slug' => 'annat', 'name' => 'Annat', 'is_active' => true]);
        config(['matches.rulesets.reveal.games' => ['reveal', 'annat']]);
        $this->service->create($other, $this->anna, 'reveal', [], null);

        $this->service->leave($matches[0], $this->anna);
        $this->assertSame(GameMatch::STATUS_WAITING, $this->create()['match']->status);
    }

    public function test_name_rules(): void
    {
        $match = $this->create(name: '  Anna  ')['match'];
        $this->assertSame('Anna', GameMatchPlayer::where('match_id', $match->id)->value('name'));

        foreach ([str_repeat('a', 21), "Ann\u{0000}a", '   ', "Ann\u{200D}a"] as $bad) {
            try {
                $this->create(name: $bad);
                $this->fail('should reject '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('name', $e->errors());
            }
        }
        $this->assertSame(str_repeat('å', 20), GameMatchPlayer::where('match_id', $this->create(name: str_repeat('å', 20))['match']->id)->value('name'));

        ['match' => $match, 'token' => $token] = $this->create(name: null);
        $this->service->join($this->game, $this->bo, $token, null);
        $this->assertSame(['Spelare 1', 'Spelare 2'], GameMatchPlayer::where('match_id', $match->id)->orderBy('seat')->pluck('name')->all());

        // Ett ogiltigt namn vid join förbrukar inte token.
        ['match' => $match, 'token' => $token] = $this->create();
        try {
            $this->service->join($this->game, $this->bo, $token, str_repeat('b', 21));
            $this->fail('should reject long name on join');
        } catch (ValidationException) {
        }
        $this->assertSame(GameMatch::STATUS_WAITING, $match->fresh()->status);
    }

    public function test_options_and_ruleset_validated(): void
    {
        try {
            $this->service->create($this->game, $this->anna, 'reveal', ['max' => 1000], null);
            $this->fail('options should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('options', $e->errors());
        }

        try {
            $this->service->create($this->game, $this->anna, 'schack', [], null);
            $this->fail('unknown ruleset should be rejected');
        } catch (AuthorizationException $e) {
            $this->assertSame(RulesetRegistry::NOT_ALLOWED, $e->getMessage());
        }

        $this->assertSame(0, GameMatch::count());
    }

    public function test_non_member_gets_not_found(): void
    {
        $match = $this->started();
        $carl = User::factory()->create();
        $before = $this->snapshot($match);

        foreach ([
            fn () => $this->service->act($match->fresh(), $carl, 'set_secret', ['value' => 5], $match->fresh()->version),
            fn () => $this->service->leave($match->fresh(), $carl),
            fn () => $this->service->rematch($match->fresh(), $carl),
        ] as $call) {
            try {
                $call();
                $this->fail('non-member should get not found');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame($before, $this->snapshot($match));
    }

    public function test_touch_throttled(): void
    {
        $match = $this->started();
        $player = fn () => GameMatchPlayer::where('match_id', $match->id)->where('seat', 0)->first();
        $this->assertNull($player()->last_seen_at);

        $this->service->touch($match->fresh(), $this->anna);
        $first = $player()->last_seen_at;
        $this->assertNotNull($first);

        $this->travel(3)->seconds();
        $this->service->touch($match->fresh(), $this->anna);
        $this->assertTrue($first->equalTo($player()->last_seen_at));

        $this->travel(6)->seconds();
        $this->service->touch($match->fresh(), $this->anna);
        $this->assertTrue($player()->last_seen_at->greaterThan($first));
    }

    public function test_view_connected_etag_and_since(): void
    {
        $match = $this->started();
        $this->assertSame('2-00', MatchView::etag($match->fresh()));
        $this->service->touch($match->fresh(), $this->bo);
        $this->assertSame('2-01', MatchView::etag($match->fresh()));
        $this->assertSame([false, true], array_column(MatchView::for($match->fresh(), 0, null)['players'], 'connected'));

        $this->travel(11)->seconds();
        $this->assertSame('2-00', MatchView::etag($match->fresh()));

        ['match' => $waiting] = $this->create();
        $this->assertSame('1-00', MatchView::etag($waiting->fresh()));
        $this->assertCount(1, MatchView::for($waiting->fresh(), 0, null)['players']);

        $this->act($match, 0, 'set_secret', ['value' => 33]);
        $this->assertSame([2], array_column(MatchView::for($match->fresh(), 0, 1)['events'], 'seq'));
        $this->assertSame([], MatchView::for($match->fresh(), 0, 2)['events']);
        $this->assertSame(['secret' => 33], MatchView::for($match->fresh(), 0, null)['you']);
        $this->assertSame(['secret' => null], MatchView::for($match->fresh(), 1, null)['you']);
        // Säte 1 har inget tal än, så bara nycklar och you kontrolleras för säte 0.
        $this->assertNoLeak(MatchView::for($match->fresh(), 0, null), null, 33);
        $this->assertNoLeak(MatchView::for($match->fresh(), 1, null), 33, null);
    }

    public function test_view_caps_events_at_200(): void
    {
        $match = $this->started();
        for ($seq = 2; $seq <= 250; $seq++) {
            GameMatchEvent::create(['match_id' => $match->id, 'seq' => $seq, 'type' => 'ready', 'seat' => 0, 'payload' => []]);
        }

        $latest = array_column(MatchView::for($match->fresh(), 0, null)['events'], 'seq');
        $this->assertSame(range(51, 250), $latest);

        $since = array_column(MatchView::for($match->fresh(), 0, 0)['events'], 'seq');
        $this->assertSame(range(1, 200), $since);
    }

    public function test_leak_scanner_detects_planted_secret(): void
    {
        $base = ['you' => ['secret' => null], 'state' => ['guesses' => [[], []]], 'events' => []];
        $planted = [
            array_replace($base, ['state' => ['guesses' => [[], []], 'x' => 77]]),
            array_replace($base, ['events' => [['type' => 'locked', 'payload' => ['n' => 77]]]]),
            array_replace($base, ['events' => [['type' => 'guess', 'payload' => ['value' => 77, 'result' => 'lower']]]]),
            array_replace($base, ['players' => [['hidden_state' => null]]]),
            array_replace($base, ['you' => ['secret' => 77]]),
        ];
        foreach ($planted as $i => $view) {
            try {
                $this->assertNoLeak($view, 77, null);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail("scanner missed planted leak {$i}");
        }

        // Tillåtet: korrekt gissning och round_end.secrets.
        $this->assertNoLeak(array_replace($base, ['events' => [
            ['type' => 'guess', 'payload' => ['value' => 77, 'result' => 'correct']],
            ['type' => 'round_end', 'payload' => ['secrets' => [1, 77]]],
        ]]), 77, null);
    }

    public function test_expire(): void
    {
        ['match' => $waiting] = $this->create();
        $active = $this->started();
        $this->act($active, 0, 'set_secret', ['value' => 42]);

        // Inte gått ut än: ingenting händer.
        $before = $this->snapshot($active);
        $this->assertFalse($this->service->expire($active->fresh()));
        $this->assertFalse($this->service->expire($waiting->fresh()));
        $this->assertSame($before, $this->snapshot($active));
        $this->assertSame(1, $waiting->fresh()->version);
        $this->assertSame(0, GameMatchEvent::where('match_id', $waiting->id)->count());

        $this->travel(16)->minutes();
        $this->assertTrue($this->service->expire($waiting->fresh()));
        $w = $waiting->fresh();
        $this->assertSame(GameMatch::STATUS_ABANDONED, $w->status);
        $this->assertSame(2, $w->version);
        $this->assertNull($w->join_token_hash);
        $event = GameMatchEvent::where('match_id', $waiting->id)->sole();
        $this->assertSame(['expired', null, [], 1], [$event->type, $event->seat, $event->payload, $event->seq]);
        // Andra anropet: redan avslutad.
        $this->assertFalse($this->service->expire($w));
        $this->assertSame(2, $w->fresh()->version);

        $this->travel(15)->minutes();
        $versionBefore = $active->fresh()->version;
        $this->assertTrue($this->service->expire($active->fresh()));
        $a = $active->fresh();
        $this->assertSame(GameMatch::STATUS_ABANDONED, $a->status);
        $this->assertSame($versionBefore + 1, $a->version);
        $this->assertSame('expired', GameMatchEvent::where('match_id', $active->id)->orderByDesc('seq')->value('type'));
        $this->assertSame([null, null], GameMatchPlayer::where('match_id', $active->id)->orderBy('seat')->pluck('hidden_state')->all());
    }

    public function test_member_seat(): void
    {
        $match = $this->started();
        $this->assertSame(0, $this->service->memberSeat($match, $this->anna));
        $this->assertSame(1, $this->service->memberSeat($match, $this->bo));
        $this->assertNull($this->service->memberSeat($match, User::factory()->create()));
    }
}
