<?php

namespace Tests\Unit\Services\AI\Insights;

use App\Services\AI\Insights\FieldNamingService;
use Tests\TestCase;

class FieldNamingServiceTest extends TestCase
{
    public function test_outdoor_is_skipped_as_too_broad(): void
    {
        $s = new FieldNamingService;
        $this->assertNull($s->inferFieldName('outdoor'));
    }

    public function test_fishing_maps_to_fish_species_not_naive_ing_stem(): void
    {
        $s = new FieldNamingService;
        $r = $s->inferFieldName('fishing');
        $this->assertNotNull($r);
        $this->assertSame('fish_species', $r['field_key']);
        $this->assertSame('Fish species', $r['field_name']);
    }

    public function test_branding_maps_to_brand_type(): void
    {
        $s = new FieldNamingService;
        $r = $s->inferFieldName('branding');
        $this->assertNotNull($r);
        $this->assertSame('brand_type', $r['field_key']);
        $this->assertSame('Brand Type', $r['field_name']);
    }

    public function test_hiking_falls_back_to_related_tags_not_hik_species(): void
    {
        $s = new FieldNamingService;
        $r = $s->inferFieldName('hiking');
        $this->assertNotNull($r);
        $this->assertStringEndsWith('_related_tags', $r['field_key']);
        $this->assertNotSame('hik_species', $r['field_key']);
    }
}
