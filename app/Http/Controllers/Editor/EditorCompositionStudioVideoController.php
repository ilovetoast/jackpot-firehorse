<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStudioCompositionVideoExportJob;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Support\StudioCanvasRuntimeExportJobDiagnostics;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class EditorCompositionStudioVideoController extends Controller
{
    /**
     * POST /app/api/compositions/{id}/studio/video-layer
     *
     * Appends a video layer referencing a DAM asset (e.g. completed Studio animation).
     */
    public function storeVideoLayer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Workspace required'], 422);
        }

        $validated = $request->validate([
            'asset_id' => [
                'required',
                'uuid',
                Rule::exists('assets', 'id')->where('tenant_id', $tenant->id),
            ],
            'file_url' => 'required|string|max:2000',
            'name' => 'nullable|string|max:255',
            // Note: do not use PHP numeric separators (3_600_000) inside rule strings — Laravel treats the max
            // parameter as a literal string and fails with "does not represent a valid number".
            'start_ms' => 'nullable|integer|min:0|max:3600000',
            'end_ms' => 'nullable|integer|min:0|max:3600000',
            'muted' => 'sometimes|boolean',
            /** `add` = new layer; `replace_layer` = swap a raster/video layer in place (uses replace_layer_id). */
            'insert_mode' => 'nullable|in:add,replace_layer',
            'replace_layer_id' => 'nullable|string|max:128',
            /** `back` = new video behind existing layers. `front` = on top. Ignored for replace_layer. */
            'stacking' => 'nullable|in:front,back',
            /** Optional; merged into the new video layer as `studioProvenance` (camelCase in document JSON). */
            'provenance' => 'nullable|array',
        ]);

        $composition = Composition::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->first();
        if (! $composition) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        if (! isset($doc['layers']) || ! is_array($doc['layers'])) {
            $doc['layers'] = [];
        }
        $w = (int) ($doc['width'] ?? 0);
        $h = (int) ($doc['height'] ?? 0);
        if ($w < 2 || $h < 2) {
            throw ValidationException::withMessages(['document' => 'Invalid composition size.']);
        }
        $insertMode = (string) ($validated['insert_mode'] ?? 'add');
        if (! in_array($insertMode, ['add', 'replace_layer'], true)) {
            $insertMode = 'add';
        }
        if ($insertMode === 'replace_layer' && (empty($validated['replace_layer_id'] ?? null))) {
            throw ValidationException::withMessages(['replace_layer_id' => 'Required when insert_mode is replace_layer.']);
        }

        $stacking = (string) ($validated['stacking'] ?? 'back');
        if (! in_array($stacking, ['front', 'back'], true)) {
            $stacking = 'back';
        }

        $this->clearPrimaryForExportOnAllVideoLayers($doc['layers']);

        $endMs = $validated['end_ms'] ?? 30_000;
        $startMs = $validated['start_ms'] ?? 0;
        if ($endMs <= $startMs) {
            $endMs = $startMs + 30_000;
        }
        if (! isset($doc['studio_timeline']) || ! is_array($doc['studio_timeline'])) {
            $doc['studio_timeline'] = ['duration_ms' => $endMs];
        } else {
            $doc['studio_timeline']['duration_ms'] = max((int) ($doc['studio_timeline']['duration_ms'] ?? 0), $endMs);
        }

        $newId = 'video_'.bin2hex(random_bytes(6));
        $provenance = $this->normalizeStudioProvenance(is_array($validated['provenance'] ?? null) ? $validated['provenance'] : null);

        if ($insertMode === 'replace_layer') {
            $replaceId = (string) $validated['replace_layer_id'];
            $foundIdx = null;
            $old = null;
            foreach ($doc['layers'] as $idx => $ly) {
                if (is_array($ly) && (string) ($ly['id'] ?? '') === $replaceId) {
                    $foundIdx = $idx;
                    $old = $ly;
                    break;
                }
            }
            if (! is_array($old)) {
                throw ValidationException::withMessages(['replace_layer_id' => 'Layer not found in this composition.']);
            }
            $oldType = (string) ($old['type'] ?? '');
            if (! in_array($oldType, ['image', 'generative_image', 'video'], true)) {
                throw ValidationException::withMessages(['replace_layer_id' => 'Only image, generative_image, or video layers can be replaced.']);
            }
            $newZ = (int) ($old['z'] ?? 0);
            $oldTransform = is_array($old['transform'] ?? null) ? $old['transform'] : null;
            $transform = is_array($oldTransform)
                ? $oldTransform
                : [
                    'x' => 0,
                    'y' => 0,
                    'width' => $w,
                    'height' => $h,
                ];
            $layerName = $validated['name'] ?? (is_string($old['name'] ?? null) ? $old['name'] : 'Video');
            array_splice($doc['layers'], (int) $foundIdx, 1);
            $newLayer = [
                'id' => $newId,
                'type' => 'video',
                'name' => $layerName,
                'visible' => ($old['visible'] ?? true) !== false,
                'locked' => (bool) ($old['locked'] ?? false),
                'z' => $newZ,
                'transform' => $transform,
                'primaryForExport' => true,
                'assetId' => (string) $validated['asset_id'],
                'src' => (string) $validated['file_url'],
                'fit' => in_array((string) ($old['fit'] ?? ''), ['fill', 'contain', 'cover'], true) ? (string) $old['fit'] : 'cover',
                'timeline' => [
                    'start_ms' => $startMs,
                    'end_ms' => $endMs,
                    'trim_in_ms' => 0,
                    'trim_out_ms' => 0,
                    'muted' => (bool) ($validated['muted'] ?? false),
                ],
            ];
            if ($provenance !== []) {
                $newLayer['studioProvenance'] = $provenance;
            }
            $doc['layers'][] = $newLayer;
        } else {
            if ($stacking === 'back') {
                foreach ($doc['layers'] as $idx => $ly) {
                    if (is_array($ly)) {
                        $doc['layers'][$idx]['z'] = (int) ($ly['z'] ?? 0) + 1;
                    }
                }
                $newZ = 0;
            } else {
                $maxZ = 0;
                foreach ($doc['layers'] as $ly) {
                    if (is_array($ly) && isset($ly['z'])) {
                        $maxZ = max($maxZ, (int) $ly['z']);
                    }
                }
                $newZ = $maxZ + 1;
            }
            $newLayer = [
                'id' => $newId,
                'type' => 'video',
                'name' => $validated['name'] ?? 'Video',
                'visible' => true,
                'locked' => false,
                'z' => $newZ,
                'transform' => [
                    'x' => 0,
                    'y' => 0,
                    'width' => $w,
                    'height' => $h,
                ],
                'primaryForExport' => true,
                'assetId' => (string) $validated['asset_id'],
                'src' => (string) $validated['file_url'],
                'timeline' => [
                    'start_ms' => $startMs,
                    'end_ms' => $endMs,
                    'trim_in_ms' => 0,
                    'trim_out_ms' => 0,
                    'muted' => (bool) ($validated['muted'] ?? false),
                ],
            ];
            if ($provenance !== []) {
                $newLayer['studioProvenance'] = $provenance;
            }
            $doc['layers'][] = $newLayer;
        }
        $composition->document_json = $doc;
        $composition->save();

        return response()->json([
            'composition_id' => (string) $composition->id,
            'document_json' => $doc,
            'new_layer_id' => $newId,
        ], 201);
    }

    /**
     * POST /app/api/compositions/{id}/studio/video-export
     */
    public function requestExport(Request $request, int $id): JsonResponse
    {
        if (! (bool) config('studio_video.export_enabled', true)) {
            return response()->json(['message' => 'Video export is disabled.'], 403);
        }
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Workspace required'], 422);
        }
        if (! $user->hasPermissionForTenant($tenant, 'asset.upload')) {
            return response()->json(['message' => 'You do not have permission to upload assets.'], 403);
        }

        $composition = Composition::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->first();
        if (! $composition) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'render_mode' => ['nullable', 'string', Rule::in([
                StudioCompositionVideoExportRenderMode::LEGACY_BITMAP->value,
                StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            ])],
            'include_audio' => 'nullable|boolean',
            'editor_publish' => 'nullable|array',
            'editor_publish.name' => 'nullable|string|max:255',
            'editor_publish.description' => 'nullable|string|max:5000',
            'editor_publish.category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id),
            ],
            'editor_publish.field_metadata' => 'nullable|array',
            'editor_publish.collection_ids' => 'nullable|array',
            'editor_publish.collection_ids.*' => 'integer|min:1',
            'editor_publish.editor_provenance' => 'nullable|array',
        ]);

        $includeAudio = (bool) $request->input('include_audio', true);
        $editorPublish = $request->input('editor_publish');
        $renderMode = StudioCompositionVideoExportRenderMode::tryFrom((string) $request->input('render_mode', 'legacy_bitmap'))
            ?? StudioCompositionVideoExportRenderMode::LEGACY_BITMAP;
        $metaJson = [
            'include_audio' => $includeAudio,
            'render_mode' => $renderMode->value,
        ];
        if (is_array($editorPublish) && $editorPublish !== []) {
            $metaJson['editor_publish'] = $editorPublish;
        }

        $row = null;
        try {
            $row = StudioCompositionVideoExportJob::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'composition_id' => $composition->id,
                'render_mode' => $renderMode->value,
                'status' => StudioCompositionVideoExportJob::STATUS_QUEUED,
                'meta_json' => $metaJson,
            ]);
            ProcessStudioCompositionVideoExportJob::dispatch($row->id);
        } catch (\Throwable $e) {
            Log::error('[EditorCompositionStudioVideoController] video export start failed', [
                'error' => $e->getMessage(),
                'job_id' => $row?->id,
                'exception' => get_class($e),
            ]);
            if ($row !== null) {
                $row->update([
                    'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                    'error_json' => ['message' => 'Could not start video export.'],
                ]);
            }
            $message = config('app.debug')
                ? $e->getMessage()
                : 'Could not start video export. Check that the queue worker is running, then try again.';

            return response()->json(['message' => $message], 500);
        }

        return response()->json([
            'id' => (string) $row->id,
            'status' => $row->status,
            'render_mode' => $row->render_mode,
        ], 202);
    }

    public function exportStatus(Request $request, int $id, int $exportJobId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Workspace required'], 422);
        }
        $row = StudioCompositionVideoExportJob::query()
            ->where('id', $exportJobId)
            ->where('composition_id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload = [
            'id' => (string) $row->id,
            'status' => $row->status,
            'render_mode' => $row->render_mode,
            'output_asset_id' => $row->output_asset_id !== null ? (string) $row->output_asset_id : null,
            'error' => $row->error_json,
            'meta' => $row->meta_json,
        ];

        if ($row->render_mode === StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value) {
            $payload['canvas_runtime_debug'] = StudioCanvasRuntimeExportJobDiagnostics::canvasRuntimeDebugBlock($row);
        }

        return response()->json($payload);
    }

    /**
     * @param  array<int, mixed>  $layers
     */
    private function clearPrimaryForExportOnAllVideoLayers(array &$layers): void
    {
        foreach ($layers as $idx => $ly) {
            if (is_array($ly) && ($ly['type'] ?? '') === 'video') {
                $layers[$idx]['primaryForExport'] = false;
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    private function normalizeStudioProvenance(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        $out = [];
        $str = static function (mixed $v): ?string {
            if (is_string($v) && $v !== '') {
                return $v;
            }
            if (is_scalar($v)) {
                return (string) $v;
            }

            return null;
        };
        $sk = $str($raw['sourceMode'] ?? null) ?? $str($raw['source_mode'] ?? null);
        if ($sk !== null) {
            $out['sourceMode'] = $sk;
        }
        foreach (['provider', 'model'] as $k) {
            $v = $str($raw[$k] ?? null);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        $jid = $str($raw['jobId'] ?? null) ?? $str($raw['job_id'] ?? null);
        if ($jid !== null) {
            $out['jobId'] = $jid;
        }
        $aid = $str($raw['outputAssetId'] ?? null) ?? $str($raw['output_asset_id'] ?? null);
        if ($aid !== null) {
            $out['outputAssetId'] = $aid;
        }
        if (isset($raw['durationMs']) && is_numeric($raw['durationMs'])) {
            $out['durationMs'] = (int) $raw['durationMs'];
        } elseif (isset($raw['duration_ms']) && is_numeric($raw['duration_ms'])) {
            $out['durationMs'] = (int) $raw['duration_ms'];
        }

        return $out;
    }
}
