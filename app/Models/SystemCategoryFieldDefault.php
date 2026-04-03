<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemCategoryFieldDefault extends Model
{
    protected $table = 'system_category_field_defaults';

    protected $fillable = [
        'system_category_id',
        'metadata_field_id',
        'is_hidden',
        'is_upload_hidden',
        'is_filter_hidden',
        'is_edit_hidden',
        'is_primary',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_upload_hidden' => 'boolean',
        'is_filter_hidden' => 'boolean',
        'is_edit_hidden' => 'boolean',
        'is_primary' => 'boolean',
    ];

    public function systemCategory(): BelongsTo
    {
        return $this->belongsTo(SystemCategory::class, 'system_category_id');
    }

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'metadata_field_id');
    }
}
