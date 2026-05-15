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
        // Phase 2: folder quick filter assignment columns.
        // See migration 2026_05_14_140000_add_folder_quick_filter_columns_*.
        'show_in_folder_quick_filters',
        'folder_quick_filter_order',
        'folder_quick_filter_weight',
        'folder_quick_filter_source',
        // Phase 5.2: pinning. Pinned filters sort first and resist overflow.
        // See migration 2026_05_14_180000_add_phase_5_2_quick_filter_columns.
        'is_pinned_folder_quick_filter',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_upload_hidden' => 'boolean',
        'is_filter_hidden' => 'boolean',
        'is_edit_hidden' => 'boolean',
        'is_primary' => 'boolean',
        'is_required' => 'boolean',
        'show_in_folder_quick_filters' => 'boolean',
        'folder_quick_filter_order' => 'integer',
        'folder_quick_filter_weight' => 'integer',
        'is_pinned_folder_quick_filter' => 'boolean',
    ];

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }
}
