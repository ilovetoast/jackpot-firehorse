<?php

namespace App\Support;

/**
 * Asset delivery variant enum.
 *
 * Extensible preview variants for unified asset delivery.
 * Used by AssetVariantPathResolver and AssetDeliveryService.
 */
enum AssetVariant: string
{
    case ORIGINAL = 'original';
    case THUMB_SMALL = 'thumbnail_small';
    case THUMB_MEDIUM = 'thumbnail_medium';
    case THUMB_LARGE = 'thumbnail_large';
    case THUMB_PREVIEW = 'thumbnail_preview'; // LQIP during processing
    case VIDEO_PREVIEW = 'video_preview';
    case PDF_PAGE = 'pdf_page';

    /**
     * Whether this variant requires options (e.g. page number for PDF_PAGE).
     */
    public function requiresOptions(): bool
    {
        return $this === self::PDF_PAGE;
    }
}
