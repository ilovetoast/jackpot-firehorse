<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand Visual Reference
 *
 * Stores reference images (logo, photography examples) for imagery similarity scoring.
 * embedding_vector holds the visual embedding; type is 'logo' or 'photography_reference'.
 */
class BrandVisualReference extends Model
{
    /** identity = logo/lockup (not used for style embedding similarity); style = imagery for similarity */
    public const REFERENCE_TYPE_IDENTITY = 'identity';

    public const REFERENCE_TYPE_STYLE = 'style';

    /** Tier presets for multi-tier weighting */
    public const TIER_SYSTEM = 'system';

    public const TIER_PROMOTED = 'promoted';

    public const TIER_GUIDELINE = 'guideline';

    public const TIER_WEIGHT_DEFAULTS = [
        self::TIER_SYSTEM => 0.2,
        self::TIER_PROMOTED => 0.6,
        self::TIER_GUIDELINE => 1.0,
    ];

    protected $fillable = [
        'brand_id',
        'asset_id',
        'embedding_vector',
        'type',
        'reference_type',
        'reference_tier',
        'weight',
        'context_type',
    ];

    protected function casts(): array
    {
        return [
            'embedding_vector' => 'array',
        ];
    }

    public const TYPE_LOGO = 'logo';
    public const TYPE_PHOTOGRAPHY_REFERENCE = 'photography_reference';
    public const TYPE_LIFESTYLE_PHOTOGRAPHY = 'lifestyle_photography';
    public const TYPE_PRODUCT_PHOTOGRAPHY = 'product_photography';
    public const TYPE_GRAPHICS_LAYOUT = 'graphics_layout';

    /** All types included in imagery centroid scoring (logo + all photography/graphics). */
    public const IMAGERY_TYPES = [
        self::TYPE_LOGO,
        self::TYPE_PHOTOGRAPHY_REFERENCE,
        self::TYPE_LIFESTYLE_PHOTOGRAPHY,
        self::TYPE_PRODUCT_PHOTOGRAPHY,
        self::TYPE_GRAPHICS_LAYOUT,
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Effective weight for EBI (explicit weight column or tier default).
     */
    public function effectiveWeight(): float
    {
        if ($this->weight !== null && is_numeric($this->weight)) {
            return max(0.0, (float) $this->weight);
        }

        $tier = $this->reference_tier;
        if (is_string($tier) && isset(self::TIER_WEIGHT_DEFAULTS[$tier])) {
            return self::TIER_WEIGHT_DEFAULTS[$tier];
        }

        return self::TIER_WEIGHT_DEFAULTS[self::TIER_GUIDELINE];
    }

    /**
     * References used for visual style / embedding similarity (excludes identity/logo lockups).
     */
    public function isStyleReferenceForSimilarity(): bool
    {
        $rt = $this->reference_type;
        if ($rt === self::REFERENCE_TYPE_IDENTITY) {
            return false;
        }
        if ($rt === self::REFERENCE_TYPE_STYLE) {
            return true;
        }

        // Legacy rows: treat non-logo imagery as style
        return ($this->type ?? '') !== self::TYPE_LOGO;
    }
}
