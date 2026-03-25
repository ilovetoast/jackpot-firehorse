<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persists in-progress composition preview PNGs as {@link Asset} rows on canonical tenant storage.
 *
 * **One {@link Asset} per composition thumbnail** (referenced by `compositions.thumbnail_asset_id`).
 * Each save appends a new {@link AssetVersion} and writes a new `v{n}/original.png` key — no new asset UUID
 * per autosave (avoids admin list spam from soft-deleted preview rows).
 *
 * Type {@see AssetType::AI_GENERATED}. WIP only: no {@see \App\Jobs\ProcessAssetJob}.
 * In-app URLs use {@see \App\Http\Controllers\Editor\EditorCompositionController::thumbnailAsset}.
 */
class CompositionThumbnailAssetService
{
    private const MAX_VERSIONS_TO_RETAIN = 20;

    public function __construct(
        protected AssetPathGenerator $pathGenerator
    ) {}

    /**
     * Store PNG bytes for a composition thumbnail: reuse existing asset id when $replaceAssetId is set.
     *
     * @return non-empty-string Asset UUID
     */
    public function createFromPngBinary(
        Tenant $tenant,
        Brand $brand,
        User $user,
        string $pngBinary,
        ?string $replaceAssetId = null
    ): string {
        if ($tenant->uuid === null || $tenant->uuid === '') {
            throw new RuntimeException('Tenant UUID is required for composition thumbnail storage.');
        }

        if ($replaceAssetId !== null && $replaceAssetId !== '') {
            $existing = Asset::withTrashed()->find($replaceAssetId);
            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                return $this->appendVersionToExistingAsset($tenant, $brand, $user, $existing, $pngBinary);
            }
        }

        return $this->createNewThumbnailAsset($tenant, $brand, $user, $pngBinary);
    }

    /**
     * @return non-empty-string
     */
    private function appendVersionToExistingAsset(
        Tenant $tenant,
        Brand $brand,
        User $user,
        Asset $asset,
        string $pngBinary
    ): string {
        return DB::transaction(function () use ($tenant, $brand, $user, $asset, $pngBinary) {
            $size = strlen($pngBinary);
            $dims = @getimagesizefromstring($pngBinary);
            $width = isset($dims[0]) ? (int) $dims[0] : null;
            $height = isset($dims[1]) ? (int) $dims[1] : null;

            $maxVersion = (int) (AssetVersion::query()
                ->where('asset_id', $asset->id)
                ->max('version_number') ?? 0);

            $nextVersion = $maxVersion + 1;

            $path = $this->pathGenerator->generateOriginalPathForAssetId(
                $tenant,
                $asset->id,
                $nextVersion,
                'png'
            );

            $previousPath = $asset->storage_root_path;

            Storage::disk('s3')->put($path, $pngBinary, 'private');

            AssetVersion::query()
                ->where('asset_id', $asset->id)
                ->update(['is_current' => false]);

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => $nextVersion,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => 'image/png',
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $pngBinary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            $meta = $asset->metadata ?? [];
            if (! is_array($meta)) {
                $meta = [];
            }
            $meta['composition_preview'] = true;
            $meta['composition_wip'] = true;
            $meta['asset_role'] = 'composition_thumbnail';
            $meta['last_preview_at'] = now()->toIso8601String();

            $asset->update([
                'storage_root_path' => $path,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'mime_type' => 'image/png',
                'metadata' => $meta,
            ]);

            if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $path) {
                try {
                    Storage::disk('s3')->delete($previousPath);
                } catch (\Throwable $e) {
                    Log::warning('[CompositionThumbnailAssetService] Could not delete previous preview object', [
                        'asset_id' => $asset->id,
                        'path' => $previousPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->pruneOldVersions($asset->id, self::MAX_VERSIONS_TO_RETAIN);

            Log::info('Composition preview version appended', [
                'asset_id' => $asset->id,
                'version' => $nextVersion,
                'bytes' => $size,
            ]);

            return $asset->id;
        });
    }

    /**
     * @return non-empty-string
     */
    private function createNewThumbnailAsset(
        Tenant $tenant,
        Brand $brand,
        User $user,
        string $pngBinary
    ): string {
        return DB::transaction(function () use ($tenant, $brand, $user, $pngBinary) {
            $size = strlen($pngBinary);
            $dims = @getimagesizefromstring($pngBinary);
            $width = isset($dims[0]) ? (int) $dims[0] : null;
            $height = isset($dims[1]) ? (int) $dims[1] : null;

            $assetId = (string) Str::uuid();
            $path = $this->pathGenerator->generateOriginalPathForAssetId(
                $tenant,
                $assetId,
                1,
                'png'
            );

            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => 'Composition preview',
                'original_filename' => 'composition-preview.png',
                'mime_type' => 'image/png',
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'analysis_status' => 'complete',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'composition_editor',
                'builder_staged' => false,
                'intake_state' => 'normal',
                'metadata' => [
                    'composition_preview' => true,
                    'composition_wip' => true,
                    'asset_role' => 'composition_thumbnail',
                ],
            ]);

            Storage::disk('s3')->put($path, $pngBinary, 'private');

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => 'image/png',
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $pngBinary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            return $asset->id;
        });
    }

    /**
     * Remove oldest version rows and S3 objects beyond the cap (keeps current + N-1).
     */
    private function pruneOldVersions(string $assetId, int $maxRetain): void
    {
        $versions = AssetVersion::query()
            ->where('asset_id', $assetId)
            ->whereNull('deleted_at')
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'file_path']);

        if ($versions->count() <= $maxRetain) {
            return;
        }

        $toRemove = $versions->slice($maxRetain);
        foreach ($toRemove as $row) {
            $path = $row->file_path;
            try {
                if (is_string($path) && $path !== '') {
                    Storage::disk('s3')->delete($path);
                }
            } catch (\Throwable) {
                // best-effort
            }
            AssetVersion::query()->whereKey($row->id)->delete();
        }
    }

    /**
     * Duplicate preview binary onto a new asset (new UUID + canonical path).
     */
    public function duplicateAsset(?string $sourceAssetId, Tenant $tenant, Brand $brand, User $user): ?string
    {
        if ($sourceAssetId === null || $sourceAssetId === '') {
            return null;
        }

        $source = Asset::query()->find($sourceAssetId);
        if (! $source || $source->storage_root_path === null || $source->storage_root_path === '') {
            return null;
        }

        try {
            $binary = Storage::disk('s3')->get($source->storage_root_path);
        } catch (\Throwable) {
            return null;
        }

        if ($binary === null || $binary === '') {
            return null;
        }

        return $this->createFromPngBinary($tenant, $brand, $user, $binary, null);
    }
}
