<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Models\Asset;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill thumbnail dimensions for legacy assets.
 *
 * Existing assets uploaded before thumbnail_dimensions persistence may lack
 * metadata.thumbnail_dimensions.medium. This command:
 * - Queries assets with thumbnail_status=completed, no thumbnail_dimensions.medium
 * - Downloads medium thumbnail, reads dimensions via getimagesize
 * - Persists dimensions to metadata
 * - Dispatches PopulateAutomaticMetadataJob
 *
 * Throttled to 100 per run. Run repeatedly until no more assets need backfill.
 *
 * Usage:
 *   php artisan assets:backfill-thumbnail-dimensions
 *   php artisan assets:backfill-thumbnail-dimensions --dry-run
 */
class AssetsBackfillThumbnailDimensionsCommand extends Command
{
    protected $signature = 'assets:backfill-thumbnail-dimensions
                            {--dry-run : Show what would be backfilled without making changes}
                            {--limit=100 : Max assets to process per run}';

    protected $description = 'Backfill thumbnail_dimensions for legacy assets (orientation, resolution_class, dominant_colors)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('DRY RUN – No changes will be made');
        }

        $this->info("Finding assets needing thumbnail dimension backfill (limit: {$limit})...");

        $assets = Asset::query()
            ->whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::COMPLETED)
            ->whereNotNull('metadata')
            ->with('storageBucket')
            ->get();

        $candidates = [];
        foreach ($assets as $asset) {
            if (!$asset->hasRasterThumbnail()) {
                continue;
            }
            if ($asset->thumbnailDimensions('medium') !== null) {
                continue;
            }
            $path = $asset->thumbnailPathForStyle('medium');
            if (!$path) {
                continue;
            }
            if (!$asset->storageBucket) {
                continue;
            }
            $candidates[] = $asset;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        if (empty($candidates)) {
            $this->info('No assets need backfill.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($candidates) . ' asset(s) to backfill.');

        $processed = 0;
        $failed = 0;

        foreach ($candidates as $asset) {
            try {
                $dims = $this->readThumbnailDimensions($asset);
                if (!$dims || $dims['width'] < 5 || $dims['height'] < 5) {
                    Log::warning('[assets:backfill-thumbnail-dimensions] Skipping asset - invalid dimensions', [
                        'asset_id' => $asset->id,
                        'dims' => $dims,
                    ]);
                    $failed++;
                    continue;
                }

                if (!$dryRun) {
                    $metadata = $asset->metadata ?? [];
                    $metadata['thumbnail_dimensions'] = array_merge(
                        $metadata['thumbnail_dimensions'] ?? [],
                        [
                            'medium' => [
                                'width' => $dims['width'],
                                'height' => $dims['height'],
                            ],
                        ]
                    );
                    $asset->update(['metadata' => $metadata]);

                    // Set analysis_status so PopulateAutomaticMetadataJob can run (it expects extracting_metadata)
                    $asset->update(['analysis_status' => 'extracting_metadata']);
                    PopulateAutomaticMetadataJob::dispatch($asset->id);
                }

                $processed++;
                $this->line(($dryRun ? '  [DRY RUN] ' : '  ') . "Asset {$asset->id}: {$dims['width']}x{$dims['height']}");
            } catch (\Throwable $e) {
                Log::error('[assets:backfill-thumbnail-dimensions] Failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "[DRY RUN] Would backfill {$processed} asset(s)."
            : "Backfilled {$processed} asset(s). Dispatched PopulateAutomaticMetadataJob for each.");
        if ($failed > 0) {
            $this->warn("Skipped/failed: {$failed}");
        }

        return Command::SUCCESS;
    }

    protected function readThumbnailDimensions(Asset $asset): ?array
    {
        $path = $asset->thumbnailPathForStyle('medium');
        if (!$path) {
            return null;
        }

        $bucket = $asset->storageBucket;
        if (!$bucket) {
            return null;
        }

        $tempPath = $this->downloadFromS3($bucket, $path);
        if (!$tempPath || !file_exists($tempPath)) {
            return null;
        }

        try {
            $info = @getimagesize($tempPath);
            if (!$info || !isset($info[0], $info[1])) {
                return null;
            }
            return [
                'width' => (int) $info[0],
                'height' => (int) $info[1],
            ];
        } finally {
            @unlink($tempPath);
        }
    }

    protected function downloadFromS3($bucket, string $s3Path): ?string
    {
        $region = $bucket->region ?? config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1'));
        $config = [
            'version' => 'latest',
            'region' => $region,
        ];
        if (!empty($bucket->endpoint)) {
            $config['endpoint'] = $bucket->endpoint;
            $config['use_path_style_endpoint'] = $bucket->use_path_style_endpoint ?? true;
        } elseif (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        try {
            $client = new S3Client($config);
            $result = $client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Path,
            ]);
            $body = (string) $result['Body'];
            if (strlen($body) === 0) {
                return null;
            }
            $tempPath = tempnam(sys_get_temp_dir(), 'backfill_dims_');
            file_put_contents($tempPath, $body);
            return $tempPath;
        } catch (S3Exception $e) {
            Log::warning('[assets:backfill-thumbnail-dimensions] S3 download failed', [
                'bucket' => $bucket->name,
                'key' => $s3Path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
