<?php

namespace Tests\Unit\Glosis;

use App\Services\Glosis\AppAttest\Cbor;
use App\Services\Glosis\AppAttest\InvalidAttestationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\CborEncoder as E;

class CborTest extends TestCase
{
    public function test_avkodar_apples_exempelobjekt(): void
    {
        $fx = json_decode(file_get_contents(__DIR__.'/../../Fixtures/glosis-appattest-apple-sample.json'), true);
        $obj = Cbor::decode(base64_decode($fx['attestation'], true));

        $this->assertSame('apple-appattest', $obj['fmt']);
        $this->assertCount(2, $obj['attStmt']['x5c']);
        $this->assertSame(226, strlen($obj['authData']));
    }

    public function test_rundtur_for_det_som_stods(): void
    {
        $value = ['a' => 1, 'b' => -7, 'c' => "\x00\xff", 'd' => [1, 2, ['x' => E::text('y')]], 3 => -1];
        $decoded = Cbor::decode(E::encode($value));
        $this->assertSame(['a' => 1, 'b' => -7, 'c' => "\x00\xff", 'd' => [1, 2, ['x' => 'y']], 3 => -1], $decoded);
    }

    public function test_decode_at_flyttar_fram_offset(): void
    {
        $bytes = E::encode(['k' => 1]).E::encode(['l' => 2]);
        $o = 0;
        $this->assertSame(['k' => 1], Cbor::decodeAt($bytes, $o));
        $this->assertSame(['l' => 2], Cbor::decodeAt($bytes, $o));
        $this->assertSame(strlen($bytes), $o);
    }

    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'tom' => [''],
            'bytes efter värdet' => [E::encode(1)."\x00"],
            'obestämd längd' => ["\x5f\x41a\xff"],
            'tagg' => ["\xc0\x01"],
            'flyttal' => ["\xfb".str_repeat("\x00", 8)],
            'true' => ["\xf5"],
            'längd längre än data' => [E::head(2, 1000).'abc'],
            '64-bitars längd över PHP_INT_MAX' => ["\x5b\xff\xff\xff\xff\xff\xff\xff\xff"],
            'för djup' => [str_repeat("\x81", 20)."\x01"],
            'för många element' => [E::head(4, 100_000)],
            'dubbel map-nyckel' => ["\xa2\x61k\x01\x61k\x02"],
            'map-nyckel som lista' => ["\xa1\x80\x01"],
            'ogiltig UTF-8 i text' => ["\x62\xc3\x28"],
        ];
    }

    #[DataProvider('hostile')]
    public function test_avvisar_fientlig_indata(string $bytes): void
    {
        $this->expectException(InvalidAttestationException::class);
        Cbor::decode($bytes);
    }
}
