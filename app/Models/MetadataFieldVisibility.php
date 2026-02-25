<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for metadata_field_visibility table.
 * Used for cache invalidation observers.
 */
class MetadataFieldVisibility extends Model
{
    protected $table = 'metadata_field_visibility';

    public $timestamps = true;

    protected $fillable = [
        'metadata_field_id',
        'tenant_id',
        'brand_id',
        'category_id',
        'is_hidden',
        'is_upload_hidden',
        'is_filter_hidden',
        'is_edit_hidden',
        'is_primary',
        'is_required',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_upload_hidden' => 'boolean',
        'is_filter_hidden' => 'boolean',
        'is_edit_hidden' => 'boolean',
        'is_primary' => 'boolean',
        'is_required' => 'boolean',
    ];

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }
}
