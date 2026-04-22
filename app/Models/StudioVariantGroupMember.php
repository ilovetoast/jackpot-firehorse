<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioVariantGroupMember extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const GENERATION_QUEUED = 'queued';

    public const GENERATION_RUNNING = 'running';

    public const GENERATION_READY = 'ready';

    public const GENERATION_FAILED = 'failed';

    protected $fillable = [
        'studio_variant_group_id',
        'composition_id',
        'slot_key',
        'label',
        'status',
        'generation_status',
        'spec_json',
        'generation_job_item_id',
        'result_asset_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'spec_json' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudioVariantGroup::class, 'studio_variant_group_id');
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class, 'composition_id');
    }

    public function generationJobItem(): BelongsTo
    {
        return $this->belongsTo(GenerationJobItem::class, 'generation_job_item_id');
    }
}
