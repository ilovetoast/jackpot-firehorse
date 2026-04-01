<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\SystemIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemIncidentQueueDedupTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $user;

    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
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
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function createAsset(array $overrides = []): Asset
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

        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'uploading',
            'metadata' => [],
        ], $overrides));
    }

    public function test_record_or_refresh_by_signature_keeps_single_row_and_increments_count(): void
    {
        $asset = $this->createAsset();
        $svc = app(SystemIncidentService::class);
        $sig = 'queue_job_failure:ProcessAssetJob:'.$asset->id;

        $first = $svc->recordOrRefreshBySignature([
            'unique_signature' => $sig,
            'source_type' => 'job',
            'source_id' => $asset->id,
            'severity' => 'error',
            'title' => 'Job failed: ProcessAssetJob',
            'message' => 'first error',
            'retryable' => true,
            'metadata' => ['queue' => 'images'],
        ]);

        $second = $svc->recordOrRefreshBySignature([
            'unique_signature' => $sig,
            'source_type' => 'job',
            'source_id' => $asset->id,
            'severity' => 'error',
            'title' => 'Job failed: ProcessAssetJob',
            'message' => 'second error',
            'retryable' => true,
            'metadata' => ['queue' => 'images'],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SystemIncident::whereNull('resolved_at')->count());
        $second->refresh();
        $this->assertSame('second error', $second->message);
        $this->assertSame(2, (int) ($second->metadata['failure_count'] ?? 0));
        $this->assertNotNull($second->metadata['first_failed_at'] ?? null);
        $this->assertNotNull($second->metadata['last_failed_at'] ?? null);
    }

    public function test_record_or_refresh_preserves_detected_at_on_refresh(): void
    {
        $asset = $this->createAsset();
        $svc = app(SystemIncidentService::class);
        $sig = 'queue_job_failure:ExtractMetadataJob:'.$asset->id;

        $first = $svc->recordOrRefreshBySignature([
            'unique_signature' => $sig,
            'source_type' => 'job',
            'source_id' => $asset->id,
            'severity' => 'error',
            'title' => 'Job failed: ExtractMetadataJob',
            'message' => 'a',
            'retryable' => true,
            'metadata' => [],
        ]);
        $originalDetected = $first->fresh()->detected_at?->toIso8601String();

        $svc->recordOrRefreshBySignature([
            'unique_signature' => $sig,
            'source_type' => 'job',
            'source_id' => $asset->id,
            'severity' => 'error',
            'title' => 'Job failed: ExtractMetadataJob',
            'message' => 'b',
            'retryable' => true,
            'metadata' => [],
        ]);

        $first->refresh();
        $this->assertSame($originalDetected, $first->detected_at?->toIso8601String());
        $this->assertSame(2, (int) ($first->metadata['failure_count'] ?? 0));
    }

    public function test_asset_observer_auto_resolves_job_and_asset_incidents_when_complete(): void
    {
        $asset = $this->createAsset(['analysis_status' => 'uploading']);

        SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'job',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'error',
            'title' => 'Job failed: ProcessAssetJob',
            'message' => 'boom',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
            'metadata' => [],
        ]);

        SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'warning',
            'title' => 'Stuck',
            'message' => 'x',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
            'metadata' => [],
        ]);

        $asset->update(['analysis_status' => 'complete']);

        $this->assertSame(0, SystemIncident::whereNull('resolved_at')->where('source_id', $asset->id)->count());
        $this->assertSame(2, SystemIncident::whereNotNull('resolved_at')->where('source_id', $asset->id)->count());
        $this->assertTrue(
            SystemIncident::where('source_type', 'job')
                ->where('source_id', $asset->id)
                ->whereNotNull('resolved_at')
                ->where('auto_resolved', true)
                ->exists()
        );
    }
}
