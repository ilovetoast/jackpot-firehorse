<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeSetVariant extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'creative_set_id',
        'composition_id',
        'sort_order',
        'label',
        'status',
        'axis',
        'generation_job_item_id',
    ];

    protected function casts(): array
    {
        return [
            'axis' => 'array',
        ];
    }

    public function creativeSet(): BelongsTo
    {
        return $this->belongsTo(CreativeSet::class, 'creative_set_id');
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function generationJobItem(): BelongsTo
    {
        return $this->belongsTo(GenerationJobItem::class, 'generation_job_item_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
