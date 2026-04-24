<?php

namespace Tests\Unit\Studio;

use App\Models\Tenant;
use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Support\Facades\Config;
use JsonException;
use Tests\TestCase;

class StudioRenderingDefaultFontConfigTest extends TestCase
{
    /**
     * @return array{default_font_path: string|null}
     *
     * @throws JsonException
     */
    private function bootstrapStudioRenderingConfigInSubprocess(string $fontPath): array
    {
        $root = base_path();
        $script = sys_get_temp_dir().'/jp_laravel_studio_cfg_sub_'.uniqid('', true).'.php';
        $payload = '<?php declare(strict_types=1);'."\n"
            .'$root = '.var_export($root, true).";\n"
            .'chdir($root);'."\n"
            .'putenv('.var_export('STUDIO_RENDERING_DEFAULT_FONT_PATH='.$fontPath, true).');'."\n"
            .'$_ENV['.var_export('STUDIO_RENDERING_DEFAULT_FONT_PATH', true).'] = '.var_export($fontPath, true).";\n"
            .'$_SERVER['.var_export('STUDIO_RENDERING_DEFAULT_FONT_PATH', true).'] = '.var_export($fontPath, true).";\n"
            ."require \$root.'/vendor/autoload.php';\n"
            ."\$app = require \$root.'/bootstrap/app.php';\n"
            ."\$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n"
            .'echo json_encode(['."\n"
            ."  'default_font_path' => config('studio_rendering.default_font_path'),\n"
            .'], JSON_THROW_ON_ERROR);'."\n";

        file_put_contents($script, $payload);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';
            $output = shell_exec($cmd);
            if (! is_string($output) || trim($output) === '') {
                $this->fail('Subprocess produced no output for studio_rendering config bootstrap.');
            }

            /** @var array{default_font_path: string|null} $decoded */
            $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } finally {
            @unlink($script);
        }
    }

    public function test_fresh_bootstrap_maps_studio_rendering_default_font_env_to_config(): void
    {
        $tmp = sys_get_temp_dir().'/jp_sub_cfg_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'x');
        try {
            $decoded = $this->bootstrapStudioRenderingConfigInSubprocess($tmp);
            $this->assertSame($tmp, $decoded['default_font_path']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_config_key_default_font_path_is_used_by_resolver(): void
    {
        $tmp = sys_get_temp_dir().'/jp_cfg_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, 'x');
        Config::set('studio_rendering.default_font_path', $tmp);
        Config::set('studio_rendering.font_family_map', []);

        $this->assertSame($tmp, config('studio_rendering.default_font_path'));

        $r = new StudioRenderingFontResolver(new StudioRenderingFontFileCache, new StudioGoogleFontFileCache);
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

    public function test_resolver_resolves_when_default_font_path_config_is_empty_string(): void
    {
        Config::set('studio_rendering.default_font_path', '');
        Config::set('studio_rendering.font_family_map', []);
        $inter = resource_path('fonts/inter/Inter-Regular.ttf');
        if (! is_file($inter)) {
            $this->markTestSkipped('Bundled Inter font not present');
        }
        $r = new StudioRenderingFontResolver(new StudioRenderingFontFileCache, new StudioGoogleFontFileCache);
        $resolved = $r->resolveForTextLayer(
            new Tenant(['id' => 1]),
            null,
            ['font_family' => 'Z, serif'],
            'Z, serif',
        );
        $this->assertContains($resolved->source, ['default', 'legacy_bundled']);
        $this->assertFileExists($resolved->absolutePath);
    }
}
