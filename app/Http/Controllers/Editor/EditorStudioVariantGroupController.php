<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\StudioVariantGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EditorStudioVariantGroupController extends Controller
{
    /**
     * GET /app/api/compositions/{compositionId}/variant-groups
     */
    public function forComposition(Request $request, int $compositionId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (! config('studio.variant_groups_v1', false)) {
            return response()->json(['variant_groups' => [], 'meta' => ['feature_enabled' => false]]);
        }
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return response()->json(['error' => 'No workspace'], 400);
        }
        $composition = Composition::query()
            ->where('id', $compositionId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->visibleToUser($user)
            ->first();
        if (! $composition) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $groups = StudioVariantGroup::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('source_composition_id', $composition->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('members.composition')
            ->get();
        $payload = $groups
            ->filter(fn (StudioVariantGroup $g) => Gate::forUser($user)->allows('view', $g))
            ->map(fn (StudioVariantGroup $g) => $this->groupJson($g))
            ->values()
            ->all();

        return response()->json([
            'variant_groups' => $payload,
            'meta' => [
                'feature_enabled' => true,
                'source_composition_id' => (string) $composition->id,
            ],
        ]);
    }

    /**
     * GET /app/api/creative-sets/{id}/variant-groups
     */
    public function forCreativeSet(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (! config('studio.variant_groups_v1', false)) {
            return response()->json(['variant_groups' => [], 'meta' => ['feature_enabled' => false]]);
        }
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return response()->json(['error' => 'No workspace'], 400);
        }
        $set = CreativeSet::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();
        if (! $set) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $user->brands()->where('brands.id', $set->brand_id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $groups = StudioVariantGroup::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('creative_set_id', $set->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('members.composition')
            ->get();
        $payload = $groups
            ->filter(fn (StudioVariantGroup $g) => Gate::forUser($user)->allows('view', $g))
            ->map(fn (StudioVariantGroup $g) => $this->groupJson($g))
            ->values()
            ->all();

        return response()->json([
            'variant_groups' => $payload,
            'meta' => [
                'feature_enabled' => true,
                'creative_set_id' => (string) $set->id,
            ],
        ]);
    }

    private function groupJson(StudioVariantGroup $g): array
    {
        $g->loadMissing('members.composition');

        return [
            'id' => (string) $g->id,
            'uuid' => (string) $g->uuid,
            'type' => $g->type instanceof \BackedEnum ? $g->type->value : (string) $g->type,
            'label' => $g->label,
            'status' => $g->status,
            'source_composition_id' => (string) $g->source_composition_id,
            'source_composition_version_id' => $g->source_composition_version_id !== null ? (string) $g->source_composition_version_id : null,
            'creative_set_id' => $g->creative_set_id !== null ? (string) $g->creative_set_id : null,
            'settings_json' => $g->settings_json ?? (object) [],
            'target_spec_json' => $g->target_spec_json ?? null,
            'shared_mask_asset_id' => $g->shared_mask_asset_id !== null ? (string) $g->shared_mask_asset_id : null,
            'sort_order' => (int) $g->sort_order,
            'members' => $g->members->map(function ($m) {
                return [
                    'id' => (string) $m->id,
                    'composition_id' => $m->composition_id !== null ? (string) $m->composition_id : null,
                    'slot_key' => $m->slot_key,
                    'label' => $m->label,
                    'status' => $m->status,
                    'generation_status' => $m->generation_status,
                    'spec_json' => $m->spec_json ?? (object) [],
                    'generation_job_item_id' => $m->generation_job_item_id !== null ? (string) $m->generation_job_item_id : null,
                    'studio_variant_group_member_id' => (string) $m->id,
                    'result_asset_id' => $m->result_asset_id !== null ? (string) $m->result_asset_id : null,
                    'sort_order' => (int) $m->sort_order,
                ];
            })->values()->all(),
        ];
    }
}
