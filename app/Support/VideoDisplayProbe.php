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
     * Pick the main video track (default disposition, else largest frame area).
     * Avoids tiny cover-art or secondary streams in some MOV/MP4 files.
     *
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    public static function selectPrimaryVideoStream(array $streams): ?array
    {
        $videos = [];
        foreach ($streams as $s) {
            if (($s['codec_type'] ?? '') === 'video') {
                $videos[] = $s;
            }
        }
        if ($videos === []) {
            return null;
        }
        $isCoverStyleVideo = static function (array $s): bool {
            $name = strtolower((string) ($s['codec_name'] ?? ''));

            return in_array($name, ['mjpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'jpeg2000', 'ljpeg'], true);
        };
        usort($videos, function (array $a, array $b) use ($isCoverStyleVideo): int {
            // Prefer real movie tracks over embedded album-art / poster “video” (often default + tiny MJPEG).
            $ac = $isCoverStyleVideo($a) ? 1 : 0;
            $bc = $isCoverStyleVideo($b) ? 1 : 0;
            if ($ac !== $bc) {
                return $ac <=> $bc;
            }
            $ad = (int) (($a['disposition'] ?? [])['default'] ?? 0);
            $bd = (int) (($b['disposition'] ?? [])['default'] ?? 0);
            if ($ad !== $bd) {
                return $bd <=> $ad;
            }
            $aa = (int) ($a['width'] ?? 0) * (int) ($a['height'] ?? 0);
            $ba = (int) ($b['width'] ?? 0) * (int) ($b['height'] ?? 0);

            return $ba <=> $aa;
        });

        return $videos[0];
    }

    /**
     * Parse ffprobe sample_aspect_ratio (e.g. "1:1", "32:27", "N/A").
     *
     * @return array{0: int, 1: int} numerator, denominator
     */
    public static function parseSampleAspectRatio(?string $ratio): array
    {
        if ($ratio === null || $ratio === '' || $ratio === 'N/A' || $ratio === '0:1') {
            return [1, 1];
        }
        $parts = explode(':', $ratio, 2);
        if (count($parts) !== 2) {
            return [1, 1];
        }
        $num = (int) $parts[0];
        $den = (int) $parts[1];
        if ($num <= 0 || $den <= 0) {
            return [1, 1];
        }

        return [$num, $den];
    }

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
     * Parse ffprobe displaymatrix: JSON array of 9 ints, or multiline hex-dump string with integers.
     *
     * @return list<int>|null
     */
    public static function parseDisplayMatrixNine(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $nums = [];
            foreach (array_slice($raw, 0, 9) as $v) {
                if (! is_numeric($v)) {
                    return null;
                }
                $nums[] = (int) $v;
            }

            return count($nums) === 9 ? $nums : null;
        }
        if (is_string($raw)) {
            if (! preg_match_all('/-?\d+/', $raw, $matches)) {
                return null;
            }
            $nums = array_map(static fn (string $s): int => (int) $s, $matches[0]);
            if (count($nums) < 9) {
                return null;
            }

            return array_slice($nums, 0, 9);
        }

        return null;
    }

    /**
     * Match libavutil av_display_rotation_get(): rotation (degrees) from 16.16 fixed-point 3×3 matrix.
     *
     * @param  list<int>  $m  nine elements row-major (indices 0–1–3–4 are the 2×2 rotation/scale block FFmpeg uses)
     */
    public static function rotationDegreesFromFfmpegMatrixNine(array $m): float
    {
        if (count($m) < 9) {
            return 0.0;
        }
        $conv = static fn (int $x): float => $x / 65536.0;
        $a = $conv($m[0]);
        $b = $conv($m[1]);
        $c = $conv($m[3]);
        $d = $conv($m[4]);
        $scale0 = hypot($a, $c);
        $scale1 = hypot($b, $d);
        if ($scale0 == 0.0 || $scale1 == 0.0) {
            return 0.0;
        }
        $rad = atan2($b / $scale1, $a / $scale0);

        return -($rad * 180 / M_PI);
    }

    /**
     * Rotation derived from Display Matrix (authoritative for many MOVs), then ffprobe side_data, then tags.
     *
     * Order matters: some files carry tags.rotate=0 while the Display Matrix encodes 180° / 90°.
     * Trusting the tag first produced upside-down or wrong-aspect thumbnails and hover MP4s.
     */
    public static function rotationFromVideoStream(array $videoStream): int
    {
        $sideList = $videoStream['side_data_list'] ?? [];

        $matrixNorm = null;
        foreach ($sideList as $sd) {
            $nine = self::parseDisplayMatrixNine($sd['displaymatrix'] ?? null);
            if ($nine === null) {
                continue;
            }
            $deg = self::rotationDegreesFromFfmpegMatrixNine($nine);
            if (! is_finite($deg)) {
                continue;
            }
            $matrixNorm = self::normalizeRotationDegrees((int) round($deg));
            if ($matrixNorm !== 0) {
                return $matrixNorm;
            }
        }

        foreach ($sideList as $sd) {
            foreach (['rotation', 'display_rotation'] as $rk) {
                if (isset($sd[$rk]) && $sd[$rk] !== '' && is_numeric($sd[$rk])) {
                    return self::normalizeRotationDegrees((int) round((float) $sd[$rk]));
                }
            }
        }

        $tags = $videoStream['tags'] ?? [];
        foreach (['rotate', 'rotation', 'com.apple.quicktime.rotation', 'com.apple.rotation'] as $tk) {
            if (isset($tags[$tk]) && $tags[$tk] !== '' && is_numeric($tags[$tk])) {
                return self::normalizeRotationDegrees((int) round((float) $tags[$tk]));
            }
        }

        if ($matrixNorm !== null) {
            return $matrixNorm;
        }

        return 0;
    }

    /**
     * Rotation from container-level tags (QuickTime / some MOVs put rotate here, not on the video stream).
     */
    public static function rotationFromFormatTags(?array $format): int
    {
        if ($format === null) {
            return 0;
        }
        $tags = $format['tags'] ?? [];
        if (! is_array($tags)) {
            return 0;
        }
        foreach (['rotate', 'rotation', 'com.apple.quicktime.rotation', 'com.apple.rotation'] as $k) {
            if (isset($tags[$k]) && $tags[$k] !== '' && is_numeric($tags[$k])) {
                return self::normalizeRotationDegrees((int) round((float) $tags[$k]));
            }
        }
        foreach ($tags as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            if (preg_match('/rotate|orientation/i', $key) === 1 && is_numeric($value)) {
                return self::normalizeRotationDegrees((int) round((float) $value));
            }
        }

        return 0;
    }

    /**
     * Primary video stream dimensions + rotation using the full ffprobe JSON (stream + format tags).
     *
     * @return array{width:int,height:int,display_width:int,display_height:int,rotation:int}|null
     */
    public static function dimensionsFromFfprobe(array $videoData): ?array
    {
        $videoStream = self::selectPrimaryVideoStream($videoData['streams'] ?? []);
        if (! $videoStream) {
            return null;
        }
        $rotation = self::rotationFromVideoStream($videoStream);
        if ($rotation === 0) {
            $rotation = self::rotationFromFormatTags($videoData['format'] ?? null);
        }

        return self::dimensionsForStreamWithRotation($videoStream, $rotation);
    }

    /**
     * @return array{width:int,height:int,display_width:int,display_height:int,rotation:int}
     */
    public static function dimensionsFromStream(array $videoStream): array
    {
        return self::dimensionsForStreamWithRotation($videoStream, self::rotationFromVideoStream($videoStream));
    }

    /**
     * @return array{width:int,height:int,display_width:int,display_height:int,rotation:int}
     */
    public static function dimensionsForStreamWithRotation(array $videoStream, int $rotation): array
    {
        $w = (int) ($videoStream['width'] ?? 0);
        $h = (int) ($videoStream['height'] ?? 0);
        $rotation = self::normalizeRotationDegrees($rotation);
        [$sarNum, $sarDen] = self::parseSampleAspectRatio($videoStream['sample_aspect_ratio'] ?? null);

        // Coded frame in square pixels (horizontal SAR stretch is the common case)
        $wSq = $w > 0 ? (int) round($w * $sarNum / $sarDen) : 0;
        $hSq = $h;

        $dw = $wSq;
        $dh = $hSq;
        if (in_array($rotation, [90, 270], true)) {
            $dw = $hSq;
            $dh = $wSq;
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
     * 180° uses hflip,vflip (equivalent rotation, avoids rare double-transpose quirks).
     */
    public static function ffmpegTransposeFilters(int $rotation): string
    {
        return match ($rotation) {
            90 => 'transpose=1',
            270 => 'transpose=2',
            180 => 'hflip,vflip',
            default => '',
        };
    }
}
