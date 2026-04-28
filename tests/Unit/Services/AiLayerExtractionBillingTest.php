<?php

namespace Tests\Unit\Services;

use App\Models\StudioLayerExtractionSession;
use App\Services\Studio\AiLayerExtractionService;
use App\Services\Studio\StudioLayerExtractionMethodService;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use Tests\TestCase;

class AiLayerExtractionBillingTest extends TestCase
{
    public function test_floodfill_default_does_not_bill_extraction(): void
    {
        config([
            'studio_layer_extraction.provider' => 'floodfill',
            'studio_layer_extraction.bill_floodfill_extraction' => false,
        ]);
        $this->assertFalse(AiLayerExtractionService::shouldBillExtractionForConfig());
    }

    public function test_sam_config_disabled_does_not_bill_as_sam_uses_floodfill_engine(): void
    {
        config([
            'studio_layer_extraction.provider' => 'sam',
            'studio_layer_extraction.sam.enabled' => false,
            'studio_layer_extraction.bill_floodfill_extraction' => false,
        ]);
        $this->assertFalse(AiLayerExtractionService::shouldBillExtractionForConfig());
    }

    public function test_sam_enabled_bills_extraction(): void
    {
        config([
            'studio_layer_extraction.provider' => 'sam',
            'studio_layer_extraction.sam.enabled' => true,
        ]);
        $this->assertTrue(AiLayerExtractionService::shouldBillExtractionForConfig());
    }

    public function test_uses_ai_segmentation_true_when_sam_fal_key_set(): void
    {
        config([
            'studio_layer_extraction.provider' => 'sam',
            'studio_layer_extraction.sam.enabled' => true,
            'studio_layer_extraction.sam.sam_provider' => 'fal',
            'services.fal.key' => 'k_test',
        ]);
        $this->assertTrue(AiLayerExtractionService::usesAiRemoteSegmentation());
    }

    public function test_uses_ai_segmentation_false_without_fal_key(): void
    {
        config([
            'studio_layer_extraction.provider' => 'sam',
            'studio_layer_extraction.sam.enabled' => true,
            'studio_layer_extraction.sam.sam_provider' => 'fal',
            'services.fal.key' => null,
        ]);
        $this->assertFalse(AiLayerExtractionService::usesAiRemoteSegmentation());
    }

    public function test_should_bill_session_local_honors_bill_floodfill(): void
    {
        config(['studio_layer_extraction.bill_floodfill_extraction' => false]);
        $s = new StudioLayerExtractionSession(
            [
                'metadata' => [
                    'extraction_method' => StudioLayerExtractionMethodService::METHOD_LOCAL,
                ],
            ]
        );
        $this->assertFalse(AiLayerExtractionService::shouldBillExtractionForSession($s, null));
    }

    public function test_should_bill_session_ai_requires_fal_sam2_result(): void
    {
        $session = new StudioLayerExtractionSession(
            [
                'metadata' => [
                    'extraction_method' => StudioLayerExtractionMethodService::METHOD_AI,
                    'billable' => true,
                ],
            ]
        );
        $c = new LayerExtractionCandidateDto(
            'a',
            'x',
            1.0,
            ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
            null,
            'iVBORw0KGgo=',
            null,
            true,
            null,
            ['segmentation_engine' => 'fal_sam2'],
        );
        $result = new LayerExtractionResult('sam', 'm', '1', [$c]);
        $this->assertTrue(AiLayerExtractionService::resultUsesRemoteFal($result));
        $this->assertTrue(AiLayerExtractionService::shouldBillExtractionForSession($session, $result));
    }
}
