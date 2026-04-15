<?php

namespace App\Enums;

/**
 * Cost tier for PDF Brand Intelligence vision passes (page rasters).
 */
enum PdfBrandIntelligenceScanMode: string
{
    /** Default: one PDF page raster for creative/vision (upload / background jobs). */
    case Standard = 'standard';

    /** Up to three pages via deterministic selection (explicit user/admin action). */
    case Deep = 'deep';

    public function maxPdfPagesForSelection(): int
    {
        return $this === self::Deep
            ? 3
            : 1;
    }
}
