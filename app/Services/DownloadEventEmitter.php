<?php

namespace App\Services;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadType;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Download;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 * - download_type (snapshot / living)
 * - source (grid / drawer / collection / public / admin)
 * - file_type (for single asset downloads)
 * - size_bytes (if known)
 * - context (zip / single)
 */
class DownloadEventEmitter
{
    /**
     * Emit download group created event.
     * 
     * @param Download $download
     * @return void
     */
    public static function emitDownloadGroupCreated(Download $download): void
    {
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_GROUP_CREATED,
                subject: $download,
                actor: auth()->user(),
                metadata: self::buildDownloadMetadata($download)
            );

            // Also emit structured log for AI agents
            Log::info('[DownloadEventEmitter] Download group created', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'user_id' => auth()->id(),
                'download_type' => $download->download_type->value,
                'source' => $download->source->value,
                'access_mode' => $download->access_mode->value,
            ]);
        } catch (\Throwable $e) {
            // Event emission must not break download creation
            Log::warning('[DownloadEventEmitter] Failed to emit download_group_created event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download group ready event.
     * 
     * @param Download $download
     * @return void
     */
    public static function emitDownloadGroupReady(Download $download): void
    {
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_GROUP_READY,
                subject: $download,
                actor: 'system',
                metadata: self::buildDownloadMetadata($download)
            );
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit download_group_ready event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download ZIP requested event.
     * 
     * @param Download $download
     * @return void
     */
    public static function emitDownloadZipRequested(Download $download): void
    {
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_ZIP_REQUESTED,
                subject: $download,
                actor: auth()->user(),
                metadata: array_merge(
                    self::buildDownloadMetadata($download),
                    [
                        'context' => 'zip',
                        'zip_path' => $download->zip_path,
                        'zip_size_bytes' => $download->zip_size_bytes,
                    ]
                )
            );

            // Structured log for analytics
            Log::info('[DownloadEventEmitter] Download ZIP requested', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'user_id' => auth()->id(),
                'download_type' => $download->download_type->value,
                'source' => $download->source->value,
                'context' => 'zip',
                'zip_size_bytes' => $download->zip_size_bytes,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit download_zip_requested event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download ZIP completed event (best-effort).
     * 
     * This is best-effort because we can't reliably track when a user
     * finishes downloading a ZIP file from S3.
     * 
     * @param Download $download
     * @return void
     */
    public static function emitDownloadZipCompleted(Download $download): void
    {
        // Best-effort: We emit when signed URL is generated
        // Actual completion would require S3 access logs or client-side tracking
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_ZIP_COMPLETED,
                subject: $download,
                actor: auth()->user(),
                metadata: array_merge(
                    self::buildDownloadMetadata($download),
                    [
                        'context' => 'zip',
                        'zip_size_bytes' => $download->zip_size_bytes,
                    ]
                )
            );
        } catch (\Throwable $e) {
            // Best-effort: Don't break download flow
            Log::debug('[DownloadEventEmitter] Failed to emit download_zip_completed event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download ZIP build failed event.
     * 
     * @param Download $download
     * @param string|null $error
     * @return void
     */
    public static function emitDownloadZipFailed(Download $download, ?string $error = null): void
    {
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_ZIP_FAILED,
                subject: $download,
                actor: 'system',
                metadata: array_merge(
                    self::buildDownloadMetadata($download),
                    [
                        'error' => $error,
                    ]
                )
            );

            Log::error('[DownloadEventEmitter] Download ZIP build failed', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'download_type' => $download->download_type->value,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit download_zip_failed event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download ZIP build success event.
     * 
     * @param Download $download
     * @param int $zipSizeBytes
     * @return void
     */
    public static function emitDownloadZipBuildSuccess(Download $download, int $zipSizeBytes): void
    {
        try {
            // Use existing ZIP_GENERATED event for ZIP build success
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::ZIP_GENERATED,
                subject: $download,
                actor: 'system',
                metadata: array_merge(
                    self::buildDownloadMetadata($download),
                    [
                        'zip_size_bytes' => $zipSizeBytes,
                        'zip_path' => $download->zip_path,
                    ]
                )
            );
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit download_zip_build_success event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit download group invalidated event.
     * 
     * @param Download $download
     * @return void
     */
    public static function emitDownloadGroupInvalidated(Download $download): void
    {
        try {
            ActivityRecorder::record(
                tenant: $download->tenant_id,
                eventType: EventType::DOWNLOAD_GROUP_INVALIDATED,
                subject: $download,
                actor: 'system',
                metadata: array_merge(
                    self::buildDownloadMetadata($download),
                    [
                        'version' => $download->version,
                        'reason' => 'asset_list_changed', // Living downloads only
                    ]
                )
            );
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit download_group_invalidated event', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit single asset download requested event.
     * 
     * @param Asset $asset
     * @return void
     */
    public static function emitAssetDownloadRequested(Asset $asset): void
    {
        try {
            ActivityRecorder::record(
                tenant: $asset->tenant_id,
                eventType: EventType::ASSET_DOWNLOAD_CREATED,
                subject: $asset,
                actor: auth()->user(),
                metadata: [
                    'context' => 'single',
                    'file_type' => $asset->type ?? null,
                    'size_bytes' => $asset->size_bytes ?? null,
                ]
            );

            // Structured log for analytics
            Log::info('[DownloadEventEmitter] Asset download requested', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'user_id' => auth()->id(),
                'context' => 'single',
                'file_type' => $asset->type ?? null,
                'size_bytes' => $asset->size_bytes ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DownloadEventEmitter] Failed to emit asset_download_requested event', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build standard download metadata payload.
     * 
     * @param Download $download
     * @return array
     */
    protected static function buildDownloadMetadata(Download $download): array
    {
        return [
            'download_type' => $download->download_type->value,
            'source' => $download->source->value,
            'access_mode' => $download->access_mode->value,
            'version' => $download->version,
        ];
    }
}
