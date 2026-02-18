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
use App\Services\Reliability\EscalationPolicy;
use App\Services\Reliability\ReliabilityEngine;
use App\Services\Reliability\ReliabilityMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reliability Engine Tests
 *
 * - report creates incident
 * - repair strategy resolves incident
 * - escalation creates ticket
 * - repeated attempts trigger escalation
 * - metrics update correctly
 */
class ReliabilityEngineTest extends TestCase
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

    public function test_report_creates_incident(): void
    {
        $asset = $this->createAsset();

        $engine = app(ReliabilityEngine::class);
        $incident = $engine->report([
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'error',
            'title' => 'Test incident',
            'message' => 'Test message',
            'retryable' => true,
        ]);

        $this->assertInstanceOf(SystemIncident::class, $incident);
        $this->assertNotNull($incident->id);
        $this->assertSame('asset', $incident->source_type);
        $this->assertSame($asset->id, $incident->source_id);
        $this->assertSame('error', $incident->severity);
        $this->assertSame('Test incident', $incident->title);
        $this->assertNull($incident->resolved_at);
    }

    public function test_repair_strategy_resolves_incident(): void
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

        $engine = app(ReliabilityEngine::class);
        $result = $engine->attemptRecovery($incident);

        $this->assertTrue($result['resolved']);
        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
        $this->assertTrue($incident->auto_resolved);
    }

    public function test_escalation_creates_ticket(): void
    {
        $asset = $this->createAsset(['analysis_status' => 'uploading',
            'metadata' => ['processing_started' => true],
        ]);

        SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'critical',
            'title' => 'Asset stuck in uploading state',
            'message' => 'Test',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
        ]);

        $engine = app(ReliabilityEngine::class);
        $incident = SystemIncident::whereNull('resolved_at')->first();
        $ticket = $engine->escalate($incident);

        $this->assertNotNull($ticket);
        $this->assertSame('operations_incident', $ticket->metadata['source'] ?? null);
        $this->assertSame($asset->id, $ticket->metadata['asset_id'] ?? null);
    }

    public function test_repeated_attempts_trigger_escalation(): void
    {
        $asset = $this->createAsset([
            'analysis_status' => 'uploading',
            'metadata' => ['processing_started' => true],
        ]);

        $incident = SystemIncident::create([
            'id' => (string) Str::uuid(),
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $this->tenant->id,
            'severity' => 'warning',
            'title' => 'Asset stuck in uploading state',
            'message' => 'Test',
            'retryable' => true,
            'resolved_at' => null,
            'detected_at' => now(),
            'metadata' => ['repair_attempts' => 3],
        ]);

        $policy = app(EscalationPolicy::class);
        $this->assertTrue($policy->shouldCreateTicket($incident));
    }

    public function test_metrics_update_correctly(): void
    {
        $metricsService = app(ReliabilityMetricsService::class);
        $all = $metricsService->getAll();

        $this->assertArrayHasKey('integrity', $all);
        $this->assertArrayHasKey('mttr', $all);
        $this->assertArrayHasKey('recovery_success', $all);
        $this->assertArrayHasKey('ticket_escalation', $all);

        $this->assertArrayHasKey('rate_percent', $all['integrity']);
        $this->assertArrayHasKey('mttr_minutes_avg', $all['mttr']);
        $this->assertArrayHasKey('recovery_rate_percent', $all['recovery_success']);
        $this->assertArrayHasKey('unresolved_count', $all['ticket_escalation']);
    }
}
