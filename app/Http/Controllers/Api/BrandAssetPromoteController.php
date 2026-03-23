<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\BrandReference\PromoteBrandReferenceAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BrandAssetPromoteController extends Controller
{
    public function store(
        Request $request,
        string $asset,
        PromoteBrandReferenceAssetService $promoteService
    ): JsonResponse {
        $assetModel = Asset::query()->with(['brand', 'tenant'])->findOrFail($asset);

        Gate::authorize('promoteToReference', $assetModel);

        $validated = $request->validate([
            'type' => 'required|string|in:reference,guideline',
            'category' => 'nullable|string|max:255',
            'context_type' => 'nullable|string|max:32',
        ]);

        $bra = $promoteService->promote(
            $assetModel,
            $request->user(),
            $validated['type'],
            $validated['category'] ?? null,
            $validated['context_type'] ?? null
        );

        return response()->json([
            'ok' => true,
            'reference' => [
                'id' => $bra->id,
                'brand_id' => $bra->brand_id,
                'asset_id' => $bra->asset_id,
                'reference_type' => $bra->reference_type,
                'tier' => $bra->tier,
                'weight' => $bra->weight,
                'category' => $bra->category,
                'context_type' => $bra->context_type,
                'reference_promotion' => $bra->toFrontendArray(),
            ],
        ], 201);
    }
}
