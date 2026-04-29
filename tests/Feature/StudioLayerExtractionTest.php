<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StorageBucket;
use App\Models\StudioLayerExtractionSession;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\MockInpaintLayerExtractionProvider;
use Tests\TestCase;

class StudioLayerExtractionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Brand $brand;

    private StorageBucket $bucket;

    private User $admin;

    private User $viewer;

    private Composition $composition;

    private Asset $asset;

    private string $layerId = 'layer-img-1';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for Studio layer extraction tests.');
        }

        // /app/* runs EnsureGatewayEntry before the route can rely on the same invariants as production;
        // for these API-style JSON tests, tenant+brand are supplied via withSession(…).
        $this->withoutMiddleware(\App\Http\Middleware\EnsureGatewayEntry::class);

        Storage::fake('s3');
        Storage::fake('studio_layer_extraction');

        $this->tenant = Tenant::create([
            'name' => 'Ex Co',
            'slug' => 'ex-co',
            'uuid' => (string) Str::uuid(),
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ex Brand',
            'slug' => 'ex-brand',
        ]);

        // Must match {@see TenantBucketService::getExpectedBucketName} (phpunit: AWS_BUCKET / storage.shared_bucket)
        // so cutout assets from {@see StudioLayerExtractionAssetFactory} resolve the same row as the source asset.
        $sharedBucketName = (string) config('storage.shared_bucket', 'testing-bucket');
        if ($sharedBucketName === '') {
            $sharedBucketName = 'testing-bucket';
        }

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => $sharedBucketName,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // EditorAssetOriginalBytesLoader only reads via Storage::disk('s3') when
        // StorageBucket#name matches filesystems.disks.s3.bucket; otherwise it
        // uses Storage::build (bypassing Storage::fake('s3')).
        config(['filesystems.disks.s3.bucket' => $sharedBucketName]);

        $this->admin = User::factory()->create();
        $this->admin->tenants()->attach($this->tenant->id);
        $this->admin->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->viewer = User::factory()->create();
        $this->viewer->tenants()->attach($this->tenant->id);
        $this->viewer->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $png = $this->makeWhiteWithBlackSquarePng();
        $assetId = (string) Str::uuid();
        $path = 'tenants/'.$this->tenant->uuid.'/assets/'.$assetId.'/v1/original.png';
        Storage::disk('s3')->put($path, $png);

        $this->asset = Asset::forceCreate([
            'id' => $assetId,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->admin->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Src',
            'original_filename' => 't.png',
            'mime_type' => 'image/png',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => $path,
            'size_bytes' => strlen($png),
            'width' => 20,
            'height' => 20,
            'thumbnail_status' => 'completed',
            'analysis_status' => 'complete',
            'approval_status' => 'not_required',
            'source' => 'upload',
            'builder_staged' => false,
            'intake_state' => 'normal',
            'metadata' => [],
        ]);

        $doc = [
            'width' => 200,
            'height' => 200,
            'layers' => [
                [
                    'id' => $this->layerId,
                    'type' => 'image',
                    'name' => 'Photo',
                    'visible' => true,
                    'locked' => false,
                    'z' => 0,
                    'transform' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 80],
                    'assetId' => $assetId,
                    'src' => '/app/api/editor/assets/'.$assetId.'/file',
                ],
            ],
        ];

        $this->composition = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->admin->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Doc',
            'document_json' => $doc,
        ]);
    }

    private function makeWhiteWithBlackSquarePng(): string
    {
        $im = imagecreatetruecolor(20, 20);
        $w = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 19, 19, $w);
        $b = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 8, 8, 11, 11, $b);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    /** Two disconnected black squares on white (same idea as unit multi-candidate test). */
    private function makeTwoBlackSquaresPng(): string
    {
        $w = 80;
        $h = 50;
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('gd');
        }
        $wpx = imagecolorallocate($im, 255, 255, 255);
        $bpx = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $wpx);
        imagefilledrectangle($im, 6, 10, 20, 24, $bpx);
        imagefilledrectangle($im, 55, 10, 70, 24, $bpx);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function makeSolidPng(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        $c = imagecolorallocate($im, $r, $g, $b);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $c);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    public function test_guest_cannot_extract(): void
    {
        $this->postJson(
            "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
            []
        )->assertUnauthorized();
    }

    public function test_viewer_cannot_extract_without_upload_permission(): void
    {
        $this->actingAs($this->viewer)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )
            ->assertForbidden();
    }

    public function test_cannot_extract_from_text_layer(): void
    {
        $this->composition->document_json = [
            'width' => 200,
            'height' => 200,
            'layers' => [
                [
                    'id' => 't1',
                    'type' => 'text',
                    'name' => 'Headline',
                    'visible' => true,
                    'locked' => false,
                    'z' => 0,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 40],
                    'content' => 'Hi',
                    'style' => ['fontFamily' => 'Inter', 'fontSize' => 12, 'color' => '#000'],
                ],
            ],
        ];
        $this->composition->save();

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/t1/extract-layers",
                []
            )
            ->assertStatus(422);
    }

    public function test_inpaint_heuristic_marks_background_fill_supported_when_enabled(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.inpaint_enabled' => true,
            'studio_layer_extraction.inpaint_provider' => 'heuristic',
        ]);

        $resp = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            );

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('provider_capabilities.supports_background_fill'));
        $sid = (string) $resp->json('extraction_session_id');
        $this->assertTrue(
            (bool) (StudioLayerExtractionSession::query()->find($sid)->metadata['background_fill_supported'] ?? false)
        );
    }

    public function test_extract_returns_candidates_and_confirm_adds_layer_above_original(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $resp = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            );

        $resp->assertOk();
        $resp->assertJsonPath('status', 'ready');
        $sid = $resp->json('extraction_session_id');
        $this->assertNotEmpty($sid);
        $candidates = $resp->json('candidates');
        $this->assertIsArray($candidates);
        $this->assertNotEmpty($candidates);
        $this->assertStringContainsString('Detected element', (string) ($candidates[0]['label'] ?? ''));
        $caps = $resp->json('provider_capabilities');
        $this->assertIsArray($caps);
        $this->assertArrayHasKey('supports_background_fill', $caps);
        $this->assertFalse($caps['supports_background_fill']);
        $this->assertArrayHasKey('supports_multiple_masks', $caps);
        $this->assertTrue($caps['supports_multiple_masks']);
        $this->assertArrayHasKey('supports_point_pick', $caps);
        $this->assertTrue($caps['supports_point_pick']);
        $cid = $candidates[0]['id'];
        $this->assertNotEmpty($cid);

        $confirm = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$cid],
                    'keep_original_visible' => true,
                    'create_filled_background' => false,
                ]
            );
        $confirm->assertOk();
        $confirm->assertJsonStructure(['document', 'new_layer_ids']);
        $newIds = $confirm->json('new_layer_ids');
        $this->assertIsArray($newIds);
        $this->assertCount(1, $newIds);
        $doc = $confirm->json('document');
        $layers = $doc['layers'];
        $this->assertCount(2, $layers);

        $byId = [];
        foreach ($layers as $l) {
            $byId[$l['id']] = $l;
        }
        $this->assertArrayHasKey($this->layerId, $byId);
        $this->assertSame('image', $byId[$this->layerId]['type']);
        $this->assertEqualsWithDelta(10, (float) $byId[$this->layerId]['transform']['x'], 0.001);
        $this->assertEqualsWithDelta(20, (float) $byId[$this->layerId]['transform']['y'], 0.001);

        $new = null;
        foreach ($layers as $l) {
            if ($l['id'] !== $this->layerId && ($l['type'] ?? '') === 'image') {
                $new = $l;
                break;
            }
        }
        $this->assertNotNull($new);
        $this->assertArrayHasKey('studioLayerExtraction', $new);
        $this->assertArrayHasKey('assetId', $new);
        $this->assertIsString($new['src'] ?? null);
        $this->assertStringStartsWith('/app/api/assets/', (string) $new['src']);
        $this->assertStringEndsWith('/file', (string) $new['src']);
        $cutAsset = Asset::query()->find((string) $new['assetId']);
        $this->assertNotNull($cutAsset);
        $this->assertTrue(Storage::disk('s3')->exists((string) $cutAsset->storage_root_path));
        $this->assertGreaterThan($byId[$this->layerId]['z'], $new['z']);
        $this->assertContains($new['id'], $newIds);
        $sourceAfter = collect($layers)->firstWhere('id', $this->layerId);
        $this->assertNotNull($sourceAfter);
        $this->assertSame($this->asset->id, (string) ($sourceAfter['assetId'] ?? ''));
    }

    public function test_refinement_pass_on_extracted_layer_increments_metadata(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()->json('extraction_session_id');

        $stored = json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true);
        $cid = (string) $stored[0]['id'];

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$cid],
                ]
            )->assertOk();

        $this->composition->refresh();
        $cut = collect($this->composition->document_json['layers'])->first(
            fn (array $l) => $l['id'] !== $this->layerId && ! empty($l['studioLayerExtraction'])
        );
        $this->assertNotNull($cut);
        $this->assertSame(1, (int) ($cut['studioLayerExtraction']['extraction_generation'] ?? 0));
        $this->assertSame($this->layerId, (string) ($cut['studioLayerExtraction']['root_source_layer_id'] ?? ''));
        $this->assertNull($cut['studioLayerExtraction']['parent_extraction_layer_id'] ?? null);

        $newLayerId = (string) $cut['id'];

        // The cutout file is a tight crop (often uniform); local floodfill needs clear fg/bg. Re-stamp
        // the same full-scene test PNG onto the cutout asset so a second extract can succeed.
        $cutoutAsset = Asset::query()->find((string) ($cut['assetId'] ?? ''));
        $this->assertNotNull($cutoutAsset);
        $restoredPng = $this->makeWhiteWithBlackSquarePng();
        Storage::disk('s3')->put((string) $cutoutAsset->storage_root_path, $restoredPng);
        $cutoutAsset->update([
            'width' => 20,
            'height' => 20,
            'size_bytes' => strlen($restoredPng),
        ]);

        $sid2 = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$newLayerId}/extract-layers",
                []
            )->assertOk()
            ->assertJsonPath('status', 'ready')
            ->json('extraction_session_id');

        $stored2 = json_decode((string) StudioLayerExtractionSession::query()->find($sid2)->candidates_json, true);
        $this->assertNotEmpty($stored2);
        $cid2 = (string) $stored2[0]['id'];

        $r2 = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$newLayerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid2,
                    'candidate_ids' => [$cid2],
                ]
            );
        $r2->assertOk();
        $refinedId = (string) ($r2->json('new_layer_ids.0') ?? '');

        $this->composition->refresh();
        $refined = collect($this->composition->document_json['layers'])->first(
            fn (array $l) => (string) ($l['id'] ?? '') === $refinedId
        );
        $this->assertNotNull($refined);
        $ex = $refined['studioLayerExtraction'];
        $this->assertSame(2, (int) ($ex['extraction_generation'] ?? 0));
        $this->assertSame($this->layerId, (string) ($ex['root_source_layer_id'] ?? ''));
        $this->assertSame($newLayerId, (string) ($ex['parent_extraction_layer_id'] ?? ''));
    }

    public function test_local_multi_off_sets_supports_multiple_masks_false(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.enable_multi_candidate' => false,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )
            ->assertOk()
            ->assertJsonPath('provider_capabilities.supports_multiple_masks', false);
    }

    public function test_uniform_image_has_no_separable_elements(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $whitePng = $this->makeSolidPng(20, 20, 255, 255, 255);
        Storage::disk('s3')->put($this->asset->storage_root_path, $whitePng);

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )
            ->assertStatus(502)
            ->assertJsonFragment(['message' => 'No separable elements found. Try a photo with a clear subject on a distinct background.']);
    }

    public function test_confirm_can_hide_original_layer(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->json('extraction_session_id');

        $candidates = StudioLayerExtractionSession::query()->find($sid);
        $stored = json_decode((string) $candidates->candidates_json, true);
        $cid = (string) $stored[0]['id'];

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$cid],
                    'keep_original_visible' => false,
                ]
            )->assertOk();

        $this->composition->refresh();
        $layers = $this->composition->document_json['layers'];
        $orig = collect($layers)->firstWhere('id', $this->layerId);
        $this->assertNotNull($orig);
        $this->assertFalse((bool) ($orig['visible'] ?? true));
    }

    public function test_provider_failure_returns_safe_error(): void
    {
        $this->app->bind(StudioLayerExtractionProviderInterface::class, fn () => new class implements StudioLayerExtractionProviderInterface
        {
            public function extractMasks(\App\Models\Asset $asset, array $options = []): LayerExtractionResult
            {
                throw new RuntimeException('provider boom');
            }

            public function supportsMultipleMasks(): bool
            {
                return false;
            }

            public function supportsBackgroundFill(): bool
            {
                return false;
            }

            public function supportsLabels(): bool
            {
                return true;
            }

            public function supportsConfidence(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )
            ->assertStatus(502);
    }

    public function test_create_filled_background_fails_with_local_floodfill_provider(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->json('extraction_session_id');

        $candidates = StudioLayerExtractionSession::query()->find($sid);
        $stored = json_decode((string) $candidates->candidates_json, true);
        $cid = (string) $stored[0]['id'];

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$cid],
                    'create_filled_background' => true,
                ]
            )->assertStatus(422);
    }

    public function test_confirm_accepts_selected_candidate_ids_alias(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->json('extraction_session_id');

        $candidates = StudioLayerExtractionSession::query()->find($sid);
        $stored = json_decode((string) $candidates->candidates_json, true);
        $cid = (string) $stored[0]['id'];

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'selected_candidate_ids' => [$cid],
                ]
            )->assertOk();
    }

    public function test_mock_inpaint_multi_candidate_confirms_both_layers(): void
    {
        $mock = new MockInpaintLayerExtractionProvider(multiCandidate: true);
        $this->app->instance(StudioLayerExtractionProviderInterface::class, $mock);
        $this->app->instance(StudioLayerExtractionInpaintBackgroundInterface::class, $mock);
        config(['studio_layer_extraction.inpaint_enabled' => true]);

        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->json('extraction_session_id');

        $stored = json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true);
        $this->assertCount(2, $stored);
        $ids = [(string) $stored[0]['id'], (string) $stored[1]['id']];

        $res = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => $ids,
                ]
            );
        $res->assertOk();
        $this->assertCount(2, $res->json('new_layer_ids'));
        $this->composition->refresh();
        $this->assertCount(3, $this->composition->document_json['layers']);
    }

    public function test_mock_inpaint_creates_filled_layer_below_extracted_object(): void
    {
        $mock = new MockInpaintLayerExtractionProvider;
        $this->app->instance(StudioLayerExtractionProviderInterface::class, $mock);
        $this->app->instance(StudioLayerExtractionInpaintBackgroundInterface::class, $mock);
        config(['studio_layer_extraction.inpaint_enabled' => true]);

        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->json('extraction_session_id');

        $candidates = StudioLayerExtractionSession::query()->find($sid);
        $stored = json_decode((string) $candidates->candidates_json, true);
        $cid = (string) $stored[0]['id'];

        $this->assertTrue(StudioLayerExtractionSession::query()->find($sid)->metadata['background_fill_supported'] ?? false);

        $res = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$cid],
                    'create_filled_background' => true,
                ]
            );
        $res->assertOk();
        $this->composition->refresh();
        $layers = $this->composition->document_json['layers'];
        $this->assertCount(3, $layers);

        $byName = collect($layers)->keyBy('name');
        $this->assertArrayHasKey('Filled background', $byName);
        $cut = collect($layers)->first(fn ($l) => ! empty($l['studioLayerExtraction']));
        $this->assertNotNull($cut);
        $fill = $byName['Filled background'];
        $this->assertGreaterThan((int) $fill['z'], (int) $cut['z']);
        $this->assertStringStartsWith('/app/api/assets/', (string) ($fill['src'] ?? ''));
        $this->assertStringEndsWith('/file', (string) ($fill['src'] ?? ''));
        $fillAsset = Asset::query()->find((string) $fill['assetId']);
        $this->assertNotNull($fillAsset);
        $this->assertTrue(Storage::disk('s3')->exists((string) $fillAsset->storage_root_path));
    }

    public function test_guest_cannot_post_pick(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        Auth::logout();

        $this->postJson(
            "/app/studio/layer-extraction-sessions/{$sid}/pick",
            ['x' => 0.5, 'y' => 0.5]
        )->assertUnauthorized();
    }

    public function test_pick_on_center_foreground_adds_picked_element(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            // Auto-detected candidate already covers the black square; allow a pick that overlaps (IoU 1.0 is not > 1.0).
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $before = count(json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true));

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            );
        $pick->assertOk();
        $this->assertCount($before + 1, $pick->json('candidates'));
        $this->assertNotNull($pick->json('new_candidate.id'));
        $this->assertStringStartsWith('pick_', (string) $pick->json('new_candidate.id'));
        $this->assertSame('Picked element', $pick->json('new_candidate.label'));
        $meta = $pick->json('new_candidate.metadata');
        $this->assertIsArray($meta);
        $this->assertSame('point', $meta['prompt_type'] ?? null);
        $this->assertSame('local_seed_floodfill', $meta['method'] ?? null);
        $this->assertIsArray($meta['seed_point_normalized'] ?? null);
    }

    public function test_pick_on_edge_background_can_return_no_new_candidate(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $before = count(json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true));

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.0, 'y' => 0.0]
            );
        $pick->assertOk();
        $this->assertNull($pick->json('new_candidate'));
        $this->assertNotNull($pick->json('warning'));
        $this->assertCount($before, $pick->json('candidates'));
    }

    public function test_confirm_uses_picked_candidate_id(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk();

        $pid = (string) $pick->json('new_candidate.id');

        $res = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$pid],
                ]
            );
        $res->assertOk();
        $this->assertIsArray($res->json('new_layer_ids'));
    }

    public function test_clear_picks_strips_only_pick_candidates(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $nAuto = count(json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true));

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk();

        $after = count(json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true));
        $this->assertSame($nAuto + 1, $after);

        $out = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/clear-picks",
                []
            );
        $out->assertOk();
        $this->assertCount($nAuto, $out->json('candidates'));
    }

    public function test_picked_candidate_refine_adds_negative_and_keeps_id(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
            'studio_layer_extraction.local_floodfill.negative_point_radius_ratio' => 0.08,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk();
        $pid = (string) $pick->json('new_candidate.id');
        $this->assertStringStartsWith('pick_', $pid);

        $this->assertTrue(
            $pick->json('provider_capabilities.supports_point_refine') === true
                || $pick->json('provider_capabilities.supports_point_refine') === 1
        );

        $r = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                ['negative_point' => ['x' => 0.4, 'y' => 0.4]]
            );
        $r->assertOk();
        $this->assertSame($pid, $r->json('updated_candidate.id'));
        $m = $r->json('updated_candidate.metadata');
        $this->assertIsArray($m);
        $this->assertCount(1, (array) ($m['negative_points'] ?? []));
        $this->assertSame(1, (int) ($m['refine_count'] ?? 0));
        $this->assertTrue((bool) ($m['refined'] ?? false));
        $this->assertSame('point_refine', $m['prompt_type'] ?? null);
    }

    public function test_refine_accepts_positive_point_to_union_extra_component(): void
    {
        $png = $this->makeTwoBlackSquaresPng();
        Storage::disk('s3')->put($this->asset->storage_root_path, $png);
        $this->asset->update([
            'width' => 80,
            'height' => 50,
            'size_bytes' => strlen($png),
        ]);

        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
            'studio_layer_extraction.floodfill.max_segmentation_edge' => 256,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.16, 'y' => 0.34]
            )->assertOk();
        $pid = (string) $pick->json('new_candidate.id');
        $w0 = (int) $pick->json('new_candidate.bbox.width') * (int) $pick->json('new_candidate.bbox.height');

        $r = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                ['positive_point' => ['x' => 0.78, 'y' => 0.34]]
            );
        $r->assertOk();
        $m = $r->json('updated_candidate.metadata');
        $this->assertIsArray($m);
        $this->assertCount(2, (array) ($m['positive_points'] ?? []));
        $w1 = (int) $r->json('updated_candidate.bbox.width') * (int) $r->json('updated_candidate.bbox.height');
        $this->assertGreaterThan($w0, $w1);
    }

    public function test_refine_rejects_both_positive_and_negative_in_one_request(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');
        $pid = (string) $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk()
            ->json('new_candidate.id');

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                [
                    'negative_point' => ['x' => 0.2, 'y' => 0.2],
                    'positive_point' => ['x' => 0.8, 'y' => 0.8],
                ]
            )
            ->assertStatus(422);
    }

    public function test_reset_refine_restores_pick_without_negatives(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pick = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk();
        $pid = (string) $pick->json('new_candidate.id');

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                ['negative_point' => ['x' => 0.4, 'y' => 0.4]]
            )->assertOk();

        $out = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/reset-refine",
                []
            );
        $out->assertOk();
        $cand = $out->json('updated_candidate');
        $this->assertIsArray($cand);
        $meta = $cand['metadata'] ?? null;
        $this->assertIsArray($meta);
        $this->assertSame('point', $meta['prompt_type'] ?? null);
        $this->assertSame('local_seed_floodfill', $meta['method'] ?? null);
        $this->assertCount(0, (array) ($meta['negative_points'] ?? []));
    }

    public function test_cannot_refine_non_pick_candidate(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $rows = json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true);
        $autoId = (string) $rows[0]['id'];
        $this->assertFalse(str_starts_with($autoId, 'pick_'));

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$autoId}/refine",
                ['negative_point' => ['x' => 0.1, 'y' => 0.1]]
            )
            ->assertStatus(422);
    }

    public function test_refine_rejects_out_of_range_point(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pid = (string) $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk()
            ->json('new_candidate.id');

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                ['negative_point' => ['x' => 1.1, 'y' => 0.5]]
            )
            ->assertUnprocessable();
    }

    public function test_guest_cannot_refine(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');
        $pid = (string) $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk()
            ->json('new_candidate.id');

        Auth::logout();
        $this->postJson(
            "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
            ['negative_point' => ['x' => 0.2, 'y' => 0.2]]
        )->assertUnauthorized();
    }

    public function test_refine_returns_warning_when_exclusion_too_much(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
            'studio_layer_extraction.local_floodfill.negative_point_radius_ratio' => 0.5,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $pid = (string) $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/pick",
                ['x' => 0.5, 'y' => 0.5]
            )->assertOk()
            ->json('new_candidate.id');

        $before = StudioLayerExtractionSession::query()->find($sid)->candidates_json;

        $r = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$pid}/refine",
                ['negative_point' => ['x' => 0.5, 'y' => 0.5]]
            );
        $r->assertOk();
        $this->assertNotNull($r->json('warning'));
        $this->assertSame($before, StudioLayerExtractionSession::query()->find($sid)->candidates_json);
    }

    public function test_extract_ready_includes_supports_box_pick(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )
            ->assertOk()
            ->assertJsonPath('provider_capabilities.supports_box_pick', true);
    }

    public function test_guest_cannot_post_box(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        Auth::logout();

        $this->postJson(
            "/app/studio/layer-extraction-sessions/{$sid}/box",
            [
                'box' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.5, 'height' => 0.5],
                'mode' => 'object',
            ]
        )->assertUnauthorized();
    }

    public function test_box_validates_coordinates(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => -0.1, 'y' => 0.2, 'width' => 0.5, 'height' => 0.5],
                    'mode' => 'object',
                ]
            )->assertStatus(422);
    }

    public function test_box_pick_adds_candidate_with_metadata(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $r = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.35, 'y' => 0.35, 'width' => 0.3, 'height' => 0.3],
                    'mode' => 'object',
                ]
            );
        $r->assertOk();
        $this->assertNotNull($r->json('new_candidate.id'));
        $this->assertStringStartsWith('box_', (string) $r->json('new_candidate.id'));
        $meta = $r->json('new_candidate.metadata');
        $this->assertIsArray($meta);
        $this->assertSame('box', $meta['prompt_type'] ?? null);
        $this->assertTrue(in_array($meta['method'] ?? null, ['local_box_floodfill', 'local_box_rect_cutout'], true));
        $this->assertArrayHasKey('box_normalized', $meta);
    }

    public function test_box_candidate_can_be_confirmed(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $box = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.35, 'y' => 0.35, 'width' => 0.3, 'height' => 0.3],
                    'mode' => 'text_graphic',
                ]
            )->assertOk();

        $bid = (string) $box->json('new_candidate.id');
        $this->assertStringStartsWith('box_', $bid);

        $res = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers/confirm",
                [
                    'extraction_session_id' => $sid,
                    'candidate_ids' => [$bid],
                ]
            );
        $res->assertOk();
        $this->assertIsArray($res->json('new_layer_ids'));
    }

    public function test_remove_candidate_removes_box_candidate(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $box = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.35, 'y' => 0.35, 'width' => 0.3, 'height' => 0.3],
                    'mode' => 'object',
                ]
            )->assertOk();

        $bid = (string) $box->json('new_candidate.id');
        $n = count($box->json('candidates'));

        $out = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->deleteJson(
                "/app/studio/layer-extraction-sessions/{$sid}/candidates/{$bid}"
            );
        $out->assertOk();
        $this->assertCount($n - 1, $out->json('candidates'));
    }

    public function test_multiple_box_candidates(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.4],
                    'mode' => 'object',
                ]
            )->assertOk();

        $b2 = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.4, 'height' => 0.4],
                    'mode' => 'object',
                ]
            )->assertOk();

        $cands = $b2->json('candidates');
        $boxes = array_values(array_filter($cands, fn ($c) => is_array($c) && str_starts_with((string) ($c['id'] ?? ''), 'box_')));
        $this->assertCount(2, $boxes);
    }

    public function test_clear_manual_candidates_removes_pick_and_box(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.merge_iou_threshold' => 1.0,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $n0 = count(json_decode((string) StudioLayerExtractionSession::query()->find($sid)->candidates_json, true));

        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/studio/layer-extraction-sessions/{$sid}/pick", ['x' => 0.5, 'y' => 0.5])
            ->assertOk();
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.3, 'height' => 0.3],
                    'mode' => 'object',
                ]
            )->assertOk();

        $out = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/clear-manual-candidates",
                []
            );
        $out->assertOk();
        $this->assertCount($n0, $out->json('candidates'));
    }

    public function test_box_no_foreground_with_fallback_off_returns_null_candidate(): void
    {
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.box_fallback_rectangle' => false,
        ]);

        $sid = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                []
            )->assertOk()
            ->json('extraction_session_id');

        $r = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/layer-extraction-sessions/{$sid}/box",
                [
                    'box' => ['x' => 0, 'y' => 0, 'width' => 0.25, 'height' => 0.25],
                    'mode' => 'object',
                ]
            );
        $r->assertOk();
        $this->assertNull($r->json('new_candidate'));
        $this->assertNotNull($r->json('warning'));
    }

    public function test_local_too_large_returns_structured_error_when_ai_available(): void
    {
        $path = 'tenants/'.$this->tenant->uuid.'/assets/'.$this->asset->id.'/v1/original.png';
        Storage::disk('s3')->put($path, $this->makeSolidPng(500, 500, 255, 255, 255));
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.max_analysis_pixels' => 200_000,
            'studio_layer_extraction.local_floodfill.downscale_oversized' => false,
            'studio_layer_extraction.sam.enabled' => true,
            'services.fal.key' => 'test-fal-key',
        ]);

        $resp = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                ['method' => 'local']
            );

        $resp->assertStatus(502);
        $resp->assertJsonPath('code', 'local_source_too_large');
        $resp->assertJsonPath('method', 'local');
        $this->assertTrue($resp->json('ai_available'));
        $this->assertTrue($resp->json('can_try_ai'));
        $this->assertNull($resp->json('ai_unavailable_reason'));
    }

    public function test_local_too_large_when_ai_unavailable_does_not_offer_switch(): void
    {
        $path = 'tenants/'.$this->tenant->uuid.'/assets/'.$this->asset->id.'/v1/original.png';
        Storage::disk('s3')->put($path, $this->makeSolidPng(500, 500, 255, 255, 255));
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.max_analysis_pixels' => 200_000,
            'studio_layer_extraction.local_floodfill.downscale_oversized' => false,
            'studio_layer_extraction.sam.enabled' => false,
            'services.fal.key' => '',
        ]);

        $resp = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                ['method' => 'local']
            );

        $resp->assertStatus(502);
        $resp->assertJsonPath('code', 'local_source_too_large');
        $this->assertFalse($resp->json('ai_available'));
        $this->assertFalse($resp->json('can_try_ai'));
        $this->assertIsString($resp->json('ai_unavailable_reason'));
    }

    public function test_ai_method_runs_for_image_that_exceeds_local_pixel_cap_with_fal_mocked(): void
    {
        $path = 'tenants/'.$this->tenant->uuid.'/assets/'.$this->asset->id.'/v1/original.png';
        Storage::disk('s3')->put($path, $this->makeSolidPng(500, 500, 10, 20, 30));
        $maskPng = $this->makeWhiteWithBlackSquarePng();
        config([
            'studio_layer_extraction.always_queue' => false,
            'studio_layer_extraction.async_pixel_threshold' => 1_000_000,
            'studio_layer_extraction.local_floodfill.max_analysis_pixels' => 200_000,
            'studio_layer_extraction.sam.enabled' => true,
            'studio_layer_extraction.sam.prefer_queue' => false,
            'services.fal.sam2_endpoint' => 'https://fal.test/fal-ai/sam2/image',
            'services.fal.key' => 'test-fal-key',
        ]);
        Http::fake([
            'https://fal.test/*' => Http::response(['image' => ['url' => 'https://cdn.test/m.png']], 200),
            'https://cdn.test/*' => Http::response($maskPng, 200, ['Content-Type' => 'image/png']),
        ]);

        $resp = $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson(
                "/app/studio/documents/{$this->composition->id}/layers/{$this->layerId}/extract-layers",
                ['method' => 'ai']
            );

        $resp->assertOk();
        $this->assertNotEmpty($resp->json('candidates'));
    }

    public function test_cleanup_command_removes_expired_sessions(): void
    {
        $id = (string) Str::uuid();
        StudioLayerExtractionSession::query()->create([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->admin->id,
            'composition_id' => $this->composition->id,
            'source_layer_id' => $this->layerId,
            'source_asset_id' => $this->asset->id,
            'status' => StudioLayerExtractionSession::STATUS_READY,
            'provider' => 'floodfill',
            'model' => 'x',
            'candidates_json' => '[]',
            'metadata' => null,
            'error_message' => null,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('studio:cleanup-layer-extraction-sessions')->assertSuccessful();
        $this->assertDatabaseMissing('studio_layer_extraction_sessions', ['id' => $id]);
    }
}
