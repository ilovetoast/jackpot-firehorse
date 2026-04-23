<?php

namespace Tests\Unit;

use App\Contracts\StudioCanvasRuntimePlaywrightInvokerContract;
use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\StudioCompositionCanvasRuntimeFfmpegMerger;
use App\Services\Studio\StudioCompositionCanvasRuntimeVideoExportService;
use App\Services\Studio\StudioCompositionVideoExportMp4Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class StudioCompositionCanvasRuntimeVideoExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, brand: Brand, user: User, composition: Composition, job: StudioCompositionVideoExportJob}
     */
    private function seedExportJob(bool $withVideoLayer = false): array
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-'.Str::random(6),
        ]);
        $user = User::factory()->create();

        $layers = [
            ['id' => 'f1', 'type' => 'fill', 'visible' => true, 'locked' => false, 'z' => 0, 'fillKind' => 'solid', 'color' => '#e0e0e0'],
        ];

        if ($withVideoLayer) {
            Storage::fake('s3');
            $videoAssetId = (string) Str::uuid();
            $storageKey = 'studio-test/'.$videoAssetId.'.mp4';
            $tiny = $this->buildTinyMp4Bytes();
            Storage::disk('s3')->put($storageKey, $tiny);

            Asset::forceCreate([
                'id' => $videoAssetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::ASSET,
                'title' => 'Base',
                'original_filename' => 'base.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => strlen($tiny),
                'width' => 320,
                'height' => 240,
                'storage_bucket_id' => null,
                'storage_root_path' => $storageKey,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                'analysis_status' => 'complete',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'test',
                'metadata' => [],
            ]);

            $layers[] = [
                'id' => 'v1',
                'type' => 'video',
                'visible' => true,
                'locked' => false,
                'z' => 1,
                'assetId' => $videoAssetId,
                'primaryForExport' => true,
                'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920],
                'timeline' => ['trim_in_ms' => 0, 'trim_out_ms' => 0, 'muted' => false],
            ];
        }

        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C',
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => $layers,
                'studio_timeline' => ['duration_ms' => 66],
            ],
        ]);
        $job = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => $composition->id,
            'render_mode' => 'canvas_runtime',
            'status' => StudioCompositionVideoExportJob::STATUS_QUEUED,
            'meta_json' => ['seed' => true],
        ]);

        return compact('tenant', 'brand', 'user', 'composition', 'job');
    }

    public function test_success_merges_and_publishes_with_pending_cleared(): void
    {
        Config::set('studio_video.canvas_runtime_export_enabled', true);
        Queue::fake();

        $seed = $this->seedExportJob(true);
        $job = $seed['job'];

        $playwright = Mockery::mock(StudioCanvasRuntimePlaywrightInvokerContract::class);
        $playwright->shouldReceive('run')
            ->once()
            ->andReturnUsing(static function (array $command, ?string $cwd, int $timeoutSeconds): array {
                $dir = null;
                foreach ($command as $part) {
                    if (is_string($part) && str_starts_with($part, '--output-dir=')) {
                        $dir = substr($part, strlen('--output-dir='));
                        break;
                    }
                }
                Assert::assertNotNull($dir);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
                file_put_contents($dir.'/frame_000000.png', $png);
                file_put_contents($dir.'/frame_000001.png', $png);
                $manifest = [
                    'schema' => 'studio_canvas_capture_manifest_v1',
                    'total_captured_frames' => 2,
                    'total_expected_frames' => 2,
                    'fps' => 30,
                    'duration_ms' => 66,
                    'width' => 1080,
                    'height' => 1920,
                    'frame_filename_pattern' => 'frame_%06d.png',
                    'render_url' => 'https://app.test/internal/studio/composition-export-render/1?signature=secret',
                ];
                file_put_contents($dir.DIRECTORY_SEPARATOR.'capture-manifest.json', json_encode($manifest));

                return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
            });
        $this->app->instance(StudioCanvasRuntimePlaywrightInvokerContract::class, $playwright);

        $mergedOut = storage_path('app/tmp/jp-canvas-test-out-'.Str::random(8).'.mp4');
        @mkdir(dirname($mergedOut), 0755, true);
        file_put_contents($mergedOut, $this->buildTinyMp4Bytes());

        $merger = Mockery::mock(StudioCompositionCanvasRuntimeFfmpegMerger::class);
        $merger->shouldReceive('mergeToTempMp4')->once()->andReturn([
            'ok' => true,
            'local_mp4_path' => $mergedOut,
            'diagnostics' => [
                'schema' => 'studio_canvas_runtime_merge_diagnostics_v1',
                'phase' => 'complete',
            ],
        ]);
        $this->app->instance(StudioCompositionCanvasRuntimeFfmpegMerger::class, $merger);

        $publisher = Mockery::mock(StudioCompositionVideoExportMp4Publisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(
            function (
                StudioCompositionVideoExportJob $row,
                Composition $composition,
                Tenant $tenant,
                User $user,
                string $localMp4Path,
                int $width,
                int $height,
                array $technicalMeta,
            ) use ($seed, $mergedOut) {
                Assert::assertSame($mergedOut, $localMp4Path);
                $newId = (string) Str::uuid();
                $asset = Asset::forceCreate([
                    'id' => $newId,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $seed['brand']->id,
                    'user_id' => $user->id,
                    'status' => AssetStatus::VISIBLE,
                    'type' => AssetType::AI_GENERATED,
                    'title' => 'Studio video export',
                    'original_filename' => 'studio-export-'.$row->id.'.mp4',
                    'mime_type' => 'video/mp4',
                    'size_bytes' => 100,
                    'width' => $width,
                    'height' => $height,
                    'storage_bucket_id' => null,
                    'storage_root_path' => 'out/'.$newId.'.mp4',
                    'thumbnail_status' => ThumbnailStatus::PENDING,
                    'analysis_status' => 'uploading',
                    'approval_status' => ApprovalStatus::NOT_REQUIRED,
                    'published_at' => null,
                    'source' => 'studio_composition_video_export',
                    'metadata' => [],
                ]);

                return [
                    'asset' => $asset,
                    'technical' => array_merge($technicalMeta, ['output_asset_id' => $asset->id]),
                ];
            }
        );
        $this->app->instance(StudioCompositionVideoExportMp4Publisher::class, $publisher);

        $service = $this->app->make(StudioCompositionCanvasRuntimeVideoExportService::class);
        $service->run($job, $seed['tenant'], $seed['user']);

        $job->refresh();
        $this->assertSame(StudioCompositionVideoExportJob::STATUS_COMPLETE, $job->status);
        $this->assertNull($job->error_json);
        $meta = is_array($job->meta_json) ? $job->meta_json : [];
        $this->assertArrayHasKey('canvas_runtime_diagnostics', $meta);
        $this->assertSame(2, $meta['canvas_runtime_diagnostics']['manifest']['total_captured_frames'] ?? null);
        $this->assertArrayNotHasKey('render_url', $meta['canvas_runtime_diagnostics']['manifest']);
        $this->assertSame('app.test', $meta['canvas_runtime_diagnostics']['manifest']['render_url_host'] ?? null);
        $this->assertArrayHasKey('canvas_runtime_capture', $meta);
        $this->assertFalse((bool) ($meta['canvas_runtime_capture']['ffmpeg_merge_pending'] ?? true));
        $this->assertSame('complete', $meta['canvas_runtime_capture']['phase'] ?? null);
        $this->assertNotNull($job->output_asset_id);
        $this->assertArrayHasKey('canvas_runtime_merge_diagnostics', $meta);
        $this->assertArrayHasKey('canvas_runtime_retention', $meta);
        $this->assertSame('studio_canvas_runtime_retention_v1', $meta['canvas_runtime_retention']['schema'] ?? null);
    }

    public function test_merge_fails_without_video_layer_and_clears_pending(): void
    {
        Config::set('studio_video.canvas_runtime_export_enabled', true);

        $seed = $this->seedExportJob(false);
        $job = $seed['job'];

        $playwright = Mockery::mock(StudioCanvasRuntimePlaywrightInvokerContract::class);
        $playwright->shouldReceive('run')
            ->once()
            ->andReturnUsing(static function (array $command, ?string $cwd, int $timeoutSeconds): array {
                $dir = null;
                foreach ($command as $part) {
                    if (is_string($part) && str_starts_with($part, '--output-dir=')) {
                        $dir = substr($part, strlen('--output-dir='));
                        break;
                    }
                }
                Assert::assertNotNull($dir);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
                file_put_contents($dir.'/frame_000000.png', $png);
                file_put_contents($dir.'/frame_000001.png', $png);
                $manifest = [
                    'schema' => 'studio_canvas_capture_manifest_v1',
                    'total_captured_frames' => 2,
                    'total_expected_frames' => 2,
                    'fps' => 30,
                    'duration_ms' => 66,
                    'width' => 1080,
                    'height' => 1920,
                    'frame_filename_pattern' => 'frame_%06d.png',
                ];
                file_put_contents($dir.DIRECTORY_SEPARATOR.'capture-manifest.json', json_encode($manifest));

                return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
            });
        $this->app->instance(StudioCanvasRuntimePlaywrightInvokerContract::class, $playwright);

        $merger = Mockery::mock(StudioCompositionCanvasRuntimeFfmpegMerger::class);
        $merger->shouldReceive('mergeToTempMp4')->never();
        $this->app->instance(StudioCompositionCanvasRuntimeFfmpegMerger::class, $merger);

        $service = $this->app->make(StudioCompositionCanvasRuntimeVideoExportService::class);
        $service->run($job, $seed['tenant'], $seed['user']);

        $job->refresh();
        $this->assertSame(StudioCompositionVideoExportJob::STATUS_FAILED, $job->status);
        $err = is_array($job->error_json) ? $job->error_json : [];
        $this->assertSame('canvas_runtime_merge_no_video_layer', $err['code'] ?? null);
        $this->assertSame('canvas_runtime_merge_no_video_layer', $err['failure_code'] ?? null);
        $meta = is_array($job->meta_json) ? $job->meta_json : [];
        $this->assertFalse((bool) ($meta['canvas_runtime_capture']['ffmpeg_merge_pending'] ?? true));
        $this->assertSame('merge_failed', $meta['canvas_runtime_capture']['phase'] ?? null);
        $this->assertArrayHasKey('canvas_runtime_merge_diagnostics', $meta);
        $this->assertArrayHasKey('canvas_runtime_retention', $meta);
        $this->assertFalse((bool) ($meta['canvas_runtime_retention']['png_frames_deleted_after_failure'] ?? true));
    }

    public function test_non_zero_exit_persists_structured_failure(): void
    {
        Config::set('studio_video.canvas_runtime_export_enabled', true);

        $seed = $this->seedExportJob();
        $job = $seed['job'];

        $mock = Mockery::mock(StudioCanvasRuntimePlaywrightInvokerContract::class);
        $mock->shouldReceive('run')
            ->once()
            ->andReturnUsing(static function (array $command, ?string $cwd, int $timeoutSeconds): array {
                $dir = null;
                foreach ($command as $part) {
                    if (is_string($part) && str_starts_with($part, '--output-dir=')) {
                        $dir = substr($part, strlen('--output-dir='));
                        break;
                    }
                }
                Assert::assertNotNull($dir);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($dir.DIRECTORY_SEPARATOR.'capture-diagnostics.json', json_encode([
                    'schema' => 'studio_canvas_capture_diagnostics_v1',
                    'error' => 'readiness timeout',
                ]));

                return ['exitCode' => 4, 'stdout' => '', 'stderr' => 'playwright failed'];
            });

        $this->app->instance(StudioCanvasRuntimePlaywrightInvokerContract::class, $mock);

        $service = $this->app->make(StudioCompositionCanvasRuntimeVideoExportService::class);
        $service->run($job, $seed['tenant'], $seed['user']);

        $job->refresh();
        $this->assertSame(StudioCompositionVideoExportJob::STATUS_FAILED, $job->status);
        $err = is_array($job->error_json) ? $job->error_json : [];
        $this->assertSame('canvas_runtime_playwright_failed', $err['code'] ?? null);
        $this->assertArrayHasKey('capture_diagnostics_file', $err['debug'] ?? []);
    }

    private function buildTinyMp4Bytes(): string
    {
        $path = sys_get_temp_dir().'/jp-studio-tiny-'.hash('sha256', self::class).'.mp4';
        if (! is_file($path) || filesize($path) < 100) {
            $cmd = sprintf(
                'ffmpeg -y -nostdin -loglevel error -f lavfi -i color=c=black:s=320x240:r=30 -t 2 -pix_fmt yuv420p %s 2>/dev/null',
                escapeshellarg($path)
            );
            exec($cmd, $o, $code);
            if ($code !== 0 || ! is_file($path)) {
                $this->markTestSkipped('ffmpeg not available');
            }
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            $this->markTestSkipped('could not read tiny mp4');
        }

        return $bytes;
    }
}
