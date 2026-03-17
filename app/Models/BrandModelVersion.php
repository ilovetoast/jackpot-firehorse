<?php

namespace App\Models;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Brand DNA / Brand Guidelines — versioned JSON model.
 * source_type: manual | scrape | ai | retrain
 * status: draft | active | archived
 */
class BrandModelVersion extends Model
{
    protected $fillable = [
        'brand_model_id',
        'version_number',
        'source_type',
        'model_payload',
        'metrics_payload',
        'builder_progress',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'model_payload' => 'array',
            'metrics_payload' => 'array',
            'builder_progress' => 'array',
        ];
    }

    /**
     * Get the brand model that owns this version.
     */
    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(BrandModel::class);
    }

    /**
     * Get the user who created this version.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versionAssets(): HasMany
    {
        return $this->hasMany(BrandModelVersionAsset::class);
    }

    /**
     * Assets for a specific builder context (e.g. brand_material, visual_reference).
     */
    public function assetsForContext(string $context): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'brand_model_version_assets', 'brand_model_version_id', 'asset_id')
            ->withPivot('builder_context', 'reference_type')
            ->wherePivot('builder_context', $context)
            ->withTimestamps();
    }

    public function insightState(): HasOne
    {
        return $this->hasOne(BrandModelVersionInsightState::class);
    }

    public function getOrCreateInsightState(?int $sourcePipelineSnapshotId = null): BrandModelVersionInsightState
    {
        $state = BrandModelVersionInsightState::firstOrCreate(
            ['brand_model_version_id' => $this->id],
            [
                'source_pipeline_snapshot_id' => $sourcePipelineSnapshotId,
                'dismissed' => [],
                'accepted' => [],
            ]
        );

        if ($sourcePipelineSnapshotId && ! $state->source_pipeline_snapshot_id) {
            $state->source_pipeline_snapshot_id = $sourcePipelineSnapshotId;
            $state->save();
        }

        return $state;
    }
}
