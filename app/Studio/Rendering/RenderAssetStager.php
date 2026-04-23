<?php

namespace App\Studio\Rendering;

use App\Models\Asset;
use App\Models\Tenant;
use App\Studio\Rendering\Dto\RenderLayer;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Str;

/**
 * Resolves DAM assets to local temp files for FFmpeg {@code -i} inputs.
 */
final class RenderAssetStager
{
    /**
     * @param  list<RenderLayer>  $layers
     * @return list<RenderLayer> layers with mediaPath set
     */
    public function stageOverlayLayers(Tenant $tenant, array $layers, string $workspacePath): array
    {
        $out = [];
        foreach ($layers as $ly) {
            if ($ly->type === 'text') {
                $out[] = $ly;

                continue;
            }
            if ($ly->mediaPath !== null && $ly->mediaPath !== '' && is_file($ly->mediaPath)) {
                $out[] = $ly;

                continue;
            }
            $assetId = (string) ($ly->extra['asset_id'] ?? '');
            if ($assetId === '') {
                throw new \RuntimeException(
                    'Overlay layer '.$ly->id.' is missing a DAM asset id (assetId / asset_id / resultAssetId). '
                    .'Image layers saved with only a remote or data URL src cannot be exported server-side; '
                    .'use a library asset or ensure generative results keep resultAssetId when converted to image.'
                );
            }
            $asset = Asset::query()
                ->where('id', $assetId)
                ->where('tenant_id', $tenant->id)
                ->first();
            if (! $asset) {
                throw new \RuntimeException('Asset not found for layer '.$ly->id.' (asset '.$assetId.').');
            }
            $ver = $asset->currentVersion()->first();
            $rel = $ver?->file_path ?? $asset->file_path;
            if (! is_string($rel) || $rel === '') {
                throw new \RuntimeException('Asset '.$assetId.' has no file path.');
            }
            $raw = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $rel);
            $mime = (string) ($asset->mime_type ?? '');
            $ext = $this->guessExtension($rel, $mime);
            $path = $workspacePath.DIRECTORY_SEPARATOR.'staged_'.$ly->id.'_'.Str::random(6).'.'.$ext;
            file_put_contents($path, $raw);
            $out[] = new RenderLayer(
                id: $ly->id,
                type: $ly->type,
                zIndex: $ly->zIndex,
                startSeconds: $ly->startSeconds,
                endSeconds: $ly->endSeconds,
                visible: $ly->visible,
                x: $ly->x,
                y: $ly->y,
                width: $ly->width,
                height: $ly->height,
                opacity: $ly->opacity,
                rotationDegrees: $ly->rotationDegrees,
                fit: $ly->fit,
                isPrimaryVideo: $ly->isPrimaryVideo,
                mediaPath: $path,
                trimInMs: $ly->trimInMs,
                trimOutMs: $ly->trimOutMs,
                muted: $ly->muted,
                fadeInMs: $ly->fadeInMs,
                fadeOutMs: $ly->fadeOutMs,
                extra: $ly->extra,
            );
        }

        return $out;
    }

    public function stagePrimaryVideo(Asset $asset, string $relativePath, string $workspacePath): string
    {
        $raw = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $relativePath);
        $path = $workspacePath.DIRECTORY_SEPARATOR.'primary_'.Str::random(8).'.mp4';
        file_put_contents($path, $raw);

        return $path;
    }

    private function guessExtension(string $rel, string $mime): string
    {
        $lower = strtolower($rel);
        if (str_ends_with($lower, '.png')) {
            return 'png';
        }
        if (str_ends_with($lower, '.webp')) {
            return 'webp';
        }
        if (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg')) {
            return 'jpg';
        }
        if (str_contains($mime, 'png')) {
            return 'png';
        }
        if (str_contains($mime, 'webp')) {
            return 'webp';
        }
        if (str_contains($mime, 'jpeg')) {
            return 'jpg';
        }

        return 'bin';
    }
}
