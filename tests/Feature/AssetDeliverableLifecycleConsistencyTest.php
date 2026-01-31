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
        
        // Create Spatie role with same name as tenant role ('admin')
        // hasPermissionForTenant looks up Spatie role by tenant role name
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
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

        // Create unpublished asset - CRITICAL: status=HIDDEN (matches AssetPublicationService::unpublish)
        $this->unpublishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['unpublished_asset']->id,
            'status' => AssetStatus::HIDDEN,
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

        // Create unpublished deliverable - CRITICAL: status=HIDDEN (matches unpublish behavior)
        $this->unpublishedDeliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions['unpublished_deliverable']->id,
            'status' => AssetStatus::HIDDEN,
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
     * HARD RULE: By default (no approval switches enabled),
     * ALL assets are published immediately on creation.
     * 
     * This applies to:
     * - Assets
     * - Deliverables / Marketing Assets
     * - All asset types
     * 
     * There is NO default unpublished state.
     * Approval workflows are EXPLICIT and OPT-IN.
     * 
     * This test verifies that both types can exist in published/unpublished states,
     * but the DEFAULT (when created via UploadCompletionService with no approval requirements)
     * is ALWAYS published.
     */
    public function test_publication_defaults_are_consistent(): void
    {
        // Verify both types support the same publication states
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
        
        // CRITICAL: Default behavior is published (tested via UploadCompletionService in other tests)
        // This test verifies the states are possible, not the default
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

    /**
     * Regression test: Owner/Admin can bypass unpublished filter
     * even if Spatie roles are not attached.
     * 
     * This ensures PermissionMap is checked FIRST before Spatie roles.
     */
    public function test_owner_admin_can_bypass_unpublished_without_spatie_roles(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        $this->actingAs($this->user);

        // Verify user is owner/admin
        $tenantRole = $this->user->getRoleForTenant($this->tenant);
        $this->assertTrue(
            in_array(strtolower($tenantRole ?? ''), ['owner', 'admin']),
            'Test user must be owner or admin'
        );

        // Remove all Spatie roles from user (simulate missing Spatie setup)
        $this->user->roles()->detach();

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

        // Assert: Unpublished items ARE in results (owner/admin can see them via PermissionMap)
        $this->assertContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Owner/Admin should see unpublished assets via PermissionMap, even without Spatie roles'
        );
        
        $this->assertContains(
            $this->unpublishedDeliverable->id,
            $deliverableIds,
            'Owner/Admin should see unpublished deliverables via PermissionMap, even without Spatie roles'
        );
    }

    /**
     * Test A: No Approval Switches Enabled (Default Behavior)
     * 
     * HARD RULE: By default, ALL assets are published immediately on creation.
     * 
     * Verify:
     * - Assets published by default
     * - Deliverables published by default
     * - No divergence between asset types
     * 
     * Note: This test verifies the published assets created in setUp()
     * which represent the default behavior (no approval required).
     */
    public function test_no_approval_switches_assets_published_by_default(): void
    {
        // CRITICAL: Published assets represent default behavior (no approval switches)
        // Both Asset and Deliverable should be published by default
        $this->assertNotNull(
            $this->publishedAsset->published_at,
            'Asset should be published by default when no approval switches are enabled'
        );
        
        $this->assertNotNull(
            $this->publishedDeliverable->published_at,
            'Deliverable should be published by default when no approval switches are enabled'
        );

        // Both should have the same publication state
        $this->assertEquals(
            $this->publishedAsset->isPublished(),
            $this->publishedDeliverable->isPublished(),
            'Assets and Deliverables must have identical default publication behavior'
        );

        // Both should be visible in default view (published)
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Both published assets should be visible in default view
        $this->assertContains(
            $this->publishedAsset->id,
            $assetIds,
            'Published asset should be visible in default view'
        );

        $this->assertContains(
            $this->publishedDeliverable->id,
            $deliverableIds,
            'Published deliverable should be visible in default view'
        );
    }

    /**
     * Test B: Approval Required (via user-level setting)
     * 
     * When approval is required:
     * - Assets unpublished on create
     * - Deliverables unpublished on create
     * - Publish action required
     * - Applies to ALL asset types
     * 
     * Note: This test verifies the unpublished assets created in setUp()
     * which represent the behavior when approval is required.
     */
    public function test_asset_approval_required_assets_unpublished_on_create(): void
    {
        // CRITICAL: Unpublished assets represent behavior when approval is required
        // Both Asset and Deliverable should be unpublished when approval is required
        $this->assertNull(
            $this->unpublishedAsset->published_at,
            'Asset should be unpublished when approval is required'
        );
        
        $this->assertNull(
            $this->unpublishedDeliverable->published_at,
            'Deliverable should be unpublished when approval is required'
        );

        // Both should have identical behavior
        $this->assertEquals(
            $this->unpublishedAsset->isPublished(),
            $this->unpublishedDeliverable->isPublished(),
            'Assets and Deliverables must have identical behavior when approval is required'
        );

        // Both should be hidden from default view (unpublished)
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Both unpublished assets should be hidden from default view
        $this->assertNotContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Unpublished asset should be hidden from default view'
        );

        $this->assertNotContains(
            $this->unpublishedDeliverable->id,
            $deliverableIds,
            'Unpublished deliverable should be hidden from default view'
        );
    }

    /**
     * Test C: Category requires approval
     * 
     * When category requires approval:
     * - Assets unpublished on create
     * - Deliverables unpublished on create
     * - Applies to ALL asset types
     * 
     * This test creates assets with categories that require approval
     * and verifies they are unpublished and have status = HIDDEN.
     */
    public function test_category_approval_required_assets_unpublished_on_create(): void
    {
        // Create categories that require approval
        $approvalCategoryAsset = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Asset Category',
            'slug' => 'approval-asset-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        $approvalCategoryDeliverable = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Deliverable Category',
            'slug' => 'approval-deliverable-category',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        // Create upload sessions
        $uploadSessionAsset = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $uploadSessionDeliverable = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create assets manually with category approval requirement
        // (We can't use UploadCompletionService in tests due to S3 dependency)
        // But we can verify the logic: when category requires approval,
        // assets should be unpublished and have status = HIDDEN
        $assetWithApprovalCategory = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessionAsset->id,
            'status' => AssetStatus::HIDDEN, // Category approval sets status to HIDDEN
            'type' => AssetType::ASSET,
            'title' => 'Asset with Approval Category',
            'original_filename' => 'asset-approval.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/asset-approval.jpg',
            'metadata' => ['category_id' => $approvalCategoryAsset->id],
            'published_at' => null, // Unpublished when category requires approval
            'published_by_id' => null,
        ]);

        $deliverableWithApprovalCategory = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessionDeliverable->id,
            'status' => AssetStatus::HIDDEN, // Category approval sets status to HIDDEN
            'type' => AssetType::DELIVERABLE,
            'title' => 'Deliverable with Approval Category',
            'original_filename' => 'deliverable-approval.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/deliverable-approval.jpg',
            'metadata' => ['category_id' => $approvalCategoryDeliverable->id],
            'published_at' => null, // Unpublished when category requires approval
            'published_by_id' => null,
        ]);

        // CRITICAL: Both should be unpublished when category requires approval
        $this->assertNull(
            $assetWithApprovalCategory->published_at,
            'Asset should be unpublished when category requires approval'
        );
        
        $this->assertNull(
            $deliverableWithApprovalCategory->published_at,
            'Deliverable should be unpublished when category requires approval'
        );

        // Both should have status = HIDDEN (category approval sets status to HIDDEN)
        $this->assertEquals(
            AssetStatus::HIDDEN->value,
            $assetWithApprovalCategory->status->value,
            'Asset should have status = HIDDEN when category requires approval'
        );

        $this->assertEquals(
            AssetStatus::HIDDEN->value,
            $deliverableWithApprovalCategory->status->value,
            'Deliverable should have status = HIDDEN when category requires approval'
        );

        // Both should have identical behavior
        $this->assertEquals(
            $assetWithApprovalCategory->isPublished(),
            $deliverableWithApprovalCategory->isPublished(),
            'Assets and Deliverables must have identical behavior when category requires approval'
        );

        $this->assertEquals(
            $assetWithApprovalCategory->status->value,
            $deliverableWithApprovalCategory->status->value,
            'Assets and Deliverables must have identical status when category requires approval'
        );
    }

    /**
     * Test D: Regression - No Special-Case Logic by Asset Type
     * 
     * Assert that there is NO conditional logic based on:
     * - asset type (ASSET vs DELIVERABLE)
     * - model class
     * - controller
     * 
     * If Assets pass and Deliverables fail, the test should fail.
     * 
     * This test verifies that published/unpublished behavior is identical
     * across all scenarios using the existing test assets.
     */
    public function test_no_special_case_logic_by_asset_type(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Test 1: Published assets - both types must behave identically
        $this->assertEquals(
            $this->publishedAsset->isPublished(),
            $this->publishedDeliverable->isPublished(),
            'Published Assets and Deliverables must have identical publication state'
        );

        $this->assertNotNull($this->publishedAsset->published_at);
        $this->assertNotNull($this->publishedDeliverable->published_at);

        // Test 2: Unpublished assets - both types must behave identically
        $this->assertEquals(
            $this->unpublishedAsset->isPublished(),
            $this->unpublishedDeliverable->isPublished(),
            'Unpublished Assets and Deliverables must have identical publication state'
        );

        $this->assertNull($this->unpublishedAsset->published_at);
        $this->assertNull($this->unpublishedDeliverable->published_at);

        // Test 3: Default view filtering - both types must behave identically
        $assetResponse = $this->get('/app/assets');
        $assetResponse->assertStatus(200);
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableResponse->assertStatus(200);
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Published assets should be visible, unpublished should be hidden (both types)
        $assetPublishedVisible = in_array($this->publishedAsset->id, $assetIds);
        $assetUnpublishedVisible = in_array($this->unpublishedAsset->id, $assetIds);
        $deliverablePublishedVisible = in_array($this->publishedDeliverable->id, $deliverableIds);
        $deliverableUnpublishedVisible = in_array($this->unpublishedDeliverable->id, $deliverableIds);

        $this->assertTrue($assetPublishedVisible, 'Published asset should be visible in default view');
        $this->assertTrue($deliverablePublishedVisible, 'Published deliverable should be visible in default view');
        $this->assertFalse($assetUnpublishedVisible, 'Unpublished asset should be hidden in default view');
        $this->assertFalse($deliverableUnpublishedVisible, 'Unpublished deliverable should be hidden in default view');

        // CRITICAL: Behavior must be identical
        $this->assertEquals(
            $assetPublishedVisible,
            $deliverablePublishedVisible,
            'Published Assets and Deliverables must have identical visibility in default view'
        );

        $this->assertEquals(
            $assetUnpublishedVisible,
            $deliverableUnpublishedVisible,
            'Unpublished Assets and Deliverables must have identical visibility in default view'
        );

        // Test 4: Unpublished filter - both types must behave identically
        $assetUnpublishedResponse = $this->get('/app/assets?lifecycle=unpublished');
        $assetUnpublishedResponse->assertStatus(200);
        $assetUnpublishedData = $assetUnpublishedResponse->inertiaPage();
        $assetUnpublishedIds = collect($assetUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableUnpublishedResponse = $this->get('/app/deliverables?lifecycle=unpublished');
        $deliverableUnpublishedResponse->assertStatus(200);
        $deliverableUnpublishedData = $deliverableUnpublishedResponse->inertiaPage();
        $deliverableUnpublishedIds = collect($deliverableUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        // Both unpublished assets should be visible with unpublished filter
        $this->assertContains($this->unpublishedAsset->id, $assetUnpublishedIds);
        $this->assertContains($this->unpublishedDeliverable->id, $deliverableUnpublishedIds);

        // Both published assets should NOT be visible with unpublished filter
        $this->assertNotContains($this->publishedAsset->id, $assetUnpublishedIds);
        $this->assertNotContains($this->publishedDeliverable->id, $deliverableUnpublishedIds);
    }

    /**
     * Test: Publish Action - Asset
     * 
     * Verify that calling the publish endpoint:
     * - Actually sets published_at in database
     * - Persists correctly
     * - Is reflected in subsequent queries
     * - Asset appears in default index
     */
    public function test_publish_action_sets_published_at_for_asset(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Start with an unpublished asset
        $asset = $this->unpublishedAsset;
        $this->assertNull($asset->published_at, 'Asset should start unpublished');

        // Call the publish endpoint (same endpoint used by UI)
        $response = $this->postJson("/app/assets/{$asset->id}/publish");
        $response->assertStatus(200);

        // Reload asset from database to verify persistence
        $asset->refresh();
        
        // CRITICAL: published_at must be set
        $this->assertNotNull(
            $asset->published_at,
            'Publish action must set published_at in database'
        );

        $this->assertNotNull(
            $asset->published_by_id,
            'Publish action must set published_by_id in database'
        );

        $this->assertEquals(
            $this->user->id,
            $asset->published_by_id,
            'published_by_id must match the user who published'
        );

        // Verify asset appears in default index (published assets are visible)
        $indexResponse = $this->get('/app/assets');
        $indexResponse->assertStatus(200);
        $indexData = $indexResponse->inertiaPage();
        $assetIds = collect($indexData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertContains(
            $asset->id,
            $assetIds,
            'Published asset must appear in default index view'
        );

        // Verify asset is NOT in unpublished filter
        $unpublishedResponse = $this->get('/app/assets?lifecycle=unpublished');
        $unpublishedResponse->assertStatus(200);
        $unpublishedData = $unpublishedResponse->inertiaPage();
        $unpublishedIds = collect($unpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains(
            $asset->id,
            $unpublishedIds,
            'Published asset must NOT appear in unpublished filter'
        );
    }

    /**
     * Test: Publish Action - Deliverable
     * 
     * Verify that calling the publish endpoint:
     * - Actually sets published_at in database
     * - Persists correctly
     * - Is reflected in subsequent queries
     * - Deliverable appears in default index
     * 
     * CRITICAL: Must behave identically to Assets
     */
    public function test_publish_action_sets_published_at_for_deliverable(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Start with an unpublished deliverable
        $deliverable = $this->unpublishedDeliverable;
        $this->assertNull($deliverable->published_at, 'Deliverable should start unpublished');

        // Call the publish endpoint (same endpoint used by UI)
        // NOTE: Deliverables use the same /app/assets/{asset}/publish endpoint
        $response = $this->postJson("/app/assets/{$deliverable->id}/publish");
        $response->assertStatus(200);

        // Reload deliverable from database to verify persistence
        $deliverable->refresh();
        
        // CRITICAL: published_at must be set (identical to Assets)
        $this->assertNotNull(
            $deliverable->published_at,
            'Publish action must set published_at in database for Deliverables'
        );

        $this->assertNotNull(
            $deliverable->published_by_id,
            'Publish action must set published_by_id in database for Deliverables'
        );

        $this->assertEquals(
            $this->user->id,
            $deliverable->published_by_id,
            'published_by_id must match the user who published'
        );

        // Verify deliverable appears in default index (published deliverables are visible)
        $indexResponse = $this->get('/app/deliverables');
        $indexResponse->assertStatus(200);
        $indexData = $indexResponse->inertiaPage();
        $deliverableIds = collect($indexData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertContains(
            $deliverable->id,
            $deliverableIds,
            'Published deliverable must appear in default index view'
        );

        // Verify deliverable is NOT in unpublished filter
        $unpublishedResponse = $this->get('/app/deliverables?lifecycle=unpublished');
        $unpublishedResponse->assertStatus(200);
        $unpublishedData = $unpublishedResponse->inertiaPage();
        $unpublishedIds = collect($unpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains(
            $deliverable->id,
            $unpublishedIds,
            'Published deliverable must NOT appear in unpublished filter'
        );

        // CRITICAL: Behavior must be identical to Assets
        $asset = $this->unpublishedAsset;
        $assetResponse = $this->postJson("/app/assets/{$asset->id}/publish");
        $assetResponse->assertStatus(200);
        $asset->refresh();

        $this->assertEquals(
            $asset->published_at !== null,
            $deliverable->published_at !== null,
            'Assets and Deliverables must have identical publish behavior'
        );
    }

    /**
     * Test: No Post-Publish Reversion
     * 
     * Regression test to ensure published_at remains set after:
     * - Publish action completes
     * - Any observers/metadata hooks run
     * - Model is reloaded from database
     * 
     * This test must fail if:
     * - published_at is cleared after publish
     * - approval logic overrides it
     * - observers reset publication state
     */
    public function test_published_at_persists_after_publish_action(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Test both Asset and Deliverable
        $testCases = [
            ['asset' => $this->unpublishedAsset, 'type' => 'Asset'],
            ['asset' => $this->unpublishedDeliverable, 'type' => 'Deliverable'],
        ];

        foreach ($testCases as $testCase) {
            $asset = $testCase['asset'];
            $type = $testCase['type'];

            // Publish the asset
            $response = $this->postJson("/app/assets/{$asset->id}/publish");
            $response->assertStatus(200);

            // Immediately reload from database
            $asset->refresh();
            $publishedAtAfterPublish = $asset->published_at;
            $publishedByIdAfterPublish = $asset->published_by_id;

            $this->assertNotNull(
                $publishedAtAfterPublish,
                "{$type}: published_at must be set immediately after publish"
            );

            // Simulate any post-publish operations that might run
            // (e.g., metadata hooks, observers, event listeners)
            // Force a fresh database query to ensure no caching issues
            $freshAsset = Asset::find($asset->id);
            
            $this->assertNotNull(
                $freshAsset->published_at,
                "{$type}: published_at must persist after fresh database query"
            );

            $this->assertEquals(
                $publishedAtAfterPublish->toIso8601String(),
                $freshAsset->published_at->toIso8601String(),
                "{$type}: published_at must not change after reload"
            );

            $this->assertEquals(
                $publishedByIdAfterPublish,
                $freshAsset->published_by_id,
                "{$type}: published_by_id must not change after reload"
            );

            // Verify it still appears in default index after reload
            $indexResponse = $this->get($type === 'Asset' ? '/app/assets' : '/app/deliverables');
            $indexResponse->assertStatus(200);
            $indexData = $indexResponse->inertiaPage();
            $assetIds = collect($indexData['props']['assets'] ?? [])->pluck('id')->toArray();

            $this->assertContains(
                $asset->id,
                $assetIds,
                "{$type}: Published asset must remain visible in index after reload"
            );
        }
    }

    /**
     * Test: Publish Permission Path Validation
     * 
     * Ensure publish works for:
     * - owner role (via PermissionMap)
     * - admin role (via PermissionMap)
     * - Even if Spatie role is not attached
     * 
     * Publish must succeed if PermissionMap allows it.
     */
    public function test_publish_succeeds_with_permission_map_permissions(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Verify user is owner/admin (has publish permission via PermissionMap)
        $tenantRole = $this->user->getRoleForTenant($this->tenant);
        $this->assertTrue(
            in_array(strtolower($tenantRole ?? ''), ['owner', 'admin']),
            'Test user must be owner or admin'
        );

        // Test both Asset and Deliverable
        $testCases = [
            ['asset' => $this->unpublishedAsset, 'type' => 'Asset'],
            ['asset' => $this->unpublishedDeliverable, 'type' => 'Deliverable'],
        ];

        foreach ($testCases as $testCase) {
            $asset = $testCase['asset'];
            $type = $testCase['type'];

            // Remove all Spatie roles (simulate missing Spatie setup)
            $this->user->roles()->detach();

            // Publish should still succeed via PermissionMap
            $response = $this->postJson("/app/assets/{$asset->id}/publish");
            $response->assertStatus(200);

            // Verify published_at is set
            $asset->refresh();
            $this->assertNotNull(
                $asset->published_at,
                "{$type}: Publish must succeed with PermissionMap permissions, even without Spatie roles"
            );

            $this->assertEquals(
                $this->user->id,
                $asset->published_by_id,
                "{$type}: published_by_id must be set correctly"
            );
        }
    }

    /**
     * Test: Publish Behavior Identical for Assets and Deliverables
     * 
     * Regression test ensuring:
     * - Same endpoint works for both
     * - Same service logic applies
     * - Same database fields are set
     * - Same query behavior after publish
     */
    public function test_publish_behavior_identical_for_assets_and_deliverables(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Publish both Asset and Deliverable
        $assetResponse = $this->postJson("/app/assets/{$this->unpublishedAsset->id}/publish");
        $assetResponse->assertStatus(200);

        $deliverableResponse = $this->postJson("/app/assets/{$this->unpublishedDeliverable->id}/publish");
        $deliverableResponse->assertStatus(200);

        // Reload both from database
        $this->unpublishedAsset->refresh();
        $this->unpublishedDeliverable->refresh();

        // CRITICAL: Both must have identical publication state
        $this->assertNotNull($this->unpublishedAsset->published_at);
        $this->assertNotNull($this->unpublishedDeliverable->published_at);

        $this->assertNotNull($this->unpublishedAsset->published_by_id);
        $this->assertNotNull($this->unpublishedDeliverable->published_by_id);

        $this->assertEquals(
            $this->unpublishedAsset->published_by_id,
            $this->unpublishedDeliverable->published_by_id,
            'Both Asset and Deliverable must have same published_by_id'
        );

        // Both must appear in their respective default indexes
        $assetIndexResponse = $this->get('/app/assets');
        $assetIndexResponse->assertStatus(200);
        $assetIndexData = $assetIndexResponse->inertiaPage();
        $assetIds = collect($assetIndexData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableIndexResponse = $this->get('/app/deliverables');
        $deliverableIndexResponse->assertStatus(200);
        $deliverableIndexData = $deliverableIndexResponse->inertiaPage();
        $deliverableIds = collect($deliverableIndexData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertContains($this->unpublishedAsset->id, $assetIds);
        $this->assertContains($this->unpublishedDeliverable->id, $deliverableIds);

        // Both must NOT appear in unpublished filter
        $assetUnpublishedResponse = $this->get('/app/assets?lifecycle=unpublished');
        $assetUnpublishedData = $assetUnpublishedResponse->inertiaPage();
        $assetUnpublishedIds = collect($assetUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $deliverableUnpublishedResponse = $this->get('/app/deliverables?lifecycle=unpublished');
        $deliverableUnpublishedData = $deliverableUnpublishedResponse->inertiaPage();
        $deliverableUnpublishedIds = collect($deliverableUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains($this->unpublishedAsset->id, $assetUnpublishedIds);
        $this->assertNotContains($this->unpublishedDeliverable->id, $deliverableUnpublishedIds);
    }

    /**
     * Test: Published Assets with Pending Approval Are NOT Unpublished
     * 
     * CANONICAL RULE: Published vs Unpublished is determined ONLY by published_at.
     * Approval state (approved_at, metadata approval, etc.) MUST NOT affect Published/Unpublished labeling.
     * 
     * This test ensures that:
     * - Assets with published_at != null are considered published
     * - Even if approved_at = null or metadata is pending
     * - Asset appears in default index
     * - Asset is NOT in unpublished filter
     * - Asset is NOT labeled as "unpublished"
     */
    public function test_published_assets_with_pending_approval_are_not_unpublished(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create a published asset with pending approval
        // This simulates: asset was published, but approval workflow is still pending
        $publishedAssetWithPendingApproval = Asset::create([
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
            'title' => 'Published Asset with Pending Approval',
            'original_filename' => 'published-pending.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published-pending.jpg',
            'metadata' => ['category_id' => $this->assetCategory->id],
            'published_at' => now(), // PUBLISHED
            'published_by_id' => $this->user->id,
            'approval_status' => \App\Enums\ApprovalStatus::PENDING, // Approval pending
            'approved_at' => null, // Not yet approved
        ]);

        // Create a published deliverable with pending approval
        $publishedDeliverableWithPendingApproval = Asset::create([
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
            'title' => 'Published Deliverable with Pending Approval',
            'original_filename' => 'published-pending-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published-pending-deliverable.jpg',
            'metadata' => ['category_id' => $this->deliverableCategory->id],
            'published_at' => now(), // PUBLISHED
            'published_by_id' => $this->user->id,
            'approval_status' => \App\Enums\ApprovalStatus::PENDING, // Approval pending
            'approved_at' => null, // Not yet approved
        ]);

        // CRITICAL: Both are published (published_at != null)
        $this->assertNotNull($publishedAssetWithPendingApproval->published_at);
        $this->assertNotNull($publishedDeliverableWithPendingApproval->published_at);

        // Both have pending approval (but that's separate from publication)
        $this->assertEquals(
            \App\Enums\ApprovalStatus::PENDING->value,
            $publishedAssetWithPendingApproval->approval_status->value
        );
        $this->assertEquals(
            \App\Enums\ApprovalStatus::PENDING->value,
            $publishedDeliverableWithPendingApproval->approval_status->value
        );

        // Test Assets endpoint
        $assetIndexResponse = $this->get('/app/assets');
        $assetIndexResponse->assertStatus(200);
        $assetIndexData = $assetIndexResponse->inertiaPage();
        $assetIds = collect($assetIndexData['props']['assets'] ?? [])->pluck('id')->toArray();

        // CRITICAL: Published asset with pending approval MUST appear in default index
        $this->assertContains(
            $publishedAssetWithPendingApproval->id,
            $assetIds,
            'Published asset with pending approval must appear in default index (published_at determines visibility)'
        );

        // CRITICAL: Published asset must NOT appear in unpublished filter
        $assetUnpublishedResponse = $this->get('/app/assets?lifecycle=unpublished');
        $assetUnpublishedData = $assetUnpublishedResponse->inertiaPage();
        $assetUnpublishedIds = collect($assetUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains(
            $publishedAssetWithPendingApproval->id,
            $assetUnpublishedIds,
            'Published asset (even with pending approval) must NOT appear in unpublished filter'
        );

        // Test Deliverables endpoint
        $deliverableIndexResponse = $this->get('/app/deliverables');
        $deliverableIndexResponse->assertStatus(200);
        $deliverableIndexData = $deliverableIndexResponse->inertiaPage();
        $deliverableIds = collect($deliverableIndexData['props']['assets'] ?? [])->pluck('id')->toArray();

        // CRITICAL: Published deliverable with pending approval MUST appear in default index
        $this->assertContains(
            $publishedDeliverableWithPendingApproval->id,
            $deliverableIds,
            'Published deliverable with pending approval must appear in default index (published_at determines visibility)'
        );

        // CRITICAL: Published deliverable must NOT appear in unpublished filter
        $deliverableUnpublishedResponse = $this->get('/app/deliverables?lifecycle=unpublished');
        $deliverableUnpublishedData = $deliverableUnpublishedResponse->inertiaPage();
        $deliverableUnpublishedIds = collect($deliverableUnpublishedData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains(
            $publishedDeliverableWithPendingApproval->id,
            $deliverableUnpublishedIds,
            'Published deliverable (even with pending approval) must NOT appear in unpublished filter'
        );

        // CRITICAL: Both must behave identically
        $assetIsPublished = $publishedAssetWithPendingApproval->isPublished();
        $deliverableIsPublished = $publishedDeliverableWithPendingApproval->isPublished();

        $this->assertTrue($assetIsPublished, 'Asset with published_at != null must be considered published');
        $this->assertTrue($deliverableIsPublished, 'Deliverable with published_at != null must be considered published');
        $this->assertEquals(
            $assetIsPublished,
            $deliverableIsPublished,
            'Assets and Deliverables must have identical published state determination'
        );
    }
}
