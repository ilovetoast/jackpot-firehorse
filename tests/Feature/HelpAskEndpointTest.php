<?php

namespace Tests\Feature;

use App\Enums\AITaskType;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HelpAskEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function actingTenantUser(): array
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-help-ask',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-help-ask']);
        $user = User::create([
            'email' => 'help-ask@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'A',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        return [$tenant, $brand, $user];
    }

    public function test_weak_question_returns_fallback_without_ai(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'zzzzzzzzzzzzzz']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'fallback');
        $this->assertLessThan(12, (int) $response->json('best_score'));
        $this->assertNotNull($response->json('help_ai_question_id'));
        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $response->json('help_ai_question_id'),
            'response_kind' => 'no_strong_match',
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_strong_match_calls_ai_and_returns_structured_answer(): void
    {
        Permission::firstOrCreate(['name' => 'asset.upload', 'guard_name' => 'web']);
        [$tenant, $brand, $user] = $this->actingTenantUser();
        $role = Role::firstOrCreate(['name' => 'help-ask-uploader', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.upload');
        $user->assignRole($role);

        $payload = [
            'severity' => 'info',
            'confidence' => 0.88,
            'summary' => 'Upload help',
            'direct_answer' => 'Use the Add Asset control on the Assets page.',
            'numbered_steps' => ['Open Assets.', 'Click Add Asset.', 'Pick files.'],
            'recommended_page' => [
                'key' => 'assets.upload',
                'title' => 'Upload new assets',
                'url' => '/app/assets',
            ],
            'related_actions' => [],
            'confidence_tier' => 'high',
        ];

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('executeAgent')
            ->once()
            ->with(
                'in_app_help_assistant',
                AITaskType::IN_APP_HELP_ACTION_ANSWER,
                Mockery::on(fn (string $p) => str_contains($p, 'USER_QUESTION:') && str_contains($p, 'HELP_ACTIONS:') && str_contains($p, 'WORKSPACE_FACTS:')),
                Mockery::on(fn (array $o) => isset($o['tenant'], $o['user']))
            )
            ->andReturn([
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'agent_run_id' => 4242,
                'cost' => 0.0001,
                'tokens_in' => 120,
                'tokens_out' => 80,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'upload files to assets']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'ai');
        $response->assertJsonPath('answer.confidence', 'high');
        $response->assertJsonPath('usage.agent_run_id', 4242);
        $response->assertJsonPath('usage.model', 'gpt-4o-mini');
        $this->assertNotNull($response->json('help_ai_question_id'));
        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $response->json('help_ai_question_id'),
            'response_kind' => 'ai',
            'agent_run_id' => 4242,
        ]);
    }

    public function test_ai_disabled_on_tenant_returns_kind_ai_disabled(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();
        $tenant->settings = array_merge($tenant->settings ?? [], ['ai_enabled' => false]);
        $tenant->save();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'upload files to assets']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'ai_disabled');
        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $response->json('help_ai_question_id'),
            'response_kind' => 'ai_disabled',
        ]);
    }

    public function test_studio_question_with_generative_off_returns_feature_unavailable_without_ai(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();
        $tenant->settings = array_merge($tenant->settings ?? [], ['generative_enabled' => false]);
        $tenant->save();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'How do I use Studio generative?']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'feature_unavailable');
        $response->assertJsonPath('feature', 'studio');
        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $response->json('help_ai_question_id'),
            'response_kind' => 'feature_unavailable',
        ]);
    }

    public function test_global_feature_disabled_returns_feature_disabled_kind(): void
    {
        config(['ai.help_ask.enabled' => false]);

        [$tenant, $brand, $user] = $this->actingTenantUser();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'upload files to assets']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'feature_disabled');
        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $response->json('help_ai_question_id'),
            'response_kind' => 'feature_disabled',
        ]);
    }

    public function test_user_can_submit_feedback_for_own_help_ask(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $ask = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'zzzzzzzzzzzzzz']);

        $ask->assertOk();
        $id = $ask->json('help_ai_question_id');
        $this->assertNotNull($id);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/help/ask/{$id}/feedback", [
                'feedback_rating' => 'helpful',
                'feedback_note' => 'Clear enough',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('help_ai_questions', [
            'id' => $id,
            'feedback_rating' => 'helpful',
            'feedback_note' => 'Clear enough',
        ]);
    }

    public function test_question_validation(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', [])
            ->assertStatus(422);
    }

    public function test_ask_password_download_enterprise_sends_password_protect_to_ai(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();

        $payload = [
            'severity' => 'info',
            'confidence' => 0.88,
            'summary' => 'Download password',
            'direct_answer' => 'Open Download settings and set an optional password.',
            'numbered_steps' => ['Open Downloads.', 'Open settings for the link.', 'Enter a password and save.'],
            'recommended_page' => [
                'key' => 'downloads.password_protect',
                'title' => 'Add a password to a download or share link',
                'url' => '/app/downloads',
            ],
            'related_actions' => [],
            'confidence_tier' => 'high',
        ];

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('executeAgent')
            ->once()
            ->with(
                Mockery::any(),
                AITaskType::IN_APP_HELP_ACTION_ANSWER,
                Mockery::on(function (string $p) {
                    return str_contains($p, 'USER_QUESTION:')
                        && preg_match('/"key"\s*:\s*"downloads\.password_protect"/', $p)
                        && ! preg_match('/"key"\s*:\s*"downloads\.password_protection_unavailable"/', $p);
                }),
                Mockery::any()
            )
            ->andReturn([
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'agent_run_id' => 5151,
                'cost' => 0.0001,
                'tokens_in' => 120,
                'tokens_out' => 80,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'how can I add a password to a download']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'ai');
        $response->assertJsonPath('matched_keys.0', 'downloads.password_protect');
    }

    public function test_ask_password_download_free_plan_uses_unavailable_topic_in_ai_context(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tf',
            'slug' => 't-ask-dl-free',
            'manual_plan_override' => 'free',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'ask-dl-free@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'F',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $payload = [
            'severity' => 'info',
            'confidence' => 0.85,
            'summary' => 'Not on plan',
            'direct_answer' => 'Password-protected links need a plan that includes them.',
            'numbered_steps' => [],
            'recommended_page' => [
                'key' => 'downloads.password_protection_unavailable',
                'title' => 'Can I password-protect a download link?',
                'url' => null,
            ],
            'related_actions' => [],
            'confidence_tier' => 'high',
        ];

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('executeAgent')
            ->once()
            ->with(
                Mockery::any(),
                AITaskType::IN_APP_HELP_ACTION_ANSWER,
                Mockery::on(function (string $p) {
                    return str_contains($p, 'USER_QUESTION:')
                        && preg_match('/"key"\s*:\s*"downloads\.password_protection_unavailable"/', $p)
                        && ! preg_match('/"key"\s*:\s*"downloads\.password_protect"/', $p);
                }),
                Mockery::any()
            )
            ->andReturn([
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'agent_run_id' => 6161,
                'cost' => 0.0001,
                'tokens_in' => 120,
                'tokens_out' => 80,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'how can I add a password to a download']);

        $response->assertOk();
        $response->assertJsonPath('kind', 'ai');
        $response->assertJsonPath('matched_keys.0', 'downloads.password_protection_unavailable');
    }

    public function test_no_workspace_in_session_returns_workspace_required_without_persisting(): void
    {
        [, , $user] = $this->actingTenantUser();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $response = $this->actingAs($user)->postJson('/app/help/ask', [
            'question' => 'How do I upload assets?',
        ]);

        $response->assertOk()
            ->assertJsonPath('kind', 'workspace_required')
            ->assertJsonPath('help_ai_question_id', null);

        $this->assertDatabaseCount('help_ai_questions', 0);
    }

    public function test_guest_cannot_post_help_ask(): void
    {
        $response = $this->postJson('/app/help/ask', [
            'question' => 'Anything?',
        ]);

        $this->assertContains($response->status(), [302, 401], 'Unauthenticated POST /app/help/ask should redirect or reject');
    }

    public function test_help_ask_is_throttled_at_21st_request_in_window(): void
    {
        [$tenant, $brand, $user] = $this->actingTenantUser();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('executeAgent');
        $this->app->instance(AIService::class, $mock);

        $session = ['tenant_id' => $tenant->id, 'brand_id' => $brand->id];
        $body = ['question' => 'zzzzzzzzzzzzzz'];

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)
                ->withSession($session)
                ->postJson('/app/help/ask', $body)
                ->assertOk();
        }

        $this->actingAs($user)
            ->withSession($session)
            ->postJson('/app/help/ask', $body)
            ->assertStatus(429);
    }

    public function test_help_ask_prompt_includes_workspace_facts_json(): void
    {
        $tenant = Tenant::create([
            'name' => 'T2',
            'slug' => 't-help-ws-facts',
            'manual_plan_override' => 'free',
        ]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b-help-ws-facts']);
        $user = User::create([
            'email' => 'help-ws-facts@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'W',
            'last_name' => 'F',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $capturedPrompt = null;
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('executeAgent')
            ->once()
            ->andReturnUsing(function (...$args) use (&$capturedPrompt) {
                $capturedPrompt = $args[2];

                return [
                    'text' => json_encode([
                        'severity' => 'info',
                        'confidence' => 0.9,
                        'summary' => 'limits',
                        'direct_answer' => 'Your Free plan caps single-file uploads at 10 MB unless a file type has a lower registry cap.',
                        'numbered_steps' => [],
                        'recommended_page' => null,
                        'related_actions' => [],
                        'confidence_tier' => 'high',
                    ], JSON_UNESCAPED_UNICODE),
                    'agent_run_id' => 777,
                    'cost' => 0.0,
                    'tokens_in' => 10,
                    'tokens_out' => 10,
                    'model' => 'gpt-4o-mini',
                    'metadata' => [],
                ];
            });
        $this->app->instance(AIService::class, $mock);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/help/ask', ['question' => 'what is the limit of file size i can upload'])
            ->assertOk()
            ->assertJsonPath('kind', 'ai');

        $this->assertIsString($capturedPrompt);
        $this->assertStringContainsString('WORKSPACE_FACTS:', $capturedPrompt);
        $this->assertStringContainsString('"max_upload_size_mb":10', $capturedPrompt);
        $this->assertStringContainsString('"display_name":"Free"', $capturedPrompt);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
