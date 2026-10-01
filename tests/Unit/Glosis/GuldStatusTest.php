<?php

namespace Tests\Unit\Glosis;

use App\Services\Glosis\GuldStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kör de gemensamma testfallen ur Glosis-repot. Källan är
 * glosis/packages/core/src/plus/fixtures.json; tests/Fixtures/glosis-plus-fixtures.json
 * är en kopia. Ändras regeln där ska filen kopieras hit på nytt och
 * FIXTURES_SHA256 uppdateras, så att de två implementationerna aldrig glider isär tyst.
 */
class GuldStatusTest extends TestCase
{
    private const FIXTURES_SHA256 = '42c7422c6b94369ea5798a7499485452707858e11efe52f7090e765742326d56';

    private static function fixtures(): array
    {
        return json_decode(file_get_contents(self::fixturePath()), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fixturePath(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/glosis-plus-fixtures.json';
    }

    public function test_fixtures_are_the_known_copy_from_the_glosis_repo(): void
    {
        $this->assertSame(self::FIXTURES_SHA256, hash_file('sha256', self::fixturePath()));
    }

    public static function productIdCases(): array
    {
        $cases = [];
        foreach (self::fixtures()['productIds'] as $i => $case) {
            $cases[$i.': '.($case['note'] ?? $case['productId'])] = [$case['productId'], $case['expectedUntil']];
        }

        return $cases;
    }

    #[DataProvider('productIdCases')]
    public function test_product_id_gives_until(string $productId, ?string $expectedUntil): void
    {
        $until = GuldStatus::until($productId);

        if ($expectedUntil === null) {
            $this->assertNull($until);

            return;
        }

        $this->assertNotNull($until);
        $this->assertSame($expectedUntil.' 23:59:59', $until->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Stockholm', $until->getTimezone()->getName());
    }

    /** plusStatus-fallen med exakt ett köp: servern ser ett köpbevis åt gången. */
    public static function singlePurchaseCases(): array
    {
        $cases = [];
        foreach (self::fixtures()['plusStatus'] as $case) {
            if (count($case['purchases']) !== 1) {
                continue;
            }
            $cases[$case['note']] = [$case['purchases'][0]['productId'], $case['now'], $case['expectedActive'], $case['expectedUntil']];
        }

        return $cases;
    }

    #[DataProvider('singlePurchaseCases')]
    public function test_single_purchase_status(string $productId, string $now, bool $expectedActive, ?string $expectedUntil): void
    {
        $this->assertSame($expectedActive, GuldStatus::isActive($productId, CarbonImmutable::parse($now)));
        $this->assertSame($expectedUntil, GuldStatus::until($productId)?->format('Y-m-d'));
    }

    public function test_trailing_newline_is_not_accepted(): void
    {
        $this->assertNull(GuldStatus::until("glosis_guld_2026_27\n"));
    }
}
