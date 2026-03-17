<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandPipelineRun;
use Illuminate\Http\JsonResponse;

class BrandPipelineController extends Controller
{
    /**
     * GET /brands/{brand}/brand-dna/builder/brand-pipeline/{run}
     * Returns pipeline run progress: stage, pages_total, pages_processed, progress_percent.
     */
    public function show(Brand $brand, BrandPipelineRun $run): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($run->brand_id !== $brand->id) {
            abort(404);
        }

        return response()->json([
            'id' => $run->id,
            'stage' => $run->stage,
            'pages_total' => $run->pages_total,
            'pages_processed' => $run->pages_processed,
            'progress_percent' => $run->progress_percent,
            'status' => $run->status,
            'extraction_mode' => $run->extraction_mode,
        ]);
    }
}
