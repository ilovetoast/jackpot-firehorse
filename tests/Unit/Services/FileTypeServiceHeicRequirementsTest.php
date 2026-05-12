<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use Tests\TestCase;

final class FileTypeServiceHeicRequirementsTest extends TestCase
{
    public function test_heic_type_declares_imagick_heif_decode_requirement(): void
    {
        $req = config('file_types.types.heic.requirements');
        $this->assertIsArray($req);
        $this->assertArrayHasKey('imagick_heif_decode', $req);
        $this->assertTrue($req['imagick_heif_decode']);
    }

    public function test_check_requirements_heic_returns_structured_result(): void
    {
        $svc = app(FileTypeService::class);
        $result = $svc->checkRequirements('heic');

        $this->assertArrayHasKey('met', $result);
        $this->assertArrayHasKey('missing', $result);
        $this->assertIsBool($result['met']);
        $this->assertIsArray($result['missing']);
    }

    public function test_imagick_has_heif_read_support_is_boolean(): void
    {
        $svc = app(FileTypeService::class);
        $this->assertIsBool($svc->imagickHasHeifReadSupport());
    }
}
