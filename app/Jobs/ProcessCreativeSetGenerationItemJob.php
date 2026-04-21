<?php

namespace App\Jobs;

use App\Models\GenerationJobItem;
use App\Services\Studio\StudioCreativeSetItemProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCreativeSetGenerationItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $generationJobItemId)
    {
        $this->onQueue(config('queue.default', 'default'));
    }

    public function handle(StudioCreativeSetItemProcessor $processor): void
    {
        $item = GenerationJobItem::query()->find($this->generationJobItemId);
        if (! $item) {
            return;
        }

        try {
            $processor->process($item);
        } catch (\Throwable $e) {
            Log::error('[ProcessCreativeSetGenerationItemJob] unhandled', [
                'item_id' => $this->generationJobItemId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
