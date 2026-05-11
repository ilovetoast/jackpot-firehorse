<?php

namespace App\Services;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Log;

/**
 * SVG sanitization for the upload pipeline.
 *
 * SVGs in the DAM are stored verbatim and served as-is from S3 / CDN,
 * which makes any unsanitized SVG a stored XSS vector — they can carry
 * <script> tags, event handlers (onload, onclick, ...), <foreignObject>
 * + iframes, JS-protocol hrefs, and external entity references.
 *
 * This service wraps `enshrined/svg-sanitize` (allowlist-based, used
 * by WordPress core) with the conservative settings appropriate for a
 * DAM:
 *   - Remove remote references (no fetched fonts / images / DTDs).
 *   - Reject the file on parse failure (do NOT silently re-emit junk).
 *   - Keep XML issues for the audit log so we can spot abuse trends.
 *
 * Usage:
 *
 *   $clean = $svc->sanitize($dirtyXml);            // returns string|null
 *   if ($clean === null) { ... reject ... }
 *
 *   $changed = $svc->sanitizeFile($absLocalPath);  // bool
 */
class SvgSanitizationService
{
    private Sanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new Sanitizer;
        // Strips xlink:href / href values that point at remote URLs (http/https/ftp/...).
        $this->sanitizer->removeRemoteReferences(true);
        // Drop the XML processing-instruction declaration on output (matches what most DAMs serve).
        $this->sanitizer->removeXMLTag(true);
        // Keep nesting bounded to defeat billion-laughs / quadratic-blowup payloads.
        $this->sanitizer->setUseNestingLimit(50);
    }

    /**
     * Sanitize an SVG XML string.
     *
     * Returns the cleaned XML on success, or null if the input was unparseable
     * or the sanitizer rejected it outright.
     */
    public function sanitize(string $xml): ?string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return null;
        }

        // Reject obviously-invalid input early (the Sanitizer would otherwise
        // return `false` and we want a clean code path).
        if (stripos($xml, '<svg') === false) {
            return null;
        }

        $clean = $this->sanitizer->sanitize($xml);
        if ($clean === false || $clean === '' || stripos($clean, '<svg') === false) {
            return null;
        }

        return $clean;
    }

    /**
     * Read an SVG from a local path, sanitize, and rewrite in place.
     *
     * Returns true on success (whether or not bytes changed), false if the
     * file is unreadable or rejected by the sanitizer.
     */
    public function sanitizeFile(string $absolutePath): bool
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            Log::warning('svg_sanitization_failed', [
                'reason' => 'unreadable',
                'path' => $absolutePath,
            ]);

            return false;
        }

        $original = file_get_contents($absolutePath);
        if ($original === false) {
            return false;
        }

        $clean = $this->sanitize($original);
        if ($clean === null) {
            Log::warning('svg_sanitization_failed', [
                'reason' => 'rejected',
                'path' => $absolutePath,
                'xml_issues' => $this->sanitizer->getXmlIssues(),
            ]);

            return false;
        }

        if ($clean === $original) {
            return true;
        }

        $bytes = file_put_contents($absolutePath, $clean);

        return $bytes !== false;
    }

    /**
     * Returns a list of structured XML issues from the most recent sanitize() call.
     * Useful for the audit log and for surfacing actionable error context.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lastIssues(): array
    {
        $issues = $this->sanitizer->getXmlIssues();

        return is_array($issues) ? $issues : [];
    }
}
