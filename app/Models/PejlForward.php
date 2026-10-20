<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En händelse från RevenueCat som vidarebefordras till Pejl som källintäkt.
 * Se migrationen create_pejl_forwards_table.
 */
class PejlForward extends Model
{
    public const KINDS = ['sale', 'refund', 'refund_reversed'];

    public const STATUSES = ['pending', 'sent', 'failed', 'skipped'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'fx_date' => 'date',
            'amount' => 'decimal:4',
            'tax_percentage' => 'decimal:6',
            'commission_percentage' => 'decimal:6',
            'fx_rate' => 'decimal:6',
            'amount_ex_vat_sek' => 'decimal:2',
            'cost_sek' => 'decimal:2',
            'attempts' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    // Pejls typ: bara refund är refund, resten är sale.
    public function pejlType(): string
    {
        return $this->kind === 'refund' ? 'refund' : 'sale';
    }
}
