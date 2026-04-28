<?php

namespace App\Studio\LayerExtraction\Dto;

final readonly class LayerExtractionResult
{
    /**
     * @param  list<LayerExtractionCandidateDto>  $candidates
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $sourceAssetId,
        public array $candidates,
    ) {}
}
