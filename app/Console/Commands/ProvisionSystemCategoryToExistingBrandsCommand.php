<?php

namespace App\Console\Commands;

use App\Models\SystemCategory;
use App\Services\SystemCategoryService;
use Illuminate\Console\Command;

/**
 * One-off: add a system template to all brands that are missing that slug (same logic as the queued job).
 */
class ProvisionSystemCategoryToExistingBrandsCommand extends Command
{
    protected $signature = 'system-category:provision-existing {id : system_categories.id}';

    protected $description = 'Create missing brand categories for a system template on all brands (new rows start hidden).';

    public function handle(SystemCategoryService $systemCategoryService): int
    {
        $id = (int) $this->argument('id');
        $template = SystemCategory::find($id);
        if (! $template) {
            $this->error("System category {$id} not found.");

            return self::FAILURE;
        }

        $count = $systemCategoryService->provisionAutoProvisionTemplateToExistingBrands($template);
        $this->info("Created or restored {$count} brand category row(s) (brands that already had this slug were skipped).");

        return self::SUCCESS;
    }
}
