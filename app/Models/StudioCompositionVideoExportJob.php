<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioCompositionVideoExportJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'composition_id',
        'render_mode',
        'status',
        'error_json',
        'meta_json',
        'output_asset_id',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'error_json' => 'array',
            'meta_json' => 'array',
        ];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
