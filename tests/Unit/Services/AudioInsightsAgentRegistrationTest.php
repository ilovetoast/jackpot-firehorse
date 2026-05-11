<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AIConfigService;
use App\Services\Audio\AudioAiAnalysisService;
use App\Services\Audio\Providers\AudioAiProviderInterface;
use App\Services\Audio\Providers\WhisperAudioAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audio AI is a first-class agent — it must show up in the same admin
 * surfaces as Tagging / Video Insights / Editor agents:
 *   - Admin → AI → Agents (the row visible in the screenshot)
 *   - Activity tab agent filter (sourced from `ai.agents`)
 *   - Per-agent budget overrides
 *   - `ai_agent_runs` rows so admins can watch in-flight calls
 *
 * These tests pin the contract end-to-end so a future refactor of the
 * audio service can't quietly drop the agent registration.
 */
class AudioInsightsAgentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_insights_agent_is_registered_in_ai_config(): void
    {
        $agents = config('ai.agents');

        $this->assertIsArray($agents);
        $this->assertArrayHasKey('audio_insights', $agents, 'audio_insights must be registered alongside metadata_generator / video / studio agents');
    }

    public function test_audio_insights_agent_definition_has_required_fields(): void
    {
        $agent = config('ai.agents.audio_insights');

        $this->assertSame('Audio Insights', $agent['name'] ?? null);
        $this->assertSame('tenant', $agent['scope'] ?? null);
        $this->assertSame('whisper-1', $agent['default_model'] ?? null);
        $this->assertIsArray($agent['allowed_models'] ?? null);
        $this->assertContains('whisper-1', $agent['allowed_models']);
        $this->assertNotEmpty($agent['description'] ?? null);
    }

    public function test_audio_insights_agent_appears_in_admin_agents_listing(): void
    {
        // The Admin → AI → Agents page renders straight from this method.
        $agents = app(AIConfigService::class)->getAllAgentsWithOverrides();

        $audio = collect($agents)->firstWhere('id', 'audio_insights');

        $this->assertNotNull($audio, 'Audio agent must be in the admin Agents listing');
        $this->assertSame('Audio Insights', $audio['config']['name'] ?? null);
        $this->assertSame('whisper-1', $audio['config']['default_model'] ?? null);
    }

    public function test_successful_audio_run_writes_an_ai_agent_run_row(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        $tenant = Tenant::create([
            'name' => 'AgentRun Co',
            'slug' => 'agent-run-success',
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 90.0);

        $this->bindFakeProvider([
            'success' => true,
            'transcript' => 'hello there',
            'transcript_chunks' => [['start' => 0.0, 'end' => 1.0, 'text' => 'hello']],
            'summary' => 'A test transcript.',
            'mood' => ['calm'],
            'detected_language' => 'en',
            'provider' => 'whisper',
            'cost_cents' => 1,
            'analyzed_at' => now()->toIso8601String(),
            'source_kind' => 'web_derivative',
            'source_size_bytes' => 4 * 1024 * 1024,
        ]);

        $result = app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $this->assertTrue($result['success'] ?? false);

        $run = AIAgentRun::query()
            ->where('agent_id', 'audio_insights')
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'Successful audio run must persist to ai_agent_runs so it shows in the Activity tab');
        $this->assertSame('success', $run->status);
        $this->assertSame('audio_insights', $run->task_type);
        $this->assertSame((string) $asset->id, (string) $run->entity_id);
        $this->assertSame(Asset::class, $run->entity_type);
        $this->assertSame('whisper-1', $run->model_used);
        $this->assertSame('tenant', $run->triggering_context, 'Tenant-scoped pipeline run -> triggering_context=tenant (enum constraint)');
        $this->assertSame('audio_pipeline', $run->metadata['trigger_source'] ?? null);
        // cost_cents=1 -> $0.01 estimated_cost
        $this->assertEqualsWithDelta(0.01, (float) $run->estimated_cost, 0.0001);
        // Source kind from preparation service flows into run metadata for observability.
        $this->assertSame('web_derivative', $run->metadata['source_kind'] ?? null);
    }

    public function test_plan_limit_blocked_run_records_a_blocked_agent_run(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        $tenant = Tenant::create([
            'name' => 'Blocked Co',
            'slug' => 'agent-run-blocked',
            'manual_plan_override' => 'free',
        ]);

        $cap = app(\App\Services\AiUsageService::class)->getEffectiveAiCredits($tenant);
        $this->assertGreaterThan(0, $cap, 'Free plan needs a finite cap to exercise the block path');

        \DB::table('ai_usage')->insert([
            'tenant_id' => $tenant->id,
            'feature' => 'tagging',
            'usage_date' => now()->toDateString(),
            'call_count' => $cap,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asset = $this->makeAudioAsset($tenant, 60.0);
        $this->bindFakeProvider(['success' => true]);

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $run = AIAgentRun::query()
            ->where('agent_id', 'audio_insights')
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'Blocked run must still be visible in the Activity tab');
        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->blocked_reason);
        $this->assertSame('Audio AI blocked by plan limit', $run->summary);
    }

    public function test_provider_failure_marks_agent_run_failed_with_reason(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        $tenant = Tenant::create([
            'name' => 'Fail Co',
            'slug' => 'agent-run-failed',
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 60.0);

        $this->bindFakeProvider([
            'success' => false,
            'reason' => 'api_error',
            'error' => '503',
        ]);

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $run = AIAgentRun::query()
            ->where('agent_id', 'audio_insights')
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame('api_error', $run->error_message);
    }

    public function test_admin_disabled_agent_skips_provider_call(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        $tenant = Tenant::create([
            'name' => 'Off Co',
            'slug' => 'agent-disabled',
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 60.0);

        // Stub the AIConfigService so our test does not depend on whether
        // an admin override row exists in the test DB.
        $stubConfig = new class extends AIConfigService
        {
            public function __construct() {}

            public function getAgentConfig(string $agentId, ?string $environment = null): ?array
            {
                return ['active' => false, 'name' => 'Audio Insights', 'default_model' => 'whisper-1'];
            }
        };
        $this->app->instance(AIConfigService::class, $stubConfig);

        $providerCalled = false;
        $this->bindFakeProvider(callback: function () use (&$providerCalled) {
            $providerCalled = true;

            return ['success' => true];
        });

        $result = app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('agent_disabled', $result['reason']);
        $this->assertFalse($providerCalled, 'Provider must not be called when the admin toggles the agent off');

        $asset->refresh();
        $this->assertSame('pending_provider', $asset->metadata['audio']['ai_status'] ?? null);
        $this->assertSame('agent_disabled', $asset->metadata['audio']['reason'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function bindFakeProvider(?array $payload = null, ?callable $callback = null): void
    {
        $fake = new class($payload, $callback) implements AudioAiProviderInterface
        {
            public function __construct(public ?array $payload, public $callback) {}

            public function analyze(Asset $asset): array
            {
                if ($this->callback !== null) {
                    return ($this->callback)($asset);
                }

                return $this->payload ?? ['success' => false, 'reason' => 'no_payload'];
            }
        };

        $this->app->instance(WhisperAudioAiProvider::class, $fake);
    }

    protected function makeAudioAsset(Tenant $tenant, float $durationSeconds): Asset
    {
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket-'.$tenant->id,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Audio',
            'original_filename' => 'voice.mp3',
            'mime_type' => 'audio/mpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.Str::uuid().'/v1/voice.mp3',
            'size_bytes' => 1024,
            'metadata' => [
                'audio' => [
                    'duration_seconds' => $durationSeconds,
                ],
            ],
        ]);
    }
}
