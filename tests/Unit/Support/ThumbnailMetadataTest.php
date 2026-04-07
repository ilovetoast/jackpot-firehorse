<?php

namespace Tests\Unit\Support;

use App\Support\ThumbnailMetadata;
use App\Support\ThumbnailMode;
use PHPUnit\Framework\TestCase;

class ThumbnailMetadataTest extends TestCase
{
    public function test_style_path_reads_nested_original_mode(): void
    {
        $meta = [
            'thumbnails' => [
                'original' => [
                    'thumb' => ['path' => 't/a/v1/thumbnails/original/thumb/thumb.webp'],
                ],
            ],
        ];

        $this->assertSame(
            't/a/v1/thumbnails/original/thumb/thumb.webp',
            ThumbnailMetadata::stylePath($meta, 'thumb')
        );
    }

    public function test_style_path_falls_back_to_legacy_flat_shape(): void
    {
        $meta = [
            'thumbnails' => [
                'medium' => ['path' => 'legacy/thumbnails/medium/medium.webp'],
            ],
        ];

        $this->assertSame(
            'legacy/thumbnails/medium/medium.webp',
            ThumbnailMetadata::stylePath($meta, 'medium')
        );
    }

    public function test_preview_path_prefers_nested_mode_then_legacy(): void
    {
        $nested = [
            'preview_thumbnails' => [
                'original' => [
                    'preview' => ['path' => 'nested/preview.webp'],
                ],
            ],
        ];
        $this->assertSame('nested/preview.webp', ThumbnailMetadata::previewPath($nested));

        $legacy = [
            'preview_thumbnails' => [
                'preview' => ['path' => 'legacy/preview.webp'],
            ],
        ];
        $this->assertSame('legacy/preview.webp', ThumbnailMetadata::previewPath($legacy));
    }

    public function test_dimensions_supports_nested_and_legacy(): void
    {
        $nested = ['thumbnail_dimensions' => ['original' => ['medium' => ['width' => 10, 'height' => 20]]]];
        $this->assertSame(['width' => 10, 'height' => 20], ThumbnailMetadata::dimensionsForStyle($nested, 'medium'));

        $legacy = ['thumbnail_dimensions' => ['medium' => ['width' => 3, 'height' => 4]]];
        $this->assertSame(['width' => 3, 'height' => 4], ThumbnailMetadata::dimensionsForStyle($legacy, 'medium'));
    }

    public function test_thumbnail_mode_values(): void
    {
        $this->assertSame('original', ThumbnailMode::default());
        $this->assertSame('preferred', ThumbnailMode::normalize('preferred'));
        $this->assertSame('enhanced', ThumbnailMode::normalize('enhanced'));
    }
}
