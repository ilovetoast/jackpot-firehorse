<?php

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use App\Support\Preview3dMetadata;
use Tests\TestCase;

class Model3dFileTypesRegistryTest extends TestCase
{
    public function test_detects_each_model_extension(): void
    {
        $svc = app(FileTypeService::class);

        $this->assertSame('model_glb', $svc->detectFileType('model/gltf-binary', 'glb'));
        $this->assertSame('model_gltf', $svc->detectFileType('model/gltf+json', 'gltf'));
        $this->assertSame('model_obj', $svc->detectFileType(null, 'obj'));
        $this->assertSame('model_stl', $svc->detectFileType('model/stl', 'stl'));
        $this->assertSame('model_fbx', $svc->detectFileType('application/vnd.autodesk.fbx', 'fbx'));
        $this->assertSame('model_blend', $svc->detectFileType('application/x-blender', 'blend'));
    }

    /** Browsers often mis-declare 3D uploads; extension must win over bogus MIME for finalize + magic-byte gate. */
    public function test_model_extension_wins_over_wrong_client_mime(): void
    {
        $svc = app(FileTypeService::class);

        $this->assertSame('model_stl', $svc->detectFileType('image/svg+xml', 'stl'));
        $this->assertSame('model_obj', $svc->detectFileType('image/svg+xml', 'obj'));
        $this->assertSame('model_glb', $svc->detectFileType('text/plain', 'glb'));

        $this->assertSame('model/stl', $svc->canonicalizeSniffedMime('image/svg+xml', 'stl'));
        $this->assertSame('model/obj', $svc->canonicalizeSniffedMime('application/octet-stream', 'obj'));
        $this->assertSame('model/gltf-binary', $svc->canonicalizeSniffedMime('', 'glb'));
    }

    public function test_upload_allowed_for_all_model_types_independent_of_dam_3d(): void
    {
        $svc = app(FileTypeService::class);

        config(['dam_3d.enabled' => false]);

        foreach (['glb', 'gltf', 'obj', 'stl', 'fbx', 'blend'] as $ext) {
            $d = $svc->isUploadAllowed('application/octet-stream', $ext);
            $this->assertTrue($d['allowed'], "upload should be allowed for .{$ext}");
            $this->assertSame('ok', $d['code'], "code for .{$ext}");
        }
    }

    public function test_glb_realtime_capability_and_fbx_conversion_required(): void
    {
        $svc = app(FileTypeService::class);

        $glb = config('file_types.types.model_glb.capabilities');
        $this->assertTrue($glb['realtime_3d_preview']);
        $this->assertFalse($glb['conversion_required'] ?? true);

        $fbx = config('file_types.types.model_fbx.capabilities');
        $this->assertTrue($fbx['conversion_required']);
        $this->assertFalse($fbx['realtime_3d_preview']);
        $this->assertFalse($fbx['thumbnail']);
    }

    public function test_is_model_3d_registry_type(): void
    {
        $svc = app(FileTypeService::class);
        $this->assertTrue($svc->isModel3dRegistryType('model_glb'));
        $this->assertFalse($svc->isModel3dRegistryType('image'));
    }

    public function test_preview_3d_merge_preserves_contract_keys(): void
    {
        $a = Preview3dMetadata::merge([], ['status' => Preview3dMetadata::STATUS_PENDING]);
        $this->assertArrayHasKey('viewer_path', $a);
        $this->assertSame(Preview3dMetadata::STATUS_PENDING, $a['status']);
        $this->assertIsArray($a['debug']);
    }

    public function test_types_for_help_includes_extra_3d_capability_keys(): void
    {
        $svc = app(FileTypeService::class);
        $payload = $svc->getUploadRegistryForFrontend();
        $byKey = [];
        foreach ($payload['types_for_help'] as $row) {
            $byKey[$row['key']] = $row;
        }
        $this->assertArrayHasKey('realtime_3d_preview', $byKey['model_glb']['capabilities']);
        $this->assertTrue($byKey['model_glb']['capabilities']['realtime_3d_preview']);
        $this->assertTrue($byKey['model_gltf']['capabilities']['requires_sidecars']);
    }

    public function test_oversized_model_glb_hits_dam_3d_byte_cap_on_classify(): void
    {
        config(['dam_3d.max_upload_bytes' => 1000]);

        $asset = new \App\Models\Asset([
            'original_filename' => 'large.glb',
            'mime_type' => 'model/gltf-binary',
            'size_bytes' => 5000,
        ]);

        $decision = app(\App\Services\Assets\AssetProcessingBudgetService::class)->classify($asset, null, [
            'mime_type' => 'model/gltf-binary',
            'file_size_bytes' => 5000,
        ]);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('file_exceeds_worker_limits', $decision->failureCode());
    }
}
