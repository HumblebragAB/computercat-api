<?php

namespace App\Services\Glosis\AppAttest;

/**
 * Strikt CBOR-avkodare (RFC 7049) för App Attest. Stöder exakt det Apples
 * objekt använder: heltal, byte- och textsträngar med bestämd längd, listor
 * och maps. Taggar, flyttal, true/false/null och obestämda längder avvisas,
 * liksom dubbla map-nycklar, för djup nästling och längder större än datan.
 * Indatan kommer från nätet: varje längd kontrolleras mot det som finns kvar.
 */
final class Cbor
{
    private const MAX_DEPTH = 8;

    private const MAX_ITEMS = 64;

    public static function decode(string $bytes): mixed
    {
        $offset = 0;
        $value = self::item($bytes, $offset, 0);
        if ($offset !== strlen($bytes)) {
            throw new InvalidAttestationException('CBOR: data efter värdet');
        }

        return $value;
    }

    public static function decodeAt(string $bytes, int &$offset): mixed
    {
        return self::item($bytes, $offset, 0);
    }

    private static function item(string $b, int &$o, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidAttestationException('CBOR: för djup nästling');
        }
        if ($o >= strlen($b)) {
            throw new InvalidAttestationException('CBOR: slut på data');
        }
        $initial = ord($b[$o++]);
        $major = $initial >> 5;
        if ($major > 5) {
            throw new InvalidAttestationException("CBOR: typ {$major} stöds inte");
        }
        $n = self::argument($b, $o, $initial & 0x1f);

        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                if ($n > strlen($b) - $o) {
                    throw new InvalidAttestationException('CBOR: strängen är längre än datan');
                }
                $s = substr($b, $o, $n);
                $o += $n;
                if ($major === 3 && ! mb_check_encoding($s, 'UTF-8')) {
                    throw new InvalidAttestationException('CBOR: text är inte UTF-8');
                }

                return $s;
            case 4:
                self::guardCount($n);
                $list = [];
                for ($i = 0; $i < $n; $i++) {
                    $list[] = self::item($b, $o, $depth + 1);
                }

                return $list;
            default:
                self::guardCount($n);
                $map = [];
                for ($i = 0; $i < $n; $i++) {
                    $key = self::item($b, $o, $depth + 1);
                    if (! is_int($key) && ! is_string($key)) {
                        throw new InvalidAttestationException('CBOR: map-nyckel är inte tal eller sträng');
                    }
                    if (array_key_exists($key, $map)) {
                        throw new InvalidAttestationException('CBOR: dubbel map-nyckel');
                    }
                    $map[$key] = self::item($b, $o, $depth + 1);
                }

                return $map;
        }
    }

    private static function argument(string $b, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new InvalidAttestationException('CBOR: obestämd eller reserverad längd'),
        };
        if ($size > strlen($b) - $o) {
            throw new InvalidAttestationException('CBOR: slut på data i längden');
        }
        $raw = substr($b, $o, $size);
        $o += $size;
        $n = match ($size) {
            1 => ord($raw),
            2 => unpack('n', $raw)[1],
            4 => unpack('N', $raw)[1],
            8 => unpack('J', $raw)[1],
        };
        if ($n < 0) {
            // unpack('J') ger negativt tal över PHP_INT_MAX.
            throw new InvalidAttestationException('CBOR: talet är för stort');
        }

        return $n;
    }

    private static function guardCount(int $n): void
    {
        if ($n > self::MAX_ITEMS) {
            throw new InvalidAttestationException('CBOR: för många element');
        }
    }
}
