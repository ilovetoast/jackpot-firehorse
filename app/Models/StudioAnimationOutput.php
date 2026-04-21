<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioAnimationOutput extends Model
{
    protected $fillable = [
        'studio_animation_job_id',
        'finalize_fingerprint',
        'asset_id',
        'disk',
        'video_path',
        'poster_path',
        'mime_type',
        'duration_seconds',
        'width',
        'height',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StudioAnimationJob::class, 'studio_animation_job_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
