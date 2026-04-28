<?php

namespace Tests\Fixtures;

use App\Models\Asset;
use App\Models\StudioLayerExtractionSession;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionBoxPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointRefineProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;

/**
 * Test double: same segmentation as {@see FloodfillStudioLayerExtractionProvider} plus a stub “inpaint” fill.
 */
final class MockInpaintLayerExtractionProvider implements StudioLayerExtractionProviderInterface, StudioLayerExtractionInpaintBackgroundInterface, StudioLayerExtractionPointPickProviderInterface, StudioLayerExtractionPointRefineProviderInterface, StudioLayerExtractionBoxPickProviderInterface
{
    private readonly FloodfillStudioLayerExtractionProvider $inner;

    public function __construct(
        private readonly bool $multiCandidate = false
    ) {
        $this->inner = new FloodfillStudioLayerExtractionProvider;
    }

    public function extractMasks(Asset $asset, array $options = []): LayerExtractionResult
    {
        $result = $this->inner->extractMasks($asset, $options);
        if (! $this->multiCandidate) {
            return $result;
        }
        $dup = $result->candidates[0];
        $candidates = $result->candidates;
        $candidates[] = new LayerExtractionCandidateDto(
            id: 'second',
            label: 'Second (test duplicate)',
            confidence: 0.5,
            bbox: $dup->bbox,
            maskPath: $dup->maskPath,
            maskBase64: $dup->maskBase64,
            previewPath: $dup->previewPath,
            selected: true,
            notes: 'Duplicate mask for feature tests only.',
            metadata: null,
        );

        return new LayerExtractionResult(
            provider: $result->provider.'+mock',
            model: $result->model,
            sourceAssetId: $result->sourceAssetId,
            candidates: $candidates,
        );
    }

    public function supportsMultipleMasks(): bool
    {
        return $this->multiCandidate;
    }

    public function supportsBackgroundFill(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return true;
    }

    public function supportsConfidence(): bool
    {
        return true;
    }

    public function extractCandidateFromPoint(Asset $asset, float $xNorm, float $yNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        return $this->inner->extractCandidateFromPoint($asset, $xNorm, $yNorm, $options);
    }

    public function extractCandidateFromBox(Asset $asset, array $boxNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        return $this->inner->extractCandidateFromBox($asset, $boxNorm, $options);
    }

    public function refineCandidateWithPoints(
        Asset $asset,
        LayerExtractionCandidateDto $candidate,
        array $positivePoints,
        array $negativePoints,
        array $options = [],
    ): ?LayerExtractionCandidateDto {
        return $this->inner->refineCandidateWithPoints($asset, $candidate, $positivePoints, $negativePoints, $options);
    }

    public function buildFilledBackground(
        Asset $sourceAsset,
        string $sourceBinary,
        string $combinedForegroundMaskPng,
        StudioLayerExtractionSession $session,
    ): string {
        $im = @imagecreatefromstring($sourceBinary);
        if ($im === false) {
            return $sourceBinary;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $m = @imagecreatefromstring($combinedForegroundMaskPng);
        if ($m === false) {
            imagedestroy($im);

            return $sourceBinary;
        }
        if (! imageistruecolor($m)) {
            imagepalettetotruecolor($m);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $mc = imagecolorat($m, $x, $y);
                $mr = ($mc >> 16) & 0xFF;
                $mg = ($mc >> 8) & 0xFF;
                $mbit = $mc & 0xFF;
                $mma = ($mc >> 24) & 127;
                $wgt = (($mr + $mg + $mbit) / 3.0) / 255.0 * (127 - $mma) / 127.0;
                if ($wgt > 0.35) {
                    $c = imagecolorallocate($im, 240, 240, 240);
                    imagesetpixel($im, $x, $y, $c);
                }
            }
        }
        imagedestroy($m);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png !== '' ? $png : $sourceBinary;
    }
}
