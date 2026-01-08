<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * SystemCategory Model
 *
 * Represents global system category templates that are copied to brands when they are created.
 * These templates are managed by site owners and define the default system categories
 * (e.g., "Logos", "Photography", "Graphics") that should exist for all brands.
 *
 * System categories are:
 * - Global (no tenant_id or brand_id)
 * - Copied to new brands when they are created
 * - Editable by site owners only
 * - Used as templates for creating brand-specific categories
 */
class SystemCategory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'asset_type',
        'is_private',
        'is_hidden',
        'sort_order',
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
            'is_private' => 'boolean',
            'is_hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Generate a slug from the name if not provided.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($systemCategory) {
            if (empty($systemCategory->slug)) {
                $systemCategory->slug = Str::slug($systemCategory->name);
            }
        });
    }
}
