<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Studio\LayerExtraction\Sam\SamPromptMapper;
use PHPUnit\Framework\TestCase;

class SamPromptMapperTest extends TestCase
{
    public function test_for_point_maps_positive_point_prompt(): void
    {
        $p = SamPromptMapper::forPoint(0.4, 0.55, 100, 200);
        $this->assertSame('point', $p['mode']);
        $this->assertSame([['x' => 0.4, 'y' => 0.55]], $p['positive_points']);
        $this->assertSame(['width' => 100, 'height' => 200], $p['image_size']);
    }

    public function test_for_refine_maps_positive_and_negative_points(): void
    {
        $pos = [['x' => 0.1, 'y' => 0.2]];
        $neg = [['x' => 0.9, 'y' => 0.8], ['x' => 0.5, 'y' => 0.5]];
        $p = SamPromptMapper::forRefine($pos, $neg, 640, 480);
        $this->assertSame('refine', $p['mode']);
        $this->assertSame($pos, $p['positive_points']);
        $this->assertSame($neg, $p['negative_points']);
    }

    public function test_for_box_maps_box_prompt(): void
    {
        $box = ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4];
        $p = SamPromptMapper::forBox($box, 800, 600);
        $this->assertSame('box', $p['mode']);
        $this->assertCount(1, $p['boxes']);
        $this->assertSame($box, $p['boxes'][0]);
    }

    public function test_for_auto_includes_image_size(): void
    {
        $p = SamPromptMapper::forAuto(123, 456);
        $this->assertSame('auto', $p['mode']);
        $this->assertSame(['width' => 123, 'height' => 456], $p['image_size']);
    }
}
