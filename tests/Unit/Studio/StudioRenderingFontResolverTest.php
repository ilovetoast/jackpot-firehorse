<?php

namespace Tests\Unit\Studio;

use App\Models\Tenant;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioRenderingFontResolverTest extends TestCase
{
    private function resolver(): StudioRenderingFontResolver
    {
        return new StudioRenderingFontResolver(new StudioRenderingFontFileCache, new StudioGoogleFontFileCache);
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

    public function test_unknown_family_falls_back_to_effective_default(): void
    {
        Config::set('studio_rendering.default_font_path', '/nonexistent/dejavu_missing.ttf');
        Config::set('studio_rendering.font_family_map', []);
        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Unknown, serif'],
            'Unknown, serif',
        );
        $this->assertContains($r->source, ['default', 'legacy_bundled']);
        $this->assertFileExists($r->absolutePath);
    }

    public function test_legacy_inter_resolves_to_bundled_when_present(): void
    {
        $inter = resource_path('fonts/inter/Inter-Regular.ttf');
        if (! is_file($inter)) {
            $this->markTestSkipped('Bundled Inter font not present');
        }
        Config::set('studio_rendering.default_font_path', '');
        Config::set('studio_rendering.font_family_map', []);
        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Inter, system-ui', 'font_weight' => 400],
            'Inter, system-ui',
        );
        $this->assertSame('legacy_bundled', $r->source);
        $this->assertSame($inter, $r->absolutePath);
    }

    public function test_bundled_font_key_resolves(): void
    {
        $inter = resource_path('fonts/inter/Inter-Regular.ttf');
        if (! is_file($inter)) {
            $this->markTestSkipped('Bundled Inter font not present');
        }
        Config::set('studio_rendering.default_font_path', '');
        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_key' => 'bundled:inter-regular', 'font_family' => 'Inter'],
            'Inter',
        );
        $this->assertSame('font_key_bundled', $r->source);
        $this->assertSame('bundled:inter-regular', $r->resolvedFontKey);
        $this->assertSame($inter, $r->absolutePath);
    }

    public function test_google_font_token_resolves_through_cache(): void
    {
        Http::fake(function () {
            return Http::response(str_repeat('0', 400), 200);
        });
        Config::set('studio_rendering.font_cache_dir', 'studio/font-cache-test-'.uniqid('', true));
        Config::set('studio_rendering.default_font_path', '');
        $r = $this->resolver()->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_key' => 'google:pacifico-regular', 'font_family' => 'Pacifico'],
            'Pacifico',
        );
        $this->assertSame('font_key_google', $r->source);
        $this->assertStringEndsWith('.ttf', strtolower($r->absolutePath));
        $this->assertFileExists($r->absolutePath);
    }

    public function test_layer_has_explicit_custom_font_selection(): void
    {
        $resolver = $this->resolver();
        $this->assertTrue($resolver->layerHasExplicitCustomFontSelection([
            'font_asset_id' => '550e8400-e29b-41d4-a716-446655440000',
            'font_family' => 'Foo',
        ]));
        $this->assertTrue($resolver->layerHasExplicitCustomFontSelection([
            'font_key' => 'bundled:inter-regular',
            'font_family' => 'Inter',
        ]));
        $this->assertFalse($resolver->layerHasExplicitCustomFontSelection([
            'font_family' => 'Inter, sans-serif',
        ]));
    }
}
