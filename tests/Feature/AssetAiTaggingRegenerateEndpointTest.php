<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\RunAudioAiAnalysisJob;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * POST /app/assets/{asset}/ai-tagging/regenerate must route audio to
 * RunAudioAiAnalysisJob (not the vision chain gated on raster thumbnails).
 * MP3s commonly have thumbnail_status=skipped; the old behaviour returned 422
 * and nothing appeared on the asset timeline.
 */
class AssetAiTaggingRegenerateEndpointTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    public function test_audio_mp3_with_skipped_thumbnail_queues_audio_ai_and_logs_timeline_event(): void
    {
        Bus::fake();

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Audio Co', 'slug' => 'audio-co-'.Str::random(6), 'manual_plan_override' => 'enterprise'],
            ['email' => 'audio-regen@example.test', 'first_name' => 'A', 'last_name' => 'U']
        );

        $asset = $this->createAssetForTenant($tenant, $brand, 'audio/mpeg', 'clip.mp3', ThumbnailStatus::SKIPPED);

        $response = $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson(route('assets.ai-tagging.regenerate', ['asset' => $asset->id]));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('pipeline', 'audio_insights');

        Bus::assertDispatched(RunAudioAiAnalysisJob::class, function (RunAudioAiAnalysisJob $job) use ($asset) {
            return $job->assetId === (string) $asset->id;
        });

        $asset->refresh();
        $this->assertSame('queued', $asset->metadata['audio']['ai_status'] ?? null);

        $this->assertTrue(
            ActivityEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('subject_id', $asset->id)
                ->where('event_type', EventType::ASSET_AI_TAGGING_REGENERATED)
                ->where('actor_id', $user->id)
                ->exists(),
            'User-triggered audio re-run must write an ActivityEvent for the asset timeline'
        );
    }

    public function test_image_with_skipped_thumbnail_still_returns_422_for_vision_chain(): void
    {
        Bus::fake();

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Img Co', 'slug' => 'img-co-'.Str::random(6), 'manual_plan_override' => 'enterprise'],
            ['email' => 'img-regen@example.test', 'first_name' => 'I', 'last_name' => 'U']
        );

        $asset = $this->createAssetForTenant($tenant, $brand, 'image/jpeg', 'pic.jpg', ThumbnailStatus::SKIPPED);

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson(route('assets.ai-tagging.regenerate', ['asset' => $asset->id]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Bus::assertNothingDispatched();
    }

    protected function createAssetForTenant(
        Tenant $tenant,
        Brand $brand,
        string $mime,
        string $filename,
        ThumbnailStatus $thumbnailStatus,
    ): Asset {
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
            'thumbnail_status' => $thumbnailStatus,
            'metadata' => [],
        ]);
    }
}
