<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Exceptions\Model3dPreviewFailedException;
use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Locks the contract that {@see Model3dPreviewFailedException} from
 * ThumbnailGenerationService is treated as a *terminal user-data failure*
 * by GenerateThumbnailsJob:
 *   • Asset goes to SKIPPED (not FAILED, not stuck PROCESSING)
 *   • Catch block returns cleanly — no rethrow → no queue retry, no Sentry event
 *   • Structured diagnostics persist under metadata.model_3d_preview so the
 *     asset card / support tooling can show a real reason
 *
 * Regression guard for the staging Sentry spam where a single broken 3D
 * upload produced up to 32 `Blender process error.` events because the job
 * retried the same broken file on the same hardware.
 */
class Model3dPreviewFailedTerminalSkipTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    public function test_blender_process_error_marks_asset_skipped_without_rethrow(): void
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 4096,
            'uploaded_size' => 4096,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'broken model',
            'original_filename' => 'broken.glb',
            'mime_type' => 'model/gltf-binary',
            'size_bytes' => 4096,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/broken.glb',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'generating_thumbnails',
        ]);

        // Reproduce the staging Sentry payload: ThumbnailGenerationService throws
        // the typed exception with the same shape attemptBlenderOrStubMaster does.
        $blenderException = new Model3dPreviewFailedException(
            message: 'Blender process error.',
            userMessage: 'Blender process error.',
            fileType: 'model_glb',
            blenderAttempted: true,
            invalidSource: false,
            debug: ['attempts' => [['attempt' => 'default', 'exit_code' => 1]]],
        );

        $mockService = Mockery::mock(ThumbnailGenerationService::class);
        // Both legacy and version-aware code paths should surface the same
        // typed exception — the catch block detects either via getPrevious() walk.
        $mockService->shouldReceive('generateThumbnails')
            ->andThrow($blenderException);
        $mockService->shouldReceive('generateThumbnailsForVersion')
            ->andThrow($blenderException);
        $mockService->shouldReceive('abandonProfilingAfterJobFailure')->andReturnNull();
        $this->app->instance(ThumbnailGenerationService::class, $mockService);

        // The job MUST swallow the exception (catch → applyModel3dPreviewSkip → return)
        // so the queue completes successfully without retrying or hitting Sentry.
        $threw = null;
        try {
            $job = new GenerateThumbnailsJob($asset->id);
            $job->handle(
                app(ThumbnailGenerationService::class),
                app(\App\Services\PdfPageRenderingService::class)
            );
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNull(
            $threw,
            'Model3dPreviewFailedException must be caught and translated to terminal SKIPPED — '
            . 'rethrowing would cause 32× retries and Sentry spam. '
            . 'Got: '.($threw ? get_class($threw).': '.$threw->getMessage() : 'none'),
        );

        $asset->refresh();

        $this->assertSame(
            ThumbnailStatus::SKIPPED,
            $asset->thumbnail_status,
            'Asset should be SKIPPED (terminal, no-retry) after a 3D preview failure, not FAILED. '
            . 'Got: '.($asset->thumbnail_status?->value ?? 'null'),
        );

        $metadata = $asset->metadata ?? [];
        $this->assertSame(
            'model3d_preview_failed',
            $metadata['thumbnail_skip_reason'] ?? null,
            'metadata.thumbnail_skip_reason must classify the failure for support tooling',
        );
        $this->assertNotEmpty(
            $metadata['preview_unavailable_user_message'] ?? null,
            'metadata.preview_unavailable_user_message must surface a user-facing reason',
        );
        $this->assertIsArray($metadata['model_3d_preview'] ?? null);
        $this->assertSame('model_glb', $metadata['model_3d_preview']['file_type'] ?? null);
        $this->assertTrue($metadata['model_3d_preview']['blender_attempted'] ?? false);
        $this->assertFalse($metadata['model_3d_preview']['invalid_glb_source'] ?? true);
        $this->assertNotEmpty($metadata['model_3d_preview']['failure_message'] ?? null);
    }

    public function test_invalid_glb_source_marks_asset_skipped_with_distinct_reason(): void
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'fake glb',
            'original_filename' => 'fake.glb',
            'mime_type' => 'model/gltf-binary',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/fake.glb',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'generating_thumbnails',
        ]);

        $invalidGlbException = new Model3dPreviewFailedException(
            message: 'This object is not valid GLB binary (bad_magic).',
            userMessage: 'This object is not valid GLB binary (bad_magic).',
            fileType: 'model_glb',
            blenderAttempted: false,
            invalidSource: true,
        );

        $mockService = Mockery::mock(ThumbnailGenerationService::class);
        $mockService->shouldReceive('generateThumbnails')->andThrow($invalidGlbException);
        $mockService->shouldReceive('generateThumbnailsForVersion')->andThrow($invalidGlbException);
        $mockService->shouldReceive('abandonProfilingAfterJobFailure')->andReturnNull();
        $this->app->instance(ThumbnailGenerationService::class, $mockService);

        $threw = null;
        try {
            $job = new GenerateThumbnailsJob($asset->id);
            $job->handle(
                app(ThumbnailGenerationService::class),
                app(\App\Services\PdfPageRenderingService::class)
            );
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNull($threw, 'invalid GLB source must also be caught & translated to SKIPPED');
        $asset->refresh();

        $this->assertSame(ThumbnailStatus::SKIPPED, $asset->thumbnail_status);
        $metadata = $asset->metadata ?? [];
        $this->assertSame(
            'model3d_invalid_source',
            $metadata['thumbnail_skip_reason'] ?? null,
            'invalid GLB bytes use a different reason key than Blender process errors',
        );
        $this->assertTrue($metadata['model_3d_preview']['invalid_glb_source'] ?? false);
        $this->assertFalse($metadata['model_3d_preview']['blender_attempted'] ?? true);
    }
}
