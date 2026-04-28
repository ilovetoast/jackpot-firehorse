<?php

namespace App\Studio\LayerExtraction\Providers;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use App\Studio\LayerExtraction\Sam\SamLayerExtractionImage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Remote background cleanup: sends original + union foreground mask to Clipdrop Cleanup.
 *
 * @see https://clipdrop.co/apis/docs/cleanup
 */
final class ClipdropInpaintBackgroundProvider implements StudioLayerExtractionInpaintBackgroundInterface
{
    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function supportsBackgroundFill(): bool
    {
        return $this->apiKey !== '';
    }

    public function buildFilledBackground(
        Asset $sourceAsset,
        string $sourceBinary,
        string $combinedForegroundMaskPng,
        StudioLayerExtractionSession $session,
    ): string {
        if ($this->apiKey === '') {
            throw new RuntimeException('Clipdrop API key is not configured.');
        }
        $maxMb = (int) config('studio_layer_extraction.inpaint.max_source_mb', 25);
        SamLayerExtractionImage::assertSourceConstraints($sourceBinary, $maxMb);
        SamLayerExtractionImage::assertSourceConstraints($combinedForegroundMaskPng, $maxMb);
        $url = (string) config('services.clipdrop.cleanup_endpoint', 'https://clipdrop-api.co/cleanup/v1');
        $timeout = (int) config('studio_layer_extraction.inpaint.timeout', 120);
        $ext = $this->extFromBinary($sourceBinary);
        try {
            $resp = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->timeout(max(1, $timeout))
                ->attach('image_file', $sourceBinary, 'source.'.$ext)
                ->attach('mask_file', $combinedForegroundMaskPng, 'mask.png')
                ->post($url);
        } catch (Throwable) {
            throw new RuntimeException('The background fill service is temporarily unavailable. Please try again.');
        }
        if (! $resp->ok() || $resp->body() === '') {
            if (config('app.debug', false)) {
                $resp->throw();
            }
            throw new RuntimeException('Background fill was rejected by the remote service. Try a smaller image or different selection.');
        }

        return (string) $resp->body();
    }

    private function extFromBinary(string $binary): string
    {
        $info = @getimagesizefromstring($binary);
        $m = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if ($m === 'image/jpeg' || $m === 'image/jpg') {
            return 'jpg';
        }
        if ($m === 'image/webp') {
            return 'webp';
        }
        if ($m === 'image/gif') {
            return 'gif';
        }

        return 'png';
    }
}
