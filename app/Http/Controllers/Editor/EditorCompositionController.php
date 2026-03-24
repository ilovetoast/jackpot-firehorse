<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Models\User;
use App\Services\CompositionThumbnailAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generative editor — composition persistence and version history.
 *
 * Preview thumbnails are stored as {@link Asset} rows on canonical tenant storage (S3 + same paths as DAM),
 * not on the local public disk.
 */
class EditorCompositionController extends Controller
{
    public function __construct(
        protected CompositionThumbnailAssetService $thumbnailAssets
    ) {}

    private function resolveComposition(Request $request, int $id): ?Composition
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return null;
        }

        return Composition::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();
    }

    private function resolveThumbnailUrl(?string $assetId): ?string
    {
        if ($assetId === null || $assetId === '') {
            return null;
        }

        $asset = Asset::query()->find($assetId);
        if (! $asset) {
            return null;
        }

        // Same-origin URL (streams via thumbnailAsset). Avoids broken <img> from presigned S3 GETs
        // (bucket policy / region / local dev) — same pattern as EditorAssetBridgeController::file.
        return route('api.editor.compositions.thumbnail-asset', ['asset' => $asset->id], true);
    }

    /**
     * GET /app/api/compositions/thumbnail/{asset}
     *
     * Stream composition preview PNG through the app (authenticated, brand-scoped).
     */
    public function thumbnailAsset(Request $request, Asset $asset): Response
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

        $meta = $asset->metadata ?? [];
        $isCompositionPreview = ($meta['composition_preview'] ?? false) === true
            || $asset->source === 'composition_editor';
        if (! $isCompositionPreview) {
            abort(404);
        }

        Gate::authorize('view', $asset);

        $path = $asset->storage_root_path;
        if ($path === null || $path === '') {
            $asset->loadMissing('currentVersion');
            $path = $asset->currentVersion?->file_path;
        }
        if ($path === null || $path === '') {
            abort(404, 'Preview not available.');
        }

        if (! Storage::disk('s3')->exists($path)) {
            abort(404, 'Preview file missing.');
        }

        return Storage::disk('s3')->response(
            $path,
            $asset->original_filename ?: 'composition-preview.png',
            [
                'Content-Type' => $asset->mime_type ?: 'image/png',
                'Cache-Control' => 'private, max-age=120',
            ]
        );
    }

    private function compositionJson(Composition $c): array
    {
        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'document' => $c->document_json ?? [],
            'thumbnail_url' => $this->resolveThumbnailUrl($c->thumbnail_asset_id),
            'created_at' => $c->created_at?->toIso8601String() ?? '',
            'updated_at' => $c->updated_at?->toIso8601String() ?? '',
        ];
    }

    private function versionMetaJson(CompositionVersion $v): array
    {
        return [
            'id' => (string) $v->id,
            'composition_id' => (string) $v->composition_id,
            'label' => $v->label,
            'thumbnail_url' => $this->resolveThumbnailUrl($v->thumbnail_asset_id),
            'created_at' => $v->created_at?->toIso8601String() ?? '',
        ];
    }

    private function versionFullJson(CompositionVersion $v): array
    {
        return [
            'id' => (string) $v->id,
            'composition_id' => (string) $v->composition_id,
            'document' => $v->document_json ?? [],
            'label' => $v->label,
            'thumbnail_url' => $this->resolveThumbnailUrl($v->thumbnail_asset_id),
            'created_at' => $v->created_at?->toIso8601String() ?? '',
        ];
    }

    private function decodeThumbnailPayload(?string $base64): ?string
    {
        if ($base64 === null || $base64 === '') {
            return null;
        }
        if (str_starts_with($base64, 'data:image')) {
            $base64 = (string) preg_replace('/^data:image\/\w+;base64,/', '', $base64);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || strlen($binary) < 32 || strlen($binary) > 2_500_000) {
            return null;
        }

        return $binary;
    }

    private function persistCompositionThumbnail(Composition $c, string $binary, User $user): void
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return;
        }

        $id = $this->thumbnailAssets->createFromPngBinary(
            $tenant,
            $brand,
            $user,
            $binary,
            $c->thumbnail_asset_id
        );
        $c->thumbnail_asset_id = $id;
    }

    private function persistVersionThumbnail(Composition $c, CompositionVersion $v, string $binary, User $user): void
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return;
        }

        $id = $this->thumbnailAssets->createFromPngBinary(
            $tenant,
            $brand,
            $user,
            $binary,
            $v->thumbnail_asset_id
        );
        $v->thumbnail_asset_id = $id;
        $v->save();
    }

    /**
     * Keep only the newest N versions per composition to bound JSON + PNG storage.
     * Deletes oldest rows first (by id) and soft-deletes version thumbnail assets.
     */
    private function pruneOldVersions(Composition $composition, int $maxVersions = 50): void
    {
        $compositionId = $composition->id;
        $count = CompositionVersion::query()->where('composition_id', $compositionId)->count();
        if ($count <= $maxVersions) {
            return;
        }

        $deleteCount = $count - $maxVersions;
        $toDelete = CompositionVersion::query()
            ->where('composition_id', $compositionId)
            ->orderBy('id')
            ->limit($deleteCount)
            ->get();

        foreach ($toDelete as $v) {
            if ($v->thumbnail_asset_id) {
                Asset::query()->whereKey($v->thumbnail_asset_id)->delete();
            }
            $v->delete();
        }
    }

    /**
     * GET /app/api/compositions — list saved compositions for this brand (no full document payload).
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized', 'compositions' => []], 403);
        }

        $rows = Composition::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'name', 'thumbnail_asset_id', 'updated_at']);

        $items = $rows->map(function (Composition $c) {
            return [
                'id' => (string) $c->id,
                'name' => $c->name,
                'thumbnail_url' => $this->resolveThumbnailUrl($c->thumbnail_asset_id),
                'updated_at' => $c->updated_at?->toIso8601String() ?? '',
            ];
        });

        return response()->json(['compositions' => $items]);
    }

    /**
     * POST /app/api/compositions
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'document' => 'required|array',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
        ]);

        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        $composition = DB::transaction(function () use ($tenant, $brand, $user, $validated, $thumbBinary) {
            $c = Composition::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'name' => $validated['name'],
                'document_json' => $validated['document'],
            ]);

            if ($thumbBinary !== null) {
                $this->persistCompositionThumbnail($c, $thumbBinary, $user);
                $c->save();
            }

            $v = CompositionVersion::query()->create([
                'composition_id' => $c->id,
                'document_json' => $validated['document'],
                'label' => null,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($c->fresh(), $v, $thumbBinary, $user);
            }

            $this->pruneOldVersions($c->fresh());

            return $c->fresh();
        });

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }

    /**
     * PUT /app/api/compositions/{id}
     *
     * create_version: false = autosave (document + optional thumbnail only, no new version row).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $composition = $this->resolveComposition($request, $id);
        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'document' => 'required|array',
            'version_label' => 'nullable|string|max:255',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
        ]);

        $createVersion = $request->boolean('create_version', true);
        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        DB::transaction(function () use ($composition, $validated, $createVersion, $thumbBinary, $user) {
            if (isset($validated['name'])) {
                $composition->name = $validated['name'];
            }
            $composition->document_json = $validated['document'];

            // Thumbnails only when creating a new version row (manual save / checkpoint), not autosave.
            if ($thumbBinary !== null && $createVersion) {
                $this->persistCompositionThumbnail($composition, $thumbBinary, $user);
            }

            $composition->save();

            if ($createVersion) {
                $v = CompositionVersion::query()->create([
                    'composition_id' => $composition->id,
                    'document_json' => $validated['document'],
                    'label' => $validated['version_label'] ?? null,
                    'created_at' => now(),
                ]);

                if ($thumbBinary !== null) {
                    $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary, $user);
                }

                $this->pruneOldVersions($composition->fresh());
            }
        });

        return response()->json(['composition' => $this->compositionJson($composition->fresh())]);
    }

    /**
     * GET /app/api/compositions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $composition = $this->resolveComposition($request, $id);
        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }

    /**
     * GET /app/api/compositions/{id}/versions
     */
    public function versionsIndex(Request $request, int $id): JsonResponse
    {
        $composition = $this->resolveComposition($request, $id);
        if (! $composition) {
            return response()->json(['error' => 'Not found', 'versions' => []], 404);
        }

        $versions = $composition->versions()
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CompositionVersion $v) => $this->versionMetaJson($v))
            ->values();

        return response()->json(['versions' => $versions]);
    }

    /**
     * POST /app/api/compositions/{id}/versions — snapshot with optional label (checkpoints).
     */
    public function versionsStore(Request $request, int $id): JsonResponse
    {
        $composition = $this->resolveComposition($request, $id);
        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'document' => 'required|array',
            'label' => 'nullable|string|max:255',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
        ]);

        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        DB::transaction(function () use ($composition, $validated, $thumbBinary, $user) {
            $composition->document_json = $validated['document'];

            if ($thumbBinary !== null) {
                $this->persistCompositionThumbnail($composition, $thumbBinary, $user);
            }

            $composition->save();

            $v = CompositionVersion::query()->create([
                'composition_id' => $composition->id,
                'document_json' => $validated['document'],
                'label' => $validated['label'] ?? null,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary, $user);
            }

            $this->pruneOldVersions($composition->fresh());
        });

        $latest = $composition->versions()->orderByDesc('id')->first();

        return response()->json([
            'composition' => $this->compositionJson($composition->fresh()),
            'version' => $latest ? $this->versionFullJson($latest) : null,
        ]);
    }

    /**
     * GET /app/api/compositions/{id}/versions/{versionId}
     */
    public function versionsShow(Request $request, int $id, int $versionId): JsonResponse
    {
        $composition = $this->resolveComposition($request, $id);
        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $version = CompositionVersion::query()
            ->where('id', $versionId)
            ->where('composition_id', $composition->id)
            ->first();

        if (! $version) {
            return response()->json(['error' => 'Version not found'], 404);
        }

        return response()->json(['version' => $this->versionFullJson($version)]);
    }

    /**
     * POST /app/api/compositions/{id}/duplicate — new composition with same document.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $source = $this->resolveComposition($request, $id);
        if (! $source) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $doc = $source->document_json ?? [];
        $name = $validated['name'] ?? ($source->name.' (copy)');

        $composition = DB::transaction(function () use ($tenant, $brand, $user, $name, $doc, $source) {
            $c = Composition::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'name' => $name,
                'document_json' => $doc,
            ]);

            CompositionVersion::query()->create([
                'composition_id' => $c->id,
                'document_json' => $doc,
                'label' => 'Duplicated',
                'created_at' => now(),
            ]);

            $dupThumbId = $source->thumbnail_asset_id
                ? $this->thumbnailAssets->duplicateAsset($source->thumbnail_asset_id, $tenant, $brand, $user)
                : null;
            if ($dupThumbId !== null && $dupThumbId !== '') {
                $c->thumbnail_asset_id = $dupThumbId;
                $c->save();
            }

            $this->pruneOldVersions($c->fresh());

            return $c->fresh();
        });

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }
}
