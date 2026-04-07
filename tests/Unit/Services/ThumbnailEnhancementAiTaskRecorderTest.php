<?php

namespace Tests\Unit\Services;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ThumbnailEnhancementAiTaskRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailEnhancementAiTaskRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAssetWithVersion(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $user = User::create([
            'name' => 'U',
            'email' => 'u@example.com',
            'password' => bcrypt('x'),
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
            'title' => 'A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'storage_root_path' => 'assets/x/v1/a.jpg',
            'metadata' => [],
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);
        $version = AssetVersion::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $asset->storage_root_path,
            'file_size' => 1,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 800,
            'checksum' => 'c',
            'pipeline_status' => 'complete',
            'is_current' => true,
            'metadata' => [],
        ]);

        return [$asset, $version, $tenant];
    }

    #[Test]
    public function start_creates_agent_run_with_expected_metadata(): void
    {
        [$asset, $version] = $this->makeAssetWithVersion();

        $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
        $run = $recorder->start($asset, $version, 'preferred', 'catalog_v1', [
            'template_version' => '1.0.0',
            'attempt' => 2,
            'model' => 'future-model',
            'tokens_input' => 10,
        ]);

        $this->assertInstanceOf(AIAgentRun::class, $run);
        $this->assertSame(AITaskType::THUMBNAIL_ENHANCEMENT, $run->task_type);
        $this->assertSame(ThumbnailEnhancementAiTaskRecorder::AGENT_ID, $run->agent_id);
        $this->assertNull($run->completed_at);
        $meta = $run->metadata;
        $this->assertSame($asset->id, $meta['asset_id']);
        $this->assertSame((string) $version->id, (string) ($meta['version_id'] ?? ''));
        $this->assertSame('preferred', $meta['input_mode']);
        $this->assertSame('enhanced', $meta['output_mode']);
        $this->assertSame('catalog_v1', $meta['template']);
        $this->assertSame('1.0.0', $meta['template_version']);
        $this->assertSame(2, $meta['attempt']);
        $this->assertSame('template', $meta['generation_type']);
        $this->assertSame('future-model', $meta['model']);
        $this->assertSame(10, $meta['tokens_input']);
    }

    #[Test]
    public function merge_metadata_patches_input_hash(): void
    {
        [$asset, $version] = $this->makeAssetWithVersion();
        $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
        $run = $recorder->start($asset, $version, 'preferred', 'catalog_v1', [
            'template_version' => '1.0.0',
            'attempt' => 1,
        ]);

        $recorder->mergeMetadata($run, ['input_hash' => 'deadbeef']);

        $run->refresh();
        $this->assertSame('deadbeef', $run->metadata['input_hash'] ?? null);
    }

    #[Test]
    public function succeed_marks_run_success_with_duration_and_zero_cost(): void
    {
        [$asset, $version] = $this->makeAssetWithVersion();
        $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
        $run = $recorder->start($asset, $version, 'original', 'surface_v1');

        $recorder->succeed($run, 42);

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(0.0, (float) $run->estimated_cost);
        $this->assertSame(42, $run->metadata['duration_ms'] ?? null);
        $this->assertArrayHasKey('model', $run->metadata);
        $this->assertNull($run->metadata['model']);
    }

    #[Test]
    public function fail_marks_run_failed_with_message(): void
    {
        [$asset, $version] = $this->makeAssetWithVersion();
        $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
        $run = $recorder->start($asset, $version, 'preferred', 'neutral_v1');

        $recorder->fail($run, 'S3 download error');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('S3 download error', (string) $run->error_message);
        $this->assertNotNull($run->completed_at);
    }

    #[Test]
    public function skip_marks_run_skipped_and_sets_metadata(): void
    {
        [$asset, $version] = $this->makeAssetWithVersion();
        $recorder = app(ThumbnailEnhancementAiTaskRecorder::class);
        $run = $recorder->start($asset, $version, 'preferred', 'neutral_v1', [
            'template_version' => '1.0.0',
            'attempt' => 1,
        ]);

        $recorder->skip($run, 'Source too small', ThumbnailEnhancementAiTaskRecorder::SKIP_REASON_TOO_SMALL);

        $run->refresh();
        $this->assertSame('skipped', $run->status);
        $this->assertTrue($run->metadata['skipped'] ?? false);
        $this->assertSame('too_small', $run->metadata['skip_reason'] ?? null);
        $this->assertNotNull($run->completed_at);
    }
}
