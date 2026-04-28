<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Models\Asset;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;

/**
 * Local or future SAM-style box-prompt extraction (normalized 0–1 in image space).
 * TODO: SAM / cloud provider can share the same session endpoint with metadata boxes / prompt_type: box.
 */
interface StudioLayerExtractionBoxPickProviderInterface
{
    /**
     * @param  array{x: float, y: float, width: float, height: float}  $boxNorm
     * @param  array{
     *   image_binary?: string,
     *   label: string,
     *   candidate_id: string,
     *   mode?: string
     * }  $options
     */
    public function extractCandidateFromBox(Asset $asset, array $boxNorm, array $options = []): ?LayerExtractionCandidateDto;
}
