<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use App\Models\Category;
use App\Services\BrandDNA\BuilderResumeStepService;
use App\Services\BrandDNA\PipelineFinalizationService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Guidelines — read-only render of active Brand DNA.
 * Internal only. No public sharing, PDF export, or WYSIWYG.
 */
class BrandGuidelinesController extends Controller
{
    /**
     * Redirect /brand-guidelines to active brand's guidelines.
     */
    public function redirectToActive(): RedirectResponse
    {
        $brand = app('brand');
        if (! $brand) {
            return redirect()->route('app');
        }

        return redirect()->route('brands.guidelines.index', ['brand' => $brand->id]);
    }

    /**
     * Show Brand Guidelines page (read-only).
     */
    public function index(Brand $brand): Response|RedirectResponse
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        $activeVersion = $brandModel?->activeVersion;
        $modelPayload = self::deepUnwrap($activeVersion?->model_payload ?? []);
        $isEnabled = $brandModel?->is_enabled ?? false;
        $draft = $brandModel?->versions()->where('status', 'draft')->first();
        $hasDraft = $draft !== null;

        $builderProcessing = false;
        $researchFinalized = false;
        if ($draft) {
            $finalizationService = app(PipelineFinalizationService::class);
            $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
            $sources = self::deepUnwrap($draft->model_payload['sources'] ?? []);
            $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
            $hasSocialUrls = ! empty($sources['social_urls'] ?? []);
            $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
            $finalization = $finalizationService->compute(
                $brand->id,
                $draft->id,
                $guidelinesPdfAsset,
                $hasWebsiteUrl,
                $hasSocialUrls,
                $brandMaterialCount
            );
            $researchFinalized = $finalization['research_finalized'] ?? false;
            $builderProcessing = ! $researchFinalized && (
                BrandPipelineRun::where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->where('status', BrandPipelineRun::STATUS_PROCESSING)->exists()
                || BrandPipelineSnapshot::where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->whereIn('status', ['pending', 'running'])->exists()
                || ($guidelinesPdfAsset && BrandPipelineRun::where('asset_id', $guidelinesPdfAsset->id)->where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->whereNotIn('status', [BrandPipelineRun::STATUS_COMPLETED, BrandPipelineRun::STATUS_FAILED])->exists())
            );
        }

        $resumeStep = 'research';
        $resumeLabel = 'Continue Brand Guidelines';
        $resumeUrl = route('brands.research.show', ['brand' => $brand->id]);
        if ($draft) {
            $resumeService = app(BuilderResumeStepService::class);
            $resolved = $resumeService->resolve($brand, $draft);
            $resumeStep = $resolved['step'];
            $resumeLabel = $resolved['label'];
            $resumeUrl = route($resolved['route'], $resolved['route_params']);
            if ($activeVersion && $hasDraft) {
                $resumeLabel = 'Continue Editing Draft';
            }
        }

        // Auto-redirect to builder when a draft exists but no published version yet
        // (skip the callout/landing page — go straight to the builder at the resume step)
        if ($hasDraft && ! $activeVersion) {
            return redirect()->to($resumeUrl);
        }

        $logoAssets = $this->gatherLogoAssets($brand);
        $visualReferences = $this->gatherVisualReferences($brand, $modelPayload, $activeVersion);

        return Inertia::render('Brands/BrandGuidelines/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'logo_url' => $brand->logo_path,
            ],
            'logoAssets' => $logoAssets,
            'visualReferences' => $visualReferences,
            'brandModel' => [
                'is_enabled' => $isEnabled,
            ],
            'modelPayload' => $modelPayload,
            'hasActiveVersion' => $activeVersion !== null,
            'hasDraft' => $hasDraft,
            'builderProcessing' => $builderProcessing,
            'researchFinalized' => $researchFinalized,
            'resumeStep' => $resumeStep,
            'resumeLabel' => $resumeLabel,
            'resumeUrl' => $resumeUrl,
        ]);
    }

    protected function gatherLogoAssets(Brand $brand): array
    {
        $results = [];
        $seen = [];

        // Primary logo from brand settings
        if ($brand->logo_id) {
            $logo = Asset::find($brand->logo_id);
            if ($logo) {
                $seen[$logo->id] = true;
                $results[] = [
                    'id' => $logo->id,
                    'title' => $logo->title,
                    'role' => 'primary',
                    'url' => $logo->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $logo->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'mime_type' => $logo->mime_type,
                ];
            }
        }

        // Additional logos from the "logos" category
        $logoCategory = Category::where('brand_id', $brand->id)->where('slug', 'logos')->first();
        if ($logoCategory) {
            $categoryAssets = Asset::where('tenant_id', $brand->tenant_id)
                ->whereNotNull('published_at')
                ->get()
                ->filter(fn (Asset $a) => ($a->metadata['category_id'] ?? null) == $logoCategory->id && ! isset($seen[$a->id]));

            foreach ($categoryAssets as $asset) {
                $results[] = [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'role' => 'secondary',
                    'url' => $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'mime_type' => $asset->mime_type,
                ];
            }
        }

        return $results;
    }

    protected function gatherVisualReferences(Brand $brand, array $modelPayload, $activeVersion): array
    {
        $results = [];
        $referenceCategories = $modelPayload['visual']['reference_categories'] ?? [];

        foreach ($referenceCategories as $categoryKey => $catData) {
            $assetIds = $catData['asset_ids'] ?? [];
            if (empty($assetIds)) {
                continue;
            }

            $assets = Asset::whereIn('id', $assetIds)->get();
            $items = [];
            foreach ($assets as $asset) {
                $items[] = [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'url' => $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'thumbnail_url' => $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                ];
            }
            if (! empty($items)) {
                $results[$categoryKey] = $items;
            }
        }

        // Fallback: also gather from brand_model_version_assets pivot for legacy references
        if ($activeVersion && empty($results)) {
            $pivotRefs = $activeVersion->assetsForContext('visual_reference')->get();

            $legacyMap = [
                'lifestyle_photography' => 'photography',
                'product_photography' => 'photography',
                'photography_reference' => 'photography',
                'graphics_layout' => 'graphics',
            ];

            foreach ($pivotRefs as $asset) {
                $refType = $asset->pivot->reference_type ?? 'photography_reference';
                $catKey = $legacyMap[$refType] ?? 'photography';
                $results[$catKey][] = [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'url' => $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'thumbnail_url' => $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                ];
            }
        }

        return $results;
    }

    protected static function deepUnwrap(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['value'], $value['source'])) {
                $inner = $value['value'];
                $result[$key] = is_array($inner) ? self::deepUnwrap($inner) : $inner;
            } elseif (is_array($value)) {
                $result[$key] = self::deepUnwrap($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
