<?php

namespace App\Services\Studio;

use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\GenerationJobItem;
use App\Models\StudioVariantGroup;
use App\Models\StudioVariantGroupMember;

/**
 * Links creative-set generation items to {@link StudioVariantGroupMember} rows when variant groups are enabled.
 */
final class StudioVariantGroupMemberSync
{
    public function afterVariantReady(
        GenerationJob $job,
        GenerationJobItem $item,
        array $specJson,
    ): void {
        $meta = is_array($job->meta) ? $job->meta : [];
        $groupId = isset($meta['studio_variant_group_id']) ? (int) $meta['studio_variant_group_id'] : null;
        if (! config('studio.variant_groups_v1', false) || $groupId === null) {
            return;
        }
        $memberId = $item->studio_variant_group_member_id;
        if ($memberId === null) {
            return;
        }
        StudioVariantGroupMember::query()->whereKey($memberId)->update([
            'generation_status' => StudioVariantGroupMember::GENERATION_READY,
            'status' => StudioVariantGroupMember::STATUS_ACTIVE,
            'spec_json' => $specJson,
            'generation_job_item_id' => $item->id,
        ]);
    }

    public function afterVariantFailed(GenerationJob $job, GenerationJobItem $item): void
    {
        $meta = is_array($job->meta) ? $job->meta : [];
        $groupId = isset($meta['studio_variant_group_id']) ? (int) $meta['studio_variant_group_id'] : null;
        if (! config('studio.variant_groups_v1', false) || $groupId === null) {
            return;
        }
        $memberId = $item->studio_variant_group_member_id;
        if ($memberId === null) {
            return;
        }
        StudioVariantGroupMember::query()->whereKey($memberId)->update([
            'generation_status' => StudioVariantGroupMember::GENERATION_FAILED,
        ]);
    }

    /**
     * Create or update member linkage when a variant row is created for a grouped generation job.
     */
    public function attachForNewVariant(
        GenerationJob $job,
        GenerationJobItem $item,
        CreativeSetVariant $variant,
        string $label,
        array $specJson,
    ): void {
        if (! config('studio.variant_groups_v1', false)) {
            return;
        }
        $meta = is_array($job->meta) ? $job->meta : [];
        $groupId = isset($meta['studio_variant_group_id']) ? (int) $meta['studio_variant_group_id'] : null;
        if ($groupId === null) {
            return;
        }
        $group = StudioVariantGroup::query()->whereKey($groupId)->first();
        if (! $group) {
            return;
        }

        $sort = (int) StudioVariantGroupMember::query()
            ->where('studio_variant_group_id', $group->id)
            ->max('sort_order') + 1;

        $member = StudioVariantGroupMember::query()->create([
            'studio_variant_group_id' => $group->id,
            'composition_id' => $variant->composition_id,
            'slot_key' => $item->combination_key,
            'label' => $label,
            'status' => StudioVariantGroupMember::STATUS_ACTIVE,
            'generation_status' => StudioVariantGroupMember::GENERATION_RUNNING,
            'spec_json' => $specJson,
            'generation_job_item_id' => $item->id,
            'sort_order' => $sort,
        ]);

        $variant->update(['studio_variant_group_id' => $group->id]);
        $item->update(['studio_variant_group_member_id' => $member->id]);
    }

    /**
     * Re-link a retry item to the existing member row for the same composition.
     */
    public function attachForRetry(
        GenerationJob $job,
        GenerationJobItem $item,
        CreativeSetVariant $variant,
    ): void {
        if (! config('studio.variant_groups_v1', false)) {
            return;
        }
        $meta = is_array($job->meta) ? $job->meta : [];
        $groupId = isset($meta['studio_variant_group_id']) ? (int) $meta['studio_variant_group_id'] : null;
        if ($groupId === null) {
            return;
        }

        $member = StudioVariantGroupMember::query()
            ->where('studio_variant_group_id', $groupId)
            ->where('composition_id', $variant->composition_id)
            ->orderByDesc('id')
            ->first();

        if ($member) {
            $member->update([
                'generation_job_item_id' => $item->id,
                'generation_status' => StudioVariantGroupMember::GENERATION_RUNNING,
            ]);
            $item->update(['studio_variant_group_member_id' => $member->id]);
            if ($variant->studio_variant_group_id === null) {
                $variant->update(['studio_variant_group_id' => $groupId]);
            }
        }
    }
}
