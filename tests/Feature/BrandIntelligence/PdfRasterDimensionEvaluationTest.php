<?php

namespace Tests\Feature\BrandIntelligence;

use App\Enums\AlignmentDimension;
use App\Enums\AssetContextType;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandVisualReference;
use App\Models\Tenant;
use App\Services\BrandIntelligence\AssetContextClassifier;
use App\Services\BrandIntelligence\BrandColorPaletteAlignmentEvaluator;
use App\Services\BrandIntelligence\Dimensions\EvaluationContext;
use App\Services\BrandIntelligence\Dimensions\EvaluationOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ensures dimension evaluation treats PDFs with a resolver-backed page raster like visual assets
 * (not blocked by root application/pdf MIME), while embeddings still gate style similarity.
 */
class PdfRasterDimensionEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_with_rendered_raster_produces_evaluable_core_dimensions(): void
    {
        $tenant = Tenant::create(['name' => 'BI PDF Tenant', 'slug' => 'bi-pdf-tenant']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'PdfRasterCo',
            'slug' => 'pdf-raster-co',
        ]);

        $brandModel = BrandModel::query()->where('brand_id', $brand->id)->firstOrFail();
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'status' => 'active',
            'model_payload' => [
                'personality' => [
                    'voice' => 'Clear and confident',
                    'tone' => 'Professional',
                ],
                'visual' => [
                    'colors' => ['#112233'],
                ],
            ],
        ]);
        $brandModel->update(['active_version_id' => $version->id]);

        $vec = [1.0, 0.0, 0.0];
        for ($i = 0; $i < 3; $i++) {
            BrandVisualReference::create([
                'brand_id' => $brand->id,
                'asset_id' => null,
                'embedding_vector' => $vec,
                'type' => BrandVisualReference::TYPE_PRODUCT_PHOTOGRAPHY,
                'reference_type' => BrandVisualReference::REFERENCE_TYPE_STYLE,
                'reference_tier' => BrandVisualReference::TIER_GUIDELINE,
                'weight' => 1.0,
            ]);
        }

        $assetId = (string) Str::uuid();
        $asset = Asset::create([
            'id' => $assetId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'lifecycle' => 'active',
            'original_filename' => 'ebi-misc-sheet-2024.pdf',
            'mime_type' => 'application/pdf',
            'path' => 'tenants/'.$tenant->id.'/assets/'.$assetId.'.pdf',
            'size_bytes' => 1200,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/'.$tenant->id.'/page_1.png'],
                    ],
                ],
                'dominant_colors' => [['hex' => '#445566']],
                'extracted_text' => 'PdfRasterCo annual overview with product highlights and brand story for stakeholders.',
            ],
        ]);

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'model' => 'test-clip',
        ]);

        $orchestrator = new EvaluationOrchestrator(
            new BrandColorPaletteAlignmentEvaluator,
            new AssetContextClassifier,
        );

        $out = $orchestrator->evaluate($asset, $brand, 'PdfRasterCo headline from vision pass for copy scoring.');
        /** @var EvaluationContext $ctx */
        $ctx = $out['context'];

        $this->assertTrue($ctx->visualEvaluationRasterResolved);
        $this->assertContains('screenshot', $ctx->availableExtractions);
        $this->assertContains('embeddings', $ctx->availableExtractions);
        $this->assertSame(AssetContextType::OTHER, $ctx->contextType);

        $dims = $out['dimensions'];
        $this->assertTrue($dims[AlignmentDimension::VISUAL_STYLE->value]->evaluable, 'visual_style');
        $this->assertTrue($dims[AlignmentDimension::IDENTITY->value]->evaluable, 'identity');
        $this->assertTrue($dims[AlignmentDimension::COPY_VOICE->value]->evaluable, 'copy_voice');
        $this->assertTrue($dims[AlignmentDimension::CONTEXT_FIT->value]->evaluable, 'context_fit');
    }
}
