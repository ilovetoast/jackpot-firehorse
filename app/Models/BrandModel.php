<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Brand DNA / Brand Guidelines — one per brand, auto-created on brand creation.
 * Holds versioned JSON model (BrandModelVersion).
 */
class BrandModel extends Model
{
    protected $fillable = [
        'brand_id',
        'is_enabled',
        'active_version_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the brand that owns this model.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get all versions of this brand model.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BrandModelVersion::class);
    }

    /**
     * Get the currently active version.
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class, 'active_version_id');
    }

    /**
     * Plan gate: whether Brand DNA is accessible for this brand's tenant.
     * Stub: returns true. Full billing logic to be implemented later.
     */
    public static function isAccessible(Brand $brand): bool
    {
        // Stub: always true. Will check tenant plan (free = false) later.
        return true;
    }
}
