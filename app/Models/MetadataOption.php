<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for metadata_options table.
 * Used for cache invalidation observers; schema resolution uses MetadataSchemaResolver.
 */
class MetadataOption extends Model
{
    protected $table = 'metadata_options';

    protected $fillable = [
        'metadata_field_id',
        'value',
        'system_label',
        'is_system',
        'color',
        'icon',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }
}
