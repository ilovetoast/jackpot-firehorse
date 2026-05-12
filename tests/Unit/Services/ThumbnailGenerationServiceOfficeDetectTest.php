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

    /**
     * Extension-less storage key + wrong version MIME: rely on nested extraction filename / MIME from asset metadata.
     */
    public function test_version_path_uses_nested_extracted_filename_and_mime_when_version_row_is_wrong(): void
    {
        $asset = new Asset([
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'original_filename' => '',
            'metadata' => [
                'metadata' => [
                    'original_filename' => 'hefty-pptx-template.pptx',
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ],
            ],
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/jpeg',
            'file_path' => 'tenants/u/assets/a/v1/original',
        ]);

        $svc = app(ThumbnailGenerationService::class);
        $this->assertSame('office', $svc->detectFileTypeForDiagnostics($asset, $version));
    }
}
