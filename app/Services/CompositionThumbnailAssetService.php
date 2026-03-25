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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persists in-progress composition preview PNGs as {@link Asset} rows on canonical tenant storage
 * (same key layout as uploads: tenants/{tenant_uuid}/assets/{asset_uuid}/v1/original.png).
 *
 * Type {@see AssetType::AI_GENERATED}. These are WIP previews only: we do not run
 * {@see \App\Jobs\ProcessAssetJob}
 * (no thumbnail jobs, embedding, or grid lifecycle). They stay unpublished ({@see Asset::$published_at} null)
 * and off the default grid until a future “export / publish as asset” flow promotes them.
 * In-app URLs use {@see \App\Http\Controllers\Editor\EditorCompositionController::thumbnailAsset} (same-origin stream).
 *
 * Not stored on the public local disk — staging/production use S3 + CloudFront like other assets.
 */
class CompositionThumbnailAssetService
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator
    ) {}

    /**
     * Create a generative preview asset from PNG bytes (flat image, same canonical key as uploaded originals).
     * Optionally soft-delete a previous asset id.
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
            Asset::query()->whereKey($replaceAssetId)->delete();
        }

        return DB::transaction(function () use ($tenant, $brand, $user, $pngBinary) {
            $size = strlen($pngBinary);

            $dims = @getimagesizefromstring($pngBinary);
            $width = isset($dims[0]) ? (int) $dims[0] : null;
            $height = isset($dims[1]) ? (int) $dims[1] : null;

            // Pre-assign UUID so we can set storage_root_path on insert (MySQL: column has no default).
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
                // Flat PNG is the preview; no derivative thumbnail pipeline for WIP compositions.
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
