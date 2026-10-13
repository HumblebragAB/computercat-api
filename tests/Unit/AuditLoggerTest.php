<?php

namespace Tests\Unit;

use App\Mcp\AuditLogger;
use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_keeps_scalars_shortens_strings_and_counts_lists(): void
    {
        $summary = AuditLogger::summarize([
            'slug' => 'high-score',
            'is_active' => true,
            'name' => str_repeat('x', 500),
            'content' => range(1, 4000),
            'settings' => ['a' => ['b' => 'c']],
        ]);

        $this->assertSame('high-score', $summary['slug']);
        $this->assertTrue($summary['is_active']);
        $this->assertSame(201, mb_strlen($summary['name'])); // 200 + "…"
        $this->assertSame('[list: 4000 items]', $summary['content']);
        $this->assertSame('[object: 1 keys]', $summary['settings']);
        $this->assertJson(json_encode($summary));
    }

    public function test_summary_is_valid_json_for_odd_input(): void
    {
        $summary = AuditLogger::summarize([
            'obj' => new \stdClass,
            'nothing' => null,
            'int' => 5,
            'float' => 1.5,
            'multibyte' => str_repeat('å', 500),
        ]);

        $this->assertSame(201, mb_strlen($summary['multibyte']));
        $this->assertSame('[object]', $summary['obj']);
        $this->assertNotFalse(json_encode($summary));
        $this->assertJson(json_encode($summary));
    }

    public function test_record_writes_a_row(): void
    {
        $user = User::factory()->create();
        AuditLogger::record($user, null, 'list_games', [], 'ok');

        $this->assertSame(1, McpAuditLog::where('user_id', $user->id)->where('outcome', 'ok')->count());
    }

    public function test_record_stores_empty_arguments_as_json(): void
    {
        AuditLogger::record(null, null, 'ping', ['obj' => new \stdClass, 'big' => range(1, 10)], 'ok');

        $row = McpAuditLog::firstOrFail();
        $this->assertSame(['obj' => '[object]', 'big' => '[list: 10 items]'], $row->arguments);
    }
}
