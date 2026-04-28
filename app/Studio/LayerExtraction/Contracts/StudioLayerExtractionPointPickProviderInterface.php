<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Models\Asset;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;

/**
 * Optional: interactive point prompts (local seed flood-fill now; future SAM positive/negative points).
 * TODO: future include/exclude points, box prompts — see STUDIO_LAYER_EXTRACTION_PROVIDER=sam
 */
interface StudioLayerExtractionPointPickProviderInterface
{
    /**
     * @param  array{
     *   image_binary?: string,
     *   label: string,
     *   candidate_id: string,
     *   existing_bboxes?: list<array{x:int,y:int,width:int,height:int}>,
     * }  $options
     */
    public function extractCandidateFromPoint(Asset $asset, float $xNorm, float $yNorm, array $options = []): ?LayerExtractionCandidateDto;
}
