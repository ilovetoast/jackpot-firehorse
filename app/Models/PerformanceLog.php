<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'url',
        'method',
        'duration_ms',
        'user_id',
        'memory_usage',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'memory_usage' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
