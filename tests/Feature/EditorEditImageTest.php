<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditorEditImageTest extends TestCase
{
    use RefreshDatabase;

    /** 10×10 PNG — normalizers require min edge ≥ 10px. */
    private const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQYlWP8z4APMOGVHbHSAEEsARM3dz+eAAAAAElFTkSuQmCC';

    protected Tenant $tenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.openai.api_key' => 'sk-test']);
        $this->app->forgetInstance(\App\Services\AIService::class);

        $this->tenant = Tenant::create([
            'name' => 'Edit Co',
            'slug' => 'edit-co',
        ]);

        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id);
    }

    public function test_edit_image_returns_stub_when_openai_key_missing(): void
    {
        config([
            'ai.openai.api_key' => '',
            'ai.gemini.api_key' => '',
        ]);
        $this->app->forgetInstance(\App\Services\AIService::class);

        $dataUrl = 'data:image/png;base64,'.self::TEST_PNG_BASE64;

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/edit-image', [
                'image_url' => $dataUrl,
                'instruction' => 'Make the sky blue',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['image_url']);
        $this->assertStringContainsString('/app/api/generate-image/proxy/', $response->json('image_url'));
    }

    public function test_edit_image_calls_openai_and_returns_proxy_url(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'data' => [
                    ['url' => 'https://oaiusercontent.com/test/edited.png'],
                ],
                'model' => 'gpt-image-1',
                'usage' => [
                    'input_tokens' => 40,
                    'output_tokens' => 80,
                ],
            ], 200),
        ]);

        $dataUrl = 'data:image/png;base64,'.self::TEST_PNG_BASE64;

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/edit-image', [
                'image_url' => $dataUrl,
                'instruction' => 'Change forest to desert',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('/app/api/generate-image/proxy/', $response->json('image_url'));

        $this->assertDatabaseHas('ai_agent_runs', [
            'agent_id' => 'editor_edit_image',
            'task_type' => 'editor_edit_image',
            'status' => 'success',
        ]);
    }

    public function test_edit_image_rejects_empty_instruction(): void
    {
        config(['ai.openai.api_key' => 'sk-test']);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/edit-image', [
                'image_url' => 'data:image/png;base64,',
                'instruction' => '   ',
            ]);

        $response->assertStatus(422);
    }

    public function test_edit_image_requires_image_url_or_asset_id(): void
    {
        config(['ai.openai.api_key' => 'sk-test']);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/edit-image', [
                'instruction' => 'Make it warmer',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Provide either asset_id or image_url.']);
    }

    public function test_edit_image_rejects_gemini_3_models_for_modification(): void
    {
        config(['ai.openai.api_key' => 'sk-test', 'ai.gemini.api_key' => 'fake']);

        $dataUrl = 'data:image/png;base64,'.self::TEST_PNG_BASE64;

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/app/api/edit-image', [
                'image_url' => $dataUrl,
                'instruction' => 'Make it warmer',
                'model_key' => 'gemini-3-pro-image-preview',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => "Model 'gemini-3-pro-image-preview' is not allowed for image modification."]);
    }
}
