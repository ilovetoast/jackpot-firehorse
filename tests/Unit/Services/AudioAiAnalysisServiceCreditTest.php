<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiUsageService;
use App\Services\Audio\AudioAiAnalysisService;
use App\Services\Audio\Providers\AudioAiProviderInterface;
use App\Services\Audio\Providers\WhisperAudioAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audio AI debits the unified `audio_insights` credit pool the same way
 * tagging / video insights do — these tests pin that contract end-to-end:
 *
 *   1. The duration tier maths line up with the marketing/help copy.
 *   2. `audio_insights` shows up in the per-feature dashboard payload so
 *      Insights / Billing surfaces will render the row even when usage is 0.
 *   3. A successful provider run writes an `ai_usage` row and that row
 *      reflects the duration-tiered credit count (not just one call).
 *   4. A workspace at its monthly cap aborts BEFORE we burn S3 egress on
 *      Whisper — the plan-limit gate is the first thing the service does.
 */
class AudioAiAnalysisServiceCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_insights_tier_math_matches_published_pricing(): void
    {
        $svc = app(AiUsageService::class);

        $this->assertSame(1, $svc->getAudioInsightsCreditCost(0.0), '0s clip floors to 1 credit');
        $this->assertSame(1, $svc->getAudioInsightsCreditCost(0.5), '30s voice memo = 1 credit');
        $this->assertSame(1, $svc->getAudioInsightsCreditCost(1.0), '60s = 1 credit');
        $this->assertSame(2, $svc->getAudioInsightsCreditCost(1.02), '61s = 2 credits');
        $this->assertSame(3, $svc->getAudioInsightsCreditCost(3.0), '3-minute clip = 3 credits');
        $this->assertSame(60, $svc->getAudioInsightsCreditCost(60.0), '60-minute podcast = 60 credits');
    }

    public function test_audio_insights_appears_in_unified_per_feature_dashboard(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 'audio-credit-dash',
            'manual_plan_override' => 'enterprise',
        ]);

        $status = app(AiUsageService::class)->getUsageStatus($tenant);

        $this->assertArrayHasKey('audio_insights', $status['per_feature'], 'audio_insights must be advertised in the unified dashboard so users can see it next to tagging / video insights');
        $this->assertSame(0, $status['per_feature']['audio_insights']['calls']);
        $this->assertSame(0, $status['per_feature']['audio_insights']['credits_used']);
    }

    public function test_pricing_config_publishes_audio_insights_cost(): void
    {
        // The Marketing pricing page + Billing tier-cost payload both read
        // these keys; the test pins them so a regression here is loud.
        $this->assertIsInt(config('ai_credits.audio_insights.base_credits'));
        $this->assertIsInt(config('ai_credits.audio_insights.per_additional_minute'));
        $this->assertGreaterThan(0, config('ai_credits.audio_insights.base_credits'));
    }

    public function test_successful_audio_analysis_charges_audio_insights_credits(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        $tenant = Tenant::create([
            'name' => 'Audio Co',
            'slug' => 'audio-credit-success',
            'manual_plan_override' => 'enterprise',
        ]);
        $asset = $this->makeAudioAsset($tenant, 180.0); // 3 minutes -> 3 credits

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

        $result = app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['credits_charged'], '3-minute clip should bill 3 credits');

        $row = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', AudioAiAnalysisService::FEATURE_KEY)
            ->first();

        $this->assertNotNull($row, 'Audio analysis must write an ai_usage row');
        $this->assertSame(3, (int) $row->call_count, 'call_count stores the duration-tiered credit count (weight=1 convention, mirrors studio_animation)');
        $this->assertEqualsWithDelta(0.02, (float) $row->cost_usd, 0.0001, 'Provider cost_cents propagates into ai_usage.cost_usd');
        $this->assertStringStartsWith('audio:', (string) $row->model);

        $asset->refresh();
        $this->assertSame(3, $asset->metadata['audio']['credits_charged'] ?? null);
        $this->assertSame('completed', $asset->metadata['audio']['ai_status'] ?? null);

        $usage = app(AiUsageService::class)->getUsageStatus($tenant);
        $this->assertSame(3, $usage['per_feature']['audio_insights']['credits_used']);
    }

    public function test_audio_analysis_short_circuits_when_credit_pool_exhausted(): void
    {
        config()->set('assets.audio_ai.provider', 'whisper');

        // Free plan: tiny ai-credit pool. Pre-burn it so the audio job has
        // nothing left to spend.
        $tenant = Tenant::create([
            'name' => 'Tight Co',
            'slug' => 'audio-credit-blocked',
            'manual_plan_override' => 'free',
        ]);
        $cap = app(AiUsageService::class)->getEffectiveAiCredits($tenant);
        $this->assertGreaterThan(0, $cap, 'Free plan must have a finite cap for this test to exercise the gate');

        DB::table('ai_usage')->insert([
            'tenant_id' => $tenant->id,
            'feature' => 'tagging',
            'usage_date' => now()->toDateString(),
            'call_count' => $cap,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asset = $this->makeAudioAsset($tenant, 60.0);

        $providerCalled = false;
        $this->bindFakeProvider(callback: function () use (&$providerCalled) {
            $providerCalled = true;

            return ['success' => true];
        });

        $result = app(AudioAiAnalysisService::class)->analyzeForAsset($asset);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame('plan_limit_exceeded', $result['status']);
        $this->assertSame('plan_limit_exceeded', $result['reason']);
        $this->assertFalse($providerCalled, 'Whisper must NOT be called once the credit pool is exhausted — that\'s the whole point of the gate');

        $audioRows = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', AudioAiAnalysisService::FEATURE_KEY)
            ->count();
        $this->assertSame(0, $audioRows, 'No audio_insights credit charge when the gate blocks');

        $asset->refresh();
        $this->assertSame('plan_limit_exceeded', $asset->metadata['audio']['ai_status'] ?? null);
        $this->assertSame('plan_limit_exceeded', $asset->metadata['audio']['reason'] ?? null);
    }

    /**
     * Bind a fake AudioAiProviderInterface in place of the real Whisper
     * provider — keeps the test offline while exercising the full service
     * path including the credit-pool side-effects.
     *
     * @param  array<string, mixed>|null  $payload  Static payload to return.
     * @param  callable|null  $callback  Dynamic payload (for instrumented tests).
     */
    protected function bindFakeProvider(?array $payload = null, ?callable $callback = null): void
    {
        $fake = new class($payload, $callback) implements AudioAiProviderInterface
        {
            public function __construct(public ?array $payload, public $callback)
            {
            }

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
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.\Illuminate\Support\Str::uuid().'/v1/voice.mp3',
            'size_bytes' => 1024,
            'metadata' => [
                'audio' => [
                    'duration_seconds' => $durationSeconds,
                ],
            ],
        ]);
    }
}
