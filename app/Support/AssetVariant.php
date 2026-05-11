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
    case VIDEO_POSTER = 'video_poster';
    case PDF_PAGE = 'pdf_page';
    /**
     * Phase 3: Audio waveform PNG strip rendered by FFmpeg in
     * AudioWaveformService. Stored alongside the asset in S3 and
     * referenced from `metadata.audio.waveform_path`.
     */
    case AUDIO_WAVEFORM = 'audio_waveform';

    /**
     * Browser-friendly MP3 derivative produced by
     * {@see \App\Services\Audio\AudioPlaybackOptimizationService} when the
     * source is uncompressed (WAV), lossless (FLAC), or above the size
     * threshold. Referenced from `metadata.audio.web_playback_path`.
     * Frontends prefer this over the original for streaming playback.
     */
    case AUDIO_WEB = 'audio_web';

    /**
     * Whether this variant requires options (e.g. page number for PDF_PAGE).
     */
    public function requiresOptions(): bool
    {
        return $this === self::PDF_PAGE;
    }
}
