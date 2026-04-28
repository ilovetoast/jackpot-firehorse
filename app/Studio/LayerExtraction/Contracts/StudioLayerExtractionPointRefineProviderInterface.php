<?php

namespace App\Studio\LayerExtraction\Contracts;

use App\Models\Asset;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;

/**
 * Optional: refine a point-picked candidate with additional exclude (negative) points.
 */
interface StudioLayerExtractionPointRefineProviderInterface
{
    /**
     * Recompute a mask from the same positive seed(s) with negative points applied.
     * Must return a candidate with the same {@see LayerExtractionCandidateDto::$id} as input.
     *
     * @param  list<array{x: float, y: float}>  $positivePoints  Normalized 0..1, same order as original pick.
     * @param  list<array{x: float, y: float}>  $negativePoints  Normalized 0..1
     * @param  array{image_binary?: string}  $options
     */
    public function refineCandidateWithPoints(
        Asset $asset,
        LayerExtractionCandidateDto $candidate,
        array $positivePoints,
        array $negativePoints,
        array $options = []
    ): ?LayerExtractionCandidateDto;
}
