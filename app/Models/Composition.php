<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Composition extends Model
{
    public const VISIBILITY_PRIVATE = 'private';

    /** Visible to any member of the brand (same workspace). */
    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'visibility',
        'name',
        'folder',
        'document_json',
        'thumbnail_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'document_json' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CompositionVersion::class)->orderByDesc('id');
    }

    public function thumbnailAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'thumbnail_asset_id');
    }

    /**
     * When this composition is one output in a Studio "Versions" set (Creative Set).
     */
    public function creativeSetVariant(): HasOne
    {
        return $this->hasOne(CreativeSetVariant::class);
    }

    /**
     * Compositions the user may list or open: shared with the brand, or private but owned by this user.
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where(function (Builder $q1) {
                $q1->where('visibility', self::VISIBILITY_SHARED)
                    ->orWhereNull('visibility');
            })->orWhere(function (Builder $q2) use ($user) {
                $q2->where('visibility', self::VISIBILITY_PRIVATE)
                    ->where('user_id', $user->id);
            });
        });
    }

    public function isVisibleToUser(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $v = $this->getAttribute('visibility');
        if ($v === null || $v === '') {
            $v = self::VISIBILITY_SHARED;
        }
        if ($v === self::VISIBILITY_SHARED) {
            return true;
        }

        return (int) $this->getAttribute('user_id') === (int) $user->id;
    }

    /**
     * Private compositions may only be removed by their creator; shared compositions may be deleted by any brand member who can open Studio.
     */
    public function userCanDelete(?User $user): bool
    {
        if ($user === null || ! $this->isVisibleToUser($user)) {
            return false;
        }
        $v = $this->getAttribute('visibility');
        if ($v === null || $v === '') {
            $v = self::VISIBILITY_SHARED;
        }
        if ($v === self::VISIBILITY_PRIVATE) {
            return (int) ($this->getAttribute('user_id') ?? 0) === (int) $user->id;
        }

        return true;
    }
}
