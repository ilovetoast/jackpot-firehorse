<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\TenantBucketService;
use App\Support\PipelineQueueResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Heal asset rows that never had `storage_bucket_id` populated.
 *
 * Historical bug: several server-side ingestion paths (OnboardingController
 * and BrandWebsiteCrawlerService, primarily) created assets without setting
 * `storage_bucket_id`. That one missing column makes the pipeline terminally
 * skip thumbnails and then fail PromoteAssetJob with "Missing storage bucket".
 *
 * This command is idempotent and safe to run repeatedly:
 *   1. Resolves the tenant's active bucket.
 *   2. Backfills `assets.storage_bucket_id`.
 *   3. Resets the terminal flags set by the failed run
 *      (thumbnail_status=SKIPPED, analysis_status=promotion_failed, metadata flags).
 *   4. Re-dispatches ProcessAssetJob so the normal pipeline can finish.
 *
 * Usage:
 *   php artisan assets:repair-missing-bucket <asset-uuid>
 *   php artisan assets:repair-missing-bucket --tenant=4
 *   php artisan assets:repair-missing-bucket --all
 *   php artisan assets:repair-missing-bucket --all --dry-run
 */
class AssetsRepairMissingBucketCommand extends Command
{
    protected $signature = 'assets:repair-missing-bucket
                            {asset? : Specific asset UUID to heal}
                            {--tenant= : Limit to a single tenant id}
                            {--all : Heal every affected asset across every tenant}
                            {--dry-run : Print what would happen without changing anything}';

    protected $description = 'Backfill storage_bucket_id on assets that were created without one and re-dispatch their pipeline.';

    public function handle(TenantBucketService $bucketService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $assetArg = $this->argument('asset');
        $tenantOpt = $this->option('tenant');
        $all = (bool) $this->option('all');

        if (! $assetArg && ! $tenantOpt && ! $all) {
            $this->error('Provide an asset UUID, --tenant=<id>, or --all.');

            return self::INVALID;
        }

        $query = Asset::query()->whereNull('storage_bucket_id')->whereNull('deleted_at');

        if ($assetArg) {
            $query->where('id', $assetArg);
        } elseif ($tenantOpt) {
            $query->where('tenant_id', (int) $tenantOpt);
        }

        $assets = $query->get();

        if ($assets->isEmpty()) {
            $this->info('No assets need repair.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d asset(s) with missing storage_bucket_id.', $assets->count()));
        if ($dryRun) {
            $this->warn('--dry-run: no changes will be persisted.');
        }

        $bucketCache = [];
        $healed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($assets as $asset) {
            $this->line('');
            $this->line("• {$asset->id}  tenant={$asset->tenant_id}  file={$asset->original_filename}  source={$asset->source}");

            $tenant = $asset->tenant_id ? Tenant::find($asset->tenant_id) : null;
            if (! $tenant) {
                $this->error('  ✗ tenant row missing — skipping');
                $skipped++;
                continue;
            }

            try {
                $bucket = $bucketCache[$tenant->id]
                    ??= $bucketService->resolveActiveBucketOrFail($tenant);
            } catch (\Throwable $e) {
                $this->error('  ✗ could not resolve active bucket: ' . $e->getMessage());
                $errors++;
                continue;
            }

            $this->line("  → bucket={$bucket->name} (id={$bucket->id})");

            if ($dryRun) {
                $healed++;
                continue;
            }

            $this->healAsset($asset, $bucket->id);
            $healed++;
            $this->info('  ✓ repaired and re-dispatched');
        }

        $this->line('');
        $this->info(sprintf('Summary: healed=%d  skipped=%d  errors=%d', $healed, $skipped, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function healAsset(Asset $asset, string $bucketId): void
    {
        $meta = $asset->metadata ?? [];
        foreach ([
            'promotion_failed', 'promotion_failed_at', 'promotion_error',
            'thumbnail_skip_reason', 'thumbnail_skip_message',
            'preview_unavailable_user_message', 'missing_storage_detected_at',
            'thumbnails_generated',
        ] as $stale) {
            unset($meta[$stale]);
        }

        $asset->update([
            'storage_bucket_id' => $bucketId,
            'analysis_status' => 'uploading',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
            'thumbnail_retry_count' => 0,
            'metadata' => $meta,
        ]);

        if ($version = $asset->currentVersion) {
            $version->update(['pipeline_status' => 'processing']);
        }

        Log::info('[assets:repair-missing-bucket] Asset repaired and redispatched', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'bucket_id' => $bucketId,
        ]);

        ProcessAssetJob::dispatch($asset->id)
            ->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
    }
}
