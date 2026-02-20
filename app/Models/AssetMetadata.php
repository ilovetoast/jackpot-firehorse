<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structured metadata values for assets/versions.
 * Phase 3B: Attaches to asset_version when available; asset_id retained for legacy.
 */
class AssetMetadata extends Model
{
    protected $table = 'asset_metadata';

    protected $fillable = [
        'asset_id',
        'asset_version_id',
        'metadata_field_id',
        'value_json',
        'source',
        'confidence',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'confidence' => 'decimal:4',
            'approved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'asset_version_id');
    }
}
