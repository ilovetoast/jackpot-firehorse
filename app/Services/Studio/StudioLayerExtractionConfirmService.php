<?php

namespace App\Services\Studio;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioLayerExtractionSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class StudioLayerExtractionConfirmService
{
    public function __construct(
        protected StudioLayerExtractionAssetFactory $assetFactory,
        protected StudioLayerExtractionProviderInterface $extractionProvider,
        protected StudioLayerExtractionInpaintBackgroundInterface $inpaint,
        protected AiUsageService $aiUsageService,
    ) {}

    /**
     * @param  list<string>  $candidateIds
     * @param  array<string|int, string|null>  $layerNames  Indexed by order or candidate id
     * @return array{document: array<string, mixed>, new_layer_ids: list<string>}
     */
    public function confirm(
        Composition $composition,
        StudioLayerExtractionSession $session,
        string $layerId,
        array $candidateIds,
        bool $keepOriginalVisible,
        bool $createFilledBackground,
        bool $hideOriginalAfterExtraction,
        array $layerNames,
        Tenant $tenant,
        Brand $brand,
        User $user,
    ): array {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready.');
        }
        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            throw new InvalidArgumentException('Extraction session has expired.');
        }
        if ($session->source_layer_id !== $layerId) {
            throw new InvalidArgumentException('Layer does not match extraction session.');
        }

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layers = $doc['layers'] ?? [];
        $sourceLayer = $this->findLayer($layers, $layerId);
        if ($sourceLayer === null) {
            throw new InvalidArgumentException('Layer not found in document.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            throw new RuntimeException('Invalid session payload.');
        }

        $byId = [];
        foreach ($stored as $row) {
            if (isset($row['id'])) {
                $byId[(string) $row['id']] = $row;
            }
        }

        $sourceAsset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->firstOrFail();

        try {
            $origBinary = EditorAssetOriginalBytesLoader::loadFromStorage($sourceAsset);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Could not load source image for cutout.');
        }

        $natural = @getimagesizefromstring($origBinary);
        $nw = isset($natural[0]) ? (int) $natural[0] : 0;
        $nh = isset($natural[1]) ? (int) $natural[1] : 0;
        if ($nw < 2 || $nh < 2) {
            throw new InvalidArgumentException('Invalid source dimensions.');
        }

        $disk = Storage::disk('studio_layer_extraction');

        $validRows = [];
        foreach ($candidateIds as $i => $cid) {
            $cid = trim((string) $cid);
            if ($cid === '' || ! isset($byId[$cid])) {
                continue;
            }
            $row = $byId[$cid];
            $maskRel = (string) ($row['mask_relative'] ?? '');
            if ($maskRel === '' || ! $disk->exists($maskRel)) {
                continue;
            }
            $bbox = $row['bbox'] ?? null;
            if (! is_array($bbox)) {
                continue;
            }
            $validRows[] = ['i' => (int) $i, 'id' => $cid, 'row' => $row];
        }

        if ($validRows === []) {
            throw new InvalidArgumentException('No valid candidates selected.');
        }

        $canFill = (bool) config('studio_layer_extraction.inpaint_enabled', false)
            && $this->inpaint->supportsBackgroundFill();
        if ($createFilledBackground && ! $canFill) {
            throw new InvalidArgumentException(
                'Background fill requires an inpainting provider. Enable STUDIO_LAYER_INPAINT_ENABLED and choose an inpaint provider, or create cutout layers only.'
            );
        }

        $maxZ = 0;
        foreach ($layers as $l) {
            $maxZ = max($maxZ, (int) ($l['z'] ?? 0));
        }
        $zFill = $maxZ + 1;

        $fillAssetId = null;
        $filledLayerDef = null;
        $combinedRel = null;
        if ($createFilledBackground) {
            if ((bool) config('studio_layer_extraction.background_fill_credits_enabled', true)) {
                $this->aiUsageService->checkUsage($tenant, 'studio_layer_background_fill', 1);
            }
            $maskBinaries = [];
            foreach ($validRows as $pack) {
                $rel = (string) ($pack['row']['mask_relative'] ?? '');
                $maskBinaries[] = $disk->get($rel);
            }
            $combinedMaskPng = $this->mergeUnionForegroundMasksPng($nw, $nh, $maskBinaries);
            $combinedRel = $session->id.'/combined_mask.png';
            $disk->put($combinedRel, $combinedMaskPng);
            try {
                $fillBinary = $this->inpaint->buildFilledBackground($sourceAsset, $origBinary, $combinedMaskPng, $session);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    'Background fill could not be completed. Try again or extract without a filled background layer. '.$e->getMessage()
                );
            }
            if (! is_string($fillBinary) || $fillBinary === '') {
                throw new InvalidArgumentException('Background fill returned an empty result.');
            }
            $fillDims = @getimagesizefromstring($fillBinary);
            $fw = isset($fillDims[0]) ? (int) $fillDims[0] : $nw;
            $fh = isset($fillDims[1]) ? (int) $fillDims[1] : $nh;
            $mime = isset($fillDims['mime']) ? (string) $fillDims['mime'] : 'image/png';
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                $ext = 'jpg';
            } elseif ($mime === 'image/webp') {
                $ext = 'webp';
            }

            $idList = array_map(static fn (array $p) => (string) $p['id'], $validRows);
            $bgMetaWrap = [
                'studio_layer_extraction_background_fill' => [
                    'created_by' => 'ai_layer_extraction_background_fill',
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand->id,
                    'user_id' => $user->id,
                    'extraction_session_id' => $session->id,
                    'source_layer_id' => $layerId,
                    'source_asset_id' => (string) $session->source_asset_id,
                    'mask_candidate_ids' => $idList,
                    'feature' => 'studio_layer_background_fill',
                    'provider' => (string) config('studio_layer_extraction.inpaint_provider', 'clipdrop'),
                    'model' => $session->model,
                    'segmentation_provider' => $session->provider,
                    'inpaint_provider' => (string) config('studio_layer_extraction.inpaint_provider', 'none'),
                ],
            ];

            $fillCreated = $this->assetFactory->createFilledBackgroundImage(
                $tenant,
                $brand,
                $user,
                $fillBinary,
                $fw,
                $fh,
                $mime,
                $ext,
                $bgMetaWrap
            );
            $fillAssetId = $fillCreated['asset_id'];

            $this->aiUsageService->tryBillStudioLayerBackgroundFill(
                $tenant,
                $session,
                (string) config('studio_layer_extraction.inpaint_provider', 'clipdrop'),
            );
            $session->refresh();

            $stx = (float) ($sourceLayer['transform']['x'] ?? 0);
            $sty = (float) ($sourceLayer['transform']['y'] ?? 0);
            $stw = (float) ($sourceLayer['transform']['width'] ?? 1);
            $sth = (float) ($sourceLayer['transform']['height'] ?? 1);

            $newFillId = (string) Str::uuid();
            $filledLayerDef = [
                'id' => $newFillId,
                'type' => 'image',
                'name' => 'Filled background',
                'visible' => true,
                'locked' => false,
                'z' => $zFill,
                'transform' => [
                    'x' => round($stx, 3),
                    'y' => round($sty, 3),
                    'width' => round($stw, 3),
                    'height' => round($sth, 3),
                    'rotation' => $sourceLayer['transform']['rotation'] ?? 0,
                ],
                'assetId' => $fillAssetId,
                'src' => $fillCreated['url'],
                'naturalWidth' => $fw,
                'naturalHeight' => $fh,
                'fit' => 'fill',
                'studioLayerExtractionBackgroundFill' => $bgMetaWrap['studio_layer_extraction_background_fill'],
            ];
            $this->logStudioLayerExtractionLayerCreated(
                $composition->id,
                $layerId,
                $newFillId,
                null,
                (string) $fillAssetId,
            );
        }

        $newDocLayers = [];
        if ($filledLayerDef !== null) {
            $newDocLayers[] = $filledLayerDef;
        }

        $tx = (float) ($sourceLayer['transform']['x'] ?? 0);
        $ty = (float) ($sourceLayer['transform']['y'] ?? 0);
        $tw = (float) ($sourceLayer['transform']['width'] ?? 1);
        $th = (float) ($sourceLayer['transform']['height'] ?? 1);

        $cutIndex = 0;
        foreach ($validRows as $pack) {
            $cid = $pack['id'];
            $i = $pack['i'];
            $row = $pack['row'];
            $maskRel = (string) ($row['mask_relative'] ?? '');
            $maskPng = $disk->get($maskRel);
            $bbox = $row['bbox'] ?? null;
            if (! is_array($bbox)) {
                continue;
            }
            $bx = max(0, (int) ($bbox['x'] ?? 0));
            $by = max(0, (int) ($bbox['y'] ?? 0));
            $bw = max(1, (int) ($bbox['width'] ?? 1));
            $bh = max(1, (int) ($bbox['height'] ?? 1));

            $cutoutPng = $this->buildCutoutPng($origBinary, $maskPng, $bx, $by, $bw, $bh);
            $dims = @getimagesizefromstring($cutoutPng);
            $cw = isset($dims[0]) ? (int) $dims[0] : $bw;
            $ch = isset($dims[1]) ? (int) $dims[1] : $bh;

            $defaultName = 'Extracted '.($row['label'] ?? 'layer');
            $name = $this->resolveLayerName($layerNames, $cid, $i, $defaultName);

            $prevEx = $sourceLayer['studioLayerExtraction'] ?? null;
            $isRefinement = is_array($prevEx);
            $rootSourceId = (string) ($isRefinement
                ? ($prevEx['root_source_layer_id'] ?? $prevEx['source_layer_id'] ?? $layerId)
                : $layerId);
            $extractionGeneration = $isRefinement
                ? (int) ($prevEx['extraction_generation'] ?? 0) + 1
                : 1;
            $parentExtractionId = $isRefinement ? (string) $layerId : null;

            $candMeta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            $extraction = [
                'created_by' => 'ai_layer_extraction',
                'provider' => $session->provider,
                'model' => $session->model,
                'extraction_session_id' => $session->id,
                'candidate_id' => $cid,
                'source_layer_id' => $layerId,
                'source_asset_id' => (string) $session->source_asset_id,
                'root_source_layer_id' => $rootSourceId,
                'extraction_generation' => $extractionGeneration,
                'parent_extraction_layer_id' => $parentExtractionId,
                'bbox' => [
                    'x' => $bx,
                    'y' => $by,
                    'width' => $bw,
                    'height' => $bh,
                ],
                'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null,
                'method' => isset($candMeta['method']) && is_string($candMeta['method']) ? $candMeta['method'] : null,
            ];
            $meta = ['studio_layer_extraction' => $extraction];

            $created = $this->assetFactory->createCutoutPng(
                $tenant,
                $brand,
                $user,
                $cutoutPng,
                $cw,
                $ch,
                $meta
            );

            $lx = $tx + ($bx / $nw) * $tw;
            $ly = $ty + ($by / $nh) * $th;
            $lw = ($bw / $nw) * $tw;
            $lh = ($bh / $nh) * $th;
            $zBase = $filledLayerDef === null ? $maxZ : $zFill;
            $zHere = $zBase + 1 + $cutIndex;
            $cutIndex++;

            $newCutoutLayerId = (string) Str::uuid();
            $this->logStudioLayerExtractionLayerCreated(
                $composition->id,
                $layerId,
                $newCutoutLayerId,
                $cid,
                (string) $created['asset_id'],
            );

            $newDocLayers[] = [
                'id' => $newCutoutLayerId,
                'type' => 'image',
                'name' => $name,
                'visible' => true,
                'locked' => false,
                'z' => $zHere,
                'transform' => [
                    'x' => round($lx, 3),
                    'y' => round($ly, 3),
                    'width' => round($lw, 3),
                    'height' => round($lh, 3),
                    'rotation' => $sourceLayer['transform']['rotation'] ?? 0,
                ],
                'assetId' => $created['asset_id'],
                'src' => $created['url'],
                'naturalWidth' => $cw,
                'naturalHeight' => $ch,
                'fit' => 'fill',
                'studioLayerExtraction' => $extraction,
            ];
        }

        if (count($newDocLayers) === 0) {
            throw new InvalidArgumentException('No valid candidates selected.');
        }

        $newLayerIds = [];
        if ($filledLayerDef !== null) {
            $newLayerIds[] = (string) $filledLayerDef['id'];
        }
        foreach ($newDocLayers as $ld) {
            if ($filledLayerDef !== null && (string) $ld['id'] === (string) $filledLayerDef['id']) {
                continue;
            }
            $newLayerIds[] = (string) $ld['id'];
        }

        $merged = $this->insertLayersAbove($layers, $layerId, $newDocLayers);

        $origVisible = $keepOriginalVisible;
        if ($createFilledBackground && $hideOriginalAfterExtraction) {
            $origVisible = false;
        }
        if (! $origVisible) {
            $merged = $this->setLayerVisible($merged, $layerId, false);
        }
        $merged = $this->normalizeZs($merged);

        $doc['layers'] = $merged;

        $idList = array_map(static fn (array $p) => (string) $p['id'], $validRows);
        $metaUpdate = array_merge($session->metadata ?? [], [
            'selected_candidate_ids' => $idList,
            'background_fill_requested' => $createFilledBackground,
            'background_fill_supported' => $canFill,
            'background_fill_asset_id' => $fillAssetId,
            'combined_mask_relative' => $combinedRel,
        ]);

        DB::transaction(function () use ($composition, $doc, $session, $metaUpdate) {
            $composition->document_json = $doc;
            $composition->save();
            $session->update([
                'status' => StudioLayerExtractionSession::STATUS_CONFIRMED,
                'metadata' => $metaUpdate,
            ]);
        });

        try {
            $this->deleteSessionFiles($session->id);
        } catch (\Throwable) {
            // best-effort cleanup of staged masks
        }

        return [
            'document' => $doc,
            'new_layer_ids' => $newLayerIds,
        ];
    }

    private function resolveLayerName(array $layerNames, string $cid, int $i, string $default): string
    {
        if (isset($layerNames[$cid]) && is_string($layerNames[$cid]) && trim($layerNames[$cid]) !== '') {
            return trim($layerNames[$cid]);
        }
        if (isset($layerNames[$i]) && is_string($layerNames[$i]) && trim($layerNames[$i]) !== '') {
            return trim($layerNames[$i]);
        }

        return $default;
    }

    /**
     * Union of per-candidate full-frame mask PNGs (white = foreground subject to extract from background).
     *
     * @param  list<string>  $maskPngs
     * @return non-empty-string
     */
    private function mergeUnionForegroundMasksPng(int $w, int $h, array $maskPngs): string
    {
        if ($maskPngs === []) {
            throw new RuntimeException('No mask inputs.');
        }
        $out = imagecreatetruecolor($w, $h);
        if ($out === false) {
            throw new RuntimeException('Failed to allocate union mask image.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $w - 1, $h - 1, $transparent);

        $fg = imagecolorallocatealpha($out, 255, 255, 255, 0);

        foreach ($maskPngs as $bin) {
            $im = @imagecreatefromstring($bin);
            if ($im === false) {
                imagedestroy($out);
                throw new RuntimeException('Could not decode a candidate mask for merging.');
            }
            if (imagesx($im) !== $w || imagesy($im) !== $h) {
                imagedestroy($im);
                imagedestroy($out);
                throw new InvalidArgumentException('Mask size does not match the source image.');
            }
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $mc = imagecolorat($im, $x, $y);
                    $mr = ($mc >> 16) & 0xFF;
                    $mg = ($mc >> 8) & 0xFF;
                    $mbit = $mc & 0xFF;
                    $mma = ($mc >> 24) & 127;
                    $maskFgWeight = (($mr + $mg + $mbit) / 3.0) / 255.0;
                    $maskFgWeight *= (127 - $mma) / 127.0;
                    $maskFgWeight = max(0.0, min(1.0, $maskFgWeight));
                    if ($maskFgWeight > 0.35) {
                        imagesetpixel($out, $x, $y, $fg);
                    }
                }
            }
            imagedestroy($im);
        }

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        imagedestroy($out);
        if ($png === '') {
            throw new RuntimeException('Failed to encode combined mask.');
        }

        return $png;
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @param  list<array<string, mixed>>  $insert
     * @return list<array<string, mixed>>
     */
    private function insertLayersAbove(array $layers, string $sourceId, array $insert): array
    {
        $sorted = $layers;
        usort($sorted, function ($a, $b) {
            $za = (int) ($a['z'] ?? 0);
            $zb = (int) ($b['z'] ?? 0);
            if ($za !== $zb) {
                return $za <=> $zb;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $idx = -1;
        foreach ($sorted as $i => $l) {
            if ((string) ($l['id'] ?? '') === $sourceId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === -1) {
            return array_merge($layers, $insert);
        }

        $before = array_slice($sorted, 0, $idx + 1);
        $after = array_slice($sorted, $idx + 1);

        return array_merge($before, $insert, $after);
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return list<array<string, mixed>>
     */
    private function normalizeZs(array $layers): array
    {
        $sorted = $layers;
        usort($sorted, function ($a, $b) {
            $za = (int) ($a['z'] ?? 0);
            $zb = (int) ($b['z'] ?? 0);
            if ($za !== $zb) {
                return $za <=> $zb;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        foreach ($sorted as $i => &$l) {
            $l['z'] = $i;
        }

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return list<array<string, mixed>>
     */
    private function setLayerVisible(array $layers, string $layerId, bool $visible): array
    {
        $out = [];
        foreach ($layers as $l) {
            if ((string) ($l['id'] ?? '') === $layerId) {
                $l['visible'] = $visible;
            }
            $out[] = $l;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $layers
     * @return array<string, mixed>|null
     */
    private function findLayer(array $layers, string $id): ?array
    {
        foreach ($layers as $l) {
            if ((string) ($l['id'] ?? '') === $id) {
                return $l;
            }
        }

        return null;
    }

    private function deleteSessionFiles(string $sessionId): void
    {
        $disk = Storage::disk('studio_layer_extraction');
        $disk->deleteDirectory($sessionId);
    }

    /**
     * Debug/delivery visibility for cutout and filled-background layers (no signed URLs).
     */
    private function logStudioLayerExtractionLayerCreated(
        int $documentId,
        string $sourceLayerId,
        string $newLayerId,
        ?string $candidateId,
        string $assetId,
    ): void {
        $asset = Asset::query()->find($assetId);
        if ($asset === null) {
            Log::warning('[studio_layer_extraction_layer_created] missing_asset', [
                'document_id' => $documentId,
                'source_layer_id' => $sourceLayerId,
                'new_layer_id' => $newLayerId,
                'candidate_id' => $candidateId,
                'asset_id' => $assetId,
            ]);

            return;
        }
        $version = $asset->currentVersion;
        $path = (string) ($asset->storage_root_path ?? '');
        $pathExists = $path !== '' && Storage::disk('s3')->exists($path);

        Log::info('[studio_layer_extraction_layer_created]', [
            'document_id' => $documentId,
            'source_layer_id' => $sourceLayerId,
            'new_layer_id' => $newLayerId,
            'candidate_id' => $candidateId,
            'asset_id' => $assetId,
            'asset_version_id' => $version?->id,
            'disk' => 's3',
            'path_exists' => $pathExists,
            'mime_type' => $asset->mime_type,
            'width' => $asset->width,
            'height' => $asset->height,
        ]);
    }

    /**
     * @return non-empty-string
     */
    private function buildCutoutPng(string $origBinary, string $maskPngBinary, int $bx, int $by, int $bw, int $bh): string
    {
        $src = @imagecreatefromstring($origBinary);
        $mask = @imagecreatefromstring($maskPngBinary);
        if ($src === false || $mask === false) {
            throw new RuntimeException('Could not decode images for cutout.');
        }
        if (! imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        if (! imageistruecolor($mask)) {
            imagepalettetotruecolor($mask);
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $mw = imagesx($mask);
        $mh = imagesy($mask);
        if ($sw !== $mw || $sh !== $mh) {
            imagedestroy($src);
            imagedestroy($mask);
            throw new RuntimeException('Mask size does not match source image.');
        }

        $out = imagecreatetruecolor($sw, $sh);
        if ($out === false) {
            imagedestroy($src);
            imagedestroy($mask);
            throw new RuntimeException('Could not allocate output image.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $sw - 1, $sh - 1, $transparent);

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $mc = imagecolorat($mask, $x, $y);
                $mr = ($mc >> 16) & 0xFF;
                $mg = ($mc >> 8) & 0xFF;
                $mb = $mc & 0xFF;
                $mma = ($mc >> 24) & 127;
                $maskFgWeight = (($mr + $mg + $mb) / 3.0) / 255.0;
                $maskFgWeight *= (127 - $mma) / 127.0;
                $maskFgWeight = max(0.0, min(1.0, $maskFgWeight));

                $sc = imagecolorat($src, $x, $y);
                $sr = ($sc >> 16) & 0xFF;
                $sg = ($sc >> 8) & 0xFF;
                $sb = $sc & 0xFF;
                $sa = ($sc >> 24) & 127;
                $srcOp = (127 - $sa) / 127.0;
                $srcOp = max(0.0, min(1.0, $srcOp));

                $outOp = $maskFgWeight * $srcOp;
                if ($outOp < 0.001) {
                    continue;
                }
                $gdAlpha = (int) round(127 * (1.0 - $outOp));
                $gdAlpha = max(0, min(127, $gdAlpha));
                $col = imagecolorallocatealpha($out, $sr, $sg, $sb, $gdAlpha);
                imagesetpixel($out, $x, $y, $col);
            }
        }

        imagedestroy($src);
        imagedestroy($mask);

        $bx = max(0, min($sw - 1, $bx));
        $by = max(0, min($sh - 1, $by));
        $bw = max(1, min($sw - $bx, $bw));
        $bh = max(1, min($sh - $by, $bh));

        $cropped = imagecrop($out, ['x' => $bx, 'y' => $by, 'width' => $bw, 'height' => $bh]);
        imagedestroy($out);
        if ($cropped === false) {
            throw new RuntimeException('imagecrop failed.');
        }

        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        ob_start();
        imagepng($cropped);
        $png = (string) ob_get_clean();
        imagedestroy($cropped);
        if ($png === '') {
            throw new RuntimeException('Failed to encode cutout.');
        }

        return $png;
    }
}
