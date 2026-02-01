<?php

namespace App\Services;

use App\Enums\DownloadAccessMode;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Phase D2 — Download Management & Access Control
 *
 * Handles revoke, extend, changeAccess, regenerate with full audit logging.
 */
class DownloadManagementService
{
    public function __construct(
        protected PlanService $planService,
        protected DownloadExpirationPolicy $expirationPolicy
    ) {}

    /**
     * Revoke a download: invalidate link, delete artifact, mark revoked.
     */
    public function revoke(Download $download, User $actor): void
    {
        $previousState = $this->captureState($download);

        if ($download->zip_path) {
            $this->deleteZipFromS3($download);
        }

        $download->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'zip_status' => ZipStatus::FAILED,
            'zip_path' => null,
        ]);

        $this->logAction('revoke', $download, $actor, $previousState, $this->captureState($download));
    }

    /**
     * Extend expiration (plan-gated). Does NOT resurrect deleted artifacts.
     */
    public function extend(Download $download, \Carbon\Carbon $newExpiresAt, User $actor): void
    {
        $previousState = $this->captureState($download);

        $tenant = $download->tenant;
        $maxDays = $this->planService->getMaxDownloadExpirationDays($tenant);
        $maxExpiresAt = now()->addDays($maxDays);

        if ($newExpiresAt->gt($maxExpiresAt)) {
            $newExpiresAt = $maxExpiresAt;
        }

        $download->update([
            'expires_at' => $newExpiresAt,
            'hard_delete_at' => $this->expirationPolicy->calculateHardDeleteAt($download, $newExpiresAt),
        ]);

        $this->logAction('extend', $download, $actor, $previousState, $this->captureState($download));
    }

    /**
     * Change access scope (plan-gated).
     */
    public function changeAccess(Download $download, string $accessMode, ?array $userIds, User $actor): void
    {
        $previousState = $this->captureState($download);

        $download->update(['access_mode' => $accessMode]);

        if ($accessMode === DownloadAccessMode::USERS->value && is_array($userIds)) {
            $download->allowedUsers()->sync($userIds);
        } else {
            $download->allowedUsers()->detach();
        }

        $this->logAction('access_change', $download, $actor, $previousState, $this->captureState($download));
    }

    /**
     * Regenerate download (Enterprise only). Creates new artifact, deletes old.
     */
    public function regenerate(Download $download, User $actor): void
    {
        $previousState = $this->captureState($download);

        if ($download->zip_path) {
            $this->deleteZipFromS3($download);
        }

        $download->update([
            'zip_status' => ZipStatus::NONE,
            'zip_path' => null,
            'zip_size_bytes' => null,
            'version' => ($download->version ?? 1) + 1,
        ]);

        BuildDownloadZipJob::dispatch($download->id);

        $this->logAction('regenerate', $download, $actor, $previousState, $this->captureState($download));
    }

    /**
     * Capture download state for audit.
     */
    protected function captureState(Download $download): array
    {
        $download->refresh();
        return [
            'expires_at' => $download->expires_at?->toIso8601String(),
            'hard_delete_at' => $download->hard_delete_at?->toIso8601String(),
            'access_mode' => $download->access_mode?->value ?? $download->access_mode,
            'revoked_at' => $download->revoked_at?->toIso8601String(),
            'zip_status' => $download->zip_status?->value ?? $download->zip_status,
            'zip_path' => $download->zip_path,
            'version' => $download->version,
        ];
    }

    /**
     * Delete ZIP from S3 (tenant-specific bucket).
     */
    protected function deleteZipFromS3(Download $download): void
    {
        $bucket = StorageBucket::where('tenant_id', $download->tenant_id)
            ->where('status', StorageBucketStatus::ACTIVE)
            ->first();

        if (! $bucket) {
            Log::warning('[DownloadManagement] No storage bucket for tenant', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
            ]);
            return;
        }

        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
                'endpoint' => config('filesystems.disks.s3.endpoint'),
                'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
            ]);

            if ($client->doesObjectExist($bucket->name, $download->zip_path)) {
                $client->deleteObject([
                    'Bucket' => $bucket->name,
                    'Key' => $download->zip_path,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[DownloadManagement] Failed to delete ZIP from S3', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log management action (structured, AI-agent readable).
     */
    protected function logAction(
        string $action,
        Download $download,
        User $actor,
        array $previousState,
        array $newState
    ): void {
        Log::info('[DownloadManagement] Action performed', [
            'event' => 'download_management_action',
            'download_id' => $download->id,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'actor_email' => $actor->email,
            'timestamp' => now()->toIso8601String(),
            'previous_state' => $previousState,
            'new_state' => $newState,
        ]);
    }
}
