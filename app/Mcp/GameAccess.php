<?php

namespace App\Mcp;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

// Enda stället som avgör vilka spel en MCP-användare når. Inaktiva spel ingår,
// så att ett spel kan konfigureras innan det aktiveras.
class GameAccess
{
    /** @return Builder<Game> */
    public static function visible(User $user): Builder
    {
        if (! $user->isStaff()) {
            return Game::query()->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdmin()) {
            return Game::query();
        }

        return Game::query()->whereHas('members', fn ($q) => $q->whereKey($user->getKey()));
    }

    public static function find(User $user, string $slug): ?Game
    {
        return self::visible($user)->where('slug', $slug)->first();
    }
}
