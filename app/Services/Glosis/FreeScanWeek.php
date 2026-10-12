<?php

namespace App\Services\Glosis;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Veckans gratisfoto per nyckel och ISO-vecka (Stockholm), utan räknare som
 * räknas ned.
 *
 * Varför inte RateLimiter: dess increment() gör put($key, 1) när add()
 * misslyckas och värdet blev 1, och tillsammans med decrement() (återföring,
 * avslag) kan ett parallellt anrop då skrivas över. Här finns bara atomiska
 * primitiver:
 *
 *  - Ett foto är en plats: '<vecka>:slot:<i>'. Den tas med Cache::add (atomisk i
 *    alla cachar: databas = insertOrIgnore på primärnyckeln). Bara den som fick
 *    platsen kan lämna tillbaka den (Cache::forget), och det görs bara när
 *    anropet nekades före Claude (globalt tak, IP-tak, budget).
 *  - En återföring öppnar en ny plats i stället för att räkna ned:
 *    '<vecka>:refunds' räknas bara upp (add 0 + increment). Tillåtna platser =
 *    1 + min(återföringar, MAX_REFUNDS). Ett nekat försök räknas också upp men
 *    ger ingen plats, så taket håller oavsett hur många tomma bilder som skickas.
 *
 * Fler än 1 + MAX_REFUNDS foton per nyckel och vecka kan aldrig beviljas,
 * hur anropen än flätas samman: varje beviljat foto äger en unik plats.
 */
final class FreeScanWeek
{
    /** Gånger per nyckel och vecka som gratisfotot återförs efter en oläsbar bild (inga ord, scan_failed eller oväntat fel). */
    public const MAX_REFUNDS = 2;

    public function __construct(private readonly string $weekKey, private readonly int $ttl) {}

    public static function for(GlosisCaller $caller, DateTimeInterface $now, int $ttl): self
    {
        $week = CarbonImmutable::instance($now)->setTimezone(GuldStatus::TIME_ZONE)->format('o-\WW');

        return new self('glosis-scan:free:'.$caller->limitId().':'.$week, $ttl);
    }

    public function key(): string
    {
        return $this->weekKey;
    }

    /** Platsens nummer, eller null när veckans foton är använda. */
    public function claim(): ?int
    {
        $allowed = GlosisSettings::FREE_SCAN_WEEKLY_PER_KEY + min($this->refunds(), self::MAX_REFUNDS);
        for ($slot = 0; $slot < $allowed; $slot++) {
            if (Cache::add($this->slotKey($slot), 1, $this->ttl)) {
                return $slot;
            }
        }

        return null;
    }

    /** Lämnar tillbaka en plats som detta anrop själv tagit med claim(), när anropet nekades före Claude. */
    public function release(int $slot): void
    {
        Cache::forget($this->slotKey($slot));
    }

    /**
     * Öppnar en ny plats efter en oläsbar bild. Också ett nekat försök räknas,
     * avsiktligt: räknaren går bara uppåt, och taket är min(…, MAX_REFUNDS).
     */
    public function refund(): bool
    {
        Cache::add($this->weekKey.':refunds', 0, $this->ttl);
        $refunds = Cache::increment($this->weekKey.':refunds');

        return $refunds !== false && $refunds <= self::MAX_REFUNDS;
    }

    private function refunds(): int
    {
        return (int) Cache::get($this->weekKey.':refunds', 0);
    }

    private function slotKey(int $slot): string
    {
        return $this->weekKey.':slot:'.$slot;
    }
}
