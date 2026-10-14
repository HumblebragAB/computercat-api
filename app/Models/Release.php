<?php

namespace App\Models;

use App\Provisioning\Http\Redactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Release extends Model
{
    public const REQUESTED = 'requested';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const BUILDING = 'building';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Pågående status: bara ett släpp per spel, mål och steg får ha någon av dem. */
    public const ACTIVE = [self::REQUESTED, self::AWAITING_APPROVAL, self::BUILDING, self::PROCESSING];

    protected $fillable = [
        'uuid', 'game_id', 'target', 'stage', 'commit_sha', 'status', 'requested_by', 'approved_by',
        'approved_at', 'self_approved', 'external', 'last_error',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'self_approved' => 'boolean', 'external' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Release $release) {
            $release->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Release $release) {
            // Indexet på (game_id, target, stage, active_key) är spärr 4: nyckeln följer alltid statusen.
            $release->active_key = in_array($release->status, self::ACTIVE, true) ? 'active' : null;

            // last_error visas för spelets medlemmar: kända hemligheter bort och högst 4 KB.
            if (is_string($release->last_error)) {
                $release->last_error = Redactor::limit(Redactor::scrubSecrets($release->last_error));
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Släpp som spärr 7 avbryter. Delas av expireStale() och nattkontrollens torrkörning,
     * så att torrkörningen rapporterar exakt de släpp en riktig körning skulle avbryta.
     *
     * @return Builder<static>
     */
    public static function expirable(): Builder
    {
        $cutoff = now()->subDays((int) config('releases.ttl_days'));

        return static::whereIn('status', [self::AWAITING_APPROVAL, self::REQUESTED])->where('updated_at', '<', $cutoff);
    }

    /**
     * Spärr 7: ett släpp i awaiting_approval eller requested som ingen rört på
     * config('releases.ttl_days') dygn blir cancelled. Anropas före varje
     * kontroll som läser pågående släpp, så att en utgången plats frigörs.
     *
     * @return Collection<int, Release> de släpp som avbröts nu
     */
    public static function expireStale(): Collection
    {
        $days = (int) config('releases.ttl_days');
        $cutoff = now()->subDays($days);
        $expired = new Collection;

        $candidates = static::expirable()->pluck('id');
        foreach ($candidates as $id) {
            DB::transaction(function () use ($id, $cutoff, $days, $expired) {
                // Om statusen hunnit ändras (godkänt, startat) lämnas släppet ifred.
                $release = static::whereKey($id)->lockForUpdate()->first();
                if ($release !== null && in_array($release->status, [self::AWAITING_APPROVAL, self::REQUESTED], true) && $release->updated_at->lt($cutoff)) {
                    $release->update(['status' => self::CANCELLED, 'last_error' => "Cancelled: no decision or build start within {$days} days."]);
                    $expired->push($release);
                }
            });
        }

        return $expired;
    }
}
