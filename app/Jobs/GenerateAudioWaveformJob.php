<?php

namespace App\Jobs;

use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Services\Audio\AudioWaveformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Render a waveform PNG for an audio asset and persist it as the asset's
 * preview/poster. Mirrors GenerateVideoPreviewJob in shape but is much
 * lighter (typically ~1-2s for a several-minute MP3).
 *
 * Failures are logged but never block the rest of the upload pipeline —
 * the asset still becomes browseable, it just falls back to a generic
 * audio placeholder in the UI.
 */
class GenerateAudioWaveformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $assetId,
    ) {
        $this->configureImagesQueue();
    }

    public function handle(AudioWaveformService $waveformService): void
    {
        Log::info('[GenerateAudioWaveformJob] start', ['asset_id' => $this->assetId]);

        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning('[GenerateAudioWaveformJob] asset missing', ['asset_id' => $this->assetId]);

            return;
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        if ($fileType !== 'audio') {
            Log::info('[GenerateAudioWaveformJob] skip — not audio', [
                'asset_id' => $asset->id,
                'file_type' => $fileType,
            ]);

            return;
        }

        $result = $waveformService->generateForAsset($asset);
        if (! ($result['success'] ?? false)) {
            Log::warning('[GenerateAudioWaveformJob] waveform not generated', [
                'asset_id' => $asset->id,
                'reason' => $result['reason'] ?? 'unknown',
            ]);

            return;
        }

        Log::info('[GenerateAudioWaveformJob] waveform generated', [
            'asset_id' => $asset->id,
            'path' => $result['path'] ?? null,
        ]);
    }
}
