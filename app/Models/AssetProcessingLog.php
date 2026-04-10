<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetProcessingLog extends Model
{
    protected $fillable = [
        'asset_id',
        'action_type',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
