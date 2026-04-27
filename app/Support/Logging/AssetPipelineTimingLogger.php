<?php

namespace App\Support\Logging;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight per-asset pipeline transition logger.
 *
 * Always on. Emits a single Log::info('[asset_pipeline_timing]', ...) row at
 * each major transition (asset stored, thumbnail dispatched/started/completed,
 * preview completed, metadata completed) with asset_id, asset_version_id,
 * event name, and ms_since_processing_marked when available.
 *
 * Distinct from {@see PipelineStepTimer}, which is gated behind
 * ASSET_PIPELINE_LOG_STEP_TIMINGS for granular per-step diagnostics.
 *
 * Grep: [asset_pipeline_timing]
 *
 * Privacy: never logs signed URLs, S3 keys, or original_filename. Caller is
 * responsible for any extra context being safe; we do not interpolate file
 * paths here.
 */
class AssetPipelineTimingLogger
{
    public const EVENT_ORIGINAL_STORED = 'original_stored';
    public const EVENT_THUMBNAIL_DISPATCHED = 'thumbnail_dispatched';
    public const EVENT_THUMBNAIL_STARTED = 'thumbnail_started';
    public const EVENT_THUMBNAIL_COMPLETED = 'thumbnail_completed';
    public const EVENT_PREVIEW_COMPLETED = 'preview_completed';
    public const EVENT_METADATA_COMPLETED = 'metadata_completed';
    public const EVENT_AI_CHAIN_DISPATCHED = 'ai_chain_dispatched';

    /**
     * @param  array<string, mixed>  $extra  small, non-sensitive context (queue name, counts, status, etc.)
     */
    public static function record(
        string $event,
        ?Asset $asset = null,
        ?AssetVersion $version = null,
        array $extra = []
    ): void {
        try {
            $assetId = $asset?->id ?? $version?->asset_id;
            $versionId = $version?->id;
            if (! $versionId && $asset) {
                $versionId = $asset->current_asset_version_id ?? null;
            }

            $row = array_merge(
                self::sanitize($extra),
                self::msSinceProcessingMarked($asset, $version),
                [
                    'event' => $event,
                    'asset_id' => $assetId,
                    'asset_version_id' => $versionId,
                    'ts' => Carbon::now()->toIso8601String(),
                ]
            );

            Log::info('[asset_pipeline_timing]', $row);
        } catch (\Throwable $e) {
            // Logging must never break the pipeline.
        }
    }

    /**
     * @return array{ms_since_processing_marked?: int}
     */
    protected static function msSinceProcessingMarked(?Asset $asset, ?AssetVersion $version): array
    {
        return PipelineStepTimer::wallClockSinceProcessingMarked($asset, $version);
    }

    /**
     * Drop fields that look like file paths, URLs, or signed URLs to keep
     * structured logs free of sensitive references.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function sanitize(array $extra): array
    {
        $denyKeys = [
            'url', 'signed_url', 'path', 'file_path', 'storage_path',
            'storage_root_path', 'source_path', 'thumbnail_url',
        ];

        foreach ($denyKeys as $k) {
            if (array_key_exists($k, $extra)) {
                unset($extra[$k]);
            }
        }

        return $extra;
    }
}
