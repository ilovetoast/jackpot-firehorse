<?php

namespace Tests\Unit\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Http\Controllers\Admin\AdminAssetController;
use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\AiTagAutoApplyJob;
use App\Jobs\ProcessVideoInsightsBatchJob;
use App\Jobs\RunAudioAiAnalysisJob;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The admin "Rerun AI Tagging" bulk action used to fan out the vision
 * tagging chain for *every* asset type — including audio, where the
 * "thumbnail" is a meaningless waveform PNG. These tests pin the new
 * file-type-aware dispatch:
 *
 *   - audio asset  -> RunAudioAiAnalysisJob (Whisper transcription path)
 *   - video asset  -> vision chain  +  ProcessVideoInsightsBatchJob fan-out
 *   - image asset  -> vision chain only (no audio / no video insights)
 *
 * We exercise the protected `executeBulkAction` directly so the test is
 * a tight unit on the dispatch logic without dragging the full admin
 * permissions + Inertia stack into scope.
 */
class AdminAssetBulkRerunAiTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerun_ai_tagging_on_audio_dispatches_audio_ai_only(): void
    {
        Bus::fake();

        $asset = $this->makeAsset('audio/mpeg', 'voice.mp3');
        $this->callRerunAiTagging($asset);

        Bus::assertDispatched(RunAudioAiAnalysisJob::class, function ($job) use ($asset) {
            return $job->assetId === (string) $asset->id;
        });
        Bus::assertNotDispatched(AiMetadataGenerationJob::class);
        Bus::assertNotDispatched(AiTagAutoApplyJob::class);
        Bus::assertNotDispatched(AiMetadataSuggestionJob::class);

        $asset->refresh();
        $this->assertSame('queued', $asset->metadata['audio']['ai_status'] ?? null,
            'Asset metadata must be reset to queued so the asset drawer + Activity tab show the fresh attempt');
    }

    public function test_rerun_ai_tagging_on_image_dispatches_vision_chain_only(): void
    {
        Bus::fake();

        $asset = $this->makeAsset('image/jpeg', 'logo.jpg');
        $this->callRerunAiTagging($asset);

        Bus::assertChained([
            AiMetadataGenerationJob::class,
            AiTagAutoApplyJob::class,
            AiMetadataSuggestionJob::class,
        ]);
        Bus::assertNotDispatched(RunAudioAiAnalysisJob::class);
        Bus::assertNotDispatched(ProcessVideoInsightsBatchJob::class);
    }

    public function test_rerun_ai_tagging_on_video_dispatches_vision_chain_and_video_insights(): void
    {
        config()->set('assets.video_ai.enabled', true);

        Bus::fake();

        $asset = $this->makeAsset('video/mp4', 'spot.mp4');
        $this->callRerunAiTagging($asset);

        Bus::assertChained([
            AiMetadataGenerationJob::class,
            AiTagAutoApplyJob::class,
            AiMetadataSuggestionJob::class,
        ]);
        Bus::assertDispatched(ProcessVideoInsightsBatchJob::class, function ($job) use ($asset) {
            return in_array((string) $asset->id, $job->assetIds, true);
        });
        Bus::assertNotDispatched(RunAudioAiAnalysisJob::class);

        $asset->refresh();
        $this->assertSame('queued', $asset->metadata['ai_video_status'] ?? null);
    }

    public function test_video_insights_skipped_when_video_ai_disabled(): void
    {
        config()->set('assets.video_ai.enabled', false);

        Bus::fake();

        $asset = $this->makeAsset('video/mp4', 'spot.mp4');
        $this->callRerunAiTagging($asset);

        Bus::assertChained([
            AiMetadataGenerationJob::class,
            AiTagAutoApplyJob::class,
            AiMetadataSuggestionJob::class,
        ]);
        Bus::assertNotDispatched(ProcessVideoInsightsBatchJob::class);
    }

    public function test_audio_skip_flag_is_cleared_so_rerun_actually_runs(): void
    {
        Bus::fake();

        $asset = $this->makeAsset('audio/mpeg', 'voice.mp3');
        $asset->update(['metadata' => array_merge($asset->metadata ?? [], [
            '_skip_ai_audio_analysis' => true,
        ])]);

        $this->callRerunAiTagging($asset->refresh());

        $asset->refresh();
        $this->assertArrayNotHasKey('_skip_ai_audio_analysis', $asset->metadata,
            'Manual rerun must clear the upload-time skip flag — otherwise admins click rerun and nothing happens');
        Bus::assertDispatched(RunAudioAiAnalysisJob::class);
    }

    protected function callRerunAiTagging(Asset $asset): void
    {
        $controller = app(AdminAssetController::class);
        $method = new ReflectionMethod($controller, 'executeBulkAction');
        $method->invoke($controller, 'rerun_ai_tagging', $asset);
    }

    protected function makeAsset(string $mime, string $filename): Asset
    {
        $tenant = Tenant::create(['name' => 'T-'.Str::random(4), 'slug' => 'rerun-'.Str::random(6)]);
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
            'title' => 'Asset',
            'original_filename' => $filename,
            'mime_type' => $mime,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.Str::uuid().'/v1/'.$filename,
            'size_bytes' => 1024,
            'metadata' => [],
        ]);
    }
}
