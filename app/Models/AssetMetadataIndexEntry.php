<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer C: allowlisted derived index rows for keyword search and future filters.
 */
class AssetMetadataIndexEntry extends Model
{
    use HasUuids;

    protected $table = 'asset_metadata_index';

    protected $fillable = [
        'asset_id',
        'namespace',
        'key',
        'normalized_key',
        'value_type',
        'value_string',
        'value_number',
        'value_boolean',
        'value_date',
        'value_datetime',
        'value_json',
        'search_text',
        'is_filterable',
        'is_visible',
        'source_priority',
    ];

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:8',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
            'value_json' => 'array',
            'is_filterable' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
