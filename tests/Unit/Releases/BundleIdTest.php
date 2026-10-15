<?php

namespace Tests\Unit\Releases;

use App\Releases\AppStore\BundleId;
use PHPUnit\Framework\TestCase;

class BundleIdTest extends TestCase
{
    public function test_bundle_id_is_se_computercat_and_the_slug_without_hyphens(): void
    {
        $this->assertSame('se.computercat.kattsvep', BundleId::forSlug('kattsvep'));
        $this->assertSame('se.computercat.tinkerisle', BundleId::forSlug('tinker-isle'));
        $this->assertSame('se.computercat.ab', BundleId::forSlug('a-b'));
        $this->assertSame(1, preg_match(BundleId::PATTERN, BundleId::forSlug(str_repeat('a', 40))));
    }

    public function test_invalid_slug_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BundleId::forSlug('Kattsvep');
    }
}
