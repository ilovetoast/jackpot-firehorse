<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4A: Read-only versions endpoint for asset version history.
 * Phase 5B: Restore endpoint (admin only, Pro/Enterprise).
 */
class AssetVersionController extends Controller
{
    /**
     * List versions for an asset.
     *
     * GET /assets/{asset}/versions
     * Plan-gated: requires plan_allows_versions (Pro/Enterprise).
     */
    public function index(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        if (!$asset->tenant->plan_allows_versions) {
            abort(403);
        }

        $query = $asset->versions()
            ->with('uploadedBy:id,first_name,last_name')
            ->orderByDesc('version_number');

        $mapVersion = fn ($v) => [
            'id' => $v->id,
            'version_number' => $v->version_number,
            'is_current' => $v->is_current,
            'file_size' => $v->file_size,
            'mime_type' => $v->mime_type,
            'uploaded_by' => $v->uploadedBy ? [
                'id' => $v->uploadedBy->id,
                'name' => $v->uploadedBy->name,
            ] : null,
            'created_at' => $v->created_at?->toIso8601String(),
            'pipeline_status' => $v->pipeline_status,
        ];

        $total = $query->count();
        if ($total > 50) {
            $paginator = $query->paginate(50);
            return response()->json($paginator->through($mapVersion));
        }

        return response()->json($query->get()->map($mapVersion)->values()->all());
    }

    /**
     * Restore a version as a new current version.
     *
     * POST /assets/{asset}/versions/{version}/restore
     * Admin only, Pro/Enterprise only.
     * {version} can be UUID (id) or version_number (1, 2, 3...) for backwards compatibility.
     */
    public function restore(Request $request, Asset $asset, string $versionParam): JsonResponse
    {
        Gate::authorize('restoreVersion', $asset);

        if (!$asset->tenant->plan_allows_versions) {
            abort(403);
        }

        // Resolve version: UUID or version_number
        $version = \Illuminate\Support\Str::isUuid($versionParam)
            ? AssetVersion::where('asset_id', $asset->id)->where('id', $versionParam)->first()
            : AssetVersion::where('asset_id', $asset->id)->where('version_number', (int) $versionParam)->first();

        if (!$version) {
            abort(404, 'Version not found');
        }

        $preserveMetadata = $request->boolean('preserve_metadata', true);
        $rerunPipeline = $request->boolean('rerun_pipeline', false);

        $service = app(\App\Services\AssetVersionRestoreService::class);

        $newVersion = $service->restore(
            $asset,
            $version,
            $preserveMetadata,
            $rerunPipeline,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'new_version_id' => $newVersion->id,
        ]);
    }
}
