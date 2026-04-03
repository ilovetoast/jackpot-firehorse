<?php

namespace App\Jobs;

use App\Models\Category;
use App\Services\SystemMetadataVisibilityService;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * For a system metadata field in template bundles: insert category-scoped visibility rows so existing
 * brand categories stay stable until tenants opt in ({@see provision_source} = system_seed).
 */
class BackfillHybridVisibilityForMetadataFieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $metadataFieldId
    ) {}

    public function handle(
        TenantMetadataVisibilityService $tenantVisibility,
        SystemMetadataVisibilityService $systemVisibility
    ): void {
        $field = DB::table('metadata_fields')
            ->where('id', $this->metadataFieldId)
            ->where('scope', 'system')
            ->whereNull('archived_at')
            ->first();

        if (! $field) {
            return;
        }

        $categories = Category::query()
            ->active()
            ->where('is_system', true)
            ->whereNotNull('brand_id')
            ->get();

        $inserted = 0;

        foreach ($categories as $category) {
            $tenantId = (int) $category->tenant_id;
            $brandId = (int) $category->brand_id;
            $categoryId = (int) $category->id;

            $latestTemplateId = $tenantVisibility->resolveLatestSystemCategoryTemplateId($category);
            if ($latestTemplateId === null) {
                continue;
            }

            $inBundle = DB::table('system_category_field_defaults')
                ->where('system_category_id', $latestTemplateId)
                ->where('metadata_field_id', $this->metadataFieldId)
                ->exists();

            if (! $inBundle) {
                continue;
            }

            if ($systemVisibility->getSuppressedFieldIdsForSystemCategoryFamily($latestTemplateId, [$this->metadataFieldId]) !== []) {
                continue;
            }

            $exists = DB::table('metadata_field_visibility')
                ->where('tenant_id', $tenantId)
                ->where('brand_id', $brandId)
                ->where('category_id', $categoryId)
                ->where('metadata_field_id', $this->metadataFieldId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('metadata_field_visibility')->insert([
                'metadata_field_id' => $this->metadataFieldId,
                'tenant_id' => $tenantId,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'is_hidden' => true,
                'is_upload_hidden' => true,
                'is_filter_hidden' => true,
                'is_edit_hidden' => true,
                'is_primary' => null,
                'is_required' => null,
                'provision_source' => 'system_seed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        Log::info('[BackfillHybridVisibilityForMetadataFieldJob] Seeded system_field visibility rows', [
            'metadata_field_id' => $this->metadataFieldId,
            'rows_inserted' => $inserted,
        ]);
    }
}
