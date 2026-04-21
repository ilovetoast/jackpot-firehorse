<?php

namespace Tests\Unit;

use App\Services\Studio\CreativeSetGenerationPlanner;
use PHPUnit\Framework\TestCase;

class CreativeSetGenerationPlannerTest extends TestCase
{
    public function test_cartesian_color_and_scene_keys(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [
            ['id' => 'a', 'label' => 'A'],
            ['id' => 'b', 'label' => 'B'],
        ];
        $scenes = [
            ['id' => 'x', 'label' => 'X', 'instruction' => 'ix'],
            ['id' => 'y', 'label' => 'Y', 'instruction' => 'iy'],
        ];
        $out = $planner->plan(99, $colors, $scenes, [], null);
        $this->assertSame(['c:a|s:x', 'c:a|s:y', 'c:b|s:x', 'c:b|s:y'], $out['keys']);

        $parsed = $planner->parseCombinationKey('c:a|s:x', $out['snapshot']);
        $this->assertSame('A', $parsed['color']['label'] ?? null);
        $this->assertSame('ix', $parsed['scene']['instruction'] ?? null);
        $this->assertNull($parsed['format']);
    }

    public function test_selected_keys_filter(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'a', 'label' => 'A']];
        $scenes = [['id' => 'x', 'label' => 'X', 'instruction' => 'ix'], ['id' => 'y', 'label' => 'Y', 'instruction' => 'iy']];
        $out = $planner->plan(1, $colors, $scenes, [], ['c:a|s:y']);
        $this->assertSame(['c:a|s:y'], $out['keys']);
    }

    public function test_selected_keys_unknown_are_filtered_out(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'a', 'label' => 'A']];
        $scenes = [['id' => 'x', 'label' => 'X', 'instruction' => 'ix']];
        $out = $planner->plan(1, $colors, $scenes, [], ['c:a|s:does-not-exist']);
        $this->assertSame([], $out['keys']);
    }

    public function test_formats_only_keys(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $formats = [
            ['id' => 'square_1080', 'label' => 'Square', 'width' => 1080, 'height' => 1080],
            ['id' => 'story_1080x1920', 'label' => 'Story', 'width' => 1080, 'height' => 1920],
        ];
        $out = $planner->plan(1, [], [], $formats, null);
        $this->assertSame(['f:square_1080', 'f:story_1080x1920'], $out['keys']);
        $parsed = $planner->parseCombinationKey('f:story_1080x1920', $out['snapshot']);
        $this->assertNull($parsed['color']);
        $this->assertNull($parsed['scene']);
        $this->assertSame('Story', $parsed['format']['label'] ?? null);
        $this->assertSame(1920, (int) ($parsed['format']['height'] ?? 0));
    }

    public function test_color_scene_format_cartesian(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'navy', 'label' => 'Navy']];
        $scenes = [['id' => 'studio', 'label' => 'Studio', 'instruction' => 'studio']];
        $formats = [
            ['id' => 'square_1080', 'label' => 'Square', 'width' => 1080, 'height' => 1080],
            ['id' => 'portrait_1080x1350', 'label' => 'Portrait', 'width' => 1080, 'height' => 1350],
        ];
        $out = $planner->plan(5, $colors, $scenes, $formats, null);
        $this->assertSame([
            'c:navy|s:studio|f:square_1080',
            'c:navy|s:studio|f:portrait_1080x1350',
        ], $out['keys']);
    }

    public function test_color_and_format_without_scene(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'a', 'label' => 'A']];
        $formats = [['id' => 'square_1080', 'label' => 'Square', 'width' => 1080, 'height' => 1080]];
        $out = $planner->plan(1, $colors, [], $formats, null);
        $this->assertSame(['c:a|f:square_1080'], $out['keys']);
    }
}
