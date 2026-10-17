<?php

namespace App\Matches;

use App\Models\GameMatch;
use App\Models\GameMatchEvent;
use App\Models\GameMatchPlayer;

/**
 * Svarsformen för en match, sedd från ett säte. Vitlista med uttryckliga fält:
 * motspelarens hidden_state läses aldrig, den egna går bara ut via rules->view().
 */
class MatchView
{
    public const MAX_EVENTS = 200;

    /** Sekunder sedan senaste poll som räknas som ansluten. */
    public const CONNECTED_SECONDS = 10;

    public static function for(GameMatch $match, int $seat, ?int $since): array
    {
        $rules = RulesetRegistry::for($match->game, $match->ruleset);
        $players = $match->players;
        $own = $players->firstWhere('seat', $seat);

        $query = $match->events();
        if ($since !== null) {
            $events = $query->where('seq', '>', $since)->limit(self::MAX_EVENTS)->get();
        } else {
            $events = $query->reorder('seq', 'desc')->limit(self::MAX_EVENTS)->get()->reverse()->values();
        }

        return [
            'id' => $match->id,
            'ruleset' => $match->ruleset,
            'status' => $match->status,
            'version' => $match->version,
            'your_seat' => $seat,
            'players' => $players->map(fn (GameMatchPlayer $p) => [
                'seat' => $p->seat,
                'name' => $p->name,
                'connected' => self::connected($p),
            ])->values()->all(),
            'state' => $match->state,
            'you' => $rules->view($own?->hidden_state),
            'events' => $events->map(fn (GameMatchEvent $e) => [
                'seq' => $e->seq,
                'type' => $e->type,
                'seat' => $e->seat,
                'payload' => $e->payload,
                'at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** {version}-{c0}{c1}, utan citattecken. Saknat säte räknas som frånkopplat. */
    public static function etag(GameMatch $match): string
    {
        $players = $match->players;
        $flag = fn (int $seat) => ($p = $players->firstWhere('seat', $seat)) !== null && self::connected($p) ? '1' : '0';

        return $match->version.'-'.$flag(0).$flag(1);
    }

    private static function connected(GameMatchPlayer $player): bool
    {
        return $player->last_seen_at !== null
            && $player->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(self::CONNECTED_SECONDS));
    }
}
