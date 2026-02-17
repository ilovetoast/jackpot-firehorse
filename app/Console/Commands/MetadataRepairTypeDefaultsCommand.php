<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Repair Type Defaults Command
 *
 * Resets type fields (photo_type, logo_type, graphic_type, etc.) to their initial default settings.
 * Type fields should only be visible in their configured category slugs; hidden everywhere else.
 * Updates existing rows and inserts missing ones. Logs changes.
 *
 * Usage:
 *   php artisan metadata:repair-type-defaults
 *   php artisan metadata:repair-type-defaults --dry-run
 */
class MetadataRepairTypeDefaultsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metadata:repair-type-defaults
                            {--dry-run : Show what would be repaired without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repair type field visibility: reset to initial defaults (only visible in configured categories)';

    /**
     * Execute the console command.
     */
    public function handle(TenantMetadataVisibilityService $visibilityService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN – No changes will be made');
        }

        $this->info('Repairing type field defaults (photo_type, logo_type, etc.)...');

        $tenants = Tenant::all();
        $totalUpdated = 0;
        $totalInserted = 0;
        $categoriesProcessed = 0;

        foreach ($tenants as $tenant) {
            $categories = Category::query()
                ->active()
                ->where('tenant_id', $tenant->id)
                ->get();

            foreach ($categories as $category) {
                $result = $visibilityService->repairTypeDefaultsForCategory($tenant, $category, $dryRun);
                if ($result['updated'] > 0 || $result['inserted'] > 0) {
                    $prefix = $dryRun ? '  [DRY RUN] ' : '  ';
                    $summary = [];
                    if ($result['updated'] > 0) {
                        $summary[] = ($dryRun ? 'would update ' : 'updated ') . $result['updated'];
                    }
                    if ($result['inserted'] > 0) {
                        $summary[] = ($dryRun ? 'would insert ' : 'inserted ') . $result['inserted'];
                    }
                    $this->line("{$prefix}Tenant {$tenant->name} ({$tenant->id}), Category {$category->name} ({$category->slug}): " . implode(', ', $summary));
                    foreach ($result['changes'] as $change) {
                        $this->line('    ' . ($change['action'] === 'hidden' ? '-' : '+') . " {$change['field_key']} -> {$change['action']}");
                    }
                    $totalUpdated += $result['updated'];
                    $totalInserted += $result['inserted'];
                }
                $categoriesProcessed++;
            }
        }

        $this->newLine();
        $totalChanges = $totalUpdated + $totalInserted;
        if ($totalChanges > 0) {
            $this->info($dryRun
                ? "[DRY RUN] Would change {$totalChanges} type field visibility row(s) ({$totalUpdated} updated, {$totalInserted} inserted) across {$categoriesProcessed} categories."
                : "Changed {$totalChanges} type field visibility row(s) ({$totalUpdated} updated, {$totalInserted} inserted) across {$categoriesProcessed} categories.");
            Log::info('[metadata:repair-type-defaults] Command completed', [
                'updated' => $totalUpdated,
                'inserted' => $totalInserted,
                'categories_processed' => $categoriesProcessed,
                'dry_run' => $dryRun,
            ]);
        } else {
            $this->info('No type field visibility changes needed. All categories match config.');
        }

        return Command::SUCCESS;
    }
}
