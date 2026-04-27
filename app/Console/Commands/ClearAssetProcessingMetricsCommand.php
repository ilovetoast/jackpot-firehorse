<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;

/**
 * Null out ops-only duration columns on assets (saves row / index space; metrics are best-effort).
 */
class ClearAssetProcessingMetricsCommand extends Command
{
    protected $signature = 'assets:clear-processing-metrics
                            {--older-than-days=90 : Only clear assets created before this many days ago}
                            {--chunk=500 : Rows per update batch}';

    protected $description = 'Clear processing_duration_ms and thumbnail_ready_duration_ms on old assets';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('older-than-days'));
        $chunk = max(50, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);

        $q = Asset::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($w) {
                $w->whereNotNull('processing_duration_ms')
                    ->orWhereNotNull('thumbnail_ready_duration_ms');
            });

        $total = (clone $q)->count();
        if ($total === 0) {
            $this->info('No rows to update.');

            return self::SUCCESS;
        }

        $this->info("Clearing metrics on up to {$total} assets (created before {$cutoff->toIso8601String()})…");

        $cleared = 0;
        $q->orderBy('id')->chunkById($chunk, function ($assets) use (&$cleared) {
            $ids = $assets->pluck('id')->all();
            $n = Asset::query()->whereIn('id', $ids)->update([
                'processing_duration_ms' => null,
                'thumbnail_ready_duration_ms' => null,
            ]);
            $cleared += $n;
            $this->line("  … {$cleared} rows cleared");
        });

        $this->info("Done. Cleared metrics on {$cleared} rows.");

        return self::SUCCESS;
    }
}
