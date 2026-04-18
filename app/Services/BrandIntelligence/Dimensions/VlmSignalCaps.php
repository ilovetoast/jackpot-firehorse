<?php

namespace App\Services\BrandIntelligence\Dimensions;

/**
 * Hard caps for VLM-derived dimension results.
 *
 * Any dimension whose score/confidence was produced primarily from VLM visual
 * analysis must clamp to these values. They prevent correlated VLM outputs
 * from ever producing a false "aligned" status or over-confident number.
 */
final class VlmSignalCaps
{
    // Typography from VLM type_classification alone.
    public const TYPE_CLASSIFICATION_MAX_SCORE = 0.60;

    public const TYPE_CLASSIFICATION_MAX_CONFIDENCE = 0.45;

    // Visual style from VLM visual_style[] tag overlap.
    public const STYLE_TAG_MAX_SCORE = 0.55;

    public const STYLE_TAG_MAX_CONFIDENCE = 0.40;

    // Color fallback when asset has no extracted palette but VLM sees colors.
    public const COLOR_VLM_FALLBACK_MAX_SCORE = 0.75;

    public const COLOR_VLM_FALLBACK_MAX_CONFIDENCE = 0.60;

    // Additive augmentation when the VLM confirms a brand-palette color on the asset.
    public const COLOR_VLM_AUGMENT_SCORE_BONUS = 0.05;

    public const COLOR_VLM_AUGMENT_CONFIDENCE_BONUS = 0.10;

    public const COLOR_VLM_AUGMENT_FINAL_SCORE_CEILING = 0.97;

    public const COLOR_VLM_AUGMENT_FINAL_CONFIDENCE_CEILING = 0.92;

    public const COLOR_VLM_AUGMENT_DELTA_E_THRESHOLD = 25.0;

    // Context fit upgraded from unclassified via VLM context_type.
    public const CONTEXT_VLM_MAX_SCORE = 0.60;

    public const CONTEXT_VLM_MAX_CONFIDENCE = 0.40;
}
