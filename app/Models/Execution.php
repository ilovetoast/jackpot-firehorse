<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Execution extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'category_id',
        'name',
        'status',
        'context_json',
        'primary_asset_id',
        'finalized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function primaryAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'primary_asset_id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'execution_assets', 'execution_id', 'asset_id')
            ->withPivot(['sort_order', 'role'])
            ->orderByPivot('sort_order');
    }

    public function brandIntelligenceScores(): HasMany
    {
        return $this->hasMany(BrandIntelligenceScore::class)->latest();
    }

    /**
     * Most recent Brand Intelligence score for this execution (primary UI hook).
     */
    public function latestScore(): HasOne
    {
        return $this->hasOne(BrandIntelligenceScore::class)->latestOfMany();
    }
}
