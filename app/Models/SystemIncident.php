<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unified operational incident record.
 *
 * DB is source of truth. No logging-only logic.
 */
class SystemIncident extends Model
{
    protected $table = 'system_incidents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'source_type',
        'source_id',
        'tenant_id',
        'severity',
        'title',
        'message',
        'metadata',
        'retryable',
        'requires_support',
        'auto_resolved',
        'resolved_at',
        'detected_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'retryable' => 'boolean',
        'requires_support' => 'boolean',
        'auto_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'detected_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_id', 'id');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeForAsset($query, string $assetId)
    {
        return $query->where('source_type', 'asset')
            ->where('source_id', $assetId);
    }

    public function scopeForJob($query)
    {
        return $query->where('source_type', 'job');
    }
}
