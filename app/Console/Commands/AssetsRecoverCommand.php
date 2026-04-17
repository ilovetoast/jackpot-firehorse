<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Services\Assets\AssetStateReconciliationService;
use App\Services\ThumbnailRetryService;
use App\Services\ThumbnailTimeoutGuard;
use App\Support\PipelineQueueResolver;
use Illuminate\Console\Command;

/**
 * Diagnose and force-recover a stuck asset.
 *
 * Usage:
 *   php artisan assets:recover <asset-id>          — diagnose + retry one asset
 *   php artisan assets:recover --dry-run <id>      — diagnose only
 *   php artisan assets:recover --sweep             — scan all assets stuck in uploading/PROCESSING/FAILED-with-retries-left
 *   php artisan assets:recover --sweep --dry-run
 *
 * This is the one-stop escape hatch when an asset refuses to exit processing
 * (e.g. SVG logos stuck after upload, thumbnails failed beyond the incident loop).
 * It:
 *   1) Runs AssetStateReconciliationService (fixes pipeline/status drift)
 *   2) Timeouts PROCESSING thumbnails (so retries become legal)
 *   3) Dispatches GenerateThumbnailsJob (or ProcessAssetJob for truly bare assets)
 */
class AssetsRecoverCommand extends Command
{
    protected $signature = 'assets:recover
                            {asset? : Asset UUID to recover (omit with --sweep)}
                            {--sweep : Scan and recover every stuck asset in the tenant/brand scope}
                            {--dry-run : Print diagnosis without dispatching any jobs}
                            {--force : Ignore thumbnail retry limit (resets thumbnail_retry_count to 0)}';

    protected $description = 'Diagnose and force-recover a stuck asset (or sweep all stuck assets).';

    public function handle(
        AssetStateReconciliationService $reconciliation,
        ThumbnailTimeoutGuard $timeoutGuard,
        ThumbnailRetryService $retryService,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($this->option('sweep')) {
            return $this->sweep($reconciliation, $timeoutGuard, $retryService, $dryRun, $force);
        }

        $assetId = $this->argument('asset');
        if (! $assetId) {
            $this->error('Provide an asset UUID or use --sweep.');
            return self::INVALID;
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
            $this->error("Asset not found: {$assetId}");
            return self::FAILURE;
        }

        $this->diagnose($asset);

        if ($dryRun) {
            $this->warn('DRY RUN — nothing dispatched.');
            return self::SUCCESS;
        }

        $this->recoverOne($asset, $reconciliation, $timeoutGuard, $retryService, $force);
        return self::SUCCESS;
    }

    protected function sweep(
        AssetStateReconciliationService $reconciliation,
        ThumbnailTimeoutGuard $timeoutGuard,
        ThumbnailRetryService $retryService,
        bool $dryRun,
        bool $force
    ): int {
        $maxRetries = (int) config('assets.thumbnail.max_retries', 3);

        $stuckAnalysis = Asset::whereNull('deleted_at')
            ->whereIn('analysis_status', ['uploading', 'generating_thumbnails'])
            ->where('updated_at', '<', now()->subMinutes(10))
            ->get();

        $failedThumbnails = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::FAILED)
            ->where('thumbnail_retry_count', '<', $force ? PHP_INT_MAX : $maxRetries)
            ->get();

        $stuckProcessing = Asset::whereNull('deleted_at')
            ->where('thumbnail_status', ThumbnailStatus::PROCESSING)
            ->where(function ($q) {
                $q->where('thumbnail_started_at', '<', now()->subMinutes(20))
                    ->orWhere(function ($q2) {
                        $q2->whereNull('thumbnail_started_at')
                            ->where('updated_at', '<', now()->subMinutes(20));
                    });
            })
            ->get();

        $all = $stuckAnalysis->concat($failedThumbnails)->concat($stuckProcessing)->unique('id');

        $this->info("Found {$all->count()} stuck asset(s): "
            . "analysis_status={$stuckAnalysis->count()}, "
            . "thumbnail=failed+retries-left={$failedThumbnails->count()}, "
            . "thumbnail=processing-stale={$stuckProcessing->count()}");

        foreach ($all as $asset) {
            $this->line('');
            $this->diagnose($asset);
            if (! $dryRun) {
                $this->recoverOne($asset, $reconciliation, $timeoutGuard, $retryService, $force);
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nothing dispatched.');
        }
        return self::SUCCESS;
    }

    protected function diagnose(Asset $asset): void
    {
        $meta = $asset->metadata ?? [];
        $thumb = $asset->thumbnail_status?->value ?? 'null';
        $this->line("Asset: {$asset->id}  title=\"{$asset->title}\"");
        $this->line("  mime={$asset->mime_type}  ext=" . strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION)));
        $this->line("  analysis_status={$asset->analysis_status}  thumbnail_status={$thumb}  retry_count={$asset->thumbnail_retry_count}");
        $this->line("  thumbnail_error=" . ($asset->thumbnail_error ?? '—'));
        $this->line("  started_at=" . ($asset->thumbnail_started_at?->toIso8601String() ?? '—') . "  updated_at=" . $asset->updated_at?->toIso8601String());
        $this->line("  metadata.pipeline_completed_at=" . ($meta['pipeline_completed_at'] ?? '—'));
        $this->line("  metadata.thumbnail_timeout=" . var_export($meta['thumbnail_timeout'] ?? false, true));
        $this->line("  metadata.thumbnail_skip_reason=" . ($meta['thumbnail_skip_reason'] ?? '—'));
        $this->line("  category_id=" . ($meta['category_id'] ?? '—') . "  type={$asset->type}  intake_state={$asset->intake_state}");
    }

    protected function recoverOne(
        Asset $asset,
        AssetStateReconciliationService $reconciliation,
        ThumbnailTimeoutGuard $timeoutGuard,
        ThumbnailRetryService $retryService,
        bool $force
    ): void {
        $reconciliation->reconcile($asset->fresh());
        $asset->refresh();

        if ($asset->analysis_status === 'complete' && $asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
            $this->info("  -> Reconciliation advanced asset to complete/completed. No dispatch needed.");
            return;
        }

        if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
            $timeoutGuard->checkAndRepair($asset);
            $asset->refresh();
        }

        if ($force) {
            $asset->update([
                'thumbnail_retry_count' => 0,
                'thumbnail_error' => null,
            ]);
            $asset->refresh();
        }

        if ($asset->thumbnail_status === ThumbnailStatus::FAILED
            || $asset->thumbnail_status === ThumbnailStatus::PENDING
            || $asset->analysis_status === 'generating_thumbnails'
        ) {
            $result = $retryService->dispatchRetry($asset, 0);
            if ($result['success'] ?? false) {
                $this->info('  -> GenerateThumbnailsJob dispatched (job_id=' . ($result['job_id'] ?? 'n/a') . ').');
            } else {
                $this->warn('  -> ThumbnailRetryService refused: ' . ($result['error'] ?? 'unknown'));
                // Fall through to ProcessAssetJob as last resort.
                ProcessAssetJob::dispatch($asset->id)->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
                $this->info('  -> ProcessAssetJob dispatched as fallback.');
            }
            return;
        }

        if (in_array($asset->analysis_status, ['uploading', 'promotion_failed'], true)) {
            ProcessAssetJob::dispatch($asset->id)->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
            $this->info('  -> ProcessAssetJob dispatched.');
            return;
        }

        $this->info('  -> Nothing to do (state is terminal or healthy).');
    }
}
