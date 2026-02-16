<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand DNA / Brand Guidelines — versioned JSON model.
 * source_type: manual | scrape | ai | retrain
 * status: draft | active | archived
 */
class BrandModelVersion extends Model
{
    protected $fillable = [
        'brand_model_id',
        'version_number',
        'source_type',
        'model_payload',
        'metrics_payload',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'model_payload' => 'array',
            'metrics_payload' => 'array',
        ];
    }

    /**
     * Get the brand model that owns this version.
     */
    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(BrandModel::class);
    }

    /**
     * Get the user who created this version.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
