<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Category Model
 *
 * Categories are brand-centric and scoped by tenant_id, brand_id, and asset_type.
 * They are NEVER global or shared across brands/tenants.
 *
 * Scoping Rules:
 * - Each category MUST have tenant_id, brand_id, and asset_type
 * - Categories are isolated per brand (no cross-brand sharing)
 * - Categories are isolated per tenant (no cross-tenant sharing)
 *
 * Category Types:
 * - System Categories (is_system = true): Auto-created defaults (Logos, Photography, Graphics)
 *   - Cannot be deleted
 *   - Cannot be updated (locked)
 *   - Exist for every brand
 * - Custom Categories (is_system = false): User-created categories
 *   - Subject to plan limits
 *   - Can be deleted/updated by authorized users
 *
 * Visibility:
 * - Private (is_private = true): Only visible to authorized users
 * - Hidden (is_hidden = true): Filtered from default views, requires special permissions
 * - Public (is_private = false, is_hidden = false): Visible to all brand users
 */
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
        'is_hidden',
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
            'is_hidden' => 'boolean',
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
     * Get the brand that owns this category.
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
        return $query->where('brand_id', $brand->id);
    }

    /**
     * Scope a query to only include categories for a specific asset type.
     */
    public function scopeForAssetType(Builder $query, AssetType $assetType): Builder
    {
        return $query->where('asset_type', $assetType);
    }

    /**
     * Scope a query to only include hidden categories.
     */
    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('is_hidden', true);
    }

    /**
     * Scope a query to only include visible (non-hidden) categories.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }
}
