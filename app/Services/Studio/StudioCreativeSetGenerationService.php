<?php

namespace App\Services\Studio;

use App\Http\Controllers\Editor\EditorCreativeSetController;
use App\Jobs\ProcessCreativeSetGenerationItemJob;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\GenerationJobItem;
use App\Models\User;
use App\Support\StudioCreativeSetGenerationQueueGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudioCreativeSetGenerationService
{
    public function __construct(
        protected CreativeSetGenerationPlanner $planner,
        protected StudioVariantGroupBinder $variantGroupBinder,
    ) {}

    /**
     * @param  array<int, string>  $colorIds
     * @param  array<int, string>  $sceneIds
     * @param  array<int, string>  $formatIds
     * @param  array<int, string>|null  $selectedCombinationKeys
     */
    public function start(
        CreativeSet $set,
        User $user,
        int $sourceCompositionId,
        array $colorIds,
        array $sceneIds,
        array $formatIds,
        ?array $selectedCombinationKeys,
    ): GenerationJob {
        StudioCreativeSetGenerationQueueGuard::assertStudioGenerationUsesWorkers();

        if ($colorIds === [] && $sceneIds === [] && $formatIds === []) {
            throw ValidationException::withMessages([
                'color_ids' => 'Select at least one color, scene, and/or format.',
            ]);
        }

        $membership = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->where('composition_id', $sourceCompositionId)
            ->first();
        if (! $membership) {
            throw ValidationException::withMessages([
                'source_composition_id' => 'Source composition must belong to this Versions set.',
            ]);
        }

        $baseline = Composition::query()
            ->where('id', $sourceCompositionId)
            ->where('tenant_id', $set->tenant_id)
            ->where('brand_id', $set->brand_id)
            ->visibleToUser($user)
            ->first();
        if (! $baseline) {
            throw ValidationException::withMessages([
                'source_composition_id' => 'Composition not found.',
            ]);
        }

        $maxColors = (int) config('studio_creative_set_generation.max_colors', 6);
        $maxScenes = (int) config('studio_creative_set_generation.max_scenes', 5);
        $maxFormats = (int) config('studio_creative_set_generation.max_formats', 3);
        $maxOutputs = (int) config('studio_creative_set_generation.max_outputs_per_request', 24);

        if (count($colorIds) > $maxColors) {
            throw ValidationException::withMessages(['color_ids' => "At most {$maxColors} colors."]);
        }
        if (count($sceneIds) > $maxScenes) {
            throw ValidationException::withMessages(['scene_ids' => "At most {$maxScenes} scenes."]);
        }
        if (count($formatIds) > $maxFormats) {
            throw ValidationException::withMessages(['format_ids' => "At most {$maxFormats} formats."]);
        }

        $colors = $this->resolvePresetColors($colorIds);
        $scenes = $this->resolvePresetScenes($sceneIds);
        $formats = $this->resolvePresetFormats($formatIds);

        if ($colors === [] && $colorIds !== []) {
            throw ValidationException::withMessages(['color_ids' => 'Unknown color id(s).']);
        }
        if ($scenes === [] && $sceneIds !== []) {
            throw ValidationException::withMessages(['scene_ids' => 'Unknown scene id(s).']);
        }
        if ($formats === [] && $formatIds !== []) {
            throw ValidationException::withMessages(['format_ids' => 'Unknown format id(s).']);
        }

        $allowedKeys = $this->planner->plan(
            (int) $baseline->id,
            $colors,
            $scenes,
            $formats,
            null,
        )['keys'];

        if ($selectedCombinationKeys !== null && $selectedCombinationKeys !== []) {
            foreach ($selectedCombinationKeys as $k) {
                $k = (string) $k;
                if (! in_array($k, $allowedKeys, true)) {
                    throw ValidationException::withMessages([
                        'selected_combination_keys' => ["Invalid combination key: {$k}"],
                    ]);
                }
            }
        }

        $planned = $this->planner->plan(
            (int) $baseline->id,
            $colors,
            $scenes,
            $formats,
            $selectedCombinationKeys,
        );

        $keys = $planned['keys'];
        if ($keys === []) {
            throw ValidationException::withMessages([
                'selected_combination_keys' => 'No combinations selected.',
            ]);
        }

        $currentVariants = CreativeSetVariant::query()
            ->where('creative_set_id', $set->id)
            ->where('status', '!=', CreativeSetVariant::STATUS_ARCHIVED)
            ->count();
        if ($currentVariants + count($keys) > EditorCreativeSetController::MAX_VARIANTS_PER_SET) {
            throw ValidationException::withMessages([
                'color_ids' => 'This generation would exceed the maximum versions per set ('.EditorCreativeSetController::MAX_VARIANTS_PER_SET.').',
            ]);
        }

        if (count($keys) > $maxOutputs) {
            throw ValidationException::withMessages([
                'color_ids' => "At most {$maxOutputs} outputs per request.",
            ]);
        }

        $snapshot = $planned['snapshot'];

        $job = DB::transaction(function () use ($set, $user, $snapshot, $keys, $baseline, $colorIds, $sceneIds, $formatIds): GenerationJob {
            $job = GenerationJob::query()->create([
                'creative_set_id' => $set->id,
                'user_id' => $user->id,
                'status' => GenerationJob::STATUS_RUNNING,
                'axis_snapshot' => $snapshot,
                'meta' => ['total' => count($keys)],
            ]);

            foreach ($keys as $key) {
                GenerationJobItem::query()->create([
                    'generation_job_id' => $job->id,
                    'combination_key' => $key,
                    'status' => GenerationJobItem::STATUS_PENDING,
                    'attempts' => 0,
                ]);
            }

            $job = $job->fresh(['items']);
            $this->variantGroupBinder->bindForGeneration(
                $set,
                $user,
                $job,
                $baseline,
                $colorIds,
                $sceneIds,
                $formatIds
            );

            return $job->fresh(['items']);
        });

        foreach ($job->items as $item) {
            ProcessCreativeSetGenerationItemJob::dispatch($item->id);
        }

        return $job->fresh(['items']);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, label: string, hex?: string|null}>
     */
    private function resolvePresetColors(array $ids): array
    {
        $presets = config('studio_creative_set_generation.preset_colors', []);
        if (! is_array($presets)) {
            return [];
        }
        $byId = [];
        foreach ($presets as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(string) $row['id']] = $row;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, label: string, instruction: string}>
     */
    private function resolvePresetScenes(array $ids): array
    {
        $presets = config('studio_creative_set_generation.preset_scenes', []);
        if (! is_array($presets)) {
            return [];
        }
        $byId = [];
        foreach ($presets as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(string) $row['id']] = $row;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array{id: string, label: string, width: int, height: int}>
     */
    private function resolvePresetFormats(array $ids): array
    {
        $presets = config('studio_creative_set_generation.preset_formats', []);
        if (! is_array($presets)) {
            return [];
        }
        $byId = [];
        foreach ($presets as $row) {
            if (is_array($row) && isset($row['id'], $row['width'], $row['height'])) {
                $entry = [
                    'id' => (string) $row['id'],
                    'label' => (string) ($row['label'] ?? $row['id']),
                    'width' => (int) $row['width'],
                    'height' => (int) $row['height'],
                ];
                foreach (['group', 'description', 'recommended'] as $extra) {
                    if (! array_key_exists($extra, $row)) {
                        continue;
                    }
                    if ($extra === 'recommended') {
                        $entry['recommended'] = (bool) $row['recommended'];

                        continue;
                    }
                    $v = $row[$extra];
                    if (is_string($v) && $v !== '') {
                        $entry[$extra] = $v;
                    }
                }
                $byId[(string) $row['id']] = $entry;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }
}
