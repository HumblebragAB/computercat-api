<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningStep extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['run_id', 'step', 'status', 'attempts', 'detail'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'attempts' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class, 'run_id');
    }
}
