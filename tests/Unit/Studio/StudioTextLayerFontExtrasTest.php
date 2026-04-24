<?php

namespace Tests\Unit\Studio;

use App\Studio\Rendering\StudioTextLayerFontExtras;
use PHPUnit\Framework\TestCase;

class StudioTextLayerFontExtrasTest extends TestCase
{
    public function test_font_key_in_props_style_is_merged_into_extra(): void
    {
        $ly = [
            'type' => 'text',
            'style' => [
                'fontFamily' => 'Inter',
            ],
            'props' => [
                'style' => [
                    'fontKey' => 'bundled:inter-regular',
                ],
            ],
        ];
        $extra = StudioTextLayerFontExtras::mergeFromDocumentLayer($ly, [
            'font_family' => 'Inter',
            'content' => 'Hello',
        ]);

        $this->assertSame('bundled:inter-regular', $extra['fontKey'] ?? null);
    }

    public function test_font_key_on_layer_root_is_copied(): void
    {
        $ly = [
            'type' => 'text',
            'fontKey' => 'google:some-slug',
            'style' => ['fontFamily' => 'Inter'],
        ];
        $extra = StudioTextLayerFontExtras::mergeFromDocumentLayer($ly, ['font_family' => 'Inter']);

        $this->assertSame('google:some-slug', $extra['fontKey'] ?? null);
    }

    public function test_shallow_merge_matches_props_style_over_style_block(): void
    {
        $ly = [
            'style' => ['fontKey' => 'bundled:first'],
            'props' => ['style' => ['fontKey' => 'bundled:second']],
        ];
        $merged = StudioTextLayerFontExtras::mergeShallowStyleSources($ly);

        $this->assertSame('bundled:second', $merged['fontKey'] ?? null);
    }
}
