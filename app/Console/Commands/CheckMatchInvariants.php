<?php

namespace App\Console\Commands;

use App\Matches\MatchContext;
use App\Matches\Rules\MatchRules;
use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Invarianterna för matcher, för matcher som ändrats senaste dygnet (status spelar ingen roll):
 *  1. händelseloggens seq är exakt 1..n utan hål,
 *  2. händelserna uppspelade genom regelklassen från initialState(options) ger den lagrade state,
 *  3. ingen avslutad eller övergiven match har kvar hidden_state hos någon spelare.
 *
 * Reglerna slås upp direkt ur config('matches.rulesets.<ruleset>.class') utan spelspärren:
 * kontrollen gäller lagrad data, inte om spelet får skapa nya matcher. Okänt ruleset är en avvikelse.
 *
 * Larmar i loggen och med exit 1. Loggen och utskriften nämner bara match-id och vilken invariant
 * som bryts, aldrig hidden_state eller state.
 */
class CheckMatchInvariants extends Command
{
    protected $signature = 'matches:check-invariants';

    protected $description = 'Kontrollerar att händelseloggen, lagrad state och dold state hos matcher från senaste dygnet stämmer';

    public function handle(): int
    {
        $checked = 0;
        /** @var array<string, list<string>> matchid => brutna invarianter */
        $violations = [];

        GameMatch::where('updated_at', '>=', now()->subDay())->chunkById(200, function ($matches) use (&$checked, &$violations) {
            foreach ($matches as $match) {
                $checked++;
                $broken = $this->check($match);
                if ($broken !== []) {
                    $violations[$match->id] = $broken;
                }
            }
        });

        if ($violations !== []) {
            foreach ($violations as $id => $broken) {
                $this->error("{$id}: ".implode(', ', $broken));
            }
            Log::error('Matcher: invarianter bryts', ['violations' => $violations]);

            return self::FAILURE;
        }
        $this->info("Matchinvarianterna håller ({$checked} matcher kontrollerade).");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function check(GameMatch $match): array
    {
        $broken = [];

        $events = GameMatchEvent::where('match_id', $match->id)->orderBy('seq')->get();
        if ($events->pluck('seq')->all() !== range(1, $events->count())) {
            $broken[] = 'seq_gap';
        }

        $rules = $this->rules($match->ruleset);
        if ($rules === null) {
            $broken[] = 'unknown_ruleset';
        } else {
            try {
                $state = $rules->initialState($match->options ?? []);
                foreach ($events as $event) {
                    if (in_array($event->type, MatchContext::RESERVED_TYPES, true)) {
                        continue;
                    }
                    $state = $rules->apply($state, $event->type, $event->seat, $event->payload ?? []);
                }
                if (self::canonical($state) !== self::canonical($match->state)) {
                    $broken[] = 'state_mismatch';
                }
            } catch (Throwable) {
                // Meddelandet kan innehålla state; bara att uppspelningen föll noteras.
                $broken[] = 'replay_failed';
            }
        }

        if (in_array($match->status, [GameMatch::STATUS_FINISHED, GameMatch::STATUS_ABANDONED], true)
            && GameMatchPlayer::where('match_id', $match->id)->whereNotNull('hidden_state')->exists()) {
            $broken[] = 'hidden_state_left';
        }

        return $broken;
    }

    private function rules(string $ruleset): ?MatchRules
    {
        $class = config("matches.rulesets.{$ruleset}.class");

        return is_string($class) && is_a($class, MatchRules::class, true) ? app($class) : null;
    }

    /** MySQL:s JSON-kolumn ordnar om objektnycklar; listor behåller ordningen. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
