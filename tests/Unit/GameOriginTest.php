<?php

namespace Tests\Unit;

use App\Rules\GameOrigin;
use App\Support\GameCorsOrigins;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class GameOriginTest extends TestCase
{
    private function passes(string $value): bool
    {
        return Validator::make(['o' => $value], ['o' => [new GameOrigin]])->passes();
    }

    public function test_accepts_https_origins(): void
    {
        foreach (['https://ordjakt.apps.humblebrag.se', 'https://ordjakt.nu', 'https://x.se:8443', 'https://Ordjakt.apps.humblebrag.se/'] as $ok) {
            $this->assertTrue($this->passes($ok), $ok);
        }
    }

    public function test_rejects_everything_else(): void
    {
        foreach ([
            'http://ordjakt.nu', 'https://*.ordjakt.nu', 'https://ordjakt.nu/spel', 'https://ordjakt.nu?a=1',
            'https://u:p@ordjakt.nu', 'ordjakt.nu', 'javascript:alert(1)', 'https://', '*', 'https://ordjakt.nu#x',
        ] as $bad) {
            $this->assertFalse($this->passes($bad), $bad);
        }
    }

    public function test_normalize_lowercases_and_strips_trailing_slash(): void
    {
        $this->assertSame('https://ordjakt.apps.humblebrag.se', GameCorsOrigins::normalize('https://Ordjakt.apps.humblebrag.se/'));
        $this->assertSame('https://ordjakt.nu', GameCorsOrigins::normalize('https://ordjakt.nu:443'));
        $this->assertFalse($this->passes("https://ordjakt.nu\n"));
    }
}
