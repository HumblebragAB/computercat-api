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
        // Lottningen av startspelare är slump i produktion; testerna förutsätter säte 0.
        app()->instance(RevealRules::STARTER_DRAW_BINDING, fn (): int => 0);
        $this->rules = new RevealRules;
    }

    /** En startad match: round_begin för runda 1 är emitterad. */
    private function started(array $options = []): MatchContext
    {
        $options = $this->rules->validateOptions($options);
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
     * Säte 0 har talet 10, säte 1 talet 20. I ekot finns ingen sista chans, så där ger
     * 'starter' och null samma sak: startspelaren vinner.
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
            // I ekot avslutar startspelarens träff rundan direkt (ingen sista chans).
            if ($ctx->state()['phase'] === 'guessing') {
                $this->guess($ctx, $other, $outcome === 'starter' ? 50 : $secretOf[$starter]);
            }
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

        $this->assertSame(['min' => 1, 'max' => 100, 'best_of' => 3, 'mode' => 'duel'], $this->rules->validateOptions([]));
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

    private function forceStarter(int $seat): void
    {
        app()->instance(RevealRules::STARTER_DRAW_BINDING, fn (): int => $seat);
    }

    public function test_drawn_starter_is_in_round_begin_state_and_turn(): void
    {
        foreach ([0, 1] as $seat) {
            $this->forceStarter($seat);
            $ctx = $this->started();
            $this->assertSame(['round' => 1, 'starter' => $seat], $this->lastOf($ctx, 'round_begin')['payload']);
            $this->assertSame($seat, $ctx->state()['starter']);
            $this->lock($ctx, 10, 20);
            $this->assertSame($seat, $ctx->state()['turn']);
        }
    }

    public function test_later_rounds_alternate_from_the_drawn_starter(): void
    {
        $this->forceStarter(1);
        $ctx = $this->started();
        $this->playRound($ctx, null);
        $this->bothReady($ctx);
        $this->assertSame(['round' => 2, 'starter' => 0], $this->lastOf($ctx, 'round_begin')['payload']);
        $this->playRound($ctx, null);
        $this->bothReady($ctx);
        $this->assertSame(['round' => 3, 'starter' => 1], $this->lastOf($ctx, 'round_begin')['payload']);
    }

    public function test_unforced_draw_yields_both_starters(): void
    {
        app()->forgetInstance(RevealRules::STARTER_DRAW_BINDING);
        app()->offsetUnset(RevealRules::STARTER_DRAW_BINDING);
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[$this->started()->state()['starter']] = true;
        }
        // Båda utfallen uteblir med sannolikhet 2^-199.
        $this->assertEqualsCanonicalizing([0, 1], array_keys($seen));
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
            'last_chance' => false, 'guesses' => [[], []], 'winner' => null, 'mode' => 'duel',
        ], $this->rules->initialState(['min' => 1, 'max' => 100, 'best_of' => 3, 'mode' => 'duel']));
    }

    // ACTIONS och EVENTS är det manualen dokumenterar; de ska vara exakt vad reglerna gör.
    public function test_action_and_event_constants_match_the_rules(): void
    {
        $ctx = $this->playMatch(['starter', null, 'other', 'starter']);
        $emitted = array_values(array_unique(array_column($ctx->emitted(), 'type')));
        $this->assertEqualsCanonicalizing(RevealRules::EVENTS, $emitted);

        $ctx = $this->started();
        foreach (RevealRules::ACTIONS as $action) {
            try {
                $this->rules->act($ctx, 1, $action, []);
            } catch (IllegalMove $e) {
                $this->assertNotSame('unknown_action', $e->errorCode, $action);
            }
        }
        $this->illegal($ctx, 0, 'resign', [], 'unknown_action');
    }

    public function test_partial_default_options_accepted(): void
    {
        foreach ([['min' => 1], ['max' => 100, 'best_of' => 3], ['min' => 1, 'max' => 100, 'best_of' => 3]] as $options) {
            $this->assertSame(RevealRules::DEFAULT_OPTIONS, $this->rules->validateOptions($options));
        }
        foreach ([['min' => '1'], ['rounds' => 5]] as $options) {
            try {
                $this->rules->validateOptions($options);
                $this->fail('should reject '.json_encode($options));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mode_defaults_to_duel_and_echo_is_accepted(): void
    {
        $this->assertSame('duel', RevealRules::DEFAULT_OPTIONS['mode']);
        $this->assertSame(['duel', 'echo'], RevealRules::MODES);
        $this->assertSame('duel', $this->rules->validateOptions([])['mode']);
        $this->assertSame('duel', $this->rules->validateOptions(['mode' => 'duel'])['mode']);
        $this->assertSame(
            ['min' => 1, 'max' => 100, 'best_of' => 3, 'mode' => 'echo'],
            $this->rules->validateOptions(['min' => 1, 'mode' => 'echo']),
        );
        $this->assertSame(['min' => 1, 'max' => 100, 'best_of' => 3, 'mode' => 'echo'], $this->rules->validateOptions(['mode' => 'echo', 'best_of' => 3]));
    }

    public function test_mode_must_be_a_known_string(): void
    {
        foreach ([['mode' => 'bluff'], ['mode' => 'ECHO'], ['mode' => ' echo'], ['mode' => ''], ['mode' => 1], ['mode' => true], ['mode' => null], ['mode' => ['echo']], ['mode' => 'echo', 'max' => 50]] as $options) {
            try {
                $this->rules->validateOptions($options);
                $this->fail('should reject '.json_encode($options));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_state_carries_mode_from_options(): void
    {
        $this->assertSame('echo', $this->started(['mode' => 'echo'])->state()['mode']);
        $this->assertSame('duel', $this->started()->state()['mode']);
        // Äldre options utan mode (matcher skapade före läget) ger ren duell.
        $this->assertSame('duel', $this->rules->initialState(['min' => 1, 'max' => 100, 'best_of' => 3])['mode']);

        // Läget ändras aldrig av en händelse, inte heller över rundor.
        $ctx = $this->started(['mode' => 'echo']);
        $this->playRound($ctx, 'other');
        $this->bothReady($ctx);
        $this->assertSame('echo', $ctx->state()['mode']);
    }

    // Ekot: motspelaren får veta hur gissningen förhåller sig till gissarens EGET tal.
    public function test_echo_higher_lower_self_from_both_seats(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 17, 42);

        $plays = [
            // [säte, gissning, result mot motspelarens tal, echo mot eget tal]
            [0, 40, 'higher', 'lower'],
            [1, 10, 'higher', 'higher'],
            [0, 5, 'higher', 'higher'],
            [1, 50, 'lower', 'lower'],
            [0, 17, 'higher', 'self'],
            [1, 42, 'lower', 'self'],
        ];
        $expected = [[], []];
        foreach ($plays as [$seat, $value, $result, $echo]) {
            $event = $this->guess($ctx, $seat, $value);
            $this->assertSame(['value' => $value, 'result' => $result, 'echo' => $echo], $event['payload'], "seat {$seat} guesses {$value}");
            $this->assertSame($seat, $event['seat']);
            $expected[$seat][] = ['value' => $value, 'result' => $result, 'echo' => $echo];
        }
        $this->assertSame($expected, $ctx->state()['guesses']);

        // Träff har också ett eko. I ekot vinner första träffen, också startspelarens.
        $this->assertSame(['value' => 42, 'result' => 'correct', 'echo' => 'lower'], $this->guess($ctx, 0, 42)['payload']);
        $this->assertNull($this->lastOf($ctx, 'last_chance'));
        $this->assertSame(['winner' => 0, 'secrets' => [17, 42], 'guesses' => [4, 3]], $this->lastOf($ctx, 'round_end')['payload']);
    }

    // Ekot har ingen sista chans: första träffen vinner rundan, oavsett vem som startade.
    public function test_echo_starter_hit_wins_at_once(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 20);

        $this->assertNull($this->lastOf($ctx, 'last_chance'));
        $this->assertSame(['winner' => 0, 'secrets' => [10, 20], 'guesses' => [1, 0]], $this->lastOf($ctx, 'round_end')['payload']);
        $this->assertFalse($ctx->state()['last_chance']);
        $this->assertSame('round_over', $ctx->state()['phase']);
        $this->assertNull($ctx->state()['turn']);
        $this->assertSame([1, 0], $ctx->state()['score']);
        $this->illegal($ctx, 1, 'guess', ['value' => 10], 'wrong_phase');
    }

    public function test_echo_non_starter_hit_wins_at_once(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 50);
        $this->guess($ctx, 1, 10);

        $this->assertNull($this->lastOf($ctx, 'last_chance'));
        $this->assertSame(['winner' => 1, 'secrets' => [10, 20], 'guesses' => [1, 1]], $this->lastOf($ctx, 'round_end')['payload']);
        $this->assertSame([0, 1], $ctx->state()['score']);
    }

    // Ekot kan inte sluta oavgjort: varje runda har en vinnare, så matchen avgörs på högst tre rundor.
    public function test_echo_has_no_draws(): void
    {
        // Startspelaren växlar: 'starter' i runda 2 är säte 1. Utfall: 2–0, 0–2, 2–1, 2–1.
        foreach ([['starter', 'other'], ['other', 'starter'], ['starter', 'starter', 'starter'], ['other', 'other', 'starter']] as $outcomes) {
            $ctx = $this->started(['mode' => 'echo']);
            foreach ($outcomes as $i => $outcome) {
                if ($i > 0) {
                    $this->bothReady($ctx);
                }
                $this->playRound($ctx, $outcome);
                $this->assertNotNull($this->lastOf($ctx, 'round_end')['payload']['winner'], json_encode($outcomes));
            }
            $this->assertTrue($ctx->isFinished(), json_encode($outcomes));
            $this->assertNotNull($this->lastOf($ctx, 'match_end')['payload']['winner']);
            $this->assertSame(2, max($ctx->state()['score']));
            $this->assertNull($this->lastOf($ctx, 'last_chance'));
        }

        // playRound(null) är i ekot en vinst för startspelaren: hans träff avslutar rundan direkt.
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 20);
        $this->assertSame(0, $this->lastOf($ctx, 'round_end')['payload']['winner']);
    }

    // Ren duell behåller utjämningen.
    public function test_duel_keeps_last_chance(): void
    {
        $ctx = $this->started(['mode' => 'duel']);
        $this->lock($ctx, 10, 20);
        $this->guess($ctx, 0, 20);
        $this->assertSame(1, $this->lastOf($ctx, 'last_chance')['seat']);
        $this->guess($ctx, 1, 10);
        $this->assertNull($this->lastOf($ctx, 'round_end')['payload']['winner']);
    }

    public function test_echo_when_both_secrets_are_equal(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 30, 30);
        $this->assertSame(['value' => 30, 'result' => 'correct', 'echo' => 'self'], $this->guess($ctx, 0, 30)['payload']);
    }

    public function test_duel_guess_has_no_echo(): void
    {
        foreach ([[], ['mode' => 'duel']] as $options) {
            $ctx = $this->started($options);
            $this->lock($ctx, 17, 42);
            $this->assertSame(['value' => 17, 'result' => 'higher'], $this->guess($ctx, 0, 17)['payload']);
            $this->assertSame([['value' => 17, 'result' => 'higher']], $ctx->state()['guesses'][0]);
        }
    }

    // Ekot nämner aldrig talet: före round_end står det inga dolda tal i händelser eller state.
    public function test_echo_never_carries_a_number(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        $this->lock($ctx, 63, 77);
        foreach ([[0, 50], [1, 60], [0, 30], [1, 63]] as [$seat, $value]) {
            $event = $this->guess($ctx, $seat, $value);
            $this->assertContains($event['payload']['echo'], RevealRules::ECHOES);
        }
        // Säte 1:s 77 gissas aldrig och säte 0:s 63 bara av säte 1 (som value), aldrig i ett eko.
        foreach ($ctx->emitted() as $event) {
            if ($event['type'] === 'round_end') {
                continue;
            }
            $this->assertStringNotContainsString('77', json_encode($event['payload']), $event['type']);
            if (isset($event['payload']['echo'])) {
                $this->assertIsString($event['payload']['echo']);
                $this->assertFalse(is_numeric($event['payload']['echo']));
            }
        }
        $this->assertStringNotContainsString('77', json_encode($ctx->state()));
    }

    public function test_replay_equals_state_in_echo_mode(): void
    {
        $ctx = $this->started(['mode' => 'echo']);
        foreach (['starter', 'starter', 'other'] as $i => $outcome) {
            if ($i > 0) {
                $this->bothReady($ctx);
            }
            $this->playRound($ctx, $outcome);
        }
        $this->assertTrue($ctx->isFinished());
        $this->assertNotEmpty(array_filter($ctx->emitted(), fn ($e) => $e['type'] === 'guess' && isset($e['payload']['echo'])));

        $state = $this->rules->initialState($ctx->options());
        foreach ($ctx->emitted() as $event) {
            $state = $this->rules->apply($state, $event['type'], $event['seat'], $event['payload']);
        }
        $this->assertSame($ctx->state(), $state);
        $this->assertSame('echo', $state['mode']);
    }

    // Lagrad state från före läget saknar mode. Den spelar upp som ren duell och vyn kompletterar den.
    public function test_legacy_state_without_mode(): void
    {
        $legacyOptions = ['min' => 1, 'max' => 100, 'best_of' => 3];
        $state = $this->rules->initialState($legacyOptions);
        unset($state['mode']);
        $ctx = new MatchContext($this->rules, $legacyOptions, $state);
        $this->rules->start($ctx);
        $this->lock($ctx, 17, 42);
        $this->assertSame(['value' => 17, 'result' => 'higher'], $this->guess($ctx, 0, 17)['payload']);
        $this->assertArrayNotHasKey('mode', $ctx->state());

        $upgraded = $this->rules->upgradeState($ctx->state());
        $this->assertSame('duel', $upgraded['mode']);
        $this->assertSame($ctx->state(), array_diff_key($upgraded, ['mode' => true]));
        // Uppspelat från options utan mode blir det samma som den kompletterade lagrade staten.
        $replayed = $this->rules->initialState($legacyOptions);
        foreach ($ctx->emitted() as $event) {
            $replayed = $this->rules->apply($replayed, $event['type'], $event['seat'], $event['payload']);
        }
        ksort($upgraded);
        ksort($replayed);
        $this->assertSame($upgraded, $replayed);
        $this->assertSame('duel', $replayed['mode']);

        // Befintligt läge skrivs aldrig över.
        $this->assertSame('echo', $this->rules->upgradeState(['mode' => 'echo'])['mode']);
    }
}
