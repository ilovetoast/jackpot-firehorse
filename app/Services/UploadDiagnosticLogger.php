<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Support\Facades\Log;

/**
 * Upload Diagnostic Logger
 *
 * Temporary diagnostic logging for upload pipeline debugging.
 * Logs asset state, version info, thumbnails, metadata, status/lifecycle at key pipeline points.
 * Use: grep "UPLOAD_DIAG" storage/logs/laravel.log
 *
 * REMOVE after debugging upload issues.
 */
class UploadDiagnosticLogger
{
    public const PREFIX = '[UPLOAD_DIAG]';

    public static function assetSnapshot(Asset $asset, string $context, array $extra = []): void
    {
        $version = $asset->currentVersion;
        $meta = $asset->metadata ?? [];
        if ($version) {
            $versionMeta = $version->metadata ?? [];
        } else {
            $versionMeta = [];
        }

        $payload = array_merge([
            'context' => $context,
            'asset_id' => $asset->id,
            'filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'status' => $asset->status?->value ?? $asset->status,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? $asset->thumbnail_status,
            'analysis_status' => $asset->analysis_status ?? 'null',
            'published_at' => $asset->published_at?->toIso8601String() ?? 'null',
            'approval_status' => $asset->approval_status?->value ?? $asset->approval_status,
            'category_id' => $meta['category_id'] ?? 'null',
            'metadata_extracted' => $meta['metadata_extracted'] ?? false,
            'thumbnails_generated' => $meta['thumbnails_generated'] ?? false,
            'preview_generated' => $meta['preview_generated'] ?? false,
            'preview_skipped' => $meta['preview_skipped'] ?? false,
            'preview_skipped_reason' => $meta['preview_skipped_reason'] ?? null,
            'version_id' => $version?->id,
            'version_pipeline_status' => $version?->pipeline_status,
            'version_file_path' => $version?->file_path,
            'thumbnail_styles' => array_keys($meta['thumbnails'] ?? []),
        ], $extra);

        Log::info(self::PREFIX . ' ' . $context, $payload);
    }

    public static function jobStart(string $jobClass, string $assetId, ?string $versionId = null, array $extra = []): void
    {
        $asset = Asset::find($assetId);
        if (!$asset) {
            Log::warning(self::PREFIX . " {$jobClass} asset not found", ['asset_id' => $assetId]);
            return;
        }
        self::assetSnapshot($asset, "{$jobClass} START", array_merge(
            ['version_id' => $versionId],
            $extra
        ));
    }

    public static function jobComplete(string $jobClass, string $assetId, array $extra = []): void
    {
        $asset = Asset::find($assetId);
        if (!$asset) {
            Log::warning(self::PREFIX . " {$jobClass} asset not found", ['asset_id' => $assetId]);
            return;
        }
        self::assetSnapshot($asset, "{$jobClass} COMPLETE", $extra);
    }

    public static function jobSkip(string $jobClass, string $assetId, string $reason, array $extra = []): void
    {
        Log::info(self::PREFIX . " {$jobClass} SKIP", array_merge([
            'asset_id' => $assetId,
            'reason' => $reason,
        ], $extra));
    }

    public static function jobFail(string $jobClass, string $assetId, string $error, array $extra = []): void
    {
        Log::error(self::PREFIX . " {$jobClass} FAIL", array_merge([
            'asset_id' => $assetId,
            'error' => $error,
        ], $extra));
    }

    public static function statusChange(string $assetId, string $field, $from, $to, string $source = ''): void
    {
        Log::info(self::PREFIX . " STATUS CHANGE", [
            'asset_id' => $assetId,
            'field' => $field,
            'from' => $from,
            'to' => $to,
            'source' => $source,
        ]);
    }
}
