<?php

namespace App\Matches\Rules;

use App\Matches\IllegalMove;
use App\Matches\MatchContext;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Reveal: båda låser ett hemligt tal och gissar turvis motspelarens, med svaret högre/lägre.
 * Hittar startspelaren rätt får motspelaren en sista chans (utjämning). Först till två
 * vunna rundor, tak på fem rundor. Motspelarens tal lämnar bara servern i round_end.
 */
class RevealRules implements MatchRules
{
    public const DEFAULT_OPTIONS = ['min' => 1, 'max' => 100, 'best_of' => 3];

    /** Rundor att vinna för att ta matchen (best_of 3). */
    private const WINS_NEEDED = 2;

    private const MAX_ROUNDS = 5;

    public function validateOptions(array $options): array
    {
        $normalized = array_replace(self::DEFAULT_OPTIONS, $options);

        if ($normalized !== self::DEFAULT_OPTIONS) {
            throw ValidationException::withMessages([
                'options' => 'Reveal stöder bara min 1, max 100, best_of 3.',
            ]);
        }

        return self::DEFAULT_OPTIONS;
    }

    public function initialState(array $options): array
    {
        return [
            'round' => 0,
            'phase' => 'choosing',
            'starter' => 0,
            'turn' => null,
            'locked' => [false, false],
            'ready' => [false, false],
            'score' => [0, 0],
            'last_chance' => false,
            'guesses' => [[], []],
            'winner' => null,
        ];
    }

    public function apply(array $state, string $type, ?int $seat, array $payload): array
    {
        switch ($type) {
            case 'round_begin':
                $state['round'] = $payload['round'];
                $state['starter'] = $payload['starter'];
                $state['phase'] = 'choosing';
                $state['turn'] = null;
                $state['locked'] = [false, false];
                $state['ready'] = [false, false];
                $state['guesses'] = [[], []];
                $state['last_chance'] = false;
                break;
            case 'locked':
                $state['locked'][$this->seat($seat)] = true;
                break;
            case 'duel_begin':
                $state['phase'] = 'guessing';
                $state['turn'] = $state['starter'];
                break;
            case 'guess':
                $s = $this->seat($seat);
                $state['guesses'][$s][] = ['value' => $payload['value'], 'result' => $payload['result']];
                $state['turn'] = 1 - $s;
                break;
            case 'last_chance':
                $state['last_chance'] = true;
                $state['turn'] = $this->seat($seat);
                break;
            case 'round_end':
                $state['phase'] = 'round_over';
                $state['turn'] = null;
                if ($payload['winner'] !== null) {
                    $state['score'][$payload['winner']]++;
                }
                break;
            case 'ready':
                $state['ready'][$this->seat($seat)] = true;
                break;
            case 'match_end':
                $state['phase'] = 'match_over';
                $state['winner'] = $payload['winner'];
                break;
            default:
                throw new LogicException("Unknown Reveal event '{$type}'.");
        }

        return $state;
    }

    public function start(MatchContext $ctx): void
    {
        $ctx->setHidden(0, null);
        $ctx->setHidden(1, null);
        $ctx->emit('round_begin', null, ['round' => 1, 'starter' => 0]);
    }

    public function act(MatchContext $ctx, int $seat, string $type, array $payload): void
    {
        $this->seat($seat);

        match ($type) {
            'set_secret' => $this->setSecret($ctx, $seat, $payload),
            'guess' => $this->guess($ctx, $seat, $payload),
            'ready' => $this->ready($ctx, $seat),
            default => throw new IllegalMove('unknown_action'),
        };
    }

    public function view(?array $hidden): array
    {
        return ['secret' => $hidden['secret'] ?? null];
    }

    private function setSecret(MatchContext $ctx, int $seat, array $payload): void
    {
        $state = $ctx->state();
        if ($state['phase'] !== 'choosing') {
            throw new IllegalMove('wrong_phase');
        }
        if ($state['locked'][$seat]) {
            throw new IllegalMove('already_locked');
        }
        $value = $this->value($ctx, $payload);

        $ctx->setHidden($seat, ['secret' => $value]);
        $ctx->emit('locked', $seat);

        if ($ctx->state()['locked'] === [true, true]) {
            $ctx->emit('duel_begin', null);
        }
    }

    private function guess(MatchContext $ctx, int $seat, array $payload): void
    {
        $state = $ctx->state();
        if ($state['phase'] !== 'guessing') {
            throw new IllegalMove('wrong_phase');
        }
        if ($state['turn'] !== $seat) {
            throw new IllegalMove('not_your_turn');
        }
        $value = $this->value($ctx, $payload);

        $secret = $ctx->hidden(1 - $seat)['secret'] ?? null;
        if (! is_int($secret)) {
            throw new LogicException('Opponent has no secret during guessing.');
        }

        $result = match (true) {
            $secret === $value => 'correct',
            $secret > $value => 'higher',
            default => 'lower',
        };
        $ctx->emit('guess', $seat, ['value' => $value, 'result' => $result]);

        $starter = $state['starter'];
        $correct = $result === 'correct';

        if ($state['last_chance']) {
            // Sista chansen: träff ger oavgjort, miss ger startspelaren rundan.
            $this->endRound($ctx, $correct ? null : $starter);
        } elseif ($correct && $seat !== $starter) {
            $this->endRound($ctx, $seat);
        } elseif ($correct) {
            $ctx->emit('last_chance', 1 - $seat);
        }
    }

    private function ready(MatchContext $ctx, int $seat): void
    {
        $state = $ctx->state();
        if ($state['phase'] !== 'round_over') {
            throw new IllegalMove('wrong_phase');
        }
        if ($state['ready'][$seat]) {
            throw new IllegalMove('already_ready');
        }

        $ctx->emit('ready', $seat);

        if ($ctx->state()['ready'] === [true, true]) {
            $ctx->setHidden(0, null);
            $ctx->setHidden(1, null);
            $ctx->emit('round_begin', null, [
                'round' => $state['round'] + 1,
                'starter' => 1 - $state['starter'],
            ]);
        }
    }

    private function endRound(MatchContext $ctx, ?int $winner): void
    {
        $guesses = $ctx->state()['guesses'];
        $ctx->emit('round_end', null, [
            'winner' => $winner,
            'secrets' => [$ctx->hidden(0)['secret'] ?? null, $ctx->hidden(1)['secret'] ?? null],
            'guesses' => [count($guesses[0]), count($guesses[1])],
        ]);

        $state = $ctx->state();
        [$w0, $w1] = $state['score'];

        if ($w0 >= self::WINS_NEEDED || $w1 >= self::WINS_NEEDED || $state['round'] >= self::MAX_ROUNDS) {
            $ctx->emit('match_end', null, ['winner' => $w0 === $w1 ? null : ($w0 > $w1 ? 0 : 1)]);
            $ctx->finish();
        }
    }

    /** Bara JSON-heltal i intervallet godtas. */
    private function value(MatchContext $ctx, array $payload): int
    {
        $value = $payload['value'] ?? null;
        if (! is_int($value)) {
            throw new IllegalMove('invalid_payload');
        }
        $options = $ctx->options();
        if ($value < $options['min'] || $value > $options['max']) {
            throw new IllegalMove('out_of_range');
        }

        return $value;
    }

    private function seat(?int $seat): int
    {
        if ($seat !== 0 && $seat !== 1) {
            throw new LogicException('Reveal event requires seat 0 or 1.');
        }

        return $seat;
    }
}
