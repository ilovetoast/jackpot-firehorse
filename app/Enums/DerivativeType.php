<?php

namespace App\Enums;

/**
 * Phase T-1: Asset derivative type classification.
 */
enum DerivativeType: string
{
    case THUMBNAIL = 'thumbnail';
    case PREVIEW = 'preview';
    case POSTER = 'poster';
    case WAVEFORM = 'waveform';
}
