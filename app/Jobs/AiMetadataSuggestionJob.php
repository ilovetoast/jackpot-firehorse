<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\ActivityRecorder;
use App\Services\AiMetadataSuggestionService;
use App\Services\AssetProcessingFailureService;
use App\Support\Logging\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

        PipelineLogger::warning('AI METADATA SUGGESTION: HANDLE START', [
            'asset_id' => $asset->id,
        ]);

        // Idempotency: Check if AI suggestions already generated
        // BUT: If it failed before, allow retry (check for failed flag)
        $existingMetadata = $asset->metadata ?? [];
        $wasCompleted = isset($existingMetadata['ai_metadata_suggestions_completed']) && 
            $existingMetadata['ai_metadata_suggestions_completed'] === true;
        $wasFailed = isset($existingMetadata['ai_metadata_suggestions_failed']) && 
            $existingMetadata['ai_metadata_suggestions_failed'] === true;
        
        // Skip if completed successfully, but allow retry if it failed
        if ($wasCompleted && !$wasFailed) {
            Log::info('[AiMetadataSuggestionJob] Skipping - already completed successfully', [
                'asset_id' => $asset->id,
                'suggestions_count' => $existingMetadata['ai_metadata_suggestions_count'] ?? 0,
            ]);
            return;
        }
        
        // If it failed before, log that we're retrying
        if ($wasFailed) {
            Log::info('[AiMetadataSuggestionJob] Retrying after previous failure', [
                'asset_id' => $asset->id,
                'previous_error' => $existingMetadata['ai_metadata_suggestions_error'] ?? 'unknown',
            ]);
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
            PipelineLogger::warning('AI METADATA SUGGESTION: SKIPPED', [
                'asset_id' => $asset->id,
                'reason' => 'no_category',
            ]);
            Log::info('[AiMetadataSuggestionJob] Skipping - no category', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        try {
            // Fetch AI metadata candidates from asset_metadata_candidates table
            $candidates = DB::table('asset_metadata_candidates')
                ->where('asset_id', $asset->id)
                ->where('producer', 'ai')
                ->whereNull('resolved_at')
                ->whereNull('dismissed_at')
                ->get();

            // Format candidates into array expected by service
            // Format: ['field_key' => ['value' => mixed, 'confidence' => float, 'source' => string]]
            $aiMetadataValues = [];
            foreach ($candidates as $candidate) {
                // Get field key from metadata_field_id
                $field = DB::table('metadata_fields')
                    ->where('id', $candidate->metadata_field_id)
                    ->first();
                
                if (!$field) {
                    continue;
                }
                
                $fieldKey = $field->key;
                
                // Decode value_json (stored as JSON in database)
                $value = json_decode($candidate->value_json, true);
                
                $aiMetadataValues[$fieldKey] = [
                    'value' => $value,
                    'confidence' => $candidate->confidence ?? null,
                    'source' => 'ai',
                ];
            }

            Log::info('[AiMetadataSuggestionJob] Found candidates', [
                'asset_id' => $asset->id,
                'candidate_count' => $candidates->count(),
                'field_keys' => array_keys($aiMetadataValues),
                'candidates_detail' => $candidates->map(function($c) {
                    return [
                        'field_id' => $c->metadata_field_id,
                        'value_json' => $c->value_json,
                        'confidence' => $c->confidence,
                    ];
                })->toArray(),
            ]);

            // Generate AI suggestions from candidates
            $suggestions = $service->generateSuggestions($asset, $aiMetadataValues);
            
            Log::info('[AiMetadataSuggestionJob] Generated suggestions', [
                'asset_id' => $asset->id,
                'suggestions_count' => count($suggestions),
                'suggestions' => $suggestions,
            ]);

            // Store suggestions in asset metadata
            if (!empty($suggestions)) {
                $service->storeSuggestions($asset, $suggestions);
            }

            // Mark as completed in asset metadata
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['ai_metadata_suggestions_completed'] = true;
            $currentMetadata['ai_metadata_suggestions_completed_at'] = now()->toIso8601String();
            $currentMetadata['ai_metadata_suggestions_count'] = count($suggestions);

            $asset->update([
                'metadata' => $currentMetadata,
            ]);

            // Record AI suggestions generated activity event
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_SUGGESTIONS_GENERATED, [
                'job' => 'AiMetadataSuggestionJob',
                'suggestions_count' => count($suggestions),
            ]);

            PipelineLogger::warning('AI METADATA SUGGESTION: COMPLETE', [
                'asset_id' => $asset->id,
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

            // Log failure event for timeline display
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_SUGGESTIONS_FAILED, [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
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
