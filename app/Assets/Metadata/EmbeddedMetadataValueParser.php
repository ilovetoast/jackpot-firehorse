<?php

namespace App\Assets\Metadata;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class EmbeddedMetadataValueParser
{
    public static function parseDatetime(mixed $value, ?string $hint = null): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::createFromTimestamp((int) $value);
            } catch (\Throwable) {
                return null;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($hint === 'pdf' || str_starts_with($value, 'D:')) {
            $parsed = self::parsePdfDate($value);
            if ($parsed) {
                return $parsed;
            }
        }

        $exif = self::parseExifDateTime($value);
        if ($exif) {
            return $exif;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function parseExifDateTime(string $value): ?CarbonInterface
    {
        $value = trim($value);
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $value, $m)) {
            try {
                return Carbon::createFromFormat('Y:m:d H:i:s', "{$m[1]}:{$m[2]}:{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * PDF date format: D:YYYYMMDDHHmmSSOHH'mm' or variants.
     */
    public static function parsePdfDate(string $value): ?CarbonInterface
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, 'D:')) {
            $value = substr($value, 2);
        }
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (strlen($digits) < 8) {
            return null;
        }
        $y = (int) substr($digits, 0, 4);
        $mo = (int) substr($digits, 4, 2);
        $d = (int) substr($digits, 6, 2);
        $h = strlen($digits) >= 10 ? (int) substr($digits, 8, 2) : 0;
        $mi = strlen($digits) >= 12 ? (int) substr($digits, 10, 2) : 0;
        $s = strlen($digits) >= 14 ? (int) substr($digits, 12, 2) : 0;

        try {
            return Carbon::create($y, $mo, $d, $h, $mi, $s);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $first = reset($value);

            return is_numeric($first) ? (float) $first : null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            if (preg_match('/^([\d.]+)/', $value, $m)) {
                return (float) $m[1];
            }
        }

        return null;
    }
}
