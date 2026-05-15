<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for metadata_fields table.
 * Used for cache invalidation observers; schema resolution uses MetadataSchemaResolver.
 */
class MetadataField extends Model
{
    protected $table = 'metadata_fields';

    protected $fillable = [
        'key',
        'system_label',
        'description',
        'type',
        'applies_to',
        'scope',
        'tenant_id',
        'is_active',
        'is_filterable',
        'is_user_editable',
        'is_ai_trainable',
        'is_upload_visible',
        'is_internal_only',
        'group_key',
        'plan_gate',
        'deprecated_at',
        'replacement_field_id',
        'population_mode',
        'show_on_upload',
        'show_on_edit',
        'show_in_filters',
        'readonly',
        'is_primary',
        'archived_at',
        'ai_eligible',
        'display_widget',
        // Phase 5.2: metadata-quality signal columns. Mostly nullable /
        // defaulted; populated opportunistically by the facet/quality
        // services. See migration 2026_05_14_180000_add_phase_5_2_quick_filter_columns.
        'estimated_distinct_value_count',
        'last_facet_usage_at',
        'facet_usage_count',
        'is_high_cardinality',
        'is_low_quality_candidate',
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_user_editable' => 'boolean',
        'is_ai_trainable' => 'boolean',
        'is_upload_visible' => 'boolean',
        'is_internal_only' => 'boolean',
        'is_active' => 'boolean',
        'show_on_upload' => 'boolean',
        'show_on_edit' => 'boolean',
        'show_in_filters' => 'boolean',
        'readonly' => 'boolean',
        'is_primary' => 'boolean',
        'deprecated_at' => 'datetime',
        'archived_at' => 'datetime',
        // Phase 5.2 quality signals.
        'estimated_distinct_value_count' => 'integer',
        'last_facet_usage_at' => 'datetime',
        'facet_usage_count' => 'integer',
        'is_high_cardinality' => 'boolean',
        'is_low_quality_candidate' => 'boolean',
    ];

    public function metadataOptions(): HasMany
    {
        return $this->hasMany(MetadataOption::class, 'metadata_field_id')
            ->orderBy('system_label', 'asc');
    }
}
