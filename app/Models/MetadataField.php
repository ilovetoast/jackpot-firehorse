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
    ];

    public function metadataOptions(): HasMany
    {
        return $this->hasMany(MetadataOption::class, 'metadata_field_id')
            ->orderBy('system_label', 'asc');
    }
}
