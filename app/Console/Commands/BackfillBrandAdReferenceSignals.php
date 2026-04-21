<?php

namespace App\Console\Commands;

use App\Models\BrandAdReference;
use App\Services\BrandIntelligence\BrandAdReferenceSignalExtractor;
use Illuminate\Console\Command;

/**
 * Backfill visual signals for existing brand_ad_references rows.
 *
 * Usage:
 *   php artisan brand-ad-references:extract-signals                  # only rows with no signals yet
 *   php artisan brand-ad-references:extract-signals --brand=123      # scope to one brand
 *   php artisan brand-ad-references:extract-signals --force          # re-extract everything (bump after algorithm changes)
 *
 * Processes rows sequentially — Imagick peaks at a few hundred MB for
 * large JPEGs, so running this in parallel on a constrained worker just
 * wastes memory.
 */
class BackfillBrandAdReferenceSignals extends Command
{
    protected $signature = 'brand-ad-references:extract-signals
        {--brand= : Restrict to a single brand_id}
        {--force : Re-extract even if signals already exist}
        {--limit=0 : Max rows to process (0 = no limit)}';

    protected $description = 'Extract visual signals (palette, brightness, saturation) for ad references';

    public function handle(BrandAdReferenceSignalExtractor $extractor): int
    {
        $query = BrandAdReference::query()->orderBy('id');

        if ($brandId = $this->option('brand')) {
            $query->where('brand_id', $brandId);
        }
        if (! $this->option('force')) {
            $query->whereNull('signals');
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Nothing to do — no references match the filter.');
            return self::SUCCESS;
        }

        $this->info("Processing {$total} reference(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $failed = 0;
        $query->chunkById(50, function ($rows) use ($extractor, $bar, &$ok, &$failed) {
            foreach ($rows as $ref) {
                try {
                    $res = $extractor->extractForReference($ref);
                    if ($res !== null) {
                        $ok++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn(" Reference #{$ref->id}: {$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done — {$ok} extracted, {$failed} failed.");

        return self::SUCCESS;
    }
}
