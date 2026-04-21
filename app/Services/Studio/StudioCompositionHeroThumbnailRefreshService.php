<?php

namespace App\Services\Studio;

use App\Models\Asset;
use App\Models\Composition;
use App\Models\User;
use App\Services\CompositionAssetReferenceStateService;
use App\Services\CompositionThumbnailAssetService;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\StudioEditorDocumentProductLayerFinder;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort refresh of {@link Composition::$thumbnail_asset_id} from the hero product image layer after
 * server-side document edits (e.g. Studio Versions generation). Runs asynchronously from {@see \App\Jobs\RefreshCompositionThumbnailFromProductLayerJob}.
 */
final class StudioCompositionHeroThumbnailRefreshService
{
    public function __construct(
        protected CompositionThumbnailAssetService $thumbnailAssets,
        protected CompositionAssetReferenceStateService $compositionRefState,
    ) {}

    public function refreshFromHeroProductLayer(Composition $composition, User $user): void
    {
        $tenant = $composition->tenant;
        $brand = $composition->brand;
        if (! $tenant || ! $brand) {
            return;
        }

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $target = StudioEditorDocumentProductLayerFinder::find($doc);
        if ($target === null) {
            return;
        }

        $asset = Asset::query()->find($target['asset_id']);
        if (! $asset) {
            return;
        }

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (\Throwable $e) {
            Log::info('[StudioCompositionHeroThumbnailRefreshService] skip_load', [
                'composition_id' => $composition->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $png = $this->downscaleToPng($binary, 512);
        if ($png === null || $png === '') {
            return;
        }

        $assetId = $this->thumbnailAssets->createFromPngBinary(
            $tenant,
            $brand,
            $user,
            $png,
            $composition->thumbnail_asset_id,
            (int) $composition->id
        );
        $composition->thumbnail_asset_id = $assetId;
        $composition->save();

        $thumb = Asset::query()->find($assetId);
        if ($thumb !== null) {
            $this->compositionRefState->refreshForAsset($thumb);
        }
    }

    /**
     * @return non-empty-string|null
     */
    private function downscaleToPng(string $binary, int $maxSide): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            return null;
        }

        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);

            return null;
        }

        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagepng($dst, null, 6);
        imagedestroy($dst);
        $out = ob_get_clean();
        if (! is_string($out) || $out === '') {
            return null;
        }

        return $out;
    }
}
