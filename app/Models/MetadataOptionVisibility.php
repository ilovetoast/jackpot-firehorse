<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for metadata_option_visibility table.
 * Used for cache invalidation observers.
 */
class MetadataOptionVisibility extends Model
{
    protected $table = 'metadata_option_visibility';

    public $timestamps = true;

    protected $fillable = [
        'metadata_option_id',
        'tenant_id',
        'brand_id',
        'category_id',
        'is_hidden',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    public function metadataOption(): BelongsTo
    {
        return $this->belongsTo(MetadataOption::class, 'metadata_option_id');
    }
}
