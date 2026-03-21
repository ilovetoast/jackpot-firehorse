<?php

namespace Tests\Unit\Support\Metadata;

use App\Support\Metadata\CategoryTypeResolver;
use PHPUnit\Framework\TestCase;

class CategoryTypeResolverTest extends TestCase
{
    public function test_resolves_photography_to_photo_type(): void
    {
        $r = CategoryTypeResolver::resolve('photography');
        $this->assertSame('photo_type', $r['field_key']);
        $this->assertSame('Type', $r['label']);
    }

    public function test_resolves_videos_to_execution_video_type(): void
    {
        $r = CategoryTypeResolver::resolve('videos');
        $this->assertSame('execution_video_type', $r['field_key']);
    }

    public function test_resolves_digital_ads_slug(): void
    {
        $r = CategoryTypeResolver::resolve('digital-ads');
        $this->assertSame('digital_type', $r['field_key']);
    }

    public function test_unknown_slug_returns_null(): void
    {
        $this->assertNull(CategoryTypeResolver::resolve('custom-category'));
    }
}
