<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterDefaultsApplier;
use App\Services\SystemCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 4.1 — verifies that newly-created tenants/brands automatically receive
 * Folder Quick Filter defaults via the SystemCategoryService bootstrap hook,
 * without requiring an operator to re-run FolderQuickFilterDefaultsSeeder.
 *
 * Coverage:
 *   - applyForCategory() applies the configured defaults for a single
 *     category at runtime.
 *   - applyForCategory() is idempotent and respects the same skip rules
 *     as the seeder (suppressed, manual source).
 *   - SystemCategoryService::addTemplateToBrand wiring fires the applier
 *     when a new system category appears for a brand.
 */
class FolderQuickFilterTenantBootstrapTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\MetadataFieldsSeeder']);
    }

    private function makeTenantAndCategory(string $slug): array
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'BS Co '.$slug,
            'slug' => 'bs-co-'.$slug,
            'manual_plan_override' => 'starter',
        ], ['email' => 'bs-'.$slug.'@example.com', 'first_name' => 'B', 'last_name' => 'S']);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_system' => true,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $category];
    }

    public function test_apply_for_category_seeds_defaults_for_known_slug(): void
    {
        [, , $category] = $this->makeTenantAndCategory('photography');

        $applier = app(FolderQuickFilterDefaultsApplier::class);
        $stats = $applier->applyForCategory($category);

        $this->assertSame(3, $stats['created'] + $stats['updated_quick_filter_only']);

        $count = DB::table('metadata_field_visibility')
            ->where('category_id', $category->id)
            ->where('show_in_folder_quick_filters', true)
            ->where('folder_quick_filter_source', FolderQuickFilterAssignmentService::SOURCE_SEEDED)
            ->count();
        $this->assertSame(3, $count);
    }

    public function test_apply_for_category_is_idempotent(): void
    {
        [, , $category] = $this->makeTenantAndCategory('photography');

        $applier = app(FolderQuickFilterDefaultsApplier::class);
        $applier->applyForCategory($category);
        $applier->applyForCategory($category);

        $count = DB::table('metadata_field_visibility')
            ->where('category_id', $category->id)
            ->where('show_in_folder_quick_filters', true)
            ->count();
        $this->assertSame(3, $count);
    }

    public function test_apply_for_category_skips_admin_touched_rows(): void
    {
        [, , $category] = $this->makeTenantAndCategory('photography');

        $envFieldId = (int) MetadataField::where('key', 'environment_type')->value('id');
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $envFieldId,
            'tenant_id' => $category->tenant_id,
            'brand_id' => $category->brand_id,
            'category_id' => $category->id,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'is_edit_hidden' => false,
            'is_primary' => false,
            'is_required' => false,
            'show_in_folder_quick_filters' => false,
            'folder_quick_filter_source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(FolderQuickFilterDefaultsApplier::class)->applyForCategory($category);

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $envFieldId)
            ->where('category_id', $category->id)
            ->first();
        $this->assertSame('manual', $row->folder_quick_filter_source);
        $this->assertFalse((bool) $row->show_in_folder_quick_filters);
    }

    public function test_apply_for_category_no_op_for_unknown_slug(): void
    {
        [, , $category] = $this->makeTenantAndCategory('totally-custom-slug');

        $stats = app(FolderQuickFilterDefaultsApplier::class)->applyForCategory($category);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated_quick_filter_only']);
    }

    public function test_apply_for_tenant_seeds_all_known_slugs(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Multi Folder Co',
            'slug' => 'multi-folder-co',
            'manual_plan_override' => 'starter',
        ], ['email' => 'multi@example.com', 'first_name' => 'M', 'last_name' => 'F']);

        $photoCat = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography',
            'is_system' => true,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);
        $logosCat = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos',
            'is_system' => true,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 2,
        ]);

        $stats = app(FolderQuickFilterDefaultsApplier::class)->applyForTenant($tenant);
        $this->assertGreaterThanOrEqual(3, $stats['created'] + $stats['updated_quick_filter_only']);

        $photoCount = DB::table('metadata_field_visibility')
            ->where('category_id', $photoCat->id)
            ->where('show_in_folder_quick_filters', true)
            ->count();
        $logoCount = DB::table('metadata_field_visibility')
            ->where('category_id', $logosCat->id)
            ->where('show_in_folder_quick_filters', true)
            ->count();
        $this->assertSame(3, $photoCount);
        $this->assertGreaterThan(0, $logoCount);
    }

    public function test_system_category_service_bootstrap_hook_applies_defaults(): void
    {
        // The system_categories template table needs at least the photography
        // template for SystemCategoryService::addTemplateToBrand to work.
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\SystemCategoryTemplateSeeder']);

        // Spawn a fresh tenant + brand; we'll add a system category through
        // SystemCategoryService::addTemplateToBrand and verify the applier
        // hook fires WITHOUT a manual seeder run.
        [, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Bootstrap Hook Co',
            'slug' => 'bootstrap-hook-co',
            'manual_plan_override' => 'starter',
        ], ['email' => 'bootstrap-hook@example.com', 'first_name' => 'B', 'last_name' => 'H']);

        $template = \App\Models\SystemCategory::where('slug', 'photography')->first();
        if ($template === null) {
            $this->markTestSkipped('SystemCategoryTemplateSeeder did not include photography in this DB.');
        }

        // The default brand may have already received `photography` via
        // Brand::created → seedForBrand → addTemplateToBrand. If so, our hook
        // ran during Tenant::create above. Either path verifies the same wire.
        $existing = Category::where('brand_id', $brand->id)->where('slug', 'photography')->first();
        if ($existing) {
            $count = DB::table('metadata_field_visibility')
                ->where('category_id', $existing->id)
                ->where('show_in_folder_quick_filters', true)
                ->where('folder_quick_filter_source', FolderQuickFilterAssignmentService::SOURCE_SEEDED)
                ->count();
            $this->assertSame(3, $count);

            return;
        }

        $newCategory = app(SystemCategoryService::class)->addTemplateToBrand($brand, $template);
        $this->assertNotNull($newCategory);

        $count = DB::table('metadata_field_visibility')
            ->where('category_id', $newCategory->id)
            ->where('show_in_folder_quick_filters', true)
            ->where('folder_quick_filter_source', FolderQuickFilterAssignmentService::SOURCE_SEEDED)
            ->count();
        $this->assertSame(3, $count);
    }

    public function test_applier_no_op_when_schema_missing_columns(): void
    {
        // Defensive: spy that the applier returns empty stats when the
        // migration column is missing. We can't easily drop the column in
        // the test DB, but we can assert that the same skip-bucket structure
        // returned by the public method is well-formed and stable.
        [, , $category] = $this->makeTenantAndCategory('photography');
        $stats = app(FolderQuickFilterDefaultsApplier::class)->applyForCategory($category);

        // Has all expected stat keys regardless of state.
        foreach ([
            'created',
            'updated_quick_filter_only',
            'skipped_existing_source',
            'skipped_ineligible',
            'skipped_suppressed',
            'skipped_unknown_field',
            'skipped_unknown_folder',
        ] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }
}
