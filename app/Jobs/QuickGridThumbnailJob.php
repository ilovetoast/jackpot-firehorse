<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 stub: intended to generate only the grid-sized thumbnail early on a
 * dedicated fast queue, then let {@see GenerateThumbnailsJob} handle full derivatives
 * with deduplication once implemented.
 *
 * Default: feature flag off; when on, handle() logs and returns (no S3/Imagick work)
 * until the real implementation ships.
 *
 * When implemented, reuse {@see \App\Services\ImageOrientationNormalizer} with the same
 * flow as {@see \App\Services\ThumbnailGenerationService} so grid thumbs match full pipeline orientation.
 */
class QuickGridThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $assetId,
        public ?string $assetVersionId = null,
    ) {
        $q = config('assets.quick_grid_thumbnails.queue');
        $queue = (is_string($q) && $q !== '') ? $q : (string) config('queue.images_fast_queue', 'images-fast');
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        Log::info('[quick_grid_thumbnail_stub]', [
            'asset_id' => $this->assetId,
            'asset_version_id' => $this->assetVersionId,
            'queue' => $this->queue,
            'note' => 'ASSET_QUICK_GRID_THUMBNAILS: stub only — no derivative generation yet',
        ]);
    }
}
