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
use Illuminate\Validation\ValidationException;

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
     * Multi-brand safety: brand-based access is only allowed when all assets are from a single brand (hard constraint).
     * Intentional design—no heuristic brand selection; UI and backend both enforce this.
     */
    public function changeAccess(Download $download, string $accessMode, ?array $userIds, User $actor): void
    {
        if ($accessMode === DownloadAccessMode::BRAND->value && ! $download->canRestrictToBrand()) {
            throw ValidationException::withMessages([
                'access_mode' => ['Brand-based access is only available when all assets in the download are from a single brand. This download contains assets from multiple brands.'],
            ]);
        }

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

        \Illuminate\Support\Facades\Log::info('download.regenerate.triggered', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'actor_user_id' => $actor->id,
        ]);

        $this->logAction('regenerate', $download, $actor, $previousState, $this->captureState($download));
    }

    /**
     * Update download settings (access, landing page, password). Caller must gate by plan.
     * $updates can contain: access_mode, user_ids, uses_landing_page, landing_copy (array), password_hash (string|null to clear).
     * Multi-brand safety: brand-based access is only allowed when all assets are from a single brand (hard constraint).
     * Intentional design—no heuristic brand selection; consistent with changeAccess and bucket assertion.
     */
    public function updateSettings(Download $download, array $updates, User $actor): void
    {
        if (array_key_exists('access_mode', $updates) && $updates['access_mode'] === DownloadAccessMode::BRAND->value && ! $download->canRestrictToBrand()) {
            throw ValidationException::withMessages([
                'access_mode' => ['Brand-based access is only available when all assets in the download are from a single brand. This download contains assets from multiple brands.'],
            ]);
        }

        $previousState = $this->captureState($download);

        if (array_key_exists('access_mode', $updates)) {
            $accessMode = $updates['access_mode'];
            $download->update(['access_mode' => $accessMode]);
            if ($accessMode === DownloadAccessMode::USERS->value && isset($updates['user_ids']) && is_array($updates['user_ids'])) {
                $download->allowedUsers()->sync($updates['user_ids']);
            } else {
                $download->allowedUsers()->detach();
            }
        }

        $modelUpdates = [];
        if (array_key_exists('landing_copy', $updates)) {
            $modelUpdates['landing_copy'] = is_array($updates['landing_copy']) ? $updates['landing_copy'] : null;
        }
        if (array_key_exists('password_hash', $updates)) {
            $modelUpdates['password_hash'] = $updates['password_hash'];
        }
        if (! empty($modelUpdates)) {
            $download->update($modelUpdates);
        }

        $this->logAction('settings_update', $download, $actor, $previousState, $this->captureState($download));
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
            'uses_landing_page' => $download->uses_landing_page ?? false,
            'landing_copy' => $download->landing_copy,
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
