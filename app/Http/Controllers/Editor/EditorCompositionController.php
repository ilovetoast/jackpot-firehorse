<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Models\User;
use App\Services\CompositionAssetReferenceStateService;
use App\Services\CompositionThumbnailAssetService;
use App\Services\Editor\CompositionDuplicateService;
use App\Services\GenerativeCompositionAssetCleanup;
use App\Services\StudioUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        protected CompositionThumbnailAssetService $thumbnailAssets,
        protected GenerativeCompositionAssetCleanup $generativeCompositionAssetCleanup,
        protected CompositionAssetReferenceStateService $compositionRefState,
        protected StudioUsageService $studioUsage,
        protected CompositionDuplicateService $compositionDuplicate,
    ) {}

    private function resolveComposition(Request $request, int $id): ?Composition
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return null;
        }

        return Composition::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
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
        $visibility = $c->visibility ?? Composition::VISIBILITY_SHARED;

        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'visibility' => $visibility,
            'owner_user_id' => $c->user_id !== null ? (string) $c->user_id : null,
            'document' => $c->document_json ?? [],
            'thumbnail_url' => $this->resolveThumbnailUrl($c->thumbnail_asset_id),
            'created_at' => $c->created_at?->toIso8601String() ?? '',
            'updated_at' => $c->updated_at?->toIso8601String() ?? '',
        ];
    }

    private function normalizeVisibility(?string $value): string
    {
        return $value === Composition::VISIBILITY_SHARED
            ? Composition::VISIBILITY_SHARED
            : Composition::VISIBILITY_PRIVATE;
    }

    private function versionMetaJson(CompositionVersion $v): array
    {
        return [
            'id' => (string) $v->id,
            'composition_id' => (string) $v->composition_id,
            'label' => $v->label,
            'kind' => $v->kind ?? CompositionVersion::KIND_MANUAL,
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
            'kind' => $v->kind ?? CompositionVersion::KIND_MANUAL,
            'thumbnail_url' => $this->resolveThumbnailUrl($v->thumbnail_asset_id),
            'created_at' => $v->created_at?->toIso8601String() ?? '',
        ];
    }

    private function normalizeKind(?string $kind): string
    {
        return $kind === CompositionVersion::KIND_AUTOSAVE
            ? CompositionVersion::KIND_AUTOSAVE
            : CompositionVersion::KIND_MANUAL;
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
            $c->thumbnail_asset_id,
            (int) $c->id
        );
        $c->thumbnail_asset_id = $id;
        $thumb = Asset::query()->find($id);
        if ($thumb !== null) {
            $this->compositionRefState->refreshForAsset($thumb);
        }
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
            $v->thumbnail_asset_id,
            (int) $c->id
        );
        $v->thumbnail_asset_id = $id;
        $v->save();
        $thumb = Asset::query()->find($id);
        if ($thumb !== null) {
            $this->compositionRefState->refreshForAsset($thumb);
        }
    }

    /**
     * Max retained versions per composition, split by kind.
     * Manual versions are user checkpoints (kept generously); autosaves are a small rolling window.
     */
    private const MAX_MANUAL_VERSIONS = 50;

    private const MAX_AUTOSAVE_VERSIONS = 10;

    /**
     * Prune old versions, split by kind:
     *  - manual: keep newest {@see self::MAX_MANUAL_VERSIONS}
     *  - autosave: keep newest {@see self::MAX_AUTOSAVE_VERSIONS}
     *
     * Rolling autosave window prevents hobby edits from burying real checkpoints.
     */
    private function pruneOldVersions(Composition $composition): void
    {
        $this->pruneVersionsOfKind($composition, CompositionVersion::KIND_MANUAL, self::MAX_MANUAL_VERSIONS);
        $this->pruneVersionsOfKind($composition, CompositionVersion::KIND_AUTOSAVE, self::MAX_AUTOSAVE_VERSIONS);
    }

    private function pruneVersionsOfKind(Composition $composition, string $kind, int $max): void
    {
        $compositionId = $composition->id;
        $count = CompositionVersion::query()
            ->where('composition_id', $compositionId)
            ->where('kind', $kind)
            ->count();
        if ($count <= $max) {
            return;
        }

        $deleteCount = $count - $max;
        $toDelete = CompositionVersion::query()
            ->where('composition_id', $compositionId)
            ->where('kind', $kind)
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
            ->visibleToUser($user)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'name', 'thumbnail_asset_id', 'updated_at', 'visibility']);

        $items = $rows->map(function (Composition $c) {
            return [
                'id' => (string) $c->id,
                'name' => $c->name,
                'visibility' => $c->visibility ?? Composition::VISIBILITY_SHARED,
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
            'visibility' => 'nullable|string|in:private,shared',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
            'telemetry' => 'nullable|array',
            'telemetry.duration_ms' => 'nullable|integer|min:0|max:172800000',
        ]);

        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);
        $visibility = $this->normalizeVisibility($validated['visibility'] ?? null);

        $composition = DB::transaction(function () use ($tenant, $brand, $user, $validated, $thumbBinary, $visibility) {
            $c = Composition::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'visibility' => $visibility,
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
                'kind' => CompositionVersion::KIND_MANUAL,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($c->fresh(), $v, $thumbBinary, $user);
            }

            $this->pruneOldVersions($c->fresh());

            return $c->fresh();
        });

        $this->recordStudioTelemetryFromRequest($request, StudioUsageService::METRIC_COMPOSITION_CREATE, 1);

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }

    /**
     * POST /app/api/compositions/batch
     *
     * Batch-create a group of compositions from a single recipe rendered across
     * a Format Pack on the client. Every incoming composition is created as its
     * own row (with its own version-0 history entry), identically to `store()`,
     * but wrapped in a single transaction so an error on composition #7 rolls
     * back compositions #1–6 too — users either see the whole batch land or
     * none of it.
     *
     * Why not call store() in a loop from the client?
     *   1. Per-request overhead across 15+ compositions is noticeable (each
     *      trip re-runs auth, tenant/brand resolution, middleware, CSRF).
     *   2. Partial failures would leave orphans behind — a client-side retry
     *      would either duplicate or silently skip.
     *   3. Transactional atomicity matters for list UX: the "open one to start
     *      editing" flow is simpler when the batch is all-or-nothing.
     *
     * Thumbnails are intentionally not accepted in this endpoint. The batch
     * flow is meant for *scaffolding* — each composition gets a thumbnail the
     * first time it's opened + saved in Studio, same as any other new comp.
     * Supporting thumbnails here would mean ~15 PNG uploads per call, which
     * would blow past request-size limits.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            // Cap the batch size at 50 so a malformed client can't flood the
            // DB. The comprehensive Format Pack caps at ~21 today; 50 leaves
            // runway without risking pathological payloads.
            'compositions' => 'required|array|min:1|max:50',
            'compositions.*.name' => 'required|string|max:255',
            'compositions.*.document' => 'required|array',
            'compositions.*.visibility' => 'nullable|string|in:private,shared',
            'telemetry' => 'nullable|array',
            'telemetry.duration_ms' => 'nullable|integer|min:0|max:172800000',
        ]);

        $compositions = DB::transaction(function () use ($tenant, $brand, $user, $validated) {
            $out = [];
            foreach ($validated['compositions'] as $item) {
                $visibility = $this->normalizeVisibility($item['visibility'] ?? null);
                $c = Composition::query()->create([
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand->id,
                    'user_id' => $user->id,
                    'visibility' => $visibility,
                    'name' => $item['name'],
                    'document_json' => $item['document'],
                ]);

                // Seed a manual version-0 so the version history reads
                // "created from batch" from the start. Matches store()'s
                // behavior — opening one of these comps in Studio sees the
                // same "one manual version" affordance as any other new comp.
                CompositionVersion::query()->create([
                    'composition_id' => $c->id,
                    'document_json' => $item['document'],
                    'label' => null,
                    'kind' => CompositionVersion::KIND_MANUAL,
                    'created_at' => now(),
                ]);

                $out[] = $c->fresh();
            }

            return $out;
        });

        $this->recordStudioTelemetryFromRequest(
            $request,
            StudioUsageService::METRIC_COMPOSITION_BATCH,
            count($compositions),
        );

        return response()->json([
            'compositions' => array_map(fn ($c) => $this->compositionJson($c), $compositions),
        ]);
    }

    /**
     * PUT /app/api/compositions/{id}
     *
     * create_version: false = autosave (document + optional thumbnail PNG, no new version row).
     * When thumbnail_png_base64 is sent, the composition list preview is updated even for autosave.
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
            'visibility' => 'sometimes|string|in:private,shared',
            'version_label' => 'nullable|string|max:255',
            'version_kind' => 'nullable|string|in:manual,autosave',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
            'telemetry' => 'nullable|array',
            'telemetry.duration_ms' => 'nullable|integer|min:0|max:172800000',
        ]);

        $createVersion = $request->boolean('create_version', true);
        $versionKind = $this->normalizeKind($validated['version_kind'] ?? null);
        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        $oldDocument = is_array($composition->document_json) ? $composition->document_json : [];

        if (array_key_exists('visibility', $validated)) {
            $nextVisibility = $this->normalizeVisibility($validated['visibility']);
            $currentVisibility = $composition->visibility ?? Composition::VISIBILITY_SHARED;
            if ($nextVisibility !== $currentVisibility && (int) $composition->user_id !== (int) $user->id) {
                return response()->json(['error' => 'Only the composition owner can change visibility.'], 403);
            }
        }

        DB::transaction(function () use ($composition, $validated, $createVersion, $versionKind, $thumbBinary, $user) {
            if (isset($validated['name'])) {
                $composition->name = $validated['name'];
            }
            if (array_key_exists('visibility', $validated)) {
                $composition->visibility = $this->normalizeVisibility($validated['visibility']);
            }
            $composition->document_json = $validated['document'];

            // Composition list preview: update whenever PNG bytes are sent (manual save or autosave).
            // Version-row thumbnails only when we create a new history snapshot (create_version true).
            if ($thumbBinary !== null) {
                $this->persistCompositionThumbnail($composition, $thumbBinary, $user);
            }

            $composition->save();

            if ($createVersion) {
                $v = CompositionVersion::query()->create([
                    'composition_id' => $composition->id,
                    'document_json' => $validated['document'],
                    'label' => $validated['version_label'] ?? null,
                    'kind' => $versionKind,
                    'created_at' => now(),
                ]);

                if ($thumbBinary !== null) {
                    $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary, $user);
                }

                $this->pruneOldVersions($composition->fresh());
            }
        });

        $fresh = $composition->fresh();
        if ($fresh !== null) {
            $this->generativeCompositionAssetCleanup->afterDocumentReplaced(
                (int) $fresh->tenant_id,
                (int) $fresh->brand_id,
                $oldDocument,
                is_array($validated['document']) ? $validated['document'] : []
            );
        }

        if ($createVersion && $versionKind === CompositionVersion::KIND_MANUAL) {
            $this->recordStudioTelemetryFromRequest($request, StudioUsageService::METRIC_MANUAL_CHECKPOINT, 1);
        }

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
            'kind' => 'nullable|string|in:manual,autosave',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
            'telemetry' => 'nullable|array',
            'telemetry.duration_ms' => 'nullable|integer|min:0|max:172800000',
        ]);

        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);
        $versionKind = $this->normalizeKind($validated['kind'] ?? null);

        $oldDocument = is_array($composition->document_json) ? $composition->document_json : [];

        DB::transaction(function () use ($composition, $validated, $versionKind, $thumbBinary, $user) {
            $composition->document_json = $validated['document'];

            if ($thumbBinary !== null) {
                $this->persistCompositionThumbnail($composition, $thumbBinary, $user);
            }

            $composition->save();

            $v = CompositionVersion::query()->create([
                'composition_id' => $composition->id,
                'document_json' => $validated['document'],
                'label' => $validated['label'] ?? null,
                'kind' => $versionKind,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary, $user);
            }

            $this->pruneOldVersions($composition->fresh());
        });

        $fresh = $composition->fresh();
        if ($fresh !== null) {
            $this->generativeCompositionAssetCleanup->afterDocumentReplaced(
                (int) $fresh->tenant_id,
                (int) $fresh->brand_id,
                $oldDocument,
                is_array($validated['document']) ? $validated['document'] : []
            );
        }

        $latest = $composition->versions()->orderByDesc('id')->first();

        if ($versionKind === CompositionVersion::KIND_MANUAL) {
            $this->recordStudioTelemetryFromRequest($request, StudioUsageService::METRIC_MANUAL_CHECKPOINT, 1);
        }

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

        $name = $validated['name'] ?? ($source->name.' (copy)');
        $composition = $this->compositionDuplicate->duplicate($source, $user, $name, 'Duplicated');

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }

    /**
     * DELETE /app/api/compositions/{id}
     *
     * Drops the composition row (composition_versions cascade). Soft-deletes preview thumbnail
     * {@link Asset} rows used for list/history tiles only — not DAM / library assets referenced in layer JSON.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $composition = Composition::query()
            ->with('versions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->first();

        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! $composition->userCanDelete($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $documentBeforeDelete = is_array($composition->document_json) ? $composition->document_json : [];

        /** @var array<int, string> $thumbnailAssetIds */
        $thumbnailAssetIds = collect([$composition->thumbnail_asset_id])
            ->merge($composition->versions->pluck('thumbnail_asset_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($composition, $thumbnailAssetIds): void {
            $composition->delete();
            foreach ($thumbnailAssetIds as $assetId) {
                Asset::query()->whereKey($assetId)->delete();
            }
        });

        $this->generativeCompositionAssetCleanup->afterCompositionRemoved(
            (int) $tenant->id,
            (int) $brand->id,
            $documentBeforeDelete
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Daily rollup (see {@link StudioUsageService}) — never throws past the save response.
     */
    private function recordStudioTelemetryFromRequest(Request $request, string $metric, int $count = 1): void
    {
        try {
            $tenant = app('tenant');
            if (! $tenant instanceof \App\Models\Tenant) {
                return;
            }
            if (! Schema::hasTable('studio_usage_daily')) {
                return;
            }
            $ms = 0;
            $t = $request->input('telemetry');
            if (is_array($t) && isset($t['duration_ms']) && is_numeric($t['duration_ms'])) {
                $ms = max(0, min((int) $t['duration_ms'], 172_800_000));
            }
            $this->studioUsage->record($tenant, $metric, $count, $ms, 0.0);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[EditorComposition] studio telemetry failed', [
                'metric' => $metric,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
