<?php

namespace App\Jobs;

use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Services\Audio\AudioMetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Extract audio-specific metadata (duration, bitrate, sample_rate, etc.)
 * via FFprobe and persist under metadata.audio.* on the asset.
 *
 * Sits in the per-asset processing chain right after the waveform job so
 * the lightbox can render duration/bitrate alongside the waveform on
 * first paint.
 */
class ExtractAudioMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $assetId,
    ) {
        $this->configureImagesQueue();
    }

    public function handle(AudioMetadataService $metadataService): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'audio') {
            return;
        }

        $result = $metadataService->extractForAsset($asset);
        if (! ($result['success'] ?? false)) {
            Log::warning('[ExtractAudioMetadataJob] extraction skipped', [
                'asset_id' => $asset->id,
                'reason' => $result['reason'] ?? 'unknown',
            ]);
        }
    }
}
