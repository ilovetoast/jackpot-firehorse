<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandIngestionRecord;
use App\Models\BrandPdfVisionExtraction;
use App\Models\BrandResearchSnapshot;
use App\Services\BrandDNA\BuilderResumeStepService;
use App\Services\BrandDNA\ResearchFinalizationService;
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
    public function index(Brand $brand): Response
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        $activeVersion = $brandModel?->activeVersion;
        $modelPayload = $activeVersion?->model_payload ?? [];
        $isEnabled = $brandModel?->is_enabled ?? false;
        $draft = $brandModel?->versions()->where('status', 'draft')->first();
        $hasDraft = $draft !== null;

        $builderProcessing = false;
        $researchFinalized = false;
        if ($draft) {
            $finalizationService = app(ResearchFinalizationService::class);
            $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
            $sources = $draft->model_payload['sources'] ?? [];
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
                BrandIngestionRecord::where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->where('status', BrandIngestionRecord::STATUS_PROCESSING)->exists()
                || BrandResearchSnapshot::where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->whereIn('status', ['pending', 'running'])->exists()
                || ($guidelinesPdfAsset && BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)->where('brand_id', $brand->id)->where('brand_model_version_id', $draft->id)->whereNotIn('status', [BrandPdfVisionExtraction::STATUS_COMPLETED, BrandPdfVisionExtraction::STATUS_FAILED])->exists())
            );
        }

        $resumeStep = 'background';
        $resumeLabel = 'Continue Brand Guidelines';
        $resumeUrl = route('brands.brand-guidelines.builder', ['brand' => $brand->id]);
        if ($draft) {
            $resumeService = app(BuilderResumeStepService::class);
            $resolved = $resumeService->resolve($brand, $draft);
            $resumeStep = $resolved['step'];
            $resumeLabel = $resolved['label'];
            $resumeUrl = route('brands.brand-guidelines.builder', ['brand' => $brand->id, 'step' => $resumeStep]);
            if ($activeVersion && $hasDraft) {
                $resumeLabel = 'Continue Editing Draft';
            }
        }

        return Inertia::render('Brands/BrandGuidelines/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
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
}
