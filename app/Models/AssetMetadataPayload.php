<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer B: raw embedded metadata (namespaced payload_json), not for direct search.
 */
class AssetMetadataPayload extends Model
{
    use HasUuids;

    protected $table = 'asset_metadata_payloads';

    protected $fillable = [
        'asset_id',
        'source',
        'schema_version',
        'payload_json',
        'extracted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
