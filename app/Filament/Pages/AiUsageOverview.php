<?php

namespace App\Filament\Pages;

use App\Models\Game;
use App\Services\Glosis\AiUsageReport;
use Filament\Pages\Page;

/**
 * Glosis betalda AI-anrop (fotoskanning och studioröst): antal och uppskattad
 * kostnad per dag, vecka och månad, mot månadsbudgeten.
 */
class AiUsageOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'AI-användning';

    protected static ?string $title = 'AI-användning (Glosis)';

    protected static ?string $slug = 'ai-usage';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.ai-usage-overview';

    /** @return array<string, mixed>|null */
    public function getReport(): ?array
    {
        $game = Game::where('slug', 'glosis')->first();

        return $game ? AiUsageReport::build($game, now()) : null;
    }

    public static function usd(int $microUsd): string
    {
        return '$'.number_format($microUsd / 1_000_000, 2, '.', ' ');
    }
}
