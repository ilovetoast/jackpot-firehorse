<?php

namespace App\Enums;

/**
 * Phase T-1: Processor used for derivative generation.
 */
enum DerivativeProcessor: string
{
    case FFMPEG = 'ffmpeg';
    case IMAGEMAGICK = 'imagemagick';
    case SHARP = 'sharp';
    case GD = 'gd'; // PHP GD library (images)
    case THUMBNAIL_GENERATOR = 'thumbnail_generator';
    case UNKNOWN = 'unknown';
}
