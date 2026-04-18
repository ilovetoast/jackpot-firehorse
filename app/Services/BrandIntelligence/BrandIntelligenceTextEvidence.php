<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\PdfTextExtraction;
use Illuminate\Support\Facades\Schema;

/**
 * Single ordering/deduping path for PDF + OCR + creative-vision text used by identity, copy/voice, and engine haystacks.
 *
 * Order: native PDF extraction → metadata OCR-style fields → supplemental creative OCR (if not redundant).
 */
final class BrandIntelligenceTextEvidence
{
    /**
     * @return list<string> trimmed, human-readable segments (original casing preserved).
     */
    public static function orderedTextSegments(Asset $asset, ?string $supplementalCreativeOcr): array
    {
        $segments = [];
        $seenLower = [];

        $remember = function (string $t) use (&$segments, &$seenLower): void {
            $t = trim($t);
            if ($t === '') {
                return;
            }
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seenLower[$k])) {
                return;
            }
            $seenLower[$k] = true;
            $segments[] = $t;
        };

        if (Schema::hasTable('pdf_text_extractions')) {
            $ext = PdfTextExtraction::query()
                ->where('asset_id', $asset->id)
                ->orderByDesc('id')
                ->first();
            if ($ext && is_string($ext->extracted_text ?? null)) {
                $remember($ext->extracted_text);
            }
        }

        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];
        foreach (['extracted_text', 'ocr_text', 'vision_ocr', 'detected_text'] as $field) {
            $raw = $meta[$field] ?? null;
            if (is_string($raw)) {
                $remember($raw);
            }
        }

        $videoTranscript = data_get($meta, 'ai_video_insights.transcript');
        if (is_string($videoTranscript)) {
            $remember($videoTranscript);
        }

        $sup = $supplementalCreativeOcr !== null ? trim($supplementalCreativeOcr) : '';
        if ($sup !== '') {
            $acc = mb_strtolower(implode("\n", $segments), 'UTF-8');
            $lowSup = mb_strtolower($sup, 'UTF-8');
            if ($lowSup !== '' && mb_stripos($acc, $lowSup, 0, 'UTF-8') === false) {
                $remember($sup);
            }
        }

        return $segments;
    }

    public static function mergedOcrBodyLowercase(Asset $asset, ?string $supplementalCreativeOcr): string
    {
        return mb_strtolower(
            trim(implode("\n", self::orderedTextSegments($asset, $supplementalCreativeOcr))),
            'UTF-8'
        );
    }

    public static function mergedCopyVoiceRaw(Asset $asset, ?string $supplementalCreativeOcr): string
    {
        return trim(implode("\n\n", self::orderedTextSegments($asset, $supplementalCreativeOcr)));
    }
}
