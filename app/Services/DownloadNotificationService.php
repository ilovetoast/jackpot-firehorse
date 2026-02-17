<?php

namespace App\Services;

use App\Models\Download;
use App\Models\Notification;
use App\Services\FeatureGate;
use Illuminate\Support\Facades\Log;

/**
 * In-app notifications when a download ZIP is ready.
 * Uses the same notifications table and FeatureGate as ApprovalNotificationService.
 */
class DownloadNotificationService
{
    /**
     * Notify the download creator when their ZIP build has finished.
     */
    public function notifyOnZipReady(Download $download): void
    {
        $creator = $download->createdBy;
        if (! $creator) {
            Log::info('[DownloadNotificationService] Download has no creator, skipping notification', [
                'download_id' => $download->id,
            ]);
            return;
        }

        $tenant = $download->tenant;
        if (! $tenant) {
            return;
        }

        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
            Log::info('[DownloadNotificationService] Notifications disabled for tenant plan, skipping', [
                'download_id' => $download->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        $title = $download->title ?? "Download {$download->id}";
        $assetCount = (int) ($download->download_options['asset_count'] ?? $download->assets()->count() ?? 0);
        $brand = $download->brand;

        Notification::create([
            'user_id' => $creator->id,
            'type' => 'download.ready',
            'data' => [
                'download_id' => $download->id,
                'download_title' => $title,
                'asset_count' => $assetCount,
                'zip_size_bytes' => $download->zip_size_bytes,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'brand_id' => $brand?->id,
                'brand_name' => $brand?->name ?? $tenant->name,
                'created_at' => now()->toISOString(),
            ],
        ]);

        Log::info('[DownloadNotificationService] Notified creator that download ZIP is ready', [
            'download_id' => $download->id,
            'user_id' => $creator->id,
        ]);
    }
}
