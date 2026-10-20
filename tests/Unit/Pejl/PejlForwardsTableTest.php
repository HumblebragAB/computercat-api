<?php

namespace Tests\Unit\Pejl;

use App\Models\Game;
use App\Models\PejlForward;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PejlForwardsTableTest extends TestCase
{
    use RefreshDatabase;

    private function row(Game $game, string $ref, array $extra = []): PejlForward
    {
        return PejlForward::create($extra + [
            'game_id' => $game->id, 'event_id' => 'e1', 'kind' => 'sale', 'external_reference' => $ref,
        ]);
    }

    public function test_failed_row_with_missing_fields_can_be_saved(): void
    {
        $game = Game::create(['slug' => 'pejltest', 'name' => 'Pejltest', 'is_active' => true, 'settings' => []]);

        $row = $this->row($game, 'rc:e1', ['status' => 'failed', 'last_error' => 'saknar pris']);

        $this->assertNull($row->fresh()->amount);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_external_reference_is_unique(): void
    {
        $game = Game::create(['slug' => 'pejltest', 'name' => 'Pejltest', 'is_active' => true, 'settings' => []]);
        $this->row($game, 'rc:e1');

        $this->expectException(QueryException::class);
        $this->row($game, 'rc:e1');
    }

    public function test_pejl_type_maps_refund_only(): void
    {
        $this->assertSame('refund', (new PejlForward(['kind' => 'refund']))->pejlType());
        $this->assertSame('sale', (new PejlForward(['kind' => 'refund_reversed']))->pejlType());
        $this->assertSame('sale', (new PejlForward(['kind' => 'sale']))->pejlType());
    }
}
