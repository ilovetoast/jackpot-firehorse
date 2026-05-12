<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\ThumbnailGenerationService;
use Tests\TestCase;

class ThumbnailGenerationServiceOfficeDetectTest extends TestCase
{
    public function test_version_path_resolves_office_from_file_path_extension(): void
    {
        $asset = new Asset([
            'mime_type' => 'application/octet-stream',
            'original_filename' => '',
        ]);
        $version = new AssetVersion([
            'mime_type' => 'application/octet-stream',
            'file_path' => 'tenants/u/assets/a/v1/original.pptx',
        ]);

        $svc = app(ThumbnailGenerationService::class);
        $this->assertSame('office', $svc->detectFileTypeForDiagnostics($asset, $version));
    }

    /**
     * When MIME is mis-sniffed as JPEG but storage path is .pptx, extension must win for the Office pipeline.
     */
    public function test_version_path_prefers_office_over_misleading_image_mime(): void
    {
        $asset = new Asset([
            'mime_type' => 'image/jpeg',
            'original_filename' => 'slide-deck.pptx',
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/jpeg',
            'file_path' => 'tenants/u/assets/a/v1/original.pptx',
        ]);

        $svc = app(ThumbnailGenerationService::class);
        $this->assertSame('office', $svc->detectFileTypeForDiagnostics($asset, $version));
    }
}
