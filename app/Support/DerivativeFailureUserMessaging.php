<?php

declare(strict_types=1);

namespace App\Support;

/**
 * User-facing copy and JSON shaping for thumbnail / preview failures.
 * Verbose diagnostics stay in logs, reliability DB rows, and admin tooling — not in brand-app payloads.
 */
final class DerivativeFailureUserMessaging
{
    /** @var list<string> */
    private const METADATA_KEYS_HIDDEN_FROM_WORKSPACE_JSON = [
        'thumbnail_engine_diagnostics',
        'thumbnail_engine_error_summary',
        'thumbnail_error_technical',
        'thumbnail_generation_error',
        'office_thumbnail_conversion_summary',
    ];

    /**
     * Remove internal pipeline diagnostics from `assets.metadata` when serializing to the workspace UI.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function workspaceMetadata(?array $metadata): array
    {
        if ($metadata === null || $metadata === []) {
            return [];
        }

        return array_diff_key($metadata, array_flip(self::METADATA_KEYS_HIDDEN_FROM_WORKSPACE_JSON));
    }

    /**
     * Value stored on `assets.thumbnail_error` for terminal failures (column is shown in the asset drawer).
     * Long engine stacks live in `metadata.thumbnail_error_technical` (stripped from workspace JSON).
     *
     * @param  string  $shortSummary  High-level summary without engine stacks (e.g. "Thumbnail generation failed: …").
     */
    public static function persistedThumbnailError(string $shortSummary): string
    {
        $short = trim($shortSummary);
        if ($short === '') {
            return self::genericPreviewFailed();
        }
        if (self::looksTechnical($short)) {
            return self::genericPreviewFailed();
        }

        return \Illuminate\Support\Str::limit($short, 500, '…');
    }

    public static function systemIncidentMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        $m = trim($message);
        if ($m === '') {
            return $message;
        }
        if (self::looksTechnical($m)) {
            return 'Preview generation failed. Details were recorded for support — contact your workspace admin if this continues.';
        }

        return \Illuminate\Support\Str::limit($m, 800, '…');
    }

    public static function genericPreviewFailed(): string
    {
        return 'We could not generate a preview for this file. You can still download the original.';
    }

    /**
     * Shape `assets.thumbnail_error` for workspace JSON when legacy rows still hold full engine output.
     */
    public static function workspaceThumbnailError(?string $thumbnailError): ?string
    {
        if ($thumbnailError === null || trim($thumbnailError) === '') {
            return $thumbnailError;
        }
        if (self::looksTechnical($thumbnailError)) {
            return self::genericPreviewFailed();
        }

        return \Illuminate\Support\Str::limit(trim($thumbnailError), 500, '…');
    }

    private static function looksTechnical(string $s): bool
    {
        if (strlen($s) > 1400) {
            return true;
        }
        if (preg_match('/Stack:|Signal\s+\d+|Fatal exception|libreoffice|libuno_sal|\/(?:usr|lib)\/|\.so\.|0x[0-9a-f]{8,}|[\r\n]{3,}/i', $s) === 1) {
            return true;
        }

        return substr_count($s, "\n") > 8;
    }
}
