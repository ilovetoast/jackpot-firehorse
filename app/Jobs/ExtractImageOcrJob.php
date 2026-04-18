<?php

namespace App\Jobs;

use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Services\BrandIntelligence\VisualEvaluationSourceResolver;
use App\Services\ImageOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Run OCR on an image asset's visual evaluation raster and persist the result
 * into asset.metadata.ocr_text.
 *
 * Triggered manually from the "Run OCR" drawer action when the scoring engine
 * detected a text-coverage gap (recommend_ocr_rerun=true) or directly by an
 * operator. PDF-origin OCR still flows through {@see ExtractPdfTextJob}.
 */
class ExtractImageOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public int $timeout = 180;

    public function __construct(
        public readonly string $assetId,
        public readonly ?string $triggeredByUserId = null,
    ) {
        $this->configureImagesQueue();
    }

    public function handle(
        ImageOcrService $ocrService,
        VisualEvaluationSourceResolver $visualResolver,
    ): void {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning('[ExtractImageOcrJob] Asset not found', ['asset_id' => $this->assetId]);

            return;
        }

        if (! $ocrService->isAvailable()) {
            Log::error('[ExtractImageOcrJob] tesseract not available on this system', [
                'asset_id' => $asset->id,
            ]);
            $this->persistOcrResult($asset, '', 'tesseract_unavailable', false);

            return;
        }

        $resolved = $visualResolver->resolve($asset);
        $path = $resolved['storage_path'] ?? null;
        if (! is_string($path) || $path === '') {
            Log::warning('[ExtractImageOcrJob] No raster storage path resolved', [
                'asset_id' => $asset->id,
            ]);
            $this->persistOcrResult($asset, '', 'no_raster', false);

            return;
        }

        $tempPath = $this->downloadRasterToTemp($path);
        if ($tempPath === null) {
            Log::warning('[ExtractImageOcrJob] Could not download raster for OCR', [
                'asset_id' => $asset->id,
                'path' => $path,
            ]);
            $this->persistOcrResult($asset, '', 'download_failed', false);

            return;
        }

        try {
            $result = $ocrService->extractFromPath($tempPath);
            $this->persistOcrResult(
                $asset,
                (string) $result['text'],
                (string) $result['source'],
                (bool) $result['truncated'],
            );

            Log::info('[ExtractImageOcrJob] OCR completed', [
                'asset_id' => $asset->id,
                'characters' => mb_strlen($result['text']),
                'source' => $result['source'],
                'truncated' => $result['truncated'],
            ]);

            if (mb_strlen(trim($result['text'])) > 0) {
                $this->rescoreIfEligible($asset);
            }
        } catch (\Throwable $e) {
            Log::error('[ExtractImageOcrJob] OCR failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->persistOcrResult($asset, '', 'exception', false);

            throw $e;
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function downloadRasterToTemp(string $storagePath): ?string
    {
        foreach (['s3', 'public', 'local'] as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if (! $disk->exists($storagePath)) {
                    continue;
                }
                $bytes = $disk->get($storagePath);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }
                $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION)) ?: 'png';
                $tmp = tempnam(sys_get_temp_dir(), 'ocr_img_');
                if ($tmp === false) {
                    return null;
                }
                $tmp .= '.' . $ext;
                if (@file_put_contents($tmp, $bytes) === false) {
                    return null;
                }

                return $tmp;
            } catch (\Throwable $e) {
                Log::debug('[ExtractImageOcrJob] Disk read failed; trying next', [
                    'disk' => $diskName,
                    'path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function persistOcrResult(Asset $asset, string $text, string $source, bool $truncated): void
    {
        $metadata = is_array($asset->metadata ?? null) ? $asset->metadata : [];

        $metadata['ocr_text'] = $text;
        $metadata['ocr'] = [
            'source' => $source,
            'character_count' => mb_strlen($text),
            'truncated' => $truncated,
            'completed_at' => now()->toIso8601String(),
            'triggered_by_user_id' => $this->triggeredByUserId,
        ];

        $asset->update(['metadata' => $metadata]);
    }

    private function rescoreIfEligible(Asset $asset): void
    {
        try {
            if ($asset->resolveCategoryForTenant()?->isEbiEnabled()) {
                \App\Jobs\ScoreAssetBrandIntelligenceJob::dispatch($asset->id);
            }
        } catch (\Throwable $e) {
            Log::warning('[ExtractImageOcrJob] Rescore dispatch failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
