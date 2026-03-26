<?php

namespace App\Assets\Metadata;

/**
 * Stable display strings for EXIF technical fields so value_string and search stay consistent.
 */
class EmbeddedMetadataTechnicalNormalizer
{
    /** @var list<string> */
    public const TECHNICAL_NORMALIZED_KEYS = ['iso', 'focal_length', 'aperture', 'exposure_time'];

    public function shouldNormalize(string $normalizedKey): bool
    {
        return in_array($normalizedKey, self::TECHNICAL_NORMALIZED_KEYS, true);
    }

    public function formatDisplay(string $normalizedKey, string $type, mixed $value): string
    {
        return match ($normalizedKey) {
            'iso' => $this->formatIso($value),
            'focal_length' => $this->formatFocalLength($value),
            'aperture' => $this->formatAperture($value),
            'exposure_time' => $this->formatExposure($value),
            default => is_scalar($value) ? (string) $value : $this->scalarString($value),
        };
    }

    protected function formatIso(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_numeric($value)) {
            return (string) (int) round((float) $value);
        }

        return trim((string) $value);
    }

    protected function formatFocalLength(mixed $value): string
    {
        $s = trim($this->scalarString($value));
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s*mm\s*$/i', '', $s) ?? $s;
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $s, $m) && (int) $m[2] > 0) {
            $mm = (float) $m[1] / (float) $m[2];
        } elseif (preg_match('/^[\d.]+$/', $s)) {
            $mm = (float) $s;
        } else {
            return $this->scalarString($value);
        }
        $rounded = round($mm, 2);
        $out = fmod($rounded, 1.0) === 0.0 ? (string) (int) $rounded : (string) $rounded;

        return $out.'mm';
    }

    protected function formatAperture(mixed $value): string
    {
        $num = EmbeddedMetadataValueParser::toNumber($value);
        if ($num !== null) {
            return 'f/'.$this->trimFloat($num);
        }
        $s = trim($this->scalarString($value));
        if (preg_match('/f\s*\/\s*([\d.]+)/i', $s, $m)) {
            return 'f/'.$m[1];
        }

        return $s;
    }

    protected function formatExposure(mixed $value): string
    {
        $s = trim($this->scalarString($value));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $s, $m) && (int) $m[2] > 0) {
            return $m[1].'/'.$m[2].'s';
        }
        if (is_numeric($s)) {
            return $this->trimFloat((float) $s).'s';
        }

        return $s;
    }

    protected function trimFloat(float $n): string
    {
        $r = round($n, 6);

        return fmod($r, 1.0) === 0.0 ? (string) (int) $r : rtrim(rtrim(sprintf('%.6f', $r), '0'), '.');
    }

    protected function scalarString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        return (string) $value;
    }
}
