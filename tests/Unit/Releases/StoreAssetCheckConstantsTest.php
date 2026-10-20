<?php

namespace Tests\Unit\Releases;

use App\Releases\TestFlight\StoreAssetCheck;
use PHPUnit\Framework\TestCase;

// Standardbildernas blob-sha gäller Capacitors mall i en viss version. Byter byggguiden Capacitor
// fäller testet, så att sha:erna läses av på nytt ur ett nyskapat iOS-projekt i stället för att
// varningen tyst slutar fungera.
class StoreAssetCheckConstantsTest extends TestCase
{
    public function test_default_image_shas_belong_to_the_capacitor_version_of_every_ios_build_guide(): void
    {
        $guides = glob(dirname(__DIR__, 3).'/resources/byggguide/v*/files/scripts/check-ios.mjs') ?: [];
        $this->assertNotEmpty($guides);
        foreach ($guides as $file) {
            preg_match("/export const CAPACITOR = '([^']+)'/", (string) file_get_contents($file), $m);
            $this->assertSame('8.5.3', $m[1] ?? null, "{$file}: ny Capacitor-version; läs av StoreAssetCheck::DEFAULT_ICON och DEFAULT_SPLASH på nytt.");
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', StoreAssetCheck::DEFAULT_ICON);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', StoreAssetCheck::DEFAULT_SPLASH);
    }
}
