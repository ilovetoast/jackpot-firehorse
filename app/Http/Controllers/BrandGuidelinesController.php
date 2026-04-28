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
use App\Support\GuidelinesFocalPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
            return redirect()->route('assets.index')->with('warning', 'That brand is not available in this workspace.');
        }
        if (! Gate::allows('view', $brand)) {
            return redirect()->route('assets.index')->with('warning', 'You don\'t have access to Brand Guidelines for this brand.');
        }

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
        // View-only users stay on the read-only guidelines experience.
        if ($hasDraft && ! $activeVersion && Gate::allows('update', $brand)) {
            return redirect()->to($resumeUrl);
        }

        $logoAssets = $this->gatherLogoAssets($brand);
        $visualReferences = $this->gatherVisualReferences($brand, $modelPayload, $activeVersion);
        $guidelinesHeroLogoUrl = $this->resolveGuidelinesHeroLogoUrl($brand, $modelPayload);

        $logoDarkUrl = null;
        if ($activeVersion) {
            $darkAsset = $activeVersion->assetsForContext('logo_on_dark')->first();
            if ($darkAsset) {
                try {
                    $logoDarkUrl = $darkAsset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;
                } catch (\Throwable $e) {
                    Log::warning('[BrandGuidelines] logo_on_dark delivery URL failed', [
                        'brand_id' => $brand->id,
                        'asset_id' => $darkAsset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $logoDarkUrl = null;
                }
            }
        }
        if (! $logoDarkUrl) {
            $logoDarkUrl = $brand->logo_dark_path;
        }

        $logoOnLightUrl = null;
        // Prefer the brand column (now canonical for the light variant slot); fall back to the
        // DNA pivot for historical versions that only have the pivot row populated.
        if ($brand->logo_light_path) {
            $logoOnLightUrl = $brand->logo_light_path;
        }
        if (! $logoOnLightUrl && $activeVersion) {
            $lightAsset = $activeVersion->assetsForContext('logo_on_light')->first();
            if ($lightAsset) {
                try {
                    $logoOnLightUrl = $lightAsset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;
                } catch (\Throwable $e) {
                    Log::warning('[BrandGuidelines] logo_on_light delivery URL failed', [
                        'brand_id' => $brand->id,
                        'asset_id' => $lightAsset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $logoOnLightUrl = null;
                }
            }
        }

        $canUpdate = Gate::forUser(request()->user())->allows('update', $brand);

        return Inertia::render('Brands/BrandGuidelines/Index', [
            'can_edit_brand_dna' => $canUpdate,
            'canCustomize' => $canUpdate && app(\App\Services\FeatureGate::class)->guidelinesCustomization($tenant),
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'logo_url' => $brand->logo_path,
                'logo_dark_url' => $logoDarkUrl,
                'logo_on_light_url' => $logoOnLightUrl,
                'logo_horizontal_url' => $brand->logo_horizontal_path,
            ],
            'logoAssets' => $logoAssets,
            'visualReferences' => $visualReferences,
            'brandModel' => [
                'is_enabled' => $isEnabled,
            ],
            'modelPayload' => $modelPayload,
            'guidelinesHeroLogoUrl' => $guidelinesHeroLogoUrl,
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

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function withGuidelinesFocalPoint(Asset $asset, array $item): array
    {
        $fp = GuidelinesFocalPoint::fromAsset($asset);
        if ($fp !== null) {
            $item['focal_point'] = $fp;
        }

        return $item;
    }

    /**
     * Optional hero logo override (presentation_overrides) — guidelines only, does not change brand settings.
     */
    protected function resolveGuidelinesHeroLogoUrl(Brand $brand, array $modelPayload): ?string
    {
        $assetId = data_get($modelPayload, 'presentation_overrides.sections.sec-hero.content.hero_logo_asset_id');
        if (! $assetId || ! is_string($assetId)) {
            return null;
        }

        $heroAsset = Asset::where('brand_id', $brand->id)->where('tenant_id', $brand->tenant_id)->find($assetId);
        if (! $heroAsset) {
            return null;
        }

        try {
            return $heroAsset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED)
                ?: $heroAsset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                ?: $heroAsset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
        } catch (\Throwable) {
            return null;
        }
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
            foreach ($assets as $asset) {
                $item = $this->withGuidelinesFocalPoint($asset, [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'url' => $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'thumbnail_url' => $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                ]);
                $results[$categoryKey] = $this->appendUniqueVisualReferenceById($results[$categoryKey] ?? [], $item);
            }
        }

        // Merge builder / AI-attached references from the version pivot (visual_reference context).
        // Always merge — do not require reference_categories to be empty so DNA + builder paths both appear.
        if ($activeVersion) {
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
                $item = $this->withGuidelinesFocalPoint($asset, [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'url' => $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED)
                        ?: $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                    'thumbnail_url' => $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED),
                ]);
                $results[$catKey] = $this->appendUniqueVisualReferenceById($results[$catKey] ?? [], $item);
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array{id?: int, title?: string, url?: string, thumbnail_url?: string}>  $items
     * @param  array{id?: int, title?: string, url?: string, thumbnail_url?: string}  $item
     * @return array<int, array{id?: int, title?: string, url?: string, thumbnail_url?: string}>
     */
    private function appendUniqueVisualReferenceById(array $items, array $item): array
    {
        $id = $item['id'] ?? null;
        if ($id !== null) {
            foreach ($items as $existing) {
                if (($existing['id'] ?? null) === $id) {
                    return $items;
                }
            }
        }
        $items[] = $item;

        return $items;
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
