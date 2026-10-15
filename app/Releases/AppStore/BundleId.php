<?php

namespace App\Releases\AppStore;

use App\Rules\GameSlug;

// Eriks beslut 2026-10-15: se.computercat.<slug utan bindestreck>.
final class BundleId
{
    public const PREFIX = 'se.computercat.';

    public const PATTERN = '/^se\.computercat\.[a-z][a-z0-9]{1,39}\z/';

    public static function forSlug(string $slug): string
    {
        if (! GameSlug::isValid($slug)) {
            throw new \InvalidArgumentException('Ogiltig slug för bundle-id.');
        }

        $id = self::PREFIX.str_replace('-', '', $slug);
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw new \LogicException("Bundle-id {$id} har fel form.");
        }

        return $id;
    }
}
