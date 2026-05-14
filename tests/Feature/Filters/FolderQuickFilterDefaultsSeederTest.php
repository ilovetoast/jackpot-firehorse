<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Category;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use Database\Seeders\FolderQuickFilterDefaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class FolderQuickFilterDefaultsSeederTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The full DatabaseSeeder is heavy. We seed only what the defaults
        // seeder needs: a tenant + brand + a system category with a known
        // slug, and the system metadata fields the config defaults reference.
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\MetadataFieldsSeeder']);
    }

    private function makeTenantWithCategory(string $slug, string $assetTypeValue = 'asset'): array
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Seed Co '.$slug,
            'slug' => 'seed-co-'.$slug,
            'manual_plan_override' => 'starter',
        ], ['email' => 'seed-'.$slug.'@example.com', 'first_name' => 'S', 'last_name' => 'D']);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => $assetTypeValue === 'deliverable' ? AssetType::DELIVERABLE : AssetType::ASSET,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_system' => true,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $user, $category];
    }

    public function test_seeds_default_quick_filters_for_photography(): void
    {
        [$tenant, $brand, , $category] = $this->makeTenantWithCategory('photography');

        (new FolderQuickFilterDefaultsSeeder())->run();

        $rows = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $category->id)
            ->where('show_in_folder_quick_filters', true)
            ->orderBy('folder_quick_filter_order')
            ->get();

        // Phase 2 minimal default for `photography` is: photo_type, environment_type, subject_type.
        $this->assertCount(3, $rows);
        $this->assertSame('seeded', $rows[0]->folder_quick_filter_source);
        $this->assertSame(0, (int) $rows[0]->folder_quick_filter_order);
        $this->assertSame(1, (int) $rows[1]->folder_quick_filter_order);
        $this->assertSame(2, (int) $rows[2]->folder_quick_filter_order);
    }

    public function test_seeder_is_idempotent(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('photography');

        (new FolderQuickFilterDefaultsSeeder())->run();
        (new FolderQuickFilterDefaultsSeeder())->run();

        $count = DB::table('metadata_field_visibility')
            ->where('category_id', $category->id)
            ->where('show_in_folder_quick_filters', true)
            ->count();
        $this->assertSame(3, $count);
    }

    public function test_seeder_does_not_overwrite_manual_changes(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('photography');

        // A pre-existing manual configuration: someone disabled environment_type.
        $envTypeId = (int) DB::table('metadata_fields')->where('key', 'environment_type')->value('id');
        $this->assertGreaterThan(0, $envTypeId);
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $envTypeId,
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
            'folder_quick_filter_order' => null,
            'folder_quick_filter_weight' => null,
            'folder_quick_filter_source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new FolderQuickFilterDefaultsSeeder())->run();

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $envTypeId)
            ->where('category_id', $category->id)
            ->first();

        // Manual customisation preserved: still disabled, still source=manual.
        $this->assertFalse((bool) $row->show_in_folder_quick_filters);
        $this->assertSame('manual', $row->folder_quick_filter_source);
    }

    public function test_seeder_skips_suppressed_category_field(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('photography');

        // Suppress photo_type for this folder (admin "off" toggle).
        $photoTypeId = (int) DB::table('metadata_fields')->where('key', 'photo_type')->value('id');
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $photoTypeId,
            'tenant_id' => $category->tenant_id,
            'brand_id' => $category->brand_id,
            'category_id' => $category->id,
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_filter_hidden' => true,
            'is_edit_hidden' => false,
            'is_primary' => false,
            'is_required' => false,
            'show_in_folder_quick_filters' => false,
            'folder_quick_filter_source' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new FolderQuickFilterDefaultsSeeder())->run();

        $photoRow = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $photoTypeId)
            ->where('category_id', $category->id)
            ->first();
        $this->assertFalse((bool) $photoRow->show_in_folder_quick_filters);
        $this->assertNull($photoRow->folder_quick_filter_source);

        // Other defaults still seeded.
        $envId = (int) DB::table('metadata_fields')->where('key', 'environment_type')->value('id');
        $envRow = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $envId)
            ->where('category_id', $category->id)
            ->first();
        $this->assertTrue((bool) $envRow->show_in_folder_quick_filters);
        $this->assertSame('seeded', $envRow->folder_quick_filter_source);
    }

    public function test_seeder_no_op_when_slug_not_in_config(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('totally-custom-slug');

        (new FolderQuickFilterDefaultsSeeder())->run();

        $count = DB::table('metadata_field_visibility')
            ->where('category_id', $category->id)
            ->count();
        $this->assertSame(0, $count);
    }

    public function test_seeder_writes_source_seeded_marker(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('logos');

        (new FolderQuickFilterDefaultsSeeder())->run();

        $rows = DB::table('metadata_field_visibility')
            ->where('category_id', $category->id)
            ->where('show_in_folder_quick_filters', true)
            ->get();

        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $r) {
            $this->assertSame('seeded', $r->folder_quick_filter_source);
        }
    }

    public function test_seeded_quick_filters_are_returned_by_assignment_service(): void
    {
        [, , , $category] = $this->makeTenantWithCategory('photography');

        (new FolderQuickFilterDefaultsSeeder())->run();

        $svc = app(FolderQuickFilterAssignmentService::class);
        $list = $svc->getQuickFiltersForFolder($category->fresh());
        $this->assertSame(3, $list->count());
    }
}
