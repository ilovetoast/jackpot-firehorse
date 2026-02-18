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
use App\Models\SupportTicket;
use App\Models\SystemIncident;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * System Auto-Recovery Command Tests
 *
 * test_auto_recover_resolves_repairable_incident
 * test_auto_recover_creates_ticket_for_unrepairable_incident
 */
class SystemAutoRecoverTest extends TestCase
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
            'metadata' => [],
        ], $overrides));
    }

    public function test_auto_recover_resolves_repairable_incident(): void
    {
        $asset = $this->createAsset([
            'analysis_status' => 'uploading',
            'metadata' => [
                'metadata_extracted' => true,
                'pipeline_completed_at' => now()->toIso8601String(),
                'thumbnails_generated' => true,
            ],
        ]);

        $incident = SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'error',
            'title' => 'Asset stuck in uploading state',
            'message' => 'Test',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
        ]);

        $this->artisan('system:auto-recover')->assertSuccessful();

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
        $this->assertTrue($incident->auto_resolved);
        $this->assertTrue($incident->metadata['auto_recovered'] ?? false);
    }

    public function test_auto_recover_creates_ticket_for_unrepairable_incident(): void
    {
        $asset = $this->createAsset([
            'analysis_status' => 'uploading',
            'metadata' => ['processing_started' => true],
        ]);

        SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'error',
            'title' => 'Asset stuck in uploading state',
            'message' => 'Test',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
        ]);

        $this->assertSame(0, SupportTicket::where('source_type', 'asset')->where('source_id', $asset->id)->count());

        $this->artisan('system:auto-recover')->assertSuccessful();

        $this->assertSame(1, SupportTicket::where('source_type', 'asset')->where('source_id', $asset->id)->count());
    }
}
