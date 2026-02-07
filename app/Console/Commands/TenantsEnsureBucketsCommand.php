<?php

namespace App\Console\Commands;

use App\Enums\StorageBucketStatus;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Services\CompanyStorageProvisioner;
use App\Services\TenantBucketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tenants Ensure Buckets (Reconciler)
 *
 * Ensures each tenant has exactly one canonical ACTIVE storage bucket.
 * - Computes expected bucket name from storage.bucket_name_pattern (via TenantBucketService).
 * - Promotes existing expected-name bucket to ACTIVE if it was PROVISIONING.
 * - Creates bucket (S3 + row) via CompanyStorageProvisioner when no expected-name row exists.
 * - Marks legacy shared buckets (e.g. dam-local-shared) as DEPRECATED so they do not block resolution.
 *
 * Idempotent and safe to run repeatedly in staging and production.
 *
 * WHERE TO RUN: Worker EC2, CI, or one-off ops only. Do not run from web EC2 so that
 * provisioning (CreateBucket, PutBucketCors, etc.) stays off the web tier.
 *
 * STATUS CASING: All status writes use StorageBucketStatus enum (values are lowercase:
 * active, provisioning, deprecated, deleting). If you see uppercase in DB, fix with:
 *   SELECT DISTINCT status FROM storage_buckets;
 * and normalize legacy rows if needed.
 */
class TenantsEnsureBucketsCommand extends Command
{
    protected $signature = 'tenants:ensure-buckets
        {--tenant-id= : Limit to a single tenant by ID}
        {--dry-run : Report what would happen without making changes}
        {--force : Allow running in local environment}
    ';

    protected $description = 'Reconcile storage buckets: ensure one ACTIVE bucket per tenant; deprecate legacy shared buckets';

    public function handle(TenantBucketService $bucketService, CompanyStorageProvisioner $provisioner): int
    {
        if (config('app.env') === 'local' && ! $this->option('force')) {
            $this->error('Aborted: APP_ENV=local. Use --force to run anyway.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant-id');
        $tenants = $tenantId
            ? Tenant::where('id', (int) $tenantId)->get()
            : Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->info('Dry run: no changes will be made.');
        }

        $rows = [];
        $failed = 0;

        foreach ($tenants as $tenant) {
            $result = $dryRun
                ? $this->reconcileTenantDryRun($bucketService, $provisioner, $tenant)
                : $this->reconcileTenant($bucketService, $provisioner, $tenant);

            $rows[] = [
                $tenant->id,
                $tenant->slug,
                $result['expected_bucket'],
                $result['action_summary'],
                $result['error'] ?? '-',
            ];

            if (isset($result['error']) && $result['error'] !== '-') {
                $failed++;
            }
        }

        $this->table(
            ['Tenant ID', 'Slug', 'Expected bucket', 'Action', 'Error'],
            $rows
        );

        $this->newLine();
        $this->info($failed > 0 ? "Summary: {$failed} tenant(s) had errors." : 'Reconciliation complete.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Reconcile one tenant: ensure one ACTIVE bucket with expected name; deprecate legacy ACTIVE buckets.
     *
     * @return array{expected_bucket: string, action_summary: string, error?: string}
     */
    protected function reconcileTenant(TenantBucketService $bucketService, CompanyStorageProvisioner $provisioner, Tenant $tenant): array
    {
        $expectedName = $bucketService->getBucketName($tenant);
        $buckets = StorageBucket::where('tenant_id', $tenant->id)->get();
        $expectedBucket = $buckets->firstWhere('name', $expectedName);
        $actions = [];

        try {
            // A) Expected-name bucket exists: ensure S3 config (CORS, etc.) and ACTIVE status
            if ($expectedBucket) {
                if ($expectedBucket->status !== StorageBucketStatus::ACTIVE) {
                    $provisioner->provision($tenant);
                    $actions[] = 'promoted';
                } else {
                    $provisioner->provision($tenant);
                    $actions[] = 'ok';
                }
            } else {
                // B) No bucket with expected name: provision (creates S3 + row)
                $provisioner->provision($tenant);
                $actions[] = 'created';
            }

            // Ensure CORS is present on the expected bucket (idempotent; never touch DEPRECATED)
            $corsApplied = $provisioner->ensureBucketCors($expectedName);
            $actions[] = $corsApplied ? 'cors-applied' : 'cors-ok';

            // C) Legacy shared buckets (same tenant, different name, ACTIVE): mark DEPRECATED
            $legacyActive = $buckets->filter(fn ($b) => $b->name !== $expectedName && $b->status === StorageBucketStatus::ACTIVE);
            $deprecatedCount = 0;
            foreach ($legacyActive as $legacy) {
                $legacy->update(['status' => StorageBucketStatus::DEPRECATED]);
                $deprecatedCount++;
            }
            if ($deprecatedCount > 0) {
                $actions[] = "deprecated {$deprecatedCount}";
            }

            $actionSummary = implode(', ', $actions);
            Log::info('[tenants:ensure-buckets] reconciled', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'expected_bucket' => $expectedName,
                'actions' => $actions,
            ]);

            return [
                'expected_bucket' => $expectedName,
                'action_summary' => $actionSummary,
            ];
        } catch (\Throwable $e) {
            Log::error('[tenants:ensure-buckets] failed', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'expected_bucket' => $expectedName,
                'error' => $e->getMessage(),
            ]);

            return [
                'expected_bucket' => $expectedName,
                'action_summary' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dry run: compute what would be done without making changes; report CORS status for existing buckets.
     * Never touches DEPRECATED buckets.
     *
     * @return array{expected_bucket: string, action_summary: string, error?: string}
     */
    protected function reconcileTenantDryRun(TenantBucketService $bucketService, CompanyStorageProvisioner $provisioner, Tenant $tenant): array
    {
        $expectedName = $bucketService->getBucketName($tenant);
        $buckets = StorageBucket::where('tenant_id', $tenant->id)->get();
        $expectedBucket = $buckets->firstWhere('name', $expectedName);
        $actions = [];

        if ($expectedBucket) {
            $actions[] = $expectedBucket->status === StorageBucketStatus::ACTIVE ? 'ok' : 'would promote';
            // Report CORS for expected bucket only (never DEPRECATED)
            try {
                $corsOk = $provisioner->bucketCorsMatchesExpected($expectedName);
                $actions[] = $corsOk ? 'cors-ok' : 'cors-missing';
            } catch (\Throwable $e) {
                $actions[] = 'cors-check-error';
            }
        } else {
            $actions[] = 'would create';
        }

        $legacyActive = $buckets->filter(fn ($b) => $b->name !== $expectedName && $b->status === StorageBucketStatus::ACTIVE);
        $n = $legacyActive->count();
        if ($n > 0) {
            $actions[] = "would deprecate {$n}";
        }

        return [
            'expected_bucket' => $expectedName,
            'action_summary' => implode(', ', $actions),
        ];
    }
}
