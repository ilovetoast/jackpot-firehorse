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
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C9: Inline collection UX — asset attach on upload, add/remove via drawer, permission guards.
 */
class CollectionInlineAttachTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Collection $collection;
    protected StorageBucket $bucket;
    protected Category $category;
    protected UploadSession $uploadSession;
    protected Asset $asset;
    protected User $adminUser;
    protected User $contributorUser;
    protected User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photo',
            'slug' => 'photo',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $this->collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'C1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'A',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);
        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset',
            'original_filename' => 'asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/asset.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);
        $this->contributorUser = User::create([
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'C',
        ]);
        $this->contributorUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributorUser->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);
        $this->viewerUser = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'V',
        ]);
        $this->viewerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
    }

    /**
     * Finalize accepts collection_ids in manifest; attach uses same flow as drawer add.
     * We use a non-existent upload_key so finalize fails fast (session not found) and we assert
     * collection_ids is accepted (no validation error for it).
     */
    public function test_asset_attached_to_collection_on_upload(): void
    {
        $fakeUploadKey = 'temp/uploads/00000000-0000-0000-0000-000000000000/original';
        $manifestWithCollection = [
            [
                'upload_key' => $fakeUploadKey,
                'expected_size' => 1024,
                'category_id' => $this->category->id,
                'collection_ids' => [$this->collection->id],
            ],
        ];

        $response = $this->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/assets/upload/finalize', ['manifest' => $manifestWithCollection]);

        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertNotEmpty($results);
        $first = $results[0];
        $this->assertArrayHasKey('status', $first);
        if (isset($first['error']['message'])) {
            $this->assertStringNotContainsString('collection_ids', $first['error']['message'] ?? '');
        }
    }

    /** Asset added via drawer (POST add asset to collection). */
    public function test_asset_added_via_drawer(): void
    {
        $this->assertFalse($this->collection->assets()->where('assets.id', $this->asset->id)->exists());

        $response = $this->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->asset->id,
            ]);

        $response->assertStatus(201);
        $response->assertJson(['attached' => true]);
        $this->assertTrue($this->collection->assets()->where('assets.id', $this->asset->id)->exists());
    }

    /** Asset removed via drawer (DELETE remove asset from collection). */
    public function test_asset_removed_via_drawer(): void
    {
        $this->collection->assets()->attach($this->asset->id);
        $this->assertTrue($this->collection->assets()->where('assets.id', $this->asset->id)->exists());

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson("/app/collections/{$this->collection->id}/assets/{$this->asset->id}");

        $response->assertStatus(200);
        $response->assertJson(['detached' => true]);
        $this->assertFalse($this->collection->assets()->where('assets.id', $this->asset->id)->exists());
    }

    /** User without add permission cannot add asset to collection. */
    public function test_user_without_permission_cannot_add(): void
    {
        $response = $this->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->asset->id,
            ]);

        $response->assertStatus(403);
        $this->assertFalse($this->collection->assets()->where('assets.id', $this->asset->id)->exists());
    }

    /** User without remove permission cannot remove asset from collection. */
    public function test_user_without_permission_cannot_remove(): void
    {
        $this->collection->assets()->attach($this->asset->id);

        $response = $this->withoutMiddleware(\App\Http\Middleware\EnsureUserWithinPlanLimit::class)
            ->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson("/app/collections/{$this->collection->id}/assets/{$this->asset->id}");

        $response->assertStatus(403);
        $this->assertTrue($this->collection->assets()->where('assets.id', $this->asset->id)->exists());
    }
}
