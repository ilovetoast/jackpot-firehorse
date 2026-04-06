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
    private const SUMMARY_PER_PAGE_DEFAULT = 25;

    private const SUMMARY_PER_PAGE_MAX = 100;

    /** Max tags per bulk-purge request (UI + server; avoids huge jobs). */
    private const PURGE_BULK_TAG_CAP = 25;

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

        $perPage = min(max((int) $request->query('per_page', self::SUMMARY_PER_PAGE_DEFAULT), 1), self::SUMMARY_PER_PAGE_MAX);
        $page = max((int) $request->query('page', 1), 1);

        $base = DB::table('asset_tags')
            ->join('assets', 'asset_tags.asset_id', '=', 'assets.id')
            ->where('assets.brand_id', $brand->id);

        $totalDistinct = (int) (clone $base)
            ->selectRaw('COUNT(DISTINCT asset_tags.tag) as aggregate')
            ->value('aggregate');

        $rows = (clone $base)
            ->select('asset_tags.tag', DB::raw('COUNT(*) as asset_count'))
            ->groupBy('asset_tags.tag')
            ->orderByDesc('asset_count')
            ->orderBy('asset_tags.tag')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $lastPage = max(1, (int) ceil($totalDistinct / $perPage));

        return response()->json([
            'tags' => $rows->map(fn ($r) => [
                'tag' => $r->tag,
                'asset_count' => (int) $r->asset_count,
            ]),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalDistinct,
                'last_page' => $lastPage,
            ],
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
                    $persistenceService->removeCanonicalTagsFromAsset($asset, $tenant, [$canonical], $fieldId, false);
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

    /**
     * Remove up to {@see PURGE_BULK_TAG_CAP} canonical tags from every asset in the brand (one pass per asset).
     * Does not schedule per-asset debounced EBI rescoring (same as single purge from settings).
     */
    public function purgeBulk(
        Request $request,
        Brand $brand,
        TagNormalizationService $normalizationService,
        MetadataPersistenceService $persistenceService
    ): JsonResponse {
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
            'tags' => 'required|array|min:1|max:'.self::PURGE_BULK_TAG_CAP,
            'tags.*' => 'required|string|min:2|max:64',
        ]);

        $canonicals = [];
        foreach ($validated['tags'] as $raw) {
            $c = $normalizationService->normalize($raw, $tenant);
            if ($c !== null) {
                $canonicals[] = $c;
            }
        }
        $canonicals = array_values(array_unique($canonicals));
        if ($canonicals === []) {
            return response()->json(['message' => 'No valid tags to remove'], 422);
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
            ->chunk(150, function ($assets) use ($tenant, $canonicals, $fieldId, $persistenceService, &$assetsTouched): void {
                foreach ($assets as $asset) {
                    $had = DB::table('asset_tags')
                        ->where('asset_id', $asset->id)
                        ->whereIn('tag', $canonicals)
                        ->exists();
                    $persistenceService->removeCanonicalTagsFromAsset($asset, $tenant, $canonicals, $fieldId, false);
                    if ($had) {
                        $assetsTouched++;
                    }
                }
            });

        return response()->json([
            'message' => 'Tags removed from brand assets',
            'canonical_tags' => $canonicals,
            'assets_affected' => $assetsTouched,
        ]);
    }
}
