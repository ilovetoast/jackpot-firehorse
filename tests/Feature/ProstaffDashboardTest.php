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
use App\Models\BrandInvitation;
use App\Models\Category;
use App\Models\ProstaffPeriodStat;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Prostaff\AssignProstaffMember;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProstaffDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected StorageBucket $bucket;

    protected User $manager;

    protected User $prostaffA;

    protected User $prostaffB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'UTC'));

        foreach (['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create(['name' => 'Dash Co', 'slug' => 'dash-co']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dash Brand',
            'slug' => 'dash-brand',
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
            'name' => 'dash-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->manager = User::factory()->create();
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->manager->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $adminSpatie = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminSpatie->syncPermissions(Permission::all());
        $this->manager->assignRole($adminSpatie);

        $this->prostaffA = $this->makeProstaffUser('prostaff-a@example.com', 'Pro', 'StaffA');
        $this->prostaffB = $this->makeProstaffUser('prostaff-b@example.com', 'Pro', 'StaffB');

        app(AssignProstaffMember::class)->assign($this->prostaffA, $this->brand, [
            'target_uploads' => 10,
            'period_type' => 'month',
        ]);
        app(AssignProstaffMember::class)->assign($this->prostaffB, $this->brand, [
            'target_uploads' => 8,
            'period_type' => 'month',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function sessionFor(User $user): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
        ];
    }

    protected function makeProstaffUser(string $email, string $first, string $last): User
    {
        $user = User::create([
            'email' => $email,
            'password' => bcrypt('password'),
            'first_name' => $first,
            'last_name' => $last,
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $contribRole = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $contribRole->syncPermissions(Permission::whereIn('name', ['view brand', 'asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive'])->get());
        $user->assignRole($contribRole);

        return $user->fresh();
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

    public function test_manager_dashboard_lists_all_active_prostaff(): void
    {
        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        $rows = $response->json('active');
        $this->assertIsArray($rows);
        $this->assertSame([], $response->json('pending_invitations'));
        $this->assertCount(2, $rows);
        $ids = collect($rows)->pluck('user_id')->sort()->values()->all();
        $this->assertSame([(int) $this->prostaffA->id, (int) $this->prostaffB->id], $ids);
    }

    public function test_manager_dashboard_lists_pending_creator_invites(): void
    {
        BrandInvitation::create([
            'brand_id' => $this->brand->id,
            'email' => 'pending-creator@example.com',
            'role' => 'contributor',
            'metadata' => [
                'assign_prostaff_after_accept' => true,
                'prostaff_target_uploads' => 12,
                'prostaff_period_type' => 'quarter',
            ],
            'token' => Str::random(64),
            'invited_by' => $this->manager->id,
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        $pending = $response->json('pending_invitations');
        $this->assertCount(1, $pending);
        $this->assertSame('pending-creator@example.com', $pending[0]['email']);
        $this->assertSame(12, $pending[0]['target_uploads']);
        $this->assertSame('quarter', $pending[0]['period_type']);
        $this->assertSame('pending_invite', $pending[0]['status']);
    }

    public function test_manager_dashboard_includes_last_upload_at_from_prostaff_assets(): void
    {
        $upload = $this->createUploadSession();
        $ts = Carbon::parse('2026-04-01 15:30:00', 'UTC');

        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffA->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Creator upload',
            'original_filename' => 'c.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/c.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => $ts,
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffA->id,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        $rowA = collect($response->json('active'))->firstWhere('user_id', (int) $this->prostaffA->id);
        $rowB = collect($response->json('active'))->firstWhere('user_id', (int) $this->prostaffB->id);
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertNotNull($rowA['last_upload_at']);
        $this->assertNull($rowB['last_upload_at']);
    }

    public function test_manager_dashboard_stats_match_period_stat_row(): void
    {
        $membership = $this->prostaffA->activeProstaffMembership($this->brand);
        $this->assertNotNull($membership);

        $monthStart = Carbon::parse('2026-05-10')->startOfMonth()->toDateString();
        $monthEnd = Carbon::parse('2026-05-10')->endOfMonth()->toDateString();

        ProstaffPeriodStat::query()->updateOrCreate(
            [
                'prostaff_membership_id' => $membership->id,
                'period_type' => 'month',
                'period_start' => $monthStart,
            ],
            [
                'period_end' => $monthEnd,
                'target_uploads' => 10,
                'actual_uploads' => 3,
                'completion_percentage' => '30.00',
            ]
        );

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        $row = collect($response->json('active'))->firstWhere('user_id', (int) $this->prostaffA->id);
        $this->assertNotNull($row);
        $this->assertSame(10, $row['target_uploads']);
        $this->assertSame(3, $row['actual_uploads']);
        $this->assertEqualsWithDelta(30.0, (float) $row['completion_percentage'], 0.01);
        $this->assertFalse($row['is_on_track']);
        $this->assertSame('behind', $row['status']);
        $this->assertSame('month', $row['period_type']);
        $this->assertSame($monthStart, $row['period_start']);
        $this->assertSame($monthEnd, $row['period_end']);
        $this->assertArrayHasKey('rank', $row);
        $this->assertIsInt($row['rank']);
    }

    public function test_manager_dashboard_normalizes_missing_period_stat_to_zeros(): void
    {
        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        foreach ($response->json('active') as $row) {
            $this->assertArrayHasKey('actual_uploads', $row);
            $this->assertArrayHasKey('completion_percentage', $row);
            $this->assertArrayHasKey('is_on_track', $row);
            $this->assertArrayHasKey('last_upload_at', $row);
            $this->assertSame(0, $row['actual_uploads']);
            $this->assertEqualsWithDelta(0.0, (float) $row['completion_percentage'], 0.001);
            $this->assertFalse($row['is_on_track']);
            $this->assertSame('behind', $row['status']);
        }
    }

    public function test_manager_dashboard_rank_orders_by_completion_then_user_id(): void
    {
        $membershipA = $this->prostaffA->activeProstaffMembership($this->brand);
        $membershipB = $this->prostaffB->activeProstaffMembership($this->brand);
        $this->assertNotNull($membershipA);
        $this->assertNotNull($membershipB);

        $monthStart = Carbon::parse('2026-05-10')->startOfMonth()->toDateString();
        $monthEnd = Carbon::parse('2026-05-10')->endOfMonth()->toDateString();

        ProstaffPeriodStat::query()->updateOrCreate(
            [
                'prostaff_membership_id' => $membershipA->id,
                'period_type' => 'month',
                'period_start' => $monthStart,
            ],
            [
                'period_end' => $monthEnd,
                'target_uploads' => 10,
                'actual_uploads' => 2,
                'completion_percentage' => '20.00',
            ]
        );

        ProstaffPeriodStat::query()->updateOrCreate(
            [
                'prostaff_membership_id' => $membershipB->id,
                'period_type' => 'month',
                'period_start' => $monthStart,
            ],
            [
                'period_end' => $monthEnd,
                'target_uploads' => 10,
                'actual_uploads' => 8,
                'completion_percentage' => '80.00',
            ]
        );

        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertOk();
        $rows = $response->json('active');
        $this->assertCount(2, $rows);
        $this->assertSame((int) $this->prostaffB->id, $rows[0]['user_id']);
        $this->assertSame(1, $rows[0]['rank']);
        $this->assertSame((int) $this->prostaffA->id, $rows[1]['user_id']);
        $this->assertSame(2, $rows[1]['rank']);
    }

    public function test_prostaff_contributor_cannot_access_manager_creators_dashboard_json(): void
    {
        $response = $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");

        $response->assertForbidden();
        $response->assertJsonFragment(['error' => 'You do not have permission to view the prostaff dashboard.']);
    }

    public function test_prostaff_contributor_cannot_open_creators_list_inertia_page(): void
    {
        $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->get("/app/brands/{$this->brand->id}/creators")
            ->assertForbidden();
    }

    public function test_manager_can_view_creator_profile_inertia(): void
    {
        $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->get("/app/brands/{$this->brand->id}/creators/{$this->prostaffA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Prostaff/CreatorProfile')
                ->has('creator')
                ->has('performance')
                ->has('rejections')
                ->has('membership')
                ->where('creator.id', (int) $this->prostaffA->id)
                ->where('canManageCreators', true));
    }

    public function test_manager_can_view_creator_profile_when_user_no_longer_on_tenant_but_still_prostaff(): void
    {
        $this->prostaffA->tenants()->detach();

        $this->assertFalse($this->prostaffA->fresh()->belongsToTenant($this->tenant->id));

        $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->get("/app/brands/{$this->brand->id}/creators/{$this->prostaffA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Prostaff/CreatorProfile')
                ->where('creator.id', (int) $this->prostaffA->id));
    }

    public function test_prostaff_can_view_own_creator_profile(): void
    {
        $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->get("/app/brands/{$this->brand->id}/creators/{$this->prostaffA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Prostaff/CreatorProfile')
                ->where('canManageCreators', false));
    }

    public function test_prostaff_can_open_cinematic_creator_progress_overview(): void
    {
        $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->get('/app/overview/creator-progress')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Overview/CreatorProgress')
                ->has('creator_home')
                ->where('creator.id', (int) $this->prostaffA->id)
                ->where('canManageCreators', false));
    }

    public function test_non_prostaff_redirected_from_creator_progress(): void
    {
        $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->get('/app/overview/creator-progress')
            ->assertRedirect(route('overview'));
    }

    public function test_prostaff_cannot_view_peer_creator_profile(): void
    {
        $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->get("/app/brands/{$this->brand->id}/creators/{$this->prostaffB->id}")
            ->assertForbidden();
    }

    public function test_asset_grid_filters_submitted_by_prostaff_and_prostaff_user_id(): void
    {
        $upload = $this->createUploadSession();

        $plain = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->manager->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Plain',
            'original_filename' => 'plain.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/plain.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'submitted_by_prostaff' => false,
            'prostaff_user_id' => null,
        ]);

        $assetA = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffA->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pro A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/a.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffA->id,
        ]);

        $assetB = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffB->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pro B',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/b.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffB->id,
        ]);

        $base = '/app/assets?category=logos&format=json';

        $all = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson($base)
            ->assertOk()
            ->json('assets');
        $allIds = collect($all)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $plain->id, $allIds);
        $this->assertContains((int) $assetA->id, $allIds);
        $this->assertContains((int) $assetB->id, $allIds);

        $proOnly = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson($base.'&submitted_by_prostaff=1')
            ->assertOk()
            ->json('assets');
        $proIds = collect($proOnly)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([(int) $assetA->id, (int) $assetB->id], $proIds);

        $aOnly = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson($base.'&submitted_by_prostaff=1&prostaff_user_id='.$this->prostaffA->id)
            ->assertOk()
            ->json('assets');
        $this->assertCount(1, $aOnly);
        $this->assertSame((int) $assetA->id, (int) $aOnly[0]['id']);
    }

    public function test_prostaff_me_returns_only_current_user_prostaff_uploads(): void
    {
        $upload = $this->createUploadSession();

        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffA->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Mine',
            'original_filename' => 'mine.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/mine.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffA->id,
        ]);

        Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->prostaffB->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Theirs',
            'original_filename' => 'theirs.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'assets/theirs.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => true,
            'prostaff_user_id' => $this->prostaffB->id,
        ]);

        $response = $this->actingAs($this->prostaffA)
            ->withSession($this->sessionFor($this->prostaffA))
            ->getJson('/app/api/prostaff/me?brand_id='.$this->brand->id);

        $response->assertOk();
        $uploads = $response->json('uploads');
        $this->assertCount(1, $uploads);
        $this->assertSame('Mine', Asset::find((int) $uploads[0]['asset_id'])->title);
        $response->assertJsonStructure([
            'pending_assets',
            'rejected_assets',
            'approved_assets',
            'uploads_remaining',
            'creator_rank_percentile',
            'creator_rank_position',
            'total_creators',
        ]);
    }

    public function test_non_prostaff_prostaff_me_returns_eligible_false(): void
    {
        $response = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson('/app/api/prostaff/me?brand_id='.$this->brand->id);

        $response->assertOk();
        $response->assertJson(['eligible' => false, 'prostaff' => false]);
    }
}
