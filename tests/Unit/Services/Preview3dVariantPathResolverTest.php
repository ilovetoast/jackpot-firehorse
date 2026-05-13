<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Services\AssetVariantPathResolver;
use App\Support\AssetVariant;
use Tests\TestCase;

/**
 * Preview 3D paths from metadata only — no DB (unsaved Asset).
 */
class Preview3dVariantPathResolverTest extends TestCase
{
    public function test_poster_path_resolves_and_viewer_path_empty_until_set(): void
    {
        $posterKey = 'tenants/abc/assets/123/v1/previews/model_3d_poster.webp';
        $asset = new Asset([
            'metadata' => [
                'preview_3d' => [
                    'poster_path' => $posterKey,
                    'viewer_path' => null,
                ],
            ],
        ]);

        $resolver = app(AssetVariantPathResolver::class);

        $this->assertSame($posterKey, $resolver->resolve($asset, AssetVariant::PREVIEW_3D_POSTER->value));
        $this->assertSame('', $resolver->resolve($asset, AssetVariant::PREVIEW_3D_GLB->value));
    }

    public function test_glb_path_resolves_when_viewer_path_present(): void
    {
        $glbKey = 'tenants/abc/assets/123/v1/previews/model_3d_viewer.glb';
        $asset = new Asset([
            'metadata' => [
                'preview_3d' => [
                    'poster_path' => 'tenants/abc/assets/123/v1/previews/poster.webp',
                    'viewer_path' => $glbKey,
                ],
            ],
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $this->assertSame($glbKey, $resolver->resolve($asset, AssetVariant::PREVIEW_3D_GLB->value));
    }

    public function test_poster_path_rejects_http_url_string(): void
    {
        $asset = new Asset([
            'metadata' => [
                'preview_3d' => [
                    'poster_path' => 'https://evil.example/poster.webp',
                ],
            ],
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $this->assertSame('', $resolver->resolve($asset, AssetVariant::PREVIEW_3D_POSTER->value));
    }

    public function test_glb_viewer_path_rejects_https_and_scheme_urls(): void
    {
        $resolver = app(AssetVariantPathResolver::class);

        $https = new Asset([
            'metadata' => [
                'preview_3d' => [
                    'viewer_path' => 'https://evil.example/x.glb',
                ],
            ],
        ]);
        $this->assertSame('', $resolver->resolve($https, AssetVariant::PREVIEW_3D_GLB->value));

        $scheme = new Asset([
            'metadata' => [
                'preview_3d' => [
                    'viewer_path' => 's3://bucket/key.glb',
                ],
            ],
        ]);
        $this->assertSame('', $resolver->resolve($scheme, AssetVariant::PREVIEW_3D_GLB->value));
    }
}
