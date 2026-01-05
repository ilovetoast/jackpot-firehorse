<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Category extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'asset_type',
        'name',
        'slug',
        'is_system',
        'is_private',
        'is_locked',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
            'is_system' => 'boolean',
            'is_private' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Get the tenant that owns this category.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand that owns this category (nullable for company-wide categories).
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Scope a query to only include system categories.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to only include custom categories.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope a query to only include private categories.
     */
    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('is_private', true);
    }

    /**
     * Scope a query to only include categories for a specific brand.
     */
    public function scopeForBrand(Builder $query, Brand $brand): Builder
    {
        return $query->where(function ($q) use ($brand) {
            $q->where('brand_id', $brand->id)
                ->orWhereNull('brand_id'); // Include company-wide categories
        });
    }

    /**
     * Scope a query to only include categories for a specific asset type.
     */
    public function scopeForAssetType(Builder $query, AssetType $assetType): Builder
    {
        return $query->where('asset_type', $assetType);
    }
}
