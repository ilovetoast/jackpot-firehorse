<?php

namespace App\Services\Studio;

use App\Jobs\ProcessCreativeSetGenerationItemJob;
use App\Models\CreativeSet;
use App\Models\GenerationJob;
use App\Models\GenerationJobItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enqueues a fresh {@link GenerationJobItem} for a failed combination, reusing the same job + combination key.
 * The previous failed item is marked {@see GenerationJobItem::$superseded_at} so aggregate status ignores it.
 */
final class StudioCreativeSetGenerationItemRetryService
{
    /**
     * @return array{generation_job_item: GenerationJobItem, generation_job: GenerationJob}
     */
    public function retryFailedItem(CreativeSet $set, User $user, int $itemId): array
    {
        $item = GenerationJobItem::query()
            ->whereKey($itemId)
            ->whereHas('job', static fn ($q) => $q->where('creative_set_id', $set->id))
            ->first();
        if (! $item) {
            throw ValidationException::withMessages(['item' => ['Generation item not found.']]);
        }

        if ($item->status !== GenerationJobItem::STATUS_FAILED) {
            throw ValidationException::withMessages(['item' => ['Only failed versions can be retried.']]);
        }

        if ($item->superseded_at !== null) {
            throw ValidationException::withMessages(['item' => ['This attempt was already superseded by a newer retry.']]);
        }

        if ($item->creative_set_variant_id === null || $item->composition_id === null) {
            throw ValidationException::withMessages(['item' => ['This generation item cannot be retried (missing variant).']]);
        }

        $job = $item->job;
        if (! $job || (int) $job->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['item' => ['You can only retry items from your own generation jobs.']]);
        }

        return DB::transaction(function () use ($item, $job): array {
            $item->update(['superseded_at' => now()]);

            $new = GenerationJobItem::query()->create([
                'generation_job_id' => $job->id,
                'retried_from_item_id' => $item->id,
                'combination_key' => $item->combination_key,
                'status' => GenerationJobItem::STATUS_PENDING,
                'attempts' => 0,
                'creative_set_variant_id' => null,
                'composition_id' => null,
                'error' => null,
            ]);

            $job->refreshStatusFromItems();
            ProcessCreativeSetGenerationItemJob::dispatch($new->id);

            return [
                'generation_job_item' => $new->fresh(),
                'generation_job' => $job->fresh(['items']),
            ];
        });
    }
}
