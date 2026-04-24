<?php

namespace App\Studio\Rendering\Dto;

/**
 * Serializable font option for the editor registry (and internal catalog rows).
 *
 * @phpstan-type Serialized array{
 *   key: string,
 *   label: string,
 *   family: string,
 *   source: string,
 *   weight: int,
 *   style: string,
 *   export_supported: bool,
 *   css_stack?: string,
 *   asset_id?: string,
 *   weights?: list<array{value:int,label:string}>,
 * }
 */
final readonly class StudioFontDescriptor
{
    /**
     * @param  list<array{value:int,label:string}>|null  $weights
     */
    public function __construct(
        public string $key,
        public string $label,
        /** bundled | google | tenant | system */
        public string $source,
        public string $family,
        public int $weight,
        public string $style,
        public bool $exportSupported,
        public ?string $cssStack = null,
        public ?string $assetId = null,
        public ?array $weights = null,
    ) {}

    /**
     * @return Serialized
     */
    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'label' => $this->label,
            'family' => $this->family,
            'source' => $this->source,
            'weight' => $this->weight,
            'style' => $this->style,
            'export_supported' => $this->exportSupported,
        ];
        if ($this->cssStack !== null) {
            $out['css_stack'] = $this->cssStack;
        }
        if ($this->assetId !== null) {
            $out['asset_id'] = $this->assetId;
        }
        if ($this->weights !== null) {
            $out['weights'] = $this->weights;
        }

        return $out;
    }
}
