<?php

namespace App\Studio\LayerExtraction\Dto;

/**
 * @phpstan-type BBox array{x: int, y: int, width: int, height: int}
 */
final readonly class LayerExtractionCandidateDto
{
    /**
     * @param  BBox  $bbox
     */
    /**
     * @param  array<string, mixed>|null  $metadata  Provider-specific (e.g. method, area_ratio, component_id)
     */
    public function __construct(
        public string $id,
        public ?string $label,
        public ?float $confidence,
        public array $bbox,
        public ?string $maskPath,
        public ?string $maskBase64,
        public ?string $previewPath,
        public bool $selected,
        public ?string $notes,
        public ?array $metadata = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeInternalPaths = true): array
    {
        $row = [
            'id' => $this->id,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'bbox' => [
                'x' => $this->bbox['x'],
                'y' => $this->bbox['y'],
                'width' => $this->bbox['width'],
                'height' => $this->bbox['height'],
            ],
            'selected' => $this->selected,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ];
        if ($includeInternalPaths) {
            $row['mask_path'] = $this->maskPath;
            $row['mask_base64'] = $this->maskBase64;
            $row['preview_path'] = $this->previewPath;
        }

        return $row;
    }
}
