<?php

namespace App\Jobs;

use App\Models\SystemCategory;
use App\Services\SystemCategoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Copies an auto-provision system template to every brand that does not yet have that slug.
 * New brand rows are created with is_hidden=true so tenants see them under "Hidden" until they choose to show them.
 */
class ProvisionSystemCategoryToExistingBrandsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $systemCategoryId
    ) {}

    public function handle(SystemCategoryService $systemCategoryService): void
    {
        $template = SystemCategory::find($this->systemCategoryId);
        if (! $template || ! $template->auto_provision) {
            return;
        }

        $newRows = $systemCategoryService->provisionAutoProvisionTemplateToExistingBrands($template);

        Log::info('ProvisionSystemCategoryToExistingBrandsJob finished', [
            'system_category_id' => $this->systemCategoryId,
            'slug' => $template->slug,
            'new_brand_category_rows' => $newRows,
        ]);
    }
}
