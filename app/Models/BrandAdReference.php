<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand Ad Reference
 *
 * Per-brand curated gallery of "ads we want our own output to feel like".
 * Each row ties a DAM Asset to a brand, in an explicit display order, with
 * optional free-form notes from the brand owner.
 *
 * Scope:
 *   - Brand-owned (not tenant-owned): teams in the same tenant can have
 *     wildly different reference banks per brand, and we do NOT want to
 *     leak them across brands.
 *   - Asset-backed: the actual image lives in DAM — this table only
 *     associates it + orders it.
 *
 * Downstream use:
 *   Today these references are a UI-only curation tool (brand owners see
 *   them inline in the Brand Guidelines Builder). Future passes can read
 *   them into `deriveBrandAdStyle` as soft signals (e.g. "your references
 *   lean warm-toned → nudge style.mood") once we have a reliable signal
 *   extractor. The shape is stable either way.
 */
class BrandAdReference extends Model
{
    protected $fillable = [
        'brand_id',
        'asset_id',
        'notes',
        'display_order',
        'signals',
        'signals_extracted_at',
        'signals_extraction_attempted_at',
        'signals_extraction_error',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'display_order' => 'integer',
            'signals' => 'array',
            'signals_extracted_at' => 'datetime',
            'signals_extraction_attempted_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
