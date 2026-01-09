<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AITaggingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Never retry forever - enforce maximum attempts.
     *
     * @var int
     */
    public $tries = 3; // Maximum retry attempts (enforced by AssetProcessingFailureService)

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
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $asset = Asset::findOrFail($this->assetId);

        // Idempotency: Check if AI tagging already completed
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['ai_tagging_completed']) && $existingMetadata['ai_tagging_completed'] === true) {
            Log::info('AI tagging skipped - already completed', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure asset is in THUMBNAIL_GENERATED status (from GeneratePreviewJob which should maintain status)
        // Actually, preview generation doesn't change status, so we check for THUMBNAIL_GENERATED
        if ($asset->status !== AssetStatus::THUMBNAIL_GENERATED) {
            Log::warning('AI tagging skipped - asset has not completed thumbnail generation', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // AI tagging (stub implementation)
        $tags = $this->generateAITags($asset);

        // Update asset metadata
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['ai_tagging_completed'] = true;
        $currentMetadata['ai_tagging_completed_at'] = now()->toIso8601String();
        if (!empty($tags)) {
            $currentMetadata['ai_tags'] = $tags;
        }

        // Update status to AI_TAGGED
        $asset->update([
            'status' => AssetStatus::AI_TAGGED,
            'metadata' => $currentMetadata,
        ]);

        // Emit AI tagging completed event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.ai_tagging.completed',
            'metadata' => [
                'job' => 'AITaggingJob',
                'tag_count' => count($tags),
            ],
            'created_at' => now(),
        ]);

        Log::info('AI tagging completed', [
            'asset_id' => $asset->id,
            'tag_count' => count($tags),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Generate AI tags for asset (stub implementation).
     *
     * @param Asset $asset
     * @return array
     */
    protected function generateAITags(Asset $asset): array
    {
        // Stub implementation - future phase will add actual AI tagging
        // Returns empty array for now
        return [];
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
