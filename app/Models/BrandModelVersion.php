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
 * lifecycle_stage: research | review | build | published
 */
class BrandModelVersion extends Model
{
    public const LIFECYCLE_RESEARCH = 'research';
    public const LIFECYCLE_REVIEW = 'review';
    public const LIFECYCLE_BUILD = 'build';
    public const LIFECYCLE_PUBLISHED = 'published';

    public const RESEARCH_NOT_STARTED = 'not_started';
    public const RESEARCH_RUNNING = 'running';
    public const RESEARCH_COMPLETE = 'complete';
    public const RESEARCH_FAILED = 'failed';

    public const REVIEW_PENDING = 'pending';
    public const REVIEW_IN_PROGRESS = 'in_progress';
    public const REVIEW_COMPLETE = 'complete';

    protected $fillable = [
        'brand_model_id',
        'version_number',
        'source_type',
        'model_payload',
        'metrics_payload',
        'builder_progress',
        'lifecycle_stage',
        'research_status',
        'review_status',
        'research_started_at',
        'research_completed_at',
        'review_completed_at',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'model_payload' => 'array',
            'metrics_payload' => 'array',
            'builder_progress' => 'array',
            'research_started_at' => 'datetime',
            'research_completed_at' => 'datetime',
            'review_completed_at' => 'datetime',
        ];
    }

    public function isResearchComplete(): bool
    {
        return $this->research_status === self::RESEARCH_COMPLETE;
    }

    public function isReviewComplete(): bool
    {
        return $this->review_status === self::REVIEW_COMPLETE;
    }

    public function canEnterBuilder(): bool
    {
        return $this->isResearchComplete();
    }

    public function isInLifecycleStage(string $stage): bool
    {
        return $this->lifecycle_stage === $stage;
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
