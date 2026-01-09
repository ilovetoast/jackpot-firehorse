<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use Illuminate\Support\Facades\Log;

/**
 * Service for detecting and handling abandoned upload sessions.
 *
 * Abandoned sessions are UploadSessions that:
 * - Are in UPLOADING status
 * - Have not updated last_activity_at within the configured timeout period
 *
 * These sessions are marked as FAILED to prevent resume attempts on stale uploads.
 *
 * FUTURE CONSIDERATION (EXPIRED vs FAILED distinction - Optional, not required now):
 * Currently, expired sessions are marked as FAILED with a descriptive failure_reason.
 * This is acceptable for now, but later you may want:
 * - EXPIRED as a distinct terminal status in UploadStatus enum
 * - Only if UX needs to explain the difference between expiration vs failure
 * - Benefits: Better UX messaging, analytics distinction, different retry policies
 * 
 * No change needed now - failure_reason field provides sufficient context.
 */
class AbandonedSessionService
{
    /**
     * Default timeout for abandoned session detection (in minutes).
     * Sessions without activity for this duration are considered abandoned.
     */
    protected const DEFAULT_ABANDONED_TIMEOUT_MINUTES = 30;

    /**
     * Detect and mark abandoned upload sessions as FAILED.
     *
     * This method should be called periodically (e.g., via scheduled task)
     * to clean up sessions that have been abandoned by clients.
     *
     * @param int|null $timeoutMinutes Optional timeout in minutes (defaults to DEFAULT_ABANDONED_TIMEOUT_MINUTES)
     * @return int Number of sessions marked as abandoned
     */
    public function detectAndMarkAbandoned(?int $timeoutMinutes = null): int
    {
        $timeout = $timeoutMinutes ?? self::DEFAULT_ABANDONED_TIMEOUT_MINUTES;
        $cutoffTime = now()->subMinutes($timeout);

        // Find sessions that are UPLOADING and meet abandonment criteria:
        // 1. Have last_activity_at set AND haven't updated it within timeout period (abandoned)
        // 2. OR have expires_at set AND session has expired (expired)
        //
        // Note: Sessions without last_activity_at (older sessions) are ignored
        // Sessions without expires_at are checked only for activity timeout
        $abandonedSessions = UploadSession::where('status', UploadStatus::UPLOADING)
            ->where(function ($query) use ($cutoffTime) {
                // Abandoned: has last_activity_at but it's older than timeout
                $query->where(function ($q) use ($cutoffTime) {
                    $q->whereNotNull('last_activity_at')
                      ->where('last_activity_at', '<', $cutoffTime);
                });
                // OR expired: has expires_at and it's in the past
                $query->orWhere(function ($q) {
                    $q->whereNotNull('expires_at')
                      ->where('expires_at', '<', now());
                });
            })
            ->get();

        $markedCount = 0;

        foreach ($abandonedSessions as $session) {
            // Check if session is expired (expires_at in past)
            $isExpired = $session->expires_at && $session->expires_at->isPast();
            
            // Use guard method to check if transition to FAILED is allowed
            // Note: Expired sessions may not pass the guard, so we handle them separately
            if ($session->canTransitionTo(UploadStatus::FAILED)) {
                $failureReason = $isExpired
                    ? 'Upload session expired - no activity detected and session expiration time passed'
                    : 'Upload session abandoned - no activity detected for ' . $timeout . ' minutes';

                $session->update([
                    'status' => UploadStatus::FAILED,
                    'failure_reason' => $failureReason,
                ]);

                Log::info('Abandoned/expired upload session detected and marked as FAILED', [
                    'upload_session_id' => $session->id,
                    'tenant_id' => $session->tenant_id,
                    'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                    'expires_at' => $session->expires_at?->toIso8601String(),
                    'is_expired' => $isExpired,
                    'timeout_minutes' => $timeout,
                    'failure_reason' => $failureReason,
                ]);

                $markedCount++;
            } else {
                // If guard prevents transition, log warning but don't fail
                // This can happen if session is already in terminal state or expired
                Log::warning('Cannot mark upload session as abandoned - transition not allowed', [
                    'upload_session_id' => $session->id,
                    'current_status' => $session->status->value,
                    'is_expired' => $isExpired,
                    'expires_at' => $session->expires_at?->toIso8601String(),
                    'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                ]);
            }
        }

        if ($markedCount > 0) {
            Log::info('Abandoned session detection completed', [
                'marked_count' => $markedCount,
                'timeout_minutes' => $timeout,
            ]);
        }

        return $markedCount;
    }

    /**
     * Update last activity timestamp for an upload session.
     *
     * This should be called periodically during active uploads to prevent
     * the session from being marked as abandoned.
     *
     * @param UploadSession $uploadSession
     * @return void
     */
    public function updateActivity(UploadSession $uploadSession): void
    {
        // Only update activity for non-terminal sessions
        if (!$uploadSession->isTerminal()) {
            $uploadSession->update([
                'last_activity_at' => now(),
            ]);
        }
    }
}
