<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Brand;
use App\Services\MetadataPersistenceService;
use App\Services\TagNormalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brand-scoped tag listing and purge (remove a canonical tag from every asset in the brand).
 */
class BrandTagManagementController extends Controller
{
    public function summary(Request $request, Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ((int) $brand->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Brand not found'], 404);
        }
        $this->authorize('view', $brand);

        $rows = DB::table('asset_tags')
            ->join('assets', 'asset_tags.asset_id', '=', 'assets.id')
            ->where('assets.brand_id', $brand->id)
            ->select('asset_tags.tag', DB::raw('COUNT(*) as asset_count'))
            ->groupBy('asset_tags.tag')
            ->orderByDesc('asset_count')
            ->orderBy('asset_tags.tag')
            ->limit(500)
            ->get();

        return response()->json([
            'tags' => $rows->map(fn ($r) => [
                'tag' => $r->tag,
                'asset_count' => (int) $r->asset_count,
            ]),
        ]);
    }

    public function purge(Request $request, Brand $brand, TagNormalizationService $normalizationService, MetadataPersistenceService $persistenceService): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ((int) $brand->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Brand not found'], 404);
        }
        $this->authorize('update', $brand);

        if (! $user->hasPermissionForTenant($tenant, 'assets.tags.delete')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'tag' => 'required|string|min:2|max:64',
        ]);

        $canonical = $normalizationService->normalize($validated['tag'], $tenant);
        if ($canonical === null) {
            return response()->json(['message' => 'Tag is invalid or blocked'], 422);
        }

        $fieldId = (int) DB::table('metadata_fields')
            ->where('key', 'tags')
            ->where('scope', 'system')
            ->value('id');

        if ($fieldId <= 0) {
            return response()->json(['message' => 'Tags metadata field not configured'], 500);
        }

        $assetsTouched = 0;

        Asset::query()
            ->where('brand_id', $brand->id)
            ->orderBy('id')
            ->chunk(150, function ($assets) use ($tenant, $canonical, $fieldId, $persistenceService, &$assetsTouched): void {
                foreach ($assets as $asset) {
                    $had = DB::table('asset_tags')
                        ->where('asset_id', $asset->id)
                        ->where('tag', $canonical)
                        ->exists();
                    $persistenceService->removeCanonicalTagsFromAsset($asset, $tenant, [$canonical], $fieldId);
                    if ($had) {
                        $assetsTouched++;
                    }
                }
            });

        return response()->json([
            'message' => 'Tag removed from brand assets',
            'canonical_tag' => $canonical,
            'assets_affected' => $assetsTouched,
        ]);
    }
}
