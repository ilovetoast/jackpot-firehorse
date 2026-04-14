<?php

namespace Tests\Feature;

use App\Enums\AITaskType;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateEnhancedPreviewJob;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ThumbnailEnhancementAiTaskRecorder;
use App\Services\ThumbnailGenerationService;
use App\Support\ThumbnailMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateEnhancedPreviewJobAiTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{0: Asset, 1: AssetVersion, 2: string}
     */
    protected function assetVersionWithPreferredThumb(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-ai-enh']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $user = User::create([
            'name' => 'U',
            'email' => 'u2@example.com',
            'password' => bcrypt('x'),
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'storage_root_path' => 'assets/x/v1/a.jpg',
            'metadata' => [],
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        $thumbPath = 'tenants/t/assets/'.$asset->id.'/v1/thumbnails/preferred/medium/m.webp';
        $thumbPathLarge = 'tenants/t/assets/'.$asset->id.'/v1/thumbnails/preferred/large/l.webp';
        $version = AssetVersion::create([
            'id' => Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $asset->storage_root_path,
            'file_size' => 1,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 800,
            'checksum' => 'c-'.$asset->id,
            'pipeline_status' => 'complete',
            'is_current' => true,
            'metadata' => [
                'thumbnails' => [
                    'preferred' => [
                        'medium' => [
                            'path' => $thumbPath,
                            'width' => 500,
                            'height' => 500,
                        ],
                        'large' => [
                            'path' => $thumbPathLarge,
                            'width' => 500,
                            'height' => 500,
                        ],
                    ],
                ],
                'thumbnail_modes_status' => ['enhanced' => 'pending'],
                'thumbnail_modes_meta' => ['enhanced' => []],
            ],
        ]);

        $png = tempnam(sys_get_temp_dir(), 'enhjob');
        $this->assertNotFalse($png);
        $im = imagecreatetruecolor(400, 400);
        imagepng($im, $png);
        imagedestroy($im);

        return [$asset, $version, $png];
    }

    #[Test]
    public function job_creates_ai_task_and_marks_complete_on_success(): void
    {
        [$asset, $version, $png] = $this->assetVersionWithPreferredThumb();

        $mode = ThumbnailMode::Enhanced->value;
        $resultStub = [
            'thumbnails' => [
                $mode => [
                    'thumb' => [
                        'path' => 'out/thumb.webp',
                        'width' => 120,
                        'height' => 120,
                        'size_bytes' => 100,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ],
            ],
            'thumbnail_dimensions' => [
                $mode => ['thumb' => ['width' => 120, 'height' => 120]],
            ],
            'preview_thumbnails' => [$mode => []],
        ];

        $mock = Mockery::mock(ThumbnailGenerationService::class);
        $mock->shouldReceive('headObjectFingerprint')->andReturn('etag-x');
        $mock->shouldReceive('downloadObjectToTemp')->once()->andReturn($png);
        $mock->shouldReceive('generateEnhancedPreviewsFromLocalRaster')->once()->andReturn($resultStub);
        $this->instance(ThumbnailGenerationService::class, $mock);

        $before = AIAgentRun::count();

        $job = new GenerateEnhancedPreviewJob((string) $asset->id, (string) $version->id, [
            'x' => 0.0,
            'y' => 0.0,
            'width' => 1.0,
            'height' => 1.0,
        ], null, true);
        $job->handle(
            $mock,
            app(\App\Services\TemplateRenderer::class),
            app(ThumbnailEnhancementAiTaskRecorder::class),
        );

        $this->assertSame($before + 1, AIAgentRun::count());
        $run = AIAgentRun::query()->where('task_type', AITaskType::THUMBNAIL_ENHANCEMENT)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(0.0, (float) $run->estimated_cost);
        $this->assertGreaterThanOrEqual(0, $run->metadata['duration_ms'] ?? -1);

        $version->refresh();
        $this->assertSame($run->id, (int) ($version->metadata['thumbnail_modes_meta']['enhanced']['ai_task_id'] ?? 0));
        $this->assertSame('complete', $version->metadata['thumbnail_modes_status']['enhanced'] ?? null);

        if (is_file($png)) {
            @unlink($png);
        }
    }

    #[Test]
    public function job_marks_ai_task_failed_when_download_fails(): void
    {
        [$asset, $version, $png] = $this->assetVersionWithPreferredThumb();

        $mock = Mockery::mock(ThumbnailGenerationService::class);
        $mock->shouldReceive('downloadObjectToTemp')->once()->andThrow(new \RuntimeException('network'));
        $this->instance(ThumbnailGenerationService::class, $mock);

        $job = new GenerateEnhancedPreviewJob((string) $asset->id, (string) $version->id, [
            'x' => 0.0,
            'y' => 0.0,
            'width' => 1.0,
            'height' => 1.0,
        ], null, true);
        $job->handle(
            $mock,
            app(\App\Services\TemplateRenderer::class),
            app(ThumbnailEnhancementAiTaskRecorder::class),
        );

        $run = AIAgentRun::query()->where('task_type', AITaskType::THUMBNAIL_ENHANCEMENT)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('network', (string) $run->error_message);

        $version->refresh();
        $this->assertSame('failed', $version->metadata['thumbnail_modes_status']['enhanced'] ?? null);
        $this->assertSame($run->id, (int) ($version->metadata['thumbnail_modes_meta']['enhanced']['ai_task_id'] ?? 0));

        if (is_file($png)) {
            @unlink($png);
        }
    }
}
