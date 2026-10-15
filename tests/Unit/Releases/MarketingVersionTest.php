<?php

namespace Tests\Unit\Releases;

use App\Releases\MarketingVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketingVersionTest extends TestCase
{
    public static function invalid(): array
    {
        return [['1.2'], ['1.2.3.4'], ['01.2.3'], ['1.2.3-beta'], ['v1.2.3'], ['1000.0.0'], [' 1.2.3'], ["1.2.3\n"], [''], [null], [123]];
    }

    #[DataProvider('invalid')]
    public function test_only_major_minor_patch_is_accepted(mixed $value): void
    {
        $this->assertNull(MarketingVersion::parse($value));
    }

    public function test_versions_compare_numerically_not_as_strings(): void
    {
        $this->assertTrue(MarketingVersion::parse('1.10.0')->isHigherThan(MarketingVersion::parse('1.9.9')));
        $this->assertFalse(MarketingVersion::parse('1.2.0')->isHigherThan(MarketingVersion::parse('1.2.0')));
        $this->assertSame('2.0.0', (string) MarketingVersion::highest(['1.9.0', null, '2.0.0', '1.10.3']));
        $this->assertNull(MarketingVersion::highest([null, 'trasig']));
        $this->assertSame('1.2.4', (string) MarketingVersion::parse('1.2.3')->nextPatch());
    }
}
