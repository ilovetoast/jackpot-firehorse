<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filter normalization tests.
 * Ensures duplicate dominant_hue_group (and other multi-value) params are deduplicated.
 */
class FilterNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;
    protected UploadSession $uploadSession;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.view');
        $this->user->setRoleForTenant($this->tenant, 'admin');
        $this->user->assignRole($role);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Image Category',
            'slug' => 'image-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    /**
     * Simulate duplicate dominant_hue_group query params → parsed filters are unique.
     */
    public function test_filter_normalization_removes_duplicates(): void
    {
        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
            ],
        ]);

        // Request with duplicate multi-value filter params (tags or dominant_hue_group)
        // Backend normalizes and deduplicates; response should have unique values when filter is present
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=' . $this->category->slug . '&tags[0]=test-tag&tags[1]=test-tag');

        $response->assertStatus(200);

        $props = $response->inertiaPage()['props'] ?? [];
        $filters = $props['filters'] ?? [];
        $tagsFilter = $filters['tags'] ?? null;

        $this->assertNotNull($tagsFilter, 'tags filter should be present when passed in URL');
        $this->assertArrayHasKey('value', $tagsFilter);
        $value = $tagsFilter['value'];
        $arr = is_array($value) ? $value : [$value];
        $unique = array_unique($arr);
        $this->assertCount(count($unique), $arr, 'tags filter should have unique values (no duplicates)');
    }
}
