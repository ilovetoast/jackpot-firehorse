<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class FolderQuickFiltersBatchServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private FolderQuickFilterAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FolderQuickFilterAssignmentService::class);
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

    private function makeCategory(int $tenantId, ?int $brandId, string $slug, int $sortOrder = 1): Category
    {
        return Category::create([
            'tenant_id' => $tenantId,
            'brand_id' => $brandId,
            'asset_type' => AssetType::ASSET,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => $sortOrder,
        ]);
    }

    public function test_batch_returns_quick_filters_keyed_by_category_id(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Batch Co A',
            'slug' => 'batch-co-a',
            'manual_plan_override' => 'starter',
        ]);
        $folderA = $this->makeCategory($tenant->id, $brand->id, 'batch-a', 1);
        $folderB = $this->makeCategory($tenant->id, $brand->id, 'batch-b', 2);

        $alpha = $this->makeField('batch_alpha', 'select');
        $bravo = $this->makeField('batch_bravo', 'select');
        $charlie = $this->makeField('batch_charlie', 'multiselect');

        $this->service->enableQuickFilter($folderA, $alpha, ['order' => 0]);
        $this->service->enableQuickFilter($folderA, $bravo, ['order' => 1]);
        $this->service->enableQuickFilter($folderB, $charlie, ['order' => 0]);

        $result = $this->service->getQuickFiltersForFolders(collect([$folderA, $folderB]));

        $this->assertSame([$alpha->id, $bravo->id], array_map(
            fn ($r) => (int) $r->metadata_field_id,
            $result[$folderA->id]
        ));
        $this->assertSame([$charlie->id], array_map(
            fn ($r) => (int) $r->metadata_field_id,
            $result[$folderB->id]
        ));
    }

    public function test_batch_returns_empty_arrays_for_folders_with_no_quick_filters(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Batch Co Empty',
            'slug' => 'batch-co-empty',
            'manual_plan_override' => 'starter',
        ]);
        $folder = $this->makeCategory($tenant->id, $brand->id, 'batch-empty', 1);

        $result = $this->service->getQuickFiltersForFolders(collect([$folder]));
        $this->assertArrayHasKey($folder->id, $result);
        $this->assertSame([], $result[$folder->id]);
    }

    public function test_batch_skips_ineligible_filters(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Batch Co Inelig',
            'slug' => 'batch-co-inelig',
            'manual_plan_override' => 'starter',
        ]);
        $folder = $this->makeCategory($tenant->id, $brand->id, 'batch-inelig', 1);
        $field = $this->makeField('batch_inelig', 'select');
        $this->service->enableQuickFilter($folder, $field);

        // Field becomes ineligible after assignment (admin reverts the type).
        DB::table('metadata_fields')->where('id', $field->id)->update(['type' => 'text']);

        $result = $this->service->getQuickFiltersForFolders(collect([$folder]));
        $this->assertSame([], $result[$folder->id]);
    }

    public function test_batch_orders_explicit_then_alphabetical(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Batch Co Order',
            'slug' => 'batch-co-order',
            'manual_plan_override' => 'starter',
        ]);
        $folder = $this->makeCategory($tenant->id, $brand->id, 'batch-order', 1);

        $z = $this->makeField('batch_zulu', 'select', ['system_label' => 'Zulu']);
        $a = $this->makeField('batch_alpha', 'select', ['system_label' => 'Alpha']);
        $m = $this->makeField('batch_mike', 'select', ['system_label' => 'Mike']);

        $this->service->enableQuickFilter($folder, $z, ['order' => 0]);
        $this->service->enableQuickFilter($folder, $a, ['order' => 1]);
        $this->service->enableQuickFilter($folder, $m); // null order → alpha tail

        $result = $this->service->getQuickFiltersForFolders(collect([$folder]));
        $this->assertSame(
            [$z->id, $a->id, $m->id],
            array_map(fn ($r) => (int) $r->metadata_field_id, $result[$folder->id])
        );
    }

    public function test_batch_rejects_multi_tenant_collections(): void
    {
        [$tenantA, $brandA] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-multi',
            'manual_plan_override' => 'starter',
        ], ['email' => 'tenant-a-multi@example.com', 'first_name' => 'A', 'last_name' => 'A']);
        [$tenantB, $brandB] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-multi',
            'manual_plan_override' => 'starter',
        ], ['email' => 'tenant-b-multi@example.com', 'first_name' => 'B', 'last_name' => 'B']);

        $folderA = $this->makeCategory($tenantA->id, $brandA->id, 'a-folder', 1);
        $folderB = $this->makeCategory($tenantB->id, $brandB->id, 'b-folder', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->service->getQuickFiltersForFolders([$folderA, $folderB]);
    }

    public function test_batch_no_op_for_empty_input(): void
    {
        $this->assertSame([], $this->service->getQuickFiltersForFolders([]));
        $this->assertSame([], $this->service->getQuickFiltersForFolders(collect()));
    }
}
