<?php

namespace App\Support;

use App\Models\Game;
use App\Rules\GameOrigin;
use Illuminate\Support\Facades\Cache;

class GameCorsOrigins
{
    private const CACHE_KEY = 'cors:game-origins';

    // Webbläsarens Origin-header har gemener, ingen avslutande / och ingen
    // standardport, så det sparade värdet måste ha samma form för att matcha.
    public static function normalize(string $origin): string
    {
        $origin = strtolower(rtrim(trim($origin), '/'));

        return str_ends_with($origin, ':443') ? substr($origin, 0, -4) : $origin;
    }

    /** @return list<string> Union av alla spels origins, aktiva som inaktiva. */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Game::query()
            ->whereNotNull('allowed_origins')
            ->pluck('allowed_origins')
            ->flatten()
            // Fail-closed: ett värde som passerat förbi valideringen (t.ex. '*') får aldrig bli CORS-jokertecken.
            ->filter(fn ($o) => GameOrigin::isValid($o))
            ->unique()
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
