<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Office;

use App\Services\Office\LibreOfficeDocumentPreviewService;
use Tests\TestCase;

final class LibreOfficeDocumentPreviewServiceTest extends TestCase
{
    public function test_find_binary_returns_configured_path_when_executable(): void
    {
        $dir = sys_get_temp_dir().'/jp_lo_binary_test_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true));
        $fake = $dir.'/fake-soffice';
        file_put_contents($fake, "#!/bin/sh\nexit 0\n");
        $this->assertNotFalse(chmod($fake, 0755));

        try {
            config(['assets.thumbnail.office.soffice_binary' => $fake]);
            $svc = app(LibreOfficeDocumentPreviewService::class);

            $this->assertSame($fake, $svc->findBinary());
        } finally {
            @unlink($fake);
            @rmdir($dir);
            config(['assets.thumbnail.office.soffice_binary' => '']);
        }
    }

    public function test_find_binary_ignores_non_executable_configured_path(): void
    {
        $dir = sys_get_temp_dir().'/jp_lo_binary_ro_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true));
        $fake = $dir.'/not-executable-soffice';
        file_put_contents($fake, "#!/bin/sh\nexit 0\n");
        $this->assertNotFalse(chmod($fake, 0644));

        try {
            config(['assets.thumbnail.office.soffice_binary' => $fake]);
            $svc = app(LibreOfficeDocumentPreviewService::class);
            $found = $svc->findBinary();

            $this->assertNotSame($fake, $found);
            if ($found !== null) {
                $this->assertFileExists($found);
                $this->assertTrue(is_executable($found));
            }
        } finally {
            @unlink($fake);
            @rmdir($dir);
            config(['assets.thumbnail.office.soffice_binary' => '']);
        }
    }

    /**
     * On Sail / worker images with libreoffice-nogui, at least one standard path exists.
     * Minimal CI images often omit LibreOffice — skip instead of failing the suite.
     */
    public function test_find_binary_discovers_system_install_when_present(): void
    {
        config(['assets.thumbnail.office.soffice_binary' => '']);

        $hasStandard =
            (is_file('/usr/bin/soffice') && is_executable('/usr/bin/soffice'))
            || (is_file('/usr/lib/libreoffice/program/soffice') && is_executable('/usr/lib/libreoffice/program/soffice'));

        if (! $hasStandard) {
            $out = [];
            $rc = 0;
            @exec('command -v soffice 2>/dev/null', $out, $rc);
            if ($rc !== 0 || empty($out[0]) || ! is_executable($out[0])) {
                $this->markTestSkipped('No LibreOffice (soffice) on PATH or standard paths — expected in slim CI.');
            }
        }

        $path = app(LibreOfficeDocumentPreviewService::class)->findBinary();
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path));
    }
}
