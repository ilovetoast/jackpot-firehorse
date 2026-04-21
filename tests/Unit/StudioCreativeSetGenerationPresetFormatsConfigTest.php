<?php

namespace Tests\Unit;

use Tests\TestCase;

class StudioCreativeSetGenerationPresetFormatsConfigTest extends TestCase
{
    public function test_format_pack_quick_ids_reference_existing_presets(): void
    {
        $formats = config('studio_creative_set_generation.preset_formats');
        $this->assertIsArray($formats);
        $ids = [];
        foreach ($formats as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey('id', $row);
            $ids[] = (string) $row['id'];
        }

        $quick = config('studio_creative_set_generation.format_pack_quick_ids');
        $this->assertIsArray($quick);
        $this->assertNotSame([], $quick, 'format_pack_quick_ids should list at least one preset id');

        foreach ($quick as $q) {
            $this->assertContains((string) $q, $ids, 'format_pack_quick_ids must only reference preset_formats.id values');
        }
    }

    public function test_preset_formats_have_stable_shape(): void
    {
        $formats = config('studio_creative_set_generation.preset_formats');
        foreach ($formats as $row) {
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('width', $row);
            $this->assertArrayHasKey('height', $row);
            $this->assertArrayHasKey('group', $row);
            $this->assertNotSame('', trim((string) $row['group']));
            $this->assertGreaterThan(0, (int) $row['width']);
            $this->assertGreaterThan(0, (int) $row['height']);
        }
    }

    public function test_format_group_labels_cover_order_keys(): void
    {
        $order = config('studio_creative_set_generation.format_group_order');
        $labels = config('studio_creative_set_generation.format_group_labels');
        $this->assertIsArray($order);
        $this->assertIsArray($labels);
        foreach ($order as $g) {
            $key = (string) $g;
            $this->assertArrayHasKey($key, $labels, "format_group_labels missing entry for group {$key}");
        }
    }
}
