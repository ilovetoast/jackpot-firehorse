<?php

namespace App\Services\Studio;

use App\Jobs\RefreshCompositionThumbnailFromProductLayerJob;
use App\Models\Composition;
use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\GenerationJobItem;
use App\Models\User;
use App\Services\Editor\CompositionDuplicateService;
use App\Services\Editor\EditorGenerativeImageEditService;
use App\Support\StudioEditorDocumentProductLayerFinder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Processes one {@link GenerationJobItem}: duplicate baseline → AI edit product image → persist composition.
 */
final class StudioCreativeSetItemProcessor
{
    public function __construct(
        protected CompositionDuplicateService $compositionDuplicate,
        protected CreativeSetGenerationPlanner $planner,
        protected EditorGenerativeImageEditService $generativeImageEditService,
    ) {}

    public function process(GenerationJobItem $item): void
    {
        $jobForRefresh = null;
        $previousTenant = app()->bound('tenant') ? app('tenant') : null;
        $previousBrand = app()->bound('brand') ? app('brand') : null;

        try {
            $item = GenerationJobItem::query()->whereKey($item->id)->lockForUpdate()->first();
            if (! $item) {
                return;
            }
            if ($item->status === GenerationJobItem::STATUS_COMPLETED) {
                return;
            }

            $job = $item->job()->with(['creativeSet', 'user'])->first();
            $jobForRefresh = $job;
            if (! $job || ! $job->creativeSet || ! $job->user instanceof User) {
                $this->failItem($item, 'Invalid generation job.');

                return;
            }

            $item->update([
                'status' => GenerationJobItem::STATUS_RUNNING,
                'attempts' => $item->attempts + 1,
            ]);

            $set = $job->creativeSet;
            $user = $job->user;
            $tenant = $set->tenant()->first();
            $brand = $set->brand()->first();
            if (! $tenant || ! $brand) {
                $this->failItem($item, 'Missing tenant or brand.');

                return;
            }

            $snapshot = is_array($job->axis_snapshot) ? $job->axis_snapshot : [];
            $baselineId = (int) ($snapshot['baseline_composition_id'] ?? 0);
            $baseline = Composition::query()
                ->where('id', $baselineId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            if (! $baseline) {
                $this->failItem($item, 'Baseline composition not found.');

                return;
            }

            app()->instance('tenant', $tenant);
            app()->instance('brand', $brand);
            Auth::login($user);

            $variant = null;
            $composition = null;

            try {
                $parsed = $this->planner->parseCombinationKey((string) $item->combination_key, $snapshot);
                $instruction = $this->planner->buildInstruction(
                    $parsed['color'],
                    $parsed['scene'],
                    (string) config('studio_creative_set_generation.color_instruction_template', '')
                );

                $labelParts = [];
                if (is_array($parsed['color'])) {
                    $labelParts[] = (string) ($parsed['color']['label'] ?? '');
                }
                if (is_array($parsed['scene'])) {
                    $labelParts[] = (string) ($parsed['scene']['label'] ?? '');
                }
                $label = implode(' · ', array_filter($labelParts)) ?: 'Generated';

                if ($item->retried_from_item_id !== null) {
                    $parent = GenerationJobItem::query()->find($item->retried_from_item_id);
                    if (! $parent || $parent->superseded_at === null) {
                        throw new \RuntimeException('Invalid retry parent item.');
                    }
                    $variant = CreativeSetVariant::query()
                        ->whereKey($parent->creative_set_variant_id)
                        ->where('creative_set_id', $set->id)
                        ->first();
                    if (! $variant || $variant->status !== CreativeSetVariant::STATUS_FAILED) {
                        throw new \RuntimeException('Retry target variant is missing or not in a failed state.');
                    }
                    $composition = Composition::query()
                        ->where('id', $variant->composition_id)
                        ->where('tenant_id', $tenant->id)
                        ->where('brand_id', $brand->id)
                        ->first();
                    if (! $composition) {
                        throw new \RuntimeException('Composition for retry not found.');
                    }

                    $variant->update([
                        'status' => CreativeSetVariant::STATUS_GENERATING,
                        'generation_job_item_id' => $item->id,
                    ]);
                    $item->update([
                        'creative_set_variant_id' => $variant->id,
                        'composition_id' => $composition->id,
                    ]);
                } else {
                    $composition = $this->compositionDuplicate->duplicate($baseline, $user, $baseline->name.' — '.$label, 'Duplicated');

                    $nextSort = (int) CreativeSetVariant::query()->where('creative_set_id', $set->id)->max('sort_order') + 1;

                    $axis = [
                        'combination_key' => $item->combination_key,
                        'color' => $parsed['color'],
                        'scene' => $parsed['scene'],
                    ];

                    $variant = CreativeSetVariant::query()->create([
                        'creative_set_id' => $set->id,
                        'composition_id' => $composition->id,
                        'sort_order' => $nextSort,
                        'label' => $label,
                        'status' => CreativeSetVariant::STATUS_GENERATING,
                        'axis' => $axis,
                        'generation_job_item_id' => $item->id,
                    ]);

                    $item->update([
                        'creative_set_variant_id' => $variant->id,
                        'composition_id' => $composition->id,
                    ]);
                }

                $doc = is_array($composition->document_json) ? $composition->document_json : [];
                $target = StudioEditorDocumentProductLayerFinder::find($doc);
                if ($target === null) {
                    throw new \RuntimeException('No image layer with a library asset was found to transform.');
                }

                $validated = [
                    'asset_id' => $target['asset_id'],
                    'instruction' => $instruction,
                    'composition_id' => $composition->id,
                    'brand_id' => $brand->id,
                ];

                $outcome = $this->generativeImageEditService->editFromValidated($user, $tenant, $validated, null);
                if ($outcome->status >= 400) {
                    $msg = (string) ($outcome->data['message'] ?? 'Image edit failed');

                    throw new \RuntimeException($msg);
                }

                $body = $outcome->data;
                $newAssetId = isset($body['asset_id']) ? (string) $body['asset_id'] : '';
                $imageUrl = isset($body['image_url']) ? (string) $body['image_url'] : '';
                if ($newAssetId === '' || $imageUrl === '') {
                    throw new \RuntimeException('Image edit returned no asset.');
                }

                $src = '/app/api/assets/'.rawurlencode($newAssetId).'/file';
                $updated = StudioEditorDocumentProductLayerFinder::applyImageAsset($doc, $target['layer_id'], $newAssetId, $src);
                $composition->document_json = $updated;
                $composition->save();

                $variant->update(['status' => CreativeSetVariant::STATUS_READY]);
                $item->update([
                    'status' => GenerationJobItem::STATUS_COMPLETED,
                    'error' => null,
                ]);

                RefreshCompositionThumbnailFromProductLayerJob::dispatch((int) $composition->id, (int) $user->id);
            } catch (\Throwable $e) {
                Log::warning('[StudioCreativeSetItemProcessor] failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                if ($variant instanceof CreativeSetVariant) {
                    try {
                        $variant->update(['status' => CreativeSetVariant::STATUS_FAILED]);
                    } catch (\Throwable) {
                        /* ignore */
                    }
                }

                $this->failItem($item, $e->getMessage() !== '' ? $e->getMessage() : 'Generation failed');
            }
        } finally {
            if (Auth::check()) {
                Auth::logout();
            }
            if ($previousTenant !== null) {
                app()->instance('tenant', $previousTenant);
            } else {
                app()->forgetInstance('tenant');
            }
            if ($previousBrand !== null) {
                app()->instance('brand', $previousBrand);
            } else {
                app()->forgetInstance('brand');
            }

            if ($jobForRefresh instanceof GenerationJob) {
                $jobForRefresh->refreshStatusFromItems();
            }
        }
    }

    private function failItem(GenerationJobItem $item, string $message): void
    {
        $item->update([
            'status' => GenerationJobItem::STATUS_FAILED,
            'error' => ['message' => $message],
        ]);
    }
}
