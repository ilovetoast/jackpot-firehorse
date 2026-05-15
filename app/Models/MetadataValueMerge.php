<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5.3 — append-only audit row for value-merge actions.
 *
 * Truncating this table is safe; it carries no functional state. It exists
 * so admins can answer "who merged Outdoors → Outdoor and when?" without
 * setting up a separate analytics pipeline.
 */
class MetadataValueMerge extends Model
{
    protected $table = 'metadata_value_merges';

    protected $fillable = [
        'tenant_id',
        'metadata_field_id',
        'from_value',
        'to_value',
        'rows_updated',
        'options_removed',
        'alias_recorded',
        'source',
        'performed_by_user_id',
        'notes',
        'performed_at',
    ];

    protected $casts = [
        'rows_updated' => 'integer',
        'options_removed' => 'integer',
        'alias_recorded' => 'boolean',
        'performed_at' => 'datetime',
    ];

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }
}
