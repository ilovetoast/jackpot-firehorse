<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\GenerationJobItem;
use App\Models\User;
use App\Services\Editor\CompositionDuplicateService;
use App\Services\Studio\CreativeSetApplyCommandsService;
use App\Services\Studio\StudioCreativeSetGenerationItemRetryService;
use App\Services\Studio\StudioCreativeSetGenerationService;
use App\Services\Studio\StudioVariantGroupResolver;
use App\Enums\StudioVariantGroupType;
use App\Models\StudioVariantGroup;
use App\Models\StudioVariantGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Studio "Versions" (product): {@link CreativeSet} + {@link CreativeSetVariant} rows wrapping {@link Composition}.
 */
class EditorCreativeSetController extends Controller
{
    /** Hard cap — combinatorial guardrail (see product spec). */
    public const MAX_VARIANTS_PER_SET = 24;

    public function __construct(
        protected CompositionDuplicateService $compositionDuplicate,
        protected StudioCreativeSetGenerationService $creativeSetGeneration,
        protected CreativeSetApplyCommandsService $applyCommands,
        protected StudioCreativeSetGenerationItemRetryService $generationItemRetry,
        protected StudioVariantGroupResolver $variantGroupResolver,
    ) {}

    private function resolveCreativeSet(Request $request, int $id): ?CreativeSet
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return null;
        }

        return CreativeSet::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();
    }

    private function resolveComposition(Request $request, int $id): ?Composition
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user instanceof User) {
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

        return route('api.editor.compositions.thumbnail-asset', ['asset' => $asset->id], true);
    }

    private function variantJson(CreativeSetVariant $v): array
    {
        $v->loadMissing('composition');

        $retryableItemId = null;
        if ($v->status === CreativeSetVariant::STATUS_FAILED) {
            $retryableItemId = GenerationJobItem::query()
                ->where('creative_set_variant_id', $v->id)
                ->where('status', GenerationJobItem::STATUS_FAILED)
                ->whereNull('superseded_at')
                ->orderByDesc('id')
                ->value('id');
        }

        $out = [
            'id' => (string) $v->id,
            'composition_id' => (string) $v->composition_id,
            'label' => $v->label,
            'sort_order' => (int) $v->sort_order,
            'status' => $v->status,
            'axis' => $v->axis ?? [],
            'thumbnail_url' => $this->resolveThumbnailUrl($v->composition?->thumbnail_asset_id),
            'retryable_generation_job_item_id' => $retryableItemId !== null ? (string) $retryableItemId : null,
        ];
        if (config('studio.variant_groups_v1', false)) {
            $out['studio_variant_group_id'] = $v->studio_variant_group_id !== null ? (string) $v->studio_variant_group_id : null;
            $out['legacy_ungrouped_label'] = $this->variantGroupResolver->virtualLegacyUngroupedLabel($v);
        }

        return $out;
    }

    private function setJson(CreativeSet $set): array
    {
        $set->loadMissing('variants.composition');
        $tenant = app('tenant');
        $brand = app('brand');
        $variantGroups = [];
        if (config('studio.variant_groups_v1', false) && $tenant && $brand) {
            $variantGroups = $this->variantGroupResolver->groupsForCreativeSet((int) $tenant->id, (int) $brand->id, $set);
        }

        $base = [
            'id' => (string) $set->id,
            'name' => $set->name,
            'status' => $set->status,
            'hero_composition_id' => $set->hero_composition_id !== null ? (string) $set->hero_composition_id : null,
            'variants' => $set->variants
                ->filter(fn (CreativeSetVariant $v) => $v->status !== CreativeSetVariant::STATUS_ARCHIVED)
                ->values()
                ->map(fn (CreativeSetVariant $v) => $this->variantJson($v))
                ->all(),
        ];
        if (config('studio.variant_groups_v1', false)) {
            $base['variant_groups'] = $variantGroups;
        }

        return $base;
    }

    private function generationJobJson(GenerationJob $job): array
    {
        $job->loadMissing('items');

        return [
            'id' => (string) $job->id,
            'creative_set_id' => (string) $job->creative_set_id,
            'status' => $job->status,
            'meta' => $job->meta ?? [],
            'items' => $job->items->map(static function (GenerationJobItem $i): array {
                return [
                    'id' => (string) $i->id,
                    'status' => $i->status,
                    'combination_key' => $i->combination_key,
                    'attempts' => (int) $i->attempts,
                    'error' => $i->error,
                    'superseded_at' => $i->superseded_at?->toIso8601String(),
                    'retried_from_item_id' => $i->retried_from_item_id !== null ? (string) $i->retried_from_item_id : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * GET /app/api/creative-sets/generation-presets
     */
    public function generationPresets(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'preset_colors' => config('studio_creative_set_generation.preset_colors', []),
            'preset_scenes' => config('studio_creative_set_generation.preset_scenes', []),
            'preset_formats' => config('studio_creative_set_generation.preset_formats', []),
            'format_pack_quick_ids' => config('studio_creative_set_generation.format_pack_quick_ids', []),
            'format_group_order' => config('studio_creative_set_generation.format_group_order', []),
            'format_group_labels' => config('studio_creative_set_generation.format_group_labels', []),
            'limits' => [
                'max_colors' => (int) config('studio_creative_set_generation.max_colors', 6),
                'max_scenes' => (int) config('studio_creative_set_generation.max_scenes', 5),
                'max_formats' => (int) config('studio_creative_set_generation.max_formats', 3),
                'max_outputs_per_request' => (int) config('studio_creative_set_generation.max_outputs_per_request', 24),
                'max_versions_per_set' => self::MAX_VARIANTS_PER_SET,
            ],
        ]);
    }

    /**
     * POST /app/api/creative-sets/{id}/generate
     */
    public function generate(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'source_composition_id' => 'required|integer',
            'color_ids' => 'sometimes|array',
            'color_ids.*' => 'string|max:64',
            'scene_ids' => 'sometimes|array',
            'scene_ids.*' => 'string|max:64',
            'format_ids' => 'sometimes|array',
            'format_ids.*' => 'string|max:64',
            'selected_combination_keys' => 'sometimes|array',
            'selected_combination_keys.*' => 'string|max:256',
        ]);

        try {
            $job = $this->creativeSetGeneration->start(
                $set,
                $user,
                (int) $validated['source_composition_id'],
                $validated['color_ids'] ?? [],
                $validated['scene_ids'] ?? [],
                $validated['format_ids'] ?? [],
                $validated['selected_combination_keys'] ?? null,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'generation_job' => $this->generationJobJson($job),
        ], 202);
    }

    /**
     * POST /app/api/creative-sets/{id}/generation-job-items/{itemId}/retry
     *
     * Re-runs a single failed output using the same combination key and existing composition/variant row.
     */
    public function retryGenerationJobItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        try {
            $out = $this->generationItemRetry->retryFailedItem($set, $user, $itemId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'generation_job' => $this->generationJobJson($out['generation_job']),
            'generation_job_item' => [
                'id' => (string) $out['generation_job_item']->id,
                'status' => $out['generation_job_item']->status,
                'combination_key' => $out['generation_job_item']->combination_key,
            ],
            'creative_set' => $this->setJson($set->fresh(['variants'])),
        ], 202);
    }

    /**
     * GET /app/api/creative-sets/{id}/generation-jobs/{jobId}
     */
    public function generationJobShow(Request $request, int $id, int $jobId): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $job = GenerationJob::query()
            ->where('id', $jobId)
            ->where('creative_set_id', $set->id)
            ->first();
        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json([
            'generation_job' => $this->generationJobJson($job),
            'creative_set' => $this->setJson($set->fresh(['variants'])),
        ]);
    }

    /**
     * PATCH /app/api/creative-sets/{id}/hero
     *
     * Marks exactly one composition in the set as the “hero” (best / export anchor), or clears it.
     */
    public function updateHero(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'composition_id' => 'nullable|integer',
        ]);

        $cid = $validated['composition_id'] ?? null;
        if ($cid !== null) {
            $member = CreativeSetVariant::query()
                ->where('creative_set_id', $set->id)
                ->where('composition_id', (int) $cid)
                ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
                ->exists();
            if (! $member) {
                throw ValidationException::withMessages([
                    'composition_id' => 'That composition is not in this Versions set.',
                ]);
            }
        }

        $set->hero_composition_id = $cid !== null ? (int) $cid : null;
        $set->save();

        return response()->json(['creative_set' => $this->setJson($set->fresh(['variants']))]);
    }

    /**
     * GET /app/api/creative-sets/for-composition/{compositionId}
     *
     * Returns the Creative Set (if any) that contains this composition as a variant.
     */
    public function forComposition(Request $request, int $compositionId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $composition = $this->resolveComposition($request, $compositionId);
        if (! $composition) {
            return response()->json(['error' => 'Not found', 'creative_set' => null], 404);
        }

        $link = CreativeSetVariant::query()
            ->where('composition_id', $composition->id)
            ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
            ->with('creativeSet')
            ->first();

        if (! $link || ! $link->creativeSet) {
            return response()->json(['creative_set' => null]);
        }

        $set = $this->resolveCreativeSet($request, (int) $link->creative_set_id);
        if (! $set) {
            return response()->json(['creative_set' => null]);
        }

        return response()->json(['creative_set' => $this->setJson($set->fresh(['variants']))]);
    }

    /**
     * GET /app/api/creative-sets/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['creative_set' => $this->setJson($set)]);
    }

    /**
     * POST /app/api/creative-sets
     *
     * Wrap the current composition as the first variant in a new Creative Set.
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
            'composition_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
        ]);

        $composition = $this->resolveComposition($request, (int) $validated['composition_id']);
        if (! $composition) {
            return response()->json(['error' => 'Composition not found'], 404);
        }

        if (CreativeSetVariant::query()
            ->where('composition_id', $composition->id)
            ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
            ->exists()) {
            return response()->json(['error' => 'This composition already belongs to a Versions set.'], 422);
        }

        $name = $validated['name'] ?? ($composition->name.' — Versions');

        $set = DB::transaction(function () use ($tenant, $brand, $user, $composition, $name): CreativeSet {
            $set = CreativeSet::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'name' => $name,
                'status' => CreativeSet::STATUS_ACTIVE,
            ]);

            CreativeSetVariant::query()->create([
                'creative_set_id' => $set->id,
                'composition_id' => $composition->id,
                'sort_order' => 0,
                'label' => $composition->name,
                'status' => CreativeSetVariant::STATUS_READY,
                'axis' => null,
            ]);

            return $set->fresh(['variants']);
        });

        return response()->json(['creative_set' => $this->setJson($set)], 201);
    }

    /**
     * POST /app/api/creative-sets/{id}/variants
     *
     * Duplicate `source_composition_id` (must be a member of this set) into a new composition + variant row.
     */
    public function storeVariant(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'source_composition_id' => 'required|integer',
            'label' => 'nullable|string|max:255',
        ]);

        $sourceComposition = $this->resolveComposition($request, (int) $validated['source_composition_id']);
        if (! $sourceComposition) {
            return response()->json(['error' => 'Source composition not found'], 404);
        }

        $membership = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->where('composition_id', $sourceComposition->id)
            ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
            ->first();
        if (! $membership) {
            return response()->json(['error' => 'Source composition is not in this Versions set.'], 422);
        }

        $count = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
            ->count();
        if ($count >= self::MAX_VARIANTS_PER_SET) {
            return response()->json([
                'error' => 'Maximum number of versions in one set reached ('.self::MAX_VARIANTS_PER_SET.').',
            ], 422);
        }

        $label = $validated['label'] ?? ($sourceComposition->name.' (copy)');
        $newName = $label;

        $variant = DB::transaction(function () use ($set, $sourceComposition, $user, $newName, $label, $count): CreativeSetVariant {
            $newComposition = $this->compositionDuplicate->duplicate($sourceComposition, $user, $newName, 'Duplicated');

            return CreativeSetVariant::query()->create([
                'creative_set_id' => $set->id,
                'composition_id' => $newComposition->id,
                'sort_order' => $count,
                'label' => $label,
                'status' => CreativeSetVariant::STATUS_READY,
                'axis' => null,
            ]);
        });

        if (config('studio.variant_groups_v1', false) && config('studio.auto_generic_group', false)) {
            $this->attachGenericVariantGroupForDuplicate($set, $user, $sourceComposition, $variant);
        }

        return response()->json([
            'variant' => $this->variantJson($variant->fresh()->load('composition')),
            'creative_set' => $this->setJson($set->fresh(['variants'])),
        ], 201);
    }

    /**
     * DELETE /app/api/creative-sets/{id}/variants/{variantId}
     *
     * Removes the variant membership row. Does not delete the {@link Composition} itself.
     */
    public function destroyVariant(Request $request, int $id, int $variantId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $variant = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->where('id', $variantId)
            ->first();
        if (! $variant) {
            return response()->json(['error' => 'Variant not found'], 404);
        }

        $members = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($members->count() < 2) {
            return response()->json(['error' => 'You cannot remove the only version in this set.'], 422);
        }

        $base = $members->first();
        if ($base !== null && (int) $variant->id === (int) $base->id) {
            return response()->json(['error' => 'You cannot remove the base version. Duplicate another version first, then remove this one if needed.'], 422);
        }

        DB::transaction(function () use ($set, $variant): void {
            if ($set->hero_composition_id !== null && (int) $set->hero_composition_id === (int) $variant->composition_id) {
                $set->hero_composition_id = null;
                $set->save();
            }
            $variant->delete();
        });

        return response()->json(['creative_set' => $this->setJson($set->fresh(['variants']))]);
    }

    /**
     * DELETE /app/api/creative-sets/{id}
     *
     * Removes the Versions set and all variant link rows. Does not delete the underlying
     * {@link Composition} records; they remain in the brand library. Cascades to related
     * generation job rows. Studio variant groups keep their row but get `creative_set_id` nulled.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $set->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/api/creative-sets/{id}/apply
     *
     * Semantic multi-version edits: `patch_layer` commands mapped from the source composition
     * onto each sibling composition (same z-stack / name fallback).
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'source_composition_id' => 'required|integer',
            'scope' => 'nullable|string|in:all_versions,selected_versions',
            'target_composition_ids' => 'nullable|array|max:'.self::MAX_VARIANTS_PER_SET,
            'target_composition_ids.*' => 'integer',
            'commands' => 'required|array|min:1|max:'.CreativeSetApplyCommandsService::MAX_COMMANDS,
            'commands.*' => 'required|array',
            'commands.*.type' => 'required|string|in:update_text_content,update_layer_visibility,update_text_alignment,update_role_transform',
        ]);

        $scope = (string) ($validated['scope'] ?? 'all_versions');
        try {
            $onlySiblings = $this->resolveApplySiblingCompositionIds(
                $set,
                (int) $validated['source_composition_id'],
                $scope,
                $validated['target_composition_ids'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        try {
            $result = $this->applyCommands->applyToAllVariants(
                $set->fresh(['variants']),
                $user,
                (int) $validated['source_composition_id'],
                $validated['commands'],
                $onlySiblings,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'ok' => true,
            'scope' => $scope,
            'updated_composition_ids' => $result['updated'],
            'skipped' => $result['skipped'],
            'skipped_by_reason' => $result['skipped_by_reason'],
            'sibling_compositions_targeted' => $result['sibling_compositions_targeted'],
            'sibling_compositions_updated' => $result['sibling_compositions_updated'],
            'commands_applied' => $result['commands_applied'],
            'creative_set' => $this->setJson($set->fresh(['variants'])),
        ]);
    }

    /**
     * POST /app/api/creative-sets/{id}/apply-preview
     *
     * Dry-run sibling apply: same validation as {@see apply} with structured skip reasons and counts
     * for confirm-dialog copy (no documents persisted).
     */
    public function applyPreview(Request $request, int $id): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $set = $this->resolveCreativeSet($request, $id);
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'source_composition_id' => 'required|integer',
            'scope' => 'nullable|string|in:all_versions,selected_versions',
            'target_composition_ids' => 'nullable|array|max:'.self::MAX_VARIANTS_PER_SET,
            'target_composition_ids.*' => 'integer',
            'commands' => 'required|array|min:1|max:'.CreativeSetApplyCommandsService::MAX_COMMANDS,
            'commands.*' => 'required|array',
            'commands.*.type' => 'required|string|in:update_text_content,update_layer_visibility,update_text_alignment,update_role_transform',
        ]);

        $scope = (string) ($validated['scope'] ?? 'all_versions');
        try {
            $onlySiblings = $this->resolveApplySiblingCompositionIds(
                $set,
                (int) $validated['source_composition_id'],
                $scope,
                $validated['target_composition_ids'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        try {
            $result = $this->applyCommands->previewApplyToAllVariants(
                $set->fresh(['variants']),
                $user,
                (int) $validated['source_composition_id'],
                $validated['commands'],
                $onlySiblings,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'ok' => true,
            'scope' => $scope,
            'skipped' => $result['skipped'],
            'skipped_by_reason' => $result['skipped_by_reason'],
            'sibling_compositions_targeted' => $result['sibling_compositions_targeted'],
            'sibling_compositions_eligible' => $result['sibling_compositions_eligible'],
            'sibling_compositions_would_skip' => $result['sibling_compositions_would_skip'],
            'commands_considered' => $result['commands_considered'],
        ]);
    }

    /**
     * @param  array<int, mixed>|null  $targetCompositionIds
     * @return list<int>|null null = all siblings; non-empty list = restricted subset
     */
    private function resolveApplySiblingCompositionIds(
        CreativeSet $set,
        int $sourceCompositionId,
        string $scope,
        ?array $targetCompositionIds,
    ): ?array {
        if ($scope === 'all_versions') {
            return null;
        }

        if ($scope !== 'selected_versions') {
            throw ValidationException::withMessages([
                'scope' => 'Apply scope must be all_versions or selected_versions.',
            ]);
        }

        if (! is_array($targetCompositionIds) || $targetCompositionIds === []) {
            throw ValidationException::withMessages([
                'target_composition_ids' => 'Select at least one target version.',
            ]);
        }

        $members = $set->variants()
            ->pluck('composition_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $memberFlip = array_fill_keys($members, true);

        $out = [];
        $seen = [];
        foreach ($targetCompositionIds as $raw) {
            $id = (int) $raw;
            if ($id === $sourceCompositionId) {
                continue;
            }
            if (! isset($memberFlip[$id])) {
                throw ValidationException::withMessages([
                    'target_composition_ids' => "Composition {$id} is not in this creative set.",
                ]);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'target_composition_ids' => 'Select at least one other version in this set (the open composition is already the source).',
            ]);
        }

        return $out;
    }

    private function attachGenericVariantGroupForDuplicate(
        CreativeSet $set,
        User $user,
        Composition $sourceComposition,
        CreativeSetVariant $newVariant,
    ): void {
        try {
            DB::transaction(function () use ($set, $user, $sourceComposition, $newVariant): void {
                $newComposition = $newVariant->composition;
                if (! $newComposition) {
                    return;
                }
                $sort = (int) StudioVariantGroup::query()
                    ->where('creative_set_id', $set->id)
                    ->max('sort_order') + 1;
                $group = StudioVariantGroup::query()->create([
                    'tenant_id' => $set->tenant_id,
                    'brand_id' => $set->brand_id,
                    'source_composition_id' => $sourceComposition->id,
                    'source_composition_version_id' => null,
                    'creative_set_id' => $set->id,
                    'type' => StudioVariantGroupType::Generic,
                    'label' => 'Duplicate',
                    'status' => StudioVariantGroup::STATUS_ACTIVE,
                    'settings_json' => ['origin' => 'manual_duplicate', 'inferred' => true],
                    'target_spec_json' => null,
                    'shared_mask_asset_id' => null,
                    'sort_order' => $sort,
                    'created_by_user_id' => $user->id,
                ]);
                StudioVariantGroupMember::query()->create([
                    'studio_variant_group_id' => $group->id,
                    'composition_id' => $newComposition->id,
                    'slot_key' => 'duplicate',
                    'label' => $newVariant->label ?? 'Copy',
                    'status' => StudioVariantGroupMember::STATUS_ACTIVE,
                    'generation_status' => null,
                    'spec_json' => ['source_composition_id' => (string) $sourceComposition->id],
                    'generation_job_item_id' => null,
                    'sort_order' => 0,
                ]);
                $newVariant->update(['studio_variant_group_id' => $group->id]);
            });
        } catch (\Throwable) {
            /* best-effort; duplicate flow must not fail */
        }
    }
}
