<?php

namespace Tests\Unit\Models;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\FullPdfExtractionJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssetPdfExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function createAsset(string $mime = 'application/pdf', string $filename = 'document.pdf'): Asset
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
            'title' => 'Test',
            'original_filename' => $filename,
            'mime_type' => $mime,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/' . $tenant->uuid . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.pdf',
            'size_bytes' => 1024,
        ]);
    }

    public function test_request_full_pdf_extraction_dispatches_job_and_sets_metadata(): void
    {
        Bus::fake();
        $asset = $this->createAsset();

        $result = $asset->requestFullPdfExtraction('123');

        $this->assertTrue($result);
        $asset->refresh();
        $this->assertFalse($asset->pdf_pages_rendered);
        $this->assertNotNull($asset->metadata['pdf_full_extraction_requested_at'] ?? null);
        $this->assertSame('123', $asset->metadata['pdf_full_extraction_requested_by'] ?? null);
        Bus::assertDispatched(FullPdfExtractionJob::class);
    }

    public function test_request_full_pdf_extraction_returns_false_for_non_pdf_assets(): void
    {
        Bus::fake();
        $asset = $this->createAsset('image/jpeg', 'image.jpg');

        $result = $asset->requestFullPdfExtraction();

        $this->assertFalse($result);
        Bus::assertNotDispatched(FullPdfExtractionJob::class);
    }
}
