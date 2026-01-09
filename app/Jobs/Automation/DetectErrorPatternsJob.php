<?php

namespace App\Jobs\Automation;

use App\Services\Automation\ErrorPatternDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for scheduled error pattern detection.
 */
class DetectErrorPatternsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(ErrorPatternDetectionService $service): void
    {
        try {
            $suggestionsCreated = $service->scanErrorPatterns();
            Log::info('Error pattern detection completed', [
                'suggestions_created' => $suggestionsCreated,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to scan error patterns in job', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Error pattern detection job failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
