<?php

namespace App\Assets\Metadata\Extractors;

use App\Assets\Metadata\Extractors\Contracts\EmbeddedMetadataExtractor;
use Imagick;

class ImageMetadataExtractor implements EmbeddedMetadataExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return str_starts_with($mimeType, 'image/')
            && ! in_array($mimeType, ['image/svg+xml', 'image/x-icon'], true);
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $localPath, string $mimeType, string $extension): array
    {
        $buckets = [
            'exif' => [],
            'iptc' => [],
            'xmp' => [],
            'image' => [],
            'other' => [],
        ];

        $ext = strtolower($extension);

        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff', 'cr2', 'heic', 'heif'], true)) {
            $exif = @exif_read_data($localPath, null, true, false);
            if (is_array($exif)) {
                $buckets['exif'] = $this->flattenExif($exif);
            }
        }

        if (in_array($ext, ['jpg', 'jpeg', 'jpe'], true)) {
            $iptc = $this->extractIptcFromJpeg($localPath);
            if ($iptc !== []) {
                $buckets['iptc'] = $iptc;
            }
        }

        try {
            $imagick = new Imagick($localPath);
            $profile = $imagick->getImageProperty('icc:description')
                ?? $imagick->getImageProperty('icc:name');
            if ($profile) {
                $buckets['image']['ColorProfile'] = $profile;
            }
            $format = $imagick->getImageFormat();
            if ($format) {
                $buckets['image']['MagickFormat'] = $format;
            }
            $w = $imagick->getImageWidth();
            $h = $imagick->getImageHeight();
            if ($w > 0 && $h > 0) {
                $buckets['image']['PixelWidth'] = $w;
                $buckets['image']['PixelHeight'] = $h;
            }
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable) {
            // Non-fatal; EXIF/IPTC may still be present
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function flattenExif(array $raw): array
    {
        $flat = [];
        foreach (['IFD0', 'EXIF', 'COMPUTED'] as $section) {
            if (empty($raw[$section]) || ! is_array($raw[$section])) {
                continue;
            }
            foreach ($raw[$section] as $k => $v) {
                if (! isset($flat[$k])) {
                    $flat[$k] = $v;
                }
            }
        }
        foreach ($raw as $k => $v) {
            if (in_array($k, ['IFD0', 'EXIF', 'COMPUTED', 'THUMBNAIL'], true)) {
                continue;
            }
            if (is_array($v) && ! array_is_list($v)) {
                foreach ($v as $sk => $sv) {
                    if (! isset($flat[$sk])) {
                        $flat[$sk] = $sv;
                    }
                }

                continue;
            }
            if (! isset($flat[$k])) {
                $flat[$k] = $v;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractIptcFromJpeg(string $localPath): array
    {
        $sizeInfo = [];
        if (! @getimagesize($localPath, $sizeInfo)) {
            return [];
        }
        if (empty($sizeInfo['APP13'])) {
            return [];
        }
        if (! function_exists('iptcparse')) {
            return [];
        }
        $iptc = @iptcparse($sizeInfo['APP13']);
        if (! is_array($iptc)) {
            return [];
        }

        $map = [
            '2#025' => 'Keywords',
            '2#080' => 'Byline',
            '2#116' => 'CopyrightNotice',
        ];

        $out = [];
        foreach ($iptc as $code => $values) {
            $label = $map[$code] ?? null;
            if ($label === null) {
                continue;
            }
            if ($label === 'Keywords') {
                $out[$label] = array_values(array_filter(array_map('strval', is_array($values) ? $values : [$values])));
            } else {
                $out[$label] = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            }
        }

        return $out;
    }
}
