<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Composition;
use App\Models\StudioAnimationJob;
use App\Models\Tenant;
use App\Models\User;
use App\Studio\Animation\Analysis\CompositionAnimationPreflightAnalyzer;
use App\Studio\Animation\Data\CreateStudioAnimationData;
use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;
use App\Studio\Animation\Services\StudioAnimationService;
use App\Studio\Animation\Support\AnimationCapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StudioAnimationController extends Controller
{
    public function __construct(
        protected StudioAnimationService $studioAnimationService,
    ) {}

    public function index(Request $request, int $document): JsonResponse
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

        $jobs = $this->studioAnimationService->listForComposition($document, $tenant, (int) $brand->id, $user);

        return response()->json([
            'animations' => $jobs->map(fn (StudioAnimationJob $j) => $this->studioAnimationService->toApiPayload($j))->values()->all(),
        ]);
    }

    public function preflight(Request $request, int $document): JsonResponse
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
            'document_json' => 'nullable|array',
            'canvas_width' => 'required|integer|min:16|max:8192',
            'canvas_height' => 'required|integer|min:16|max:8192',
        ]);

        $exists = Composition::query()
            ->where('id', $document)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->exists();
        if (! $exists) {
            return response()->json(['message' => 'Composition not found.'], 404);
        }

        $doc = $validated['document_json'] ?? null;
        $risk = (new CompositionAnimationPreflightAnalyzer)->analyze(
            is_array($doc) ? $doc : null,
            (int) $validated['canvas_width'],
            (int) $validated['canvas_height'],
        );

        return response()->json(['preflight' => $risk]);
    }

    public function store(Request $request, int $document): JsonResponse
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
            'provider' => 'required|string|max:64',
            'provider_model' => 'required|string|max:128',
            'source_strategy' => 'required|string|max:48',
            'prompt' => 'nullable|string|max:4000',
            'negative_prompt' => 'nullable|string|max:2000',
            'motion_preset' => 'nullable|string|max:128',
            'duration_seconds' => 'required|integer|min:3|max:15',
            'aspect_ratio' => 'required|string|max:16',
            'generate_audio' => 'sometimes|boolean',
            'composition_snapshot_png_base64' => 'required|string|max:12000000',
            'snapshot_width' => 'required|integer|min:16|max:8192',
            'snapshot_height' => 'required|integer|min:16|max:8192',
            'document_json' => 'nullable|array',
            'source_composition_version_id' => [
                'nullable',
                'integer',
                Rule::exists('composition_versions', 'id')->where('composition_id', $document),
            ],
            'high_fidelity_submit' => 'sometimes|boolean',
        ]);

        if ($validated['source_strategy'] !== StudioAnimationSourceStrategy::CompositionSnapshot->value) {
            return response()->json(['message' => 'Unsupported source strategy.'], 422);
        }

        $exists = Composition::query()
            ->where('id', $document)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->exists();
        if (! $exists) {
            return response()->json(['message' => 'Composition not found.'], 404);
        }

        $doc = $validated['document_json'] ?? null;

        $data = new CreateStudioAnimationData(
            tenantId: $tenant->id,
            brandId: (int) $brand->id,
            userId: $user->id,
            compositionId: $document,
            provider: (string) $validated['provider'],
            providerModel: (string) $validated['provider_model'],
            sourceStrategy: StudioAnimationSourceStrategy::CompositionSnapshot,
            prompt: $validated['prompt'] ?? null,
            negativePrompt: $validated['negative_prompt'] ?? null,
            motionPreset: $validated['motion_preset'] ?? null,
            durationSeconds: (int) $validated['duration_seconds'],
            aspectRatio: (string) $validated['aspect_ratio'],
            generateAudio: (bool) ($validated['generate_audio'] ?? false),
            compositionSnapshotPngBase64: (string) $validated['composition_snapshot_png_base64'],
            snapshotWidth: (int) $validated['snapshot_width'],
            snapshotHeight: (int) $validated['snapshot_height'],
            documentJson: is_array($doc) ? $doc : null,
            sourceCompositionVersionId: isset($validated['source_composition_version_id'])
                ? (int) $validated['source_composition_version_id']
                : null,
            highFidelitySubmit: (bool) ($validated['high_fidelity_submit'] ?? false),
            settings: [
                'capabilities' => AnimationCapabilityRegistry::forProvider((string) $validated['provider']),
            ],
        );

        try {
            $job = $this->studioAnimationService->create($data, $tenant, $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json($this->studioAnimationService->toApiPayload($job), 201);
    }

    public function show(Request $request, StudioAnimationJob $animationJob): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        Gate::authorize('view', $animationJob);

        return response()->json($this->studioAnimationService->toApiPayload($animationJob));
    }

    public function retry(Request $request, StudioAnimationJob $animationJob): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        Gate::authorize('retry', $animationJob);

        try {
            $this->studioAnimationService->retry($animationJob, $tenant, $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        $animationJob->refresh();

        return response()->json($this->studioAnimationService->toApiPayload($animationJob));
    }

    public function cancel(Request $request, StudioAnimationJob $animationJob): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        Gate::authorize('cancel', $animationJob);

        $this->studioAnimationService->cancel($animationJob);
        $animationJob->refresh();

        return response()->json($this->studioAnimationService->toApiPayload($animationJob));
    }

    public function destroy(Request $request, int $animationJob): \Illuminate\Http\Response|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required.'], 422);
        }

        $job = StudioAnimationJob::query()
            ->whereKey($animationJob)
            ->where('tenant_id', $tenant->id)
            ->first();
        if ($job === null) {
            Log::info('StudioAnimationController.destroy not_found (treated as idempotent)', [
                'requested_studio_animation_job_id' => $animationJob,
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ]);

            return response()->noContent();
        }

        Gate::authorize('delete', $job);

        try {
            $this->studioAnimationService->discard($job, $tenant);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->noContent();
    }
}
