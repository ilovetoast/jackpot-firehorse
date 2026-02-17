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
    protected $fillable = [
        'brand_id',
        'asset_id',
        'embedding_vector',
        'type',
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
}
