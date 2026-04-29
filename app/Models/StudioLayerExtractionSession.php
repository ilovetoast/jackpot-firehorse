<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioLayerExtractionSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONFIRMED = 'confirmed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'studio_layer_extraction_sessions';

    protected $fillable = [
        'id',
        'tenant_id',
        'brand_id',
        'user_id',
        'composition_id',
        'source_layer_id',
        'source_asset_id',
        'status',
        'provider',
        'model',
        'candidates_json',
        'metadata',
        'error_message',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class, 'composition_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
