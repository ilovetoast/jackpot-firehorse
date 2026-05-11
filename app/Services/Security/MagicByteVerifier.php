<?php

namespace App\Services\Security;

/**
 * Phase 6: Defense-in-depth MIME mismatch detector.
 *
 * The upload pipeline already validates by extension + declared MIME at
 * preflight, and uses libmagic at finalize to sniff the real bytes. This
 * service adds a third gate: it confirms the sniffed signature is in the
 * known accept-list for the *declared* registry type. The motivating
 * scenario is a file claiming to be `image/jpeg` whose first bytes are
 * `<?php` or PE/MachO — libmagic reports `text/x-php` or
 * `application/x-dosexec`, but a buggy `canonicalizeSniffedMime`
 * implementation could otherwise let it through.
 *
 * Returns:
 *   - ['ok' => true]                      bytes match the declared MIME
 *   - ['ok' => false, 'reason' => 'no_signature']        too few bytes / unknown
 *   - ['ok' => false, 'reason' => 'mime_mismatch',
 *      'expected' => 'image/jpeg',
 *      'detected_signature' => 'pe_executable']          danger
 *
 * Intentionally not a replacement for FileInspectionService — it runs
 * AFTER libmagic has produced a canonical MIME. This is the "gut check"
 * that any suspicious mismatch surfaces as a hard reject.
 */
class MagicByteVerifier
{
    /**
     * @param  string  $headBytes  First N bytes of the file (recommended: 512+).
     * @param  string  $declaredMime  Canonical mime as decided by FileTypeService.
     */
    public function verify(string $headBytes, string $declaredMime): array
    {
        if (strlen($headBytes) < 4) {
            return ['ok' => false, 'reason' => 'no_signature'];
        }

        $signature = $this->detectSignature($headBytes);
        if ($signature === null) {
            // Unknown signature isn't necessarily wrong — many text formats
            // (TXT, CSV) have no magic bytes. We pass when the declared MIME
            // is one of those plain-text types, otherwise reject.
            return $this->isHeaderlessTextMime($declaredMime)
                ? ['ok' => true]
                : ['ok' => false, 'reason' => 'no_signature'];
        }

        $expected = $this->signaturesForMime($declaredMime);
        if ($expected === null) {
            // We don't have an opinion about this MIME — pass-through.
            return ['ok' => true];
        }

        if (in_array($signature, $expected, true)) {
            return ['ok' => true];
        }

        // Special-case dangerous mismatches with louder labels for the audit log.
        $dangerous = ['pe_executable', 'macho_executable', 'elf_executable', 'shell_script', 'php_script', 'java_class', 'zip_archive', 'rar_archive', 'sevenz_archive'];
        if (in_array($signature, $dangerous, true)) {
            return [
                'ok' => false,
                'reason' => 'dangerous_mismatch',
                'expected' => $declaredMime,
                'detected_signature' => $signature,
            ];
        }

        return [
            'ok' => false,
            'reason' => 'mime_mismatch',
            'expected' => $declaredMime,
            'detected_signature' => $signature,
        ];
    }

    /**
     * Detect known signatures from the head bytes. Returns a stable
     * label string (e.g. 'jpeg', 'pe_executable') or null if no match.
     */
    public function detectSignature(string $head): ?string
    {
        // Only look at the first 64 bytes for the cheap match.
        $h = substr($head, 0, 64);

        if (str_starts_with($h, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($h, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($h, 'GIF87a') || str_starts_with($h, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (str_starts_with($h, "II*\x00") || str_starts_with($h, "MM\x00*")) {
            return 'tiff';
        }
        if (str_starts_with($h, 'BM')) {
            return 'bmp';
        }
        if (str_starts_with($h, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($h, 'ID3') || (substr($h, 0, 2) === "\xFF\xFB") || (substr($h, 0, 2) === "\xFF\xF3") || (substr($h, 0, 2) === "\xFF\xF2")) {
            return 'mp3';
        }
        if (substr($h, 4, 4) === 'ftyp') {
            // ISO BMFF — MP4, MOV, M4A all share this header. Differentiate
            // via the brand string.
            $brand = substr($h, 8, 4);
            if (in_array($brand, ['M4A ', 'M4B ', 'mp42', 'mp41', 'isom', 'iso2', 'avc1', 'qt  ', 'M4V ', 'MSNV', 'dash'], true)) {
                if (str_starts_with($brand, 'M4')) {
                    return 'mp4_audio';
                }

                return 'mp4_video';
            }

            return 'mp4_video';
        }
        if (str_starts_with($h, "OggS")) {
            return 'ogg';
        }
        if (str_starts_with($h, "fLaC")) {
            return 'flac';
        }
        // SVG / HTML / generic XML — sniff up to first '<' run.
        $stripped = ltrim($h);
        if (str_starts_with($stripped, '<svg') || (str_starts_with($stripped, '<?xml') && stripos($head, '<svg') !== false)) {
            return 'svg';
        }

        // Dangerous payloads we want to surface loudly.
        if (str_starts_with($h, 'MZ')) {
            return 'pe_executable';
        }
        if (str_starts_with($h, "\x7FELF")) {
            return 'elf_executable';
        }
        if (str_starts_with($h, "\xCA\xFE\xBA\xBE") || str_starts_with($h, "\xCE\xFA\xED\xFE") || str_starts_with($h, "\xCF\xFA\xED\xFE")) {
            return 'macho_executable';
        }
        if (str_starts_with($h, '#!')) {
            return 'shell_script';
        }
        if (str_starts_with($stripped, '<?php')) {
            return 'php_script';
        }
        if (str_starts_with($h, "PK\x03\x04") || str_starts_with($h, "PK\x05\x06") || str_starts_with($h, "PK\x07\x08")) {
            return 'zip_archive';
        }
        if (str_starts_with($h, 'Rar!')) {
            return 'rar_archive';
        }
        if (str_starts_with($h, "7z\xBC\xAF\x27\x1C")) {
            return 'sevenz_archive';
        }
        if (str_starts_with($h, "\xCA\xFE\xBA\xBE")) {
            return 'java_class';
        }

        return null;
    }

    /**
     * Whitelist of (declared MIME → acceptable signature labels). When a
     * MIME is not present here, we abstain (return null) and let the
     * upstream gates own the decision; we only HARD reject when we have
     * an explicit accept-list and the detected signature isn't in it.
     *
     * @return array<int, string>|null
     */
    protected function signaturesForMime(string $mime): ?array
    {
        return match ($mime) {
            'image/jpeg' => ['jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/tiff' => ['tiff'],
            'image/bmp' => ['bmp'],
            'image/svg+xml' => ['svg'],
            'application/pdf' => ['pdf'],
            'audio/mpeg' => ['mp3'],
            'audio/mp4', 'audio/x-m4a' => ['mp4_audio'],
            'audio/wav', 'audio/x-wav' => ['wav'],
            'audio/ogg' => ['ogg'],
            'audio/flac' => ['flac'],
            'video/mp4', 'video/quicktime', 'video/x-m4v' => ['mp4_video'],
            default => null,
        };
    }

    protected function isHeaderlessTextMime(string $mime): bool
    {
        return str_starts_with($mime, 'text/')
            || $mime === 'application/json'
            || $mime === 'application/xml'
            || $mime === 'application/csv';
    }
}
