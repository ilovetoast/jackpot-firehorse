<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAlignmentScore extends Model
{
    protected $fillable = [
        'asset_id',
        'collection_id',
        'campaign_identity_id',
        'brand_id',
        'overall_score',
        'confidence',
        'level',
        'breakdown_json',
        'engine_version',
        'ai_used',
    ];

    protected function casts(): array
    {
        return [
            'breakdown_json' => 'array',
            'confidence' => 'float',
            'ai_used' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function campaignIdentity(): BelongsTo
    {
        return $this->belongsTo(CollectionCampaignIdentity::class, 'campaign_identity_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
