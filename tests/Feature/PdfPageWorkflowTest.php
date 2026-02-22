<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\FullPdfExtractionJob;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\PdfPageRenderJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AssetVariantPathResolver;
use App\Services\PdfPageRenderingService;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PdfPageWorkflowTest extends TestCase
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
            'name' => 'PDF Tenant',
            'slug' => 'pdf-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PDF Brand',
            'slug' => 'pdf-brand',
        ]);

        $this->user = User::create([
            'email' => 'pdf-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'PDF',
            'last_name' => 'Admin',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'pdf-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_upload_pdf_dispatches_page_one_render_job(): void
    {
        Queue::fake();

        $asset = $this->createPdfAsset();
        $job = new ProcessAssetJob($asset->id);
        $job->handle();

        Queue::assertPushed(PdfPageRenderJob::class, function (PdfPageRenderJob $queued) use ($asset) {
            return $queued->assetId === $asset->id && $queued->page === 1;
        });
    }

    public function test_pdf_page_endpoint_returns_processing_when_page_missing(): void
    {
        Queue::fake();
        $asset = $this->createPdfAsset(['pdf_page_count' => 3]);

        $pdfServiceMock = Mockery::mock(PdfPageRenderingService::class);
        $pdfServiceMock->shouldReceive('isPdfAsset')->andReturn(true);
        $pdfServiceMock->shouldReceive('getPdfPageCount')->andReturn(3);
        $pdfServiceMock->shouldReceive('pageExists')->andReturn(false);
        $this->app->instance(PdfPageRenderingService::class, $pdfServiceMock);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/assets/{$asset->id}/pdf-page/2");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'processing',
                'page' => 2,
            ]);

        Queue::assertPushed(PdfPageRenderJob::class, function (PdfPageRenderJob $queued) use ($asset) {
            return $queued->assetId === $asset->id && $queued->page === 2;
        });
    }

    public function test_pdf_page_endpoint_returns_ready_with_url_when_page_exists(): void
    {
        Queue::fake();
        $asset = $this->createPdfAsset(['pdf_page_count' => 3]);

        $pdfServiceMock = Mockery::mock(PdfPageRenderingService::class);
        $pdfServiceMock->shouldReceive('isPdfAsset')->andReturn(true);
        $pdfServiceMock->shouldReceive('getPdfPageCount')->andReturn(3);
        $pdfServiceMock->shouldReceive('pageExists')->andReturn(true);
        $this->app->instance(PdfPageRenderingService::class, $pdfServiceMock);

        $deliveryServiceMock = Mockery::mock(\App\Services\AssetDeliveryService::class);
        $deliveryServiceMock->shouldReceive('url')->andReturn('https://cdn.example.com/pdf_pages/page-2.webp');
        $this->app->instance(\App\Services\AssetDeliveryService::class, $deliveryServiceMock);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/assets/{$asset->id}/pdf-page/2");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ready',
                'page' => 2,
                'url' => 'https://cdn.example.com/pdf_pages/page-2.webp',
            ]);

        Queue::assertNotPushed(PdfPageRenderJob::class);
    }

    public function test_full_pdf_extraction_starts_batch_jobs(): void
    {
        Bus::fake();

        $asset = $this->createPdfAsset();

        $pdfServiceMock = Mockery::mock(PdfPageRenderingService::class);
        $pdfServiceMock->shouldReceive('isPdfAsset')->andReturn(true);
        $pdfServiceMock->shouldReceive('getPdfPageCount')->andReturn(4);
        $this->app->instance(PdfPageRenderingService::class, $pdfServiceMock);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->post("/app/assets/{$asset->id}/pdf/extract-all");

        $response->assertStatus(202)->assertJson(['status' => 'started']);

        Bus::assertBatched(function ($batch) use ($asset) {
            if (count($batch->jobs) !== 4) {
                return false;
            }

            foreach ($batch->jobs as $job) {
                if (! $job instanceof FullPdfExtractionJob || $job->assetId !== $asset->id) {
                    return false;
                }
            }

            return true;
        });

        $asset->refresh();
        $this->assertNotNull($asset->full_pdf_extraction_batch_id);
    }

    public function test_version_change_produces_new_pdf_page_storage_path(): void
    {
        $asset = $this->createPdfAsset([
            'storage_root_path' => "tenants/{$this->tenant->uuid}/assets/test-asset/v1/original.pdf",
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $v1Path = $resolver->resolve($asset, \App\Support\AssetVariant::PDF_PAGE->value, ['page' => 2]);

        $asset->update([
            'storage_root_path' => "tenants/{$this->tenant->uuid}/assets/test-asset/v2/original.pdf",
        ]);
        $asset->refresh();

        $v2Path = $resolver->resolve($asset, \App\Support\AssetVariant::PDF_PAGE->value, ['page' => 2]);

        $this->assertNotSame($v1Path, $v2Path);
        $this->assertStringContainsString('/v1/pdf_pages/page-2.webp', $v1Path);
        $this->assertStringContainsString('/v2/pdf_pages/page-2.webp', $v2Path);
    }

    public function test_large_pdf_guardrail_marks_asset_unsupported_and_skips_generation(): void
    {
        config(['pdf.max_allowed_pages' => 50]);

        $asset = $this->createPdfAsset([
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'generating_thumbnails',
        ]);

        $thumbnailServiceMock = Mockery::mock(ThumbnailGenerationService::class);
        $thumbnailServiceMock->shouldNotReceive('generateThumbnails');

        $pdfServiceMock = Mockery::mock(PdfPageRenderingService::class);
        $pdfServiceMock->shouldReceive('getPdfPageCount')->andReturn(120);

        $job = new GenerateThumbnailsJob($asset->id);
        $job->handle($thumbnailServiceMock, $pdfServiceMock);

        $asset->refresh();
        $this->assertTrue((bool) $asset->pdf_unsupported_large);
        $this->assertSame(120, (int) $asset->pdf_page_count);
        $this->assertSame(ThumbnailStatus::SKIPPED, $asset->thumbnail_status);
        $this->assertStringContainsString('exceeds allowed limit', (string) $asset->thumbnail_error);
    }

    protected function createPdfAsset(array $overrides = []): Asset
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 2048,
            'uploaded_size' => 2048,
        ]);

        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Sample PDF',
            'original_filename' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'storage_root_path' => "tenants/{$this->tenant->uuid}/assets/sample/v1/original.pdf",
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ], $overrides));
    }
}
