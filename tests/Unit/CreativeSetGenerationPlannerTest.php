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
        $out = $planner->plan(99, $colors, $scenes, null);
        $this->assertSame(['c:a|s:x', 'c:a|s:y', 'c:b|s:x', 'c:b|s:y'], $out['keys']);

        $parsed = $planner->parseCombinationKey('c:a|s:x', $out['snapshot']);
        $this->assertSame('A', $parsed['color']['label'] ?? null);
        $this->assertSame('ix', $parsed['scene']['instruction'] ?? null);
    }

    public function test_selected_keys_filter(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'a', 'label' => 'A']];
        $scenes = [['id' => 'x', 'label' => 'X', 'instruction' => 'ix'], ['id' => 'y', 'label' => 'Y', 'instruction' => 'iy']];
        $out = $planner->plan(1, $colors, $scenes, ['c:a|s:y']);
        $this->assertSame(['c:a|s:y'], $out['keys']);
    }

    public function test_selected_keys_unknown_are_filtered_out(): void
    {
        $planner = new CreativeSetGenerationPlanner;
        $colors = [['id' => 'a', 'label' => 'A']];
        $scenes = [['id' => 'x', 'label' => 'X', 'instruction' => 'ix']];
        $out = $planner->plan(1, $colors, $scenes, ['c:a|s:does-not-exist']);
        $this->assertSame([], $out['keys']);
    }
}
