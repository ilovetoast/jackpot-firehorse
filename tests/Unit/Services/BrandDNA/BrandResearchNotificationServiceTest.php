<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionInsightState;
use App\Models\BrandPipelineSnapshot;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BrandDNA\BrandResearchNotificationService;
use App\Services\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandResearchNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(FeatureGate::class, function ($mock) {
            $mock->shouldReceive('notificationsEnabled')->andReturn(true);
        });
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $this->user = User::create([
            'email' => 'creator@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Creator',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
    }

    public function test_notification_is_created_when_research_finalized(): void
    {
        $brandModel = BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => true,
        ]);
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        BrandPipelineSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'ingestion',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => [],
            'alignment' => [],
        ]);

        $state = BrandModelVersionInsightState::create([
            'brand_model_version_id' => $draft->id,
            'source_pipeline_snapshot_id' => null,
        ]);

        $service = app(BrandResearchNotificationService::class);
        $service->maybeNotifyResearchReady($this->brand, $draft);

        $notification = Notification::where('user_id', $this->user->id)
            ->where('type', 'brand_research.ready')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Brand research is ready', $notification->data['title'] ?? null);
        $this->assertStringContainsString('Test Brand', $notification->data['body'] ?? '');
        $this->assertArrayHasKey('action_url', $notification->data);

        $state->refresh();
        $this->assertNotNull($state->research_ready_notified_at);
    }

    public function test_duplicate_notifications_are_not_created(): void
    {
        $brandModel = BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => true,
        ]);
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        BrandPipelineSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'ingestion',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => [],
            'alignment' => [],
        ]);

        $state = BrandModelVersionInsightState::create([
            'brand_model_version_id' => $draft->id,
            'source_pipeline_snapshot_id' => null,
            'research_ready_notified_at' => now(),
        ]);

        $service = app(BrandResearchNotificationService::class);
        $service->maybeNotifyResearchReady($this->brand, $draft);

        $count = Notification::where('user_id', $this->user->id)
            ->where('type', 'brand_research.ready')
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_no_notification_when_draft_has_no_creator(): void
    {
        $brandModel = BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => true,
        ]);
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'status' => 'draft',
            'created_by' => null,
        ]);

        BrandPipelineSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'ingestion',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => [],
            'alignment' => [],
        ]);

        BrandModelVersionInsightState::create([
            'brand_model_version_id' => $draft->id,
            'source_pipeline_snapshot_id' => null,
        ]);

        $service = app(BrandResearchNotificationService::class);
        $service->maybeNotifyResearchReady($this->brand, $draft);

        $count = Notification::where('type', 'brand_research.ready')->count();
        $this->assertSame(0, $count);
    }
}
