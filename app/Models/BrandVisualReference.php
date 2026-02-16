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

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
