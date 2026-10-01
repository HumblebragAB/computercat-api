<?php

namespace App\Services\Glosis;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Guldstjärnans giltighet, samma regel som Glosis packages/core/src/plus/validity.ts.
 *
 * En produkt per läsår: glosis_guld_2026_27 gäller till och med 2027-06-30
 * 23:59:59 i Europe/Stockholm. Giltigheten följer bara av produkt-id:t, aldrig
 * av köpdatumet. Båda implementationerna testas mot samma fixtures.json.
 */
final class GuldStatus
{
    public const TIME_ZONE = 'Europe/Stockholm';

    // Samma mönster som PRODUCT_RE i validity.ts. \z i stället för $ så att
    // ett avslutande radbyte inte godkänns (PHP:s $ matchar före "\n").
    private const PRODUCT_RE = '/^glosis_guld_(2\d{3})_(\d{2})\z/';

    /** Sista ögonblicket passet gäller, eller null om produkt-id:t inte är ett Guld-läsår. */
    public static function until(string $productId): ?CarbonImmutable
    {
        if (preg_match(self::PRODUCT_RE, $productId, $m) !== 1) {
            return null;
        }

        $start = (int) $m[1];
        if ((int) $m[2] !== ($start + 1) % 100) {
            return null;
        }

        return CarbonImmutable::create($start + 1, 6, 30, 23, 59, 59, self::TIME_ZONE);
    }

    public static function isActive(string $productId, DateTimeInterface $now): bool
    {
        $until = self::until($productId);

        return $until !== null && $now->getTimestamp() <= $until->getTimestamp();
    }
}
