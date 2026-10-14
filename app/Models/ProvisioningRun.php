<?php

namespace App\Models;

use App\Provisioning\Http\Redactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProvisioningRun extends Model
{
    public const KIND_PROVISION = 'provision';

    public const KIND_ARCHIVE = 'archive';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const RELEASED = 'released';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'uuid', 'slug', 'slug_lock', 'name', 'user_id', 'game_id', 'kind', 'status', 'idea',
        'github_repo', 'coolify_app_uuid', 'siteops_site_id', 'demo_url', 'monitoring_enabled_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['siteops_site_id' => 'integer', 'monitoring_enabled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProvisioningRun $run) {
            $run->uuid ??= (string) Str::uuid();
        });

        // last_error visas för spelets medlemmar. Sista skyddet: kända
        // hemligheter bort och högst 4 KB, oavsett vem som skrev värdet.
        static::saving(function (ProvisioningRun $run) {
            if (is_string($run->last_error)) {
                $run->last_error = Redactor::limit(Redactor::scrubSecrets($run->last_error));
            }
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningStep::class, 'run_id')->orderBy('id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeProvision(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PROVISION);
    }

    // Spec 2c, taket på 15: spel som finns eller kan finnas på Coolify.
    public function scopeCountingTowardsCap(Builder $query): Builder
    {
        return $query->provision()->whereIn('status', [self::RUNNING, self::SUCCEEDED, self::FAILED]);
    }
}
