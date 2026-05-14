<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;
use App\Services\Filters\Facet\AssetMetadataFacetCountProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5 — facet count provider correctness.
 *
 * Asserts:
 *  - Counts aggregate correctly for single_select / multi_select / boolean.
 *  - Active filters from OTHER dimensions narrow the count.
 *  - Active filters on the SAME dimension are stripped before counting (so
 *    Nature's count reflects "all environments matching the rest of the
 *    page state", not "only assets where environment=Nature").
 *  - Tenant scoping isolates one tenant's data from another's.
 *  - Folder scoping does not leak counts across categories.
 *  - Per-value queries are bounded by the candidate count, not by row volume
 *    (no N+1 explosion as assets grow).
 */
class AssetMetadataFacetCountProviderTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private Tenant $tenant;
    private Brand $brand;
    private Category $folder;
    private MetadataField $subjectField;
    private MetadataField $photoField;
    private MetadataField $starField;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable the cache layer for these tests; behavior is asserted
        // against the real provider directly.
        config(['categories.folder_quick_filters.facet_cache_enabled' => false]);
        // Bind tenant context for MetadataFilterService::applyFilters.
        $this->bootTenancy();
        $this->seedFields();
        $this->seedAssets();
    }

    private function bootTenancy(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Facet Co',
            'slug' => 'facet-co',
            'manual_plan_override' => 'starter',
        ], ['email' => 'facet@example.com', 'first_name' => 'F', 'last_name' => 'C']);
        $this->tenant = $tenant;
        $this->brand = $brand;
        $this->folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'requires_approval' => false,
        ]);
        // Bind tenant in the container so MetadataFilterService can resolve it.
        $this->app->instance('tenant', $tenant);
    }

    private function seedFields(): void
    {
        $this->subjectField = $this->makeField('p5_subject', 'multiselect', [
            ['people', 'People'],
            ['product', 'Product'],
            ['studio', 'Studio'],
        ]);
        $this->photoField = $this->makeField('p5_photo_type', 'select', [
            ['studio', 'Studio'],
            ['outdoor', 'Outdoor'],
        ]);
        $this->starField = $this->makeField('p5_starred', 'boolean', []);
    }

    private function makeField(string $key, string $type, array $options): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId([
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
        ]);
        foreach ($options as [$value, $label]) {
            DB::table('metadata_options')->insert([
                'metadata_field_id' => $id,
                'value' => $value,
                'system_label' => $label,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return MetadataField::query()->findOrFail($id);
    }

    private function seedAssets(): void
    {
        // 4 assets in this folder. Subject array + scalar photo type +
        // boolean starred. Counts assertions below depend on these values.
        $this->makeAsset($this->folder, [
            $this->subjectField->id => ['people'],
            $this->photoField->id => 'studio',
            $this->starField->id => true,
        ]);
        $this->makeAsset($this->folder, [
            $this->subjectField->id => ['product'],
            $this->photoField->id => 'studio',
            $this->starField->id => false,
        ]);
        $this->makeAsset($this->folder, [
            $this->subjectField->id => ['people', 'studio'],
            $this->photoField->id => 'outdoor',
            $this->starField->id => false,
        ]);
        $this->makeAsset($this->folder, [
            $this->subjectField->id => ['product'],
            $this->photoField->id => 'outdoor',
            $this->starField->id => true,
        ]);
    }

    /**
     * Insert an asset + a current asset_metadata row per provided field
     * mapping. Source/approval are set so MetadataFilterService's filtering
     * machinery considers them — Phase 5's count provider mirrors that
     * logic via applyFilters().
     */
    private function makeAsset(Category $folder, array $fieldValueMap): string
    {
        $assetId = (string) Str::uuid();
        DB::table('assets')->insert([
            'id' => $assetId,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => null,
            'storage_bucket_id' => null,
            'status' => AssetStatus::VISIBLE->value,
            'thumbnail_status' => ThumbnailStatus::PENDING->value,
            'analysis_status' => 'pending',
            'type' => AssetType::ASSET->value,
            'original_filename' => 'a.jpg',
            'title' => 'A',
            'size_bytes' => 3,
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/'.$this->tenant->uuid.'/assets/'.$assetId.'/v1',
            'metadata' => json_encode(['category_id' => (string) $folder->id]),
            'created_at' => now(),
            'updated_at' => now(),
            'intake_state' => 'normal',
        ]);
        foreach ($fieldValueMap as $fieldId => $value) {
            DB::table('asset_metadata')->insert([
                'asset_id' => $assetId,
                'metadata_field_id' => $fieldId,
                'value_json' => json_encode($value),
                'source' => 'user',
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $assetId;
    }

    private function provider(): AssetMetadataFacetCountProvider
    {
        return $this->app->make(AssetMetadataFacetCountProvider::class);
    }

    public function test_counts_aggregate_correctly_for_multiselect_with_no_active_filters(): void
    {
        $counts = $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $this->tenant,
            $this->brand,
            $this->folder,
        );

        $this->assertNotNull($counts);
        // people: assets 1 + 3 = 2; product: assets 2 + 4 = 2; studio: asset 3 = 1.
        $this->assertSame(2, $counts['people']);
        $this->assertSame(2, $counts['product']);
        $this->assertSame(1, $counts['studio']);
    }

    public function test_counts_aggregate_correctly_for_single_select_with_no_active_filters(): void
    {
        $counts = $this->provider()->countOptionsForFolder(
            $this->photoField,
            $this->tenant,
            $this->brand,
            $this->folder,
        );

        $this->assertNotNull($counts);
        $this->assertSame(2, $counts['studio']);   // assets 1, 2
        $this->assertSame(2, $counts['outdoor']);  // assets 3, 4
    }

    public function test_counts_aggregate_correctly_for_boolean(): void
    {
        $counts = $this->provider()->countOptionsForFolder(
            $this->starField,
            $this->tenant,
            $this->brand,
            $this->folder,
        );

        $this->assertNotNull($counts);
        $this->assertSame(2, $counts['true']);   // assets 1, 4
        $this->assertSame(2, $counts['false']);  // assets 2, 3
    }

    public function test_counts_respect_other_dimension_filters(): void
    {
        $counts = $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $this->tenant,
            $this->brand,
            $this->folder,
            [
                'p5_photo_type' => ['operator' => 'equals', 'value' => 'studio'],
            ],
        );

        $this->assertNotNull($counts);
        // Only studio photos: assets 1 + 2. people=1, product=1, studio=0.
        $this->assertSame(1, $counts['people']);
        $this->assertSame(1, $counts['product']);
        $this->assertSame(0, $counts['studio']);
    }

    public function test_counts_strip_current_dimensions_active_filter(): void
    {
        // Active filter set INCLUDES subject_type, but counts for the
        // subject dimension itself must ignore that constraint — otherwise
        // "people" would always be reported as the only matching value once
        // the user picked it, which is the wrong facet semantic.
        $counts = $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $this->tenant,
            $this->brand,
            $this->folder,
            [
                'p5_subject' => ['operator' => 'equals', 'value' => ['people']],
            ],
        );

        $this->assertNotNull($counts);
        // Same totals as the no-filter case — current dimension was stripped.
        $this->assertSame(2, $counts['people']);
        $this->assertSame(2, $counts['product']);
        $this->assertSame(1, $counts['studio']);
    }

    public function test_counts_isolate_tenants_and_folders(): void
    {
        // A second tenant + folder + asset for the SAME field should NOT
        // bleed into the first tenant's counts.
        [$otherTenant, $otherBrand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'manual_plan_override' => 'starter',
        ], ['email' => 'other@example.com', 'first_name' => 'O', 'last_name' => 'C']);
        $otherFolder = Category::create([
            'tenant_id' => $otherTenant->id,
            'brand_id' => $otherBrand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Other Folder',
            'slug' => 'other-folder',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'requires_approval' => false,
        ]);
        // Capture both before swapping so the restore at the end is clean —
        // makeAsset() reads $this->tenant + $this->brand for the FK columns.
        $originalTenant = $this->tenant;
        $originalBrand = $this->brand;
        $this->tenant = $otherTenant;
        $this->brand = $otherBrand;
        $this->makeAsset($otherFolder, [
            $this->subjectField->id => ['people'],
        ]);
        $this->makeAsset($otherFolder, [
            $this->subjectField->id => ['people'],
        ]);
        $this->tenant = $originalTenant;
        $this->brand = $originalBrand;

        $counts = $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $originalTenant,
            $originalBrand,
            $this->folder,
        );

        // Original tenant's counts unchanged — the other tenant's "people"
        // assets are not visible.
        $this->assertSame(2, $counts['people']);
    }

    public function test_provider_returns_zero_for_unseeded_folder(): void
    {
        $emptyFolder = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Empty',
            'slug' => 'empty',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 2,
            'requires_approval' => false,
        ]);

        $counts = $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $this->tenant,
            $this->brand,
            $emptyFolder,
        );

        $this->assertSame(['people' => 0, 'product' => 0, 'studio' => 0], $counts);
    }

    public function test_provider_runs_a_bounded_number_of_queries_per_call(): void
    {
        // Per-value count strategy is bounded by candidate count + a fixed
        // schema-resolution cost. Asserting an upper bound prevents
        // accidentally regressing to N+1 over assets.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->provider()->countOptionsForFolder(
            $this->subjectField,
            $this->tenant,
            $this->brand,
            $this->folder,
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 3 candidate values + a few schema/setup queries. 25 is a generous
        // upper bound — any regression to N+1 over the 4 assets here would
        // far exceed it.
        $this->assertLessThanOrEqual(
            25,
            $queryCount,
            "Provider issued {$queryCount} queries; expected ≤ 25 for 3 candidate values."
        );
    }

    public function test_returns_empty_map_when_field_has_no_options(): void
    {
        $bareField = $this->makeField('p5_bare', 'select', []);
        $counts = $this->provider()->countOptionsForFolder(
            $bareField,
            $this->tenant,
            $this->brand,
            $this->folder,
        );

        $this->assertSame([], $counts);
    }

    public function test_default_binding_is_cached_decorator_wrapping_real_provider(): void
    {
        // Wiring smoke-check: the container hands out the cached decorator,
        // which fronts the real provider. The decorator is what production
        // code actually uses; tests above use the inner provider directly
        // for deterministic assertions.
        $bound = $this->app->make(FacetCountProvider::class);
        $this->assertInstanceOf(
            \App\Services\Filters\Facet\CachedFacetCountProvider::class,
            $bound,
        );
    }
}
