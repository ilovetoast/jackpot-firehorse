<?php

namespace Tests\Unit\Studio;

use App\Models\Tenant;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StudioRenderingDefaultFontConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('STUDIO_RENDERING_DEFAULT_FONT_PATH');
        parent::tearDown();
    }

    public function test_config_key_default_font_path_is_used_by_resolver(): void
    {
        $tmp = sys_get_temp_dir().'/jp_cfg_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'x');
        Config::set('studio_rendering.default_font_path', $tmp);
        Config::set('studio_rendering.font_family_map', []);

        $this->assertSame($tmp, config('studio_rendering.default_font_path'));

        $r = new StudioRenderingFontResolver(new StudioRenderingFontFileCache);
        $resolved = $r->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'UnknownStack, serif'],
            'UnknownStack, serif',
            'layer-1',
        );
        $this->assertSame('default', $resolved->source);
        $this->assertSame($tmp, $resolved->absolutePath);
        @unlink($tmp);
    }

    public function test_getenv_default_font_used_when_config_value_empty(): void
    {
        $tmp = sys_get_temp_dir().'/jp_env_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'x');
        Config::set('studio_rendering.default_font_path', '');
        Config::set('studio_rendering.font_family_map', []);
        putenv('STUDIO_RENDERING_DEFAULT_FONT_PATH='.$tmp);

        $r = new StudioRenderingFontResolver(new StudioRenderingFontFileCache);
        $resolved = $r->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Z, serif'],
            'Z, serif',
        );
        $this->assertSame('default', $resolved->source);
        $this->assertSame($tmp, $resolved->absolutePath);
        @unlink($tmp);
    }
}
