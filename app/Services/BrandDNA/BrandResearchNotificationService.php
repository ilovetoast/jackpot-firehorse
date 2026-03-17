<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\Notification;
use App\Services\FeatureGate;
use Illuminate\Support\Facades\Log;

/**
 * Notifies users when Brand Guidelines research processing is complete.
 * Prevents duplicate notifications via research_ready_notified_at on insight state.
 */
class BrandResearchNotificationService
{
    public function __construct(
        protected \App\Services\BrandDNA\PipelineFinalizationService $finalizationService,
        protected FeatureGate $featureGate
    ) {}

    /**
     * If research is finalized and not yet notified, create notification and mark notified.
     */
    public function maybeNotifyResearchReady(Brand $brand, BrandModelVersion $draft): void
    {
        $finalization = $this->finalizationService->compute(
            $brand->id,
            $draft->id,
            $draft->assetsForContext('guidelines_pdf')->first(),
            $this->hasWebsiteUrl($draft),
            $this->hasSocialUrls($draft),
            $draft->assetsForContext('brand_material')->count()
        );

        if (! ($finalization['research_finalized'] ?? false)) {
            return;
        }

        $state = $draft->insightState;
        if (! $state) {
            return;
        }

        if ($state->research_ready_notified_at !== null) {
            return;
        }

        $user = $draft->createdByUser;
        if (! $user) {
            Log::info('[BrandResearchNotificationService] Draft has no creator, skipping notification', [
                'draft_id' => $draft->id,
            ]);
            return;
        }

        $tenant = $brand->tenant;
        if (! $tenant) {
            return;
        }

        if (! $this->featureGate->notificationsEnabled($tenant)) {
            return;
        }

        $actionUrl = route('brands.brand-guidelines.builder', ['brand' => $brand->id, 'step' => 'research-summary']);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'brand_research.ready',
            'data' => [
                'title' => 'Brand research is ready',
                'body' => 'Your uploaded brand guidelines for ' . $brand->name . ' have finished processing.',
                'action_url' => $actionUrl,
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'draft_id' => $draft->id,
                'created_at' => now()->toISOString(),
            ],
        ]);

        $state->update(['research_ready_notified_at' => now()]);

        Log::info('[BrandResearchNotificationService] Notified user that brand research is ready', [
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
        ]);
    }

    private function hasWebsiteUrl(BrandModelVersion $draft): bool
    {
        $sources = $draft->model_payload['sources'] ?? [];

        return ! empty(trim((string) ($sources['website_url'] ?? '')));
    }

    private function hasSocialUrls(BrandModelVersion $draft): bool
    {
        $sources = $draft->model_payload['sources'] ?? [];

        return ! empty($sources['social_urls'] ?? []);
    }
}
