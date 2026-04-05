<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProstaffMembership;
use App\Models\ProstaffPeriodStat;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\Prostaff\ResolveProstaffPeriod;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProstaffPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected StorageBucket $bucket;

    protected UploadCompletionService $completionService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-15 14:00:00', 'UTC'));

        Queue::fake();

        $this->completionService = new UploadCompletionService($this->createS3Mock());

        $this->tenant = Tenant::create([
            'name' => 'T Prostaff Perf',
            'slug' => 't-prostaff-perf',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Perf',
            'slug' => 'brand-perf',
            'settings' => [
                'contributor_upload_requires_approval' => false,
            ],
        ]);

        $this->enableCreatorModuleForTenant($this->tenant);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Perf Cat',
            'slug' => 'perf-cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket-perf',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
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

    protected function createProstaffUserWithTarget(int $target, string $periodType = 'month'): User
    {
        $user = User::create([
            'email' => 'prostaff-perf-'.uniqid('', true).'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pro',
            'last_name' => 'Perf',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        app(AssignProstaffMember::class)->assign($user, $this->brand, [
            'target_uploads' => $target,
            'period_type' => $periodType,
        ]);

        return $user->fresh();
    }

    protected function completeOneUpload(User $user): void
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

        $this->completionService->complete(
            $uploadSession,
            'asset',
            'shot.jpg',
            'Shot',
            'temp/uploads/'.$uploadSession->id.'/original',
            $this->category->id,
            ['fields' => ['caption' => 'x']],
            $user->id
        );
    }

    public function test_upload_increments_actual_uploads_for_configured_period(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $membership = $user->activeProstaffMembership($this->brand);
        $this->assertNotNull($membership);

        $this->completeOneUpload($user);

        $monthStart = Carbon::parse('2026-05-15')->startOfMonth()->toDateString();

        $monthRow = ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->whereDate('period_start', $monthStart)
            ->first();

        $this->assertNotNull($monthRow);
        $this->assertSame(1, $monthRow->actual_uploads);
    }

    public function test_completion_percentage_matches_target(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $membership = $user->activeProstaffMembership($this->brand);

        $this->completeOneUpload($user);
        $this->completeOneUpload($user);
        $this->completeOneUpload($user);

        $monthStart = Carbon::parse('2026-05-15')->startOfMonth()->toDateString();

        $monthRow = ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->whereDate('period_start', $monthStart)
            ->first();

        $this->assertSame(3, $monthRow->actual_uploads);
        $this->assertSame(10, $monthRow->target_uploads);
        $this->assertEqualsWithDelta(30.0, (float) $monthRow->completion_percentage, 0.001);
        $this->assertNotNull($monthRow->last_calculated_at);
        $this->assertFalse($monthRow->isOnTrack());
    }

    public function test_resolve_period_month_quarter_year_boundaries(): void
    {
        $user = $this->createProstaffUserWithTarget(5, 'month');
        $membership = $user->activeProstaffMembership($this->brand);
        $resolver = app(ResolveProstaffPeriod::class);

        $date = Carbon::parse('2026-05-15');
        $all = $resolver->resolve($membership, $date);

        $this->assertSame('2026-05-01', $all['month']['period_start']->toDateString());
        $this->assertSame('2026-05-31', $all['month']['period_end']->toDateString());

        $this->assertSame('2026-04-01', $all['quarter']['period_start']->toDateString());
        $this->assertSame('2026-06-30', $all['quarter']['period_end']->toDateString());

        $this->assertSame('2026-01-01', $all['year']['period_start']->toDateString());
        $this->assertSame('2026-12-31', $all['year']['period_end']->toDateString());
    }

    public function test_multiple_period_types_accumulate_on_same_upload(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $membership = $user->activeProstaffMembership($this->brand);

        $this->completeOneUpload($user);

        $this->assertSame(3, ProstaffPeriodStat::query()->where('prostaff_membership_id', $membership->id)->count());

        $this->assertSame(1, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->value('actual_uploads'));
        $this->assertSame(1, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'quarter')
            ->value('actual_uploads'));
        $this->assertSame(1, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'year')
            ->value('actual_uploads'));
    }

    public function test_new_calendar_month_creates_new_row(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $membership = $user->activeProstaffMembership($this->brand);

        $this->completeOneUpload($user);

        Carbon::setTestNow(Carbon::parse('2026-06-02 10:00:00', 'UTC'));

        $this->completeOneUpload($user);

        $mayStart = Carbon::parse('2026-05-01')->toDateString();
        $juneStart = Carbon::parse('2026-06-01')->toDateString();

        $this->assertSame(1, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->whereDate('period_start', $mayStart)
            ->value('actual_uploads'));

        $this->assertSame(1, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->whereDate('period_start', $juneStart)
            ->value('actual_uploads'));

        $this->assertSame(2, ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->count());
    }

    public function test_membership_target_change_does_not_rewrite_existing_period_row(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $membership = $user->activeProstaffMembership($this->brand);

        $this->completeOneUpload($user);

        $monthStart = Carbon::parse('2026-05-15')->startOfMonth()->toDateString();
        $rowId = ProstaffPeriodStat::query()
            ->where('prostaff_membership_id', $membership->id)
            ->where('period_type', 'month')
            ->whereDate('period_start', $monthStart)
            ->value('id');

        ProstaffMembership::query()->whereKey($membership->id)->update(['target_uploads' => 99]);

        $this->completeOneUpload($user);

        $row = ProstaffPeriodStat::query()->findOrFail($rowId);
        $this->assertSame(10, $row->target_uploads);
        $this->assertSame(2, $row->actual_uploads);
        $this->assertEqualsWithDelta(20.0, (float) $row->completion_percentage, 0.001);
    }

    public function test_get_prostaff_stats_for_brand_returns_current_periods(): void
    {
        $user = $this->createProstaffUserWithTarget(10, 'month');
        $this->completeOneUpload($user);

        $payload = $user->fresh()->getProstaffStatsForBrand($this->brand);

        $this->assertNotNull($payload['membership']);
        $this->assertArrayHasKey('month', $payload['periods']);
        $this->assertSame(1, $payload['periods']['month']['actual_uploads']);
        $this->assertSame(10, $payload['periods']['month']['target_uploads']);
        $this->assertEqualsWithDelta(10.0, $payload['periods']['month']['completion_percentage'], 0.001);
    }
}
