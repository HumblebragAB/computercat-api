<?php

namespace App\Services\Glosis;

use App\Models\AiUsage;
use App\Models\Game;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Sammanställning av ai_usage för Filament-sidan "AI-användning": antal anrop
 * och uppskattad kostnad per funktion, per dag (14 dagar), innevarande
 * ISO-vecka och kalendermånad. Allt räknat i Europe/Stockholm.
 */
final class AiUsageReport
{
    public const DAYS = 14;

    public const FEATURES = [AiUsage::FEATURE_SCAN, AiUsage::FEATURE_TTS];

    /**
     * @return array{
     *     days: list<array{date: string, features: array<string, array{count: int, micro_usd: int}>}>,
     *     week: array<string, array{count: int, micro_usd: int}>,
     *     month: array<string, array{count: int, micro_usd: int}>,
     *     month_total_micro_usd: int,
     *     budget_micro_usd: int,
     * }
     */
    public static function build(Game $game, DateTimeInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE);
        $today = $now->startOfDay();
        $firstDay = $today->subDays(self::DAYS - 1);
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY);
        $monthStart = $now->startOfMonth();
        $from = $firstDay->min($weekStart)->min($monthStart);

        $empty = fn () => array_fill_keys(self::FEATURES, ['count' => 0, 'micro_usd' => 0]);
        $days = [];
        for ($d = $today; $d->greaterThanOrEqualTo($firstDay); $d = $d->subDay()) {
            $days[$d->format('Y-m-d')] = $empty();
        }
        $week = $empty();
        $month = $empty();

        $rows = AiUsage::query()
            ->where('game_id', $game->id)
            ->where('created_at', '>=', $from->setTimezone(config('app.timezone')))
            ->toBase()
            ->select(['id', 'feature', 'created_at', 'est_cost_usd'])
            ->lazyById(1000, 'id');

        foreach ($rows as $row) {
            if (! in_array($row->feature, self::FEATURES, true)) {
                continue;
            }
            $at = CarbonImmutable::parse($row->created_at, config('app.timezone'))->setTimezone(GuldStatus::TIME_ZONE);
            if ($at->greaterThan($now)) {
                continue;
            }
            $micro = (int) round(((float) $row->est_cost_usd) * 1_000_000);
            $add = function (array &$bucket) use ($row, $micro) {
                $bucket[$row->feature]['count']++;
                $bucket[$row->feature]['micro_usd'] += $micro;
            };

            $day = $at->format('Y-m-d');
            if (isset($days[$day])) {
                $add($days[$day]);
            }
            if ($at->greaterThanOrEqualTo($weekStart)) {
                $add($week);
            }
            if ($at->greaterThanOrEqualTo($monthStart)) {
                $add($month);
            }
        }

        return [
            'days' => array_map(fn ($date, $features) => ['date' => $date, 'features' => $features], array_keys($days), $days),
            'week' => $week,
            'month' => $month,
            'month_total_micro_usd' => array_sum(array_column($month, 'micro_usd')),
            'budget_micro_usd' => GlosisSettings::for($game)->monthlyBudgetMicroUsd(),
        ];
    }
}
