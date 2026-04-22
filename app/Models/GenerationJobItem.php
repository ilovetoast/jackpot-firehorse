<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationJobItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'generation_job_id',
        'retried_from_item_id',
        'combination_key',
        'status',
        'creative_set_variant_id',
        'studio_variant_group_member_id',
        'composition_id',
        'attempts',
        'error',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'error' => 'array',
            'superseded_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class, 'generation_job_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function creativeSetVariant(): BelongsTo
    {
        return $this->belongsTo(CreativeSetVariant::class);
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function studioVariantGroupMember(): BelongsTo
    {
        return $this->belongsTo(StudioVariantGroupMember::class, 'studio_variant_group_member_id');
    }
}
