<?php

namespace Tests\Feature;

use App\Models\AIAgentRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditorGenerativeImageTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.openai.api_key' => 'sk-test']);
        $this->app->forgetInstance(\App\Services\AIService::class);

        $this->tenant = Tenant::create([
            'name' => 'Gen Co',
            'slug' => 'gen-co',
        ]);

        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id);
    }

    public function test_generate_image_returns_json_and_records_agent_run_and_usage(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [
                    ['url' => 'https://oaiusercontent.com/test/image.png'],
                ],
                'model' => 'gpt-image-1',
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 100,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/generate-image', [
                'prompt' => ['scene' => 'A red apple'],
                'prompt_string' => 'A red apple',
                'model' => [
                    'provider' => 'openai',
                    'model' => 'gpt-image-1',
                ],
                'model_key' => 'default',
                'size' => '1024x1024',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'image_url',
            'resolved_model_key',
            'model_display_name',
            'agent_run_id',
        ]);

        $this->assertDatabaseHas('ai_agent_runs', [
            'agent_id' => 'editor_generative_image',
            'task_type' => 'editor_generative_image',
            'status' => 'success',
        ]);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $usageSum = (int) DB::table('ai_usage')
            ->where('tenant_id', $this->tenant->id)
            ->where('feature', 'generative_editor_images')
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->sum('call_count');

        $this->assertSame(1, $usageSum);

        $run = AIAgentRun::where('agent_id', 'editor_generative_image')->first();
        $this->assertNotNull($run);
        $this->assertSame(50, $run->tokens_in);
        $this->assertSame(100, $run->tokens_out);
    }

    public function test_generate_image_returns_429_when_monthly_cap_exceeded(): void
    {
        config(['ai.openai.api_key' => 'sk-test']);
        $this->app->forgetInstance(\App\Services\AIService::class);

        $today = now()->toDateString();
        DB::table('ai_usage')->insert([
            'tenant_id' => $this->tenant->id,
            'feature' => 'generative_editor_images',
            'usage_date' => $today,
            'call_count' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/generate-image', [
                'prompt' => ['scene' => 'Test'],
                'prompt_string' => 'Test',
                'model' => [
                    'provider' => 'openai',
                    'model' => 'gpt-image-1',
                ],
                'model_key' => 'default',
                'size' => '1024x1024',
            ]);

        $response->assertStatus(429);
        $this->assertSame(0, AIAgentRun::where('agent_id', 'editor_generative_image')->count());
    }
}
