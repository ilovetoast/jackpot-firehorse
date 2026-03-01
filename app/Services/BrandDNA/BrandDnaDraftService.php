<?php

namespace App\Services\BrandDNA;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use Illuminate\Support\Facades\DB;

/**
 * Brand DNA Draft Service — wizard write path for Brand Guidelines Builder.
 * Manages draft BrandModelVersion and safe patch/merge of partial payloads.
 * Uses BrandGuidelinesBuilderSteps as single source of truth for allowed paths.
 */
class BrandDnaDraftService
{
    public function __construct(
        private BrandDnaPayloadNormalizer $normalizer
    ) {}

    /**
     * Allowed paths per builder step. Uses step config.
     */
    protected static function allowedPathsForStep(string $stepKey): array
    {
        return BrandGuidelinesBuilderSteps::allowedPathsForStep($stepKey);
    }

    /**
     * Get or create the draft BrandModelVersion for the brand.
     * Returns the latest draft (status=draft); creates one if none exists.
     */
    public function getOrCreateDraftVersion(Brand $brand): BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create([
                'is_enabled' => false,
                'brand_dna_scoring_enabled' => true,
            ]);
        }

        $draft = $brandModel->versions()
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->first();

        if ($draft) {
            return $draft;
        }

        return DB::transaction(function () use ($brandModel, $brand) {
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
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Patch draft payload with incoming data. Deep merge restricted to allowed paths.
     * Preserves existing keys not present in incoming payload.
     *
     * @param  array<string>  $allowedPaths  Top-level keys allowed (e.g. ['identity', 'personality'])
     */
    public function patchDraftPayload(Brand $brand, array $patch, array $allowedPaths): BrandModelVersion
    {
        $draft = $this->getOrCreateDraftVersion($brand);
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
     * Deep merge: only merge keys under allowed top-level paths.
     * For each allowed path, recursively merge patch into current (patch wins for conflicts).
     * Sibling sections not in allowedPaths are left untouched.
     */
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

    /**
     * Recursive merge: patch values overwrite current for same keys.
     * Preserves current keys not in patch.
     */
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

    /**
     * Patch from builder step. Determines allowed paths from step config.
     * Filters payload to only allowed paths (rejects arbitrary keys).
     * Handles brand_colors specially: updates Brand model, not model_payload.
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

        // Filter payload to only allowed paths (server enforces; client cannot invent keys)
        $filteredPatch = [];
        foreach ($allowedPaths as $path) {
            if (array_key_exists($path, $payload)) {
                $filteredPatch[$path] = $payload[$path];
            }
        }

        // brand_colors: update Brand model directly, do not store in model_payload
        if (array_key_exists('brand_colors', $filteredPatch)) {
            $colors = $filteredPatch['brand_colors'];
            if (is_array($colors)) {
                $updates = [];
                if (array_key_exists('primary_color', $colors) && $colors['primary_color'] !== null) {
                    $updates['primary_color'] = $colors['primary_color'];
                }
                if (array_key_exists('secondary_color', $colors) && $colors['secondary_color'] !== null) {
                    $updates['secondary_color'] = $colors['secondary_color'];
                }
                if (array_key_exists('accent_color', $colors) && $colors['accent_color'] !== null) {
                    $updates['accent_color'] = $colors['accent_color'];
                }
                if (! empty($updates)) {
                    $brand->update($updates);
                }
            }
            unset($filteredPatch['brand_colors']);
        }

        // Paths for model_payload only (exclude brand_colors which updates Brand)
        $modelPayloadPaths = array_values(array_filter($allowedPaths, fn ($p) => $p !== 'brand_colors'));

        return $this->patchDraftPayload($brand, $filteredPatch, $modelPayloadPaths);
    }

    /**
     * Apply a prefill patch to the draft with fill_empty or replace semantics.
     * fill_empty: only write keys that are currently empty/null/[] in the draft (deep).
     * replace: overwrite those keys.
     *
     * @return array{applied: array, skipped: array<string>, draft: BrandModelVersion}
     */
    public function applyPrefillPatch(Brand $brand, array $suggestedPatch, string $mode, ?int $targetVersionId = null): array
    {
        $draft = $targetVersionId
            ? BrandModelVersion::where('brand_model_id', $brand->brandModel?->id)->where('id', $targetVersionId)->where('status', 'draft')->first()
            : $this->getOrCreateDraftVersion($brand);

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

    /**
     * Create a NEW draft version (for "Run Builder Again").
     * Seeds from active version if exists. Does not reuse existing draft.
     */
    public function createNewDraftVersion(Brand $brand): BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create([
                'is_enabled' => false,
                'brand_dna_scoring_enabled' => true,
            ]);
        }

        return DB::transaction(function () use ($brandModel, $brand) {
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
                'created_by' => auth()->id(),
            ]);
        });
    }
}
