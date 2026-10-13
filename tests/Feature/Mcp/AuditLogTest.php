<?php

namespace Tests\Feature\Mcp;

use App\Filament\Resources\McpAuditLogResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_page_renders_for_staff(): void
    {
        $this->actingAs(User::factory()->staff(admin: true)->create());

        $this->get(McpAuditLogResource::getUrl('index'))->assertOk();
    }

    public function test_resource_is_read_only(): void
    {
        $this->assertFalse(McpAuditLogResource::canCreate());
        $this->assertFalse(McpAuditLogResource::canDeleteAny());
        $this->assertArrayNotHasKey('edit', McpAuditLogResource::getPages());
    }
}
