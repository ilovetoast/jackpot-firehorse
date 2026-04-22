<?php

namespace Tests\Feature;

use App\Jobs\FinalizeStudioAnimationJob;
use App\Jobs\PollStudioAnimationJob;
use App\Jobs\ProcessStudioAnimationJob;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Models\StudioAnimationJob;
use App\Models\StudioAnimationOutput;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiUsageService;
use App\Studio\Animation\Services\StudioAnimationCompletionService;
use App\Studio\Animation\Services\StudioAnimationService;
use App\Studio\Animation\Support\AnimationSourceLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioAnimationTest extends TestCase
{
    use RefreshDatabase;

    /** Minimal valid 1×1 PNG (base64, no data URL prefix). */
    private const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private Brand $brand;

    private User $user;

    private Composition $composition;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'studio_animation.providers.kling.transport' => 'mock',
            'studio_animation.render_disk' => 'local',
            'studio_animation.output_disk' => 'local',
        ]);

        Http::fake([
            'https://studio-animation.mock/*' => Http::response(str_repeat('x', 4000), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Anim Co',
            'slug' => 'anim-co',
            'uuid' => (string) Str::uuid(),
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Anim Brand',
            'slug' => 'anim-brand',
        ]);

        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->composition = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Test',
            'document_json' => ['layers' => []],
        ]);
    }

    public function test_guest_cannot_create_animation(): void
    {
        $this->postJson("/app/studio/documents/{$this->composition->id}/animations", [
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'source_strategy' => 'composition_snapshot',
            'prompt' => 'Test',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'composition_snapshot_png_base64' => self::TINY_PNG_B64,
            'snapshot_width' => 1,
            'snapshot_height' => 1,
        ])->assertUnauthorized();
    }

    public function test_creates_animation_job_and_eventually_completes_with_mock_provider(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/documents/{$this->composition->id}/animations", [
                'provider' => 'kling',
                'provider_model' => 'kling_v3_standard_image_to_video',
                'source_strategy' => 'composition_snapshot',
                'prompt' => 'Slow pan',
                'negative_prompt' => null,
                'motion_preset' => 'cinematic_pan',
                'duration_seconds' => 5,
                'aspect_ratio' => '16:9',
                'generate_audio' => false,
                'composition_snapshot_png_base64' => self::TINY_PNG_B64,
                'snapshot_width' => 1,
                'snapshot_height' => 1,
            ]);

        $response->assertCreated();
        $jobId = (int) $response->json('id');
        $this->assertGreaterThan(0, $jobId);

        $job = StudioAnimationJob::query()->findOrFail($jobId);
        $this->assertSame('complete', $job->status);
        $this->assertNotNull($job->output);
        $this->assertNotNull($job->output?->asset_id);

        $this->assertDatabaseHas('ai_agent_runs', [
            'agent_id' => 'studio_animate_composition',
            'task_type' => 'studio_composition_animation',
            'status' => 'success',
        ]);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $usage = (int) DB::table('ai_usage')
            ->where('tenant_id', $this->tenant->id)
            ->where('feature', 'studio_animation')
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->sum('call_count');
        $this->assertGreaterThan(0, $usage);
    }

    public function test_rejects_invalid_source_strategy(): void
    {
        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/documents/{$this->composition->id}/animations", [
                'provider' => 'kling',
                'provider_model' => 'kling_v3_standard_image_to_video',
                'source_strategy' => 'layer_snapshot',
                'prompt' => 'x',
                'duration_seconds' => 5,
                'aspect_ratio' => '16:9',
                'generate_audio' => false,
                'composition_snapshot_png_base64' => self::TINY_PNG_B64,
                'snapshot_width' => 1,
                'snapshot_height' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_index_lists_animations_for_composition(): void
    {
        StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'queued',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'started_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/studio/documents/{$this->composition->id}/animations")
            ->assertOk()
            ->assertJsonCount(1, 'animations');
    }

    public function test_preflight_returns_risk_payload(): void
    {
        $doc = [
            'width' => 1080,
            'height' => 1080,
            'layers' => [
                [
                    'id' => 't1',
                    'type' => 'text',
                    'name' => 'Headline',
                    'visible' => true,
                    'locked' => false,
                    'z' => 1,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 1000, 'height' => 200],
                    'content' => str_repeat('Lorem ipsum. ', 80),
                    'style' => ['fontFamily' => 'Inter', 'fontSize' => 12, 'color' => '#000'],
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/documents/{$this->composition->id}/animation-preflight", [
                'document_json' => $doc,
                'canvas_width' => 1080,
                'canvas_height' => 1080,
            ])
            ->assertOk()
            ->assertJsonPath('preflight.risk_level', 'high');
    }

    public function test_store_persists_document_revision_hash_when_document_json_sent(): void
    {
        $doc = ['width' => 1, 'height' => 1, 'layers' => []];

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/documents/{$this->composition->id}/animations", [
                'provider' => 'kling',
                'provider_model' => 'kling_v3_standard_image_to_video',
                'source_strategy' => 'composition_snapshot',
                'prompt' => 'Slow pan',
                'negative_prompt' => null,
                'motion_preset' => 'cinematic_pan',
                'duration_seconds' => 5,
                'aspect_ratio' => '16:9',
                'generate_audio' => false,
                'composition_snapshot_png_base64' => self::TINY_PNG_B64,
                'snapshot_width' => 1,
                'snapshot_height' => 1,
                'document_json' => $doc,
            ]);

        $response->assertCreated();
        $job = StudioAnimationJob::query()->findOrFail((int) $response->json('id'));
        $this->assertSame(AnimationSourceLock::hashDocument($doc), $job->source_document_revision_hash);
        $this->assertIsArray($job->animation_intent_json);
        $this->assertSame('animate_composition', $job->animation_intent_json['mode'] ?? null);
        $this->assertSame('1.4.0', $job->animation_intent_json['intent_version'] ?? null);
        $this->assertSame(2, $job->animation_intent_json['schema_version'] ?? null);
    }

    public function test_store_rejects_document_json_out_of_sync_with_version(): void
    {
        $versionDoc = ['width' => 2, 'height' => 2, 'layers' => [['id' => 'a', 'type' => 'fill', 'visible' => true, 'locked' => false, 'z' => 0, 'transform' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2]]]];
        $version = CompositionVersion::query()->create([
            'composition_id' => $this->composition->id,
            'document_json' => $versionDoc,
            'label' => 'v1',
            'kind' => CompositionVersion::KIND_MANUAL,
            'created_at' => now(),
        ]);

        $wrongClientDoc = ['width' => 9, 'height' => 9, 'layers' => []];

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/documents/{$this->composition->id}/animations", [
                'provider' => 'kling',
                'provider_model' => 'kling_v3_standard_image_to_video',
                'source_strategy' => 'composition_snapshot',
                'prompt' => null,
                'duration_seconds' => 5,
                'aspect_ratio' => '16:9',
                'generate_audio' => false,
                'composition_snapshot_png_base64' => self::TINY_PNG_B64,
                'snapshot_width' => 1,
                'snapshot_height' => 1,
                'document_json' => $wrongClientDoc,
                'source_composition_version_id' => $version->id,
            ])
            ->assertStatus(422);
    }

    public function test_retry_poll_only_dispatches_poll_not_full_process(): void
    {
        Bus::fake();

        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'provider_job_id' => 'mock-abc123',
            'error_code' => 'provider_timeout',
            'error_message' => 'Timed out',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/animations/{$job->id}/retry")
            ->assertOk();

        Bus::assertDispatched(PollStudioAnimationJob::class);
        Bus::assertNotDispatched(ProcessStudioAnimationJob::class);
        Bus::assertNotDispatched(FinalizeStudioAnimationJob::class);
    }

    public function test_retry_finalize_only_dispatches_finalize_job(): void
    {
        Bus::fake();

        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [
                'pending_finalize_remote_video_url' => 'https://studio-animation.mock/local-test.mp4',
            ],
            'provider_job_id' => 'mock-done',
            'error_code' => 'download_failed',
            'error_message' => 'DOWNLOAD_FAILED: HTTP 500',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/animations/{$job->id}/retry")
            ->assertOk();

        Bus::assertDispatched(FinalizeStudioAnimationJob::class);
        Bus::assertNotDispatched(ProcessStudioAnimationJob::class);
    }

    public function test_webhook_ingest_returns_503_when_disabled(): void
    {
        config(['studio_animation.webhooks.ingest_enabled' => false]);

        $this->postJson('/webhooks/studio-animation/kling', ['request_id' => 'x', 'status' => 'COMPLETED'])
            ->assertStatus(503);
    }

    public function test_webhook_ingest_rejects_bad_secret_when_enabled(): void
    {
        config([
            'studio_animation.webhooks.ingest_enabled' => true,
            'studio_animation.webhooks.shared_secret' => 'expected-secret',
        ]);

        $this->postJson('/webhooks/studio-animation/kling', [
            'request_id' => 'rid',
            'status' => 'COMPLETED',
        ])->assertStatus(401);

        $this->postJson('/webhooks/studio-animation/kling', [
            'request_id' => 'rid',
            'status' => 'COMPLETED',
        ], ['X-Studio-Animation-Secret' => 'expected-secret'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_finalize_twice_does_not_duplicate_outputs_or_double_charge_credits(): void
    {
        $this->mock(AiUsageService::class, function ($mock): void {
            $mock->shouldReceive('trackUsage')->once();
        });

        $url = 'https://studio-animation.mock/reuse-finalize.mp4';

        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'finalizing',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => ['credit_cost_reserved' => 40],
            'provider_job_id' => json_encode(['request_id' => 'rid-1', 'status_url' => 'http://x', 'response_url' => 'http://y']),
            'started_at' => now(),
        ]);

        $svc = app(StudioAnimationCompletionService::class);
        $svc->finalizeFromRemoteUrl($job, $url);
        $this->assertSame(1, StudioAnimationOutput::query()->where('studio_animation_job_id', $job->id)->count());
        $job->refresh();
        $this->assertTrue((bool) (($job->settings_json ?? [])['credits_tracked'] ?? false));

        $svc->finalizeFromRemoteUrl($job->fresh(), $url);
        $this->assertSame(1, StudioAnimationOutput::query()->where('studio_animation_job_id', $job->id)->count());
        $job->refresh();
        $this->assertSame('finalize_reused_existing_output', $job->settings_json['finalize_last_outcome'] ?? null);
        $this->assertSame('fingerprint', $job->settings_json['finalize_reuse_mode'] ?? null);
        $this->assertTrue((bool) ($job->settings_json['was_reused_existing_output'] ?? false));
    }

    public function test_duplicate_output_row_same_job_hits_unique_constraint(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'complete',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'provider_job_id' => 'x',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        StudioAnimationOutput::query()->create([
            'studio_animation_job_id' => $job->id,
            'finalize_fingerprint' => str_repeat('a', 64),
            'asset_id' => null,
            'disk' => 'local',
            'video_path' => 'a.mp4',
            'poster_path' => null,
            'mime_type' => 'video/mp4',
            'duration_seconds' => 5,
            'width' => null,
            'height' => null,
            'metadata_json' => [],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        StudioAnimationOutput::query()->create([
            'studio_animation_job_id' => $job->id,
            'finalize_fingerprint' => str_repeat('b', 64),
            'asset_id' => null,
            'disk' => 'local',
            'video_path' => 'b.mp4',
            'poster_path' => null,
            'mime_type' => 'video/mp4',
            'duration_seconds' => 5,
            'width' => null,
            'height' => null,
            'metadata_json' => [],
        ]);
    }

    public function test_webhook_accepts_fal_hmac_when_secret_configured(): void
    {
        config([
            'studio_animation.webhooks.ingest_enabled' => true,
            'studio_animation.webhooks.shared_secret' => 'shared',
            'studio_animation.webhooks.fal_signature_secret' => 'hmac-secret',
        ]);

        $payload = ['request_id' => 'rid-hmac', 'status' => 'COMPLETED'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $raw, 'hmac-secret');

        $this->postJson('/webhooks/studio-animation/kling', $payload, [
            'X-Studio-Animation-Secret' => 'shared',
            'X-Fal-Signature' => $sig,
        ])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_webhook_rejects_missing_fal_hmac_when_secret_configured(): void
    {
        config([
            'studio_animation.webhooks.ingest_enabled' => true,
            'studio_animation.webhooks.shared_secret' => 'shared',
            'studio_animation.webhooks.fal_signature_secret' => 'hmac-secret',
        ]);

        $this->postJson('/webhooks/studio-animation/kling', [
            'request_id' => 'rid-hmac',
            'status' => 'COMPLETED',
        ], [
            'X-Studio-Animation-Secret' => 'shared',
        ])->assertStatus(401);
    }

    public function test_finalize_reuses_legacy_output_with_job_only_mode(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'finalizing',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => ['credits_tracked' => true, 'credits_charged' => 40],
            'provider_job_id' => json_encode(['request_id' => 'rid-legacy', 'status_url' => 'http://x', 'response_url' => 'http://y']),
            'started_at' => now(),
        ]);

        StudioAnimationOutput::query()->create([
            'studio_animation_job_id' => $job->id,
            'finalize_fingerprint' => null,
            'asset_id' => null,
            'disk' => 'local',
            'video_path' => 'legacy.mp4',
            'poster_path' => null,
            'mime_type' => 'video/mp4',
            'duration_seconds' => 5,
            'width' => null,
            'height' => null,
            'metadata_json' => [],
        ]);

        app(StudioAnimationCompletionService::class)->finalizeFromRemoteUrl($job->fresh(), 'https://studio-animation.mock/legacy-reuse.mp4');
        $job->refresh();
        $this->assertSame('job_only', $job->settings_json['finalize_reuse_mode'] ?? null);
        $this->assertTrue((bool) ($job->settings_json['was_reused_existing_output'] ?? false));
    }

    public function test_show_animation_exposes_flattened_v12_reliability_fields(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'queued',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [
                'canonical_frame' => [
                    'canonical_source_render_origin' => 'server_locked_state',
                    'frame_drift_status' => 'match',
                    'drift_summary' => 'sha256_match',
                    'provider_submit_start_image_origin' => 'server_locked_state',
                ],
            ],
            'started_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/studio/animations/{$job->id}")
            ->assertOk()
            ->assertJsonPath('frame_drift_status', 'match')
            ->assertJsonPath('drift_summary', 'sha256_match')
            ->assertJsonPath('provider_submission_used_frame', 'server_locked_state');
    }

    public function test_to_api_payload_includes_drift_level_and_conditional_rollout_diagnostics(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'queued',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [
                'canonical_frame' => [
                    'drift_level' => 'medium',
                    'frame_drift_status' => 'mismatch',
                    'render_engine' => 'client_snapshot',
                    'renderer_version' => '1.4.0',
                ],
            ],
            'started_at' => now(),
        ]);

        $svc = app(StudioAnimationService::class);

        config(['studio_animation.diagnostics_api.enabled' => false]);
        $payload = $svc->toApiPayload($job);
        $this->assertSame('medium', $payload['drift_level']);
        $this->assertSame('client_snapshot', $payload['render_engine']);
        $this->assertNotNull($payload['created_at'] ?? null);
        $this->assertArrayNotHasKey('rollout_diagnostics', $payload);

        config(['studio_animation.diagnostics_api.enabled' => true]);
        $payloadDiag = $svc->toApiPayload($job->fresh());
        $this->assertSame('single_output_per_job', $payloadDiag['rollout_diagnostics']['output_policy']);
        $this->assertSame(0, $payloadDiag['rollout_diagnostics']['studio_animation_outputs_count']);
        $this->assertArrayHasKey('status_debug', $payloadDiag['rollout_diagnostics']);
        $this->assertSame('queued', $payloadDiag['rollout_diagnostics']['status_debug']['status']);
        $this->assertSame('medium', $payloadDiag['rollout_diagnostics']['status_debug']['drift_level']);
        $this->assertArrayHasKey('manual_validation_paths', $payloadDiag['rollout_diagnostics']);
    }

    public function test_effective_retry_kind_finalize_only_when_pending_url_and_no_output(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [
                'pending_finalize_remote_video_url' => 'https://studio-animation.mock/retry.mp4',
            ],
            'provider_job_id' => 'x',
            'error_code' => 'download_failed',
            'error_message' => 'DOWNLOAD_FAILED: 500',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->assertSame('finalize_only', app(StudioAnimationService::class)->effectiveRetryKind($job->fresh()));
    }

    public function test_effective_retry_kind_full_retry_when_output_row_exists(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [
                'pending_finalize_remote_video_url' => 'https://studio-animation.mock/retry.mp4',
            ],
            'provider_job_id' => 'x',
            'error_code' => 'download_failed',
            'error_message' => 'DOWNLOAD_FAILED: 500',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        StudioAnimationOutput::query()->create([
            'studio_animation_job_id' => $job->id,
            'finalize_fingerprint' => str_repeat('c', 64),
            'asset_id' => null,
            'disk' => 'local',
            'video_path' => 'partial.mp4',
            'poster_path' => null,
            'mime_type' => 'video/mp4',
            'duration_seconds' => 5,
            'width' => null,
            'height' => null,
            'metadata_json' => [],
        ]);

        $this->assertSame('full_retry', app(StudioAnimationService::class)->effectiveRetryKind($job->fresh()));
    }

    public function test_destroy_discards_failed_job(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'error_code' => 'provider_submit_failed',
            'error_message' => '401',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson("/app/studio/animations/{$job->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('studio_animation_jobs', ['id' => $job->id]);
    }

    public function test_destroy_no_row_is_idempotent_no_content(): void
    {
        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson('/app/studio/animations/9999991')
            ->assertNoContent();
    }

    public function test_destroy_forbidden_when_session_brand_mismatches_job_brand(): void
    {
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);
        $this->user->brands()->attach($otherBrand->id, ['role' => 'admin', 'removed_at' => null]);

        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'failed',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'error_code' => 'provider_submit_failed',
            'error_message' => '401',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $otherBrand->id])
            ->deleteJson("/app/studio/animations/{$job->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('studio_animation_jobs', ['id' => $job->id]);
    }

    public function test_destroy_rejects_complete_job(): void
    {
        $job = StudioAnimationJob::query()->create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'studio_document_id' => null,
            'composition_id' => $this->composition->id,
            'source_composition_version_id' => null,
            'source_document_revision_hash' => null,
            'animation_intent_json' => null,
            'provider' => 'kling',
            'provider_model' => 'kling_v3_standard_image_to_video',
            'status' => 'complete',
            'source_strategy' => 'composition_snapshot',
            'prompt' => null,
            'negative_prompt' => null,
            'motion_preset' => 'cinematic_pan',
            'duration_seconds' => 5,
            'aspect_ratio' => '16:9',
            'generate_audio' => false,
            'settings_json' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson("/app/studio/animations/{$job->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('studio_animation_jobs', ['id' => $job->id]);
    }
}
