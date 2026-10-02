<?php

namespace App\Services\Glosis;

use App\Models\AiUsage;
use App\Models\Game;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Global månadsbudget för betalda AI-anrop (skanning och studioröst).
 *
 * Före varje betalt anrop reserveras den uppskattade kostnaden med en atomisk
 * Cache::increment (databas-store: lockForUpdate i en transaktion), och
 * beslutet fattas på värdet increment returnerar. Parallella anrop kan alltså
 * inte alla se en räknare under budgeten. Efter anropet rättas räknaren till den
 * verkliga kostnaden (settle), eller återförs helt om inget debiterades (release).
 * Överskjutet begränsas därmed till (verklig − reserverad kostnad) för anrop
 * som pågår samtidigt; reservationen är satt så att den normalt överstiger den
 * verkliga kostnaden.
 *
 * Räknaren startas från summan i ai_usage för månaden (Cache::add är atomisk),
 * så en tömd cache tappar inte det som redan förbrukats. Invariant: när inget
 * anrop pågår är räknaren lika med summan av est_cost_usd för månaden.
 *
 * Månad = kalendermånad i Europe/Stockholm.
 */
final class AiBudget
{
    public const KEY_PREFIX = 'glosis-ai-budget:';

    public static function monthKey(Game $game, DateTimeInterface $now): string
    {
        $month = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('Y-m');

        return self::KEY_PREFIX.$game->id.':'.$month;
    }

    /** Null när budgeten inte räcker; då är inget reserverat. */
    public function reserve(Game $game, int $microUsd, string $feature): ?BudgetReservation
    {
        $now = CarbonImmutable::now(GuldStatus::TIME_ZONE);
        $key = self::monthKey($game, $now);
        $budget = GlosisSettings::for($game)->monthlyBudgetMicroUsd();

        // Lever månaden ut plus marginal; nyckeln innehåller månaden.
        $ttl = (int) $now->diffInSeconds($now->addMonthNoOverflow()->startOfMonth()) + 7 * 86400;
        Cache::add($key, self::spentMicroUsd($game, $now), $ttl);
        $total = Cache::increment($key, $microUsd);

        if ($total === false) {
            // Cachen svarar inte: stäng hellre än att släppa igenom obegränsat.
            Log::error('Glosis: budgeträknaren gick inte att räkna upp', ['feature' => $feature]);

            return null;
        }
        if ($total > $budget) {
            Cache::decrement($key, $microUsd);
            Log::warning('Glosis: månadsbudgeten för AI är förbrukad', [
                'feature' => $feature,
                'month' => $now->format('Y-m'),
                'budget_usd' => AiCost::toUsd($budget),
                'spent_usd' => AiCost::toUsd($total - $microUsd),
            ]);

            return null;
        }

        return new BudgetReservation($key, $microUsd);
    }

    /**
     * Sparar anropet i ai_usage och rättar reservationen till verklig kostnad.
     *
     * @param  array{units?: int, input_tokens?: int|null, output_tokens?: int|null, characters?: int|null}  $fields
     */
    public function record(Game $game, BudgetReservation $reservation, string $feature, string $environment, int $microUsd, array $fields = []): AiUsage
    {
        $usage = AiUsage::create([
            'game_id' => $game->id,
            'feature' => $feature,
            'environment' => $environment,
            'units' => $fields['units'] ?? 1,
            'input_tokens' => $fields['input_tokens'] ?? null,
            'output_tokens' => $fields['output_tokens'] ?? null,
            'characters' => $fields['characters'] ?? null,
            'est_cost_usd' => AiCost::toUsd($microUsd),
        ]);

        $this->close($reservation);
        $delta = $microUsd - $reservation->microUsd;
        if ($delta > 0) {
            Cache::increment($reservation->key, $delta);
        } elseif ($delta < 0) {
            Cache::decrement($reservation->key, -$delta);
        }

        return $usage;
    }

    /** Anropet debiterades inte: hela reservationen återförs. */
    public function release(BudgetReservation $reservation): void
    {
        $this->close($reservation);
        Cache::decrement($reservation->key, $reservation->microUsd);
    }

    /** Summan i ai_usage för månaden som innehåller $now, i miljondels dollar. */
    public static function spentMicroUsd(Game $game, DateTimeInterface $now): int
    {
        $start = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->startOfMonth();
        $sum = AiUsage::where('game_id', $game->id)
            ->where('created_at', '>=', $start->setTimezone(config('app.timezone')))
            ->where('created_at', '<', $start->addMonthNoOverflow()->setTimezone(config('app.timezone')))
            ->sum('est_cost_usd');

        return (int) round(((float) $sum) * 1_000_000);
    }

    private function close(BudgetReservation $reservation): void
    {
        if ($reservation->closed) {
            throw new LogicException('Budgetreservationen är redan avslutad');
        }
        $reservation->closed = true;
    }
}
