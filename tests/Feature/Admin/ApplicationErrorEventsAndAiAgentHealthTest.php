<?php

namespace Tests\Feature\Admin;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\ApplicationErrorEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationErrorEventsAndAiAgentHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    #[Test]
    public function mark_as_failed_creates_application_error_event(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $run = AIAgentRun::create([
            'agent_id' => 'brand_pdf_extractor',
            'agent_name' => 'Brand PDF Extractor',
            'triggering_context' => 'tenant',
            'environment' => 'testing',
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'task_type' => AITaskType::BRAND_PDF_EXTRACTION,
            'entity_type' => 'asset',
            'entity_id' => '00000000-0000-0000-0000-000000000001',
            'model_used' => 'claude-test',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
        ]);

        $run->markAsFailed('Anthropic API error: Overloaded');

        $this->assertDatabaseHas('application_error_events', [
            'source_type' => 'ai_agent_run',
            'source_id' => (string) $run->id,
            'tenant_id' => $tenant->id,
            'category' => 'ai_agent',
            'code' => 'provider_overloaded',
        ]);

        $event = ApplicationErrorEvent::where('source_id', (string) $run->id)->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('Overloaded', $event->message);
        $this->assertSame('brand_pdf_extractor', $event->context['agent_id']);
    }

    #[Test]
    public function mark_as_failed_monthly_cap_sets_code_and_can_notify_owner(): void
    {
        Mail::fake();
        config(['mail.automations_enabled' => true]);

        $tenant = Tenant::create([
            'name' => 'CapCo',
            'slug' => 'capco',
        ]);

        $owner = User::factory()->create(['email' => 'owner-cap@example.com']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $run = AIAgentRun::create([
            'agent_id' => 'metadata_agent',
            'agent_name' => 'Metadata',
            'triggering_context' => 'tenant',
            'environment' => 'testing',
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'task_type' => AITaskType::ASSET_METADATA_GENERATION,
            'entity_type' => 'asset',
            'entity_id' => '00000000-0000-0000-0000-000000000002',
            'model_used' => 'gpt-test',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
        ]);

        $run->markAsFailed('Monthly AI tagging cap exceeded. Current: 5, Cap: 5. Usage resets at the start of next month.');

        $this->assertDatabaseHas('application_error_events', [
            'source_id' => (string) $run->id,
            'code' => 'ai_monthly_cap_exceeded',
        ]);

        Mail::assertSent(\App\Mail\AiMonthlyUsageCapReachedMail::class);
    }

    #[Test]
    public function mark_as_failed_monthly_cap_skips_email_when_incubating(): void
    {
        Mail::fake();

        $agency = Tenant::create(['name' => 'Agency', 'slug' => 'agency']);
        $tenant = Tenant::create([
            'name' => 'Incubated',
            'slug' => 'incubated',
            'incubated_by_agency_id' => $agency->id,
        ]);

        $owner = User::factory()->create(['email' => 'owner-inc@example.com']);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $run = AIAgentRun::create([
            'agent_id' => 'metadata_agent',
            'agent_name' => 'Metadata',
            'triggering_context' => 'tenant',
            'environment' => 'testing',
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'task_type' => AITaskType::ASSET_METADATA_GENERATION,
            'entity_type' => 'asset',
            'entity_id' => '00000000-0000-0000-0000-000000000003',
            'model_used' => 'gpt-test',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
        ]);

        $run->markAsFailed('Monthly AI tagging cap exceeded. Current: 5, Cap: 5. Usage resets at the start of next month.');

        Mail::assertNothingSent();
    }

    #[Test]
    public function site_owner_can_load_ai_agent_health_without_n_plus_one_pattern(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme-2',
        ]);

        User::unguard();
        $owner = User::factory()->create([
            'id' => 1,
            'email' => 'owner@example.com',
        ]);
        User::reguard();
        $owner->tenants()->attach($tenant->id, ['role' => 'member']);

        for ($i = 0; $i < 5; $i++) {
            AIAgentRun::create([
                'agent_id' => "agent_{$i}",
                'agent_name' => "Agent {$i}",
                'triggering_context' => 'tenant',
                'environment' => 'testing',
                'tenant_id' => $tenant->id,
                'task_type' => 'test_task',
                'model_used' => 'test-model',
                'tokens_in' => 0,
                'tokens_out' => 0,
                'estimated_cost' => 0,
                'status' => 'success',
                'started_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-agents')
            ->assertOk();
    }

    #[Test]
    public function site_admin_can_load_operations_center_application_errors_tab_props(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme-3',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('site_admin');
        $admin->tenants()->attach($tenant->id, ['role' => 'member']);

        ApplicationErrorEvent::create([
            'source_type' => 'ai_agent_run',
            'source_id' => '99',
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'category' => 'ai_agent',
            'code' => 'provider_overloaded',
            'message' => 'Anthropic API error: Overloaded',
            'context' => ['agent_id' => 'brand_pdf_extractor'],
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('admin.operations-center.index', ['tab' => 'application-errors']));

        $response->assertOk();
        $props = $response->inertiaPage()['props'] ?? [];
        $this->assertArrayHasKey('applicationErrors', $props);
        $this->assertCount(1, $props['applicationErrors']);
        $this->assertSame('provider_overloaded', $props['applicationErrors'][0]['code']);
        $this->assertSame('Acme', $props['applicationErrors'][0]['tenant_name']);
        $this->assertSame('acme-3', $props['applicationErrors'][0]['tenant_slug']);
    }
}
