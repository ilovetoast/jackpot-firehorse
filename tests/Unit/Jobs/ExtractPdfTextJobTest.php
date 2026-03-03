<?php

namespace Tests\Unit\Jobs;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\ExtractPdfTextJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\PdfTextExtraction;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtractPdfTextJobTest extends TestCase
{
    use RefreshDatabase;

    protected function createPdfAsset(): Asset
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
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
            'title' => 'Test PDF',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/' . $tenant->uuid . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.pdf',
            'size_bytes' => 1024,
        ]);
    }

    public function test_empty_pdf_sets_failed_status(): void
    {
        $asset = $this->createPdfAsset();
        $extraction = PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        $extractionService = $this->mock(\App\Services\PdfTextExtractionService::class);
        $extractionService->shouldReceive('isPdftotextAvailable')->andReturn(true);
        $extractionService->shouldReceive('extractFromPath')
            ->andReturn(['text' => '', 'source' => 'pdftotext', 'exit_code' => 0, 'stderr' => '']);

        $tempPath = sys_get_temp_dir() . '/test-empty-' . uniqid() . '.pdf';
        file_put_contents($tempPath, '%PDF-1.4');
        $pdfPageRenderingService = $this->mock(\App\Services\PdfPageRenderingService::class);
        $pdfPageRenderingService->shouldReceive('downloadSourcePdfToTemp')->andReturn($tempPath);

        $job = new ExtractPdfTextJob($asset->id, $extraction->id, null);
        $job->handle($pdfPageRenderingService, $extractionService);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        $extraction->refresh();
        $this->assertSame(PdfTextExtraction::STATUS_FAILED, $extraction->status);
        $this->assertSame('No selectable text detected', $extraction->failure_reason);
        $this->assertNull($extraction->extracted_text);
    }
}
