<?php

namespace App\Services\Studio;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetPathGenerator;
use App\Services\TenantBucketService;
use App\Support\PipelineQueueResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists a finished Studio composition MP4 to storage and creates the asset row + pipeline dispatch.
 * Shared by {@see StudioCompositionVideoExportService} (legacy) and {@see StudioCompositionCanvasRuntimeVideoExportService}.
 */
final class StudioCompositionVideoExportMp4Publisher
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator,
        protected EditorStudioVideoPublishApplier $videoPublishApplier,
    ) {}

    /**
     * @param  array<string, mixed>  $technicalMeta  Merged into job meta_json on success (caller supplies export-specific keys).
     * @return array{asset: Asset, technical: array<string, mixed>}
     */
    public function publish(
        StudioCompositionVideoExportJob $row,
        Composition $composition,
        Tenant $tenant,
        User $user,
        string $localMp4Path,
        int $width,
        int $height,
        array $technicalMeta,
    ): array {
        $bytes = @file_get_contents($localMp4Path);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Export file was empty or unreadable.');
        }
        $size = strlen($bytes);
        $brand = $composition->brand;
        if (! $brand) {
            throw new \RuntimeException('Composition brand missing.');
        }
        $outDisk = (string) config('studio_animation.output_disk', 's3');
        $newAssetId = (string) Str::uuid();
        $path = $this->pathGenerator->generateOriginalPathForAssetId($tenant, $newAssetId, 1, 'mp4');
        Storage::disk($outDisk)->put($path, $bytes, 'private');

        $storageBucketId = null;
        try {
            $storageBucketId = app(TenantBucketService::class)->getOrProvisionBucket($tenant)->id;
        } catch (\Throwable $e) {
            Log::warning('[StudioCompositionVideoExportMp4Publisher] Could not resolve tenant storage bucket; asset will rely on disk fallback', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        $requestMeta = is_array($row->meta_json) ? $row->meta_json : [];

        $asset = Asset::forceCreate([
            'id' => $newAssetId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::AI_GENERATED,
            'title' => 'Studio video export',
            'original_filename' => 'studio-export-'.$row->id.'.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'storage_bucket_id' => $storageBucketId,
            'storage_root_path' => $path,
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'uploading',
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'published_at' => null,
            'source' => 'studio_composition_video_export',
            'metadata' => [
                'studio_composition_video_export_job_id' => (string) $row->id,
                'composition_id' => (string) $composition->id,
            ],
        ]);

        $pub = is_array($requestMeta['editor_publish'] ?? null) ? $requestMeta['editor_publish'] : null;
        if (is_array($pub) && $pub !== []) {
            try {
                $this->videoPublishApplier->apply($asset, $user, $tenant, $brand, $pub);
            } catch (\Throwable $e) {
                Log::warning('[StudioCompositionVideoExportMp4Publisher] editor_publish apply failed', [
                    'job_id' => $row->id,
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->videoPublishApplier->ensureShelfCategoryWhenMissing($asset->fresh(), $tenant, $brand);

        AssetVersion::query()->create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $path,
            'file_size' => $size,
            'mime_type' => 'video/mp4',
            'width' => $width,
            'height' => $height,
            'checksum' => hash('sha256', $bytes),
            'is_current' => true,
            'pipeline_status' => 'pending',
            'uploaded_by' => $user->id,
        ]);

        DB::afterCommit(function () use ($asset): void {
            ProcessAssetJob::dispatch((string) $asset->id)
                ->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
        });

        $technical = array_merge($technicalMeta, [
            'output_asset_id' => $asset->id,
        ]);

        return ['asset' => $asset, 'technical' => $technical];
    }
}
