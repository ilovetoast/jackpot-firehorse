<?php

namespace Tests\Unit\Studio;

use App\Models\Tenant;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StudioRenderingFontResolverTest extends TestCase
{
    private function resolver(): StudioRenderingFontResolver
    {
        return new StudioRenderingFontResolver(new StudioRenderingFontFileCache);
    }

    public function test_default_font_fallback_when_no_explicit_selection(): void
    {
        $tmp = sys_get_temp_dir().'/jp_font_test_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'dummy');
        Config::set('studio_rendering.default_font_path', $tmp);
        Config::set('studio_rendering.font_family_map', []);

        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Something, sans-serif'],
            'Something, sans-serif',
        );
        $this->assertSame('default', $r->source);
        $this->assertSame($tmp, $r->absolutePath);
        @unlink($tmp);
    }

    public function test_default_font_path_used_when_no_tenant_font_no_explicit_font_and_no_family_map(): void
    {
        $tmp = sys_get_temp_dir().'/jp_font_minimal_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'dummy');
        Config::set('studio_rendering.default_font_path', $tmp);
        Config::set('studio_rendering.font_family_map', []);

        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            [],
            'Some Web Font, sans-serif',
        );
        $this->assertSame('default', $r->source);
        $this->assertSame($tmp, $r->absolutePath);
        @unlink($tmp);
    }

    public function test_font_family_map_local_path(): void
    {
        $tmp = sys_get_temp_dir().'/jp_map_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'dummy');
        Config::set('studio_rendering.default_font_path', '');
        Config::set('studio_rendering.font_family_map', ['custombrand' => $tmp]);

        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'CustomBrand, serif'],
            'CustomBrand, serif',
        );
        $this->assertSame('family_map', $r->source);
        $this->assertSame($tmp, $r->absolutePath);
        @unlink($tmp);
    }

    public function test_explicit_missing_path_throws_not_silent_fallback(): void
    {
        Config::set('studio_rendering.default_font_path', '');
        $this->expectException(StudioFontResolutionException::class);
        $this->expectExceptionMessage('Explicit font path does not exist');

        $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            [
                'font_family' => 'X',
                'font_local_path' => '/nonexistent/path/to/font.ttf',
            ],
            'X',
        );
    }

    public function test_woff_explicit_path_rejected(): void
    {
        $tmp = sys_get_temp_dir().'/jp_woff_'.uniqid('', true).'.woff';
        file_put_contents($tmp, 'wOFF');
        Config::set('studio_rendering.default_font_path', '');
        try {
            $this->expectException(StudioFontResolutionException::class);
            $this->expectExceptionMessage('unsupported');
            $this->resolver()->resolveForTextLayer(
                new Tenant(['id' => 1]),
                null,
                [
                    'font_family' => 'X',
                    'font_local_path' => $tmp,
                ],
                'X',
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function test_default_font_path_must_exist_on_disk(): void
    {
        Config::set('studio_rendering.default_font_path', '/nonexistent/dejavu_missing.ttf');
        Config::set('studio_rendering.font_family_map', []);
        $this->expectException(StudioFontResolutionException::class);
        $this->expectExceptionMessage('is not a file');

        $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Unknown, serif'],
            'Unknown, serif',
        );
    }

    public function test_layer_has_explicit_custom_font_selection(): void
    {
        $resolver = $this->resolver();
        $this->assertTrue($resolver->layerHasExplicitCustomFontSelection([
            'font_asset_id' => '550e8400-e29b-41d4-a716-446655440000',
            'font_family' => 'Foo',
        ]));
        $this->assertFalse($resolver->layerHasExplicitCustomFontSelection([
            'font_family' => 'Inter, sans-serif',
        ]));
    }
}
