<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Audio\AudioAiAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Kick off AI analysis for an audio asset (transcript, mood, summary).
 *
 * Runs on the `ai` queue (parallel to the main processing chain) and is
 * a no-op when no provider is configured — see AudioAiAnalysisService.
 * The job is intentionally tolerant of missing providers so we can ship
 * audio support immediately and bolt the provider on later without
 * touching the upload pipeline.
 */
class RunAudioAiAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly string $assetId,
    ) {
        $this->onQueue(config('queue.ai_queue', 'ai'));
    }

    public function handle(AudioAiAnalysisService $analysisService): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'audio') {
            return;
        }

        $result = $analysisService->analyzeForAsset($asset);
        Log::info('[RunAudioAiAnalysisJob] result', [
            'asset_id' => $asset->id,
            'status' => $result['status'] ?? null,
        ]);
    }
}
