<?php

namespace Tests\Unit;

use App\Studio\Animation\Providers\Kling\KlingNativeAspectMapper;
use PHPUnit\Framework\TestCase;

class KlingNativeAspectMapperTest extends TestCase
{
    public function test_passthrough_common_ratios_including_4_5_and_3_4(): void
    {
        $this->assertSame('16:9', KlingNativeAspectMapper::toKlingRequestValue('16:9'));
        $this->assertSame('9:16', KlingNativeAspectMapper::toKlingRequestValue('9:16'));
        $this->assertSame('1:1', KlingNativeAspectMapper::toKlingRequestValue('1:1'));
        $this->assertSame('4:5', KlingNativeAspectMapper::toKlingRequestValue('4:5'));
        $this->assertSame('3:4', KlingNativeAspectMapper::toKlingRequestValue('3:4'));
    }

    public function test_2_3_string_maps_to_nearest_of_kling_three_because_unlisted(): void
    {
        $this->assertSame('9:16', KlingNativeAspectMapper::toKlingRequestValue('2:3'));
    }

    public function test_malformed_falls_back_to_1_1(): void
    {
        $this->assertSame('1:1', KlingNativeAspectMapper::toKlingRequestValue('not-a-ratio'));
    }
}
