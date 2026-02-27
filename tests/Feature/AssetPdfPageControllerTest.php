<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\FullPdfExtractionJob;
use App\Jobs\PdfPageRenderJob;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssetPdfPageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createAsset(string $mimeType = 'application/pdf', string $filename = 'document.pdf'): Asset
    {
        $tenant = Tenant::create(['name' => 'Tenant', 'slug' => 'tenant']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Brand', 'slug' => 'brand']);
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
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'storage_root_path' => 'tenants/' . $tenant->uuid . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.pdf',
            'size_bytes' => 1024,
            'pdf_page_count' => str_contains(strtolower($mimeType), 'pdf') ? 3 : null,
            'pdf_pages_rendered' => false,
        ]);
    }

    protected function createTenantUser(Tenant $tenant, string $tenantRole = 'admin'): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => $tenantRole]);

        return $user;
    }

    public function test_show_returns_ready_when_pdf_page_record_exists(): void
    {
        Bus::fake();
        $asset = $this->createAsset();
        $user = $this->createTenantUser($asset->tenant, 'admin');
        app()->instance('tenant', $asset->tenant);

        AssetPdfPage::create([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'asset_version_id' => null,
            'version_number' => 1,
            'page_number' => 2,
            'storage_path' => 'tenants/' . $asset->tenant->uuid . '/assets/' . $asset->id . '/v1/pdf_pages/page-2.webp',
            'mime_type' => 'image/webp',
            'status' => 'completed',
            'rendered_at' => now(),
        ]);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->getJson("/app/assets/{$asset->id}/pdf-page/2");

        $response->assertOk()
            ->assertJson([
                'status' => 'ready',
                'page' => 2,
                'page_count' => 3,
            ])
            ->assertJsonStructure(['url']);

        Bus::assertNotDispatched(PdfPageRenderJob::class);
    }

    public function test_show_queues_render_job_when_page_is_missing(): void
    {
        Bus::fake();
        $asset = $this->createAsset();
        $user = $this->createTenantUser($asset->tenant, 'admin');
        app()->instance('tenant', $asset->tenant);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->getJson("/app/assets/{$asset->id}/pdf-page/2");

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'processing',
                'page' => 2,
                'page_count' => 3,
            ]);

        Bus::assertDispatched(PdfPageRenderJob::class, function (PdfPageRenderJob $job) use ($asset) {
            return $job->assetId === $asset->id && $job->page === 2;
        });
    }

    public function test_show_throttles_dispatch_so_second_request_does_not_dispatch_again(): void
    {
        Bus::fake();
        $asset = $this->createAsset();
        $user = $this->createTenantUser($asset->tenant, 'admin');
        app()->instance('tenant', $asset->tenant);

        $response1 = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->getJson("/app/assets/{$asset->id}/pdf-page/2");

        $response1->assertStatus(202)->assertJson(['status' => 'processing']);
        $this->assertSame(1, Bus::dispatched(PdfPageRenderJob::class)->count());

        $response2 = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->getJson("/app/assets/{$asset->id}/pdf-page/2");

        $response2->assertStatus(202)->assertJson(['status' => 'processing']);
        $this->assertSame(1, Bus::dispatched(PdfPageRenderJob::class)->count(), 'Throttle should prevent second dispatch within 60s');
    }

    public function test_request_full_extraction_queues_job_for_tenant_admin(): void
    {
        Bus::fake();
        $asset = $this->createAsset();
        $user = $this->createTenantUser($asset->tenant, 'admin');
        app()->instance('tenant', $asset->tenant);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/pdf-pages/full-extraction");

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'queued',
                'asset_id' => $asset->id,
            ]);

        $asset->refresh();
        $this->assertTrue((bool) ($asset->metadata['pdf_full_extraction_requested'] ?? false));
        $this->assertFalse($asset->pdf_pages_rendered);

        Bus::assertDispatched(FullPdfExtractionJob::class, function (FullPdfExtractionJob $job) use ($asset) {
            return $job->assetId === $asset->id;
        });
    }

    public function test_request_full_extraction_forbidden_for_non_admin_or_owner(): void
    {
        Bus::fake();
        $asset = $this->createAsset();
        $user = $this->createTenantUser($asset->tenant, 'member');
        $user->brands()->attach($asset->brand_id, ['role' => 'viewer']);
        app()->instance('tenant', $asset->tenant);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/pdf-pages/full-extraction");

        $response->assertForbidden();
        Bus::assertNotDispatched(FullPdfExtractionJob::class);
    }

    public function test_request_full_extraction_rejects_non_pdf_assets(): void
    {
        Bus::fake();
        $asset = $this->createAsset('image/jpeg', 'image.jpg');
        $user = $this->createTenantUser($asset->tenant, 'admin');
        app()->instance('tenant', $asset->tenant);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/pdf-pages/full-extraction");

        $response->assertStatus(422);
        Bus::assertNotDispatched(FullPdfExtractionJob::class);
    }
}
