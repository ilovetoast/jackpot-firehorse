<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
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

    /**
     * GET /brands/{brand}/brand-dna/builder/brand-pipeline/{run}/detail
     * Returns the full extracted data for inspection.
     */
    public function detail(Brand $brand, BrandPipelineRun $run): JsonResponse
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
            'status' => $run->status,
            'stage' => $run->stage,
            'extraction_mode' => $run->extraction_mode,
            'pages_total' => $run->pages_total,
            'pages_processed' => $run->pages_processed,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'merged_extraction' => $run->merged_extraction_json,
            'raw_api_response' => $run->raw_api_response_json,
        ]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/brand-pipeline-snapshot/{snapshot}/detail
     * Returns the full snapshot data for inspection.
     */
    public function snapshotDetail(Brand $brand, BrandPipelineSnapshot $snapshot): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($snapshot->brand_id !== $brand->id) {
            abort(404);
        }

        return response()->json([
            'id' => $snapshot->id,
            'status' => $snapshot->status,
            'source_url' => $snapshot->source_url,
            'created_at' => $snapshot->created_at?->toISOString(),
            'snapshot' => $snapshot->snapshot,
            'suggestions' => $snapshot->suggestions,
            'coherence' => $snapshot->coherence,
            'alignment' => $snapshot->alignment,
        ]);
    }
}
