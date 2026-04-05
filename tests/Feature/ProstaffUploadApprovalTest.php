<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Http\Controllers\AssetApprovalController;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Mockery;
use Tests\TestCase;

class ProstaffUploadApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $categoryNoApproval;

    protected StorageBucket $bucket;

    protected UploadCompletionService $completionService;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->completionService = new UploadCompletionService($this->createS3Mock());

        $this->tenant = Tenant::create([
            'name' => 'T Prostaff Upload',
            'slug' => 't-prostaff-upload',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand PU',
            'slug' => 'brand-pu',
            'settings' => [
                'contributor_upload_requires_approval' => false,
            ],
        ]);

        $this->enableCreatorModuleForTenant($this->tenant);

        $this->categoryNoApproval = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'No Approval Cat',
            'slug' => 'no-approval-cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket-pu',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    protected function createProstaffUser(): User
    {
        $user = User::create([
            'email' => 'prostaff-uploader@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pro',
            'last_name' => 'Staff',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        app(AssignProstaffMember::class)->assign($user, $this->brand, []);

        return $user->fresh();
    }

    protected function createRegularContributor(): User
    {
        $user = User::create([
            'email' => 'regular@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Reg',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        return $user;
    }

    protected function createApprover(): User
    {
        $user = User::create([
            'email' => 'approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Ap',
            'last_name' => 'Prover',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'brand_manager',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        return $user;
    }

    protected function completeUploadForUser(User $user): Asset
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return $this->completionService->complete(
            $uploadSession,
            'asset',
            'shot.jpg',
            'Shot',
            'temp/uploads/'.$uploadSession->id.'/original',
            $this->categoryNoApproval->id,
            ['fields' => ['caption' => 'Lake view']],
            $user->id
        );
    }

    public function test_prostaff_upload_is_always_pending_and_tagged(): void
    {
        $user = $this->createProstaffUser();
        $asset = $this->completeUploadForUser($user);
        $asset->refresh();

        $this->assertSame(ApprovalStatus::PENDING, $asset->approval_status);
        $this->assertNull($asset->published_at);
        $this->assertNull($asset->published_by_id);
        $this->assertTrue($asset->submitted_by_prostaff);
        $this->assertTrue($asset->isProstaffAsset());
        $this->assertSame($user->id, $asset->prostaff_user_id);
    }

    public function test_prostaff_user_id_is_immutable_after_set(): void
    {
        $prostaff = $this->createProstaffUser();
        $other = $this->createRegularContributor();
        $asset = $this->completeUploadForUser($prostaff);
        $asset->refresh();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('prostaff_user_id is immutable once set');

        $asset->prostaff_user_id = $other->id;
        $asset->save();
    }

    public function test_non_prostaff_upload_unchanged_auto_publish_when_rules_allow(): void
    {
        $user = $this->createRegularContributor();
        $asset = $this->completeUploadForUser($user);
        $asset->refresh();

        $this->assertSame(ApprovalStatus::NOT_REQUIRED, $asset->approval_status);
        $this->assertNotNull($asset->published_at);
        $this->assertFalse($asset->submitted_by_prostaff);
        $this->assertNull($asset->prostaff_user_id);
    }

    public function test_approve_sets_approved_and_preserves_metadata(): void
    {
        $prostaff = $this->createProstaffUser();
        $asset = $this->completeUploadForUser($prostaff);
        $asset->refresh();

        $approver = $this->createApprover();
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($approver);
        app()->instance('tenant', $this->tenant);

        $request = Request::create('/noop', 'POST', ['title' => 'Approved title']);
        $request->setUserResolver(fn () => $approver);

        $response = app(AssetApprovalController::class)->approve($request, $this->brand, $asset);
        $this->assertSame(200, $response->getStatusCode());

        $asset->refresh();
        $this->assertSame(ApprovalStatus::APPROVED, $asset->approval_status);
        $this->assertNotNull($asset->published_at);
        $this->assertSame('Lake view', $asset->metadata['fields']['caption'] ?? null);
        $this->assertSame('Approved title', $asset->title);
    }

    public function test_reject_saves_reason_and_status(): void
    {
        $prostaff = $this->createProstaffUser();
        $asset = $this->completeUploadForUser($prostaff);
        $asset->refresh();

        $approver = $this->createApprover();
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($approver);
        app()->instance('tenant', $this->tenant);

        $reason = 'Needs better composition and metadata alignment.';
        $request = Request::create('/noop', 'POST', ['rejection_reason' => $reason]);
        $request->setUserResolver(fn () => $approver);

        $response = app(AssetApprovalController::class)->reject($request, $this->brand, $asset);
        $this->assertSame(200, $response->getStatusCode());

        $asset->refresh();
        $this->assertSame(ApprovalStatus::REJECTED, $asset->approval_status);
        $this->assertSame($reason, $asset->rejection_reason);
        $this->assertNull($asset->published_at);
        $this->assertSame('Lake view', $asset->metadata['fields']['caption'] ?? null);
    }

    public function test_resubmit_returns_to_pending_same_asset(): void
    {
        $prostaff = $this->createProstaffUser();
        $asset = $this->completeUploadForUser($prostaff);
        $assetId = $asset->id;

        $approver = $this->createApprover();
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        app()->instance('tenant', $this->tenant);

        $rejectRequest = Request::create('/noop', 'POST', [
            'rejection_reason' => 'First rejection reason text here minimum.',
        ]);
        $rejectRequest->setUserResolver(fn () => $approver);
        app(AssetApprovalController::class)->reject($rejectRequest, $this->brand, $asset->fresh());

        $asset->refresh();
        $this->assertSame(ApprovalStatus::REJECTED, $asset->approval_status);

        $this->actingAs($prostaff);
        $resubmitRequest = Request::create('/noop', 'POST', ['comment' => 'Fixed per feedback']);
        $resubmitRequest->setUserResolver(fn () => $prostaff);

        $res = app(AssetApprovalController::class)->resubmit($resubmitRequest, $this->brand, $asset->fresh());
        $this->assertSame(200, $res->getStatusCode());

        $asset->refresh();
        $this->assertSame($assetId, $asset->id);
        $this->assertSame(ApprovalStatus::PENDING, $asset->approval_status);
        $this->assertNull($asset->rejection_reason);
        $this->assertSame('Lake view', $asset->metadata['fields']['caption'] ?? null);
    }

    public function test_prostaff_cannot_publish_pending_asset_via_policy(): void
    {
        $prostaff = $this->createProstaffUser();
        $asset = $this->completeUploadForUser($prostaff);
        $asset->refresh();

        $this->assertFalse($prostaff->can('publish', $asset));
    }
}
