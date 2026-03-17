<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\BrandComplianceScore;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\Category;
use App\Services\BrandDNA\BrandModelService;
use App\Services\BrandDNA\BrandVisualReferenceSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand DNA Settings — internal editor for versioned Brand DNA model.
 * Not public guidelines, scraper, or scoring.
 */
class BrandDNAController extends Controller
{
    public function __construct(
        private BrandModelService $brandModelService
    ) {}

    /**
     * Show Brand DNA settings page.
     */
    public function index(Request $request, Brand $brand): Response
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
        }

        $activeVersion = $brandModel->activeVersion;
        $allVersions = $brandModel->versions()
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'status', 'source_type', 'created_at'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status,
                'source_type' => $v->source_type,
                'created_at' => $v->created_at->toISOString(),
            ]);

        $editingVersionId = $request->query('editing');
        $editingVersion = null;
        if ($editingVersionId) {
            $v = BrandModelVersion::find($editingVersionId);
            if ($v && $v->brand_model_id === $brandModel->id) {
                $editingVersion = [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'status' => $v->status,
                    'model_payload' => self::deepUnwrap($v->model_payload ?? []),
                ];
            }
        }

        $complianceAggregate = $brand->complianceAggregate;
        $deliverableCategoryIds = Category::where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE)
            ->pluck('id')
            ->toArray();

        $topExecutions = [];
        $bottomExecutions = [];
        if (! empty($deliverableCategoryIds)) {
            $topScores = BrandComplianceScore::where('brand_id', $brand->id)
                ->where('evaluation_status', 'evaluated')
                ->whereNotNull('overall_score')
                ->with('asset:id,title,metadata')
                ->orderByDesc('overall_score')
                ->limit(3)
                ->get();
            $bottomScores = BrandComplianceScore::where('brand_id', $brand->id)
                ->where('evaluation_status', 'evaluated')
                ->whereNotNull('overall_score')
                ->with('asset:id,title,metadata')
                ->orderBy('overall_score')
                ->limit(3)
                ->get();

            foreach ($topScores as $s) {
                $catId = $s->asset?->metadata['category_id'] ?? null;
                if ($catId && in_array((int) $catId, $deliverableCategoryIds, true)) {
                    $topExecutions[] = ['id' => $s->asset_id, 'title' => $s->asset?->title ?? '—', 'score' => $s->overall_score];
                }
            }
            foreach ($bottomScores as $s) {
                $catId = $s->asset?->metadata['category_id'] ?? null;
                if ($catId && in_array((int) $catId, $deliverableCategoryIds, true)) {
                    $bottomExecutions[] = ['id' => $s->asset_id, 'title' => $s->asset?->title ?? '—', 'score' => $s->overall_score];
                }
            }
        }

        return Inertia::render('Brands/BrandDNA/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'brandModel' => [
                'id' => $brandModel->id,
                'is_enabled' => $brandModel->is_enabled,
            ],
            'activeVersion' => $activeVersion ? [
                'id' => $activeVersion->id,
                'version_number' => $activeVersion->version_number,
                'status' => $activeVersion->status,
                'model_payload' => self::deepUnwrap($activeVersion->model_payload ?? []),
            ] : null,
            'editingVersion' => $editingVersion,
            'allVersions' => $allVersions,
            'complianceAggregate' => $complianceAggregate ? [
                'avg_score' => $complianceAggregate->execution_count > 0 ? (float) $complianceAggregate->avg_score : null,
                'execution_count' => (int) $complianceAggregate->execution_count,
                'high_score_count' => (int) $complianceAggregate->high_score_count,
                'low_score_count' => (int) $complianceAggregate->low_score_count,
                'last_scored_at' => $complianceAggregate->last_scored_at?->toISOString(),
            ] : null,
            'topExecutions' => $topExecutions,
            'bottomExecutions' => $bottomExecutions,
            'visualReferences' => $this->getVisualReferencesForBrand($brand, $activeVersion),
        ]);
    }

    /**
     * Store or update Brand DNA.
     * - model_payload: create initial version (if none) + activate, or update active version
     * - is_enabled: toggle Brand DNA on/off
     */
    public function store(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
        }

        if ($request->has('is_enabled')) {
            $brandModel->update(['is_enabled' => (bool) $request->is_enabled]);
        }

        if ($request->has('model_payload')) {
            $validated = $request->validate([
                'model_payload' => 'required|array',
                'version_id' => 'nullable|exists:brand_model_versions,id',
            ]);
            $payload = $validated['model_payload'];
            $versionId = $validated['version_id'] ?? null;

            $activeVersion = $brandModel->activeVersion;

            if ($versionId) {
                $version = BrandModelVersion::find($versionId);
                if ($version && $version->brandModel->brand_id === $brand->id) {
                    $this->brandModelService->updateVersionPayload($brand, $version, $payload);
                }
            } elseif (! $activeVersion) {
                $version = $this->brandModelService->createInitialVersion($brand, $payload, 'manual');
                $this->brandModelService->activateVersion($version);
            } else {
                $this->brandModelService->updateActiveVersionPayload($brand, $payload);
            }
        }

        $returnTo = $request->input('return_to');
        if ($returnTo === 'edit') {
            return redirect()->route('brands.edit', ['brand' => $brand->id])
                ->with('success', 'Brand DNA saved.');
        }

        return redirect()->route('brands.dna.index', ['brand' => $brand->id])
            ->with('success', 'Brand DNA saved.');
    }

    /**
     * Get a specific version's payload (for version switcher).
     */
    public function showVersion(Brand $brand, BrandModelVersion $version)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        if ($version->brandModel->brand_id !== $brand->id) {
            abort(404);
        }

        return response()->json([
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'model_payload' => self::deepUnwrap($version->model_payload ?? []),
            ],
        ]);
    }

    /**
     * Create a new draft version from the current active version.
     */
    public function createVersion(Brand $brand)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $version = $this->brandModelService->createNewVersionFromExisting($brand);

        return redirect()->route('brands.dna.index', ['brand' => $brand->id, 'editing' => $version->id])
            ->with('success', "Draft version {$version->version_number} created.");
    }

    /**
     * Activate a version (must be draft).
     */
    public function activateVersion(Brand $brand, BrandModelVersion $version)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        if ($version->brandModel->brand_id !== $brand->id) {
            abort(403, 'Version does not belong to this brand.');
        }

        $this->brandModelService->activateVersion($version);

        return redirect()->route('brands.dna.index', ['brand' => $brand->id])
            ->with('success', "Version {$version->version_number} activated.");
    }

    /**
     * Store visual references (logo + lifestyle, product, graphics).
     * Triggers embedding generation when assets are selected.
     */
    public function storeVisualReferences(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        if (! $tenant || $brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
        }

        $logoInput = $request->input('logo_asset_id');
        $photoInput = $request->input('photography_asset_ids', []);
        $lifestyleInput = $request->input('lifestyle_photography_ids', []);
        $productInput = $request->input('product_photography_ids', []);
        $graphicsInput = $request->input('graphics_layout_ids', []);

        $photoInput = is_array($photoInput) ? array_values(array_filter($photoInput)) : [];
        $lifestyleInput = is_array($lifestyleInput) ? array_values(array_filter($lifestyleInput)) : [];
        $productInput = is_array($productInput) ? array_values(array_filter($productInput)) : [];
        $graphicsInput = is_array($graphicsInput) ? array_values(array_filter($graphicsInput)) : [];

        $request->merge([
            'logo_asset_id' => $logoInput && $logoInput !== '' ? $logoInput : null,
            'photography_asset_ids' => $photoInput,
            'lifestyle_photography_ids' => $lifestyleInput,
            'product_photography_ids' => $productInput,
            'graphics_layout_ids' => $graphicsInput,
        ]);

        $validated = $request->validate([
            'logo_asset_id' => 'nullable|exists:assets,id',
            'photography_asset_ids' => 'nullable|array',
            'photography_asset_ids.*' => 'exists:assets,id',
            'lifestyle_photography_ids' => 'nullable|array',
            'lifestyle_photography_ids.*' => 'exists:assets,id',
            'product_photography_ids' => 'nullable|array',
            'product_photography_ids.*' => 'exists:assets,id',
            'graphics_layout_ids' => 'nullable|array',
            'graphics_layout_ids.*' => 'exists:assets,id',
        ]);

        $logoAssetId = $validated['logo_asset_id'] ?? null;
        $logoAssetId = $logoAssetId && $logoAssetId !== '' ? $logoAssetId : null;
        $photoIds = array_slice($validated['photography_asset_ids'] ?? [], 0, 3);
        $lifestyleIds = array_slice($validated['lifestyle_photography_ids'] ?? [], 0, 6);
        $productIds = array_slice($validated['product_photography_ids'] ?? [], 0, 6);
        $graphicsIds = array_slice($validated['graphics_layout_ids'] ?? [], 0, 4);

        $allAssetIds = array_filter(array_merge(
            $logoAssetId ? [$logoAssetId] : [],
            $photoIds,
            $lifestyleIds,
            $productIds,
            $graphicsIds
        ));
        foreach ($allAssetIds as $aid) {
            $asset = \App\Models\Asset::find($aid);
            if ($asset && $asset->brand_id !== $brand->id) {
                abort(422, 'Selected assets must belong to this brand.');
            }
        }

        $version = $brandModel->activeVersion;
        if (! $version) {
            $version = $this->brandModelService->createInitialVersion($brand, [], 'manual');
            $this->brandModelService->activateVersion($version);
        }

        BrandModelVersionAsset::where('brand_model_version_id', $version->id)
            ->where('builder_context', 'visual_reference')
            ->delete();

        $toInsert = [];
        if ($logoAssetId) {
            $toInsert[] = ['asset_id' => $logoAssetId, 'reference_type' => \App\Models\BrandVisualReference::TYPE_LOGO];
        }
        foreach ($photoIds as $assetId) {
            $toInsert[] = ['asset_id' => $assetId, 'reference_type' => \App\Models\BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE];
        }
        foreach ($lifestyleIds as $assetId) {
            $toInsert[] = ['asset_id' => $assetId, 'reference_type' => \App\Models\BrandVisualReference::TYPE_LIFESTYLE_PHOTOGRAPHY];
        }
        foreach ($productIds as $assetId) {
            $toInsert[] = ['asset_id' => $assetId, 'reference_type' => \App\Models\BrandVisualReference::TYPE_PRODUCT_PHOTOGRAPHY];
        }
        foreach ($graphicsIds as $assetId) {
            $toInsert[] = ['asset_id' => $assetId, 'reference_type' => \App\Models\BrandVisualReference::TYPE_GRAPHICS_LAYOUT];
        }

        foreach ($toInsert as $row) {
            BrandModelVersionAsset::create([
                'brand_model_version_id' => $version->id,
                'asset_id' => $row['asset_id'],
                'builder_context' => 'visual_reference',
                'reference_type' => $row['reference_type'],
            ]);
        }

        app(BrandVisualReferenceSyncService::class)->syncFromVersion($version);

        return redirect()->route('brands.dna.index', ['brand' => $brand->id])
            ->with('success', 'Visual references saved.');
    }

    protected function getVisualReferencesForBrand(Brand $brand, ?BrandModelVersion $activeVersion): array
    {
        if (! $activeVersion) {
            return [];
        }
        $pivots = BrandModelVersionAsset::where('brand_model_version_id', $activeVersion->id)
            ->where('builder_context', 'visual_reference')
            ->with('asset:id,title,metadata')
            ->get();

        return $pivots->map(fn ($p) => [
            'id' => $p->id,
            'asset_id' => $p->asset_id,
            'type' => $p->reference_type ?? \App\Models\BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
            'asset' => $p->asset ? [
                'id' => $p->asset->id,
                'title' => $p->asset->title,
                'thumbnail_url' => $p->asset->deliveryUrl(\App\Support\AssetVariant::THUMB_MEDIUM, \App\Support\DeliveryContext::AUTHENTICATED) ?: null,
            ] : null,
        ])->values()->all();
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
