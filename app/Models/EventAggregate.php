<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Phase 4 — Analytics Aggregation (FOUNDATION)
 * 
 * Consumes events from locked phases only.
 * Must not modify event producers.
 * 
 * EventAggregate Model
 * 
 * Time-bucketed aggregation of activity events by tenant and event type.
 * Used for efficient querying and pattern detection without scanning raw events.
 * 
 * @property int $id
 * @property int $tenant_id
 * @property int|null $brand_id
 * @property string $event_type
 * @property \Carbon\Carbon $bucket_start_at
 * @property \Carbon\Carbon $bucket_end_at
 * @property int $count
 * @property int $success_count
 * @property int $failure_count
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EventAggregate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'event_aggregates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'event_type',
        'bucket_start_at',
        'bucket_end_at',
        'count',
        'success_count',
        'failure_count',
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
            'success_count' => 'integer',
            'failure_count' => 'integer',
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
     * Get the brand associated with this aggregate.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
