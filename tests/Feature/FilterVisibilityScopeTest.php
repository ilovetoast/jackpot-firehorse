<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filter visibility scope tests (Phase M).
 *
 * Ensures filter payload only includes field keys that have at least one value
 * in the scoped asset set. Prevents empty filters from appearing in the UI.
 *
 * Invariants:
 * - Filter omitted when no values exist in scope.
 * - Filter appears when at least one value exists in scope.
 */
class FilterVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected MetadataFilterService $filterService;
    protected MetadataSchemaResolver $schemaResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Filter Scope Tenant', 'slug' => 'filter-scope-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Filter Scope Brand',
            'slug' => 'filter-scope-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $this->user = User::create([
            'email' => 'filter-scope@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Filter',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);

        $this->artisan('db:seed', ['--class' => 'MetadataFieldsSeeder']);

        $this->filterService = app(MetadataFilterService::class);
        $this->schemaResolver = app(MetadataSchemaResolver::class);
    }

    /**
     * Build base query for filter visibility (tenant, brand, category; no request metadata filters).
     */
    protected function baseQueryForCategory(): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('status', AssetStatus::VISIBLE)
            ->where('metadata->category_id', (int) $this->category->id);
    }

    /**
     * Get filter payload (field keys that survive visibility filter) the same way the controller does.
     *
     * @return array<int, string> List of field_key that appear in the payload
     */
    protected function getFilterPayloadFieldKeys(): array
    {
        $schema = $this->schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );
        $filterableSchema = $this->filterService->getFilterableFields($schema, $this->category, $this->tenant);
        $baseQuery = $this->baseQueryForCategory();
        $keysWithValues = $this->filterService->getFieldKeysWithValuesInScope($baseQuery, $filterableSchema);
        $filtered = array_values(array_filter($filterableSchema, function ($field) use ($keysWithValues) {
            $key = $field['field_key'] ?? $field['key'] ?? null;
            return $key && in_array($key, $keysWithValues, true);
        }));

        return array_map(function ($field) {
            return $field['field_key'] ?? $field['key'] ?? '';
        }, $filtered);
    }

    /**
     * Test 1: Filter omitted when no values exist.
     *
     * Create assets with no radio_type, no ooh_type (and no photo_type / scene_classification).
     * Assert filter payload does NOT include those keys.
     */
    public function test_filter_omitted_when_no_values_exist(): void
    {
        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'scope-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Two assets with no photo_type, no scene_classification, no radio_type, no ooh_type
        foreach (['a', 'b'] as $i) {
            Asset::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'upload_session_id' => $session->id,
                'storage_bucket_id' => $bucket->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::ASSET,
                'original_filename' => "{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'storage_root_path' => "test/{$i}.jpg",
                'metadata' => [
                    'category_id' => $this->category->id,
                    'fields' => [],
                ],
            ]);
        }

        $payloadKeys = $this->getFilterPayloadFieldKeys();

        // Fields with zero values in scope must not appear in the payload
        $this->assertNotContains('photo_type', $payloadKeys, 'photo_type should be omitted when no asset has a value');
        $this->assertNotContains('scene_classification', $payloadKeys, 'scene_classification should be omitted when no asset has a value');
    }

    /**
     * Test 2: Filter appears when at least one value exists.
     *
     * Add one asset with photo_type = studio. Assert filter payload includes photo_type.
     */
    public function test_filter_appears_when_at_least_one_value_exists(): void
    {
        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'scope-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'original_filename' => 'with-type.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/with-type.jpg',
            'metadata' => [
                'category_id' => $this->category->id,
                'fields' => ['photo_type' => 'studio'],
            ],
        ]);

        $payloadKeys = $this->getFilterPayloadFieldKeys();

        $this->assertContains('photo_type', $payloadKeys, 'photo_type should appear when at least one asset has a value');
    }
}
