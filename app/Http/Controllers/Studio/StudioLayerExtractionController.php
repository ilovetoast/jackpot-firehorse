<?php

namespace App\Http\Controllers\Studio;

use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Controller;
use App\Jobs\StudioExtractLayersJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioLayerExtractionSession;
use App\Models\StudioAnimationJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\Fal\FalModelPricingService;
use App\Services\Studio\AiLayerExtractionService;
use App\Services\Studio\StudioLayerExtractionConfirmService;
use App\Services\Studio\StudioLayerExtractionMethodService;
use App\Support\StudioLayerExtractionStoragePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioLayerExtractionController extends Controller
{
    public function __construct(
        protected AiUsageService $aiUsageService,
        protected AiLayerExtractionService $extractionService,
        protected StudioLayerExtractionConfirmService $confirmService,
        protected StudioLayerExtractionMethodService $extractionMethodService,
        protected FalModelPricingService $falModelPricing,
    ) {}

    /**
     * POST /app/studio/documents/{document}/layers/{layer}/extract-layers
     */
    public function extract(Request $request, int $document, string $layer): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $composition = $this->resolveComposition($document, $tenant, $brand, $user);
        if ($composition === null) {
            return response()->json(['message' => 'Composition not found.'], 404);
        }

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layers = $doc['layers'] ?? [];
        $layerRow = $this->findLayer($layers, $layer);
        if ($layerRow === null) {
            return response()->json(['message' => 'Layer not found.'], 404);
        }

        $assetId = $this->resolveRasterAssetId($layerRow);
        if ($assetId === null) {
            return response()->json([
                'message' => 'This layer needs a library-backed raster (asset id) to extract from.',
            ], 422);
        }

        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();
        if ($asset === null) {
            return response()->json(['message' => 'Source asset not found.'], 404);
        }

        $request->validate([
            'method' => 'nullable|string|in:local,ai',
        ]);
        $rawInput = $request->input('method');
        if (is_string($rawInput) && strtolower(trim($rawInput)) === StudioLayerExtractionMethodService::METHOD_AI) {
            if (! $this->extractionMethodService->isAiExtractionRuntimeAvailable($tenant, $brand)) {
                return response()->json(['message' => 'AI segmentation is not available.'], 422);
            }
        }
        $method = $this->extractionMethodService->resolvedMethodForRequest(
            is_string($rawInput) ? $rawInput : null,
            $tenant,
            $brand
        );
        $mustPreCheckCredits = false;
        if ($method === StudioLayerExtractionMethodService::METHOD_AI) {
            $mustPreCheckCredits = true;
        } elseif ($method === StudioLayerExtractionMethodService::METHOD_LOCAL) {
            $mustPreCheckCredits = (bool) config('studio_layer_extraction.bill_floodfill_extraction', false);
        } else {
            $mustPreCheckCredits = AiLayerExtractionService::shouldBillExtractionForConfig();
        }
        if ($mustPreCheckCredits) {
            try {
                $this->aiUsageService->checkUsage($tenant, 'studio_layer_extraction', 1);
            } catch (PlanLimitExceededException $e) {
                if ($method === StudioLayerExtractionMethodService::METHOD_AI
                    && $e->limitType === PlanLimitExceededException::LIMIT_AI_CREDITS) {
                    return response()->json(['message' => 'Not enough AI credits for layer extraction.'], 402);
                }

                return response()->json($e->toApiArray(), 429);
            }
        }

        $this->purgeExpiredSessions();

        $ttlHours = max(1, (int) config('studio_layer_extraction.session_ttl_hours', 24));
        $sessionId = (string) Str::uuid();
        $providerKey = $method === StudioLayerExtractionMethodService::METHOD_AI
            ? 'sam'
            : 'floodfill';
        $initialMetadata = [
            'extraction_method' => $method,
            'billable' => $this->extractionMethodService->isBillableOnSuccess($method, $tenant, $brand),
            'ai_credit_key' => 'studio_layer_extraction',
            'estimated_provider_cost_usd' => $this->falModelPricing->estimatedCostUsd(),
            'provider_cost_source' => $this->falModelPricing->costSource(),
        ];
        $availableMethods = $this->extractionMethodService->buildAvailableMethods($tenant, $brand, $this->aiUsageService);
        $defaultExtractionMethod = $this->extractionMethodService->defaultMethodForContext($tenant, $brand);

        $pixels = $this->estimateAssetPixels($asset);
        $threshold = (int) config('studio_layer_extraction.async_pixel_threshold', 2_500_000);
        $alwaysQueue = (bool) config('studio_layer_extraction.always_queue', false);
        $samQueue = $method === StudioLayerExtractionMethodService::METHOD_AI
            && (bool) config('studio_layer_extraction.sam.prefer_queue', true);
        $shouldQueue = $alwaysQueue || ($pixels > $threshold && $threshold > 0) || $samQueue;

        StudioLayerExtractionSession::query()->create([
            'id' => $sessionId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => $composition->id,
            'source_layer_id' => $layer,
            'source_asset_id' => $assetId,
            'status' => StudioLayerExtractionSession::STATUS_PENDING,
            'provider' => $providerKey,
            'model' => null,
            'candidates_json' => null,
            'metadata' => $initialMetadata,
            'error_message' => null,
            'expires_at' => now()->addHours($ttlHours),
        ]);

        $session = StudioLayerExtractionSession::query()->findOrFail($sessionId);

        if ($shouldQueue) {
            StudioExtractLayersJob::dispatch($sessionId);

            return response()->json([
                'status' => 'pending',
                'extraction_session_id' => $sessionId,
                'queued' => true,
                'extraction_method' => $method,
                'default_extraction_method' => $defaultExtractionMethod,
                'available_methods' => $availableMethods,
                'provider_capabilities' => $this->extractionService->providerCapabilities(
                    $session
                ),
            ], 202);
        }

        $this->extractionService->processSession($session->fresh());
        $session = $session->fresh();
        if ($session === null) {
            return response()->json(['message' => 'Extraction failed.'], 500);
        }

        if ($session->status === StudioLayerExtractionSession::STATUS_FAILED) {
            $errFields = $this->extractionService->extractionFailureFieldsForApi($session);

            return response()->json(array_merge(
                [
                    'message' => $session->error_message ?? 'Extraction failed.',
                    'extraction_session_id' => $sessionId,
                ],
                $errFields
            ), 502);
        }

        $stored = json_decode((string) $session->candidates_json, true);

        return response()->json([
            'status' => 'ready',
            'extraction_session_id' => $sessionId,
            'queued' => false,
            'extraction_method' => $method,
            'default_extraction_method' => $defaultExtractionMethod,
            'available_methods' => $availableMethods,
            'candidates' => is_array($stored)
                ? $this->extractionService->decorateCandidatesForApi($session, $stored)
                : [],
            'provider_capabilities' => $this->providerCapabilitiesForSession($session),
        ]);
    }

    /**
     * GET /app/studio/layer-extraction-sessions/{session}
     */
    public function sessionShow(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.', 'status' => 'expired'], 410);
        }

        $stored = json_decode((string) $row->candidates_json, true);
        $candidates = is_array($stored) ? $this->extractionService->decorateCandidatesForApi($row, $stored) : [];
        $availableMethods = $this->extractionMethodService->buildAvailableMethods($tenant, $brand, $this->aiUsageService);
        $defaultExtractionMethod = $this->extractionMethodService->defaultMethodForContext($tenant, $brand);

        $payload = [
            'status' => $row->status,
            'extraction_session_id' => $row->id,
            'error_message' => $row->error_message,
            'candidates' => $candidates,
            'extraction_method' => is_array($row->metadata) ? ($row->metadata['extraction_method'] ?? null) : null,
            'default_extraction_method' => $defaultExtractionMethod,
            'available_methods' => $availableMethods,
            'provider_capabilities' => $this->providerCapabilitiesForSession($row),
        ];

        if ($row->status === StudioLayerExtractionSession::STATUS_FAILED) {
            $payload = array_merge($payload, $this->extractionService->extractionFailureFieldsForApi($row));
        }

        return response()->json($payload);
    }

    /**
     * GET /app/studio/documents/{document}/layers/{layer}/extract-layers/options
     */
    public function extractOptions(Request $request, int $document, string $layer): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }
        Gate::authorize('create', StudioAnimationJob::class);
        $composition = $this->resolveComposition($document, $tenant, $brand, $user);
        if ($composition === null) {
            return response()->json(['message' => 'Composition not found.'], 404);
        }
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layers = $doc['layers'] ?? [];
        if ($this->findLayer($layers, $layer) === null) {
            return response()->json(['message' => 'Layer not found.'], 404);
        }

        return response()->json([
            'default_extraction_method' => $this->extractionMethodService->defaultMethodForContext($tenant, $brand),
            'available_methods' => $this->extractionMethodService->buildAvailableMethods($tenant, $brand, $this->aiUsageService),
        ]);
    }

    /**
     * GET /app/studio/layer-extraction-sessions/{session}/candidates/{candidate}/preview
     */
    public function preview(Request $request, string $session, string $candidate): Response|StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            abort(422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            abort(404);
        }

        $stored = json_decode((string) $row->candidates_json, true);
        if (! is_array($stored)) {
            abort(404);
        }
        $rel = null;
        foreach ($stored as $c) {
            if ((string) ($c['id'] ?? '') === $candidate) {
                $rel = (string) ($c['preview_relative'] ?? '');
                break;
            }
        }
        if ($rel === null || $rel === '') {
            abort(404);
        }

        $disk = Storage::disk('studio_layer_extraction');
        if (! $disk->exists($rel)) {
            abort(404);
        }

        return response()->stream(function () use ($disk, $rel) {
            echo $disk->get($rel);
        }, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=120',
        ]);
    }

    /**
     * POST /app/studio/documents/{document}/layers/{layer}/extract-layers/confirm
     */
    public function confirm(Request $request, int $document, string $layer): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $composition = $this->resolveComposition($document, $tenant, $brand, $user);
        if ($composition === null) {
            return response()->json(['message' => 'Composition not found.'], 404);
        }

        $validated = $request->validate([
            'extraction_session_id' => 'required|uuid',
            'candidate_ids' => 'required_without:selected_candidate_ids|array|min:1',
            'candidate_ids.*' => 'string|max:128',
            'selected_candidate_ids' => 'required_without:candidate_ids|array|min:1',
            'selected_candidate_ids.*' => 'string|max:128',
            'keep_original_visible' => 'sometimes|boolean',
            'create_filled_background' => 'sometimes|boolean',
            'hide_original_after_extraction' => 'sometimes|boolean',
            'layer_names' => 'sometimes|array',
            'layer_names.*' => 'nullable|string|max:255',
        ]);
        $candidateIds = $validated['selected_candidate_ids'] ?? $validated['candidate_ids'];

        $session = StudioLayerExtractionSession::query()
            ->whereKey($validated['extraction_session_id'])
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->where('composition_id', $composition->id)
            ->first();
        if ($session === null) {
            return response()->json(['message' => 'Extraction session not found.'], 404);
        }

        try {
            $out = $this->confirmService->confirm(
                $composition->fresh(),
                $session,
                $layer,
                $candidateIds,
                (bool) ($validated['keep_original_visible'] ?? true),
                (bool) ($validated['create_filled_background'] ?? false),
                (bool) ($validated['hide_original_after_extraction'] ?? false),
                is_array($validated['layer_names'] ?? null) ? $validated['layer_names'] : [],
                $tenant,
                $brand,
                $user,
            );
        } catch (PlanLimitExceededException $e) {
            if ($e->limitType === PlanLimitExceededException::LIMIT_AI_CREDITS) {
                return response()->json(['message' => 'Not enough AI credits for background fill.'], 402);
            }

            return response()->json($e->toApiArray(), 429);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('[studio_layer_extraction] confirm_failed', [
                'document_id' => $document,
                'layer_id' => $layer,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not create layers from extraction.'], 500);
        }

        return response()->json($out);
    }

    private function purgeExpiredSessions(): void
    {
        $disk = Storage::disk('studio_layer_extraction');
        $rows = StudioLayerExtractionSession::query()
            ->where('expires_at', '<', now())
            ->whereIn('status', [
                StudioLayerExtractionSession::STATUS_PENDING,
                StudioLayerExtractionSession::STATUS_READY,
                StudioLayerExtractionSession::STATUS_FAILED,
            ])
            ->limit(200)
            ->get();
        foreach ($rows as $row) {
            try {
                $disk->deleteDirectory(StudioLayerExtractionStoragePaths::sessionDirectory($row->id));
            } catch (\Throwable) {
            }
            $row->delete();
        }
    }

    private function estimateAssetPixels(Asset $asset): int
    {
        $w = (int) ($asset->width ?? 0);
        $h = (int) ($asset->height ?? 0);
        if ($w > 0 && $h > 0) {
            return $w * $h;
        }

        return 0;
    }

    private function resolveComposition(int $document, Tenant $tenant, Brand $brand, User $user): ?Composition
    {
        return Composition::query()
            ->where('id', $document)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return array<string, mixed>|null
     */
    private function findLayer(array $layers, string $id): ?array
    {
        foreach ($layers as $l) {
            if ((string) ($l['id'] ?? '') === $id) {
                return $l;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function resolveRasterAssetId(array $layer): ?string
    {
        $type = (string) ($layer['type'] ?? '');
        if ($type === 'image') {
            $aid = trim((string) ($layer['assetId'] ?? ''));

            return $aid !== '' ? $aid : null;
        }
        if ($type === 'generative_image') {
            $aid = trim((string) ($layer['resultAssetId'] ?? ''));

            return $aid !== '' ? $aid : null;
        }

        return null;
    }

    /**
     * @return array{
     *   supports_multiple_masks: bool,
     *   supports_background_fill: bool,
     *   supports_labels: bool,
     *   supports_confidence: bool
     * }
     */
    private function providerCapabilitiesForSession(StudioLayerExtractionSession $session): array
    {
        return $this->extractionService->providerCapabilities($session);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/pick
     */
    public function pick(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $validated = $request->validate([
            'x' => 'required|numeric|min:0|max:1',
            'y' => 'required|numeric|min:0|max:1',
        ]);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        try {
            $out = $this->extractionService->appendPointPickCandidate(
                $row,
                (float) $validated['x'],
                (float) $validated['y']
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('[studio_layer_extraction] pick_failed', [
                'session' => $session,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Point pick could not be completed.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh !== null) {
            $out['provider_capabilities'] = $this->providerCapabilitiesForSession($fresh);
        }

        return response()->json($out);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/box
     *
     * TODO: future SAM (or similar) provider: same payload/response; implement {@see StudioLayerExtractionBoxPickProviderInterface} on that provider.
     */
    public function box(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $validated = $request->validate([
            'box' => 'required|array',
            'box.x' => 'required|numeric|min:0|max:1',
            'box.y' => 'required|numeric|min:0|max:1',
            'box.width' => 'required|numeric|min:0.0001|max:1',
            'box.height' => 'required|numeric|min:0.0001|max:1',
            'mode' => 'required|in:object,text_graphic',
        ]);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        $bx = (float) $validated['box']['x'];
        $by = (float) $validated['box']['y'];
        $bw = (float) $validated['box']['width'];
        $bh = (float) $validated['box']['height'];
        if ($bx + $bw > 1.0 + 1.0e-6 || $by + $bh > 1.0 + 1.0e-6) {
            return response()->json(['message' => 'Box must fit within the image.'], 422);
        }
        if (! $this->extractionService->sessionSupportsBoxPick($row)) {
            return response()->json(['message' => 'Box selection is not available for the current provider.'], 422);
        }

        try {
            $out = $this->extractionService->appendBoxPickCandidate(
                $row,
                [
                    'x' => $bx,
                    'y' => $by,
                    'width' => $bw,
                    'height' => $bh,
                ],
                (string) $validated['mode']
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('[studio_layer_extraction] box_failed', [
                'session' => $session,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Box selection could not be completed.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh !== null) {
            $out['provider_capabilities'] = $this->providerCapabilitiesForSession($fresh);
        }

        return response()->json($out);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/clear-manual-candidates
     */
    public function clearManualCandidates(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        try {
            $this->extractionService->clearManualCandidates($row);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not clear manual candidates.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $stored = json_decode((string) $fresh->candidates_json, true);
        $candidates = is_array($stored) ? $this->extractionService->decorateCandidatesForApi($fresh, $stored) : [];

        return response()->json([
            'status' => $fresh->status,
            'extraction_session_id' => $fresh->id,
            'candidates' => $candidates,
            'provider_capabilities' => $this->providerCapabilitiesForSession($fresh),
        ]);
    }

    /**
     * DELETE /app/studio/layer-extraction-sessions/{session}/candidates/{candidate}
     */
    public function removeCandidate(Request $request, string $session, string $candidate): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        try {
            $this->extractionService->removeCandidate($row, $candidate);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not remove candidate.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $stored = json_decode((string) $fresh->candidates_json, true);
        $candidates = is_array($stored) ? $this->extractionService->decorateCandidatesForApi($fresh, $stored) : [];

        return response()->json([
            'status' => $fresh->status,
            'extraction_session_id' => $fresh->id,
            'candidates' => $candidates,
            'provider_capabilities' => $this->providerCapabilitiesForSession($fresh),
        ]);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/clear-picks
     */
    public function clearPicks(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        try {
            $this->extractionService->clearPickedCandidates($row);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not clear picks.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $stored = json_decode((string) $fresh->candidates_json, true);
        $candidates = is_array($stored) ? $this->extractionService->decorateCandidatesForApi($fresh, $stored) : [];

        return response()->json([
            'status' => $fresh->status,
            'extraction_session_id' => $fresh->id,
            'candidates' => $candidates,
            'provider_capabilities' => $this->providerCapabilitiesForSession($fresh),
        ]);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/candidates/{candidate}/refine
     */
    public function refineCandidate(Request $request, string $session, string $candidate): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $validated = $request->validate([
            'negative_point' => 'sometimes|array',
            'negative_point.x' => 'required_with:negative_point|numeric|min:0|max:1',
            'negative_point.y' => 'required_with:negative_point|numeric|min:0|max:1',
            'positive_point' => 'sometimes|array',
            'positive_point.x' => 'required_with:positive_point|numeric|min:0|max:1',
            'positive_point.y' => 'required_with:positive_point|numeric|min:0|max:1',
        ]);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        if (! isset($validated['negative_point']) && ! isset($validated['positive_point'])) {
            return response()->json(['message' => 'Send negative_point (exclude) or positive_point (include more).'], 422);
        }
        if (isset($validated['negative_point'], $validated['positive_point'])) {
            return response()->json(['message' => 'Send only one of negative_point or positive_point.'], 422);
        }

        try {
            if (isset($validated['positive_point'])) {
                $out = $this->extractionService->appendRefinePositivePoint(
                    $row,
                    $candidate,
                    (float) $validated['positive_point']['x'],
                    (float) $validated['positive_point']['y'],
                );
            } else {
                $out = $this->extractionService->appendRefineNegativePoint(
                    $row,
                    $candidate,
                    (float) $validated['negative_point']['x'],
                    (float) $validated['negative_point']['y'],
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('[studio_layer_extraction] refine_failed', [
                'session' => $session,
                'candidate' => $candidate,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Refinement could not be completed.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh !== null) {
            $out['provider_capabilities'] = $this->providerCapabilitiesForSession($fresh);
        }

        return response()->json($out);
    }

    /**
     * POST /app/studio/layer-extraction-sessions/{session}/candidates/{candidate}/reset-refine
     */
    public function resetCandidateRefine(Request $request, string $session, string $candidate): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant instanceof Tenant || ! $brand) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('create', StudioAnimationJob::class);

        $row = StudioLayerExtractionSession::query()
            ->whereKey($session)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'Session expired.'], 410);
        }

        try {
            $out = $this->extractionService->resetPickCandidateRefine($row, $candidate);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('[studio_layer_extraction] reset_refine_failed', [
                'session' => $session,
                'candidate' => $candidate,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not reset refinement.'], 500);
        }

        $fresh = StudioLayerExtractionSession::query()
            ->whereKey($row->id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();
        if ($fresh !== null) {
            $out['provider_capabilities'] = $this->providerCapabilitiesForSession($fresh);
        }

        return response()->json($out);
    }
}
