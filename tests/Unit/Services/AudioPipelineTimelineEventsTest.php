<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateAudioWebPlaybackJob;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\Audio\AudioAiAnalysisService;
use App\Services\Audio\AudioPlaybackOptimizationService;
use App\Services\Audio\Providers\AudioAiProviderInterface;
use App\Services\Audio\Providers\WhisperAudioAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the contract: every audio pipeline transition writes an
 * ActivityEvent so the asset Timeline + Admin Activity tab can show
 * "web playback MP3 generated" and "audio AI analysis completed"
 * without operators having to dig through Horizon / job logs.
 *
 * Two pipelines, four lifecycle states each (started, completed, skipped,
 * failed) — the mapping must stay consistent because the frontend's
 * AssetTimeline event-map / Activity filters key off these strings.
 */
class AudioPipelineTimelineEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_ai_success_emits_started_and_completed_events(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');
        $tenant = Tenant::create([
            'name' => 'A Co',
            'slug' => 'audio-tl-success-'.Str::random(4),
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 180.0);

        $this->bindFakeProvider([
            'success' => true,
            'transcript' => 'hello world',
            'transcript_chunks' => [['start' => 0.0, 'end' => 1.5, 'text' => 'hello']],
            'summary' => 'hello world',
            'mood' => ['calm'],
            'detected_language' => 'en',
            'provider' => 'whisper',
            'cost_cents' => 2,
            'analyzed_at' => now()->toIso8601String(),
        ]);

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_AI_STARTED, $events,
            'Started event must fire BEFORE the provider call so an in-flight run is visible in Activity');
        $this->assertContains(EventType::ASSET_AUDIO_AI_COMPLETED, $events);
        $this->assertNotContains(EventType::ASSET_AUDIO_AI_FAILED, $events);
        $this->assertNotContains(EventType::ASSET_AUDIO_AI_SKIPPED, $events);

        $completed = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_AI_COMPLETED)
            ->firstOrFail();
        $this->assertSame('whisper', $completed->metadata['provider']);
        $this->assertSame(3, $completed->metadata['credits_charged'],
            '3-minute clip bills 3 credits — surfaced in the timeline so users can audit cost without opening Billing');
        $this->assertSame('en', $completed->metadata['detected_language']);
        $this->assertSame(['calm'], $completed->metadata['mood']);
    }

    public function test_audio_ai_plan_limit_block_emits_skipped_event_not_failed(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');
        $tenant = Tenant::create([
            'name' => 'Tight Co',
            'slug' => 'audio-tl-limit-'.Str::random(4),
            'manual_plan_override' => 'free',
        ]);
        $asset = $this->makeAudioAsset($tenant, 60.0);

        // Pre-burn the credit pool so the gate trips.
        $cap = app(\App\Services\AiUsageService::class)->getEffectiveAiCredits($tenant);
        $this->assertGreaterThan(0, $cap);
        \DB::table('ai_usage')->insert([
            'tenant_id' => $tenant->id,
            'feature' => 'tagging',
            'usage_date' => now()->toDateString(),
            'call_count' => $cap,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindFakeProvider(['success' => true]);
        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_AI_SKIPPED, $events,
            'Plan-limit blocks must show as informational `skipped` (blue check), not red `failed`');
        $this->assertNotContains(EventType::ASSET_AUDIO_AI_FAILED, $events);
        $this->assertNotContains(EventType::ASSET_AUDIO_AI_STARTED, $events,
            'No started event when we never actually start — the gate fires first');

        $skipped = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_AI_SKIPPED)
            ->firstOrFail();
        $this->assertSame('plan_limit_exceeded', $skipped->metadata['reason']);
    }

    public function test_audio_ai_no_provider_configured_emits_skipped_event(): void
    {
        config()->set('assets.audio_ai.provider', null);
        $tenant = Tenant::create([
            'name' => 'No Provider Co',
            'slug' => 'audio-tl-noprov-'.Str::random(4),
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $skipped = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_AI_SKIPPED)
            ->first();
        $this->assertNotNull($skipped, 'No-provider must surface as skipped — operators need to know why audio AI never ran');
        $this->assertSame('no_provider', $skipped->metadata['reason']);
    }

    public function test_no_provider_gate_records_visible_blocked_run_in_ai_control_center(): void
    {
        // Without this row, repeated bulk reruns silently no-op and AI
        // Control Center shows nothing — the exact staging puzzle this
        // pin was added for. The agent run row is what makes "we tried,
        // but the provider isn't configured" visible to admins.
        config()->set('assets.audio_ai.provider', null);
        $tenant = Tenant::create([
            'name' => 'No Provider Visible Co',
            'slug' => 'audio-tl-noprov-vis-'.Str::random(4),
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $run = \App\Models\AIAgentRun::query()
            ->where('agent_id', 'audio_insights')
            ->where('entity_id', (string) $asset->id)
            ->first();
        $this->assertNotNull($run, 'no_provider must record an AIAgentRun so reruns appear in AI Control Center');
        $this->assertSame('failed', $run->status);
        $this->assertSame('no_provider', $run->blocked_reason);
        $this->assertSame($tenant->id, $run->tenant_id);
        $this->assertStringContainsString('not configured', (string) $run->summary);
    }

    public function test_audio_ai_provider_exception_emits_failed_event(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');
        $tenant = Tenant::create([
            'name' => 'Boom Co',
            'slug' => 'audio-tl-fail-'.Str::random(4),
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        $this->bindFakeProvider(callback: function () {
            throw new \RuntimeException('whisper offline');
        });

        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_AI_STARTED, $events);
        $this->assertContains(EventType::ASSET_AUDIO_AI_FAILED, $events);

        $failed = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_AI_FAILED)
            ->firstOrFail();
        $this->assertSame('provider_exception', $failed->metadata['reason']);
        $this->assertSame('whisper offline', $failed->metadata['error']);
    }

    public function test_provider_returns_budget_exceeded_emits_skipped_not_failed(): void
    {
        // budget_exceeded / duration_exceeded are NOT failures from a UX
        // standpoint — the file is fine, the operator hit a guardrail.
        config()->set('assets.audio_ai.provider', 'whisper');
        $tenant = Tenant::create([
            'name' => 'Budget Co',
            'slug' => 'audio-tl-budget-'.Str::random(4),
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 5.0);

        $this->bindFakeProvider(['success' => false, 'reason' => 'budget_exceeded']);
        app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_AI_SKIPPED, $events,
            'budget_exceeded is a guardrail, not a fault — must render as informational');
        $this->assertNotContains(EventType::ASSET_AUDIO_AI_FAILED, $events);
    }

    public function test_web_playback_job_records_started_and_completed_events_on_success(): void
    {
        $tenant = Tenant::create([
            'name' => 'Web Co',
            'slug' => 'audio-tl-web-'.Str::random(4),
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        $this->fakeWebPlaybackResult([
            'success' => true,
            'path' => 'tenants/x/assets/y/v1/previews/audio_web.mp3',
            'size_bytes' => 480000,
            'bitrate_kbps' => 128,
            'reason' => 'force_extension:wav',
        ]);

        (new GenerateAudioWebPlaybackJob((string) $asset->id))->handle(app(AudioPlaybackOptimizationService::class));

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_WEB_PLAYBACK_STARTED, $events);
        $this->assertContains(EventType::ASSET_AUDIO_WEB_PLAYBACK_COMPLETED, $events);

        $completed = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_WEB_PLAYBACK_COMPLETED)
            ->firstOrFail();
        $this->assertSame(128, $completed->metadata['bitrate_kbps']);
        $this->assertSame(480000, $completed->metadata['output_size_bytes']);
        $this->assertSame('mp3', $completed->metadata['codec']);
        $this->assertSame('force_extension:wav', $completed->metadata['reason']);
    }

    public function test_web_playback_job_records_skipped_event_for_small_mp3(): void
    {
        $tenant = Tenant::create([
            'name' => 'Skip Co',
            'slug' => 'audio-tl-skip-'.Str::random(4),
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        $this->fakeWebPlaybackResult([
            'success' => true,
            'skipped' => true,
            'reason' => 'mp3_under_threshold',
        ]);

        (new GenerateAudioWebPlaybackJob((string) $asset->id))->handle(app(AudioPlaybackOptimizationService::class));

        $events = $this->assetEvents($asset);
        $this->assertContains(EventType::ASSET_AUDIO_WEB_PLAYBACK_SKIPPED, $events,
            'Small MP3 sources legitimately skip transcoding — the timeline must say so explicitly so operators don\'t think the pipeline stalled');
        $this->assertNotContains(EventType::ASSET_AUDIO_WEB_PLAYBACK_COMPLETED, $events);
        $this->assertNotContains(EventType::ASSET_AUDIO_WEB_PLAYBACK_FAILED, $events);

        $skipped = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_WEB_PLAYBACK_SKIPPED)
            ->firstOrFail();
        $this->assertSame('mp3_under_threshold', $skipped->metadata['reason']);
    }

    public function test_web_playback_job_records_failed_event_on_ffmpeg_failure(): void
    {
        $tenant = Tenant::create([
            'name' => 'Fail Co',
            'slug' => 'audio-tl-fail-'.Str::random(4),
        ]);
        $asset = $this->makeAudioAsset($tenant, 30.0);

        $this->fakeWebPlaybackResult([
            'success' => false,
            'reason' => 'ffmpeg_failed',
            'error' => 'libmp3lame missing',
        ]);

        (new GenerateAudioWebPlaybackJob((string) $asset->id))->handle(app(AudioPlaybackOptimizationService::class));

        $failed = ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('event_type', EventType::ASSET_AUDIO_WEB_PLAYBACK_FAILED)
            ->firstOrFail();
        $this->assertSame('ffmpeg_failed', $failed->metadata['reason']);
        $this->assertSame('libmp3lame missing', $failed->metadata['error']);
    }

    /**
     * @param  array<int, string>  $expectedHas
     * @return list<string>
     */
    protected function assetEvents(Asset $asset): array
    {
        return ActivityEvent::query()
            ->where('subject_id', $asset->id)
            ->where('subject_type', Asset::class)
            ->orderBy('id')
            ->pluck('event_type')
            ->all();
    }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function fakeWebPlaybackResult(array $payload): void
    {
        $fake = new class($payload) extends AudioPlaybackOptimizationService
        {
            public function __construct(public array $payload)
            {
                // Skip parent boot — we don't need the S3 client wiring for
                // the timeline contract test; only the return shape matters.
            }

            public function generateForAsset(Asset $asset): array
            {
                return $this->payload;
            }
        };
        $this->app->instance(AudioPlaybackOptimizationService::class, $fake);
    }

    protected function makeAudioAsset(Tenant $tenant, float $durationSeconds): Asset
    {
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'b-'.$tenant->id,
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
