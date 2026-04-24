<?php

namespace App\Studio\Rendering\Dto;

/**
 * Result of {@see \App\Studio\Rendering\StudioRenderingFontResolver::resolveForTextLayer()}.
 *
 * @param  array<string, mixed>  $debug
 */
final readonly class ResolvedStudioFont
{
    /**
     * @param  array<string, mixed>  $debug
     */
    public function __construct(
        public string $absolutePath,
        /** explicit_path | tenant_asset | family_map | default | font_key_* | legacy_bundled */
        public string $source,
        public bool $hadExplicitCustomFontSelection,
        public array $debug = [],
        public ?string $resolvedFontKey = null,
    ) {}
}
