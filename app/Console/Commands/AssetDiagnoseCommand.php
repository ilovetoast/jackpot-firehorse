<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Console\Command;

/**
 * Diagnose asset state for visibility and processing issues.
 *
 * Use to inspect why assets may not appear in the grid or why thumbnails failed.
 * Run: php artisan assets:diagnose 019c7c3d-84d8-7122-82bb-d855db89bd5a [id2] [id3] ...
 */
class AssetDiagnoseCommand extends Command
{
    protected $signature = 'assets:diagnose 
                            {ids* : Asset UUIDs to diagnose (space-separated)}';

    protected $description = 'Diagnose asset state: status, visibility, thumbnails, metadata, and why it may not appear in grid';

    public function handle(): int
    {
        $ids = $this->argument('ids');
        $this->info('Diagnosing ' . count($ids) . ' asset(s)...');
        $this->newLine();

        foreach ($ids as $id) {
            $this->diagnoseOne(trim($id));
            $this->newLine();
        }

        return 0;
    }

    protected function diagnoseOne(string $id): void
    {
        $asset = Asset::withTrashed()->find($id);
        if (!$asset) {
            $version = AssetVersion::where('id', $id)->first();
            if ($version) {
                $asset = $version->asset;
                $this->warn("ID {$id} is an AssetVersion; using parent asset: {$asset->id}");
            } else {
                $this->error("Asset or version not found: {$id}");
                return;
            }
        }

        $this->info("=== Asset: {$asset->id} ===");
        $this->line("  original_filename: " . ($asset->original_filename ?? '(null)'));
        $this->line("  status: " . ($asset->status?->value ?? $asset->status ?? '(null)'));
        $this->line("  thumbnail_status: " . ($asset->thumbnail_status?->value ?? $asset->thumbnail_status ?? '(null)'));
        $this->line("  published_at: " . ($asset->published_at?->toIso8601String() ?? 'NULL'));
        $this->line("  archived_at: " . ($asset->archived_at?->toIso8601String() ?? 'NULL'));
        $this->line("  deleted_at: " . ($asset->deleted_at?->toIso8601String() ?? 'NULL'));
        $this->line("  approval_status: " . ($asset->approval_status?->value ?? $asset->approval_status ?? '(null)'));
        $this->line("  expires_at: " . ($asset->expires_at?->toIso8601String() ?? 'NULL'));

        $meta = $asset->metadata ?? [];
        if (!empty($meta['processing_failed'])) {
            $this->warn("  processing_failed: true");
            $this->line("  failure_reason: " . ($meta['failure_reason'] ?? '(none)'));
            $this->line("  failed_job: " . ($meta['failed_job'] ?? '(none)'));
        }
        if (!empty($meta['thumbnail_skip_reason'])) {
            $this->line("  thumbnail_skip_reason: " . $meta['thumbnail_skip_reason']);
        }
        if (!empty($meta['file_type_unsupported'])) {
            $this->line("  file_type_unsupported: true");
        }

        $thumbnails = $meta['thumbnails'] ?? null;
        $hasThumbs = $thumbnails && !empty($thumbnails);
        $this->line("  has_thumbnails_in_metadata: " . ($hasThumbs ? 'yes' : 'no'));

        $this->newLine();
        $this->line("Visibility in default grid:");
        $reasons = $this->visibilityReasons($asset);
        if (empty($reasons)) {
            $this->info("  -> SHOULD appear in default grid");
        } else {
            $this->warn("  -> HIDDEN from default grid:");
            foreach ($reasons as $r) {
                $this->line("     - {$r}");
            }
        }
    }

    protected function visibilityReasons(Asset $asset): array
    {
        $reasons = [];

        if ($asset->deleted_at !== null) {
            $reasons[] = 'deleted (soft-deleted)';
        }
        if ($asset->archived_at !== null) {
            $reasons[] = 'archived';
        }
        if ($asset->published_at === null) {
            $reasons[] = 'unpublished (published_at is null)';
        }
        if ($asset->expires_at !== null && $asset->expires_at->isPast()) {
            $reasons[] = 'expired';
        }

        // Note: status=FAILED no longer hides assets - LifecycleResolver includes FAILED in default grid

        return $reasons;
    }
}
