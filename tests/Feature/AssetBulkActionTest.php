<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ProcessAssetJob;
use App\Jobs\RegenerateSystemMetadataQueuedJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Asset $asset1;
    protected Asset $asset2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'buck',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'brand_manager']);

        $this->asset1 = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'A1',
            'original_filename' => 'a1.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenant->id . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.jpg',
            'size_bytes' => 1024,
            'published_at' => null,
            'archived_at' => null,
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
        ]);
        $this->asset2 = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'A2',
            'original_filename' => 'a2.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenant->id . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original2.jpg',
            'size_bytes' => 1024,
            'published_at' => null,
            'archived_at' => null,
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
        ]);
    }

    public function test_bulk_publish(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id, $this->asset2->id],
                'action' => 'PUBLISH',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson([
            'total_selected' => 2,
            'processed' => 2,
            'errors' => [],
        ]);
        $this->asset1->refresh();
        $this->asset2->refresh();
        $this->assertNotNull($this->asset1->published_at);
        $this->assertNotNull($this->asset2->published_at);
        $this->assertEquals($this->user->id, $this->asset1->published_by_id);
    }

    public function test_bulk_archive(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'ARCHIVE',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson(['processed' => 1]);
        $this->asset1->refresh();
        $this->assertNotNull($this->asset1->archived_at);
    }

    public function test_bulk_reject_requires_reason(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'REJECT',
                'payload' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Rejection reason is required for REJECT action.']);
    }

    public function test_bulk_reject_with_reason_succeeds(): void
    {
        $this->asset1->update(['approval_status' => ApprovalStatus::PENDING]);
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'REJECT',
                'payload' => ['rejection_reason' => 'Not suitable'],
            ]);

        $response->assertOk();
        $this->asset1->refresh();
        $this->assertEquals(ApprovalStatus::REJECTED, $this->asset1->approval_status);
        $this->assertNotNull($this->asset1->rejected_at);
        $this->assertEquals('Not suitable', $this->asset1->rejection_reason);
    }

    public function test_bulk_soft_delete(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SOFT_DELETE',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson(['processed' => 1]);
        $this->asset1->refresh();
        $this->assertNotNull($this->asset1->deleted_at);
    }

    public function test_bulk_permission_respected(): void
    {
        $viewer = User::factory()->create();
        $viewer->tenants()->attach($this->tenant->id, ['role' => 'viewer']);
        $viewer->brands()->attach($this->brand->id, ['role' => 'viewer']);
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($viewer)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id, $this->asset2->id],
                'action' => 'PUBLISH',
                'payload' => [],
            ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals(2, $data['total_selected']);
        $this->assertEquals(0, $data['processed']);
        $this->assertEquals(2, $data['skipped']);
        $this->asset1->refresh();
        $this->asset2->refresh();
        $this->assertNull($this->asset1->published_at);
        $this->assertNull($this->asset2->published_at);
    }

    public function test_bulk_rename_assets_sets_title_and_filename(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id, $this->asset2->id],
                'action' => 'RENAME_ASSETS',
                'payload' => ['base_name' => 'Photo Shoot XY'],
            ]);

        $response->assertOk();
        $response->assertJson([
            'total_selected' => 2,
            'processed' => 2,
            'errors' => [],
        ]);

        $this->asset1->refresh();
        $this->asset2->refresh();
        $this->assertSame('Photo Shoot XY 1 of 2', $this->asset1->title);
        $this->assertSame('Photo Shoot XY 2 of 2', $this->asset2->title);
        $this->assertSame('photo-shoot-xy-1.jpg', $this->asset1->original_filename);
        $this->assertSame('photo-shoot-xy-2.jpg', $this->asset2->original_filename);
    }

    public function test_bulk_rename_requires_two_assets(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'RENAME_ASSETS',
                'payload' => ['base_name' => 'Test'],
            ]);

        $response->assertStatus(422);
    }

    public function test_site_rerun_thumbnails_forbidden_without_site_role(): void
    {
        Queue::fake();
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SITE_RERUN_THUMBNAILS',
                'payload' => [],
            ]);

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_site_rerun_thumbnails_queues_jobs_for_site_engineering(): void
    {
        Queue::fake();
        Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $this->user->assignRole('site_engineering');

        $this->asset1->update(['thumbnail_status' => ThumbnailStatus::COMPLETED]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SITE_RERUN_THUMBNAILS',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson([
            'total_selected' => 1,
            'processed' => 1,
            'errors' => [],
        ]);
        Queue::assertPushed(GenerateThumbnailsJob::class, 1);
    }

    public function test_site_rerun_ai_metadata_forbidden_without_site_role(): void
    {
        Bus::fake();
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SITE_RERUN_AI_METADATA_TAGGING',
                'payload' => [],
            ]);

        $response->assertStatus(403);
    }

    public function test_site_reprocess_system_metadata_queues_job_for_site_engineering(): void
    {
        Queue::fake();
        Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $this->user->assignRole('site_engineering');

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SITE_REPROCESS_SYSTEM_METADATA',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson([
            'total_selected' => 1,
            'processed' => 1,
            'errors' => [],
        ]);
        Queue::assertPushed(RegenerateSystemMetadataQueuedJob::class, 1);
    }

    public function test_site_reprocess_full_pipeline_queues_process_asset_job_for_site_engineering(): void
    {
        Queue::fake();
        Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $this->user->assignRole('site_engineering');
        $this->asset1->update(['thumbnail_status' => ThumbnailStatus::COMPLETED]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->user)
            ->postJson(route('assets.bulk-action'), [
                'asset_ids' => [$this->asset1->id],
                'action' => 'SITE_REPROCESS_FULL_PIPELINE',
                'payload' => [],
            ]);

        $response->assertOk();
        $response->assertJson([
            'total_selected' => 1,
            'processed' => 1,
            'errors' => [],
        ]);
        Queue::assertPushed(ProcessAssetJob::class, 1);
    }
}
