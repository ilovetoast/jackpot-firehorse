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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Asset vs Deliverable Lifecycle Consistency Test
 * 
 * CRITICAL: This test verifies that Assets and Deliverables behave IDENTICALLY
 * in terms of publication lifecycle, visibility rules, and filter behavior.
 * 
 * If behavior differs, it is a BUG.
 * 
 * Test Strategy:
 * - Compare Assets and Deliverables side-by-side
 * - Use identical test structure for both
 * - Fail if defaults, queries, or filters differ
 */
class AssetDeliverableLifecycleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Category $assetCategory;
    protected Category $deliverableCategory;
    protected StorageBucket $bucket;

    // Test assets (both types)
    protected Asset $publishedAsset;
    protected Asset $unpublishedAsset;
    protected Asset $publishedDeliverable;
    protected Asset $unpublishedDeliverable;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'metadata.bypass_approval', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.archive', 'guard_name' => 'web']);

        // Create tenant and brand
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create user with all permissions
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        
        $role = Role::create(['name' => 'admin_role', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive']);
        $this->user->setRoleForTenant($this->tenant, 'admin'); // Use valid tenant role: owner, admin, or member
        $this->user->assignRole($role);

        // Create categories
        $this->assetCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Asset Category',
            'slug' => 'asset-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->deliverableCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Deliverable Category',
            'slug' => 'deliverable-category',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload sessions
        $uploadSessions = [
            'published_asset' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
            'unpublished_asset' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
            'published_deliverable' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
            'unpublished_deliverable' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
        ];

        // Create published asset
        $this->publishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['published_asset']->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Published Asset',
            'original_filename' => 'published-asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published-asset.jpg',
            'metadata' => ['category_id' => $this->assetCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        // Create unpublished asset
        $this->unpublishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['unpublished_asset']->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Unpublished Asset',
            'original_filename' => 'unpublished-asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished-asset.jpg',
            'metadata' => ['category_id' => $this->assetCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);

        // Create published deliverable
        $this->publishedDeliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['published_deliverable']->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Published Deliverable',
            'original_filename' => 'published-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published-deliverable.jpg',
            'metadata' => ['category_id' => $this->deliverableCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        // Create unpublished deliverable
        $this->unpublishedDeliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['unpublished_deliverable']->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Unpublished Deliverable',
            'original_filename' => 'unpublished-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished-deliverable.jpg',
            'metadata' => ['category_id' => $this->deliverableCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);
    }

    /**
     * Test 1: Publication Default Test
     * 
     * Verify that when Assets and Deliverables are created,
     * they have the same default publication state.
     * 
     * This test checks the actual state after creation,
     * not the creation process itself.
     */
    public function test_publication_defaults_are_consistent(): void
    {
        // This test verifies that both types can exist in published/unpublished states
        // The actual default is tested through the UploadCompletionService
        // Here we verify both states are possible and queryable
        
        $this->assertNotNull($this->publishedAsset->published_at, 'Published asset should have published_at set');
        $this->assertNull($this->unpublishedAsset->published_at, 'Unpublished asset should have null published_at');
        $this->assertNotNull($this->publishedDeliverable->published_at, 'Published deliverable should have published_at set');
        $this->assertNull($this->unpublishedDeliverable->published_at, 'Unpublished deliverable should have null published_at');
        
        // Both types support the same publication states
        $this->assertTrue(
            $this->publishedAsset->isPublished() && $this->publishedDeliverable->isPublished(),
            'Both Asset and Deliverable should support published state'
        );
        
        $this->assertTrue(
            !$this->unpublishedAsset->isPublished() && !$this->unpublishedDeliverable->isPublished(),
            'Both Asset and Deliverable should support unpublished state'
        );
    }

    /**
     * Test 2: Unpublished Visibility Test (No Filter Applied)
     * 
     * For a given Brand:
     * - Create published and unpublished Assets
     * - Create published and unpublished Deliverables
     * 
     * Assert:
     * - Unpublished items are NOT returned when no filter is applied
     * - This must behave identically for Assets and Deliverables
     */
    public function test_unpublished_items_hidden_by_default(): void
    {
        // Set up session for controller access
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        // Test Assets endpoint
        $this->actingAs($this->user);
        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        
        // Extract Inertia props from response
        $assetResponse->assertInertia(fn ($page) => $page->has('assets'));
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Test Deliverables endpoint
        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        
        // Extract Inertia props from response
        $deliverableResponse->assertInertia(fn ($page) => $page->has('assets'));
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Assert: Published items ARE visible
        $this->assertContains(
            $this->publishedAsset->id,
            $assetIds,
            'Published assets should be visible in default view'
        );
        
        $this->assertContains(
            $this->publishedDeliverable->id,
            $deliverableIds,
            'Published deliverables should be visible in default view'
        );

        // Assert: Unpublished items are NOT visible (CRITICAL TEST)
        $this->assertNotContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Unpublished assets should NOT be visible in default view (BUG if this fails)'
        );
        
        $this->assertNotContains(
            $this->unpublishedDeliverable->id,
            $deliverableIds,
            'Unpublished deliverables should NOT be visible in default view (BUG if this fails)'
        );

        // Assert: Behavior is identical
        $assetHasUnpublished = in_array($this->unpublishedAsset->id, $assetIds);
        $deliverableHasUnpublished = in_array($this->unpublishedDeliverable->id, $deliverableIds);
        
        $this->assertEquals(
            $assetHasUnpublished,
            $deliverableHasUnpublished,
            'Assets and Deliverables must have identical default visibility behavior. ' .
            'Asset unpublished visible: ' . ($assetHasUnpublished ? 'YES' : 'NO') . ', ' .
            'Deliverable unpublished visible: ' . ($deliverableHasUnpublished ? 'YES' : 'NO')
        );
    }

    /**
     * Test 3: Unpublished Filter Test
     * 
     * Apply filter: lifecycle=unpublished
     * 
     * Assert:
     * - ONLY unpublished items are returned
     * - Published items are excluded
     * - Behavior matches for Assets and Deliverables
     */
    public function test_unpublished_filter_shows_only_unpublished(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        $this->actingAs($this->user);

        // Test Assets endpoint with unpublished filter
        $assetResponse = $this->get('/app/assets?lifecycle=unpublished');
        $assetResponse->assertStatus(200);
        
        $assetResponse->assertInertia(fn ($page) => $page->has('assets'));
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Test Deliverables endpoint with unpublished filter
        $deliverableResponse = $this->get('/app/deliverables?lifecycle=unpublished');
        $deliverableResponse->assertStatus(200);
        
        $deliverableResponse->assertInertia(fn ($page) => $page->has('assets'));
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Assert: Unpublished items ARE in results
        $this->assertContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Unpublished filter should include unpublished assets'
        );
        
        $this->assertContains(
            $this->unpublishedDeliverable->id,
            $deliverableIds,
            'Unpublished filter should include unpublished deliverables'
        );

        // Assert: Published items are NOT in results
        $this->assertNotContains(
            $this->publishedAsset->id,
            $assetIds,
            'Unpublished filter should exclude published assets'
        );
        
        $this->assertNotContains(
            $this->publishedDeliverable->id,
            $deliverableIds,
            'Unpublished filter should exclude published deliverables'
        );

        // Assert: Behavior is identical
        $assetFilterWorks = !in_array($this->publishedAsset->id, $assetIds) && in_array($this->unpublishedAsset->id, $assetIds);
        $deliverableFilterWorks = !in_array($this->publishedDeliverable->id, $deliverableIds) && in_array($this->unpublishedDeliverable->id, $deliverableIds);
        
        $this->assertEquals(
            $assetFilterWorks,
            $deliverableFilterWorks,
            'Assets and Deliverables must have identical unpublished filter behavior. ' .
            'Asset filter works: ' . ($assetFilterWorks ? 'YES' : 'NO') . ', ' .
            'Deliverable filter works: ' . ($deliverableFilterWorks ? 'YES' : 'NO')
        );
    }

    /**
     * Test 4: Default View Shows Only Published
     * 
     * This is a more explicit version of Test 2, focusing on the query logic.
     * 
     * Assert:
     * - Default view (no filter) shows ONLY published items
     * - Behavior matches for Assets and Deliverables
     */
    public function test_default_view_shows_only_published(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        $this->actingAs($this->user);

        // Test Assets endpoint (no filter)
        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        
        $assetResponse->assertInertia(fn ($page) => $page->has('assets'));
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Test Deliverables endpoint (no filter)
        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        
        $deliverableResponse->assertInertia(fn ($page) => $page->has('assets'));
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Assert: All returned items are published
        foreach ($assetIds as $assetId) {
            $asset = Asset::find($assetId);
            $this->assertNotNull(
                $asset->published_at,
                "Asset ID {$assetId} in default view should be published (BUG: unpublished asset leaked)"
            );
        }

        foreach ($deliverableIds as $deliverableId) {
            $deliverable = Asset::find($deliverableId);
            $this->assertNotNull(
                $deliverable->published_at,
                "Deliverable ID {$deliverableId} in default view should be published (BUG: unpublished deliverable leaked)"
            );
        }

        // Assert: Published items are included
        $this->assertContains($this->publishedAsset->id, $assetIds);
        $this->assertContains($this->publishedDeliverable->id, $deliverableIds);
    }

    /**
     * Test 5: Category Filter Interaction
     * 
     * For both Assets and Deliverables:
     * - Create items in multiple categories
     * - Mix published and unpublished states
     * 
     * Assert:
     * - Category filters do NOT override publication rules
     * - Publication filters AND category filters are respected together
     */
    public function test_category_filter_respects_publication_rules(): void
    {
        // Create additional category
        $otherAssetCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Other Asset Category',
            'slug' => 'other-asset-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $otherDeliverableCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Other Deliverable Category',
            'slug' => 'other-deliverable-category',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        // Create unpublished asset in other category
        $unpublishedAssetOtherCategory = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Unpublished Asset Other Category',
            'original_filename' => 'unpublished-asset-other.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished-asset-other.jpg',
            'metadata' => ['category_id' => $otherAssetCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);

        // Create unpublished deliverable in other category
        $unpublishedDeliverableOtherCategory = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Unpublished Deliverable Other Category',
            'original_filename' => 'unpublished-deliverable-other.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished-deliverable-other.jpg',
            'metadata' => ['category_id' => $otherDeliverableCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        $this->actingAs($this->user);

        // Test with category filter (should still exclude unpublished)
        $assetResponse = $this->get("/app/assets?category={$this->assetCategory->slug}");
        $assetResponse->assertStatus(200);
        
        $assetResponse->assertInertia(fn ($page) => $page->has('assets'));
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableResponse = $this->get("/app/deliverables?category={$this->deliverableCategory->slug}");
        $deliverableResponse->assertStatus(200);
        
        $deliverableResponse->assertInertia(fn ($page) => $page->has('assets'));
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Assert: Category filter includes published items from that category
        $this->assertContains($this->publishedAsset->id, $assetIds);
        $this->assertContains($this->publishedDeliverable->id, $deliverableIds);

        // Assert: Category filter STILL excludes unpublished items (even from that category)
        $this->assertNotContains($this->unpublishedAsset->id, $assetIds);
        $this->assertNotContains($this->unpublishedDeliverable->id, $deliverableIds);
        $this->assertNotContains($unpublishedAssetOtherCategory->id, $assetIds);
        $this->assertNotContains($unpublishedDeliverableOtherCategory->id, $deliverableIds);
    }

    /**
     * Test 6: Brand Isolation Test (Safety Check)
     * 
     * Ensure:
     * - Deliverables from Brand A never appear under Brand B
     * - Same test already passes for Assets (use as reference)
     */
    public function test_brand_isolation(): void
    {
        // Create another brand
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        // Create asset and deliverable in other brand
        $otherBrandAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $otherBrand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Other Brand Asset',
            'original_filename' => 'other-brand-asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/other-brand-asset.jpg',
            'metadata' => ['category_id' => $this->assetCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        $otherBrandDeliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $otherBrand->id,
                'storage_bucket_id' => $this->bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Other Brand Deliverable',
            'original_filename' => 'other-brand-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/other-brand-deliverable.jpg',
            'metadata' => ['category_id' => $this->deliverableCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id); // Current brand

        $this->actingAs($this->user);

        // Test Assets endpoint
        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        
        $assetResponse->assertInertia(fn ($page) => $page->has('assets'));
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Test Deliverables endpoint
        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        
        $deliverableResponse->assertInertia(fn ($page) => $page->has('assets'));
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Assert: Other brand's items are NOT visible
        $this->assertNotContains($otherBrandAsset->id, $assetIds);
        $this->assertNotContains($otherBrandDeliverable->id, $deliverableIds);

        // Assert: Current brand's items ARE visible
        $this->assertContains($this->publishedAsset->id, $assetIds);
        $this->assertContains($this->publishedDeliverable->id, $deliverableIds);
    }
}
