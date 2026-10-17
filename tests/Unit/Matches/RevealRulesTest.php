<?php

namespace Tests\Unit\Matches;

use App\Matches\IllegalMove;
use App\Matches\MatchContext;
use App\Matches\Rules\RevealRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RevealRulesTest extends TestCase
{
    private RevealRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new RevealRules;
    }

    /** En startad match: round_begin för runda 1 är emitterad. */
    private function started(): MatchContext
    {
        $options = $this->rules->validateOptions([]);
        $ctx = new MatchContext($this->rules, $options, $this->rules->initialState($options));
        $this->rules->start($ctx);

        return $ctx;
    }

    private function illegal(MatchContext $ctx, int $seat, string $type, array $payload, string $code): void
    {
        $before = [$ctx->state(), count($ctx->emitted()), $ctx->hidden(0), $ctx->hidden(1)];
        try {
            $this->rules->act($ctx, $seat, $type, $payload);
            $this->fail("Expected IllegalMove {$code}");
        } catch (IllegalMove $e) {
            $this->assertSame($code, $e->errorCode);
        }
        $this->assertSame($before, [$ctx->state(), count($ctx->emitted()), $ctx->hidden(0), $ctx->hidden(1)]);
    }

    private function lock(MatchContext $ctx, int $s0, int $s1): void
    {
        $this->rules->act($ctx, 0, 'set_secret', ['value' => $s0]);
        $this->rules->act($ctx, 1, 'set_secret', ['value' => $s1]);
    }

    private function guess(MatchContext $ctx, int $seat, int $value): array
    {
        $this->rules->act($ctx, $seat, 'guess', ['value' => $value]);

        return $this->lastOf($ctx, 'guess');
    }

    private function lastOf(MatchContext $ctx, string $type): ?array
    {
        $found = null;
        foreach ($ctx->emitted() as $event) {
            if ($event['type'] === $type) {
                $found = $event;
            }
        }

        return $found;
    }

    private function bothReady(MatchContext $ctx): void
    {
        $this->rules->act($ctx, 0, 'ready', []);
        $this->rules->act($ctx, 1, 'ready', []);
    }

    /**
     * Spelar en hel runda med givet utfall: 'starter', 'other' eller null (oavgjort).
     * Säte 0 har talet 10, säte 1 talet 20.
     */
    private function playRound(MatchContext $ctx, ?string $outcome): void
    {
        $this->lock($ctx, 10, 20);
        $starter = $ctx->state()['starter'];
        $other = 1 - $starter;
        $secretOf = [10, 20];

        if ($outcome === 'other') {
            $this->guess($ctx, $starter, 50);
            $this->guess($ctx, $other, $secretOf[$starter]);
        } else {
            $this->guess($ctx, $starter, $secretOf[$other]);
            $this->guess($ctx, $other, $outcome === 'starter' ? 50 : $secretOf[$starter]);
        }
    }

    public function test_options_reject_anything_but_default(): void
    {
        foreach ([['max' => 1000], ['best_of' => 5]] as $options) {
            try {
                $this->rules->validateOptions($options);
                $this->fail('should reject '.json_encode($options));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(['min' => 1, 'max' => 100, 'best_of' => 3], $this->rules->validateOptions([]));
    }

    public function test_non_integer_values_rejected(): void
    {
        $ctx = $this->started();
        foreach (['37', 37.0, 37.5, true, null, []] as $bad) {
            $this->illegal($ctx, 0, 'set_secret', ['value' => $bad], 'invalid_payload');
        }
        $this->illegal($ctx, 0, 'set_secret', [], 'invalid_payload');
        $this->illegal($ctx, 0, 'set_secret', ['value' => 0], 'out_of_range');
        $this->illegal($ctx, 0, 'set_secret', ['value' => 101], 'out_of_range');

        $this->lock($ctx, 42, 7);
        foreach (['37', 37.0, 37.5, true, null, []] as $bad) {
            $this->illegal($ctx, 0, 'guess', ['value' => $bad], 'invalid_payload');
        }
        $this->illegal($ctx, 0, 'guess', ['value' => 0], 'out_of_range');
        $this->illegal($ctx, 0, 'guess', ['value' => 101], 'out_of_range');
    }

    public function test_higher_lower_correct(): void
    {
        $ctx = $this->started();
        $this->lock($ctx, 5, 42);

        $this->assertSame(['value' => 50, 'result' => 'lower'], $this->guess($ctx, 0, 50)['payload']);
        $this->guess($ctx, 1, 90);
        $this->assertSame(['value' => 30, 'result' => 'higher'], $this->guess($ctx, 0, 30)['payload']);
        $this->guess($ctx, 1, 80);
        $event = $this->guess($ctx, 0, 42);
        $this->assertSame(['value' => 42, 'result' => 'correct'], $event['payload']);
        $this->assertSame(0, $event['seat']);

        $this->assertSame([
            [['value' => 50, 'result' => 'lower'], ['value' => 30, 'result' => 'higher'], ['value' => 42, 'result' => 'correct']],
            [['value' => 90, 'result' => 'lower'], ['value' => 80, 'result' => 'lower']],
        ], $ctx->state()['guesses']);
    }

    public function test_non_starter_hit_wins_immediately(): void
    {
        $ctx = $this->started();
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 50);
        $this->guess($ctx, 1, 10);

        $end = $this->lastOf($ctx, 'round_end');
        $this->assertSame(['winner' => 1, 'secrets' => [10, 20], 'guesses' => [1, 1]], $end['payload']);
        $this->assertNull($this->lastOf($ctx, 'last_chance'));
        $this->assertSame('round_over', $ctx->state()['phase']);
        $this->assertNull($ctx->state()['turn']);
        $this->assertSame([0, 1], $ctx->state()['score']);
    }

    public function test_starter_hit_gives_last_chance_then_wins(): void
    {
        $ctx = $this->started();
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 20);

        $lastChance = $this->lastOf($ctx, 'last_chance');
        $this->assertSame(1, $lastChance['seat']);
        $this->assertTrue($ctx->state()['last_chance']);
        $this->assertSame(1, $ctx->state()['turn']);
        $this->assertNull($this->lastOf($ctx, 'round_end'));

        $this->guess($ctx, 1, 50);
        $end = $this->lastOf($ctx, 'round_end');
        $this->assertSame(['winner' => 0, 'secrets' => [10, 20], 'guesses' => [1, 1]], $end['payload']);
        $this->assertSame([1, 0], $ctx->state()['score']);
    }

    public function test_last_chance_hit_is_draw(): void
    {
        $ctx = $this->started();
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 20);
        $this->guess($ctx, 1, 10);

        $end = $this->lastOf($ctx, 'round_end');
        $this->assertSame(['winner' => null, 'secrets' => [10, 20], 'guesses' => [1, 1]], $end['payload']);
        $this->assertSame([0, 0], $ctx->state()['score']);
        $this->assertSame('round_over', $ctx->state()['phase']);
    }

    public function test_start_is_not_an_action(): void
    {
        $ctx = $this->started();
        $this->illegal($ctx, 0, 'start', [], 'unknown_action');
        $this->illegal($ctx, 0, 'nonsense', [], 'unknown_action');
    }

    public function test_turn_and_phase_enforced(): void
    {
        $ctx = $this->started();
        $this->illegal($ctx, 0, 'guess', ['value' => 5], 'wrong_phase');
        $this->illegal($ctx, 0, 'ready', [], 'wrong_phase');

        $this->rules->act($ctx, 0, 'set_secret', ['value' => 10]);
        $this->illegal($ctx, 0, 'set_secret', ['value' => 11], 'already_locked');
        $this->assertSame(['secret' => 10], $ctx->hidden(0));
        $this->rules->act($ctx, 1, 'set_secret', ['value' => 20]);

        $this->illegal($ctx, 1, 'set_secret', ['value' => 21], 'wrong_phase');
        $this->illegal($ctx, 1, 'guess', ['value' => 5], 'not_your_turn');
        $this->guess($ctx, 0, 5);
        $this->illegal($ctx, 0, 'guess', ['value' => 6], 'not_your_turn');
        $this->guess($ctx, 1, 10);

        $this->rules->act($ctx, 0, 'ready', []);
        $this->illegal($ctx, 0, 'ready', [], 'already_ready');
        $this->illegal($ctx, 1, 'guess', ['value' => 5], 'wrong_phase');
    }

    public function test_starter_alternates(): void
    {
        $ctx = $this->started();
        $this->assertSame(['round' => 1, 'starter' => 0], $this->lastOf($ctx, 'round_begin')['payload']);

        $this->playRound($ctx, null);
        $this->bothReady($ctx);
        $this->assertSame(['round' => 2, 'starter' => 1], $this->lastOf($ctx, 'round_begin')['payload']);
        $this->assertSame('choosing', $ctx->state()['phase']);
        $this->assertSame([false, false], $ctx->state()['locked']);
        $this->assertSame([[], []], $ctx->state()['guesses']);
        $this->assertNull($ctx->hidden(0));
        $this->assertNull($ctx->hidden(1));

        $this->lock($ctx, 10, 20);
        $this->assertSame(1, $ctx->state()['turn']);
        $this->guess($ctx, 1, 10);
        $this->guess($ctx, 0, 20);
        $this->bothReady($ctx);
        $this->assertSame(['round' => 3, 'starter' => 0], $this->lastOf($ctx, 'round_begin')['payload']);
    }

    public function test_first_to_two_wins_match(): void
    {
        $ctx = $this->started();
        // Runda 1: säte 0 startar och vinner. Runda 2: säte 1 startar, säte 0 träffar först.
        $this->playRound($ctx, 'starter');
        $this->bothReady($ctx);
        $this->assertFalse($ctx->isFinished());
        $this->playRound($ctx, 'other');

        $this->assertSame(['winner' => 0], $this->lastOf($ctx, 'match_end')['payload']);
        $this->assertTrue($ctx->isFinished());
        $this->assertSame('match_over', $ctx->state()['phase']);
        $this->assertSame(0, $ctx->state()['winner']);
        $this->assertSame([2, 0], $ctx->state()['score']);
        $this->illegal($ctx, 0, 'ready', [], 'wrong_phase');
    }

    /** @param list<?string> $outcomes */
    private function playMatch(array $outcomes): MatchContext
    {
        $ctx = $this->started();
        foreach ($outcomes as $i => $outcome) {
            $this->playRound($ctx, $outcome);
            if ($i < count($outcomes) - 1) {
                $this->assertFalse($ctx->isFinished(), 'finished after round '.($i + 1));
                $this->bothReady($ctx);
            }
        }

        return $ctx;
    }

    public function test_five_round_cap(): void
    {
        $ctx = $this->playMatch([null, null, null, null, null]);
        $this->assertSame(['winner' => null], $this->lastOf($ctx, 'match_end')['payload']);
        $this->assertTrue($ctx->isFinished());
        $this->assertNull($ctx->state()['winner']);

        // Runda 1 startar säte 0 (vinner), runda 2 startar säte 1 (vinner): 1–1.
        $ctx = $this->playMatch(['starter', 'starter', null, null, null]);
        $this->assertSame([1, 1], $ctx->state()['score']);
        $this->assertSame(['winner' => null], $this->lastOf($ctx, 'match_end')['payload']);
        $this->assertTrue($ctx->isFinished());

        $ctx = $this->playMatch(['starter', null, null, null, null]);
        $this->assertSame([1, 0], $ctx->state()['score']);
        $this->assertSame(['winner' => 0], $this->lastOf($ctx, 'match_end')['payload']);
        $this->assertTrue($ctx->isFinished());
        $this->assertSame(5, $ctx->state()['round']);
    }

    public function test_secret_only_in_round_end(): void
    {
        $ctx = $this->started();
        $states = [$ctx->state()];

        $this->rules->act($ctx, 0, 'set_secret', ['value' => 42]);
        $states[] = $ctx->state();
        $this->rules->act($ctx, 1, 'set_secret', ['value' => 77]);
        $states[] = $ctx->state();
        foreach ([[0, 50], [1, 60], [0, 30], [1, 42]] as [$seat, $value]) {
            $this->guess($ctx, $seat, $value);
            $states[] = $ctx->state();
        }
        $this->assertSame(1, $this->lastOf($ctx, 'round_end')['payload']['winner']);

        // Säte 1:s tal 77 gissas aldrig rätt, så det får bara synas i round_end.secrets.
        foreach ($ctx->emitted() as $event) {
            if ($event['type'] === 'round_end') {
                $this->assertSame([42, 77], $event['payload']['secrets']);

                continue;
            }
            $this->assertStringNotContainsString('77', json_encode($event['payload']), $event['type']);
            $this->assertArrayNotHasKey('secret', $event['payload']);
            $this->assertArrayNotHasKey('secrets', $event['payload']);
        }
        $this->assertSame([], $this->lastOf($ctx, 'locked')['payload']);

        foreach ($states as $state) {
            $this->assertStringNotContainsString('77', json_encode($state));
            $this->assertStringNotContainsString('secret', json_encode($state));
        }

        $this->assertSame(['secret' => 77], $this->rules->view($ctx->hidden(1)));
        $this->assertSame(['secret' => null], $this->rules->view(null));
    }

    public function test_replay_equals_state(): void
    {
        $ctx = $this->playMatch(['starter', null, 'other', 'starter']);
        $this->assertTrue($ctx->isFinished());

        $state = $this->rules->initialState($ctx->options());
        foreach ($ctx->emitted() as $event) {
            $state = $this->rules->apply($state, $event['type'], $event['seat'], $event['payload']);
        }

        $this->assertSame($ctx->state(), $state);
    }

    public function test_initial_state_shape(): void
    {
        $this->assertSame([
            'round' => 0, 'phase' => 'choosing', 'starter' => 0, 'turn' => null,
            'locked' => [false, false], 'ready' => [false, false], 'score' => [0, 0],
            'last_chance' => false, 'guesses' => [[], []], 'winner' => null,
        ], $this->rules->initialState(['min' => 1, 'max' => 100, 'best_of' => 3]));
    }
}
