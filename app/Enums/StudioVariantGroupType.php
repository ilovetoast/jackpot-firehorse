<?php

namespace App\Enums;

/**
 * Family type for {@see \App\Models\StudioVariantGroup} — not the same as composition revision history.
 */
enum StudioVariantGroupType: string
{
    case Color = 'color';
    case LayoutSize = 'layout_size';
    case Generic = 'generic';

    /** Reserved for a future motion/animation family without schema churn. */
    case Motion = 'motion';
}
