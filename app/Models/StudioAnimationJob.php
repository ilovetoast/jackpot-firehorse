<?php

namespace App\Models;

use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudioAnimationJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'user_id',
        'studio_document_id',
        'composition_id',
        'source_composition_version_id',
        'source_document_revision_hash',
        'animation_intent_json',
        'provider',
        'provider_model',
        'status',
        'source_strategy',
        'prompt',
        'negative_prompt',
        'motion_preset',
        'duration_seconds',
        'aspect_ratio',
        'generate_audio',
        'settings_json',
        'provider_request_json',
        'provider_response_json',
        'provider_job_id',
        'provider_queue_request_id',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'generate_audio' => 'boolean',
            'animation_intent_json' => 'array',
            'settings_json' => 'array',
            'provider_request_json' => 'array',
            'provider_response_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function statusEnum(): ?StudioAnimationStatus
    {
        return StudioAnimationStatus::tryFrom((string) $this->status);
    }

    public function sourceStrategyEnum(): ?StudioAnimationSourceStrategy
    {
        return StudioAnimationSourceStrategy::tryFrom((string) $this->source_strategy);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function sourceCompositionVersion(): BelongsTo
    {
        return $this->belongsTo(CompositionVersion::class, 'source_composition_version_id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(StudioAnimationRender::class, 'studio_animation_job_id');
    }

    /**
     * Exactly one output row per job is enforced at the database level (unique studio_animation_job_id).
     */
    public function output(): HasOne
    {
        return $this->hasOne(StudioAnimationOutput::class, 'studio_animation_job_id');
    }

    /**
     * Legacy plural relation; unique index guarantees at most one row. Prefer {@see output()} for reads and policy checks.
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(StudioAnimationOutput::class, 'studio_animation_job_id');
    }

    public function markStatus(StudioAnimationStatus $status): void
    {
        $this->update(['status' => $status->value]);
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?StudioAnimationJob
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! $tenant) {
            return null;
        }

        // Scope by tenant only here; session brand is enforced in {@see \App\Policies\StudioAnimationJobPolicy}
        // so a valid id in another brand yields 403 (forbidden) instead of 404 (not found).
        return self::query()
            ->whereKey($value)
            ->where('tenant_id', $tenant->id)
            ->first();
    }
}
