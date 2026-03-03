<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandResearchSnapshot;
use App\Models\Category;
use App\Models\PdfTextExtraction;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Jobs\AnalyzeBrandPdfPageJob;
use App\Jobs\ExtractPdfTextJob;
use App\Jobs\MergeBrandPdfExtractionJob;
use App\Jobs\RunBrandIngestionJob;
use App\Jobs\RunBrandPdfVisionExtractionJob;
use App\Jobs\RunBrandResearchJob;
use App\Models\BrandIngestionRecord;
use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\BrandDnaDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Brand Guidelines Builder v1 — backend API tests.
 */
class BrandGuidelinesBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'view brand', 'guard_name' => 'web']);
        Permission::create(['name' => 'brand_settings.manage', 'guard_name' => 'web']);

        $this->tenant = Tenant::create(['name' => 'Builder Tenant', 'slug' => 'builder-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Builder Brand',
            'slug' => 'builder-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Assets',
            'slug' => 'assets',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $this->user = User::create([
            'email' => 'builder@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Builder',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'view brand', 'brand_settings.manage']);
        $this->user->setRoleForTenant($this->tenant, 'admin');
        $this->user->assignRole($role);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'builder-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    public function test_patch_endpoint_creates_draft_if_none_exists(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/patch", [
                'step_key' => 'positioning',
                'payload' => [
                    'identity' => [
                        'beliefs' => ['Quality first'],
                        'values' => ['Integrity'],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('draft_version', $data);
        $this->assertArrayHasKey('id', $data['draft_version']);
        $this->assertSame('draft', $data['draft_version']['status']);

        $draft = BrandModelVersion::find($data['draft_version']['id']);
        $this->assertNotNull($draft);
        $payload = $draft->model_payload ?? [];
        $this->assertSame(['Quality first'], $payload['identity']['beliefs'] ?? null);
        $this->assertSame(['Integrity'], $payload['identity']['values'] ?? null);
    }

    public function test_patch_endpoint_only_updates_allowed_paths_and_preserves_other_keys(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->patchFromStep($this->brand, 'positioning', [
            'identity' => [
                'beliefs' => ['Original belief'],
                'values' => ['Original value'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/patch", [
                'step_key' => 'archetype',
                'payload' => [
                    'personality' => [
                        'primary_archetype' => 'Creator',
                        'candidate_archetypes' => ['Explorer'],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $draft->refresh();
        $payload = $draft->model_payload ?? [];

        // Identity section preserved
        $this->assertSame(['Original belief'], $payload['identity']['beliefs'] ?? null);
        $this->assertSame(['Original value'], $payload['identity']['values'] ?? null);

        // Personality section updated
        $this->assertSame('Creator', $payload['personality']['primary_archetype'] ?? null);
        $this->assertSame(['Explorer'], $payload['personality']['candidate_archetypes'] ?? null);
    }

    public function test_user_defined_color_overrides_extraction(): void
    {
        $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/patch", [
                'step_key' => 'standards',
                'payload' => [
                    'brand_colors' => [
                        'primary_color' => '#003388',
                        'secondary_color' => null,
                        'accent_color' => null,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->brand->refresh();
        $this->assertSame('#003388', $this->brand->primary_color);
        $this->assertTrue($this->brand->primary_color_user_defined, 'User-defined primary_color must set primary_color_user_defined');
    }

    public function test_patch_endpoint_rejects_unknown_step_key(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/patch", [
                'step_key' => 'invalid_step',
                'payload' => ['identity' => ['mission' => 'test']],
            ]);

        $response->assertStatus(422);
    }

    public function test_patch_endpoint_rejects_payload_keys_not_in_allowed_paths(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/patch", [
                'step_key' => 'background',
                'payload' => [
                    'sources' => ['website_url' => 'https://example.com'],
                    'identity' => ['mission' => 'Should not be written'],
                ],
            ]);

        $response->assertStatus(200);
        $draft = $this->brand->brandModel->versions()->where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $payload = $draft->model_payload ?? [];
        $this->assertSame('https://example.com', $payload['sources']['website_url'] ?? null);
        $this->assertEmpty(trim((string) ($payload['identity']['mission'] ?? '')), 'identity.mission must not be written by background step');
    }

    public function test_start_builder_creates_new_draft_even_if_active_exists(): void
    {
        $brandModel = $this->brand->brandModel;
        $activeVersion = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => ['identity' => ['mission' => 'Original']],
            'status' => 'active',
        ]);
        $brandModel->update(['active_version_id' => $activeVersion->id]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->post(route('brands.brand-dna.builder.start', ['brand' => $this->brand->id]));

        $response->assertRedirect();
        $this->assertStringContainsString('brand-guidelines/builder', $response->headers->get('Location'));

        $drafts = $brandModel->versions()->where('status', 'draft')->get();
        $this->assertGreaterThanOrEqual(1, $drafts->count());
    }

    public function test_publish_validator_blocks_publish_when_required_fields_missing(): void
    {
        $brandModel = $this->brand->brandModel;
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'sources' => ['website_url' => null, 'social_urls' => []],
                'identity' => ['mission' => '', 'positioning' => ''],
                'personality' => ['primary_archetype' => null, 'candidate_archetypes' => []],
            ],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/versions/{$version->id}/publish");

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertArrayHasKey('missing_fields', $data);
        $this->assertNotEmpty($data['missing_fields']);
    }

    public function test_publish_activates_version_and_sets_brand_dna_enabled(): void
    {
        $this->brand->update(['primary_color' => '#003388']);
        $brandModel = $this->brand->brandModel;
        if (! $brandModel) {
            $brandModel = BrandModel::create([
                'brand_id' => $this->brand->id,
                'is_enabled' => false,
                'brand_dna_scoring_enabled' => true,
            ]);
        } else {
            $brandModel->update(['is_enabled' => false]);
        }
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'sources' => ['website_url' => 'https://example.com', 'social_urls' => []],
                'identity' => ['mission' => 'Our mission', 'positioning' => 'Our positioning'],
                'personality' => ['primary_archetype' => 'Creator', 'candidate_archetypes' => []],
                'scoring_rules' => [
                    'tone_keywords' => ['artistic', 'visionary', 'bold'],
                    'allowed_color_palette' => [['hex' => '#003388']],
                ],
                'typography' => ['primary_font' => 'Inter'],
            ],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/versions/{$version->id}/publish", [
                'enable_scoring' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame(true, $response->json('brand_dna_enabled'));

        $brandModel->refresh();
        $this->assertTrue($brandModel->is_enabled);

        $version->refresh();
        $this->assertSame('active', $version->status);
    }

    public function test_unpublish_sets_brand_dna_enabled_false_without_deleting_versions(): void
    {
        $brandModel = $this->brand->brandModel;
        if (! $brandModel) {
            $brandModel = BrandModel::create([
                'brand_id' => $this->brand->id,
                'is_enabled' => true,
                'brand_dna_scoring_enabled' => true,
            ]);
        } else {
            $brandModel->update(['is_enabled' => true]);
        }
        BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [],
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/unpublish");

        $response->assertStatus(200);
        $this->assertSame(false, $response->json('brand_dna_enabled'));

        $brandModel->refresh();
        $this->assertFalse($brandModel->is_enabled);

        $this->assertSame(1, $brandModel->versions()->count());
    }

    public function test_asset_grid_excludes_builder_staged_assets(): void
    {
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $normalAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Normal Asset',
            'original_filename' => 'normal.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/normal.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => false,
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        $session2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $stagedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session2->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Staged Asset',
            'original_filename' => 'staged.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/staged.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'logo_primary',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/assets?category={$this->category->slug}");

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');

        $this->assertContains($normalAsset->id, $ids);
        $this->assertNotContains($stagedAsset->id, $ids);
    }

    public function test_prefill_returns_pending_when_no_extraction_yet(): void
    {
        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'fill_empty',
            ]);

        $response->assertStatus(422);
        $this->assertSame('pending', $response->json('status'));
    }

    public function test_prefill_returns_empty_when_extracted_text_empty(): void
    {
        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => '',
            'extraction_source' => 'pdftotext',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'fill_empty',
            ]);

        $response->assertStatus(422);
        $this->assertSame('empty', $response->json('status'));
    }

    public function test_prefill_fill_empty_does_not_overwrite_existing_identity_mission(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->patchFromStep($this->brand, 'positioning', [
            'identity' => [
                'mission' => 'Existing mission',
                'positioning' => 'Existing positioning',
            ],
        ]);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        $text = "Mission\nOur purpose is to change the world.\n\nPositioning\nWe are the best.";
        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => $text,
            'extraction_source' => 'pdftotext',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'fill_empty',
            ]);

        $response->assertStatus(200);
        $this->assertSame('applied', $response->json('status'));
        $this->assertContains('identity.mission', $response->json('skipped'));

        $draft->refresh();
        $payload = $draft->model_payload ?? [];
        $this->assertSame('Existing mission', $payload['identity']['mission'] ?? null);
    }

    public function test_prefill_replace_overwrites_identity_mission(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draftService->patchFromStep($this->brand, 'positioning', [
            'identity' => [
                'mission' => 'Existing mission',
                'positioning' => 'Existing positioning',
            ],
        ]);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        $text = "Mission\nOur purpose is to change the world.\n\nPositioning\nWe are the best.";
        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => $text,
            'extraction_source' => 'pdftotext',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'replace',
            ]);

        $response->assertStatus(200);
        $this->assertSame('applied', $response->json('status'));

        $draft = $this->brand->brandModel->versions()->where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $payload = $draft->model_payload ?? [];
        $this->assertStringContainsString('change the world', $payload['identity']['mission'] ?? '');
    }

    public function test_mapper_extracts_hex_colors_into_allowed_color_palette(): void
    {
        $mapper = app(\App\Services\BrandDNA\GuidelinesPdfToBrandDnaMapper::class);
        $text = "Brand Colors\nPrimary: #003388\nSecondary: #FF6600\n\nUse hex #003388 for headers.";
        $patch = $mapper->map($text, 0);

        $this->assertArrayHasKey('scoring_rules', $patch);
        $palette = $patch['scoring_rules']['allowed_color_palette'] ?? [];
        $this->assertNotEmpty($palette);
        $hexes = array_column($palette, 'hex');
        $this->assertContains('#003388', $hexes);
    }

    public function test_prefill_rejects_asset_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
        $otherBucket = StorageBucket::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'other-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $otherBrand = Brand::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        $session = UploadSession::create([
            'tenant_id' => $otherTenant->id,
            'brand_id' => $otherBrand->id,
            'storage_bucket_id' => $otherBucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $otherTenant->id,
            'brand_id' => $otherBrand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $otherBucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => [],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => 'Mission: Test',
            'extraction_source' => 'pdftotext',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'fill_empty',
            ]);

        $response->assertStatus(404);
    }

    public function test_prefill_rejects_non_builder_staged_asset(): void
    {
        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => false,
            'builder_context' => null,
        ]);

        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => 'Mission: Test',
            'extraction_source' => 'pdftotext',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/prefill-from-guidelines-pdf", [
                'asset_id' => $asset->id,
                'mode' => 'fill_empty',
            ]);

        $response->assertStatus(422);
    }

    public function test_research_insights_snapshot_scoped_to_draft_version(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draftA = $draftService->getOrCreateDraftVersion($this->brand);

        $draftB = $draftService->createNewDraftVersion($this->brand);

        $snapshotA = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftA->id,
            'source_url' => 'https://example-a.com',
            'status' => 'completed',
            'snapshot' => ['brand_bio' => 'Version A'],
            'suggestions' => [],
            'coherence' => ['overall' => ['score' => 70]],
            'alignment' => ['findings' => []],
        ]);

        $snapshotB = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftB->id,
            'source_url' => 'https://example-b.com',
            'status' => 'completed',
            'snapshot' => ['brand_bio' => 'Version B'],
            'suggestions' => [],
            'coherence' => ['overall' => ['score' => 80]],
            'alignment' => ['findings' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/brands/{$this->brand->id}/brand-dna/builder/research-insights");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotNull($data['latestSnapshotLite']);
        $this->assertSame($snapshotB->id, $data['latestSnapshotLite']['id'], 'Research insights must return snapshot for current draft (B), not draft A');
    }

    public function test_run_brand_research_job_links_insight_state_to_snapshot(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        RunBrandResearchJob::dispatchSync($this->brand->id, $draft->id, 'https://example.com');

        $snapshot = BrandResearchSnapshot::where('brand_id', $this->brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($snapshot, 'Job must create completed snapshot');

        $insightState = $draft->insightState;
        $this->assertNotNull($insightState, 'Insight state must exist');
        $this->assertSame($snapshot->id, $insightState->source_snapshot_id, 'Insight state must link to snapshot');
    }

    public function test_run_brand_research_does_not_modify_draft_fields(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->patchFromStep($this->brand, 'purpose_promise', [
            'identity' => [
                'mission' => 'Original',
            ],
        ]);

        $payloadBefore = $draft->model_payload ?? [];
        $this->assertSame('Original', $payloadBefore['identity']['mission'] ?? null);

        RunBrandResearchJob::dispatchSync($this->brand->id, $draft->id, 'https://example.com');

        $draft->refresh();
        $payloadAfter = $draft->model_payload ?? [];
        $this->assertSame('Original', $payloadAfter['identity']['mission'] ?? null, 'Research job must not modify draft fields');

        $snapshot = BrandResearchSnapshot::where('brand_id', $this->brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($snapshot, 'Job must create completed snapshot');
        $this->assertNotNull($snapshot->suggestions, 'Snapshot must have suggestions');
    }

    public function test_apply_suggestion_updates_draft_and_insight_state(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);
        $draft->update([
            'model_payload' => array_merge($draft->model_payload ?? [], [
                'scoring_rules' => ['allowed_color_palette' => []],
            ]),
        ]);

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'https://example.com',
            'status' => 'completed',
            'snapshot' => ['primary_colors' => ['#FF0000', '#00FF00']],
            'suggestions' => [
                [
                    'key' => 'SUG:standards.allowed_color_palette',
                    'path' => 'scoring_rules.allowed_color_palette',
                    'type' => 'merge',
                    'value' => [['hex' => '#FF0000'], ['hex' => '#00FF00']],
                    'reason' => 'Detected website colors.',
                    'confidence' => 0.9,
                ],
            ],
            'coherence' => ['overall' => ['score' => 70]],
            'alignment' => ['findings' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/snapshots/{$snapshot->id}/apply", [
                'key' => 'SUG:standards.allowed_color_palette',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('draft', $data);
        $this->assertArrayHasKey('insightState', $data);
        $this->assertArrayHasKey('coherence_delta', $data);
        $this->assertContains('SUG:standards.allowed_color_palette', $data['insightState']['accepted'] ?? []);
        $this->assertArrayHasKey('overall_delta', $data['coherence_delta']);
        $this->assertArrayHasKey('section_deltas', $data['coherence_delta']);
        $this->assertArrayHasKey('resolved_risks', $data['coherence_delta']);
        $this->assertArrayHasKey('new_risks', $data['coherence_delta']);

        $draft->refresh();
        $palette = ($draft->model_payload ?? [])['scoring_rules']['allowed_color_palette'] ?? [];
        $this->assertNotEmpty($palette);
        $hexes = array_map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : $c, $palette);
        $this->assertContains('#FF0000', $hexes);
        $this->assertContains('#00FF00', $hexes);
    }

    public function test_dismiss_suggestion_adds_to_insight_state(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'https://example.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [
                [
                    'key' => 'SUG:standards.logo',
                    'path' => 'visual.detected_logo',
                    'type' => 'informational',
                    'value' => 'https://example.com/logo.png',
                    'reason' => 'Logo detected.',
                    'confidence' => 0.6,
                ],
            ],
            'coherence' => [],
            'alignment' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/snapshots/{$snapshot->id}/dismiss", [
                'key' => 'SUG:standards.logo',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertContains('SUG:standards.logo', $data['insightState']['dismissed'] ?? []);
    }

    public function test_show_research_snapshot_filters_dismissed_suggestions(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);
        $state = $draft->getOrCreateInsightState();
        $state->update(['dismissed' => ['SUG:standards.logo']]);

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'https://example.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [
                [
                    'key' => 'SUG:standards.logo',
                    'path' => 'visual.detected_logo',
                    'type' => 'informational',
                    'value' => 'https://example.com/logo.png',
                    'reason' => 'Logo detected.',
                    'confidence' => 0.6,
                ],
                [
                    'key' => 'SUG:standards.allowed_color_palette',
                    'path' => 'scoring_rules.allowed_color_palette',
                    'type' => 'update',
                    'value' => ['#FF0000'],
                    'reason' => 'Colors.',
                    'confidence' => 0.9,
                ],
            ],
            'coherence' => [],
            'alignment' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/brands/{$this->brand->id}/brand-dna/builder/research-snapshots/{$snapshot->id}");

        $response->assertStatus(200);
        $suggestions = $response->json('snapshot.suggestions') ?? [];
        $keys = array_column($suggestions, 'key');
        $this->assertNotContains('SUG:standards.logo', $keys);
        $this->assertContains('SUG:standards.allowed_color_palette', $keys);
    }

    public function test_snapshot_listing_scoped_to_version(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draftA = $draftService->getOrCreateDraftVersion($this->brand);
        $draftB = $draftService->createNewDraftVersion($this->brand);

        BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftA->id,
            'source_url' => 'https://example-a.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => ['overall' => ['score' => 70]],
            'alignment' => ['findings' => []],
        ]);

        BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftB->id,
            'source_url' => 'https://example-b.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [['key' => 'SUG:x', 'path' => 'x', 'type' => 'informational', 'value' => null]],
            'coherence' => ['overall' => ['score' => 80]],
            'alignment' => ['findings' => [['id' => 'F1']]],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/brands/{$this->brand->id}/brand-dna/builder/research-snapshots");

        $response->assertStatus(200);
        $snapshots = $response->json('snapshots') ?? [];
        $this->assertCount(1, $snapshots, 'Listing must return only snapshots for current draft (B)');
        $this->assertSame('https://example-b.com', $snapshots[0]['source_url']);
        $this->assertSame(80, $snapshots[0]['coherence_overall']);
        $this->assertSame(1, $snapshots[0]['suggestions_count']);
        $this->assertSame(1, $snapshots[0]['alignment_findings_count']);
    }

    public function test_snapshot_compare_requires_same_version(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draftA = $draftService->getOrCreateDraftVersion($this->brand);
        $draftB = $draftService->createNewDraftVersion($this->brand);

        $snapshotA = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftA->id,
            'source_url' => 'https://example-a.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => [],
            'alignment' => [],
        ]);

        $snapshotB = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draftB->id,
            'source_url' => 'https://example-b.com',
            'status' => 'completed',
            'snapshot' => [],
            'suggestions' => [],
            'coherence' => [],
            'alignment' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/brands/{$this->brand->id}/brand-dna/builder/research-snapshots/{$snapshotA->id}/compare/{$snapshotB->id}");

        $response->assertStatus(422);
        $this->assertSame('Snapshots must belong to the same draft version.', $response->json('error'));
    }

    public function test_apply_returns_coherence_delta(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);
        $draft->update([
            'model_payload' => array_merge($draft->model_payload ?? [], [
                'scoring_rules' => ['allowed_color_palette' => []],
            ]),
        ]);

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'https://example.com',
            'status' => 'completed',
            'snapshot' => ['primary_colors' => ['#FF0000']],
            'suggestions' => [
                [
                    'key' => 'SUG:standards.allowed_color_palette',
                    'path' => 'scoring_rules.allowed_color_palette',
                    'type' => 'merge',
                    'value' => [['hex' => '#FF0000']],
                    'reason' => 'Detected colors.',
                    'confidence' => 0.9,
                ],
            ],
            'coherence' => [
                'overall' => ['score' => 65],
                'sections' => ['standards' => ['score' => 60]],
                'risks' => [['id' => 'COH:COLOR_MISMATCH', 'label' => 'Color mismatch']],
            ],
            'alignment' => ['findings' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/snapshots/{$snapshot->id}/apply", [
                'key' => 'SUG:standards.allowed_color_palette',
            ]);

        $response->assertStatus(200);
        $delta = $response->json('coherence_delta');
        $this->assertNotNull($delta);
        $this->assertIsInt($delta['overall_delta']);
        $this->assertIsArray($delta['section_deltas']);
        $this->assertIsArray($delta['resolved_risks']);
        $this->assertIsArray($delta['new_risks']);
    }

    public function test_ingestion_creates_snapshot(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => "Brand Archetype: Creator\nMission: We empower creators.\nPrimary Color: #003388",
            'extraction_source' => 'pdftotext',
        ]);

        RunBrandIngestionJob::dispatchSync(
            $this->brand->id,
            $draft->id,
            $asset->id,
            null,
            []
        );

        $record = BrandIngestionRecord::where('brand_id', $this->brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->latest()
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(BrandIngestionRecord::STATUS_COMPLETED, $record->status);

        $snapshot = BrandResearchSnapshot::where('brand_id', $this->brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('source_url', 'ingestion')
            ->latest()
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('completed', $snapshot->status);
        $this->assertNotEmpty($snapshot->suggestions);
    }

    public function test_conflict_is_stored_on_snapshot(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $pdfAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $pdfAsset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => "Brand Archetype: Creator\nMission: We empower creators.\nPrimary Color: #003388",
            'extraction_source' => 'pdftotext',
        ]);

        $materialAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Material PDF',
            'original_filename' => 'material.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/material.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'brand_material',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $materialAsset->id,
            'status' => PdfTextExtraction::STATUS_COMPLETE,
            'extracted_text' => "Brand Archetype: Hero\nMission: We lead boldly.\nPrimary Color: #FF0000",
            'extraction_source' => 'pdftotext',
        ]);

        RunBrandIngestionJob::dispatchSync(
            $this->brand->id,
            $draft->id,
            $pdfAsset->id,
            null,
            [$materialAsset->id]
        );

        $snapshot = BrandResearchSnapshot::where('brand_id', $this->brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('source_url', 'ingestion')
            ->latest()
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('completed', $snapshot->status);

        $snapshotData = $snapshot->snapshot ?? [];
        $conflicts = $snapshotData['conflicts'] ?? [];
        $this->assertNotEmpty($conflicts, 'Snapshot must store conflicts when sources disagree');

        $archetypeConflict = collect($conflicts)->firstWhere('field', 'personality.primary_archetype');
        $this->assertNotNull($archetypeConflict);
        $this->assertArrayHasKey('candidates', $archetypeConflict);
        $this->assertArrayHasKey('recommended', $archetypeConflict);
        $this->assertArrayHasKey('recommended_weight', $archetypeConflict);
        $this->assertCount(2, $archetypeConflict['candidates']);
    }

    public function test_apply_rejected_when_overriding_user_data(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);
        $draft->update([
            'model_payload' => array_merge($draft->model_payload ?? [], [
                'personality' => ['primary_archetype' => 'Creator'],
            ]),
        ]);

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => 'https://example.com',
            'status' => 'completed',
            'snapshot' => ['conflicts' => []],
            'suggestions' => [
                [
                    'key' => 'SUG:personality.primary_archetype',
                    'path' => 'personality.primary_archetype',
                    'type' => 'update',
                    'value' => 'Ruler',
                    'reason' => 'PDF explicitly declares Ruler.',
                    'confidence' => 0.9,
                    'weight' => 0.7,
                ],
            ],
            'coherence' => ['overall' => ['score' => 70]],
            'alignment' => ['findings' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/brand-dna/builder/snapshots/{$snapshot->id}/apply", [
                'key' => 'SUG:personality.primary_archetype',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Cannot override user-defined value.']);

        $draft->refresh();
        $this->assertSame('Creator', ($draft->model_payload ?? [])['personality']['primary_archetype'] ?? null);
    }

    public function test_cannot_access_archetype_step_while_processing(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/brands/{$this->brand->id}/brand-guidelines/builder?step=archetype");

        $response->assertRedirect();
        $this->assertStringContainsString('step=background', $response->headers->get('Location'));
        $this->assertStringContainsString('processing=1', $response->headers->get('Location'));
    }

    public function test_cannot_proceed_without_snapshot(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draftService->getOrCreateDraftVersion($this->brand);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/brands/{$this->brand->id}/brand-guidelines/builder?step=archetype");

        $response->assertRedirect();
        $this->assertStringContainsString('step=background', $response->headers->get('Location'));
    }

    public function test_extraction_failure_sets_failed_status(): void
    {
        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        $extraction = PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        $extractionService = $this->mock(\App\Services\PdfTextExtractionService::class);
        $extractionService->shouldReceive('isPdftotextAvailable')->andReturn(true);
        $extractionService->shouldReceive('extractFromPath')
            ->andReturn(['text' => '', 'source' => 'pdftotext', 'exit_code' => 0, 'stderr' => '']);

        $tempPath = sys_get_temp_dir() . '/test-empty-' . uniqid() . '.pdf';
        file_put_contents($tempPath, '%PDF-1.4 minimal');
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
    }

    public function test_ingestion_auto_triggers_after_extraction(): void
    {
        \Illuminate\Support\Facades\Bus::fake([RunBrandIngestionJob::class]);

        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        $extraction = PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        $extractionService = $this->mock(\App\Services\PdfTextExtractionService::class);
        $extractionService->shouldReceive('isPdftotextAvailable')->andReturn(true);
        $extractionService->shouldReceive('extractFromPath')
            ->andReturn(['text' => "Brand Archetype: Creator\nMission: Test.", 'source' => 'pdftotext', 'exit_code' => 0, 'stderr' => '']);

        $tempPath = sys_get_temp_dir() . '/test-pdf-' . uniqid() . '.pdf';
        file_put_contents($tempPath, '%PDF-1.4 minimal');
        $pdfPageRenderingService = $this->mock(\App\Services\PdfPageRenderingService::class);
        $pdfPageRenderingService->shouldReceive('downloadSourcePdfToTemp')->andReturn($tempPath);

        $job = new ExtractPdfTextJob($asset->id, $extraction->id, null);
        $job->handle($pdfPageRenderingService, $extractionService);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        \Illuminate\Support\Facades\Bus::assertDispatched(RunBrandIngestionJob::class, function ($job) use ($draft, $asset) {
            return $job->brandId === $this->brand->id
                && $job->brandModelVersionId === $draft->id
                && $job->pdfAssetId === $asset->id;
        });
    }

    public function test_pdf_vision_fallback_triggers_when_text_empty(): void
    {
        \Illuminate\Support\Facades\Bus::fake([RunBrandPdfVisionExtractionJob::class]);

        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        $extraction = PdfTextExtraction::create([
            'asset_id' => $asset->id,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        $extractionService = $this->mock(\App\Services\PdfTextExtractionService::class);
        $extractionService->shouldReceive('isPdftotextAvailable')->andReturn(true);
        $extractionService->shouldReceive('extractFromPath')
            ->andReturn(['text' => '', 'source' => 'pdftotext', 'exit_code' => 0, 'stderr' => '']);

        $tempPath = sys_get_temp_dir() . '/test-empty-' . uniqid() . '.pdf';
        file_put_contents($tempPath, '%PDF-1.4 minimal');
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

        \Illuminate\Support\Facades\Bus::assertDispatched(RunBrandPdfVisionExtractionJob::class, function ($job) use ($asset, $draft) {
            return $job->assetId === $asset->id
                && $job->brandId === $this->brand->id
                && $job->brandModelVersionId === $draft->id;
        });
    }

    public function test_parallel_page_processing_merges_results(): void
    {
        \Illuminate\Support\Facades\Bus::fake([RunBrandIngestionJob::class]);

        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        $batchId = 'vision_test_' . uniqid();
        $visionBatch = BrandPdfVisionExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'pages_total' => 2,
            'pages_processed' => 0,
            'status' => BrandPdfVisionExtraction::STATUS_PROCESSING,
        ]);

        $tempDir = sys_get_temp_dir() . '/pdf-pages-' . uniqid();
        mkdir($tempDir, 0755, true);
        $page1Path = $tempDir . '/page-1.png';
        $page2Path = $tempDir . '/page-2.png';
        file_put_contents($page1Path, 'fake-png');
        file_put_contents($page2Path, 'fake-png');

        $page1 = BrandPdfPageExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'page_number' => 1,
            'extraction_json' => [
                '_temp_image_path' => $page1Path,
                'identity' => ['mission' => 'Test mission', 'vision' => null, 'positioning' => null],
                'personality' => ['primary_archetype' => 'Creator', 'tone_keywords' => ['bold', 'creative'], 'traits' => []],
                'visual' => ['primary_colors' => ['#FF0000'], 'fonts' => ['Helvetica']],
            ],
            'status' => BrandPdfPageExtraction::STATUS_COMPLETED,
        ]);

        $page2 = BrandPdfPageExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'page_number' => 2,
            'extraction_json' => [
                '_temp_image_path' => $page2Path,
                'identity' => ['mission' => null, 'vision' => 'Test vision', 'positioning' => null],
                'personality' => ['primary_archetype' => null, 'tone_keywords' => ['innovative'], 'traits' => []],
                'visual' => ['primary_colors' => ['#00FF00'], 'fonts' => []],
            ],
            'status' => BrandPdfPageExtraction::STATUS_COMPLETED,
        ]);

        $pageRenderer = $this->mock(\App\Services\BrandDNA\Extraction\PdfPageRenderer::class);
        $pageRenderer->shouldReceive('cleanupPages')->once();

        $job = new MergeBrandPdfExtractionJob($batchId);
        $job->handle($pageRenderer);

        $visionBatch->refresh();
        $this->assertSame(BrandPdfVisionExtraction::STATUS_COMPLETED, $visionBatch->status);
        $merged = $visionBatch->extraction_json ?? [];
        $this->assertSame('Test mission', $merged['identity']['mission'] ?? null);
        $this->assertSame('Test vision', $merged['identity']['vision'] ?? null);
        $this->assertSame('Creator', $merged['personality']['primary_archetype'] ?? null);
        $this->assertContains('#FF0000', $merged['visual']['primary_colors'] ?? []);
        $this->assertContains('#00FF00', $merged['visual']['primary_colors'] ?? []);

        \Illuminate\Support\Facades\Bus::assertDispatched(RunBrandIngestionJob::class, function ($job) {
            return $job->brandId === $this->brand->id;
        });

        if (is_dir($tempDir)) {
            @unlink($page1Path);
            @unlink($page2Path);
            @rmdir($tempDir);
        }
    }

    public function test_early_exit_stops_remaining_jobs(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        $batchId = 'vision_early_' . uniqid();
        $visionBatch = BrandPdfVisionExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'pages_total' => 3,
            'pages_processed' => 1,
            'signals_detected' => 5,
            'early_complete' => true,
            'status' => BrandPdfVisionExtraction::STATUS_PROCESSING,
        ]);

        $tempPath = sys_get_temp_dir() . '/page-pending-' . uniqid() . '.png';
        file_put_contents($tempPath, 'fake');

        $pendingPage = BrandPdfPageExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'page_number' => 2,
            'extraction_json' => ['_temp_image_path' => $tempPath],
            'status' => BrandPdfPageExtraction::STATUS_PENDING,
        ]);

        $job = new AnalyzeBrandPdfPageJob($pendingPage->id);
        $job->handle(app(\App\Services\BrandDNA\Extraction\VisionExtractionService::class));

        $pendingPage->refresh();
        $this->assertSame(BrandPdfPageExtraction::STATUS_CANCELLED, $pendingPage->status);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_processing_dashboard_returns_page_progress(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandPdfVisionExtraction::create([
            'batch_id' => 'batch_dash_' . uniqid(),
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'pages_total' => 12,
            'pages_processed' => 6,
            'signals_detected' => 4,
            'early_complete' => false,
            'status' => BrandPdfVisionExtraction::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/brands/{$this->brand->id}/brand-dna/builder/research-insights");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('pdf', $data);
        $this->assertSame('processing', $data['pdf']['status']);
        $this->assertSame(12, $data['pdf']['pages_total']);
        $this->assertSame(6, $data['pdf']['pages_processed']);
        $this->assertSame(4, $data['pdf']['signals_detected']);
        $this->assertFalse($data['pdf']['early_complete']);
        $this->assertSame('processing', $data['overall_status']);
    }

    public function test_cannot_proceed_while_any_source_processing(): void
    {
        $draftService = app(BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($this->brand);

        $session = UploadSession::create([
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
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Guidelines PDF',
            'original_filename' => 'guidelines.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/guidelines.pdf',
            'metadata' => ['category_id' => $this->category->id],
            'builder_staged' => true,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandModelVersionAsset::create([
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'builder_context' => 'guidelines_pdf',
        ]);

        BrandPdfVisionExtraction::create([
            'batch_id' => 'batch_block_' . uniqid(),
            'brand_id' => $this->brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $asset->id,
            'pages_total' => 5,
            'pages_processed' => 2,
            'status' => BrandPdfVisionExtraction::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/brands/{$this->brand->id}/brand-guidelines/builder?step=archetype");

        $response->assertRedirect();
        $this->assertStringContainsString('step=background', $response->headers->get('Location'));
        $this->assertStringContainsString('processing=1', $response->headers->get('Location'));
    }
}
