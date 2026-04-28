<?php

namespace App\Studio\LayerExtraction\Providers;

use App\Models\Asset;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionBoxPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointRefineProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use App\Support\EditorAssetOriginalBytesLoader;
use InvalidArgumentException;
use RuntimeException;
use SplQueue;

/**
 * Edge-connected “background” flood fill from corner colors — useful for products on
 * light backdrops. Optional multi-candidate mode: 4-connected foreground components on
 * a downscaled analysis image. Not a SAM replacement; swap {@see StudioLayerExtractionProviderInterface} impl for APIs.
 */
final class FloodfillStudioLayerExtractionProvider implements StudioLayerExtractionProviderInterface, StudioLayerExtractionPointPickProviderInterface, StudioLayerExtractionPointRefineProviderInterface, StudioLayerExtractionBoxPickProviderInterface
{
    /**
     * {@see \IMG_NEAREST_NEIGHBOR} and {@see \IMG_BILINEAR_FIXED} — use ints so it works in namespaced
     * code and when ext-gd is loaded without the usual constants (e.g. odd builds).
     *
     * @see https://www.php.net/manual/en/image.constants.php
     */
    private const IMAGE_SCALE_NEAREST = 3;

    private const IMAGE_SCALE_BILINEAR_FIXED = 5;

    private const NOTES_COPY = 'Local mask detection. Results are editable cutout layers, not original Photoshop layers.';

    public function extractMasks(Asset $asset, array $options = []): LayerExtractionResult
    {
        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        }

        $cfgF = config('studio_layer_extraction.floodfill', []);
        $cfgL = config('studio_layer_extraction.local_floodfill', []);
        $maxEdge = max(64, (int) ($cfgF['max_segmentation_edge'] ?? 1024));
        $tolerance = max(1, (int) ($cfgF['color_tolerance'] ?? 45));
        $maxAnalysisPx = max(200_000, (int) ($cfgL['max_analysis_pixels'] ?? 3_000_000));
        $multi = (bool) ($cfgL['enable_multi_candidate'] ?? true);
        $maxCands = max(1, (int) ($cfgL['max_candidates'] ?? 6));
        $minAr = (float) ($cfgL['min_area_ratio'] ?? 0.01);
        $maxAr = (float) ($cfgL['max_area_ratio'] ?? 0.85);
        $mergeIou = (float) ($cfgL['merge_iou_threshold'] ?? 0.65);

        $dims = @getimagesizefromstring($binary);
        if (! is_array($dims) || ($dims[0] ?? 0) < 2 || ($dims[1] ?? 0) < 2) {
            throw new InvalidArgumentException('Unsupported or unreadable image for extraction.');
        }

        $fullW = (int) $dims[0];
        $fullH = (int) $dims[1];
        if ($fullW * $fullH > $maxAnalysisPx) {
            throw new InvalidArgumentException(
                'This image is too large for local extraction. Try a smaller resolution, or add a paid segmentation provider later when available.'
            );
        }

        $src = $this->decodeToTrueColorGd($binary);

        $scale = min(1.0, $maxEdge / max($fullW, $fullH));
        $segW = max(2, (int) round($fullW * $scale));
        $segH = max(2, (int) round($fullH * $scale));

        $seg = imagescale($src, $segW, $segH, self::IMAGE_SCALE_NEAREST);
        if ($seg === false) {
            $seg = imagescale($src, $segW, $segH, self::IMAGE_SCALE_BILINEAR_FIXED);
        }
        if ($seg === false) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $seg = imagecreatetruecolor($segW, $segH);
            if ($seg === false) {
                imagedestroy($src);

                throw new RuntimeException('Failed to scale image for segmentation.');
            }
            imagealphablending($src, true);
            imagesavealpha($src, true);
            imagecopyresampled($seg, $src, 0, 0, 0, 0, $segW, $segH, $sw, $sh);
        }
        imagedestroy($src);

        $bgRgb = $this->averageCornerRgb($seg, $segW, $segH);
        $bgMask = $this->buildBackgroundMask($seg, $segW, $segH, $bgRgb, $tolerance);
        imagedestroy($seg);

        $total = $segW * $segH;
        $fgCount = 0;
        for ($i = 0; $i < $total; $i++) {
            if ($bgMask[$i] !== "\x01") {
                $fgCount++;
            }
        }
        if ($fgCount === 0) {
            throw new InvalidArgumentException('No separable elements found. Try a photo with a clear subject on a distinct background.');
        }
        $fgTotalRatio = $fgCount / $total;
        if ($fgTotalRatio < $minAr) {
            throw new InvalidArgumentException('No separable elements found. The visible subject may be too small in this image.');
        }

        $model = (string) ($cfgF['model'] ?? 'gd_floodfill_v1');

        if (! $multi) {
            return $this->resultFromCandidates(
                $asset,
                $model,
                [
                    $this->buildCandidateFromUnionMask(
                        'subject',
                        'Detected element',
                        $bgMask,
                        $segW,
                        $segH,
                        $scale,
                        $fullW,
                        $fullH
                    ),
                ]
            );
        }

        [$labelGrid, $byId] = $this->labelForegroundComponents($bgMask, $segW, $segH);
        $items = [];
        foreach (array_keys($byId) as $cid) {
            $info = $byId[$cid];
            $ar = $info['count'] / $total;
            if ($ar < $minAr || $ar > $maxAr) {
                continue;
            }
            $items[] = [
                'id' => (int) $cid,
                'count' => $info['count'],
                'bbox' => $info['bbox'],
            ];
        }

        usort($items, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $items = $this->dedupeCandidatesByBboxIou($items, $mergeIou);
        $items = array_slice($items, 0, $maxCands);

        if ($items === []) {
            return $this->resultFromCandidates(
                $asset,
                $model,
                [
                    $this->buildCandidateFromUnionMask(
                        'el_1',
                        'Detected element',
                        $bgMask,
                        $segW,
                        $segH,
                        $scale,
                        $fullW,
                        $fullH
                    ),
                ]
            );
        }

        $candidates = [];
        $k = 0;
        foreach ($items as $row) {
            $k++;
            $subBg = $this->buildBgMaskForComponent($bgMask, $labelGrid, $segW, $segH, (int) $row['id']);
            $areaR = $row['count'] / $total;
            if ($k === 1) {
                $label = 'Detected element';
            } else {
                $bw = max(1, (int) $row['bbox']['width']);
                $bh = max(1, (int) $row['bbox']['height']);
                $aspect = $bw / $bh;
                $label = ($aspect >= 3.0 || $aspect <= 1 / 3.0)
                    ? 'Text/graphic-like region'
                    : 'Detected element '.$k;
            }
            $candidates[] = $this->buildCandidateFromBgMask(
                'el_'.$k,
                $label,
                $subBg,
                $segW,
                $segH,
                $scale,
                $fullW,
                $fullH,
                $areaR,
                (int) $row['id']
            );
        }

        return $this->resultFromCandidates($asset, $model, $candidates);
    }

    public function extractCandidateFromPoint(Asset $asset, float $xNorm, float $yNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        if ($xNorm < 0.0 || $xNorm > 1.0 || $yNorm < 0.0 || $yNorm > 1.0) {
            throw new InvalidArgumentException('Pick coordinates must be between 0 and 1.');
        }
        $label = (string) ($options['label'] ?? '');
        $candidateId = (string) ($options['candidate_id'] ?? '');
        if ($label === '' || $candidateId === '') {
            throw new InvalidArgumentException('Point pick requires label and candidate_id in options.');
        }

        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        }

        $state = $this->loadLocalSegmentationState($binary);
        $bgMask = $state['bgMask'];
        $segW = $state['segW'];
        $segH = $state['segH'];
        $scale = $state['scale'];
        $fullW = $state['fullW'];
        $fullH = $state['fullH'];
        $total = $segW * $segH;
        $minAr = $state['minAr'];
        $maxAr = $state['maxAr'];
        $mergeIou = $state['mergeIou'];
        /** @var list<array{x:int,y:int,width:int,height:int}> $existingBboxes */
        $existingBboxes = is_array($options['existing_bboxes'] ?? null) ? $options['existing_bboxes'] : [];

        $px = (int) max(0, min($fullW - 1, (int) round($xNorm * max(1, $fullW - 1))));
        $py = (int) max(0, min($fullH - 1, (int) round($yNorm * max(1, $fullH - 1))));

        $sx = (int) max(0, min($segW - 1, (int) floor($px * $segW / $fullW)));
        $sy = (int) max(0, min($segH - 1, (int) floor($py * $segH / $fullH)));
        $seed = $sy * $segW + $sx;
        if ($bgMask[$seed] === "\x01") {
            return null;
        }

        $comp = $this->connectedForegroundFromSeed($bgMask, $segW, $segH, $seed);
        if ($comp === []) {
            return null;
        }
        $compCount = count($comp);
        $areaR = $compCount / $total;
        if ($areaR < $minAr || $areaR > $maxAr) {
            return null;
        }

        $compMask = $this->buildForegroundMaskForIndices($comp, $segW, $segH, $total);
        $bboxSeg = $this->bboxFromFlatIndices($comp, $segW, $segH);
        if ($bboxSeg === null) {
            return null;
        }
        $bboxFull = $this->mapSegBboxToFull($bboxSeg, $scale, $fullW, $fullH);
        foreach ($existingBboxes as $eb) {
            if (is_array($eb) && $this->bboxIou($bboxFull, $eb) > $mergeIou) {
                return null;
            }
        }

        $metadata = [
            'method' => 'local_seed_floodfill',
            'seed_point_normalized' => ['x' => $xNorm, 'y' => $yNorm],
            'seed_point_pixels' => ['x' => $px, 'y' => $py],
            'prompt_type' => 'point',
            'positive_points' => [['x' => $xNorm, 'y' => $yNorm]],
            'negative_points' => [],
            'provider' => 'floodfill',
            'area_ratio' => $areaR,
            'note' => 'Local mask detection',
        ];

        return $this->buildCandidateFromBgMask(
            $candidateId,
            $label,
            $compMask,
            $segW,
            $segH,
            $scale,
            $fullW,
            $fullH,
            $areaR,
            0,
            $metadata
        );
    }

    /**
     * @param  list<array{x: float, y: float}>  $positivePoints
     * @param  list<array{x: float, y: float}>  $negativePoints
     * @param  array{image_binary?: string}  $options
     */
    public function refineCandidateWithPoints(
        Asset $asset,
        LayerExtractionCandidateDto $candidate,
        array $positivePoints,
        array $negativePoints,
        array $options = []
    ): ?LayerExtractionCandidateDto {
        if (! (bool) config('studio_layer_extraction.local_floodfill.refine_enabled', true)) {
            return null;
        }
        if (! str_starts_with($candidate->id, 'pick_')) {
            return null;
        }
        $meta = $candidate->metadata ?? [];
        $method = (string) ($meta['method'] ?? '');
        if ($method !== 'local_seed_floodfill' && $method !== 'local_seed_floodfill_refined') {
            return null;
        }
        if ($positivePoints === []) {
            return null;
        }
        if ($negativePoints === [] && count($positivePoints) < 2) {
            return null;
        }

        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        }

        $state = $this->loadLocalSegmentationState($binary);
        $bgMask = $state['bgMask'];
        $segW = $state['segW'];
        $segH = $state['segH'];
        $scale = $state['scale'];
        $fullW = $state['fullW'];
        $fullH = $state['fullH'];
        $total = $segW * $segH;
        $minAr = $state['minAr'];
        $maxAr = $state['maxAr'];
        $cfgL = config('studio_layer_extraction.local_floodfill', []);
        $negRatio = (float) ($cfgL['negative_point_radius_ratio'] ?? 0.04);
        $r = max(1, (int) round($negRatio * max($segW, $segH)));

        $p0 = $positivePoints[0];
        $xNorm = (float) $p0['x'];
        $yNorm = (float) $p0['y'];
        if ($xNorm < 0.0 || $xNorm > 1.0 || $yNorm < 0.0 || $yNorm > 1.0) {
            return null;
        }
        $px = (int) max(0, min($fullW - 1, (int) round($xNorm * max(1, $fullW - 1))));
        $py = (int) max(0, min($fullH - 1, (int) round($yNorm * max(1, $fullH - 1))));

        $unionMap = [];
        $seeds = [];
        foreach ($positivePoints as $pp) {
            $xN = (float) $pp['x'];
            $yN = (float) $pp['y'];
            if ($xN < 0.0 || $xN > 1.0 || $yN < 0.0 || $yN > 1.0) {
                continue;
            }
            $ppx = (int) max(0, min($fullW - 1, (int) round($xN * max(1, $fullW - 1))));
            $ppy = (int) max(0, min($fullH - 1, (int) round($yN * max(1, $fullH - 1))));
            $sx2 = (int) max(0, min($segW - 1, (int) floor($ppx * $segW / $fullW)));
            $sy2 = (int) max(0, min($segH - 1, (int) floor($ppy * $segH / $fullH)));
            $sidx = $sy2 * $segW + $sx2;
            if ($bgMask[$sidx] === "\x01") {
                continue;
            }
            $seeds[] = $sidx;
            $candComp = $this->connectedForegroundFromSeed($bgMask, $segW, $segH, $sidx);
            foreach ($candComp as $i) {
                if ($i >= 0 && $i < $total) {
                    $unionMap[$i] = true;
                }
            }
        }
        if ($seeds === [] || $unionMap === []) {
            return null;
        }
        $comp = array_keys($unionMap);
        $inComp = array_fill(0, $total, false);
        foreach ($comp as $i) {
            $inComp[$i] = true;
        }

        $seedProtect = [];
        foreach (array_unique($seeds) as $s) {
            if ($s >= 0 && $s < $total) {
                $seedProtect[$s] = true;
            }
        }

        $excluded = [];
        foreach ($negativePoints as $np) {
            $nx = (float) $np['x'];
            $ny = (float) $np['y'];
            if ($nx < 0.0 || $nx > 1.0 || $ny < 0.0 || $ny > 1.0) {
                continue;
            }
            $npx = (int) max(0, min($fullW - 1, (int) round($nx * max(1, $fullW - 1))));
            $npy = (int) max(0, min($fullH - 1, (int) round($ny * max(1, $fullH - 1))));
            $nsx = (int) max(0, min($segW - 1, (int) floor($npx * $segW / $fullW)));
            $nsy = (int) max(0, min($segH - 1, (int) floor($npy * $segH / $fullH)));
            foreach ($comp as $i) {
                if (isset($seedProtect[$i])) {
                    continue;
                }
                $cx = $i % $segW;
                $cy = (int) floor($i / $segW);
                if (hypot((float) $cx - (float) $nsx, (float) $cy - (float) $nsy) <= (float) $r) {
                    $excluded[$i] = true;
                }
            }
        }

        $vis = str_repeat("\0", $total);
        $final = [];
        $uniqueSeeds = array_values(array_unique($seeds));
        foreach ($uniqueSeeds as $start) {
            if (! ($inComp[$start] ?? false) || isset($excluded[$start]) || $vis[$start] === "\x01") {
                continue;
            }
            $q = new SplQueue;
            $q->enqueue($start);
            $vis[$start] = "\x01";
            while (! $q->isEmpty()) {
                $i = (int) $q->dequeue();
                if (isset($excluded[$i])) {
                    continue;
                }
                $final[] = $i;
                $x = $i % $segW;
                $y = (int) floor($i / $segW);
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as $d) {
                    $d0 = (int) $d[0];
                    $d1 = (int) $d[1];
                    $nx2 = $x + $d0;
                    $ny2 = $y + $d1;
                    if ($nx2 < 0 || $ny2 < 0 || $nx2 >= $segW || $ny2 >= $segH) {
                        continue;
                    }
                    $ni = $ny2 * $segW + $nx2;
                    if ($vis[$ni] === "\x01" || $bgMask[$ni] === "\x01" || ! $inComp[$ni] || isset($excluded[$ni])) {
                        continue;
                    }
                    $vis[$ni] = "\x01";
                    $q->enqueue($ni);
                }
            }
        }

        if ($final === []) {
            return null;
        }
        $compCount = count($final);
        $areaR = $compCount / $total;
        if ($areaR < $minAr || $areaR > $maxAr) {
            return null;
        }

        $compMask = $this->buildForegroundMaskForIndices($final, $segW, $segH, $total);
        $refineCount = count($negativePoints);
        $negMeta = [];
        $negPix = [];
        foreach ($negativePoints as $np) {
            $negMeta[] = ['x' => (float) $np['x'], 'y' => (float) $np['y']];
            $npx = (int) max(0, min($fullW - 1, (int) round((float) $np['x'] * max(1, $fullW - 1))));
            $npy = (int) max(0, min($fullH - 1, (int) round((float) $np['y'] * max(1, $fullH - 1))));
            $negPix[] = ['x' => $npx, 'y' => $npy];
        }
        $posMeta = [];
        foreach ($positivePoints as $pp) {
            $posMeta[] = ['x' => (float) $pp['x'], 'y' => (float) $pp['y']];
        }

        $metadata = [
            'method' => 'local_seed_floodfill_refined',
            'seed_point_normalized' => ['x' => $xNorm, 'y' => $yNorm],
            'seed_point_pixels' => ['x' => $px, 'y' => $py],
            'prompt_type' => 'point_refine',
            'positive_points' => $posMeta,
            'negative_points' => $negMeta,
            'negative_points_pixels' => $negPix,
            'refined' => true,
            'refine_count' => $refineCount,
            'provider' => 'floodfill',
            'area_ratio' => $areaR,
            'note' => 'Local mask detection (refined)',
        ];

        return $this->buildCandidateFromBgMask(
            $candidate->id,
            $candidate->label ?? 'Picked element',
            $compMask,
            $segW,
            $segH,
            $scale,
            $fullW,
            $fullH,
            $areaR,
            0,
            $metadata
        );
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $boxNorm
     * @param  array{image_binary?: string, label: string, candidate_id: string, mode?: string}  $options
     */
    public function extractCandidateFromBox(Asset $asset, array $boxNorm, array $options = []): ?LayerExtractionCandidateDto
    {
        if (! (bool) config('studio_layer_extraction.local_floodfill.box_pick_enabled', true)) {
            return null;
        }
        if (! isset($boxNorm['x'], $boxNorm['y'], $boxNorm['width'], $boxNorm['height'])) {
            throw new InvalidArgumentException('Box must include x, y, width, and height.');
        }
        $label = (string) ($options['label'] ?? '');
        $candidateId = (string) ($options['candidate_id'] ?? '');
        if ($label === '' || $candidateId === '') {
            throw new InvalidArgumentException('Box pick requires label and candidate_id in options.');
        }

        $xn = (float) $boxNorm['x'];
        $yn = (float) $boxNorm['y'];
        $wN = (float) $boxNorm['width'];
        $hN = (float) $boxNorm['height'];
        $xn = max(0.0, min(1.0, $xn));
        $yn = max(0.0, min(1.0, $yn));
        if ($wN <= 0.0 || $hN <= 0.0) {
            return null;
        }
        $wN = min($wN, 1.0 - $xn);
        $hN = min($hN, 1.0 - $yn);

        $minBoxR = (float) config('studio_layer_extraction.local_floodfill.box_min_size_ratio', 0.02);
        $maxBoxR = (float) config('studio_layer_extraction.local_floodfill.box_max_size_ratio', 0.75);
        $boxAreaR = $wN * $hN;
        if ($boxAreaR < $minBoxR || $boxAreaR > $maxBoxR) {
            return null;
        }

        $cfgL = config('studio_layer_extraction.local_floodfill', []);
        $minAr = (float) ($cfgL['min_area_ratio'] ?? 0.01);
        $maxAr = (float) ($cfgL['max_area_ratio'] ?? 0.85);
        $fallback = (bool) config('studio_layer_extraction.local_floodfill.box_fallback_rectangle', true);

        $binary = $options['image_binary'] ?? null;
        if (! is_string($binary) || $binary === '') {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        }

        $state = $this->loadLocalSegmentationState($binary);
        $bgMask = $state['bgMask'];
        $segW = $state['segW'];
        $segH = $state['segH'];
        $scale = $state['scale'];
        $fullW = $state['fullW'];
        $fullH = $state['fullH'];
        $total = $segW * $segH;
        if ($total < 1) {
            return null;
        }

        $fx0 = (int) max(0, min($fullW - 1, (int) floor($xn * $fullW)));
        $fy0 = (int) max(0, min($fullH - 1, (int) floor($yn * $fullH)));
        $fx1 = (int) max($fx0, min($fullW - 1, (int) ceil($xn * $fullW + $wN * $fullW) - 1));
        $fy1 = (int) max($fy0, min($fullH - 1, (int) ceil($yn * $fullH + $hN * $fullH) - 1));

        $boxMeta = [
            'x' => $xn,
            'y' => $yn,
            'width' => $wN,
            'height' => $hN,
        ];
        $boxPixels = [
            'x' => $fx0,
            'y' => $fy0,
            'width' => $fx1 - $fx0 + 1,
            'height' => $fy1 - $fy0 + 1,
        ];

        $inBox = $this->buildSegInBoxTable($segW, $segH, $fullW, $fullH, $fx0, $fy0, $fx1, $fy1);
        $boxCellCount = 0;
        for ($i = 0; $i < $total; $i++) {
            if ($inBox[$i] === "\x01") {
                $boxCellCount++;
            }
        }
        if ($boxCellCount < 1) {
            return null;
        }

        $restricted = $this->applyBoxClipToBackgroundMask($bgMask, $inBox, $total);
        [$labelGrid, $by] = $this->labelForegroundComponents($restricted, $segW, $segH);

        $items = [];
        foreach (array_keys($by) as $cid) {
            $info = $by[$cid];
            $cnt = (int) $info['count'];
            $arFull = $cnt / $total;
            if ($arFull < $minAr || $arFull > $maxAr) {
                continue;
            }
            $arInBox = $cnt / max(1, $boxCellCount);
            if ($arInBox < 0.0008) {
                continue;
            }
            $items[] = [
                'id' => (int) $cid,
                'count' => $cnt,
                'bbox' => $info['bbox'],
            ];
        }
        usort($items, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $samBox = [
            'x' => $xn,
            'y' => $yn,
            'width' => $wN,
            'height' => $hN,
        ];
        if ($items !== []) {
            $row = $items[0];
            $compId = (int) $row['id'];
            $compMask = $this->buildBgMaskForComponent($restricted, $labelGrid, $segW, $segH, $compId);
            $compCount = (int) $row['count'];
            $areaR = $compCount / $total;
            $metadata = [
                'method' => 'local_box_floodfill',
                'prompt_type' => 'box',
                'boxes' => [$samBox],
                'box_normalized' => $boxMeta,
                'box_pixels' => $boxPixels,
                'positive_points' => [],
                'negative_points' => [],
                'source' => 'box',
                'note' => 'Box-constrained local mask',
                'provider' => 'floodfill',
                'area_ratio' => $areaR,
            ];

            return $this->buildCandidateFromBgMask(
                $candidateId,
                $label,
                $compMask,
                $segW,
                $segH,
                $scale,
                $fullW,
                $fullH,
                $areaR,
                0,
                $metadata
            );
        }

        if (! $fallback) {
            return null;
        }
        $rectMask = $this->buildSegSolidBoxMask($segW, $segH, $inBox, $total);
        $rectFg = 0;
        for ($i = 0; $i < $total; $i++) {
            if ($rectMask[$i] !== "\x01") {
                $rectFg++;
            }
        }
        $arRect = $rectFg / $total;
        if ($arRect < $minAr) {
            return null;
        }
        $rectMeta = [
            'method' => 'local_box_rect_cutout',
            'prompt_type' => 'box',
            'boxes' => [$samBox],
            'box_normalized' => $boxMeta,
            'box_pixels' => $boxPixels,
            'positive_points' => [],
            'negative_points' => [],
            'source' => 'box',
            'note' => 'Box-constrained local mask',
            'provider' => 'floodfill',
            'area_ratio' => $arRect,
        ];

        return $this->buildCandidateFromBgMask(
            $candidateId,
            $label,
            $rectMask,
            $segW,
            $segH,
            $scale,
            $fullW,
            $fullH,
            $arRect,
            0,
            $rectMeta
        );
    }

    private function buildSegInBoxTable(int $segW, int $segH, int $fullW, int $fullH, int $fx0, int $fy0, int $fx1, int $fy1): string
    {
        $t = $segW * $segH;
        $out = str_repeat("\0", $t);
        for ($i = 0; $i < $t; $i++) {
            $sx = $i % $segW;
            $sy = (int) floor($i / $segW);
            $px = (int) min($fullW - 1, max(0, (int) floor(($sx + 0.5) * $fullW / $segW)));
            $py = (int) min($fullH - 1, max(0, (int) floor(($sy + 0.5) * $fullH / $segH)));
            if ($px >= $fx0 && $px <= $fx1 && $py >= $fy0 && $py <= $fy1) {
                $out[$i] = "\x01";
            }
        }

        return $out;
    }

    private function applyBoxClipToBackgroundMask(string $bgMask, string $inBox, int $total): string
    {
        $out = str_repeat("\0", $total);
        for ($i = 0; $i < $total; $i++) {
            if ($inBox[$i] !== "\x01") {
                $out[$i] = "\x01";
            } else {
                $out[$i] = $bgMask[$i];
            }
        }

        return $out;
    }

    /**
     * @return non-empty-string
     */
    private function buildSegSolidBoxMask(int $segW, int $segH, string $inBox, int $total): string
    {
        $out = str_repeat("\x01", $total);
        for ($i = 0; $i < $total; $i++) {
            if ($inBox[$i] === "\x01") {
                $out[$i] = "\x00";
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   fullW: int,
     *   fullH: int,
     *   segW: int,
     *   segH: int,
     *   scale: float,
     *   bgMask: string,
     *   model: string,
     *   minAr: float,
     *   maxAr: float,
     *   mergeIou: float,
     * }
     */
    private function loadLocalSegmentationState(string $binary): array
    {
        $cfgF = config('studio_layer_extraction.floodfill', []);
        $cfgL = config('studio_layer_extraction.local_floodfill', []);
        $maxEdge = max(64, (int) ($cfgF['max_segmentation_edge'] ?? 1024));
        $tolerance = max(1, (int) ($cfgF['color_tolerance'] ?? 45));
        $maxAnalysisPx = max(200_000, (int) ($cfgL['max_analysis_pixels'] ?? 3_000_000));
        $minAr = (float) ($cfgL['min_area_ratio'] ?? 0.01);
        $maxAr = (float) ($cfgL['max_area_ratio'] ?? 0.85);
        $mergeIou = (float) ($cfgL['merge_iou_threshold'] ?? 0.65);

        $dims = @getimagesizefromstring($binary);
        if (! is_array($dims) || ($dims[0] ?? 0) < 2 || ($dims[1] ?? 0) < 2) {
            throw new InvalidArgumentException('Unsupported or unreadable image for extraction.');
        }

        $fullW = (int) $dims[0];
        $fullH = (int) $dims[1];
        if ($fullW * $fullH > $maxAnalysisPx) {
            throw new InvalidArgumentException(
                'This image is too large for local extraction. Try a smaller resolution, or add a paid segmentation provider later when available.'
            );
        }

        $src = $this->decodeToTrueColorGd($binary);
        $scale = min(1.0, $maxEdge / max($fullW, $fullH));
        $segW = max(2, (int) round($fullW * $scale));
        $segH = max(2, (int) round($fullH * $scale));

        $seg = imagescale($src, $segW, $segH, self::IMAGE_SCALE_NEAREST);
        if ($seg === false) {
            $seg = imagescale($src, $segW, $segH, self::IMAGE_SCALE_BILINEAR_FIXED);
        }
        if ($seg === false) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $seg = imagecreatetruecolor($segW, $segH);
            if ($seg === false) {
                imagedestroy($src);
                throw new RuntimeException('Failed to scale image for segmentation.');
            }
            imagealphablending($src, true);
            imagesavealpha($src, true);
            imagecopyresampled($seg, $src, 0, 0, 0, 0, $segW, $segH, $sw, $sh);
        }
        imagedestroy($src);

        $bgRgb = $this->averageCornerRgb($seg, $segW, $segH);
        $bgMask = $this->buildBackgroundMask($seg, $segW, $segH, $bgRgb, $tolerance);
        imagedestroy($seg);

        $model = (string) ($cfgF['model'] ?? 'gd_floodfill_v1');

        return [
            'fullW' => $fullW,
            'fullH' => $fullH,
            'segW' => $segW,
            'segH' => $segH,
            'scale' => $scale,
            'bgMask' => $bgMask,
            'model' => $model,
            'minAr' => $minAr,
            'maxAr' => $maxAr,
            'mergeIou' => $mergeIou,
        ];
    }

    /**
     * @return list<int>
     */
    private function connectedForegroundFromSeed(string $bgMask, int $w, int $h, int $seed): array
    {
        if ($bgMask[$seed] === "\x01") {
            return [];
        }
        $q = new SplQueue;
        $q->enqueue($seed);
        $vis = str_repeat("\0", $w * $h);
        $vis[$seed] = "\x01";
        $out = [];
        while (! $q->isEmpty()) {
            $i = (int) $q->dequeue();
            $out[] = $i;
            $x = $i % $w;
            $y = (int) floor($i / $w);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $ni = $ny * $w + $nx;
                if ($vis[$ni] === "\x01" || $bgMask[$ni] === "\x01") {
                    continue;
                }
                $vis[$ni] = "\x01";
                $q->enqueue($ni);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $indices
     */
    private function buildForegroundMaskForIndices(array $indices, int $w, int $h, int $len): string
    {
        $set = array_fill(0, $len, false);
        foreach ($indices as $i) {
            if ($i >= 0 && $i < $len) {
                $set[$i] = true;
            }
        }
        $out = str_repeat("\x01", $len);
        for ($i = 0; $i < $len; $i++) {
            if ($set[$i]) {
                $out[$i] = "\x00";
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $indices
     * @return ?array{x:int,y:int,width:int,height:int}
     */
    private function bboxFromFlatIndices(array $indices, int $w, int $h): ?array
    {
        if ($indices === []) {
            return null;
        }
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        foreach ($indices as $i) {
            if ($i < 0 || $i >= $w * $h) {
                continue;
            }
            $x = $i % $w;
            $y = (int) floor($i / $w);
            if ($x < $minX) {
                $minX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }
        if ($maxX < $minX || $maxY < $minY) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ];
    }

    /**
     * @param  array{x:int,y:int,width:int,height:int}  $bboxSeg
     * @return array{x:int,y:int,width:int,height:int}
     */
    private function mapSegBboxToFull(array $bboxSeg, float $scale, int $fullW, int $fullH): array
    {
        $bboxFull = [
            'x' => (int) floor($bboxSeg['x'] / $scale),
            'y' => (int) floor($bboxSeg['y'] / $scale),
            'width' => (int) ceil($bboxSeg['width'] / $scale),
            'height' => (int) ceil($bboxSeg['height'] / $scale),
        ];
        $bboxFull['width'] = min($fullW - $bboxFull['x'], max(1, $bboxFull['width']));
        $bboxFull['height'] = min($fullH - $bboxFull['y'], max(1, $bboxFull['height']));
        $bboxFull['x'] = max(0, min($bboxFull['x'], $fullW - 1));
        $bboxFull['y'] = max(0, min($bboxFull['y'], $fullH - 1));

        return $bboxFull;
    }

    /**
     * @param  list<LayerExtractionCandidateDto>  $candidates
     */
    private function resultFromCandidates(Asset $asset, string $model, array $candidates): LayerExtractionResult
    {
        return new LayerExtractionResult(
            provider: 'floodfill',
            model: $model,
            sourceAssetId: (string) $asset->id,
            candidates: $candidates,
        );
    }

    private function buildCandidateFromUnionMask(
        string $id,
        string $label,
        string $bgMask,
        int $segW,
        int $segH,
        float $scale,
        int $fullW,
        int $fullH,
    ): LayerExtractionCandidateDto {
        $t = $segW * $segH;
        $fg = 0;
        for ($i = 0; $i < $t; $i++) {
            if ($bgMask[$i] !== "\x01") {
                $fg++;
            }
        }
        $areaR = $t > 0 ? $fg / $t : 0.0;

        return $this->buildCandidateFromBgMask(
            $id,
            $label,
            $bgMask,
            $segW,
            $segH,
            $scale,
            $fullW,
            $fullH,
            $areaR,
            0
        );
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, array{count:int, bbox: array{x:int,y:int,width:int,height:int}}>}
     */
    private function labelForegroundComponents(string $bgMask, int $w, int $h): array
    {
        $len = $w * $h;
        /** @var list<int> $lab */
        $lab = array_fill(0, $len, 0);
        $next = 1;
        $by = [];

        for ($i = 0; $i < $len; $i++) {
            if ($bgMask[$i] === "\x01" || $lab[$i] !== 0) {
                continue;
            }
            $id = $next++;
            $q = new SplQueue;
            $q->enqueue($i);
            $lab[$i] = $id;
            $cnt = 0;
            $minX = $w;
            $minY = $h;
            $maxX = -1;
            $maxY = -1;
            while (! $q->isEmpty()) {
                $p = (int) $q->dequeue();
                $cnt++;
                $x = $p % $w;
                $y = (int) floor($p / $w);
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as $d) {
                    $nx = $x + $d[0];
                    $ny = $y + $d[1];
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $ni = $ny * $w + $nx;
                    if ($bgMask[$ni] === "\x01" || $lab[$ni] !== 0) {
                        continue;
                    }
                    $lab[$ni] = $id;
                    $q->enqueue($ni);
                }
            }
            $by[$id] = [
                'count' => $cnt,
                'bbox' => [
                    'x' => $minX,
                    'y' => $minY,
                    'width' => $maxX - $minX + 1,
                    'height' => $maxY - $minY + 1,
                ],
            ];
        }

        return [$lab, $by];
    }

    /**
     * @param  list<int>  $labelGrid
     */
    private function buildBgMaskForComponent(string $bgMask, array $labelGrid, int $w, int $h, int $compId): string
    {
        $len = $w * $h;
        $out = str_repeat("\0", $len);
        for ($i = 0; $i < $len; $i++) {
            if ($bgMask[$i] === "\x01" || (int) $labelGrid[$i] !== $compId) {
                $out[$i] = "\x01";
            } else {
                $out[$i] = "\x00";
            }
        }

        return $out;
    }

    /**
     * @param  list<array{id:int, count: int, bbox: array{x:int,y:int,width:int,height:int}}>  $items
     * @return list<array{id: int, count: int, bbox: array{x:int,y:int,width:int,height:int}}>
     */
    private function dedupeCandidatesByBboxIou(array $items, float $iouT): array
    {
        $out = [];
        foreach ($items as $cand) {
            $overlap = false;
            foreach ($out as $o) {
                if ($this->bboxIou($cand['bbox'], $o['bbox']) > $iouT) {
                    $overlap = true;
                    break;
                }
            }
            if (! $overlap) {
                $out[] = $cand;
            }
        }

        return $out;
    }

    /**
     * @param  array{x:int,y:int,width:int,height:int}  $a
     * @param  array{x:int,y:int,width:int,height:int}  $b
     */
    private function bboxIou(array $a, array $b): float
    {
        $x1 = max(0, (int) $a['x']);
        $y1 = max(0, (int) $a['y']);
        $x2 = $x1 + max(0, (int) $a['width']);
        $y2 = $y1 + max(0, (int) $a['height']);
        $X1 = max(0, (int) $b['x']);
        $Y1 = max(0, (int) $b['y']);
        $X2 = $X1 + max(0, (int) $b['width']);
        $Y2 = $Y1 + max(0, (int) $b['height']);
        $ix = min($x2, $X2) - max($x1, $X1);
        $iy = min($y2, $Y2) - max($y1, $Y1);
        if ($ix <= 0 || $iy <= 0) {
            return 0.0;
        }
        $inter = $ix * $iy;
        $areaA = max(0, (int) $a['width'] * (int) $a['height']);
        $areaB = max(0, (int) $b['width'] * (int) $b['height']);
        $u = $areaA + $areaB - $inter;
        if ($u <= 0) {
            return 0.0;
        }

        return $inter / $u;
    }

    private function buildCandidateFromBgMask(
        string $id,
        string $label,
        string $componentBgMask,
        int $segW,
        int $segH,
        float $scale,
        int $fullW,
        int $fullH,
        ?float $areaRatio,
        int $internalCompId,
        ?array $metadataOverride = null
    ): LayerExtractionCandidateDto {
        $bboxSeg = $this->foregroundBoundingBox($componentBgMask, $segW, $segH);
        if ($bboxSeg === null) {
            $bboxSeg = ['x' => 0, 'y' => 0, 'width' => $segW, 'height' => $segH];
        }
        $bboxFull = [
            'x' => (int) floor($bboxSeg['x'] / $scale),
            'y' => (int) floor($bboxSeg['y'] / $scale),
            'width' => (int) ceil($bboxSeg['width'] / $scale),
            'height' => (int) ceil($bboxSeg['height'] / $scale),
        ];
        $bboxFull['width'] = min($fullW - $bboxFull['x'], max(1, $bboxFull['width']));
        $bboxFull['height'] = min($fullH - $bboxFull['y'], max(1, $bboxFull['height']));
        $bboxFull['x'] = max(0, min($bboxFull['x'], $fullW - 1));
        $bboxFull['y'] = max(0, min($bboxFull['y'], $fullH - 1));

        $fullMask = $this->upscaleMask($componentBgMask, $segW, $segH, $fullW, $fullH);
        $maskPng = $this->maskToRgbaPng($fullMask, $fullW, $fullH);

        $metadata = $metadataOverride ?? [
            'method' => 'local_floodfill',
            'region_id' => (string) $internalCompId,
            'area_ratio' => $areaRatio,
            'note' => 'Local mask detection',
        ];

        return new LayerExtractionCandidateDto(
            id: $id,
            label: $label,
            confidence: null,
            bbox: $bboxFull,
            maskPath: null,
            maskBase64: base64_encode($maskPng),
            previewPath: null,
            selected: true,
            notes: self::NOTES_COPY,
            metadata: $metadata,
        );
    }

    public function supportsMultipleMasks(): bool
    {
        return (bool) config('studio_layer_extraction.local_floodfill.enable_multi_candidate', true);
    }

    public function supportsBackgroundFill(): bool
    {
        return false;
    }

    public function supportsLabels(): bool
    {
        return true;
    }

    public function supportsConfidence(): bool
    {
        return false;
    }

    /**
     * GD often fails on CMYK JPEG, some WebP, etc. When Imagick is available, normalize to sRGB PNG for {@see imagecreatefromstring()}.
     */
    private function decodeToTrueColorGd(string $binary): \GdImage
    {
        $src = @imagecreatefromstring($binary);
        if ($src !== false) {
            if (! imageistruecolor($src)) {
                imagepalettetotruecolor($src);
            }

            return $src;
        }

        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($binary);
                $im->stripImage();
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->setImageFormat('png');
                $png = $im->getImageBlob();
                $im->clear();
                $im->destroy();
                if (is_string($png) && $png !== '') {
                    $gd = @imagecreatefromstring($png);
                    if ($gd !== false) {
                        if (! imageistruecolor($gd)) {
                            imagepalettetotruecolor($gd);
                        }

                        return $gd;
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        throw new InvalidArgumentException(
            'Could not read this image with GD. Try a standard RGB PNG or JPEG, or re-upload the file.'
        );
    }

    /**
     * @return array{r:int,g:int,b:int}
     */
    private function averageCornerRgb(\GdImage $im, int $w, int $h): array
    {
        $samples = [
            $this->rgbAt($im, 0, 0),
            $this->rgbAt($im, $w - 1, 0),
            $this->rgbAt($im, 0, $h - 1),
            $this->rgbAt($im, $w - 1, $h - 1),
        ];
        $r = (int) round(array_sum(array_column($samples, 'r')) / 4);
        $g = (int) round(array_sum(array_column($samples, 'g')) / 4);
        $b = (int) round(array_sum(array_column($samples, 'b')) / 4);

        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    /**
     * @return array{r:int,g:int,b:int}
     */
    private function rgbAt(\GdImage $im, int $x, int $y): array
    {
        $c = imagecolorat($im, $x, $y);

        /** @see https://www.php.net/manual/en/function.imagecolorat.php (truecolor: R=bits 16-23) */
        return [
            'r' => ($c >> 16) & 0xFF,
            'g' => ($c >> 8) & 0xFF,
            'b' => $c & 0xFF,
        ];
    }

    /**
     * @param  array{r:int,g:int,b:int}  $bgRgb
     * @return string byte string length w*h, chr(1)=background
     */
    private function buildBackgroundMask(\GdImage $im, int $w, int $h, array $bgRgb, int $tolerance): string
    {
        $len = $w * $h;
        $bg = str_repeat("\0", $len);
        $visited = str_repeat("\0", $len);

        $queue = new SplQueue;
        $enqueue = function (int $x, int $y) use ($w, $h, $im, $bgRgb, $tolerance, &$bg, &$visited, $queue): void {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                return;
            }
            $i = $y * $w + $x;
            if ($visited[$i] === "\x01") {
                return;
            }
            $rgb = $this->rgbAt($im, $x, $y);
            if ($this->colorDistance($rgb, $bgRgb) > $tolerance) {
                return;
            }
            $visited[$i] = "\x01";
            $bg[$i] = "\x01";
            $queue->enqueue([$x, $y]);
        };

        for ($x = 0; $x < $w; $x++) {
            $enqueue($x, 0);
            $enqueue($x, $h - 1);
        }
        for ($y = 0; $y < $h; $y++) {
            $enqueue(0, $y);
            $enqueue($w - 1, $y);
        }

        while (! $queue->isEmpty()) {
            [$x, $y] = $queue->dequeue();
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $ni = $ny * $w + $nx;
                if ($visited[$ni] === "\x01") {
                    continue;
                }
                $rgb = $this->rgbAt($im, $nx, $ny);
                if ($this->colorDistance($rgb, $bgRgb) > $tolerance) {
                    continue;
                }
                $visited[$ni] = "\x01";
                $bg[$ni] = "\x01";
                $queue->enqueue([$nx, $ny]);
            }
        }

        return $bg;
    }

    /**
     * @param  array{r:int,g:int,b:int}  $a
     * @param  array{r:int,g:int,b:int}  $b
     */
    private function colorDistance(array $a, array $b): float
    {
        $dr = $a['r'] - $b['r'];
        $dg = $a['g'] - $b['g'];
        $db = $a['b'] - $b['b'];

        return sqrt($dr * $dr + $dg * $dg + $db * $db);
    }

    /**
     * @return ?array{x:int,y:int,width:int,height:int}
     */
    private function foregroundBoundingBox(string $bgMask, int $w, int $h): ?array
    {
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($bgMask[$row + $x] === "\x01") {
                    continue;
                }
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }
        if ($maxX < $minX || $maxY < $minY) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ];
    }

    /**
     * @return string chr(1)=background (same encoding as buildBackgroundMask)
     */
    private function upscaleMask(string $smallBg, int $sw, int $sh, int $fw, int $fh): string
    {
        $out = str_repeat("\0", $fw * $fh);
        for ($fy = 0; $fy < $fh; $fy++) {
            $sy = (int) (($fy * $sh) / $fh);
            $sy = min($sh - 1, max(0, $sy));
            for ($fx = 0; $fx < $fw; $fx++) {
                $sx = (int) (($fx * $sw) / $fw);
                $sx = min($sw - 1, max(0, $sx));
                if ($smallBg[$sy * $sw + $sx] === "\x01") {
                    $out[$fy * $fw + $fx] = "\x01";
                }
            }
        }

        return $out;
    }

    /**
     * RGBA PNG: foreground white @ alpha 0, background fully transparent.
     *
     * @return non-empty-string
     */
    private function maskToRgbaPng(string $bgMask, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new RuntimeException('maskToRgbaPng failed.');
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $transparent);
        $fg = imagecolorallocatealpha($im, 255, 255, 255, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($bgMask[$y * $w + $x] === "\x01") {
                    continue;
                }
                imagesetpixel($im, $x, $y, $fg);
            }
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        if ($png === '') {
            throw new RuntimeException('Failed to encode mask PNG.');
        }

        return $png;
    }
}
