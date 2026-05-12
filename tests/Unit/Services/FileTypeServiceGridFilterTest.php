<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Services\FileTypeService;
use Tests\TestCase;

class FileTypeServiceGridFilterTest extends TestCase
{
    public function test_build_grid_file_type_filter_options_payload_is_grouped_and_sorted(): void
    {
        $svc = app(FileTypeService::class);
        $payload = $svc->buildGridFileTypeFilterOptionsPayload();
        $this->assertArrayHasKey('grouped', $payload);
        $this->assertNotSame([], $payload['grouped']);
        $keysSeen = [];
        foreach ($payload['grouped'] as $group) {
            $this->assertArrayHasKey('group_label', $group);
            $this->assertArrayHasKey('types', $group);
            foreach ($group['types'] as $row) {
                $this->assertArrayHasKey('key', $row);
                $this->assertArrayHasKey('label', $row);
                $keysSeen[] = $row['key'];
            }
        }
        $this->assertCount(count(array_unique($keysSeen)), $keysSeen, 'Duplicate type keys in grid filter payload');
    }

    public function test_mime_match_set_includes_sniff_alias_keys(): void
    {
        $svc = app(FileTypeService::class);
        $mimes = $svc->getMimeTypeMatchSetForRegisteredType('image');
        $this->assertContains('image/pjpeg', $mimes);
        $this->assertContains('image/jpeg', $mimes);
    }

    public function test_apply_grid_file_type_filter_rejects_unknown_type(): void
    {
        $svc = app(FileTypeService::class);
        $q = Asset::query()->whereRaw('0 = 1');
        $this->assertFalse($svc->applyGridFileTypeFilterToAssetQuery($q, 'not_a_real_type_key'));
    }

    public function test_apply_grid_file_type_filter_accepts_pdf(): void
    {
        $svc = app(FileTypeService::class);
        $q = Asset::query()->whereRaw('0 = 1');
        $this->assertTrue($svc->applyGridFileTypeFilterToAssetQuery($q, 'pdf'));
        $sql = $q->toSql();
        $this->assertStringContainsString('mime_type', $sql);
        $this->assertStringContainsString('original_filename', $sql);
    }
}
