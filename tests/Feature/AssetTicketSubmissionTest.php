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
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Ticket Submission Test
 *
 * P8: Verifies submit-ticket endpoint returns complete payload and correct structure.
 */
class AssetTicketSubmissionTest extends TestCase
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
            'thumbnail_status' => ThumbnailStatus::FAILED,
            'metadata' => [],
        ], $overrides));
    }

    public function test_asset_ticket_submission_payload_is_complete(): void
    {
        $asset = $this->createAsset();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/submit-ticket");

        $response->assertOk();

        $data = $response->json();

        $this->assertArrayHasKey('ticket', $data);
        $this->assertArrayHasKey('incidents', $data);
        $this->assertArrayHasKey('last_failed_job', $data);

        $ticket = $data['ticket'];
        $this->assertTrue($ticket['source'] === 'manual', 'ticket.source must be manual');
        $this->assertFalse($ticket['auto_created'] ?? true, 'ticket.auto_created must be false');

        $this->assertIsArray($data['incidents']);

        $this->assertTrue(
            $data['last_failed_job'] === null || is_array($data['last_failed_job']),
            'last_failed_job must be null or array'
        );

        $payload = $ticket['payload'] ?? [];
        $requiredKeys = [
            'asset_id',
            'tenant_id',
            'brand_id',
            'analysis_status',
            'thumbnail_status',
            'thumbnail_error',
            'metadata',
            'thumbnail_retry_count',
            'recent_incidents',
            'last_failed_job',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "Payload must include key: {$key}");
        }

        $this->assertIsArray($payload['recent_incidents']);
        $this->assertArrayHasKey('pipeline_completed_at', $payload['metadata']);
        $this->assertArrayHasKey('thumbnail_timeout', $payload['metadata']);
        $this->assertArrayHasKey('thumbnail_timeout_reason', $payload['metadata']);
    }

    public function test_asset_ticket_submission_is_idempotent(): void
    {
        $asset = $this->createAsset();

        $response1 = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/submit-ticket");

        $response1->assertOk();
        $ticketId1 = $response1->json('ticket.id');

        $response2 = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/submit-ticket");

        $response2->assertOk();
        $ticketId2 = $response2->json('ticket.id');

        $this->assertSame($ticketId1, $ticketId2, 'Second submission should return same ticket (idempotent)');
        $this->assertSame(1, SupportTicket::where('source_type', 'asset')->where('source_id', $asset->id)->count());
    }
}
