<?php

namespace App\Services\Studio;

use App\Enums\StudioVariantGroupType;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\GenerationJob;
use App\Models\StudioVariantGroup;
use App\Models\User;

/**
 * Binds a {@link GenerationJob} to a new {@link StudioVariantGroup} when {@code config('studio.variant_groups_v1')} is on.
 */
final class StudioVariantGroupBinder
{
    /**
     * @param  array<int, string>  $colorIds
     * @param  array<int, string>  $sceneIds
     * @param  array<int, string>  $formatIds
     */
    public function bindForGeneration(
        CreativeSet $set,
        User $user,
        GenerationJob $job,
        Composition $baseline,
        array $colorIds,
        array $sceneIds,
        array $formatIds,
    ): void {
        if (! config('studio.variant_groups_v1', false)) {
            return;
        }

        $type = $this->inferType($colorIds, $sceneIds, $formatIds);
        $label = $this->suggestGroupLabel($type);

        $group = StudioVariantGroup::query()->create([
            'tenant_id' => $set->tenant_id,
            'brand_id' => $set->brand_id,
            'source_composition_id' => $baseline->id,
            'source_composition_version_id' => $baseline->versions()->orderByDesc('id')->value('id'),
            'creative_set_id' => $set->id,
            'type' => $type,
            'label' => $label,
            'status' => StudioVariantGroup::STATUS_ACTIVE,
            'settings_json' => [
                'source_generation_job_id' => (int) $job->id,
                'inferred' => true,
            ],
            'target_spec_json' => [
                'color_ids' => $colorIds,
                'scene_ids' => $sceneIds,
                'format_ids' => $formatIds,
            ],
            'shared_mask_asset_id' => null,
            'sort_order' => 0,
            'created_by_user_id' => $user->id,
        ]);

        $meta = is_array($job->meta) ? $job->meta : [];
        $meta['studio_variant_group_id'] = (int) $group->id;
        $meta['variant_family_type'] = $type->value;
        $job->meta = $meta;
        $job->save();
    }

    private function inferType(array $colorIds, array $sceneIds, array $formatIds): StudioVariantGroupType
    {
        $hasC = $colorIds !== [];
        $hasS = $sceneIds !== [];
        $hasF = $formatIds !== [];

        if ($hasC && ! $hasS && ! $hasF) {
            return StudioVariantGroupType::Color;
        }
        if ($hasF && ! $hasC && ! $hasS) {
            return StudioVariantGroupType::LayoutSize;
        }

        return StudioVariantGroupType::Generic;
    }

    private function suggestGroupLabel(StudioVariantGroupType $type): string
    {
        return match ($type) {
            StudioVariantGroupType::Color => 'Color set',
            StudioVariantGroupType::LayoutSize => 'Size & layout set',
            StudioVariantGroupType::Generic => 'Variant set',
            StudioVariantGroupType::Motion => 'Motion set',
        };
    }
}
