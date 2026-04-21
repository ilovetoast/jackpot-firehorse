<?php

namespace App\Services\Studio;

use App\Support\StudioEditorDocumentProductLayerFinder;

/**
 * Deterministic, role-aware layout reflow when changing Studio canvas size (format pack).
 * Avoids naive uniform document scaling; re-anchors known {@code studioSyncRole} layers into safe zones.
 */
final class StudioCompositionFormatReflowService
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function reflowToCanvasSize(array $document, int $targetWidth, int $targetHeight): array
    {
        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, $targetHeight);

        $sourceWidth = max(1, (int) ($document['width'] ?? 1080));
        $sourceHeight = max(1, (int) ($document['height'] ?? 1080));

        if ($sourceWidth === $targetWidth && $sourceHeight === $targetHeight) {
            return $document;
        }

        $layers = $document['layers'] ?? null;
        if (! is_array($layers) || $layers === []) {
            $document['width'] = $targetWidth;
            $document['height'] = $targetHeight;

            return $document;
        }

        $product = StudioEditorDocumentProductLayerFinder::find($document);
        $productLayerId = $product['layer_id'] ?? null;

        $ctaGroupId = $this->resolveCtaGroupId($layers);
        $ctaMemberIds = $ctaGroupId !== null ? $this->collectGroupLayerIds($layers, $ctaGroupId) : [];

        $unionCta = $ctaMemberIds !== [] ? $this->unionTransformForLayerIds($layers, $ctaMemberIds) : null;

        $fontScale = $this->clampFontScale($sourceWidth, $sourceHeight, $targetWidth, $targetHeight);

        $W = $targetWidth;
        $H = $targetHeight;
        $m = 0.04;

        $zones = [
            'headline' => $this->rectPx($W, $H, $m, 0.05, 1 - 2 * $m, 0.10),
            'subheadline' => $this->rectPx($W, $H, $m, 0.16, 1 - 2 * $m, 0.08),
            'cta' => $this->rectPx($W, $H, 0.18, 0.70, 0.64, 0.12),
            'disclaimer' => $this->rectPx($W, $H, $m, 0.90, 1 - 2 * $m, 0.07),
            'logo' => $this->rectPx($W, $H, $m, $m, 0.14 * min($W, $H) / $W, 0.14 * min($W, $H) / $H),
            'badge' => $this->rectPx($W, $H, 1 - $m - 0.16, $m, 0.16, 0.10),
            'product' => $this->rectPx($W, $H, 0.10, 0.22, 0.80, 0.50),
        ];

        foreach ($layers as $i => $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $id = (string) ($layer['id'] ?? '');
            $type = (string) ($layer['type'] ?? '');

            if ($type === 'generative_image') {
                $layers[$i] = $this->setLayerTransform($layer, 0, 0, $W, $H);
                $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);

                continue;
            }

            if ($this->isLikelyBackgroundFill($layer, $sourceWidth, $sourceHeight)) {
                $layers[$i] = $this->setLayerTransform($layer, 0, 0, $W, $H);
                $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);

                continue;
            }

            if ($productLayerId !== null && $id === $productLayerId) {
                $r = $zones['product'];
                $layers[$i] = $this->setLayerTransform($layer, $r['x'], $r['y'], $r['w'], $r['h']);
                if ($type === 'image' && ! isset($layer['fit'])) {
                    $layer['fit'] = 'cover';
                    $layers[$i]['fit'] = 'cover';
                }
                $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);

                continue;
            }

            if ($ctaGroupId !== null && $unionCta !== null && in_array($id, $ctaMemberIds, true)) {
                $r = $zones['cta'];
                $scaled = $this->mapUnionIntoTargetRect($layer, $unionCta, $r);
                $layers[$i] = $scaled;
                $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);

                continue;
            }

            $role = $this->readSyncRole($layer);
            if ($role !== null && isset($zones[$role])) {
                $r = $zones[$role];
                $layers[$i] = $this->setLayerTransform($layer, $r['x'], $r['y'], $r['w'], $r['h']);
                $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);

                continue;
            }

            $layers[$i] = $this->relayoutProportionally($layer, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight);
            $layers[$i] = $this->scaleTextFont($layers[$i], $fontScale);
        }

        $document['width'] = $targetWidth;
        $document['height'] = $targetHeight;
        $document['layers'] = array_values($layers);

        return $document;
    }

    /**
     * @param  array<int, mixed>  $layers
     */
    private function resolveCtaGroupId(array $layers): ?string
    {
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $sync = strtolower(trim((string) ($layer['studioSyncRole'] ?? '')));
            if ($sync === 'cta') {
                $gid = $layer['groupId'] ?? null;

                return is_string($gid) && $gid !== '' ? $gid : null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @return list<string>
     */
    private function collectGroupLayerIds(array $layers, string $groupId): array
    {
        $ids = [];
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['groupId'] ?? null) === $groupId) {
                $id = (string) ($layer['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @param  list<string>  $ids
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function unionTransformForLayerIds(array $layers, array $ids): array
    {
        $allow = array_flip($ids);
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $id = (string) ($layer['id'] ?? '');
            if (! isset($allow[$id])) {
                continue;
            }
            $t = $this->readTransform($layer);
            $minX = min($minX, $t['x']);
            $minY = min($minY, $t['y']);
            $maxX = max($maxX, $t['x'] + $t['width']);
            $maxY = max($maxY, $t['y'] + $t['height']);
        }
        if (! is_finite($minX)) {
            return ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1];
        }

        return ['x' => $minX, 'y' => $minY, 'w' => max(1, $maxX - $minX), 'h' => max(1, $maxY - $minY)];
    }

    /**
     * @param  array<string, mixed>  $layer
     * @param  array{x: float, y: float, w: float, h: float}  $union
     * @param  array{x: int, y: int, w: int, h: int}  $target
     * @return array<string, mixed>
     */
    private function mapUnionIntoTargetRect(array $layer, array $union, array $target): array
    {
        $t = $this->readTransform($layer);
        $relX = $t['x'] - $union['x'];
        $relY = $t['y'] - $union['y'];
        $scale = min($target['w'] / $union['w'], $target['h'] / $union['h']) * 0.92;
        $scale = max(0.05, $scale);
        $blockW = $union['w'] * $scale;
        $blockH = $union['h'] * $scale;
        $ox = $target['x'] + ($target['w'] - $blockW) / 2;
        $oy = $target['y'] + ($target['h'] - $blockH) / 2;
        $nx = (int) round($ox + $relX * $scale);
        $ny = (int) round($oy + $relY * $scale);
        $nw = (int) max(1, round($t['width'] * $scale));
        $nh = (int) max(1, round($t['height'] * $scale));

        return $this->setLayerTransform($layer, $nx, $ny, $nw, $nh);
    }

    /**
     * @return array{x: int, y: int, w: int, h: int}
     */
    private function rectPx(int $W, int $H, float $nx, float $ny, float $nwFrac, float $nhFrac): array
    {
        return [
            'x' => (int) round($nx * $W),
            'y' => (int) round($ny * $H),
            'w' => (int) max(1, round($nwFrac * $W)),
            'h' => (int) max(1, round($nhFrac * $H)),
        ];
    }

    private function clampFontScale(int $sw, int $sh, int $tw, int $th): float
    {
        $r = min($tw / max(1, $sw), $th / max(1, $sh));

        return max(0.55, min(1.35, $r));
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function readTransform(array $layer): array
    {
        $tr = $layer['transform'] ?? null;
        if (! is_array($tr)) {
            return ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];
        }

        return [
            'x' => (float) ($tr['x'] ?? 0),
            'y' => (float) ($tr['y'] ?? 0),
            'width' => (float) ($tr['width'] ?? 1),
            'height' => (float) ($tr['height'] ?? 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private function setLayerTransform(array $layer, int $x, int $y, int $w, int $h): array
    {
        $tr = $layer['transform'] ?? [];
        if (! is_array($tr)) {
            $tr = [];
        }
        $tr['x'] = $x;
        $tr['y'] = $y;
        $tr['width'] = max(1, $w);
        $tr['height'] = max(1, $h);
        $layer['transform'] = $tr;

        return $layer;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function isLikelyBackgroundFill(array $layer, int $docW, int $docH): bool
    {
        if (($layer['type'] ?? '') !== 'fill') {
            return false;
        }
        $t = $this->readTransform($layer);
        $covW = $t['width'] / max(1, $docW);
        $covH = $t['height'] / max(1, $docH);

        return $covW >= 0.88 && $covH >= 0.88;
    }

    private function readSyncRole(array $layer): ?string
    {
        $r = strtolower(trim((string) ($layer['studioSyncRole'] ?? '')));
        if ($r === '') {
            return null;
        }
        if (in_array($r, ['headline', 'subheadline', 'cta', 'logo', 'badge', 'disclaimer'], true)) {
            return $r;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private function relayoutProportionally(
        array $layer,
        int $sw,
        int $sh,
        int $tw,
        int $th,
    ): array {
        $t = $this->readTransform($layer);
        $nx = (int) round($t['x'] / max(1, $sw) * $tw);
        $ny = (int) round($t['y'] / max(1, $sh) * $th);
        $nw = (int) max(1, round($t['width'] / max(1, $sw) * $tw));
        $nh = (int) max(1, round($t['height'] / max(1, $sh) * $th));

        return $this->setLayerTransform($layer, $nx, $ny, $nw, $nh);
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    private function scaleTextFont(array $layer, float $scale): array
    {
        if (($layer['type'] ?? '') !== 'text') {
            return $layer;
        }
        $style = $layer['style'] ?? null;
        if (! is_array($style) || ! isset($style['fontSize']) || ! is_numeric($style['fontSize'])) {
            return $layer;
        }
        $fs = (float) $style['fontSize'];
        $style['fontSize'] = (int) max(6, round($fs * $scale));
        $layer['style'] = $style;

        return $layer;
    }
}
