<?php

namespace App\Matches;

use App\Matches\Rules\MatchRules;
use App\Models\Game;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Slår upp regelklasser ur config('matches.rulesets').
 *
 * for() kastar AuthorizationException med meddelandet 'ruleset_not_allowed' när
 * spelet inte är listat eller rulesetet saknas. Controllern mappar det
 * meddelandet till felkoden ruleset_not_allowed (403).
 */
class RulesetRegistry
{
    public const NOT_ALLOWED = 'ruleset_not_allowed';

    public static function for(Game $game, string $ruleset): MatchRules
    {
        $entry = config('matches.rulesets')[$ruleset] ?? null;

        if (! is_array($entry) || ! in_array($game->slug, $entry['games'] ?? [], true)) {
            throw new AuthorizationException(self::NOT_ALLOWED);
        }

        return app($entry['class']);
    }

    /** @return list<string> */
    public static function forGame(Game $game): array
    {
        $names = [];
        foreach (config('matches.rulesets', []) as $name => $entry) {
            if (in_array($game->slug, $entry['games'] ?? [], true)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
