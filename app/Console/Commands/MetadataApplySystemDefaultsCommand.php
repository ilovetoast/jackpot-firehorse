<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Console\Command;

/**
 * Metadata Apply System Defaults Command
 *
 * Applies seeded defaults from config/metadata_category_defaults.php to ALL categories.
 * Use after updating system_automated_enabled_for_all or dominant_colors_visibility
 * to ensure existing categories get the new defaults (orientation, resolution_class,
 * dominant_colors, etc. enabled for every category).
 *
 * Usage:
 *   php artisan metadata:apply-system-defaults
 *   php artisan metadata:apply-system-defaults --dry-run
 */
class MetadataApplySystemDefaultsCommand extends Command
{
    protected $signature = 'metadata:apply-system-defaults
                            {--dry-run : Show what would be applied without making changes}';

    protected $description = 'Apply system metadata defaults to all categories (enables orientation, resolution_class, dominant_colors, etc.)';

    public function handle(TenantMetadataVisibilityService $visibilityService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN – No changes will be made');
        }

        $this->info('Applying system metadata defaults to all categories...');

        $tenants = Tenant::all();
        $totalWritten = 0;
        $categoriesProcessed = 0;

        foreach ($tenants as $tenant) {
            $categories = Category::query()
                ->active()
                ->where('tenant_id', $tenant->id)
                ->get();

            foreach ($categories as $category) {
                if (!$dryRun) {
                    $count = $visibilityService->applySeededDefaultsForCategory($tenant, $category);
                    $totalWritten += $count;
                } else {
                    $totalWritten += 1; // Estimate for dry run
                }
                $categoriesProcessed++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "[DRY RUN] Would apply defaults to {$categoriesProcessed} categories."
            : "Applied system defaults to {$categoriesProcessed} categories.");

        return Command::SUCCESS;
    }
}
