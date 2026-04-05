<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProstaffMembership;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\FeatureGate;
use App\Services\Prostaff\AssignProstaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProstaffFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected StorageBucket $bucket;

    protected User $manager;

    protected User $prostaffUser;

    protected User $plainContributor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create(['name' => 'Filter Co', 'slug' => 'filter-co']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Filter Brand',
            'slug' => 'filter-brand',
        ]);

        $this->enableCreatorModuleForTenant($this->tenant);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'filter-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->manager = User::factory()->create();
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->manager->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $adminSpatie = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminSpatie->syncPermissions(Permission::all());
        $this->manager->assignRole($adminSpatie);

        $this->prostaffUser = User::create([
            'email' => 'prostaff-filter@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pro',
            'last_name' => 'Staff',
        ]);
        $this->prostaffUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->prostaffUser->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $contribRole = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $contribRole->syncPermissions(Permission::whereIn('name', ['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive'])->get());
        $this->prostaffUser->assignRole($contribRole);
        app(AssignProstaffMember::class)->assign($this->prostaffUser, $this->brand, []);

        $this->plainContributor = User::create([
            'email' => 'plain-filter@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Plain',
            'last_name' => 'User',
        ]);
        $this->plainContributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->plainContributor->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $this->plainContributor->assignRole($contribRole);
    }

    protected function sessionFor(User $user): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
        ];
    }

    protected function createUploadSession(): UploadSession
    {
        return UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
    }

    public function test_filter_assets_by_submitted_by_prostaff(): void
    {
        $upload = $this->createUploadSession();

        $regular = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->manager->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Regular',
            'original_filename' => 'r.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'r.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => false,
            'prostaff_user_id' => null,
        ]);

        $pro = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffUser->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pro',
            'original_filename' => 'p.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'p.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffUser->id,
        ]);

        $base = '/app/assets?category=logos&format=json';
        $filtered = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson($base.'&submitted_by_prostaff=1')
            ->assertOk()
            ->json('assets');

        $ids = collect($filtered)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $pro->id, $ids);
        $this->assertNotContains((int) $regular->id, $ids);
    }

    public function test_filter_assets_by_prostaff_user_id(): void
    {
        $upload = $this->createUploadSession();

        $assetA = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffUser->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'a.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffUser->id,
        ]);

        $otherProstaff = User::create([
            'email' => 'other-pro@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'Pro',
        ]);
        $otherProstaff->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $otherProstaff->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $otherProstaff->assignRole(Role::where('name', 'contributor')->where('guard_name', 'web')->firstOrFail());
        app(AssignProstaffMember::class)->assign($otherProstaff, $this->brand, []);

        $assetB = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $otherProstaff->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'B',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'b.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $otherProstaff->id,
        ]);

        $base = '/app/assets?category=logos&format=json&submitted_by_prostaff=1';
        $onlyA = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson($base.'&prostaff_user_id='.$this->prostaffUser->id)
            ->assertOk()
            ->json('assets');

        $this->assertCount(1, $onlyA);
        $this->assertSame((int) $assetA->id, (int) $onlyA[0]['id']);
        $this->assertNotSame((int) $assetB->id, (int) $onlyA[0]['id']);
    }

    public function test_prostaff_options_endpoint_returns_active_members_only(): void
    {
        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/options");

        $response->assertOk();
        $rows = $response->json();
        $this->assertIsArray($rows);
        $ids = collect($rows)->pluck('user_id')->all();
        $this->assertContains((int) $this->prostaffUser->id, $ids);
        $this->assertNotContains((int) $this->plainContributor->id, $ids);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('user_id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function test_prostaff_options_excludes_non_active_membership(): void
    {
        $paused = User::create([
            'email' => 'paused-pro@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Paused',
            'last_name' => 'Member',
        ]);
        $paused->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $paused->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $paused->assignRole(Role::where('name', 'contributor')->where('guard_name', 'web')->firstOrFail());
        app(AssignProstaffMember::class)->assign($paused, $this->brand, []);

        ProstaffMembership::query()
            ->where('brand_id', $this->brand->id)
            ->where('user_id', $paused->id)
            ->update(['status' => 'paused']);

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/options");

        $response->assertOk();
        $ids = collect($response->json())->pluck('user_id')->all();
        $this->assertNotContains((int) $paused->id, $ids);
        $this->assertContains((int) $this->prostaffUser->id, $ids);
    }

    public function test_asset_json_includes_prostaff_fields_and_alias(): void
    {
        $upload = $this->createUploadSession();
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffUser->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Tagged',
            'original_filename' => 't.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 't.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffUser->id,
        ]);

        $payload = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson('/app/assets?category=logos&format=json')
            ->assertOk()
            ->json('assets');

        $row = collect($payload)->firstWhere('id', $asset->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['submitted_by_prostaff']);
        $this->assertSame((int) $this->prostaffUser->id, (int) $row['prostaff_user_id']);
        $this->assertSame('Pro Staff', $row['prostaff_user_name']);
        $this->assertTrue($row['is_prostaff_asset']);
        $this->assertSame($row['submitted_by_prostaff'], $row['is_prostaff_asset']);
    }

    public function test_pending_assets_includes_is_prostaff_asset(): void
    {
        $this->mock(FeatureGate::class, function ($mock) {
            $mock->shouldReceive('approvalsEnabled')->andReturn(true);
            $mock->shouldIgnoreMissing();
        });

        $upload = $this->createUploadSession();
        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffUser->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pending pro',
            'original_filename' => 'pp.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'pp.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => null,
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffUser->id,
        ]);

        $res = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/pending-assets");

        $res->assertOk();
        $first = $res->json('assets.0');
        $this->assertNotNull($first);
        $this->assertArrayHasKey('is_prostaff_asset', $first);
        $this->assertTrue($first['is_prostaff_asset']);
    }
}
