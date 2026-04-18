<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOnboardingProgress extends Model
{
    protected $table = 'brand_onboarding_progress';

    protected $fillable = [
        'brand_id',
        'tenant_id',
        'current_step',
        'brand_name_confirmed',
        'primary_color_set',
        'brand_mark_confirmed',
        'brand_mark_type',
        'brand_mark_asset_id',
        'starter_assets_count',
        'guideline_uploaded',
        'website_url',
        'industry',
        'enrichment_processing_status',
        'enrichment_processing_detail',
        'category_preferences_saved',
        'metadata',
        'activated_at',
        'completed_at',
        'dismissed_at',
        'card_dismissed_at',
        'activated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'brand_name_confirmed' => 'boolean',
            'primary_color_set' => 'boolean',
            'brand_mark_confirmed' => 'boolean',
            'guideline_uploaded' => 'boolean',
            'category_preferences_saved' => 'boolean',
            'metadata' => 'array',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'card_dismissed_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /**
     * Overview card permanently hidden by deliberate user action.
     */
    public function isCardDismissed(): bool
    {
        return $this->card_dismissed_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Minimum activation: name + color + brand mark (real proof). Asset upload
     * is no longer part of the cinematic onboarding flow — users drop into the
     * library to upload after activation.
     */
    public function minimumActivationMet(): bool
    {
        return $this->brand_name_confirmed
            && $this->primary_color_set
            && $this->brand_mark_confirmed;
    }

    public function recommendedCompletionMet(): bool
    {
        return $this->minimumActivationMet()
            && ($this->guideline_uploaded
                || ($this->website_url !== null && $this->website_url !== ''));
    }

    /**
     * Activation progress (0–100) across the 3 required steps.
     */
    public function activationPercent(): int
    {
        $steps = [
            $this->brand_name_confirmed,
            $this->primary_color_set,
            $this->brand_mark_confirmed,
        ];

        return (int) round((count(array_filter($steps)) / count($steps)) * 100);
    }

    /**
     * Full completion percent (activation 70%, enrichment 30%).
     */
    public function completionPercent(): int
    {
        $activationPct = $this->activationPercent();

        $optional = [
            $this->guideline_uploaded,
            $this->website_url !== null && $this->website_url !== '',
            $this->industry !== null && $this->industry !== '',
        ];

        $optionalDone = count(array_filter($optional));
        $optionalPct = ($optionalDone / max(count($optional), 1)) * 100;

        return (int) round(($activationPct * 0.7) + ($optionalPct * 0.3));
    }

    public function hasEnrichmentProcessing(): bool
    {
        return in_array($this->enrichment_processing_status, ['queued', 'processing'], true);
    }

    public function isEnrichmentComplete(): bool
    {
        return $this->enrichment_processing_status === 'complete';
    }

    public function isEnrichmentFailed(): bool
    {
        return $this->enrichment_processing_status === 'failed';
    }
}
