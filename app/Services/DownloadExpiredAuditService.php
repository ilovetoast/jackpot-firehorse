<?php

namespace App\Services;

use App\Models\Download;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 *
 * Audit service: verifies that expired downloads do NOT have ZIP files in storage.
 * Flags anomalies (ZIP still present after expiration). Logs are structured for AI/agents.
 */
class DownloadExpiredAuditService
{
    public function __construct(
        protected DownloadExpirationPolicy $expirationPolicy
    ) {}

    /**
     * Run audit: find downloads that are expired (expires_at in the past) and verify
     * their ZIP does not exist in S3. Log anomalies (ZIP still present).
     *
     * @return array{checked: int, anomalies: list<array>, ok: int}
     */
    public function run(): array
    {
        $expired = Download::withTrashed()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $anomalies = [];
        $ok = 0;

        foreach ($expired as $download) {
            if (! $download->zip_path) {
                $ok++;
                continue;
            }

            $exists = $this->zipExistsInStorage($download);
            if ($exists) {
                $anomaly = [
                    'download_id' => $download->id,
                    'tenant_id' => $download->tenant_id,
                    'zip_path' => $download->zip_path,
                    'expires_at' => $download->expires_at?->toIso8601String(),
                    'message' => 'Expired download still has ZIP in storage',
                ];
                $anomalies[] = $anomaly;
                Log::warning('[DownloadExpiredAudit] Anomaly: expired download ZIP still in storage', [
                    'event' => 'download_audit_anomaly',
                    'download_id' => $download->id,
                    'zip_path' => $download->zip_path,
                ]);
            } else {
                $ok++;
            }
        }

        $result = [
            'checked' => $expired->count(),
            'anomalies' => $anomalies,
            'ok' => $ok,
        ];

        Log::info('[DownloadExpiredAudit] Audit completed', [
            'event' => 'download_audit_complete',
            'result' => $result,
        ]);

        return $result;
    }

    private function zipExistsInStorage(Download $download): bool
    {
        $bucketService = app(\App\Services\TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($download->tenant);

        $client = $this->s3Client();
        return $client->doesObjectExist($bucket->name, $download->zip_path);
    }

    private function s3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }
        return new S3Client($config);
    }
}
