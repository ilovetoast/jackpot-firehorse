<?php

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use Tests\TestCase;

class FileTypeHeicConfigTest extends TestCase
{
    public function test_heic_mime_and_extension_resolve_to_heic_type(): void
    {
        $svc = app(FileTypeService::class);

        $this->assertSame('heic', $svc->detectFileType('image/heic', null));
        $this->assertSame('heic', $svc->detectFileType('image/heif', null));
        $this->assertSame('heic', $svc->detectFileType(null, 'heic'));
        $this->assertSame('heic', $svc->detectFileType(null, 'heif'));
    }

    public function test_heic_is_in_thumbnail_capability_lists(): void
    {
        $svc = app(FileTypeService::class);

        if (! $svc->registryTypeSupportsThumbnailPipeline('heic')) {
            $this->markTestSkipped('HEIC pipeline requirements (Imagick + HEIF) not met in this environment');
        }

        $mimes = $svc->getThumbnailCapabilityMimeTypes();
        $exts = $svc->getThumbnailCapabilityExtensions();

        $this->assertContains('image/heic', $mimes);
        $this->assertContains('image/heif', $mimes);
        $this->assertContains('heic', $exts);
        $this->assertContains('heif', $exts);
    }

    public function test_heic_thumbnail_handler_is_registered(): void
    {
        $svc = app(FileTypeService::class);

        $this->assertSame('generateHeicThumbnail', $svc->getHandler('heic', 'thumbnail'));
    }
}
