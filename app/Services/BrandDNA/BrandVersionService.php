<?php

namespace App\Services\BrandDNA;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandPipelineRun;
use App\Support\WebsiteUrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Brand Version Service — unified lifecycle + draft management for Brand Guidelines.
 *
 * Replaces BrandDnaDraftService. Handles:
 * - Version creation, retrieval, and lifecycle transitions
 * - Payload patching (wizard write path)
 * - Prefill application
 */
class BrandVersionService
{
    public function __construct(
        private BrandDnaPayloadNormalizer $normalizer
    ) {}

    // ─── Version retrieval ───────────────────────────────────────────

    /**
     * Get or create the working draft for a brand.
     * Returns the latest draft (status=draft); creates one if none exists.
     * New drafts are seeded from the active version's model_payload (or empty if none).
     */
    public function getWorkingVersion(Brand $brand): BrandModelVersion
    {
        $brandModel = $this->ensureBrandModel($brand);

        $draft = $brandModel->versions()
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->first();

        if ($draft) {
            return $draft;
        }

        return DB::transaction(function () use ($brandModel) {
            $activeVersion = $brandModel->activeVersion;
            $basePayload = $activeVersion
                ? ($activeVersion->model_payload ?? [])
                : [];

            $versionNumber = $brandModel->versions()->max('version_number') + 1;

            return $brandModel->versions()->create([
                'version_number' => $versionNumber,
                'source_type' => 'manual',
                'model_payload' => $this->normalizer->normalize($basePayload),
                'metrics_payload' => null,
                'status' => 'draft',
                'lifecycle_stage' => BrandModelVersion::LIFECYCLE_RESEARCH,
                'research_status' => BrandModelVersion::RESEARCH_NOT_STARTED,
                'review_status' => BrandModelVersion::REVIEW_PENDING,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Remove all draft versions for this brand (in-progress guidelines only).
     * Published/active/archived versions are untouched.
     *
     * @return int Number of draft rows deleted
     */
    public function discardAllDrafts(Brand $brand): int
    {
        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            return 0;
        }

        return DB::transaction(function () use ($brandModel) {
            return $this->deleteDraftVersionsForModel($brandModel);
        });
    }

    /**
     * Delete every draft version row for a brand model (cascades version assets, insight state, etc.).
     */
    protected function deleteDraftVersionsForModel(BrandModel $brandModel): int
    {
        $drafts = $brandModel->versions()->where('status', 'draft')->get();
        if ($drafts->isEmpty()) {
            return 0;
        }

        $draftIds = $drafts->pluck('id')->all();
        BrandPipelineRun::whereIn('brand_model_version_id', $draftIds)
            ->whereIn('status', [BrandPipelineRun::STATUS_PENDING, BrandPipelineRun::STATUS_PROCESSING])
            ->update([
                'status' => BrandPipelineRun::STATUS_FAILED,
                'stage' => BrandPipelineRun::STAGE_FAILED,
                'error_message' => 'Cancelled (draft reset)',
            ]);

        $count = 0;
        foreach ($drafts as $draft) {
            $draft->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Create a NEW draft version (e.g. "Start Over" / "Run Builder Again").
     * Seeds from active version. Any existing draft rows are removed first so only one draft exists.
     */
    public function createNewVersion(Brand $brand): BrandModelVersion
    {
        $brandModel = $this->ensureBrandModel($brand);

        return DB::transaction(function () use ($brandModel) {
            $this->deleteDraftVersionsForModel($brandModel);

            $activeVersion = $brandModel->activeVersion;
            $basePayload = $activeVersion
                ? ($activeVersion->model_payload ?? [])
                : [];

            $versionNumber = $brandModel->versions()->max('version_number') + 1;

            return $brandModel->versions()->create([
                'version_number' => $versionNumber,
                'source_type' => 'manual',
                'model_payload' => $this->normalizer->normalize($basePayload),
                'metrics_payload' => null,
                'status' => 'draft',
                'lifecycle_stage' => BrandModelVersion::LIFECYCLE_RESEARCH,
                'research_status' => BrandModelVersion::RESEARCH_NOT_STARTED,
                'review_status' => BrandModelVersion::REVIEW_PENDING,
                'created_by' => auth()->id(),
            ]);
        });
    }

    // ─── Lifecycle transitions ───────────────────────────────────────

    public function markResearchRunning(BrandModelVersion $version): void
    {
        $version->update([
            'research_status' => BrandModelVersion::RESEARCH_RUNNING,
            'research_started_at' => $version->research_started_at ?? now(),
        ]);
    }

    public function markResearchComplete(BrandModelVersion $version): void
    {
        $version->update([
            'research_status' => BrandModelVersion::RESEARCH_COMPLETE,
            'research_completed_at' => now(),
        ]);
    }

    public function markResearchFailed(BrandModelVersion $version): void
    {
        $version->update([
            'research_status' => BrandModelVersion::RESEARCH_FAILED,
        ]);
    }

    public function advanceToReview(BrandModelVersion $version): void
    {
        $version->update([
            'lifecycle_stage' => BrandModelVersion::LIFECYCLE_REVIEW,
            'research_status' => BrandModelVersion::RESEARCH_COMPLETE,
            'research_completed_at' => $version->research_completed_at ?? now(),
        ]);
    }

    public function advanceToBuild(BrandModelVersion $version): void
    {
        $version->update([
            'lifecycle_stage' => BrandModelVersion::LIFECYCLE_BUILD,
            'review_status' => BrandModelVersion::REVIEW_COMPLETE,
            'review_completed_at' => now(),
        ]);
    }

    // ─── Payload patching (wizard write path) ────────────────────────

    /**
     * Allowed paths per builder step. Uses step config.
     */
    protected static function allowedPathsForStep(string $stepKey): array
    {
        return BrandGuidelinesBuilderSteps::allowedPathsForStep($stepKey);
    }

    /**
     * Patch draft payload with incoming data. Deep merge restricted to allowed paths.
     */
    public function patchDraftPayload(Brand $brand, array $patch, array $allowedPaths): BrandModelVersion
    {
        $draft = $this->getWorkingVersion($brand);
        $current = $draft->model_payload ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        $merged = $this->deepMergeRestricted($current, $patch, $allowedPaths);
        $normalized = $this->normalizer->normalize($merged);

        $draft->update(['model_payload' => $normalized]);

        return $draft->fresh();
    }

    /**
     * Patch from builder step. Updates the draft version only.
     */
    public function patchFromStep(Brand $brand, string $stepKey, array $payload): BrandModelVersion
    {
        if (! BrandGuidelinesBuilderSteps::isValidStepKey($stepKey)) {
            throw new \InvalidArgumentException("Unknown step_key: {$stepKey}");
        }

        $allowedPaths = self::allowedPathsForStep($stepKey);
        if (empty($allowedPaths)) {
            throw new \InvalidArgumentException("Unknown step_key: {$stepKey}");
        }

        $filteredPatch = [];
        foreach ($allowedPaths as $path) {
            if (array_key_exists($path, $payload)) {
                $filteredPatch[$path] = $payload[$path];
            }
        }

        if (isset($filteredPatch['sources']) && is_array($filteredPatch['sources']) && array_key_exists('website_url', $filteredPatch['sources'])) {
            $wu = $filteredPatch['sources']['website_url'];
            if (is_string($wu) && trim($wu) !== '') {
                $filteredPatch['sources']['website_url'] = WebsiteUrlNormalizer::normalize($wu) ?? '';
            }
        }

        if (array_key_exists('brand_colors', $filteredPatch)) {
            $colors = $filteredPatch['brand_colors'];
            if (is_array($colors)) {
                $updates = [];
                if (array_key_exists('primary_color', $colors)) {
                    $updates['primary_color'] = $colors['primary_color'];
                    $updates['primary_color_user_defined'] = true;
                }
                if (array_key_exists('secondary_color', $colors)) {
                    $updates['secondary_color'] = $colors['secondary_color'];
                    $updates['secondary_color_user_defined'] = true;
                }
                if (array_key_exists('accent_color', $colors)) {
                    $updates['accent_color'] = $colors['accent_color'];
                    $updates['accent_color_user_defined'] = true;
                }
                if (! empty($updates)) {
                    $brand = Brand::find($brand->id);
                    $brand->update($updates);
                }
            }
            unset($filteredPatch['brand_colors']);
        }

        $modelPayloadPaths = array_values(array_filter($allowedPaths, fn ($p) => $p !== 'brand_colors'));

        return $this->patchDraftPayload($brand, $filteredPatch, $modelPayloadPaths);
    }

    /**
     * Apply a prefill patch to the draft with fill_empty or replace semantics.
     *
     * @return array{applied: array, skipped: array<string>, draft: BrandModelVersion}
     */
    public function applyPrefillPatch(Brand $brand, array $suggestedPatch, string $mode, ?int $targetVersionId = null): array
    {
        $draft = $targetVersionId
            ? BrandModelVersion::where('brand_model_id', $brand->brandModel?->id)->where('id', $targetVersionId)->where('status', 'draft')->first()
            : $this->getWorkingVersion($brand);

        if (! $draft) {
            throw new \InvalidArgumentException('Draft version not found.');
        }

        $current = $draft->model_payload ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        $applied = [];
        $skipped = [];

        if ($mode === 'fill_empty') {
            $toApply = $this->filterFillEmptyOnly($current, $suggestedPatch, '', $skipped);
        } else {
            $toApply = $suggestedPatch;
        }

        $allPaths = ['sources', 'identity', 'personality', 'typography', 'scoring_rules', 'visual', 'brand_colors'];
        $merged = $this->deepMergeRestricted($current, $toApply, $allPaths);

        if (array_key_exists('brand_colors', $toApply) && is_array($toApply['brand_colors'])) {
            $colors = $toApply['brand_colors'];
            $updates = [];
            if (! empty($colors['primary_color'])) {
                $updates['primary_color'] = $colors['primary_color'];
            }
            if (! empty($colors['secondary_color'])) {
                $updates['secondary_color'] = $colors['secondary_color'];
            }
            if (! empty($colors['accent_color'])) {
                $updates['accent_color'] = $colors['accent_color'];
            }
            if (! empty($updates)) {
                $brand->update($updates);
            }
        }
        unset($merged['brand_colors']);

        $normalized = $this->normalizer->normalize($merged);
        $draft->update(['model_payload' => $normalized]);

        return [
            'applied' => $toApply,
            'skipped' => $skipped,
            'draft' => $draft->fresh(),
        ];
    }

    // ─── Internal helpers ────────────────────────────────────────────

    protected function ensureBrandModel(Brand $brand): BrandModel
    {
        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create([
                'is_enabled' => false,
                'brand_dna_scoring_enabled' => true,
            ]);
        }

        return $brandModel;
    }

    protected function deepMergeRestricted(array $current, array $patch, array $allowedPaths): array
    {
        $result = $current;

        foreach ($allowedPaths as $path) {
            if (! array_key_exists($path, $patch)) {
                continue;
            }
            $patchValue = $patch[$path];
            $currentValue = $result[$path] ?? [];
            if (! is_array($currentValue)) {
                $currentValue = [];
            }
            if (! is_array($patchValue)) {
                $result[$path] = $patchValue;
            } else {
                $result[$path] = $this->arrayMergeRecursive($currentValue, $patchValue);
            }
        }

        return $result;
    }

    protected function arrayMergeRecursive(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                $current[$key] = $this->arrayMergeRecursive($current[$key], $value);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    protected function filterFillEmptyOnly(array $current, array $patch, string $pathPrefix, array &$skipped): array
    {
        $result = [];
        foreach ($patch as $key => $patchVal) {
            $dotPath = $pathPrefix ? "{$pathPrefix}.{$key}" : $key;
            $curVal = $current[$key] ?? null;

            if (is_array($patchVal)) {
                $curArr = is_array($curVal) ? $curVal : [];
                $filtered = $this->filterFillEmptyOnly($curArr, $patchVal, $dotPath, $skipped);
                if ($filtered !== []) {
                    $result[$key] = $filtered;
                }
            } else {
                $isEmpty = $curVal === null || $curVal === '' || (is_array($curVal) && $curVal === []);
                if ($isEmpty && $patchVal !== null && $patchVal !== '') {
                    $result[$key] = $patchVal;
                } elseif (! $isEmpty) {
                    $skipped[] = $dotPath;
                }
            }
        }

        return $result;
    }
}
