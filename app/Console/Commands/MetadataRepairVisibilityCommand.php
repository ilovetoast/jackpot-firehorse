<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Repair Visibility Command
 *
 * Rebuilds visibility from config (metadata_category_defaults.php) for missing rows only.
 * Does NOT override existing values. Safe to run repeatedly.
 *
 * Usage:
 *   php artisan metadata:repair-visibility
 *   php artisan metadata:repair-visibility --dry-run
 */
class MetadataRepairVisibilityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metadata:repair-visibility
                            {--dry-run : Show what would be repaired without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repair metadata field visibility: insert missing rows from config (does not override existing)';

    /**
     * Execute the console command.
     */
    public function handle(TenantMetadataVisibilityService $visibilityService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN – No changes will be made');
        }

        $this->info('Repairing metadata field visibility (missing rows only)...');

        $tenants = Tenant::all();
        $totalInserted = 0;
        $categoriesProcessed = 0;

        foreach ($tenants as $tenant) {
            $categories = Category::query()
                ->active()
                ->where('tenant_id', $tenant->id)
                ->get();

            foreach ($categories as $category) {
                $result = $visibilityService->repairVisibilityForCategory($tenant, $category, $dryRun);
                if ($result['inserted'] > 0) {
                    $prefix = $dryRun ? '  [DRY RUN] ' : '  ';
                    $this->line("{$prefix}Tenant {$tenant->name} ({$tenant->id}), Category {$category->name} ({$category->slug}): " .
                        ($dryRun ? "would insert " : "inserted ") . "{$result['inserted']} row(s)");
                    foreach ($result['changes'] as $change) {
                        $this->line('    ' . ($dryRun ? '-' : '+') . " {$change['field_key']}");
                    }
                    $totalInserted += $result['inserted'];
                }
                $categoriesProcessed++;
            }
        }

        $this->newLine();
        if ($totalInserted > 0) {
            $this->info($dryRun
                ? "[DRY RUN] Would insert {$totalInserted} missing visibility row(s) across {$categoriesProcessed} categories."
                : "Inserted {$totalInserted} missing visibility row(s) across {$categoriesProcessed} categories.");
            Log::info('[metadata:repair-visibility] Command completed', [
                'inserted' => $totalInserted,
                'categories_processed' => $categoriesProcessed,
                'dry_run' => $dryRun,
            ]);
        } else {
            $this->info('No missing visibility rows found. All categories are up to date.');
        }

        return Command::SUCCESS;
    }
}
