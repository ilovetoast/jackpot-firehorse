<?php

namespace Tests\Feature;

use App\Enums\AITaskType;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GeneratePresentationPreviewJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AIService;
use App\Services\EditorGenerativeImagePersistService;
use App\Services\FreePlanImageWatermarkService;
use App\Support\ThumbnailMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneratePresentationPreviewJobTest extends TestCase
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
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-pres']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $user = User::create([
            'name' => 'U',
            'email' => 'u-pres@example.com',
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
            'metadata' => ['usage' => 'web'],
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        $thumbPath = 'tenants/t/assets/'.$asset->id.'/v1/thumbnails/preferred/medium/m.webp';
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
                    ],
                ],
                'thumbnail_modes_status' => ['presentation' => 'pending'],
                'thumbnail_modes_meta' => ['presentation' => []],
            ],
        ]);

        $png = tempnam(sys_get_temp_dir(), 'presjob');
        $this->assertNotFalse($png);
        $im = imagecreatetruecolor(400, 400);
        imagepng($im, $png);
        imagedestroy($im);

        return [$asset, $version, $png];
    }

    #[Test]
    public function job_stores_presentation_metadata_and_thumbnails_on_success(): void
    {
        [$asset, $version, $png] = $this->assetVersionWithPreferredThumb();

        $mode = ThumbnailMode::Presentation->value;
        $resultStub = [
            'thumbnails' => [
                $mode => [
                    'thumb' => [
                        'path' => 'out/pres/thumb.webp',
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
            'styles_generated' => ['thumb'],
        ];

        $thumbMock = Mockery::mock(\App\Services\ThumbnailGenerationService::class);
        $thumbMock->shouldReceive('downloadObjectToTemp')->once()->andReturn($png);
        $thumbMock->shouldReceive('generatePresentationPreviewsFromLocalRaster')->once()->andReturn($resultStub);
        $this->instance(\App\Services\ThumbnailGenerationService::class, $thumbMock);

        $tinyPng = tempnam(sys_get_temp_dir(), 'presai');
        $this->assertNotFalse($tinyPng);
        $im2 = imagecreatetruecolor(64, 64);
        imagepng($im2, $tinyPng);
        imagedestroy($im2);
        $dataUrl = 'data:image/png;base64,'.base64_encode((string) file_get_contents($tinyPng));

        $aiMock = Mockery::mock(AIService::class);
        $aiMock->shouldReceive('executeEditorImageEditAgent')
            ->once()
            ->withArgs(function (string $agentId, $taskType, string $prompt, array $opts): bool {
                return str_contains($prompt, "Architect's drafting desk")
                    && str_contains($prompt, 'Environment / placement');
            })
            ->andReturn([
                'image_ref' => $dataUrl,
                'agent_run_id' => 4242,
                'cost' => 0.01,
                'tokens_in' => 10,
                'tokens_out' => 20,
                'resolved_model_key' => 'gpt-image-1',
            ]);
        $this->instance(AIService::class, $aiMock);

        $persist = app(EditorGenerativeImagePersistService::class);

        $job = new GeneratePresentationPreviewJob((string) $asset->id, (string) $version->id, true, "Architect's drafting desk");
        $job->handle(
            $thumbMock,
            $aiMock,
            app(\App\Services\PresentationPreviewPromptBuilder::class),
            $persist,
            app(\App\Services\AiUsageService::class),
            app(FreePlanImageWatermarkService::class),
        );

        $version->refresh();
        $this->assertSame('complete', $version->metadata['thumbnail_modes_status']['presentation'] ?? null);
        $presMeta = $version->metadata['thumbnail_modes_meta']['presentation'] ?? [];
        $this->assertSame(4242, (int) ($presMeta['ai_task_id'] ?? 0));
        $this->assertSame('preferred', $presMeta['input_mode'] ?? null);
        $this->assertSame('thumb', $presMeta['style'] ?? null);
        $this->assertSame(10, (int) ($presMeta['tokens_in'] ?? 0));
        $this->assertSame(20, (int) ($presMeta['tokens_out'] ?? 0));
        $this->assertArrayHasKey('prompt', $presMeta);
        $this->assertStringContainsString('marketing presentations', (string) $presMeta['prompt']);
        $this->assertStringContainsString("Architect's drafting desk", (string) $presMeta['prompt']);
        $this->assertSame("Architect's drafting desk", (string) ($presMeta['last_scene_description'] ?? ''));

        $this->assertSame(
            'out/pres/thumb.webp',
            $version->metadata['thumbnails'][$mode]['thumb']['path'] ?? null
        );

        if (is_file($png)) {
            @unlink($png);
        }
        if (is_file($tinyPng)) {
            @unlink($tinyPng);
        }
    }

    #[Test]
    public function ai_service_accepts_presentation_task_type(): void
    {
        $this->assertContains(
            AITaskType::THUMBNAIL_PRESENTATION_PREVIEW,
            AITaskType::all()
        );
    }
}
