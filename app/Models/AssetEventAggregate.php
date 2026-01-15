<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Phase 4 — Analytics Aggregation (FOUNDATION)
 * 
 * Consumes events from locked phases only.
 * Must not modify event producers.
 * 
 * AssetEventAggregate Model
 * 
 * Time-bucketed aggregation of activity events per asset.
 * Enables efficient per-asset analytics and pattern detection.
 * 
 * @property int $id
 * @property int $tenant_id
 * @property string $asset_id
 * @property string $event_type
 * @property \Carbon\Carbon $bucket_start_at
 * @property \Carbon\Carbon|null $bucket_end_at
 * @property int $count
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AssetEventAggregate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'asset_event_aggregates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'event_type',
        'bucket_start_at',
        'bucket_end_at',
        'count',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket_start_at' => 'datetime',
            'bucket_end_at' => 'datetime',
            'count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the tenant that owns this aggregate.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the asset this aggregate is for.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
