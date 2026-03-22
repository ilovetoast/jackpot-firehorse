<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-promoted brand style references (EBI similarity pool).
 */
class BrandReferenceAsset extends Model
{
    public const REFERENCE_TYPE_STYLE = 'style';

    /** Promoted as curated reference (tier 2) */
    public const TIER_REFERENCE = 2;

    /** Brand guideline weight (tier 3) */
    public const TIER_GUIDELINE = 3;

    protected $fillable = [
        'brand_id',
        'asset_id',
        'reference_type',
        'tier',
        'weight',
        'category',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'weight' => 'float',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promotionKind(): string
    {
        return (int) $this->tier === self::TIER_GUIDELINE ? 'guideline' : 'reference';
    }

    /**
     * @return array{kind: string, tier: int, category: string|null}
     */
    public function toFrontendArray(): array
    {
        return [
            'kind' => $this->promotionKind(),
            'tier' => (int) $this->tier,
            'category' => $this->category,
        ];
    }
}
