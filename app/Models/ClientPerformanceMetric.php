<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPerformanceMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'url',
        'path',
        'ttfb_ms',
        'dom_content_loaded_ms',
        'load_event_ms',
        'total_load_ms',
        'avg_image_load_ms',
        'image_count',
        'user_id',
        'session_id',
    ];

    protected $casts = [
        'ttfb_ms' => 'integer',
        'dom_content_loaded_ms' => 'integer',
        'load_event_ms' => 'integer',
        'total_load_ms' => 'integer',
        'avg_image_load_ms' => 'integer',
        'image_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
