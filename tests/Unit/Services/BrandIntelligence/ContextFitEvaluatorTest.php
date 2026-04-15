<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Enums\AlignmentDimension;
use App\Enums\AssetContextType;
use App\Enums\DimensionStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\BrandIntelligence\Dimensions\ContextFitEvaluator;
use App\Services\BrandIntelligence\Dimensions\EvaluationContext;
use Tests\TestCase;

class ContextFitEvaluatorTest extends TestCase
{
    public function test_pdf_other_with_page_raster_hint_is_evaluable(): void
    {
        $asset = new Asset(['mime_type' => 'application/pdf']);
        $brand = new Brand(['name' => 'Acme']);
        $ctx = new EvaluationContext(
            MediaType::PDF,
            AssetContextType::OTHER,
            ['screenshot'],
            [],
            false,
            null,
            null,
            true,
        );

        $eval = new ContextFitEvaluator;
        $r = $eval->evaluate($asset, $brand, $ctx);

        $this->assertSame(AlignmentDimension::CONTEXT_FIT, $r->dimension);
        $this->assertTrue($r->evaluable);
        $this->assertSame(DimensionStatus::WEAK, $r->status);
    }

    public function test_enrich_includes_scene_type_only(): void
    {
        $asset = new Asset(['mime_type' => 'application/pdf']);
        $brand = new Brand(['name' => 'Acme']);
        $ctx = new EvaluationContext(
            MediaType::PDF,
            AssetContextType::PRODUCT_HERO,
            ['screenshot'],
            [],
            false,
            null,
            null,
            false,
        );

        $eval = new ContextFitEvaluator;
        $base = $eval->evaluate($asset, $brand, $ctx);

        $enriched = $eval->enrichWithCreativeIntelligence($base, [
            'context_type_ai' => null,
            'mood' => null,
            'scene_type' => 'product sell sheet',
        ]);

        $this->assertTrue($enriched->evaluable);
        $texts = array_map(static fn ($e) => $e->message, $enriched->evidence);
        $joined = implode(' ', $texts);
        $this->assertStringContainsString('sell sheet', $joined);
    }
}
