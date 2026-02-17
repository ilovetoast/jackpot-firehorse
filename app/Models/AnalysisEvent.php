<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable log of analysis_status transitions.
 * Used for debugging pipeline progression and audit.
 */
class AnalysisEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'previous_status',
        'new_status',
        'job',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
