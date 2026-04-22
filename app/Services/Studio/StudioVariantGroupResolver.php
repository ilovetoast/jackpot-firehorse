<?php

namespace App\Services\Studio;

use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\StudioVariantGroup;

/**
 * Merges persisted variant groups with a legacy "virtual" view for ungrouped rail variants.
 */
final class StudioVariantGroupResolver
{
    /**
     * @return list<array<string, mixed>>
     */
    public function groupsForCreativeSet(int $tenantId, int $brandId, CreativeSet $set): array
    {
        if (! config('studio.variant_groups_v1', false)) {
            return [];
        }

        return StudioVariantGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->where('creative_set_id', $set->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('members.composition')
            ->get()
            ->map(function (StudioVariantGroup $g) {
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
                    'target_spec_json' => $g->target_spec_json,
                    'shared_mask_asset_id' => $g->shared_mask_asset_id !== null ? (string) $g->shared_mask_asset_id : null,
                    'sort_order' => (int) $g->sort_order,
                    'member_count' => $g->members->count(),
                    'members' => $g->members->map(static function ($m) {
                        return [
                            'id' => (string) $m->id,
                            'composition_id' => $m->composition_id !== null ? (string) $m->composition_id : null,
                            'slot_key' => $m->slot_key,
                            'label' => $m->label,
                            'status' => $m->status,
                            'generation_status' => $m->generation_status,
                            'spec_json' => $m->spec_json ?? (object) [],
                            'generation_job_item_id' => $m->generation_job_item_id !== null ? (string) $m->generation_job_item_id : null,
                            'result_asset_id' => $m->result_asset_id !== null ? (string) $m->result_asset_id : null,
                            'sort_order' => (int) $m->sort_order,
                        ];
                    })->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * Legacy variants without a {@code studio_variant_group_id} are treated as a synthetic generic bucket for the UI.
     */
    public function virtualLegacyUngroupedLabel(CreativeSetVariant $v): ?string
    {
        if ($v->studio_variant_group_id !== null) {
            return null;
        }
        $axis = is_array($v->axis) ? $v->axis : [];

        return isset($axis['combination_key']) ? 'Ungrouped (legacy)' : 'Ungrouped';
    }
}
