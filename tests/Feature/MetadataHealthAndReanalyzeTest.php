<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\BrandComplianceScore;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Metadata health and reanalyze endpoint tests.
 */
class MetadataHealthAndReanalyzeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;
    protected UploadSession $uploadSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Image Category',
            'slug' => 'image-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    /**
     * Asset missing dominant_colors → metadata_health.is_complete is false.
     */
    public function test_metadata_health_detects_missing_dominant_color(): void
    {
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/assets/{$asset->id}/metadata/editable");

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('metadata_health', $data);
        $health = $data['metadata_health'];
        $this->assertFalse($health['dominant_colors']);
        $this->assertFalse($health['is_complete']);
    }

    /**
     * POST reanalyze → timeline event exists.
     */
    public function test_rerun_analysis_creates_timeline_event(): void
    {
        Queue::fake();

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/reanalyze");

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);

        $event = ActivityEvent::where('subject_type', Asset::class)
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_ANALYSIS_RERUN_REQUESTED)
            ->first();

        $this->assertNotNull($event, 'Timeline event analysis_rerun_requested should exist');
    }

    /**
     * Re-run analysis clears previous BrandComplianceScore and debug_snapshot.
     */
    public function test_reanalysis_clears_previous_score_and_snapshot(): void
    {
        Queue::fake();

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'analysis_status' => 'complete',
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
            ],
        ]);

        BrandComplianceScore::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'overall_score' => 75,
            'color_score' => 80,
            'typography_score' => 70,
            'tone_score' => 75,
            'imagery_score' => 72,
            'evaluation_status' => 'evaluated',
            'debug_snapshot' => [
                'analysis_status' => 'complete',
                'overall_score' => 75,
                'applicable_dimensions' => ['color' => true, 'imagery' => true],
            ],
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/reanalyze");

        $this->assertDatabaseMissing('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
        ]);

        $asset->refresh();
        $this->assertSame('generating_thumbnails', $asset->analysis_status);
        $this->assertSame(ThumbnailStatus::PENDING, $asset->thumbnail_status);
    }
}
