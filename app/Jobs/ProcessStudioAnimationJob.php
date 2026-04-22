<?php

namespace App\Jobs;

use App\Support\StudioAnimationQueue;
use App\Studio\Animation\Services\StudioAnimationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStudioAnimationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $studioAnimationJobId,
    ) {
        $this->onQueue(StudioAnimationQueue::name());
    }

    public function handle(StudioAnimationProcessor $processor): void
    {
        $processor->process($this->studioAnimationJobId);
    }
}
