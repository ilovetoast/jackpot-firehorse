<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Resolves download name templates (e.g. {{company}} {{brand}} download {{date}})
 * and sanitizes for safe filenames: spaces and special chars → hyphen, collapse/trim.
 */
class DownloadNameResolver
{
    /** Default template when none is set. */
    public const DEFAULT_TEMPLATE = '{{brand}}-download-{{date}}';

    /** Supported tokens (lowercase keys). */
    public const TOKENS = ['company', 'brand', 'date', 'datetime'];

    /**
     * Resolve a template with tenant/brand/date and return a filename-safe string.
     * Company and brand names are sanitized (spaces → hyphen) before substitution.
     */
    public function resolve(
        string $template,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Carbon $date = null
    ): string {
        $date = $date ?? now();
        $company = $tenant ? $this->sanitizeTokenValue($tenant->name) : '';
        $brandName = $brand ? $this->sanitizeTokenValue($brand->name) : '';

        $replacements = [
            '{{company}}' => $company,
            '{{brand}}' => $brandName,
            '{{date}}' => $date->format('Y-m-d'),
            '{{datetime}}' => $date->format('Y-m-d-H-i'),
        ];

        $resolved = str_replace(array_keys($replacements), array_values($replacements), $template);
        return $this->sanitizeFilename($resolved);
    }

    /**
     * Sanitize a string for use inside a token (e.g. company/brand name).
     * Spaces and special chars → hyphen; collapse multiple hyphens; trim.
     */
    public function sanitizeTokenValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '-', $value);
        $value = preg_replace('/\s+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-');
    }

    /**
     * Sanitize the full resolved string for use as a filename (no path).
     * Replaces spaces and problematic chars with hyphen; collapses; trims.
     */
    public function sanitizeFilename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'download';
        }
        $value = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '-', $value);
        $value = preg_replace('/\s+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, '-._');
        return $value !== '' ? $value : 'download';
    }

    /**
     * Validate that a template only contains allowed tokens and is reasonable length.
     * Returns null if valid, or an error message string.
     */
    public function validateTemplate(?string $template): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }
        if (strlen($template) > 500) {
            return 'Template must be at most 500 characters.';
        }
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $token = strtolower($m[1]);
                if (! in_array($token, self::TOKENS, true)) {
                    return "Unknown token: {{$m[1]}}. Use {{company}}, {{brand}}, {{date}}, or {{datetime}}.";
                }
            }
        }
        return null;
    }

    /**
     * Validate that a resolved name is safe for storage (non-empty after sanitization).
     */
    public function validateResolved(string $resolved): ?string
    {
        $sanitized = $this->sanitizeFilename($resolved);
        if ($sanitized === '' || strlen($sanitized) > 255) {
            return 'Resolved name would be empty or too long.';
        }
        return null;
    }
}
