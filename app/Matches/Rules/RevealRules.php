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
 *
 * Läget (options.mode, också i state.mode) är 'duel' eller 'echo'. I ekot bär varje gissning
 * dessutom ett eko till motspelaren: högre, lägre eller self mot gissarens EGET tal. Ekot är
 * alltid ett ord, aldrig talet. I ekot vinner första träffen rundan: ingen sista chans, så
 * rundor och matcher kan bara sluta oavgjort i duell. Lagrad state från före läget saknar
 * mode och räknas som 'duel'.
 */
class RevealRules implements MatchRules
{
    public const DEFAULT_OPTIONS = ['min' => 1, 'max' => 100, 'best_of' => 3, 'mode' => 'duel'];

    /** Spellägen. Manualen (IntegrationGuide) dokumenterar varje. */
    public const MODES = ['duel', 'echo'];

    /** Ekots värden i guess-händelsen (bara i läget echo), sett från gissarens eget tal. */
    public const ECHOES = ['higher', 'lower', 'self'];

    /** Läget för lagrad state och options som saknar mode (skapade före läget). */
    public const LEGACY_MODE = 'duel';

    /** Drag en spelare kan skicka. Manualen (IntegrationGuide) dokumenterar varje. */
    public const ACTIONS = ['set_secret', 'guess', 'ready'];

    /** Händelser reglerna emitterar. Manualen (IntegrationGuide) dokumenterar varje. */
    public const EVENTS = ['round_begin', 'locked', 'duel_begin', 'guess', 'last_chance', 'round_end', 'ready', 'match_end'];

    /** Rundor att vinna för att ta matchen (best_of 3). */
    public const WINS_NEEDED = 2;

    public const MAX_ROUNDS = 5;

    public function validateOptions(array $options): array
    {
        $normalized = array_replace(self::DEFAULT_OPTIONS, $options);
        $mode = $normalized['mode'];
        $fixed = array_diff_key(self::DEFAULT_OPTIONS, ['mode' => true]);

        // Strikt: inga okända nycklar, min/max/best_of exakt standard, mode en känd sträng.
        if (array_diff_key($normalized, self::DEFAULT_OPTIONS) !== []
            || array_intersect_key($normalized, $fixed) !== $fixed
            || ! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages([
                'options' => 'Reveal stöder bara min 1, max 100, best_of 3 och mode duel eller echo.',
            ]);
        }

        return array_replace(self::DEFAULT_OPTIONS, ['mode' => $mode]);
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
            'mode' => $options['mode'] ?? self::LEGACY_MODE,
        ];
    }

    /** Lagrad state från före läget saknar mode; den är ren duell. Befintligt läge rörs inte. */
    public function upgradeState(array $state): array
    {
        $state['mode'] ??= self::LEGACY_MODE;

        return $state;
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
                $entry = ['value' => $payload['value'], 'result' => $payload['result']];
                if (array_key_exists('echo', $payload)) {
                    $entry['echo'] = $payload['echo'];
                }
                $state['guesses'][$s][] = $entry;
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

        if (! in_array($type, self::ACTIONS, true)) {
            throw new IllegalMove('unknown_action');
        }

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
        $payload = ['value' => $value, 'result' => $result];
        if (($state['mode'] ?? self::LEGACY_MODE) === 'echo') {
            $payload['echo'] = $this->echo($ctx, $seat, $value);
        }
        $ctx->emit('guess', $seat, $payload);

        $starter = $state['starter'];
        $correct = $result === 'correct';

        if ($correct && ($state['mode'] ?? self::LEGACY_MODE) === 'echo') {
            // Ekot: första träffen vinner rundan, ingen sista chans och ingen oavgjord runda.
            $this->endRound($ctx, $seat);
        } elseif ($state['last_chance']) {
            // Sista chansen: träff ger oavgjort, miss ger startspelaren rundan.
            $this->endRound($ctx, $correct ? null : $starter);
        } elseif ($correct && $seat !== $starter) {
            $this->endRound($ctx, $seat);
        } elseif ($correct) {
            $ctx->emit('last_chance', 1 - $seat);
        }
    }

    /** Ekot: gissningen mot gissarens eget dolda tal. Bara ordet lämnar servern. */
    private function echo(MatchContext $ctx, int $seat, int $value): string
    {
        $own = $ctx->hidden($seat)['secret'] ?? null;
        if (! is_int($own)) {
            throw new LogicException('Guesser has no secret during guessing.');
        }

        return match (true) {
            $own === $value => 'self',
            $own > $value => 'higher',
            default => 'lower',
        };
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
