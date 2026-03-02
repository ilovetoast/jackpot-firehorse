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
use App\Models\BrandResearchSnapshot;
use App\Models\Category;
use App\Models\PdfTextExtraction;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Jobs\RunBrandResearchJob;
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
}
