<?php

namespace Tests\Concerns;

/**
 * Strukturell läckkontroll av en matchvy (MatchView::for) sedd från ett säte.
 *
 * Söker aldrig efter delsträngar i JSON. Kontrollen går på nycklar och sökvägar:
 * - `you` är exakt ['secret' => eget tal];
 * - ingen nyckel heter hidden_state, hidden eller secret någonstans utanför `you`;
 * - `secrets` får bara finnas i payload för round_end;
 * - motspelarens aktuella tal får, före rundans round_end, bara stå under värdenycklar:
 *   state.guesses[*][*].value och payload.value i guess-händelser, och då bara om talet
 *   redan gissats i rundan. Varje annat heltal med samma värde är en läcka, utom under de
 *   uppräknade räknar- och sätesfälten (seq, version, round …), så små tal ger inte falsklarm.
 *   Talet räknas också som läckt om det står som numerisk sträng ("7", "7.0") eller flyttal (7.0).
 *
 * Rundgränserna är Reveal-specifika: aktuell runda börjar vid sista round_begin och talet
 * räknas som avslöjat vid round_end, och tillåtna platser (guesses, secrets) följer Reveals
 * händelseformer. En annan regeluppsättning behöver en egen variant av den här kontrollen.
 *
 * assertNoLeakInError() gäller felsvar ({message, code, ...}): samma nyckelkontroll och en
 * värdesökning där inga platser alls är tillåtna utöver de strukturella fälten.
 */
trait AssertsNoMatchLeak
{
    /** Heltalsfält som är räknare, säten eller id och därför inte kan bära ett dolt tal. */
    private static array $structuralKeys = ['seq', 'version', 'seat', 'your_seat', 'round', 'starter', 'turn', 'winner'];

    /**
     * @param  ?int  $opponentSecret  motspelarens tal i aktuell runda, null om inget är låst
     * @param  ?int  $ownSecret  det egna talet som `you` ska visa
     * @param  list<int>  $guessedThisRound  värden som gissats (av någon av dem) i aktuell runda
     */
    protected function assertNoLeak(array $view, ?int $opponentSecret, ?int $ownSecret, array $guessedThisRound = []): void
    {
        $this->assertSame(['secret' => $ownSecret], $view['you'] ?? null, 'you must carry only the own secret');

        $rest = $view;
        unset($rest['you']);
        $this->assertLeakKeysAbsent($rest, '');

        $events = array_values($view['events'] ?? []);
        foreach ($events as $k => $event) {
            if (($event['type'] ?? null) !== 'round_end') {
                $this->assertArrayNotHasKey('secrets', $event['payload'] ?? [], "secrets outside round_end at events.{$k}");
            }
        }

        if ($opponentSecret === null) {
            return;
        }

        // Aktuell runda börjar vid sista round_begin. Har den redan en round_end är talet avslöjat.
        $currentFrom = 0;
        foreach ($events as $k => $event) {
            if ($event['type'] === 'round_begin') {
                $currentFrom = $k;
            }
        }
        for ($k = $currentFrom; $k < count($events); $k++) {
            if ($events[$k]['type'] === 'round_end') {
                return;
            }
        }

        $state = $view['state'] ?? [];
        $guessed = fn ($v) => in_array($v, $guessedThisRound, true);

        $this->scanValues($state, 'state', $opponentSecret, function (array $path) use ($guessed, $opponentSecret) {
            // state.guesses.{seat}.{i}.value
            if (count($path) === 5 && $path[1] === 'guesses' && $path[4] === 'value') {
                return $guessed($opponentSecret);
            }

            // state.score.{seat}
            return count($path) === 3 && $path[1] === 'score';
        });

        foreach ($events as $k => $event) {
            $isGuess = $event['type'] === 'guess';
            $isCurrent = $k >= $currentFrom;
            $this->scanValues($event, "events.{$k}", $opponentSecret, function (array $path) use ($isGuess, $isCurrent, $guessed, $opponentSecret, $event) {
                // events.{k}.payload.value i en gissning: tillåtet i tidigare rundor, i aktuell bara om gissat.
                if (count($path) === 4 && $path[2] === 'payload' && $path[3] === 'value' && $isGuess) {
                    return ! $isCurrent || $guessed($opponentSecret);
                }
                // round_end i tidigare rundor: avslöjade tal och antal gissningar.
                if (count($path) === 5 && $path[2] === 'payload' && $event['type'] === 'round_end') {
                    return in_array($path[3], ['secrets', 'guesses'], true);
                }

                return false;
            });
        }

        // players, id, status m.m.: inget heltalsfält utöver de strukturella får bära talet.
        $top = $rest;
        unset($top['state'], $top['events']);
        $this->scanValues($top, '', $opponentSecret, fn () => false);
    }

    /** Felsvar: inga förbjudna nycklar och motspelarens tal inte under något icke-strukturellt fält. */
    protected function assertNoLeakInError(array $body, ?int $opponentSecret): void
    {
        $this->assertLeakKeysAbsent($body, '');
        $this->assertArrayNotHasKey('secrets', $body, 'secrets in error body');
        $this->assertArrayNotHasKey('you', $body, 'you in error body');
        if ($opponentSecret !== null) {
            $this->scanValues($body, '', $opponentSecret, fn () => false);
        }
    }

    /** Heltal strikt, flyttal med samma värde och numeriska strängar med samma värde. */
    private function carriesSecret(mixed $value, int $secret): bool
    {
        return match (true) {
            is_int($value) => $value === $secret,
            is_float($value) => $value == $secret,
            is_string($value) => is_numeric(trim($value)) && (float) trim($value) == $secret,
            default => false,
        };
    }

    private function assertLeakKeysAbsent(array $tree, string $at): void
    {
        foreach ($tree as $key => $value) {
            $path = $at === '' ? (string) $key : "{$at}.{$key}";
            $this->assertNotContains($key, ['hidden_state', 'hidden', 'secret'], "forbidden key at {$path}");
            if (is_array($value)) {
                $this->assertLeakKeysAbsent($value, $path);
            }
        }
    }

    /**
     * Går igenom alla löv. Ett löv som bär $secret (se carriesSecret) är en läcka om nyckeln inte
     * är strukturell och $allowed(sökväg) inte godkänner platsen.
     *
     * @param  callable(list<string|int>): bool  $allowed
     */
    private function scanValues(array $tree, string $at, int $secret, callable $allowed, array $path = []): void
    {
        if ($path === [] && $at !== '') {
            $path = explode('.', $at);
            $path = array_map(fn ($p) => ctype_digit($p) ? (int) $p : $p, $path);
        }

        foreach ($tree as $key => $value) {
            $here = [...$path, $key];
            if (is_array($value)) {
                $this->scanValues($value, '', $secret, $allowed, $here);

                continue;
            }
            if (! $this->carriesSecret($value, $secret) || in_array($key, self::$structuralKeys, true) || $allowed($here)) {
                continue;
            }
            $this->fail('opponent secret leaked at '.implode('.', $here));
        }
    }
}
