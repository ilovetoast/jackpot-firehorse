<?php

namespace App\Support;

/**
 * FFprobe stream metadata: coded size vs display size for rotation-tagged video (common on phones).
 *
 * Coded WxH from the video stream are often landscape (e.g. 1920×1080) while display_matrix / rotate
 * indicates the player should show portrait (1080×1920). Preview generation must transpose before scale
 * or the hover MP4 will be the wrong aspect ratio.
 */
final class VideoDisplayProbe
{
    /**
     * Normalize rotation to 0, 90, 180, or 270 (degrees clockwise from coded frame to intended display).
     */
    public static function normalizeRotationDegrees(int $deg): int
    {
        $d = $deg % 360;
        if ($d < 0) {
            $d += 360;
        }

        return match (true) {
            $d >= 315 || $d < 45 => 0,
            $d < 135 => 90,
            $d < 225 => 180,
            default => 270,
        };
    }

    /**
     * Rotation derived from stream tags (legacy rotate=) or Display Matrix side data.
     */
    public static function rotationFromVideoStream(array $videoStream): int
    {
        $tags = $videoStream['tags'] ?? [];
        if (isset($tags['rotate']) && $tags['rotate'] !== '') {
            return self::normalizeRotationDegrees((int) round((float) $tags['rotate']));
        }

        foreach ($videoStream['side_data_list'] ?? [] as $sd) {
            $type = (string) ($sd['side_data_type'] ?? '');
            if ($type === 'Display Matrix' && isset($sd['rotation'])) {
                return self::normalizeRotationDegrees((int) round((float) $sd['rotation']));
            }
        }

        return 0;
    }

    /**
     * @return array{width:int,height:int,display_width:int,display_height:int,rotation:int}
     */
    public static function dimensionsFromStream(array $videoStream): array
    {
        $w = (int) ($videoStream['width'] ?? 0);
        $h = (int) ($videoStream['height'] ?? 0);
        $rotation = self::rotationFromVideoStream($videoStream);

        $dw = $w;
        $dh = $h;
        if (in_array($rotation, [90, 270], true)) {
            $dw = $h;
            $dh = $w;
        }

        return [
            'width' => $w,
            'height' => $h,
            'display_width' => $dw,
            'display_height' => $dh,
            'rotation' => $rotation,
        ];
    }

    /**
     * FFmpeg filters to bake rotation into pixels before scale (empty string if none).
     * transpose=1: 90° clockwise; transpose=2: 90° counter-clockwise.
     */
    public static function ffmpegTransposeFilters(int $rotation): string
    {
        return match ($rotation) {
            90 => 'transpose=1',
            270 => 'transpose=2',
            180 => 'transpose=1,transpose=1',
            default => '',
        };
    }
}
