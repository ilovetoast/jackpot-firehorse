<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class FolderQuickFilterAssignmentServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private FolderQuickFilterAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FolderQuickFilterAssignmentService::class);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\Brand, 2: \App\Models\User, 3: Category} */
    private function tenantBrandUserCategory(string $slug = 'qf-clips'): array
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'QF Co '.$slug,
                'slug' => 'qf-co-'.$slug,
                'manual_plan_override' => 'starter',
            ],
            ['email' => 'qf-'.$slug.'@example.com', 'first_name' => 'Q', 'last_name' => 'F']
        );
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Clips '.$slug,
            'slug' => $slug,
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $user, $category];
    }

    private function makeField(string $key, string $type, array $overrides = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
            'key' => $key,
            'system_label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => $type,
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return MetadataField::query()->findOrFail($id);
    }

    public function test_enable_quick_filter_creates_a_visibility_row_and_get_returns_it(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandUserCategory();
        $field = $this->makeField('qf_kind_a', 'select');

        $row = $this->service->enableQuickFilter($category, $field, ['order' => 1, 'source' => 'manual']);

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->show_in_folder_quick_filters);
        $this->assertSame(1, $row->folder_quick_filter_order);
        $this->assertSame('manual', $row->folder_quick_filter_source);
        $this->assertSame($category->id, $row->category_id);
        $this->assertSame($brand->id, $row->brand_id);
        $this->assertSame($tenant->id, $row->tenant_id);

        $this->assertTrue($this->service->isQuickFilterEnabled($category, $field));

        $list = $this->service->getQuickFiltersForFolder($category);
        $this->assertCount(1, $list);
        $this->assertSame($field->id, $list->first()->metadata_field_id);
    }

    public function test_same_filter_can_be_enabled_for_multiple_folders_independently(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Multi Folder Co',
            'slug' => 'multi-folder-co',
            'manual_plan_override' => 'starter',
        ]);
        $folderA = Category::create([
            'tenant_id' => $tenant->id, 'brand_id' => $brand->id, 'asset_type' => AssetType::ASSET,
            'name' => 'Folder A', 'slug' => 'folder-a', 'is_system' => false, 'is_locked' => false,
            'is_private' => false, 'is_hidden' => false, 'sort_order' => 1,
        ]);
        $folderB = Category::create([
            'tenant_id' => $tenant->id, 'brand_id' => $brand->id, 'asset_type' => AssetType::ASSET,
            'name' => 'Folder B', 'slug' => 'folder-b', 'is_system' => false, 'is_locked' => false,
            'is_private' => false, 'is_hidden' => false, 'sort_order' => 2,
        ]);
        $field = $this->makeField('qf_shared_kind', 'select');

        $this->service->enableQuickFilter($folderA, $field, ['order' => 0]);

        // Folder B is NOT auto-enabled.
        $this->assertTrue($this->service->isQuickFilterEnabled($folderA, $field));
        $this->assertFalse($this->service->isQuickFilterEnabled($folderB, $field));

        $this->service->enableQuickFilter($folderB, $field, ['order' => 5]);
        $this->assertTrue($this->service->isQuickFilterEnabled($folderA, $field));
        $this->assertTrue($this->service->isQuickFilterEnabled($folderB, $field));

        // Disabling for A leaves B untouched.
        $this->service->disableQuickFilter($folderA, $field);
        $this->assertFalse($this->service->isQuickFilterEnabled($folderA, $field));
        $this->assertTrue($this->service->isQuickFilterEnabled($folderB, $field));
    }

    public function test_ineligible_filter_cannot_be_enabled(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('text-blocked');
        $field = $this->makeField('qf_text_only', 'text');

        $this->expectException(InvalidArgumentException::class);
        $this->service->enableQuickFilter($category, $field);
    }

    public function test_archived_filter_cannot_be_enabled(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('archived-blocked');
        $field = $this->makeField('qf_arch_select', 'select', ['archived_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->enableQuickFilter($category, $field);
    }

    public function test_disabling_quick_filters_via_feature_flag_blocks_enable(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('feature-off');
        $field = $this->makeField('qf_when_off', 'select');

        config(['categories.folder_quick_filters.enabled' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->enableQuickFilter($category, $field);
    }

    public function test_disable_quick_filter_clears_show_flag_and_order_but_preserves_source(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('disable-clears');
        $field = $this->makeField('qf_disable_kind', 'select');
        $this->service->enableQuickFilter($category, $field, ['order' => 3, 'source' => 'seeded']);

        $this->service->disableQuickFilter($category, $field);

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $field->id)
            ->where('category_id', $category->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->show_in_folder_quick_filters);
        $this->assertNull($row->folder_quick_filter_order);
        $this->assertSame('seeded', $row->folder_quick_filter_source);
    }

    public function test_get_quick_filters_for_folder_orders_explicitly_then_alphabetically(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('ordered');
        $alpha = $this->makeField('qf_alpha', 'select', ['system_label' => 'Alpha kind']);
        $bravo = $this->makeField('qf_bravo', 'select', ['system_label' => 'Bravo kind']);
        $charlie = $this->makeField('qf_charlie', 'select', ['system_label' => 'Charlie kind']);

        // Charlie has explicit order=0, Alpha=1, Bravo=null → alpha-sort tail.
        $this->service->enableQuickFilter($category, $charlie, ['order' => 0]);
        $this->service->enableQuickFilter($category, $alpha, ['order' => 1]);
        $this->service->enableQuickFilter($category, $bravo);

        $rows = $this->service->getQuickFiltersForFolder($category);
        $this->assertSame(
            [$charlie->id, $alpha->id, $bravo->id],
            $rows->pluck('metadata_field_id')->all()
        );
    }

    public function test_update_order_and_weight_persist(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('order-weight');
        $field = $this->makeField('qf_orderweight', 'multiselect');
        $this->service->enableQuickFilter($category, $field);

        $this->service->updateQuickFilterOrder($category, $field, 7);
        $this->service->updateQuickFilterWeight($category, $field, 42);

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $field->id)
            ->where('category_id', $category->id)
            ->first();
        $this->assertSame(7, (int) $row->folder_quick_filter_order);
        $this->assertSame(42, (int) $row->folder_quick_filter_weight);
    }

    public function test_negative_order_or_weight_is_rejected(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('neg');
        $field = $this->makeField('qf_neg', 'boolean');
        $this->service->enableQuickFilter($category, $field);

        $this->expectException(InvalidArgumentException::class);
        $this->service->updateQuickFilterOrder($category, $field, -1);
    }

    public function test_get_quick_filters_skips_ineligible_now_even_if_row_says_enabled(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('became-text');
        $field = $this->makeField('qf_was_select', 'select');
        $this->service->enableQuickFilter($category, $field);

        // After the fact, an admin reverts the field type (or it's archived).
        DB::table('metadata_fields')->where('id', $field->id)->update(['type' => 'text']);

        $rows = $this->service->getQuickFiltersForFolder($category->fresh());
        $this->assertCount(0, $rows);
        // The row is still in the DB with show_in_folder_quick_filters=true; the
        // service simply refuses to surface it. No silent corruption.
        $stored = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $field->id)
            ->where('category_id', $category->id)
            ->first();
        $this->assertNotNull($stored);
        $this->assertTrue((bool) $stored->show_in_folder_quick_filters);
    }

    public function test_unknown_source_value_is_rejected(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('bad-source');
        $field = $this->makeField('qf_bad_src', 'select');

        $this->expectException(InvalidArgumentException::class);
        $this->service->enableQuickFilter($category, $field, ['source' => 'totally_invented']);
    }

    public function test_supports_helpers_track_eligibility(): void
    {
        $select = $this->makeField('qf_select_supports', 'select');
        $text = $this->makeField('qf_text_unsupported', 'text');

        $this->assertTrue($this->service->supportsFolderQuickFiltering($select));
        $this->assertFalse($this->service->supportsFolderQuickFiltering($text));

        $this->assertTrue($this->service->supportsFacetCounts($select));
        $this->assertFalse($this->service->supportsFacetCounts($text));

        $this->assertTrue($this->service->isFacetEfficient($select));
        $this->assertFalse($this->service->isFacetEfficient($text));

        $this->assertNull($this->service->reasonIneligible($select));
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED,
            $this->service->reasonIneligible($text)
        );
    }
}
