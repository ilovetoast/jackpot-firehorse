<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Office;

use App\Services\FileTypeService;
use Tests\TestCase;

final class LibreOfficeRequirementsTest extends TestCase
{
    public function test_office_requirements_report_libreoffice_when_binary_not_configured(): void
    {
        config(['assets.thumbnail.office.soffice_binary' => '/nonexistent/jp_test_soffice_'.bin2hex(random_bytes(4))]);

        $r = app(FileTypeService::class)->checkRequirements('office');

        $joined = strtolower(implode(' ', $r['missing']));
        $this->assertStringContainsString('libreoffice', $joined);
        $this->assertFalse($r['met']);
    }
}
