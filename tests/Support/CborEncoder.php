<?php

namespace Tests\Support;

/** Minimal CBOR-kodare för tester (RFC 7049). Strängar = byte-strängar, CborText och map-nycklar = text. */
final class CborEncoder
{
    public static function text(string $s): CborText
    {
        return new CborText($s);
    }

    public static function encode(mixed $v): string
    {
        return match (true) {
            $v instanceof CborText => self::head(3, strlen($v->value)).$v->value,
            is_int($v) && $v >= 0 => self::head(0, $v),
            is_int($v) => self::head(1, -1 - $v),
            is_string($v) => self::head(2, strlen($v)).$v,
            is_array($v) && array_is_list($v) => self::head(4, count($v)).implode('', array_map(self::encode(...), $v)),
            is_array($v) => self::head(5, count($v)).implode('', array_map(
                fn ($k, $x) => (is_string($k) ? self::encode(new CborText($k)) : self::encode($k)).self::encode($x),
                array_keys($v), $v)),
            default => throw new \InvalidArgumentException('Typen stöds inte'),
        };
    }

    /** Rått huvud, för att kunna bygga fientlig indata i tester. */
    public static function head(int $major, int $n): string
    {
        $m = $major << 5;

        return match (true) {
            $n < 24 => chr($m | $n),
            $n < 0x100 => chr($m | 24).chr($n),
            $n < 0x10000 => chr($m | 25).pack('n', $n),
            $n < 0x100000000 => chr($m | 26).pack('N', $n),
            default => chr($m | 27).pack('J', $n),
        };
    }
}

final class CborText
{
    public function __construct(public readonly string $value) {}
}
