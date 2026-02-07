<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Company Storage Usage Service
 *
 * Calculates and tracks storage usage for companies (tenants).
 * Implements storage accounting rules:
 * - Originals count toward storage
 * - Versions count based on plan
 * - Thumbnails excluded
 * - Failed assets excluded
 * - Admin-only repair methods available
 */
class CompanyStorageUsageService
{
    public function __construct(
        protected PlanService $planService,
        protected ?S3Client $s3Client = null
    ) {
    }

    /**
     * Calculate storage usage for a tenant (originals only).
     * Excludes thumbnails, previews, and failed assets.
     *
     * @param Tenant $tenant
     * @return int Total storage in bytes
     */
    public function calculateStorageUsage(Tenant $tenant): int
    {
        // Query assets that count toward storage:
        // - Not soft-deleted (SoftDeletes trait automatically excludes trashed records)
        // - Status is not FAILED (failed assets don't count)
        // - Status is not DELETED (deleted assets don't count)
        // Note: Asset records represent original files only.
        // Thumbnails and previews are stored in metadata and don't have separate Asset records.
        $totalBytes = Asset::where('tenant_id', $tenant->id)
            ->where('status', '!=', AssetStatus::FAILED)
            ->whereNotNull('storage_root_path')
            ->sum('size_bytes') ?? 0;

        return (int) $totalBytes;
    }

    /**
     * Calculate version storage for a tenant.
     * Version counting is based on plan.
     *
     * @param Tenant $tenant
     * @return int Total version storage in bytes
     */
    public function calculateVersionStorage(Tenant $tenant): int
    {
        $planName = $this->planService->getCurrentPlan($tenant);

        // Free and Starter plans don't count versions
        if (in_array($planName, ['free', 'starter'])) {
            return 0;
        }

        // Pro and Enterprise plans count versions
        // Get all buckets for tenant
        $buckets = StorageBucket::where('tenant_id', $tenant->id)->get();

        if ($buckets->isEmpty()) {
            return 0;
        }

        $totalVersionStorage = 0;
        $s3Client = $this->getS3Client();

        foreach ($buckets as $bucket) {
            try {
                $versionStorage = $this->calculateBucketVersionStorage($bucket, $s3Client);
                $totalVersionStorage += $versionStorage;
            } catch (\Exception $e) {
                Log::warning('Failed to calculate version storage for bucket', [
                    'bucket' => $bucket->name,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other buckets
            }
        }

        return $totalVersionStorage;
    }

    /**
     * Calculate version storage for a specific bucket.
     *
     * @param StorageBucket $bucket
     * @param S3Client|null $s3Client
     * @return int Total version storage in bytes
     */
    protected function calculateBucketVersionStorage(StorageBucket $bucket, ?S3Client $s3Client = null): int
    {
        $s3Client = $s3Client ?? $this->getS3Client();
        $totalVersionStorage = 0;

        try {
            // Get all object versions in bucket
            $versions = $s3Client->listObjectVersions([
                'Bucket' => $bucket->name,
            ]);

            // Sum up all non-current versions (delete markers are free)
            if (isset($versions['Versions'])) {
                foreach ($versions['Versions'] as $version) {
                    // Only count non-current versions
                    if (!$version['IsLatest']) {
                        $totalVersionStorage += $version['Size'] ?? 0;
                    }
                }
            }
        } catch (S3Exception $e) {
            // Versioning might not be enabled or bucket might not exist
            if ($e->getAwsErrorCode() !== 'NoSuchBucket') {
                Log::error('Failed to calculate bucket version storage', [
                    'bucket' => $bucket->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalVersionStorage;
    }

    /**
     * Get total storage usage for a tenant (originals + versions).
     *
     * @param Tenant $tenant
     * @return array Storage usage breakdown
     */
    public function getTotalStorageUsage(Tenant $tenant): array
    {
        $originalStorage = $this->calculateStorageUsage($tenant);
        $versionStorage = $this->calculateVersionStorage($tenant);
        $totalStorage = $originalStorage + $versionStorage;

        $maxStorage = $this->planService->getMaxStorage($tenant);

        return [
            'total_bytes' => $totalStorage,
            'total_mb' => round($totalStorage / 1024 / 1024, 2),
            'original_bytes' => $originalStorage,
            'original_mb' => round($originalStorage / 1024 / 1024, 2),
            'version_bytes' => $versionStorage,
            'version_mb' => round($versionStorage / 1024 / 1024, 2),
            'max_bytes' => $maxStorage,
            'max_mb' => round($maxStorage / 1024 / 1024, 2),
            'usage_percent' => $maxStorage > 0 ? round(($totalStorage / $maxStorage) * 100, 2) : 0,
            'plan' => $this->planService->getCurrentPlan($tenant),
        ];
    }

    /**
     * Get storage breakdown by status for a tenant.
     *
     * @param Tenant $tenant
     * @return array Storage breakdown by status
     */
    public function getStorageBreakdown(Tenant $tenant): array
    {
        $breakdown = Asset::where('tenant_id', $tenant->id)
            ->where('status', '!=', AssetStatus::FAILED)
            ->selectRaw('status, COUNT(*) as count, SUM(size_bytes) as total_bytes')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->status->value => [
                        'count' => $item->count,
                        'bytes' => (int) $item->total_bytes,
                        'mb' => round($item->total_bytes / 1024 / 1024, 2),
                    ],
                ];
            })
            ->toArray();

        return [
            'by_status' => $breakdown,
            'total_assets' => array_sum(array_column($breakdown, 'count')),
            'total_bytes' => array_sum(array_column($breakdown, 'bytes')),
        ];
    }

    /**
     * Repair storage calculations for a tenant (admin-only).
     * Recalculates storage usage and fixes any discrepancies.
     *
     * @param Tenant $tenant
     * @return array Repair results
     */
    public function repairStorageCalculations(Tenant $tenant): array
    {
        Log::info('Starting storage calculation repair', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
        ]);

        $results = [
            'before' => $this->getTotalStorageUsage($tenant),
            'fixed_assets' => 0,
            'errors' => [],
        ];

        try {
            // Find assets with inconsistencies
            $assets = Asset::where('tenant_id', $tenant->id)
                ->where('status', '!=', AssetStatus::FAILED)
                ->get();

            foreach ($assets as $asset) {
                try {
                    // Verify file exists and get actual size
                    $actualSize = $this->verifyAssetSize($asset);

                    if ($actualSize !== null && $actualSize !== $asset->size_bytes) {
                        // Update asset with correct size
                        $asset->update(['size_bytes' => $actualSize]);
                        $results['fixed_assets']++;

                        Log::info('Fixed asset file size', [
                            'asset_id' => $asset->id,
                            'old_size' => $asset->size_bytes,
                            'new_size' => $actualSize,
                        ]);
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ];
                    Log::warning('Failed to verify asset size', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $results['after'] = $this->getTotalStorageUsage($tenant);

            Log::info('Storage calculation repair completed', [
                'tenant_id' => $tenant->id,
                'fixed_assets' => $results['fixed_assets'],
                'errors' => count($results['errors']),
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('Storage calculation repair failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            $results['errors'][] = [
                'general' => $e->getMessage(),
            ];

            return $results;
        }
    }

    /**
     * Verify asset size by checking S3.
     *
     * @param Asset $asset
     * @return int|null Actual file size in bytes, or null if not found
     */
    protected function verifyAssetSize(Asset $asset): ?int
    {
        $bucket = $asset->storageBucket;
        if (!$bucket) {
            return null;
        }

        $s3Client = $this->getS3Client();

        try {
            $result = $s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $asset->storage_root_path,
            ]);

            return (int) ($result['ContentLength'] ?? null);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === '404' || $e->getAwsErrorCode() === 'NoSuchKey') {
                // File doesn't exist
                return null;
            }
            throw $e;
        }
    }

    /**
     * Recalculate storage for all tenants (admin-only).
     *
     * @return array Summary of repairs
     */
    public function repairAllTenants(): array
    {
        $tenants = Tenant::all();
        $summary = [
            'total_tenants' => $tenants->count(),
            'repaired' => 0,
            'errors' => [],
        ];

        foreach ($tenants as $tenant) {
            try {
                $this->repairStorageCalculations($tenant);
                $summary['repaired']++;
            } catch (\Exception $e) {
                $summary['errors'][] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to repair tenant storage', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Get S3 client instance.
     *
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if ($this->s3Client) {
            return $this->s3Client;
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Endpoint for MinIO/local S3; credentials via SDK default chain
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
