<?php

namespace App\Models;

use App\Enums\MetricType;
use App\Enums\ViewType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AssetMetric Model
 * 
 * Stores individual metric events for assets with full context.
 * Used for detailed analytics and periodic aggregation.
 * 
 * @property string $id
 * @property int $tenant_id
 * @property int|null $brand_id
 * @property string $asset_id
 * @property int|null $user_id
 * @property MetricType $metric_type
 * @property ViewType|null $view_type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 */
class AssetMetric extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'asset_metrics';

    /**
     * Indicates if the model should be timestamped.
     * Only created_at, no updated_at (append-only).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'asset_id',
        'user_id',
        'metric_type',
        'view_type',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
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
            'view_type' => ViewType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this metric.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand associated with this metric.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the asset this metric is for.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Get the user that triggered this metric.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Scope to filter by view type.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param ViewType|string $viewType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfViewType($query, ViewType|string $viewType)
    {
        $value = $viewType instanceof ViewType ? $viewType->value : $viewType;
        return $query->where('view_type', $value);
    }

    /**
     * Scope to get recent metrics.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
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
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query;
    }
}
