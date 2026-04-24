<?php

namespace Tests\Unit\Studio;

use App\Services\Studio\StudioEditorFontRegistryService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StudioEditorFontRegistryServiceTest extends TestCase
{
    public function test_editor_font_list_groups_include_bundled_and_google(): void
    {
        $tmp = sys_get_temp_dir().'/jp_reg_font_'.uniqid('', true).'.ttf';
        file_put_contents($tmp, str_repeat('0', 200));
        try {
            Config::set('studio_rendering.fonts.bundled', [
                'demo-regular' => [
                    'label' => 'Demo',
                    'family' => 'Demo',
                    'weight' => 400,
                    'style' => 'normal',
                    'path' => $tmp,
                    'export_supported' => true,
                ],
            ]);
            Config::set('studio_rendering.fonts.google', [
                'demo-google' => [
                    'label' => 'GDemo',
                    'family' => 'GDemo',
                    'weight' => 400,
                    'style' => 'normal',
                    'download_url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/pacifico/Pacifico-Regular.ttf',
                    'supported_export' => true,
                ],
            ]);
            $svc = new StudioEditorFontRegistryService;
            $out = $svc->groupedFonts(null);
            $ids = array_column($out['groups'], 'id');
            $this->assertContains('google', $ids);
            $this->assertContains('bundled', $ids);
            $this->assertContains('system', $ids);
        } finally {
            @unlink($tmp);
        }
    }
}
