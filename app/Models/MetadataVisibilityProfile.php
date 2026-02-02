<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3a: Named metadata visibility profile.
 * Snapshot = array of per-field visibility (metadata_field_id, is_hidden, is_upload_hidden, is_filter_hidden, is_primary, is_edit_hidden).
 * Scope: tenant_id required; brand_id nullable = tenant-wide profile.
 */
class MetadataVisibilityProfile extends Model
{
    protected $table = 'metadata_visibility_profiles';

    protected $fillable = [
        'tenant_id',
        'brand_id',
        'name',
        'category_slug',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
