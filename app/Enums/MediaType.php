<?php

namespace App\Enums;

/**
 * Broad media type bucket for category-aware scoring weights.
 */
enum MediaType: string
{
    case IMAGE = 'image';
    case PDF = 'pdf';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case OTHER = 'other';

    public static function fromMime(?string $mime): self
    {
        $mime = strtolower(trim((string) $mime));

        if ($mime === '' || $mime === 'application/octet-stream') {
            return self::OTHER;
        }
        if (str_starts_with($mime, 'image/')) {
            return self::IMAGE;
        }
        if ($mime === 'application/pdf' || str_contains($mime, 'pdf')) {
            return self::PDF;
        }
        if (str_starts_with($mime, 'video/')) {
            return self::VIDEO;
        }
        if (str_starts_with($mime, 'audio/')) {
            return self::AUDIO;
        }
        if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint') || str_contains($mime, 'slide')) {
            return self::PDF;
        }

        return self::OTHER;
    }
}
