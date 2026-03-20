<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Execution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExecutionController extends Controller
{
    /**
     * Create a draft execution (minimal; executions reserved for future multi-asset deliverables).
     */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('brand_id', $brand->id)],
            'primary_asset_id' => [
                'nullable',
                'uuid',
                Rule::exists('assets', 'id')->where('brand_id', $brand->id),
            ],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => [
                'uuid',
                Rule::exists('assets', 'id')->where('brand_id', $brand->id),
            ],
        ]);

        $execution = Execution::create([
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'status' => 'draft',
            'primary_asset_id' => $validated['primary_asset_id'] ?? null,
        ]);

        if (! empty($validated['asset_ids'])) {
            $sync = [];
            foreach ($validated['asset_ids'] as $index => $assetId) {
                $sync[$assetId] = ['sort_order' => $index, 'role' => null];
            }
            $execution->assets()->sync($sync);
        }

        return response()->json([
            'execution' => $execution->load(['assets', 'primaryAsset', 'category']),
        ], 201);
    }

    /**
     * Mark finalized. EBI scoring is asset-based ({@see \App\Jobs\ScoreAssetBrandIntelligenceJob}); execution scoring disabled until multi-asset flows return.
     */
    public function finalize(Brand $brand, Execution $execution): JsonResponse
    {
        $this->authorize('update', $brand);

        if ($execution->brand_id !== $brand->id) {
            abort(404);
        }

        $execution->update([
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        // ScoreExecutionBrandIntelligenceJob::dispatchSync($execution->fresh());

        return response()->json([
            'execution' => $execution->fresh()->load(['assets', 'primaryAsset', 'category', 'latestScore']),
        ]);
    }

    /**
     * Manual trigger placeholder — use asset-level scoring (e.g. re-run pipeline / ScoreAssetBrandIntelligenceJob) for EBI.
     */
    public function scoreNow(Brand $brand, Execution $execution): JsonResponse
    {
        $this->authorize('update', $brand);

        if ($execution->brand_id !== $brand->id) {
            abort(404);
        }

        // ScoreExecutionBrandIntelligenceJob::dispatchSync($execution->fresh());

        return response()->json([
            'execution' => $execution->fresh()->load(['assets', 'primaryAsset', 'category', 'latestScore']),
        ]);
    }
}
