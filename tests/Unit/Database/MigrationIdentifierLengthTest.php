<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * MySQL tillåter högst 64 tecken i identifierare. SQLite i testerna har ingen gräns,
 * så ett för långt automatiskt indexnamn syns först vid deploy (hände 2026-10-01).
 */
class MigrationIdentifierLengthTest extends TestCase
{
    public function test_automatic_index_names_fit_mysql(): void
    {
        $tooLong = [];
        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $file) {
            $src = file_get_contents($file);
            preg_match_all("/Schema::(?:create|table)\\('([a-z0-9_]+)'/", $src, $tables);
            preg_match_all("/->(unique|index|foreign)\\(\\[([^\\]]+)\\]\\)(?!\\s*,)/", $src, $indexes, PREG_SET_ORDER);
            foreach ($tables[1] as $table) {
                foreach ($indexes as [$all, $kind, $cols]) {
                    // Index med eget namn (andra argumentet) räknas inte.
                    if (preg_match("/\\]\\s*,\\s*'/", $all)) {
                        continue;
                    }
                    preg_match_all("/'([a-z0-9_]+)'/", $cols, $c);
                    $name = $table.'_'.implode('_', $c[1]).'_'.$kind;
                    if (strlen($name) > 64) {
                        $tooLong[] = basename($file).": {$name} (".strlen($name).')';
                    }
                }
            }
        }
        $this->assertSame([], $tooLong, 'Ge indexet ett eget kortare namn som andra argument.');
    }
}
