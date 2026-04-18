<?php

namespace App\Services;

use App\Jobs\BrandPipelineRunnerJob;
use App\Jobs\RunBrandResearchJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandOnboardingProgress;
use App\Models\BrandPipelineRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BrandDNA\BrandVersionService;

class OnboardingService
{
    public const STEP_WELCOME = 'welcome';
    public const STEP_BRAND_SHELL = 'brand_shell';
    // Legacy step kept for backward compatibility with stored progress rows. The
    // cinematic flow no longer asks users to upload assets — that now happens
    // after onboarding inside the library.
    public const STEP_STARTER_ASSETS = 'starter_assets';
    public const STEP_CATEGORIES = 'categories';
    public const STEP_ENRICHMENT = 'enrichment';
    public const STEP_COMPLETE = 'complete';

    public const MIN_STARTER_ASSETS = 1;
    public const RECOMMENDED_STARTER_ASSETS = 3;

    public const STEPS = [
        self::STEP_WELCOME,
        self::STEP_BRAND_SHELL,
        self::STEP_CATEGORIES,
        self::STEP_ENRICHMENT,
        self::STEP_COMPLETE,
    ];

    // ── Gate helpers ────────────────────────────────────────────────

    /**
     * Whether the email-verification gate should block this user.
     *
     * Verified users always pass. For unverified users we only block when they
     * pose a bot / free-storage abuse risk: either they own a tenant (every
     * fresh signup that creates an account lands here), or the current tenant
     * is on the free plan (still inside the storage-abuse blast radius).
     *
     * Members added to a paid tenant (e.g. agency-created accounts that get
     * transferred to the client later) skip the gate — their ownership and
     * storage already sit behind a paid subscription, so forcing a reverify
     * later adds friction without mitigating abuse.
     */
    public function shouldShowVerificationGate(User $user, ?Brand $brand = null): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        // Resolve the tenant from the provided brand, falling back to the
        // container binding used throughout the tenant-scoped stack.
        $tenant = null;
        if ($brand) {
            $tenant = $brand->tenant ?? Tenant::find($brand->tenant_id);
        } elseif (app()->bound('brand')) {
            $contextBrand = app('brand');
            if ($contextBrand instanceof Brand) {
                $tenant = $contextBrand->tenant ?? Tenant::find($contextBrand->tenant_id);
            }
        }

        if (! $tenant) {
            // No tenant context — fall back to the conservative behaviour so a
            // stray unverified session can't silently bypass the gate.
            return true;
        }

        if ($tenant->isOwner($user)) {
            return true;
        }

        $planService = app(\App\Services\PlanService::class);
        if ($planService->getCurrentPlan($tenant) === 'free') {
            return true;
        }

        return false;
    }

    /**
     * Whether the middleware should hard-redirect to cinematic onboarding.
     * False once activated OR dismissed.
     */
    public function isBlocking(Brand $brand): bool
    {
        $progress = $this->getOrCreateProgress($brand);

        if ($progress->isActivated()) {
            return false;
        }

        if ($progress->isDismissed()) {
            return false;
        }

        return true;
    }

    public function shouldShowCinematicFlow(User $user, Brand $brand): bool
    {
        if ($this->shouldShowVerificationGate($user, $brand)) {
            return false;
        }

        return $this->isBlocking($brand);
    }

    // ── Progress record ────────────────────────────────────────────

    public function getOrCreateProgress(Brand $brand): BrandOnboardingProgress
    {
        $progress = $brand->onboardingProgress;

        if ($progress) {
            return $progress;
        }

        $progress = BrandOnboardingProgress::create([
            'brand_id' => $brand->id,
            'tenant_id' => $brand->tenant_id,
            'current_step' => self::STEP_WELCOME,
        ]);

        $this->syncProgressFromBrand($progress, $brand);

        $brand->setRelation('onboardingProgress', $progress);

        return $progress;
    }

    /**
     * Detect existing brand setup state (agency-created brands, returning users).
     */
    public function syncProgressFromBrand(BrandOnboardingProgress $progress, Brand $brand): void
    {
        $changed = false;

        if (! $progress->brand_name_confirmed && $brand->name !== null && $brand->name !== '') {
            $tenant = $brand->tenant ?? Tenant::find($brand->tenant_id);
            if ($tenant && $brand->name !== $tenant->name) {
                $progress->brand_name_confirmed = true;
                $changed = true;
            }
        }

        // Brand mark: only confirmed when actual logo asset exists or user explicitly
        // chose a monogram with a custom bg color (not just the seeded default icon_style).
        if (! $progress->brand_mark_confirmed) {
            $logoId = $brand->getRawOriginal('logo_id');
            $hasRealLogo = ($logoId !== null && $logoId !== '')
                || ($brand->attributes['logo_path'] ?? null) !== null;
            if ($hasRealLogo) {
                $progress->brand_mark_confirmed = true;
                $progress->brand_mark_type = 'logo';
                $progress->brand_mark_asset_id = $logoId;
                $changed = true;
            } elseif ($brand->icon_bg_color !== null && $brand->icon_bg_color !== '') {
                // icon_bg_color is only set when user explicitly picks a monogram color;
                // icon_style alone defaults to 'subtle' on every brand and isn't proof of a choice.
                $progress->brand_mark_confirmed = true;
                $progress->brand_mark_type = 'monogram';
                $changed = true;
            }
        }

        // Only count the color as "set" if the user explicitly chose it, not if it was seeded.
        if (! $progress->primary_color_set && $brand->primary_color !== null && $brand->primary_color !== '') {
            $userDefined = $brand->primary_color_user_defined ?? false;
            if ($userDefined) {
                $progress->primary_color_set = true;
                $changed = true;
            }
        }

        $assetCount = $brand->tenant
            ? \App\Models\Asset::where('brand_id', $brand->id)->count()
            : 0;
        if ($assetCount > $progress->starter_assets_count) {
            $progress->starter_assets_count = $assetCount;
            $changed = true;
        }

        // Detect stale "queued" enrichment — if stuck for 2+ minutes with no job running,
        // either re-dispatch or clear the status so the card doesn't spin forever.
        if ($progress->enrichment_processing_status === 'queued') {
            $staleThreshold = now()->subMinutes(2);
            $isStale = $progress->updated_at && $progress->updated_at->lt($staleThreshold);

            if ($isStale) {
                $hasWebsite = $progress->website_url !== null && $progress->website_url !== '';
                if ($hasWebsite) {
                    $this->dispatchWebsiteResearch($brand, $progress->website_url);
                    $progress->enrichment_processing_detail = 'Re-queued for processing';
                } else {
                    $progress->enrichment_processing_status = null;
                    $progress->enrichment_processing_detail = null;
                }
                $changed = true;
            }
        }

        if ($changed) {
            $this->maybeAutoActivate($progress);
            if ($progress->minimumActivationMet() && $progress->current_step === self::STEP_WELCOME) {
                $progress->current_step = self::STEP_ENRICHMENT;
            }
            $progress->save();
        }
    }

    // ── Step actions ───────────────────────────────────────────────

    public function saveBrandShell(Brand $brand, array $data): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        $brandUpdates = [];
        if (isset($data['name']) && $data['name'] !== '') {
            $brandUpdates['name'] = $data['name'];
            $progress->brand_name_confirmed = true;
        }
        if (isset($data['primary_color']) && $data['primary_color'] !== '') {
            $brandUpdates['primary_color'] = $data['primary_color'];
            $brandUpdates['primary_color_user_defined'] = true;
            $progress->primary_color_set = true;
        }
        if (isset($data['secondary_color'])) {
            $brandUpdates['secondary_color'] = $data['secondary_color'];
            $brandUpdates['secondary_color_user_defined'] = true;
        }
        if (isset($data['accent_color'])) {
            $brandUpdates['accent_color'] = $data['accent_color'];
            $brandUpdates['accent_color_user_defined'] = true;
        }

        // Brand mark: actual logo asset provided
        if (isset($data['logo_id']) && $data['logo_id'] !== '') {
            $brandUpdates['logo_id'] = $data['logo_id'];
            $progress->brand_mark_confirmed = true;
            $progress->brand_mark_type = 'logo';
            $progress->brand_mark_asset_id = $data['logo_id'];
        }
        if (isset($data['logo_dark_id'])) {
            $brandUpdates['logo_dark_id'] = $data['logo_dark_id'];
        }

        // Brand mark: logo path chosen but no asset yet — record intent, NOT confirmed
        if (isset($data['mark_type']) && $data['mark_type'] === 'logo' && ! $progress->brand_mark_confirmed) {
            $progress->brand_mark_type = 'logo';
            // brand_mark_confirmed stays false until real logo is provided
        }

        // Brand mark: monogram — immediately confirmed (system can render it)
        if (! empty($data['use_monogram'])) {
            $progress->brand_mark_confirmed = true;
            $progress->brand_mark_type = 'monogram';
            $progress->brand_mark_asset_id = null;
            if (isset($data['icon_bg_color'])) {
                $brandUpdates['icon_bg_color'] = $data['icon_bg_color'];
            }
        }

        if (! empty($brandUpdates)) {
            $brand->update($brandUpdates);
        }

        // Starter-asset upload step was removed from the cinematic flow (assets
        // are now uploaded later from inside the library), so advance straight
        // to category selection.
        $progress->current_step = self::STEP_CATEGORIES;

        // Brand shell captures all three activation requirements (name, colour,
        // brand mark). As soon as they're all satisfied, flip activated_at so
        // the Overview onboarding card stops blocking — no need to wait for
        // the user to click the cinematic "Finish" button.
        $this->maybeAutoActivate($progress);

        $progress->save();

        return $progress;
    }

    public function recordStarterAssets(Brand $brand, int $count): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);
        $progress->starter_assets_count = max($progress->starter_assets_count, $count);

        if ($progress->brand_mark_type === 'logo' && ! $progress->brand_mark_confirmed) {
            $this->recheckBrandLogo($progress, $brand->fresh());
        }

        $this->maybeAutoActivate($progress);
        $progress->save();

        return $progress;
    }

    public function recordCategoryPreferences(Brand $brand): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);
        $progress->category_preferences_saved = true;
        $progress->current_step = self::STEP_ENRICHMENT;
        $progress->save();

        return $progress;
    }

    public function saveEnrichment(Brand $brand, array $data): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        if (isset($data['website_url'])) {
            $progress->website_url = $data['website_url'];
        }
        if (isset($data['industry'])) {
            $progress->industry = $data['industry'];
        }
        if (! empty($data['guideline_uploaded'])) {
            $progress->guideline_uploaded = true;
        }
        if (! empty($data['guideline_asset_id'])) {
            $progress->guideline_uploaded = true;
        }

        $hasWebsite = $progress->website_url !== null && $progress->website_url !== '';
        $hasGuideline = ! empty($data['guideline_asset_id']);
        $hasActionableData = $progress->guideline_uploaded || $hasWebsite;

        if ($hasActionableData && $progress->enrichment_processing_status === null) {
            $progress->enrichment_processing_status = 'queued';
            $progress->enrichment_processing_detail = 'Queued for processing';
        }

        $progress->current_step = self::STEP_COMPLETE;
        $progress->save();

        $versionService = app(BrandVersionService::class);
        $draft = $versionService->getWorkingVersion($brand);

        // Store the website URL on the draft so the pipeline runner can use it
        if ($hasWebsite) {
            $payload = $draft->model_payload ?? [];
            $payload['sources'] = array_merge($payload['sources'] ?? [], [
                'website_url' => $progress->website_url,
            ]);
            $draft->update(['model_payload' => $payload]);

            $this->dispatchWebsiteResearch($brand, $progress->website_url);
        }

        // Dispatch PDF pipeline for uploaded guidelines
        if ($hasGuideline) {
            $this->dispatchGuidelinePipeline($brand, $draft, $data['guideline_asset_id']);
        }

        return $progress;
    }

    /**
     * Kick off brand research from a website URL.
     * Creates a working draft version and dispatches the research job.
     */
    private function dispatchWebsiteResearch(Brand $brand, string $url): void
    {
        try {
            $versionService = app(BrandVersionService::class);
            $draft = $versionService->getWorkingVersion($brand);

            RunBrandResearchJob::dispatch($brand->id, $draft->id, $url);

            \Illuminate\Support\Facades\Log::info('[OnboardingService] Dispatched website research', [
                'brand_id' => $brand->id,
                'url' => $url,
                'draft_id' => $draft->id,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[OnboardingService] Failed to dispatch website research', [
                'brand_id' => $brand->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            self::transitionEnrichmentStatus($brand, 'failed', 'Could not start website research');
        }
    }

    /**
     * Dispatch the PDF brand-guidelines pipeline.
     */
    private function dispatchGuidelinePipeline(Brand $brand, \App\Models\BrandModelVersion $draft, string $assetId): void
    {
        try {
            $asset = Asset::find($assetId);
            if (! $asset) {
                \Illuminate\Support\Facades\Log::warning('[OnboardingService] Guideline asset not found', [
                    'brand_id' => $brand->id,
                    'asset_id' => $assetId,
                ]);

                return;
            }

            $extractionMode = BrandPipelineRun::resolveExtractionMode($asset);

            $run = BrandPipelineRun::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $draft->id,
                'asset_id' => $asset->id,
                'source_size_bytes' => BrandPipelineRun::sourceSizeBytesFromAsset($asset),
                'stage' => BrandPipelineRun::STAGE_INIT,
                'extraction_mode' => $extractionMode,
                'status' => BrandPipelineRun::STATUS_PENDING,
            ]);

            BrandPipelineRunnerJob::dispatch($run->id);

            \Illuminate\Support\Facades\Log::info('[OnboardingService] Dispatched guideline pipeline', [
                'brand_id' => $brand->id,
                'asset_id' => $assetId,
                'run_id' => $run->id,
                'extraction_mode' => $extractionMode,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[OnboardingService] Failed to dispatch guideline pipeline', [
                'brand_id' => $brand->id,
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
            ]);

            self::transitionEnrichmentStatus($brand, 'failed', 'Could not start guideline processing');
        }
    }

    public function activate(Brand $brand, User $user): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        if (! $progress->isActivated()) {
            $progress->activated_at = now();
            $progress->activated_by_user_id = $user->id;
        }

        $progress->current_step = self::STEP_COMPLETE;
        $progress->save();

        return $progress;
    }

    public function completeOnboarding(Brand $brand, User $user): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        if (! $progress->isActivated()) {
            $progress->activated_at = now();
            $progress->activated_by_user_id = $user->id;
        }

        $progress->completed_at = now();
        $progress->current_step = self::STEP_COMPLETE;
        $progress->save();

        return $progress;
    }

    public function dismissCinematicFlow(Brand $brand): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        if (! $progress->isDismissed()) {
            $progress->dismissed_at = now();
            $progress->save();
        }

        return $progress;
    }

    /**
     * Permanently hide the onboarding card from Overview.
     * Separate from cinematic dismiss — requires a deliberate second action.
     */
    public function dismissCard(Brand $brand): BrandOnboardingProgress
    {
        $progress = $this->getOrCreateProgress($brand);

        if (! $progress->isCardDismissed()) {
            $progress->card_dismissed_at = now();
            $progress->save();
        }

        return $progress;
    }

    // ── Enrichment lifecycle (called from background jobs) ─────────

    /**
     * Centralized enrichment status updater. Called by pipeline jobs
     * when processing begins, completes, or fails.
     */
    public static function transitionEnrichmentStatus(Brand $brand, string $status, ?string $detail = null): void
    {
        $progress = $brand->onboardingProgress;
        if (! $progress) {
            return;
        }

        if (! in_array($status, ['queued', 'processing', 'complete', 'failed'], true)) {
            return;
        }

        $progress->enrichment_processing_status = $status;
        $progress->enrichment_processing_detail = $detail;
        $progress->save();
    }

    // ── Brand mark helpers ─────────────────────────────────────────

    /**
     * Re-check if the brand now has a real logo (e.g. uploaded during starter assets).
     */
    public function recheckBrandLogo(BrandOnboardingProgress $progress, Brand $brand): void
    {
        $logoId = $brand->getRawOriginal('logo_id');
        $hasRealLogo = ($logoId !== null && $logoId !== '')
            || ($brand->attributes['logo_path'] ?? null) !== null;

        if ($hasRealLogo && ! $progress->brand_mark_confirmed) {
            $progress->brand_mark_confirmed = true;
            $progress->brand_mark_type = 'logo';
            $progress->brand_mark_asset_id = $logoId;
        }
    }

    /**
     * Called when a logo asset is linked to the brand from any context
     * (Brand Settings, onboarding, etc.).
     */
    public function confirmLogoMark(Brand $brand, ?string $assetId = null): void
    {
        $progress = $brand->onboardingProgress;
        if (! $progress || $progress->isActivated()) {
            return;
        }

        $progress->brand_mark_confirmed = true;
        $progress->brand_mark_type = 'logo';
        $progress->brand_mark_asset_id = $assetId;
        $this->maybeAutoActivate($progress);
        $progress->save();
    }

    // ── Payload builders ───────────────────────────────────────────

    public function getStatusPayload(Brand $brand): array
    {
        $progress = $this->getOrCreateProgress($brand);

        // Re-sync from current brand state so settings changes (name, color, logo)
        // are reflected even if the user never went through the cinematic flow.
        $this->syncProgressFromBrand($progress, $brand);

        return [
            'current_step' => $progress->current_step,
            'activation_percent' => $progress->activationPercent(),
            'completion_percent' => $progress->completionPercent(),
            'is_activated' => $progress->isActivated(),
            'is_completed' => $progress->isCompleted(),
            'is_blocking' => $this->isBlocking($brand),
            'is_dismissed' => $progress->isDismissed(),
            'is_card_dismissed' => $progress->isCardDismissed(),
            'is_agency_created' => $this->isAgencyCreatedBrand($brand),
            'minimum_activation_met' => $progress->minimumActivationMet(),
            'recommended_completion_met' => $progress->recommendedCompletionMet(),
            'enrichment_processing_status' => $progress->enrichment_processing_status,
            'enrichment_processing_detail' => $progress->enrichment_processing_detail,
            'steps' => [
                'brand_name_confirmed' => $progress->brand_name_confirmed,
                'primary_color_set' => $progress->primary_color_set,
                'brand_mark_confirmed' => $progress->brand_mark_confirmed,
                'brand_mark_type' => $progress->brand_mark_type,
                'brand_mark_pending' => $progress->brand_mark_type === 'logo' && ! $progress->brand_mark_confirmed,
                'starter_assets_count' => $progress->starter_assets_count,
                'min_starter_assets' => self::MIN_STARTER_ASSETS,
                'recommended_starter_assets' => self::RECOMMENDED_STARTER_ASSETS,
                'guideline_uploaded' => $progress->guideline_uploaded,
                'website_url' => $progress->website_url,
                'industry' => $progress->industry,
            ],
            'activated_at' => $progress->activated_at?->toIso8601String(),
            'completed_at' => $progress->completed_at?->toIso8601String(),
        ];
    }

    public function getChecklistItems(Brand $brand): array
    {
        $progress = $this->getOrCreateProgress($brand);

        $brandMarkDone = $progress->brand_mark_confirmed;
        $brandMarkDetail = null;
        if ($progress->brand_mark_type === 'monogram' && $brandMarkDone) {
            $brandMarkDetail = 'Using temporary monogram';
        } elseif ($progress->brand_mark_type === 'logo' && ! $brandMarkDone) {
            $brandMarkDetail = 'Logo still needed';
        }

        return [
            [
                'key' => 'brand_name',
                'label' => 'Confirm your brand name',
                'done' => $progress->brand_name_confirmed,
                'required' => true,
            ],
            [
                'key' => 'brand_mark',
                'label' => 'Choose your brand mark',
                'done' => $brandMarkDone,
                'required' => true,
                'detail' => $brandMarkDetail,
            ],
            [
                'key' => 'primary_color',
                'label' => 'Set your primary brand color',
                'done' => $progress->primary_color_set,
                'required' => true,
            ],
            [
                'key' => 'guidelines',
                'label' => 'Add guidelines or website',
                'done' => $progress->guideline_uploaded
                    || ($progress->website_url !== null && $progress->website_url !== ''),
                'required' => false,
            ],
            [
                'key' => 'industry',
                'label' => 'Complete brand profile',
                'done' => $progress->industry !== null && $progress->industry !== '',
                'required' => false,
            ],
        ];
    }

    // ── Internal helpers ───────────────────────────────────────────

    private function maybeAutoActivate(BrandOnboardingProgress $progress): void
    {
        if ($progress->minimumActivationMet() && ! $progress->isActivated()) {
            $progress->activated_at = now();
        }
    }

    private function isAgencyCreatedBrand(Brand $brand): bool
    {
        if ($brand->owning_tenant_id !== null && $brand->owning_tenant_id !== $brand->tenant_id) {
            return true;
        }

        $tenant = $brand->tenant ?? Tenant::find($brand->tenant_id);
        if ($tenant && $tenant->incubated_by_agency_id !== null) {
            return true;
        }

        return false;
    }
}
