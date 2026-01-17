<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Category;
use App\Services\AiMetadataSuggestionService;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AI Metadata Suggestion Job
 *
 * Phase 2 – Step 5: Generates AI metadata suggestions after asset processing completes.
 *
 * This job runs after:
 * - Thumbnail generation
 * - Metadata extraction
 * - Basic analysis
 *
 * Rules:
 * - AI suggestions are NEVER auto-applied
 * - AI failures must not affect upload success
 * - Suggestions are stored but not applied
 */
class AiMetadataSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AiMetadataSuggestionService $service): void
    {
        $asset = Asset::findOrFail($this->assetId);

        // Idempotency: Check if AI suggestions already generated
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['ai_metadata_suggestions_completed']) && 
            $existingMetadata['ai_metadata_suggestions_completed'] === true) {
            Log::info('[AiMetadataSuggestionJob] Skipping - already completed', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            Log::info('[AiMetadataSuggestionJob] Skipping - no category', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        try {
            // Generate AI suggestions
            $suggestions = $service->generateSuggestions($asset, $category);

            // Mark as completed in asset metadata
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['ai_metadata_suggestions_completed'] = true;
            $currentMetadata['ai_metadata_suggestions_completed_at'] = now()->toIso8601String();
            $currentMetadata['ai_metadata_suggestions_count'] = count($suggestions);

            $asset->update([
                'metadata' => $currentMetadata,
            ]);

            Log::info('[AiMetadataSuggestionJob] Completed', [
                'asset_id' => $asset->id,
                'suggestions_count' => count($suggestions),
            ]);
        } catch (\Throwable $e) {
            // AI failures must not affect upload success
            // Log error but don't throw - allow job to complete
            Log::error('[AiMetadataSuggestionJob] Failed to generate suggestions', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed in asset metadata
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['ai_metadata_suggestions_failed'] = true;
            $currentMetadata['ai_metadata_suggestions_failed_at'] = now()->toIso8601String();
            $currentMetadata['ai_metadata_suggestions_error'] = $e->getMessage();

            $asset->update([
                'metadata' => $currentMetadata,
            ]);

            // Don't throw - allow job to complete successfully
            // This ensures AI failures don't block the processing pipeline
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );
        }
    }
}
