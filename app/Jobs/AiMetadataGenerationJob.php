<?php

namespace App\Jobs;

use App\Exceptions\PlanLimitExceededException;
use App\Exceptions\AIQuotaExceededException;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\AIAgentRun;
use App\Enums\AITaskType;
use App\Services\AiMetadataGenerationService;
use App\Services\AiUsageService;
use App\Services\ActivityRecorder;
use App\Enums\EventType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AI Metadata Generation Job
 *
 * Generates AI metadata candidates for assets using OpenAI Vision API.
 * This job analyzes images and creates candidates in asset_metadata_candidates table.
 *
 * Critical Principles:
 * - Never blocks uploads on failure
 * - Checks plan limits before API call
 * - Auto-generation runs once per asset (unless manual rerun)
 * - Tracks costs and usage
 * - Logs all activity
 */
class AiMetadataGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes
    
    /**
     * Determine if the job should be retried when it fails.
     * 
     * Quota errors should not be retried - they won't succeed until quota is resolved.
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        // Don't retry quota errors - they require manual intervention (fixing API billing/quota)
        if ($exception instanceof AIQuotaExceededException) {
            return false;
        }
        
        // Don't retry plan limit errors - they require plan upgrade
        if ($exception instanceof PlanLimitExceededException) {
            return false;
        }
        
        // Retry other errors (network issues, temporary API problems, etc.)
        return true;
    }

    /**
     * Whether this is a manual rerun (overrides idempotency check).
     */
    public bool $isManualRerun = false;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId,
        bool $isManualRerun = false
    ) {
        $this->isManualRerun = $isManualRerun;
    }

    /**
     * Execute the job.
     */
    public function handle(
        AiMetadataGenerationService $service,
        AiUsageService $usageService
    ): void {
        $asset = Asset::findOrFail($this->assetId);

        // 1. Check if already generated (unless manual rerun)
        // Auto-generation runs once per asset
        // Manual regenerate (via admin) overrides this check
        $metadata = $asset->metadata ?? [];
        $alreadyGenerated = isset($metadata['_ai_metadata_generated_at']);

        if ($alreadyGenerated && !$this->isManualRerun) {
            Log::info('[AiMetadataGenerationJob] AI Metadata Generation skipped - already generated', [
                'asset_id' => $asset->id,
                'generated_at' => $metadata['_ai_metadata_generated_at'],
            ]);
            // Ensure status is set even if already generated
            if (!isset($metadata['_ai_metadata_status'])) {
                $metadata['_ai_metadata_status'] = 'completed';
                $asset->update(['metadata' => $metadata]);
            }
            return;
        }

        // 2. Check plan limits (hard stop)
        $tenant = Tenant::find($asset->tenant_id);
        if (!$tenant) {
            Log::warning('[AiMetadataGenerationJob] Tenant not found', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
            ]);
            return;
        }

        try {
            $usageService->checkUsage($tenant, 'tagging', 1);
        } catch (PlanLimitExceededException $e) {
            // Hard stop - don't retry, log and skip
            Log::warning('[AiMetadataGenerationJob] Plan limit exceeded', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            $this->markAsSkipped($asset, 'plan_limit_exceeded');
            return;
        }

        // 3. Verify prerequisites - wait for thumbnail with retry logic
        $thumbnailUrl = $this->waitForThumbnail($asset);
        if (!$thumbnailUrl) {
            // waitForThumbnail already logged the failure
            $this->markAsSkipped($asset, 'thumbnail_unavailable');
            return;
        }

        if (!isset($asset->metadata['category_id'])) {
            Log::info('[AiMetadataGenerationJob] AI Metadata Generation skipped - no category', [
                'asset_id' => $asset->id,
            ]);
            $this->markAsSkipped($asset, 'no_category');
            return;
        }

        // 4. Create AI agent run record for audit trail
        // This allows the metadata generator to be managed as an AI agent
        $agentRun = AIAgentRun::create([
            'agent_id' => 'metadata_generator',
            'triggering_context' => 'tenant',
            'environment' => app()->environment(),
            'tenant_id' => $tenant->id,
            'user_id' => null, // System-triggered during asset processing
            'task_type' => AITaskType::ASSET_METADATA_GENERATION,
            'model_used' => config('ai.models.gpt-4o-mini.model_name', 'gpt-4o-mini'),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed', // Will be updated on success
            'started_at' => now(),
            'metadata' => [
                'asset_id' => $asset->id,
                'is_manual_rerun' => $this->isManualRerun,
            ],
        ]);

        // 5. Generate metadata (use thumbnail URL from waitForThumbnail)
        try {
            $results = $service->generateMetadata($asset);

            // 6. Mark as generated (prevents silent re-runs)
            // This timestamp is updated even on manual rerun
            $metadata = $asset->metadata ?? [];
            $metadata['_ai_metadata_generated_at'] = now()->toIso8601String();
            $metadata['_ai_metadata_status'] = 'completed'; // Explicit status for debugging
            // Clear any previous skip/failed states
            unset($metadata['_ai_metadata_skipped'], $metadata['_ai_metadata_skip_reason'], $metadata['_ai_metadata_skipped_at']);
            unset($metadata['_ai_metadata_failed'], $metadata['_ai_metadata_error'], $metadata['_ai_metadata_failed_at']);
            $asset->update(['metadata' => $metadata]);

            // 7. Update AI agent run with success
            $agentRun->markAsSuccessful(
                $results['tokens_in'] ?? 0,
                $results['tokens_out'] ?? 0,
                $results['cost'] ?? 0.0,
                array_merge($agentRun->metadata ?? [], [
                    'candidates_created' => $results['candidates_created'],
                    'fields_processed' => $results['fields_processed'],
                ])
            );

            // 8. Track usage and cost (dual tracking: ai_usage table + ai_agent_runs table)
            $usageService->trackUsageWithCost(
                $tenant,
                'tagging',
                1,
                $results['cost'],
                $results['tokens_in'],
                $results['tokens_out'],
                $results['model'] ?? 'gpt-4o-mini' // Use model from response or default
            );

            // 9. Log activity (AI Metadata Generation - metadata_generator agent)
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_METADATA_GENERATED, [
                'agent_run_id' => $agentRun->id,
                'agent_id' => 'metadata_generator',
                'candidates_created' => $results['candidates_created'],
                'fields_processed' => $results['fields_processed'],
                'model' => $results['model'] ?? 'unknown',
                'cost' => $results['cost'] ?? 0.0,
                'tokens_in' => $results['tokens_in'] ?? 0,
                'tokens_out' => $results['tokens_out'] ?? 0,
                'is_manual_rerun' => $this->isManualRerun,
            ]);

        } catch (PlanLimitExceededException $e) {
            // Already handled above, but catch here for safety
            if (isset($agentRun)) {
                $agentRun->markAsFailed($e->getMessage());
            }
            $this->markAsSkipped($asset, 'plan_limit_exceeded');
            return;
        } catch (AIQuotaExceededException $e) {
            // Quota exceeded - don't retry, mark as skipped with specific reason
            // This is different from plan limits - it's an API provider quota issue
            if (isset($agentRun)) {
                $agentRun->markAsFailed($e->getMessage());
            }
            Log::warning('[AiMetadataGenerationJob] AI Metadata Generation skipped - quota exceeded', [
                'asset_id' => $asset->id,
                'agent_run_id' => $agentRun->id ?? null,
                'agent_id' => 'metadata_generator',
                'error' => $e->getMessage(),
                'note' => 'OpenAI API quota exceeded - check API billing and quota limits. Job will not retry.',
            ]);
            $this->markAsSkipped($asset, 'api_quota_exceeded');
            
            // Log failure event for timeline display
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_METADATA_FAILED, [
                'error' => $e->getMessage(),
                'error_type' => 'quota_exceeded',
                'agent_run_id' => $agentRun->id ?? null,
                'agent_id' => 'metadata_generator',
            ]);
            
            // Don't retry quota errors - they won't succeed until quota is resolved
            // Mark job as failed to prevent retries
            $this->fail($e);
            return;
        } catch (\Throwable $e) {
            // AI failures must not affect upload success
            if (isset($agentRun)) {
                $agentRun->markAsFailed($e->getMessage());
            }
            Log::error('[AiMetadataGenerationJob] AI Metadata Generation failed', [
                'asset_id' => $asset->id,
                'agent_run_id' => $agentRun->id ?? null,
                'agent_id' => 'metadata_generator',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->markAsFailed($asset, $e->getMessage());
            
            // Log failure event for timeline display
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_METADATA_FAILED, [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'agent_run_id' => $agentRun->id ?? null,
                'agent_id' => 'metadata_generator',
            ]);
            
            // Don't throw - allow job to complete
        }
    }

    /**
     * Mark asset as skipped.
     *
     * Sets explicit status for debugging: _ai_metadata_status = "skipped:{reason}"
     *
     * @param Asset $asset
     * @param string $reason Skip reason (e.g., 'thumbnail_unavailable', 'no_category', 'plan_limit_exceeded')
     * @return void
     */
    protected function markAsSkipped(Asset $asset, string $reason): void
    {
        $metadata = $asset->metadata ?? [];
        $metadata['_ai_metadata_skipped'] = true;
        $metadata['_ai_metadata_skip_reason'] = $reason;
        $metadata['_ai_metadata_skipped_at'] = now()->toIso8601String();
        $metadata['_ai_metadata_status'] = "skipped:{$reason}"; // Explicit status for debugging
        $asset->update(['metadata' => $metadata]);
    }

    /**
     * Mark asset as failed.
     *
     * Sets explicit status for debugging: _ai_metadata_status = "failed"
     *
     * @param Asset $asset
     * @param string $error
     * @return void
     */
    protected function markAsFailed(Asset $asset, string $error): void
    {
        $metadata = $asset->metadata ?? [];
        $metadata['_ai_metadata_failed'] = true;
        $metadata['_ai_metadata_error'] = $error;
        $metadata['_ai_metadata_failed_at'] = now()->toIso8601String();
        $metadata['_ai_metadata_status'] = 'failed'; // Explicit status for debugging
        $asset->update(['metadata' => $metadata]);
    }

    /**
     * Wait for thumbnail to be available with enterprise-level retry logic.
     *
     * This method implements intelligent waiting for thumbnails that may be:
     * - In temp path during processing (before asset promotion)
     * - In final path after promotion
     * - Still being generated (thumbnail_status = PROCESSING)
     *
     * Retry strategy:
     * - Initial check (immediate)
     * - Retry with exponential backoff (2s, 4s, 8s, 16s)
     * - Maximum wait time: 30 seconds
     * - Checks both temp and final thumbnail paths
     *
     * @param Asset $asset
     * @return string|null Thumbnail URL if available, null if timeout
     */
    protected function waitForThumbnail(Asset $asset): ?string
    {
        $maxWaitSeconds = 30;
        $maxRetries = 5;
        $retryDelays = [0, 2, 4, 8, 16]; // Exponential backoff
        
        Log::info('[AiMetadataGenerationJob] Waiting for thumbnail', [
            'asset_id' => $asset->id,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            'max_wait_seconds' => $maxWaitSeconds,
        ]);

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Refresh asset to get latest state
            $asset->refresh();
            
            // Check if thumbnail URL is available
            $thumbnailUrl = $asset->medium_thumbnail_url;
            if ($thumbnailUrl) {
                Log::info('[AiMetadataGenerationJob] Thumbnail available', [
                    'asset_id' => $asset->id,
                    'attempt' => $attempt + 1,
                    'thumbnail_url' => $thumbnailUrl,
                    'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
                ]);
                return $thumbnailUrl;
            }

            // Check if we should continue waiting
            $thumbnailStatus = $asset->thumbnail_status;
            $isProcessing = in_array($thumbnailStatus, [
                \App\Enums\ThumbnailStatus::PENDING,
                \App\Enums\ThumbnailStatus::PROCESSING,
            ], true);

            if (!$isProcessing && $thumbnailStatus !== \App\Enums\ThumbnailStatus::COMPLETED) {
                // Thumbnail generation failed or was skipped - no point waiting
                Log::warning('[AiMetadataGenerationJob] Thumbnail not processing, skipping wait', [
                    'asset_id' => $asset->id,
                    'thumbnail_status' => $thumbnailStatus?->value ?? 'null',
                ]);
                break;
            }

            // Wait before next retry (except on last attempt)
            if ($attempt < $maxRetries - 1) {
                $delay = $retryDelays[$attempt] ?? 16;
                if ($delay > 0) {
                    Log::debug('[AiMetadataGenerationJob] Waiting for thumbnail, retry in ' . $delay . 's', [
                        'asset_id' => $asset->id,
                        'attempt' => $attempt + 1,
                        'delay_seconds' => $delay,
                    ]);
                    sleep($delay);
                }
            }
        }

        Log::warning('[AiMetadataGenerationJob] Thumbnail not available after retries', [
            'asset_id' => $asset->id,
            'attempts' => $maxRetries,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
        ]);

        return null;
    }
}
