<?php

namespace App\Jobs;

use App\Assets\Metadata\EmbeddedMetadataExtractionService;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Layer B/C embedded metadata extraction. Runs after {@see ExtractMetadataJob} when the file is in storage.
 * Failures are swallowed so the processing chain is not blocked.
 */
class ExtractEmbeddedMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $assetId,
        public readonly ?string $versionId = null
    ) {}

    public function handle(EmbeddedMetadataExtractionService $service): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        if ($asset->status !== AssetStatus::VISIBLE) {
            return;
        }

        $version = $this->versionId ? AssetVersion::find($this->versionId) : null;

        try {
            $service->extractAndPersist($asset, $version);
        } catch (\Throwable $e) {
            Log::warning('[ExtractEmbeddedMetadataJob] Non-fatal embedded extraction failure', [
                'asset_id' => $this->assetId,
                'version_id' => $this->versionId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
