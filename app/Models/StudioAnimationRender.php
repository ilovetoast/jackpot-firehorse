<?php

namespace App\Models;

use App\Studio\Animation\Enums\StudioAnimationRenderRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioAnimationRender extends Model
{
    protected $fillable = [
        'studio_animation_job_id',
        'render_role',
        'asset_id',
        'disk',
        'path',
        'mime_type',
        'width',
        'height',
        'sha256',
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

    public function roleEnum(): ?StudioAnimationRenderRole
    {
        return StudioAnimationRenderRole::tryFrom((string) $this->render_role);
    }
}
