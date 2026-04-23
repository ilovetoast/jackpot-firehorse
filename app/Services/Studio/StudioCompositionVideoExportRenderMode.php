<?php

namespace App\Services\Studio;

/**
 * Composition MP4 export strategies.
 *
 * {@see self::LEGACY_BITMAP} — FFmpeg composites base video + raster overlays only (current production behavior).
 * {@see self::CANVAS_RUNTIME} — headless browser renders the composition scene graph, then FFmpeg encodes/composites.
 * {@see self::FFMPEG_NATIVE} — server-side FFmpeg composition from normalized timeline/layer data (no browser).
 */
enum StudioCompositionVideoExportRenderMode: string
{
    case LEGACY_BITMAP = 'legacy_bitmap';

    case CANVAS_RUNTIME = 'canvas_runtime';

    case FFMPEG_NATIVE = 'ffmpeg_native';
}
