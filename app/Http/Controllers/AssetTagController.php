<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tenant;
use App\Services\PlanService;
use App\Services\TagNormalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asset Tag Controller
 *
 * Phase J.2.3: Tag UX Implementation
 * 
 * Handles CRUD operations for asset tags with normalization and source tracking.
 * Provides APIs for the unified tag input component and tag removal functionality.
 */
class AssetTagController extends Controller
{
    protected TagNormalizationService $normalizationService;
    protected PlanService $planService;

    public function __construct(TagNormalizationService $normalizationService, PlanService $planService)
    {
        $this->normalizationService = $normalizationService;
        $this->planService = $planService;
    }

    /**
     * Get all tags for an asset with source information.
     *
     * GET /api/assets/{asset}/tags
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function index(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission - if user can access the asset drawer, they can view tags
        // Note: This aligns with AssetTagManager permission logic

        $tags = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id ?? null,
                    'tag' => $tag->tag,
                    'source' => $tag->source, // manual, ai, ai:auto
                    'confidence' => $tag->confidence,
                    'created_at' => $tag->created_at,
                ];
            });

        return response()->json([
            'tags' => $tags,
            'total' => $tags->count(),
        ]);
    }

    /**
     * Add a new tag to an asset.
     *
     * POST /api/assets/{asset}/tags
     *
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function store(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'assets.tags.create')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Check plan tag limit before processing
        try {
            $this->planService->enforceTagLimit($asset);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return $e->render($request);
        }

        $validated = $request->validate([
            'tag' => 'required|string|min:2|max:64',
        ]);

        $rawTag = $validated['tag'];

        // Normalize tag to canonical form
        $canonicalTag = $this->normalizationService->normalize($rawTag, $tenant);

        if ($canonicalTag === null) {
            return response()->json([
                'message' => 'Tag is invalid or blocked',
                'original_tag' => $rawTag,
            ], 422);
        }

        // Check if canonical tag already exists
        $existingTag = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->where('tag', $canonicalTag)
            ->first();

        if ($existingTag) {
            return response()->json([
                'message' => 'Tag already exists',
                'canonical_tag' => $canonicalTag,
                'existing_tag' => [
                    'id' => $existingTag->id ?? null,
                    'tag' => $existingTag->tag,
                    'source' => $existingTag->source,
                    'created_at' => $existingTag->created_at,
                ],
            ], 409);
        }

        // Create the tag
        $tagId = DB::table('asset_tags')->insertGetId([
            'asset_id' => $asset->id,
            'tag' => $canonicalTag,
            'source' => 'manual', // Manual tag creation
            'confidence' => null,
            'created_at' => now(),
        ]);

        Log::info('[AssetTagController] Manual tag created', [
            'asset_id' => $asset->id,
            'user_id' => $user->id,
            'original_tag' => $rawTag,
            'canonical_tag' => $canonicalTag,
            'tag_id' => $tagId,
        ]);

        return response()->json([
            'message' => 'Tag created successfully',
            'tag' => [
                'id' => $tagId,
                'tag' => $canonicalTag,
                'source' => 'manual',
                'confidence' => null,
                'created_at' => now()->toISOString(),
            ],
            'canonical_tag' => $canonicalTag,
            'original_tag' => $rawTag,
        ], 201);
    }

    /**
     * Remove a tag from an asset.
     *
     * DELETE /api/assets/{asset}/tags/{tagId}
     *
     * @param Asset $asset
     * @param int $tagId
     * @return JsonResponse
     */
    public function destroy(Asset $asset, int $tagId): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'assets.tags.delete')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get the tag
        $tag = DB::table('asset_tags')
            ->where('id', $tagId)
            ->where('asset_id', $asset->id)
            ->first();

        if (!$tag) {
            return response()->json(['message' => 'Tag not found'], 404);
        }

        // Remove the tag
        $deleted = DB::table('asset_tags')
            ->where('id', $tagId)
            ->where('asset_id', $asset->id)
            ->delete();

        if ($deleted > 0) {
            Log::info('[AssetTagController] Tag removed', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'tag_id' => $tagId,
                'tag' => $tag->tag,
                'source' => $tag->source,
            ]);

            return response()->json([
                'message' => 'Tag removed successfully',
                'removed_tag' => [
                    'id' => $tag->id ?? null,
                    'tag' => $tag->tag,
                    'source' => $tag->source,
                ],
            ]);
        }

        return response()->json(['message' => 'Failed to remove tag'], 500);
    }

    /**
     * Get autocomplete suggestions for tag input (tenant-wide).
     *
     * GET /api/tenants/{tenant}/tags/autocomplete?q=search
     *
     * @param Request $request
     * @param Tenant $tenant
     * @return JsonResponse
     */
    public function tenantAutocomplete(Request $request, Tenant $tenant): JsonResponse
    {
        $currentTenant = app('tenant');
        $user = Auth::user();

        // Verify tenant access
        if ($tenant->id !== $currentTenant->id) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Check permission (permission name is asset.view, not assets.view)
        if (!$user->hasPermissionForTenant($tenant, 'asset.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $query = $request->input('q', '');
        $queryTrimmed = is_string($query) ? trim($query) : '';

        // Empty or short query: return list of used tags (for filter dropdown "show on focus")
        if (strlen($queryTrimmed) < 2) {
            $suggestions = DB::table('asset_tags')
                ->select('tag', DB::raw('COUNT(*) as usage_count'))
                ->whereIn('asset_id', function ($subQuery) use ($tenant) {
                    $subQuery->select('id')
                        ->from('assets')
                        ->where('tenant_id', $tenant->id);
                })
                ->groupBy('tag')
                ->orderByDesc('usage_count')
                ->orderBy('tag')
                ->limit(30)
                ->get()
                ->map(function ($suggestion) {
                    return [
                        'tag' => $suggestion->tag,
                        'usage_count' => (int) $suggestion->usage_count,
                        'type' => 'existing',
                    ];
                });

            return response()->json([
                'suggestions' => $suggestions,
                'query' => $queryTrimmed,
            ]);
        }

        // Get existing canonical tags matching search
        $suggestions = DB::table('asset_tags')
            ->select('tag', DB::raw('COUNT(*) as usage_count'))
            ->whereIn('asset_id', function ($subQuery) use ($tenant) {
                $subQuery->select('id')
                    ->from('assets')
                    ->where('tenant_id', $tenant->id);
            })
            ->where('tag', 'LIKE', '%' . $queryTrimmed . '%')
            ->groupBy('tag')
            ->orderByDesc('usage_count')
            ->orderBy('tag')
            ->limit(10)
            ->get()
            ->map(function ($suggestion) {
                return [
                    'tag' => $suggestion->tag,
                    'usage_count' => (int) $suggestion->usage_count,
                    'type' => 'existing',
                ];
            });

        // If no exact matches, suggest normalized version
        if ($suggestions->isEmpty()) {
            $normalizedSuggestion = $this->normalizationService->normalize($queryTrimmed, $tenant);
            if ($normalizedSuggestion !== null) {
                $suggestions->push([
                    'tag' => $normalizedSuggestion,
                    'usage_count' => 0,
                    'type' => 'normalized',
                ]);
            }
        }

        return response()->json([
            'suggestions' => $suggestions,
            'query' => $queryTrimmed,
        ]);
    }

    /**
     * Get autocomplete suggestions for tag input.
     *
     * GET /api/assets/{asset}/tags/autocomplete?q=search
     *
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function autocomplete(Request $request, Asset $asset): JsonResponse
    {
        try {
            $tenant = app('tenant');
            $user = Auth::user();

            // Verify asset belongs to tenant
            if ($asset->tenant_id !== $tenant->id) {
                return response()->json(['message' => 'Asset not found'], 404);
            }

            // Check permission - autocomplete should be available to users who can view tags
            // Note: This aligns with tag input functionality

            $query = $request->input('q', '');
            
            if (strlen($query) < 2) {
                return response()->json(['suggestions' => []]);
            }

            // Get existing canonical tags across all tenant assets
            $suggestions = DB::table('asset_tags')
                ->select('tag', DB::raw('COUNT(*) as usage_count'))
                ->whereIn('asset_id', function ($subQuery) use ($tenant) {
                    $subQuery->select('id')
                        ->from('assets')
                        ->where('tenant_id', $tenant->id);
                })
                ->where('tag', 'LIKE', '%' . $query . '%')
                ->groupBy('tag')
                ->orderByDesc('usage_count')
                ->orderBy('tag')
                ->limit(10)
                ->get()
                ->map(function ($suggestion) {
                    return [
                        'tag' => $suggestion->tag,
                        'usage_count' => $suggestion->usage_count,
                        'type' => 'existing', // Existing canonical tag
                    ];
                });

            // If no exact matches, suggest normalized version
            if ($suggestions->isEmpty()) {
                $normalizedSuggestion = $this->normalizationService->normalize($query, $tenant);
                if ($normalizedSuggestion !== null) {
                    $suggestions->push([
                        'tag' => $normalizedSuggestion,
                        'usage_count' => 0,
                        'type' => 'normalized', // New normalized suggestion
                    ]);
                }
            }

            return response()->json([
                'suggestions' => $suggestions,
                'query' => $query,
            ]);
        } catch (\Exception $e) {
            Log::error('[AssetTagController] Autocomplete error', [
                'asset_id' => $asset->id,
                'query' => $request->input('q', ''),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch suggestions',
                'suggestions' => [],
            ], 500);
        }
    }
}