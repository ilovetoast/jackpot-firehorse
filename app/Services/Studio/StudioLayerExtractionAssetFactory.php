<?php

namespace App\Services\Studio;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetPathGenerator;
use App\Services\CompositionAssetReferenceStateService;
use App\Services\TenantBucketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists Studio layer-extraction cutouts as tenant-scoped {@link Asset} rows (same storage pattern as generative editor).
 */
final class StudioLayerExtractionAssetFactory
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator,
        protected TenantBucketService $tenantBucketService,
        protected CompositionAssetReferenceStateService $compositionRefState,
    ) {}

    /**
     * @param  array<string, mixed>  $extractionMeta
     * @return array{asset_id: string, url: string}
     */
    public function createCutoutPng(
        Tenant $tenant,
        Brand $brand,
        User $user,
        string $pngBinary,
        ?int $width,
        ?int $height,
        array $extractionMeta = [],
    ): array {
        if ($tenant->uuid === null || $tenant->uuid === '') {
            throw new \InvalidArgumentException('Tenant UUID is required for asset storage.');
        }

        $bucket = $this->tenantBucketService->resolveActiveBucketOrFail($tenant);
        $assetId = (string) Str::uuid();
        $path = $this->pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, 'png');
        $size = strlen($pngBinary);

        $meta = array_merge([
            'asset_role' => 'studio_layer_extraction_cutout',
            'ai_generated' => true,
            'generated_at' => now()->toIso8601String(),
        ], $extractionMeta);

        $asset = DB::transaction(function () use (
            $tenant,
            $brand,
            $user,
            $bucket,
            $assetId,
            $path,
            $pngBinary,
            $size,
            $width,
            $height,
            $meta,
        ) {
            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'storage_bucket_id' => $bucket->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => 'Extracted layer '.now()->format('M j, Y g:i a'),
                'original_filename' => 'studio-extract-'.$assetId.'.png',
                'mime_type' => 'image/png',
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'analysis_status' => 'complete',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'studio_layer_extraction',
                'builder_staged' => false,
                'intake_state' => 'normal',
                'metadata' => $meta,
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

            return $asset;
        });

        $url = $this->editorImageLayerSrcForDb($asset->id);
        $this->compositionRefState->refreshForAsset($asset);

        return ['asset_id' => $asset->id, 'url' => $url];
    }

    /**
     * @param  array<string, mixed>  $extractionMeta  Must include `studio_layer_extraction_background_fill` for provenance.
     * @return array{asset_id: string, url: string}
     */
    public function createFilledBackgroundImage(
        Tenant $tenant,
        Brand $brand,
        User $user,
        string $imageBinary,
        ?int $width,
        ?int $height,
        string $mimeType,
        string $fileExt,
        array $extractionMeta = [],
    ): array {
        if ($tenant->uuid === null || $tenant->uuid === '') {
            throw new \InvalidArgumentException('Tenant UUID is required for asset storage.');
        }

        $bucket = $this->tenantBucketService->resolveActiveBucketOrFail($tenant);
        $assetId = (string) Str::uuid();
        $path = $this->pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, $fileExt);
        $size = strlen($imageBinary);

        $meta = array_merge([
            'asset_role' => 'studio_layer_extraction_background_fill',
            'ai_generated' => true,
            'generated_at' => now()->toIso8601String(),
        ], $extractionMeta);

        $asset = DB::transaction(function () use (
            $tenant,
            $brand,
            $user,
            $bucket,
            $assetId,
            $path,
            $imageBinary,
            $size,
            $width,
            $height,
            $meta,
            $mimeType,
            $fileExt,
        ) {
            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'storage_bucket_id' => $bucket->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => 'Filled background',
                'original_filename' => 'studio-extract-bg-'.$assetId.'.'.$fileExt,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'analysis_status' => 'complete',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'studio_layer_extraction',
                'builder_staged' => false,
                'intake_state' => 'normal',
                'metadata' => $meta,
            ]);

            Storage::disk('s3')->put($path, $imageBinary, 'private');

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $imageBinary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            return $asset;
        });

        $url = $this->editorImageLayerSrcForDb($asset->id);
        $this->compositionRefState->refreshForAsset($asset);

        return [
            'asset_id' => $asset->id,
            'url' => $url,
        ];
    }

    /**
     * Relative path for `document_json` image layers: GET /app/api/assets/{id}/file (no APP_URL).
     */
    public function editorImageLayerSrcForDb(string $assetId): string
    {
        return route('api.editor.assets.file', ['asset' => $assetId], absolute: false);
    }
}
