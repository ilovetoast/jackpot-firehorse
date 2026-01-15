<?php

namespace App\Http\Controllers;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\ZipStatus;
use App\Models\Download;
use App\Services\DownloadEventEmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 */
class DownloadController extends Controller
{
    /**
     * Download a ZIP file from a download group.
     * 
     * GET /downloads/{download}/download
     * 
     * Responsibilities:
     * - Validate download exists and is READY
     * - Validate access (public link OR authenticated user with permission)
     * - Generate short-lived S3 signed URL (5-15 minutes)
     * - Redirect user to S3 URL (do NOT proxy file)
     * - Update last_accessed_at (if desired)
     * - Do NOT increment analytics yet (log intent only)
     * 
     * @param Download $download
     * @return RedirectResponse|JsonResponse
     */
    public function download(Download $download): RedirectResponse|JsonResponse
    {
        // Verify download exists and is accessible
        if ($download->trashed()) {
            return response()->json([
                'message' => 'Download not found',
            ], 404);
        }

        // For non-public downloads, verify tenant scope
        // Public downloads can be accessed across tenants (shared links)
        $tenant = app('tenant');
        if ($download->access_mode !== DownloadAccessMode::PUBLIC) {
            // Team/restricted downloads must match tenant
            if (!$tenant || $download->tenant_id !== $tenant->id) {
                return response()->json([
                    'message' => 'Download not found',
                ], 404);
            }
        }

        // Validate download status
        if ($download->status !== DownloadStatus::READY) {
            return response()->json([
                'message' => $this->getStatusErrorMessage($download->status),
            ], 422);
        }

        // Validate ZIP status
        if ($download->zip_status !== ZipStatus::READY) {
            return response()->json([
                'message' => $this->getZipStatusErrorMessage($download->zip_status),
            ], 422);
        }

        // Validate ZIP path exists
        if (!$download->zip_path) {
            Log::error('[DownloadController] Download ZIP path is missing', [
                'download_id' => $download->id,
            ]);
            return response()->json([
                'message' => 'ZIP file not available',
            ], 404);
        }

        // Validate access
        $accessAllowed = $this->validateAccess($download);
        if (!$accessAllowed) {
            return response()->json([
                'message' => 'Access denied',
            ], 403);
        }

        // Check if download is expired (Phase 2.8: also check if assets are archived)
        if ($download->isExpired()) {
            return response()->json([
                'message' => 'This download has expired',
            ], 410);
        }

        try {
            // Generate signed S3 URL (short-lived: 10 minutes)
            $disk = Storage::disk('s3');
            $signedUrl = $disk->temporaryUrl(
                $download->zip_path,
                now()->addMinutes(10)
            );

            // Phase 3.1 Step 5: Emit download ZIP requested event
            DownloadEventEmitter::emitDownloadZipRequested($download);

            // Best-effort: Emit completed event (we can't track actual completion from S3)
            DownloadEventEmitter::emitDownloadZipCompleted($download);

            // TODO: Update last_accessed_at field if it exists in schema
            // $download->update(['last_accessed_at' => now()]);

            // Redirect to signed URL
            return redirect($signedUrl);
        } catch (\Exception $e) {
            Log::error('[DownloadController] Failed to generate download URL', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate download URL',
            ], 500);
        }
    }

    /**
     * Validate access to download based on access_mode.
     * 
     * @param Download $download
     * @return bool True if access is allowed
     */
    protected function validateAccess(Download $download): bool
    {
        $user = auth()->user();

        switch ($download->access_mode) {
            case DownloadAccessMode::PUBLIC:
                // Public access - anyone with the link can access
                return true;

            case DownloadAccessMode::TEAM:
                // Team access - only authenticated users who are members of the tenant
                if (!$user) {
                    return false;
                }

                // Verify user belongs to the download's tenant
                $tenant = app('tenant');
                return $tenant && $download->tenant_id === $tenant->id;

            case DownloadAccessMode::RESTRICTED:
                // Restricted access - only specific users (future implementation)
                // For now, treat as team access
                if (!$user) {
                    return false;
                }

                $tenant = app('tenant');
                return $tenant && $download->tenant_id === $tenant->id;

            default:
                Log::warning('[DownloadController] Unknown access mode', [
                    'download_id' => $download->id,
                    'access_mode' => $download->access_mode?->value ?? 'null',
                ]);
                return false;
        }
    }

    /**
     * Get error message for download status.
     * 
     * @param DownloadStatus $status
     * @return string
     */
    protected function getStatusErrorMessage(DownloadStatus $status): string
    {
        return match ($status) {
            DownloadStatus::PENDING => 'Download is not ready yet',
            DownloadStatus::INVALIDATED => 'Download has been invalidated',
            DownloadStatus::FAILED => 'Download failed',
            DownloadStatus::READY => 'Download is ready', // Should not reach here
        };
    }

    /**
     * Get error message for ZIP status.
     * 
     * @param ZipStatus $zipStatus
     * @return string
     */
    protected function getZipStatusErrorMessage(ZipStatus $zipStatus): string
    {
        return match ($zipStatus) {
            ZipStatus::NONE => 'ZIP file has not been generated yet',
            ZipStatus::BUILDING => 'ZIP file is being built. Please try again in a few moments',
            ZipStatus::INVALIDATED => 'ZIP file needs to be regenerated',
            ZipStatus::FAILED => 'ZIP file generation failed',
            ZipStatus::READY => 'ZIP file is ready', // Should not reach here
        };
    }
}
