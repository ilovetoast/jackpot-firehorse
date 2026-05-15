<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5.3 — alias → canonical mapping for metadata values.
 *
 * Eloquent surface is intentionally thin: hygiene business logic lives in
 * {@see \App\Services\Hygiene\MetadataCanonicalizationService}. The model
 * exists so observers + admin UIs can hold typed instances and so cache
 * invalidators can react to changes.
 */
class MetadataValueAlias extends Model
{
    protected $table = 'metadata_value_aliases';

    protected $fillable = [
        'tenant_id',
        'metadata_field_id',
        'alias_value',
        'canonical_value',
        'normalization_hash',
        'source',
        'created_by_user_id',
        'notes',
    ];

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
