<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Phase-1 heuristics: which videos should receive a full-length H.264/AAC MP4
 * {@see \App\Support\AssetVariant::VIDEO_WEB} derivative on the video-heavy queue.
 */
final class VideoWebPlaybackOptimizationService
{
    /**
     * @return array{
     *     should_generate: bool,
     *     strategy: 'transcode'|'native_skipped',
     *     reason: string,
     *     extension: string|null,
     *     source_mime: string|null
     * }
     */
    public function decide(Asset $asset): array
    {
        $mime = strtolower(trim((string) ($asset->mime_type ?? '')));
        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        if ($ext === '' && $asset->file_extension) {
            $ext = strtolower(ltrim((string) $asset->file_extension, '.'));
        }

        if (! (bool) config('assets.video.web_playback.enabled', false)) {
            return [
                'should_generate' => false,
                'strategy' => 'native_skipped',
                'reason' => 'feature_disabled',
                'extension' => $ext !== '' ? $ext : null,
                'source_mime' => $mime !== '' ? $mime : null,
            ];
        }

        if ($mime !== '' && ! str_starts_with($mime, 'video/')) {
            return [
                'should_generate' => false,
                'strategy' => 'native_skipped',
                'reason' => 'not_video',
                'extension' => $ext !== '' ? $ext : null,
                'source_mime' => $mime,
            ];
        }

        $force = config('assets.video.web_playback.force_extensions', []);
        $force = is_array($force) ? array_map('strtolower', $force) : [];

        if ($ext !== '' && in_array($ext, $force, true)) {
            return [
                'should_generate' => true,
                'strategy' => 'transcode',
                'reason' => 'forced_extension',
                'extension' => $ext,
                'source_mime' => $mime !== '' ? $mime : null,
            ];
        }

        return [
            'should_generate' => false,
            'strategy' => 'native_skipped',
            'reason' => 'likely_browser_safe_or_not_forced',
            'extension' => $ext !== '' ? $ext : null,
            'source_mime' => $mime !== '' ? $mime : null,
        ];
    }

    /**
     * When true, {@see ProcessAssetJob} omits {@see GenerateVideoPreviewJob} from the main chain so the
     * hover clip is not decoded from the risky original first; {@see GenerateVideoWebPlaybackJob} dispatches
     * preview generation from the VIDEO_WEB MP4 after a successful transcode (see metadata.video.preview_*).
     */
    public function shouldDeferHoverPreviewUntilVideoWeb(Asset $asset): bool
    {
        return ($this->decide($asset)['should_generate'] ?? false) === true;
    }
}
