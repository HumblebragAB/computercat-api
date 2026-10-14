<?php

namespace App\Releases;

use App\Models\Game;

// Spärr 9: brytaren globalt (R4) och per spel.
final class ReleaseSwitch
{
    public static function disabledReason(Game $game): ?string
    {
        if (config('releases.enabled') !== true) {
            return 'Releases are switched off globally (RELEASES_ENABLED).';
        }
        if (! $game->releases_enabled) {
            return 'Releases are switched off for this game.';
        }

        return null;
    }
}
