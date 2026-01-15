<?php

namespace App\Models;

use App\Enums\MetricType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MetricAggregate Model
 * 
 * Periodic aggregation of asset metrics for performance.
 * One row per asset per metric type per time period.
 * Used for fast widget queries without scanning millions of individual records.
 * 
 * @property int $id
 * @property int $tenant_id
 * @property int|null $brand_id
 * @property string $asset_id
 * @property MetricType $metric_type
 * @property string $period (daily, weekly, monthly)
 * @property \Carbon\Carbon $period_start
 * @property int $count
 * @property int $unique_users
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MetricAggregate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'metric_aggregates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'asset_id',
        'metric_type',
        'period',
        'period_start',
        'count',
        'unique_users',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_type' => MetricType::class,
            'period_start' => 'date',
            'count' => 'integer',
            'unique_users' => 'integer',
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

    /**
     * Get the asset this aggregate is for.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Scope to filter by tenant.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by brand.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $brandId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForBrand($query, ?int $brandId)
    {
        if ($brandId === null) {
            return $query->whereNull('brand_id');
        }
        
        return $query->where('brand_id', $brandId);
    }

    /**
     * Scope to filter by asset.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $assetId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAsset($query, string $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    /**
     * Scope to filter by metric type.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param MetricType|string $metricType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, MetricType|string $metricType)
    {
        $value = $metricType instanceof MetricType ? $metricType->value : $metricType;
        return $query->where('metric_type', $value);
    }

    /**
     * Scope to filter by period.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $period
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * Scope to filter by date range.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Carbon\Carbon|null $startDate
     * @param \Carbon\Carbon|null $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInDateRange($query, ?\Carbon\Carbon $startDate = null, ?\Carbon\Carbon $endDate = null)
    {
        if ($startDate) {
            $query->where('period_start', '>=', $startDate->format('Y-m-d'));
        }
        
        if ($endDate) {
            $query->where('period_start', '<=', $endDate->format('Y-m-d'));
        }
        
        return $query;
    }
}
