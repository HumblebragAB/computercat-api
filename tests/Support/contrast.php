<?php

namespace Tests\Support;

/** WCAG-kontrast mellan två hex-färger. */
function contrast(string $a, string $b): float
{
    $lum = function (string $hex): float {
        $c = array_map(fn ($i) => hexdec(substr(ltrim($hex, '#'), $i, 2)) / 255, [0, 2, 4]);
        $c = array_map(fn ($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}
