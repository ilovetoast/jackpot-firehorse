<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Category;
use Illuminate\Console\Command;

/**
 * Recover assets that have null category_id in metadata.
 *
 * FinalizeAssetJob (before fix) replaced asset metadata with version metadata,
 * which wiped category_id and caused assets to disappear from the grid.
 * This command restores category_id for affected assets.
 *
 * Run: php artisan assets:recover-category-id [--category=5] [--tenant=1] [--brand=1] [--dry-run]
 */
class AssetRecoverCategoryIdCommand extends Command
{
    protected $signature = 'assets:recover-category-id
                            {--category= : Category ID to assign (required for recovery)}
                            {--tenant= : Limit to tenant ID}
                            {--brand= : Limit to brand ID}
                            {--dry-run : Show affected assets without making changes}
                            {--limit=1000 : Maximum number of assets to process}';

    protected $description = 'Recover assets with null category_id in metadata (fixes FinalizeAssetJob metadata wipe)';

    public function handle(): int
    {
        $categoryId = $this->option('category');
        $tenantId = $this->option('tenant');
        $brandId = $this->option('brand');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Asset::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereRaw('(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "" OR JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) = "null")');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        $assets = $query->limit($limit)->get();

        if ($assets->isEmpty()) {
            $this->info('No assets with null category_id found.');
            return 0;
        }

        $this->warn('Found ' . $assets->count() . ' assets with null/missing category_id in metadata.');

        if (!$categoryId) {
            $this->error('Provide --category=<id> to assign a category. Example: --category=5');
            $this->line('');
            $this->line('Affected assets (first 10):');
            foreach ($assets->take(10) as $a) {
                $this->line("  - {$a->id} | {$a->original_filename} | brand_id={$a->brand_id}");
            }
            if ($assets->count() > 10) {
                $this->line('  ... and ' . ($assets->count() - 10) . ' more');
            }
            return 1;
        }

        $category = Category::find($categoryId);
        if (!$category) {
            $this->error("Category {$categoryId} not found.");
            return 1;
        }

        // Ensure category belongs to same brand as assets (when brand filter is used)
        if ($brandId && (int) $category->brand_id !== (int) $brandId) {
            $this->error("Category {$categoryId} belongs to brand {$category->brand_id}, but --brand={$brandId} was specified.");
            return 1;
        }

        // Only update assets in same brand as category (prevent cross-brand assignment)
        $assets = $assets->filter(fn ($a) => (int) $a->brand_id === (int) $category->brand_id);
        if ($assets->isEmpty()) {
            $this->warn('No affected assets belong to brand ' . $category->brand_id . ' (category "' . $category->name . '").');
            return 0;
        }

        if ($dryRun) {
            $this->info('[DRY RUN] Would assign category "' . $category->name . '" (id=' . $categoryId . ') to ' . $assets->count() . ' assets.');
            foreach ($assets->take(5) as $a) {
                $this->line("  - {$a->id} | {$a->original_filename}");
            }
            if ($assets->count() > 5) {
                $this->line('  ... and ' . ($assets->count() - 5) . ' more');
            }
            return 0;
        }

        $updated = 0;
        foreach ($assets as $asset) {
            $meta = $asset->metadata ?? [];
            $meta['category_id'] = (int) $categoryId;
            $asset->update(['metadata' => $meta]);
            $updated++;
        }

        $this->info("Updated {$updated} assets with category_id={$categoryId} ({$category->name}).");
        return 0;
    }
}
