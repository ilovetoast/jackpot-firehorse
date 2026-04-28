<?php

namespace App\Studio\LayerExtraction\Providers;

use App\Models\Asset;
use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionBoxPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointRefineProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use App\Studio\LayerExtraction\Sam\SamLayerExtractionImage;
use App\Studio\LayerExtraction\Sam\SamSegmentationResult;
use App\Studio\LayerExtraction\Sam\NullSamSegmentationClient;
use App\Studio\LayerExtraction\Sam\SamPromptMapper;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SAM-style multi-prompt segmentation. Uses a remote driver (Fal) when configured; otherwise
 * {@see FloodfillStudioLayerExtractionProvider} (local) with SAM-style metadata.
 */
final class SamStudioLayerExtractionProvider implements
    StudioLayerExtractionProviderInterface,
    StudioLayerExtractionPointPickProviderInterface,
    StudioLayerExtractionPointRefineProviderInterface,
    StudioLayerExtractionBoxPickProviderInterface
{
    private const NOTES_SHIM = 'Local mask detection (flood-fill engine behind SAM mode).';

    private const NOTES_REMOTE = 'AI segmentation.';

    private SamSegmentationClientInterface $samClient;

    public function __construct(
        private FloodfillStudioLayerExtractionProvider $floodfill,
        ?SamSegmentationClientInterface $samClient = null,
    ) {
        $this->samClient = $samClient ?? new NullSamSegmentationClient;
    }

    public function extractMasks(Asset $asset, array $options = []): LayerExtractionResult
    {
        $binary = $this->loadBinary($asset, $options);
        $this->assertInputSize($binary);
        if ($this->useRemoteSegmentation()) {
            $d = $this->dims($binary);
            $promptPayload = SamPromptMapper::forAuto($d['w'], $d['h']);
            $prep = $this->prepareRemote($binary, $options);
            $r = $this->samClient->autoSegment(
                $prep['prepared_binary'],
                $this->remoteCallOptions($prep, $options, 'auto')
            );

            return $this->toLayerExtractionResult($asset, $r, 'auto', $promptPayload, $prep);
        }

        $inner = $this->floodfill->extractMasks($asset, $options);
        $model = (string) config('studio_layer_extraction.sam.model', 'segment_anything_v1');
        $out = [];
        $d = $this->dims($binary);
        $autoPayload = SamPromptMapper::forAuto($d['w'], $d['h']);
        foreach ($inner->candidates as $c) {
            $out[] = $this->mergeShimMetadata($c, 'auto', $autoPayload, [
                'source' => 'auto',
            ]);
        }

        return new LayerExtractionResult(
            provider: 'sam',
            model: $model,
            sourceAssetId: $inner->sourceAssetId,
            candidates: $out,
        );
    }

    public function extractCandidateFromPoint(Asset $asset, float $xNorm, float $yNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        $binary = $this->loadBinary($asset, $options);
        $this->assertInputSize($binary);
        $d = $this->dims($binary);
        $pointPayload = SamPromptMapper::forPoint($xNorm, $yNorm, $d['w'], $d['h']);
        if ($this->useRemoteSegmentation()) {
            $prep = $this->prepareRemote($binary, $options);
            $r = $this->samClient->segmentWithPoints(
                $prep['prepared_binary'],
                [['x' => $xNorm, 'y' => $yNorm]],
                [],
                $this->remoteCallOptions($prep, $options, 'point')
            );
            $label = (string) ($options['label'] ?? 'Picked element');

            return $this->firstRemoteCandidate(
                $r,
                $label,
                'point',
                $pointPayload,
                $prep,
                $options['candidate_id'] ?? null
            );
        }

        $c = $this->floodfill->extractCandidateFromPoint($asset, $xNorm, $yNorm, $options);
        if ($c === null) {
            return null;
        }

        return $this->mergeShimMetadata($c, 'point', $pointPayload, [
            'source' => 'point',
        ]);
    }

    public function extractCandidateFromBox(Asset $asset, array $boxNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        if (! isset($boxNorm['x'], $boxNorm['y'], $boxNorm['width'], $boxNorm['height'])) {
            throw new InvalidArgumentException('Box must include x, y, width, and height.');
        }
        $binary = $this->loadBinary($asset, $options);
        $this->assertInputSize($binary);
        $d = $this->dims($binary);
        $box = [
            'x' => (float) $boxNorm['x'],
            'y' => (float) $boxNorm['y'],
            'width' => (float) $boxNorm['width'],
            'height' => (float) $boxNorm['height'],
        ];
        $boxPayload = SamPromptMapper::forBox($box, $d['w'], $d['h']);
        if ($this->useRemoteSegmentation()) {
            $prep = $this->prepareRemote($binary, $options);
            $pxBox = SamLayerExtractionImage::mapNormBoxToPixelBox($box, (int) $prep['fal_width'], (int) $prep['fal_height']);
            $r = $this->samClient->segmentWithBox(
                $prep['prepared_binary'],
                $pxBox,
                $this->remoteCallOptions($prep, $options, 'box')
            );
            $label = (string) ($options['label'] ?? 'Box-selected element');

            return $this->firstRemoteCandidate(
                $r,
                $label,
                'box',
                $boxPayload,
                $prep,
                $options['candidate_id'] ?? null
            );
        }

        $c = $this->floodfill->extractCandidateFromBox($asset, $boxNorm, $options);
        if ($c === null) {
            return null;
        }

        return $this->mergeShimMetadata($c, 'box', $boxPayload, [
            'source' => 'box',
        ]);
    }

    public function refineCandidateWithPoints(
        Asset $asset,
        LayerExtractionCandidateDto $candidate,
        array $positivePoints,
        array $negativePoints,
        array $options = []
    ): ?LayerExtractionCandidateDto {
        $binary = $this->loadBinary($asset, $options);
        $this->assertInputSize($binary);
        $d = $this->dims($binary);
        $refinePayload = SamPromptMapper::forRefine($positivePoints, $negativePoints, $d['w'], $d['h']);
        if ($this->useRemoteSegmentation() && (($candidate->metadata['segmentation_engine'] ?? null) === 'fal_sam2')) {
            $prep = $this->prepareRemote($binary, $options);
            $r = $this->samClient->segmentWithPoints(
                $prep['prepared_binary'],
                $positivePoints,
                $negativePoints,
                $this->remoteCallOptions($prep, $options, 'refine')
            );
            $label = (string) ($options['label'] ?? ($candidate->label ?? 'Refined'));

            return $this->firstRemoteCandidate(
                $r,
                $label,
                'point_refine',
                $refinePayload,
                $prep,
                (string) $candidate->id
            );
        }

        $c = $this->floodfill->refineCandidateWithPoints(
            $asset,
            $candidate,
            $positivePoints,
            $negativePoints,
            $options
        );
        if ($c === null) {
            return null;
        }

        return $this->mergeShimMetadata($c, 'point_refine', $refinePayload, [
            'source' => 'point',
        ]);
    }

    public function supportsMultipleMasks(): bool
    {
        return true;
    }

    public function supportsBackgroundFill(): bool
    {
        if (! (bool) config('studio_layer_extraction.inpaint_enabled', false)) {
            return false;
        }

        return (string) config('studio_layer_extraction.inpaint_provider', 'none') === 'clipdrop'
            && filled((string) config('services.clipdrop.key'));
    }

    public function supportsLabels(): bool
    {
        return true;
    }

    public function supportsConfidence(): bool
    {
        return ! $this->useRemoteSegmentation();
    }

    private function useRemoteSegmentation(): bool
    {
        if (! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            return false;
        }

        return $this->samClient->isAvailable();
    }

    private function assertInputSize(string $binary): void
    {
        $dims = @getimagesizefromstring($binary);
        if (! is_array($dims) || ($dims[0] ?? 0) < 1 || ($dims[1] ?? 0) < 1) {
            throw new InvalidArgumentException('Unsupported or unreadable image for segmentation.');
        }
        $w = (int) $dims[0];
        $h = (int) $dims[1];
        $maxEdge = max(64, (int) config('studio_layer_extraction.sam.max_input_edge', 4096));
        $maxPx = max(1, (int) config('studio_layer_extraction.sam.max_input_pixels', 16_000_000));
        if (max($w, $h) > $maxEdge) {
            throw new InvalidArgumentException(
                'This image is too large on the long edge for the current segmentation configuration.'
            );
        }
        if ($w * $h > $maxPx) {
            throw new InvalidArgumentException(
                'This image has too many pixels for the current segmentation configuration. Try a smaller resolution.'
            );
        }
    }

    private function dims(string $binary): array
    {
        $dims = @getimagesizefromstring($binary);
        if (! is_array($dims) || ($dims[0] ?? 0) < 1) {
            throw new InvalidArgumentException('Unsupported or unreadable image for segmentation.');

        }

        return ['w' => (int) $dims[0], 'h' => (int) $dims[1]];
    }

    /**
     * @param  array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}  $prep
     * @param  array<string, mixed>  $options
     * @return array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}
     */
    private function prepareRemote(string $binary, array $options): array
    {
        $maxMb = max(1, (int) config('studio_layer_extraction.sam.max_source_mb', 25));
        $im = SamLayerExtractionImage::assertSourceConstraints($binary, $maxMb);
        $maxEdge = max(256, (int) config('studio_layer_extraction.sam.fal_max_long_edge', 2048));
        $down = SamLayerExtractionImage::downscaleToMaxLongEdge($binary, $maxEdge);

        return [
            'prepared_binary' => $down['binary'],
            'fal_width' => $down['w'],
            'fal_height' => $down['h'],
            'orig_width' => $down['orig_w'],
            'orig_height' => $down['orig_h'],
            'image_mime' => $im['mime'],
            'scale' => $down['scale'],
        ];
    }

    /**
     * @param  array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}  $prep
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function remoteCallOptions(array $prep, array $options, string $falLogMode = 'auto'): array
    {
        return array_merge(
            $options,
            [
                'fal_width' => (int) $prep['fal_width'],
                'fal_height' => (int) $prep['fal_height'],
                'orig_width' => (int) $prep['orig_width'],
                'orig_height' => (int) $prep['orig_height'],
                'image_mime' => (string) $prep['image_mime'],
                'timeout_seconds' => (int) config('studio_layer_extraction.sam.timeout', 120),
                'fal_log_mode' => $falLogMode,
            ]
        );
    }

    /**
     * @param  array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}  $prep
     * @param  array<string, mixed>  $promptExtra
     * @return list<array<string, mixed>>  Merged remote metadata
     */
    private function buildRemoteMetadata(
        string $promptType,
        array $promptExtra,
        string $configModel,
        SamSegmentationResult $r
    ): array {
        $meta = [
            'provider' => 'sam',
            'model' => $r->model !== '' ? $r->model : $configModel,
            'prompt_type' => $promptType,
            'segmentation_engine' => 'fal_sam2',
            'remote_engine' => $r->engine,
            'remote_duration_ms' => $r->durationMs,
        ];
        foreach ($promptExtra as $k => $v) {
            if ($k === 'mode') {
                continue;
            }
            $meta[$k] = $v;
        }
        if (! isset($meta['note']) || $meta['note'] === '') {
            $meta['note'] = 'AI segmentation (remote)';
        }
        if ($promptType === 'auto') {
            $meta['method'] = 'fal_sam2_auto';
        } elseif ($promptType === 'point') {
            $meta['method'] = 'fal_sam2_point';
        } elseif ($promptType === 'box') {
            $meta['method'] = 'fal_sam2_box';
        } elseif ($promptType === 'point_refine') {
            $meta['method'] = 'fal_sam2_refine';
        } else {
            $meta['method'] = 'fal_sam2';
        }

        return $meta;
    }

    /**
     * @param  array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}  $prep
     * @param  array<string, mixed>  $promptExtra
     */
    private function toLayerExtractionResult(
        Asset $asset,
        SamSegmentationResult $r,
        string $promptType,
        array $promptExtra,
        array $prep
    ): LayerExtractionResult {
        $candidates = [];
        $ow = (int) $prep['orig_width'];
        $oh = (int) $prep['orig_height'];
        $sw = (int) $prep['fal_width'];
        $sh = (int) $prep['fal_height'];
        $configModel = (string) config('studio_layer_extraction.sam.model', 'sam2');
        foreach ($r->segments as $seg) {
            $mask = $seg->maskPngBinary;
            if ($ow !== $sw || $oh !== $sh) {
                $mask = SamLayerExtractionImage::scaleMaskPngToSize($mask, $ow, $oh);
            }
            $bbox = SamLayerExtractionImage::bboxFromForegroundMaskPng($mask)
                ?? $this->scaleBboxToOriginal($seg->bbox, $ow, $oh, $sw, $sh);
            $candidates[] = new LayerExtractionCandidateDto(
                (string) Str::uuid(),
                $seg->label,
                $seg->confidence,
                $bbox,
                null,
                base64_encode($mask),
                null,
                true,
                self::NOTES_REMOTE,
                $this->buildRemoteMetadata($promptType, $promptExtra, $configModel, $r)
            );
        }
        if ($candidates === []) {
            throw new InvalidArgumentException('The segmentation service returned no usable masks.');
        }
        $model = $r->model !== '' ? $r->model : $configModel;

        return new LayerExtractionResult(
            provider: 'sam',
            model: $model,
            sourceAssetId: (string) $asset->getKey(),
            candidates: $candidates,
        );
    }

    /**
     * @param  array{prepared_binary: string, fal_width: int, fal_height: int, orig_width: int, orig_height: int, image_mime: string, scale: float}  $prep
     * @param  array<string, mixed>  $promptExtra
     */
    private function firstRemoteCandidate(
        SamSegmentationResult $r,
        string $defaultLabel,
        string $promptType,
        array $promptExtra,
        array $prep,
        ?string $idOverride
    ): ?LayerExtractionCandidateDto {
        if ($r->segments === []) {
            return null;
        }
        $seg = $r->segments[0];
        $ow = (int) $prep['orig_width'];
        $oh = (int) $prep['orig_height'];
        $sw = (int) $prep['fal_width'];
        $sh = (int) $prep['fal_height'];
        $mask = $seg->maskPngBinary;
        if ($ow !== $sw || $oh !== $sh) {
            $mask = SamLayerExtractionImage::scaleMaskPngToSize($mask, $ow, $oh);
        }
        $bbox = SamLayerExtractionImage::bboxFromForegroundMaskPng($mask)
            ?? $this->scaleBboxToOriginal($seg->bbox, $ow, $oh, $sw, $sh);
        $id = $idOverride !== null && $idOverride !== '' ? (string) $idOverride : (string) Str::uuid();
        $configModel = (string) config('studio_layer_extraction.sam.model', 'sam2');

        return new LayerExtractionCandidateDto(
            $id,
            $defaultLabel,
            $seg->confidence,
            $bbox,
            null,
            base64_encode($mask),
            null,
            true,
            self::NOTES_REMOTE,
            $this->buildRemoteMetadata($promptType, $promptExtra, $configModel, $r)
        );
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $bbox
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function scaleBboxToOriginal(array $bbox, int $ow, int $oh, int $sw, int $sh): array
    {
        if ($sw < 1 || $sh < 1) {
            return $bbox;
        }
        $sx = $ow / $sw;
        $sy = $oh / $sh;
        $x = (int) max(0, min($ow - 1, (int) floor($bbox['x'] * $sx)));
        $y = (int) max(0, min($oh - 1, (int) floor($bbox['y'] * $sy)));
        $w = max(1, (int) min($ow - $x, (int) ceil($bbox['width'] * $sx)));
        $h = max(1, (int) min($oh - $y, (int) ceil($bbox['height'] * $sy)));

        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    /**
     * @param  array<string, mixed>  $promptPayload
     * @param  array<string, mixed>  $override
     */
    private function mergeShimMetadata(
        LayerExtractionCandidateDto $c,
        string $promptType,
        array $promptPayload,
        array $override = []
    ): LayerExtractionCandidateDto {
        $model = (string) config('studio_layer_extraction.sam.model', 'segment_anything_v1');
        $meta = $c->metadata ?? [];
        $meta['provider'] = 'sam';
        $meta['model'] = $model;
        $meta['prompt_type'] = $promptType;
        $meta['segmentation_engine'] = 'floodfill_shim';
        foreach ($promptPayload as $k => $v) {
            if ($k === 'mode') {
                continue;
            }
            $meta[$k] = $v;
        }
        if (! isset($meta['note']) || (is_string($meta['note']) && $meta['note'] === '')) {
            $meta['note'] = 'SAM-style local mask (delegated engine)';
        }
        foreach ($override as $k => $v) {
            $meta[$k] = $v;
        }
        if (! is_string($meta['note'] ?? null) || $meta['note'] === '') {
            $meta['note'] = 'SAM-style local mask (delegated engine)';
        }

        return new LayerExtractionCandidateDto(
            $c->id,
            $c->label,
            $c->confidence,
            $c->bbox,
            $c->maskPath,
            $c->maskBase64,
            $c->previewPath,
            $c->selected,
            $c->notes !== null && $c->notes !== '' ? $c->notes : self::NOTES_SHIM,
            $meta
        );
    }

    /**
     * @param  array{image_binary?: string}  $options
     */
    private function loadBinary(Asset $asset, array $options): string
    {
        $b = $options['image_binary'] ?? null;
        if (is_string($b) && $b !== '') {
            return $b;
        }

        return EditorAssetOriginalBytesLoader::loadFromStorage($asset);
    }
}
