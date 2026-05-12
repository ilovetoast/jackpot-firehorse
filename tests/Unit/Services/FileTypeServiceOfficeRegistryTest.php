<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use Tests\TestCase;

/**
 * Ensures Office coverage stays aligned with config/file_types.php `office` —
 * manual thumbnail {@see \App\Http\Controllers\AssetThumbnailController::generate()}
 * and the pipeline use {@see FileTypeService::isOfficeDocument()} (no duplicate lists).
 */
final class FileTypeServiceOfficeRegistryTest extends TestCase
{
    public function test_all_configured_office_mime_types_resolve_to_office(): void
    {
        $svc = app(FileTypeService::class);
        $mimes = config('file_types.types.office.mime_types', []);
        $this->assertNotEmpty($mimes);

        foreach ($mimes as $mime) {
            $mimeLower = strtolower((string) $mime);
            $this->assertSame(
                'office',
                $svc->detectFileType($mimeLower, null),
                "MIME {$mimeLower} must map to office type",
            );
            $this->assertTrue(
                $svc->isOfficeDocument($mimeLower, null),
                "isOfficeDocument must accept MIME {$mimeLower}",
            );
        }
    }

    public function test_all_configured_office_extensions_resolve_to_office(): void
    {
        $svc = app(FileTypeService::class);
        $exts = config('file_types.types.office.extensions', []);
        $this->assertNotEmpty($exts);

        foreach ($exts as $ext) {
            $e = strtolower((string) $ext);
            $this->assertSame(
                'office',
                $svc->detectFileType(null, $e),
                "Extension .{$e} must map to office type",
            );
            $this->assertTrue(
                $svc->isOfficeDocument(null, $e),
                "isOfficeDocument must accept extension .{$e}",
            );
        }
    }

    public function test_office_registry_count_matches_product_set(): void
    {
        $exts = config('file_types.types.office.extensions', []);
        $mimes = config('file_types.types.office.mime_types', []);

        // Word / Excel / PowerPoint: legacy + OpenXML (6 extensions, 6 MIME rows today).
        $this->assertCount(6, $exts, 'Office extensions: doc, docx, xls, xlsx, ppt, pptx');
        $this->assertCount(6, $mimes, 'Office MIME types: one per extension family');
    }
}
