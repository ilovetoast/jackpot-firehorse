<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Preview3dMetadata;
use PHPUnit\Framework\TestCase;

final class Preview3dMetadataDerivativeKeysTest extends TestCase
{
    public function test_derivative_cleanup_includes_poster_and_thumb_excludes_viewer_when_same_as_root(): void
    {
        $root = 'tenants/u1/assets/a1/v1/model.glb';
        $meta = [
            'preview_3d' => [
                'viewer_path' => $root,
                'poster_path' => 'tenants/u1/assets/a1/v1/thumbnails/poster.webp',
                'thumbnail_path' => 'tenants/u1/assets/a1/v1/thumbnails/t.webp',
            ],
        ];
        $keys = Preview3dMetadata::derivativeStorageKeysForCleanup($meta, $root);
        sort($keys);
        $this->assertSame([
            'tenants/u1/assets/a1/v1/thumbnails/poster.webp',
            'tenants/u1/assets/a1/v1/thumbnails/t.webp',
        ], $keys);
    }

    public function test_derivative_cleanup_includes_distinct_viewer_path(): void
    {
        $root = 'tenants/u1/assets/a1/v1/source.glb';
        $viewer = 'tenants/u1/assets/a1/v1/derived.glb';
        $meta = [
            'preview_3d' => [
                'viewer_path' => $viewer,
                'poster_path' => 'p.webp',
            ],
        ];
        $keys = Preview3dMetadata::derivativeStorageKeysForCleanup($meta, $root);
        sort($keys);
        $this->assertSame([
            'p.webp',
            'tenants/u1/assets/a1/v1/derived.glb',
        ], $keys);
    }

    public function test_cache_revision_changes_when_poster_path_changes(): void
    {
        $a = ['preview_3d' => ['status' => 'ready', 'poster_path' => 'a.webp', 'viewer_path' => 'm.glb']];
        $b = ['preview_3d' => ['status' => 'ready', 'poster_path' => 'b.webp', 'viewer_path' => 'm.glb']];
        $this->assertNotSame(
            Preview3dMetadata::cacheRevisionToken($a),
            Preview3dMetadata::cacheRevisionToken($b)
        );
    }
}
