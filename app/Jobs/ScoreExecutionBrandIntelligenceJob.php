<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reserved for future multi-asset execution (Deliverables) scoring.
 * EBI currently uses {@see ScoreAssetBrandIntelligenceJob} on {@see \App\Models\Asset}.
 */
class ScoreExecutionBrandIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $executionId
    ) {}

    public function handle(): void
    {
        // Intentionally no-op: executions table kept for future use; scoring is asset-based for now.
    }
}
