<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapService;
use App\Services\BrandDNA\BrandModelService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Bootstrap — URL-based Brand DNA extraction (foundation only).
 * No scraping yet. No AI yet.
 */
class BrandBootstrapController extends Controller
{
    public function __construct(
        private BrandBootstrapService $bootstrapService,
        private BrandModelService $brandModelService
    ) {}

    /**
     * List bootstrap runs for a brand.
     */
    public function index(Brand $brand): Response
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $runs = $brand->bootstrapRuns()
            ->with('approvedVersion:id,version_number')
            ->orderByDesc('created_at')
            ->get(['id', 'status', 'source_url', 'created_at', 'approved_version_id', 'raw_payload'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'source_url' => $r->source_url,
                'created_at' => $r->created_at->toISOString(),
                'approved_version_id' => $r->approved_version_id,
                'approved_version' => $r->approvedVersion ? ['version_number' => $r->approvedVersion->version_number] : null,
                'raw_payload' => $r->raw_payload,
            ]);

        return Inertia::render('Brands/BrandDNA/Bootstrap/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'runs' => $runs,
        ]);
    }

    /**
     * Create a bootstrap run.
     */
    public function store(Request $request, Brand $brand)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        $this->bootstrapService->createRun($brand, $validated['url'], $request->user());

        return redirect()
            ->route('brands.dna.bootstrap.index', ['brand' => $brand->id])
            ->with('success', 'Bootstrap run created.');
    }

    /**
     * Show a bootstrap run.
     */
    public function show(Brand $brand, BrandBootstrapRun $run): Response
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($run->brand_id !== $brand->id) {
            abort(404);
        }
        $this->authorize('update', $brand);

        return Inertia::render('Brands/BrandDNA/Bootstrap/Show', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'stage' => $run->stage,
                'progress_percent' => (int) ($run->progress_percent ?? 0),
                'stage_log' => $run->stage_log ?? [],
                'current_stage_index' => (int) ($run->current_stage_index ?? 0),
                'source_url' => $run->source_url,
                'raw_payload' => $run->raw_payload,
                'ai_output_payload' => $run->ai_output_payload,
                'created_at' => $run->created_at->toISOString(),
                'updated_at' => $run->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Create draft BrandModelVersion from AI research. Does NOT activate or enable.
     * User must manually activate from Brand DNA page.
     */
    public function approve(Request $request, Brand $brand, BrandBootstrapRun $run)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($run->brand_id !== $brand->id) {
            abort(404);
        }
        if ($run->status !== 'inferred') {
            abort(422, 'Run must be inferred before approval.');
        }
        $this->authorize('update', $brand);

        $payload = $request->input('model_payload') ?? $run->ai_output_payload ?? [];
        if (empty($payload)) {
            abort(422, 'No AI output to approve.');
        }

        $version = $this->brandModelService->createInitialVersion($brand, $payload, 'ai');
        $run->update(['approved_version_id' => $version->id]);

        return redirect()
            ->route('brands.dna.index', ['brand' => $brand->id, 'editing' => $version->id])
            ->with('success', 'Draft created from AI research. Review and activate when ready.');
    }

    /**
     * Delete a bootstrap run. Fails if draft was already created (approved_version_id set).
     */
    public function destroy(Brand $brand, BrandBootstrapRun $run)
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($run->brand_id !== $brand->id) {
            abort(404);
        }
        $this->authorize('update', $brand);

        if ($run->approved_version_id) {
            return response()->json([
                'message' => 'Cannot delete: a draft was already created from this run. Delete the draft version first if needed.',
            ], 422);
        }

        $run->delete();

        return redirect()
            ->route('brands.dna.bootstrap.index', ['brand' => $brand->id])
            ->with('success', 'Bootstrap run deleted.');
    }
}
