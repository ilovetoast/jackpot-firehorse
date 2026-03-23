<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandReferenceAsset;
use App\Models\BrandVisualReference;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandAssetPromoteTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected User $user;

    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'view brand', 'guard_name' => 'web']);
        Permission::create(['name' => 'brand_settings.manage', 'guard_name' => 'web']);

        $this->tenant = Tenant::create(['name' => 'Promote Tenant', 'slug' => 'promote-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promote Brand',
            'slug' => 'promote-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Assets',
            'slug' => 'assets',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $this->user = User::create([
            'email' => 'promote@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Promote',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'brand-admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'view brand', 'brand_settings.manage']);
        $this->user->setRoleForTenant($this->tenant, 'admin');
        $this->user->assignRole($role);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'promote-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        BrandVisualReference::withoutEvents(function () {
            BrandVisualReference::create([
                'brand_id' => $this->brand->id,
                'asset_id' => null,
                'type' => BrandVisualReference::TYPE_LOGO,
                'embedding_vector' => null,
            ]);
        });
    }

    protected function createAsset(string $title = 'Asset'): Asset
    {
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => $title,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.jpg',
            'metadata' => [
                'category_id' => $this->category->id,
            ],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
    }

    public function test_promote_creates_brand_reference_asset_row(): void
    {
        $asset = $this->createAsset();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/api/brand-assets/'.$asset->id.'/promote', [
                'type' => 'reference',
                'category' => 'photography',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('reference.tier', BrandReferenceAsset::TIER_REFERENCE);
        $response->assertJsonPath('reference.weight', 0.6);
        $response->assertJsonPath('reference.reference_promotion.kind', 'reference');

        $this->assertDatabaseHas('brand_reference_assets', [
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'tier' => BrandReferenceAsset::TIER_REFERENCE,
            'reference_type' => BrandReferenceAsset::REFERENCE_TYPE_STYLE,
        ]);
    }

    public function test_promote_accepts_optional_context_type(): void
    {
        $asset = $this->createAsset();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/api/brand-assets/'.$asset->id.'/promote', [
                'type' => 'reference',
                'context_type' => 'lifestyle',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('brand_reference_assets', [
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'context_type' => 'lifestyle',
        ]);
    }

    public function test_duplicate_promote_returns_validation_error(): void
    {
        $asset = $this->createAsset();

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/api/brand-assets/'.$asset->id.'/promote', ['type' => 'guideline'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/api/brand-assets/'.$asset->id.'/promote', ['type' => 'reference'])
            ->assertStatus(422);
    }
}
