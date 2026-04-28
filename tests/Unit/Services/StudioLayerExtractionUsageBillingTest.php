<?php

namespace Tests\Unit\Services;

use App\Models\AIAgentRun;
use App\Models\Brand;
use App\Models\StudioLayerExtractionSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioLayerExtractionUsageBillingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantContext(): array
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-'.Str::random(6),
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        return [$tenant, $brand, $user];
    }

    public function test_try_bill_studio_layer_extraction_is_idempotent(): void
    {
        [$tenant, $brand, $user] = $this->seedTenantContext();
        $session = StudioLayerExtractionSession::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => 1,
            'source_layer_id' => 'L1',
            'source_asset_id' => (string) Str::uuid(),
            'status' => StudioLayerExtractionSession::STATUS_PENDING,
            'provider' => 'sam',
            'model' => 'test-model',
            'candidates_json' => null,
            'metadata' => [
                'extraction_method' => 'ai',
                'billable' => true,
            ],
            'error_message' => null,
            'expires_at' => now()->addDay(),
        ]);

        config(['ai_credits.weights.studio_layer_extraction' => 1]);
        $ai = app(AiUsageService::class);
        $ai->tryBillStudioLayerExtraction($tenant, $session, 'fal_sam2', 'fal');
        $ai->tryBillStudioLayerExtraction($tenant, $session, 'fal_sam2', 'fal');

        $this->assertEquals(1, (int) \DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', 'studio_layer_extraction')
            ->sum('call_count'));
        $this->assertEquals(1, AIAgentRun::query()
            ->where('task_type', 'studio_layer_extraction')
            ->where('entity_id', $session->id)
            ->count());

        $run = AIAgentRun::query()
            ->where('entity_id', $session->id)
            ->where('task_type', 'studio_layer_extraction')
            ->first();
        $this->assertNotNull($run);
        $meta = $run->metadata ?? [];
        $this->assertSame($tenant->id, $meta['tenant_id']);
        $this->assertSame($brand->id, $meta['brand_id']);
        $this->assertSame($user->id, $meta['user_id']);
        $this->assertSame('fal', $meta['provider']);
        $this->assertSame('fal_sam2', $meta['model']);
        $this->assertSame('studio_layer_extraction', $meta['feature']);
        $this->assertSame($session->id, $meta['extraction_session_id']);
    }

    public function test_try_bill_background_fill_skips_when_credits_disabled(): void
    {
        [$tenant, $brand, $user] = $this->seedTenantContext();
        $session = StudioLayerExtractionSession::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => 1,
            'source_layer_id' => 'L1',
            'source_asset_id' => (string) Str::uuid(),
            'status' => StudioLayerExtractionSession::STATUS_READY,
            'provider' => 'sam',
            'model' => null,
            'candidates_json' => '[]',
            'metadata' => [],
            'error_message' => null,
            'expires_at' => now()->addDay(),
        ]);

        config(['studio_layer_extraction.background_fill_credits_enabled' => false]);
        $ai = app(AiUsageService::class);
        $ai->tryBillStudioLayerBackgroundFill($tenant, $session, 'clipdrop');

        $this->assertEquals(0, (int) \DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', 'studio_layer_background_fill')
            ->sum('call_count'));
        $this->assertEquals(0, AIAgentRun::query()
            ->where('task_type', 'studio_layer_background_fill')
            ->where('entity_id', $session->id)
            ->count());
    }

    public function test_try_bill_background_fill_idempotent_with_agent_metadata(): void
    {
        [$tenant, $brand, $user] = $this->seedTenantContext();
        $session = StudioLayerExtractionSession::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => 1,
            'source_layer_id' => 'L1',
            'source_asset_id' => (string) Str::uuid(),
            'status' => StudioLayerExtractionSession::STATUS_READY,
            'provider' => 'sam',
            'model' => null,
            'candidates_json' => '[]',
            'metadata' => [],
            'error_message' => null,
            'expires_at' => now()->addDay(),
        ]);

        config([
            'studio_layer_extraction.background_fill_credits_enabled' => true,
            'ai_credits.weights.studio_layer_background_fill' => 1,
        ]);
        $ai = app(AiUsageService::class);
        $ai->tryBillStudioLayerBackgroundFill($tenant, $session, 'clipdrop');
        $ai->tryBillStudioLayerBackgroundFill($tenant, $session, 'clipdrop');

        $this->assertEquals(1, (int) \DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', 'studio_layer_background_fill')
            ->sum('call_count'));

        $run = AIAgentRun::query()
            ->where('task_type', 'studio_layer_background_fill')
            ->where('entity_id', $session->id)
            ->first();
        $this->assertNotNull($run);
        $meta = $run->metadata ?? [];
        $this->assertSame('clipdrop', $meta['provider']);
        $this->assertSame('studio_layer_background_fill', $meta['feature']);
        $this->assertSame($brand->id, $meta['brand_id']);
        $this->assertSame($user->id, $meta['user_id']);
        $this->assertSame($session->id, $meta['extraction_session_id']);
    }
}
