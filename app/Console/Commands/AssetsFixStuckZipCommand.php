<?php

namespace App\Console\Commands;

use App\Enums\ThumbnailStatus;
use App\Jobs\FinalizeAssetJob;
use App\Models\Asset;
use Illuminate\Console\Command;

/**
 * Fix stuck ZIP/archive assets that are stuck in processing.
 *
 * ZIP files cannot generate thumbnails or image-derived metadata. If the pipeline
 * failed or retried indefinitely, these assets show as "processing" forever.
 * This command applies the same short-circuit logic as ProcessAssetJob and completes them.
 *
 * Run: php artisan assets:fix-stuck-zip [--dry-run] [--tenant=1] [--limit=100]
 */
class AssetsFixStuckZipCommand extends Command
{
    protected $signature = 'assets:fix-stuck-zip
                            {--dry-run : List stuck assets without fixing}
                            {--tenant= : Limit to tenant ID}
                            {--limit=50 : Maximum number of assets to fix}';

    protected $description = 'Fix ZIP/archive assets stuck in processing (complete them immediately)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $limit = (int) $this->option('limit');

        $zipMimes = ['application/zip', 'application/x-zip-compressed'];

        $query = Asset::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($zipMimes) {
                $q->whereIn('mime_type', $zipMimes)
                    ->orWhere('original_filename', 'LIKE', '%.zip');
            })
            ->where(function ($q) {
                $q->where('analysis_status', '!=', 'complete')
                    ->orWhereNull('analysis_status');
            });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $assets = $query->limit($limit)->get();

        if ($assets->isEmpty()) {
            $this->info('No stuck ZIP/archive assets found.');
            return 0;
        }

        $this->warn('Found ' . $assets->count() . ' stuck ZIP/archive asset(s):');

        $rows = [];
        foreach ($assets as $a) {
            $version = $a->currentVersion;
            $rows[] = [
                $a->id,
                $a->original_filename,
                $a->mime_type,
                $a->analysis_status ?? 'null',
                $a->thumbnail_status?->value ?? 'null',
                $version?->pipeline_status ?? 'n/a',
                $a->brand_id,
            ];
        }
        $this->table(
            ['ID', 'Filename', 'MIME', 'analysis_status', 'thumbnail_status', 'version.pipeline', 'brand_id'],
            $rows
        );

        if ($dryRun) {
            $this->info('Dry run — no changes made. Run without --dry-run to fix.');
            return 0;
        }

        if (!$this->confirm('Apply short-circuit and complete these assets?', true)) {
            return 0;
        }

        $fixed = 0;
        foreach ($assets as $asset) {
            $this->shortCircuit($asset);
            $fixed++;
            $this->line("  Fixed: {$asset->id} | {$asset->original_filename}");
        }

        $this->info("Fixed {$fixed} asset(s).");
        return 0;
    }

    protected function shortCircuit(Asset $asset): void
    {
        $version = $asset->currentVersion;
        $skipReason = 'unsupported_format:zip';
        $skipMessage = 'Thumbnail generation is not supported for this file type.';

        $assetMetadata = $asset->metadata ?? [];
        $assetMetadata['thumbnail_skip_reason'] = $skipReason;
        $assetMetadata['thumbnail_skip_message'] = $skipMessage;
        $assetMetadata['thumbnails_generated'] = false;
        $assetMetadata['metadata_extracted'] = $assetMetadata['metadata_extracted'] ?? true;
        $assetMetadata['preview_generated'] = false;
        $assetMetadata['preview_skipped'] = true;
        $assetMetadata['preview_skipped_reason'] = 'unsupported_file_type';
        $assetMetadata['ai_tagging_completed'] = true;

        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => $skipMessage,
            'thumbnail_started_at' => null,
            'metadata' => $assetMetadata,
        ]);

        if ($version) {
            $versionMetadata = $version->metadata ?? [];
            $versionMetadata['thumbnail_skip_reason'] = $skipReason;
            $versionMetadata['thumbnail_skip_message'] = $skipMessage;
            $versionMetadata['thumbnails_generated'] = false;
            $version->update([
                'metadata' => $versionMetadata,
                'pipeline_status' => 'complete',
            ]);
        }

        FinalizeAssetJob::dispatch($asset->id);
    }
}
