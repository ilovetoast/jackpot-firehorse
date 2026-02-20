<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\AssetMetadata;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetVersionRestoreService
{
    public function restore(
        Asset $asset,
        AssetVersion $sourceVersion,
        bool $preserveMetadata = true,
        bool $rerunPipeline = false,
        ?string $restoredBy = null
    ): AssetVersion {

        return DB::transaction(function () use (
            $asset,
            $sourceVersion,
            $preserveMetadata,
            $rerunPipeline,
            $restoredBy
        ) {
            // Phase 7: Lock asset and versions to prevent replace/restore collision
            $asset = Asset::where('id', $asset->id)->lockForUpdate()->firstOrFail();
            $asset->versions()->lockForUpdate()->get();

            $limit = app(PlanService::class)->maxVersionsPerAsset($asset->tenant);
            $currentCount = $asset->versions()->withTrashed()->count();

            if ($currentCount >= $limit) {
                throw new \DomainException(
                    "This asset has reached the maximum number of versions allowed by your plan ({$limit})."
                );
            }

            // Determine next version number (exclude trashed for promotion; withTrashed for limit count)
            $nextVersion = ($asset->versions()->max('version_number') ?? 0) + 1;

            // Build new file path
            $extension = pathinfo($sourceVersion->file_path, PATHINFO_EXTENSION);
            $newPath = "assets/{$asset->id}/v{$nextVersion}/original.{$extension}";

            // Copy file in S3 - use asset's storage bucket (tenant bucket on staging, shared on local)
            $bucket = $asset->storageBucket;
            if (!$bucket) {
                throw new \RuntimeException("Asset has no storage bucket - cannot restore version.");
            }
            $s3Client = $this->createS3Client();
            $s3Client->copyObject([
                'Bucket' => $bucket->name,
                'CopySource' => rawurlencode($bucket->name . '/' . $sourceVersion->file_path),
                'Key' => $newPath,
            ]);

            // Phase 7: Lock previous current versions before toggle
            $asset->versions()
                ->where('is_current', true)
                ->lockForUpdate()
                ->update(['is_current' => false]);

            // Create new version
            $newVersion = AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => $nextVersion,
                'file_path' => $newPath,
                'file_size' => $sourceVersion->file_size,
                'mime_type' => $sourceVersion->mime_type,
                'width' => $sourceVersion->width,
                'height' => $sourceVersion->height,
                'checksum' => $sourceVersion->checksum,
                'uploaded_by' => $restoredBy,
                'pipeline_status' => $rerunPipeline ? 'pending' : 'complete',
                'is_current' => true,
                'restored_from_version_id' => $sourceVersion->id,
            ]);

            // Preserve metadata snapshot
            if ($preserveMetadata && !$rerunPipeline) {

                $metadataRows = AssetMetadata::where(
                    'asset_version_id',
                    $sourceVersion->id
                )->get();

                foreach ($metadataRows as $row) {
                    $data = $row->getAttributes();
                    unset($data['id'], $data['created_at'], $data['updated_at']);
                    $data['asset_version_id'] = $newVersion->id;
                    $data['asset_id'] = $asset->id;
                    $data['created_at'] = now();
                    $data['updated_at'] = now();
                    AssetMetadata::create($data);
                }
            }

            // Update asset compatibility pointer
            $asset->update([
                'storage_root_path' => $newPath
            ]);

            // Optional: rerun full pipeline (FileInspection + thumbnails + metadata + AI + finalize)
            if ($rerunPipeline) {
                $meta = $asset->metadata ?? [];
                foreach (['processing_started', 'processing_started_at', 'thumbnails_generated', 'thumbnails_generated_at', 'metadata_extracted', 'metadata_extracted_at', 'thumbnails', 'preview_thumbnails', 'thumbnail_dimensions', 'thumbnail_timeout'] as $k) {
                    unset($meta[$k]);
                }
                $asset->update([
                    'analysis_status' => 'uploading',
                    'thumbnail_status' => \App\Enums\ThumbnailStatus::PENDING,
                    'metadata' => $meta,
                ]);
                dispatch(new \App\Jobs\ProcessAssetJob($newVersion->id));
            } else {
                // Without rerun: copy thumbnails from source version to new version path
                // so the UI shows the correct thumbnail for the restored file.
                // Dispatch to worker so it runs with S3 ListBucket permission (web has restricted perms).
                \App\Jobs\CopyThumbnailsForRestoredVersionJob::dispatch(
                    $asset->id,
                    $sourceVersion->id,
                    $newVersion->id
                );
            }

            app(AssetVersionService::class)->assertSingleCurrentVersion($asset);

            $user = $restoredBy ? \App\Models\User::find($restoredBy) : null;
            \App\Services\ActivityRecorder::logAsset(
                $asset,
                \App\Enums\EventType::ASSET_VERSION_RESTORED,
                ['version_number' => $newVersion->version_number, 'version_id' => $newVersion->id, 'restored_from_version_id' => $sourceVersion->id],
                $user
            );

            return $newVersion;
        });
    }

    protected function createS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region', 'us-east-1'),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }
        return new S3Client($config);
    }
}
