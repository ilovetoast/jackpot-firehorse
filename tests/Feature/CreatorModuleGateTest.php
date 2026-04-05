<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProstaffMembership;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\FeatureGate;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreatorModuleGateTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $manager;

    protected User $contributor;

    protected Category $category;

    protected StorageBucket $bucket;

    protected UploadCompletionService $completionService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        foreach (['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create(['name' => 'Gate Co', 'slug' => 'gate-co']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gate Brand',
            'slug' => 'gate-brand',
        ]);

        $this->manager = User::factory()->create();
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->manager->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());
        $this->manager->assignRole($adminRole);

        $this->contributor = User::create([
            'email' => 'contrib-gate@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'User',
        ]);
        $this->contributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributor->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $contribRole = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $contribRole->syncPermissions(Permission::whereIn('name', ['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval'])->get());
        $this->contributor->assignRole($contribRole);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Cat',
            'slug' => 'cat-gate',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'gate-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->completionService = new UploadCompletionService($this->createS3Mock());

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    protected function sessionFor(User $user): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
        ];
    }

    protected function createS3Mock(int $fileSize = 1024): S3Client
    {
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('doesObjectExist')->andReturn(true);

        $headResult = Mockery::mock(Result::class);
        $headResult->shouldReceive('get')->with('ContentLength')->andReturn($fileSize);
        $headResult->shouldReceive('get')->with('ContentType')->andReturn('image/jpeg');
        $headResult->shouldReceive('get')->with('ContentDisposition')->andReturn(null);
        $headResult->shouldReceive('get')->with('Metadata')->andReturn([]);
        $headResult->shouldReceive('get')->with('ETag')->andReturn('"etag-test"');

        $s3Client->shouldReceive('headObject')->andReturn($headResult);
        $s3Client->shouldReceive('copyObject')->andReturn(new Result);

        return $s3Client;
    }

    public function test_feature_gate_active_module_without_expiry(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant, ['status' => 'active', 'expires_at' => null]);

        $this->assertTrue(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_feature_gate_trial_module(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant, ['status' => 'trial']);

        $this->assertTrue(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_feature_gate_expired_status_denied(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant, [
            'status' => 'expired',
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_feature_gate_past_expires_at_denied(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant, [
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_feature_gate_admin_grant_requires_expires_at_on_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin-granted modules must set expires_at.');

        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_key' => TenantModule::KEY_CREATOR,
            'status' => 'active',
            'expires_at' => null,
            'granted_by_admin' => true,
        ]);
    }

    public function test_feature_gate_admin_grant_works_until_expiry(): void
    {
        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_key' => TenantModule::KEY_CREATOR,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'granted_by_admin' => true,
        ]);

        $this->assertTrue(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_feature_gate_invalid_admin_row_without_expiry_in_db_denied(): void
    {
        DB::table('tenant_modules')->insert([
            'tenant_id' => $this->tenant->id,
            'module_key' => TenantModule::KEY_CREATOR,
            'status' => 'active',
            'expires_at' => null,
            'granted_by_admin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(FeatureGate::class)->creatorModuleEnabled($this->tenant));
    }

    public function test_assign_prostaff_throws_when_module_disabled(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Creator module is not active for this tenant.');

        app(AssignProstaffMember::class)->assign($this->contributor, $this->brand, []);
    }

    public function test_dashboard_forbidden_when_module_disabled(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant);
        app(AssignProstaffMember::class)->assign($this->contributor, $this->brand, []);

        TenantModule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->update(['status' => 'cancelled']);

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertForbidden();
    }

    public function test_prostaff_options_empty_when_module_disabled(): void
    {
        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/options");

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_prostaff_finalize_upload_blocked_without_module(): void
    {
        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributor->id,
            'status' => 'active',
            'target_uploads' => 10,
            'period_type' => 'month',
            'requires_approval' => false,
            'started_at' => now(),
        ]);
        $this->contributor->forgetActiveBrandMembershipForBrand($this->brand);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Creator module is not active for this tenant.');

        $this->completionService->complete(
            $uploadSession,
            'asset',
            'shot.jpg',
            'Shot',
            'temp/uploads/'.$uploadSession->id.'/original',
            $this->category->id,
            ['fields' => ['caption' => 'x']],
            $this->contributor->id
        );
    }
}
