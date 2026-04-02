<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * List and stream {@link AssetVersion} rows for editor layers (same-origin URLs for canvas & thumbnails).
 */
class EditorAssetVersionController extends Controller
{
    /**
     * GET /app/api/assets/{asset}/versions
     */
    public function index(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            abort(403);
        }

        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            abort(404);
        }

        Gate::authorize('view', $asset);

        $versions = $asset->versions()
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (AssetVersion $version) use ($asset) {
                return [
                    'id' => $version->id,
                    'url' => route('api.editor.assets.versions.file', [
                        'asset' => $asset->id,
                        'assetVersion' => $version->id,
                    ], absolute: true),
                    'created_at' => $version->created_at?->toIso8601String(),
                    'version_number' => (int) $version->version_number,
                    'is_current' => (bool) $version->is_current,
                ];
            });

        return response()->json([
            'versions' => $versions,
        ]);
    }

    /**
     * GET /app/api/assets/{asset}/versions/{version}/file
     *
     * Stream one version’s bytes (authenticated, brand-scoped) — avoids broken img requests from raw S3 URLs.
     */
    public function file(Request $request, Asset $asset, AssetVersion $assetVersion): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            abort(403);
        }

        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            abort(404);
        }

        if ($assetVersion->asset_id !== $asset->id) {
            abort(404);
        }

        Gate::authorize('view', $asset);

        $path = $assetVersion->file_path;
        if ($path === null || $path === '') {
            abort(404, 'Version file not available.');
        }

        if (! Storage::disk('s3')->exists($path)) {
            abort(404, 'File missing.');
        }

        $filename = basename($path) ?: 'image';

        return Storage::disk('s3')->response(
            $path,
            $filename,
            [
                'Content-Type' => $assetVersion->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }
}
