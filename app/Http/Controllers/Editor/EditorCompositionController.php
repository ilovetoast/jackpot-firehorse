<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Composition;
use App\Models\CompositionVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generative editor — composition persistence and version history.
 */
class EditorCompositionController extends Controller
{
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

    private function publicThumbnailUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function compositionJson(Composition $c): array
    {
        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'document' => $c->document_json ?? [],
            'thumbnail_url' => $this->publicThumbnailUrl($c->thumbnail_path),
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
            'thumbnail_url' => $this->publicThumbnailUrl($v->thumbnail_path),
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
            'thumbnail_url' => $this->publicThumbnailUrl($v->thumbnail_path),
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

    private function compositionThumbDir(Composition $c): string
    {
        return 'composition-thumbnails/'.$c->tenant_id.'/'.$c->brand_id.'/'.$c->id;
    }

    private function persistCompositionThumbnail(Composition $c, string $binary): void
    {
        $path = $this->compositionThumbDir($c).'/thumb.png';
        Storage::disk('public')->put($path, $binary);
        $c->thumbnail_path = $path;
    }

    private function persistVersionThumbnail(Composition $c, CompositionVersion $v, string $binary): void
    {
        $path = $this->compositionThumbDir($c).'/v-'.$v->id.'.png';
        Storage::disk('public')->put($path, $binary);
        $v->thumbnail_path = $path;
        $v->save();
    }

    /**
     * Keep only the newest N versions per composition to bound JSON + PNG storage.
     * Deletes oldest rows first (by id) and removes version thumbnail files from disk.
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
            if ($v->thumbnail_path) {
                Storage::disk('public')->delete($v->thumbnail_path);
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
            ->get(['id', 'name', 'thumbnail_path', 'updated_at']);

        $items = $rows->map(function (Composition $c) {
            return [
                'id' => (string) $c->id,
                'name' => $c->name,
                'thumbnail_url' => $this->publicThumbnailUrl($c->thumbnail_path),
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
                $this->persistCompositionThumbnail($c, $thumbBinary);
                $c->save();
            }

            $v = CompositionVersion::query()->create([
                'composition_id' => $c->id,
                'document_json' => $validated['document'],
                'label' => null,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($c->fresh(), $v, $thumbBinary);
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

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'document' => 'required|array',
            'version_label' => 'nullable|string|max:255',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
        ]);

        $createVersion = $request->boolean('create_version', true);
        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        DB::transaction(function () use ($composition, $validated, $createVersion, $thumbBinary) {
            if (isset($validated['name'])) {
                $composition->name = $validated['name'];
            }
            $composition->document_json = $validated['document'];

            // Thumbnails only when creating a new version row (manual save / checkpoint), not autosave.
            if ($thumbBinary !== null && $createVersion) {
                $this->persistCompositionThumbnail($composition, $thumbBinary);
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
                    $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary);
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

        $validated = $request->validate([
            'document' => 'required|array',
            'label' => 'nullable|string|max:255',
            'thumbnail_png_base64' => 'nullable|string|max:6000000',
        ]);

        $thumbBinary = $this->decodeThumbnailPayload($validated['thumbnail_png_base64'] ?? null);

        DB::transaction(function () use ($composition, $validated, $thumbBinary) {
            $composition->document_json = $validated['document'];

            if ($thumbBinary !== null) {
                $this->persistCompositionThumbnail($composition, $thumbBinary);
            }

            $composition->save();

            $v = CompositionVersion::query()->create([
                'composition_id' => $composition->id,
                'document_json' => $validated['document'],
                'label' => $validated['label'] ?? null,
                'created_at' => now(),
            ]);

            if ($thumbBinary !== null) {
                $this->persistVersionThumbnail($composition->fresh(), $v, $thumbBinary);
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

        $composition = DB::transaction(function () use ($tenant, $brand, $user, $name, $doc) {
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

            $this->pruneOldVersions($c->fresh());

            return $c->fresh();
        });

        return response()->json(['composition' => $this->compositionJson($composition)]);
    }
}
