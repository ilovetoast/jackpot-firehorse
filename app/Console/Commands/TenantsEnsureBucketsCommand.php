<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantBucket\EnsureBucketResult;
use App\Services\TenantBucketService;
use Illuminate\Console\Command;

/**
 * Tenants Ensure Buckets
 *
 * Staging reconciliation: ensure each tenant has an S3 bucket.
 * Loops through tenants, calls TenantBucketService::ensureBucketExists.
 * Aborts in local unless --force.
 */
class TenantsEnsureBucketsCommand extends Command
{
    protected $signature = 'tenants:ensure-buckets
        {--tenant-id= : Limit to a single tenant by ID}
        {--dry-run : Report what would happen without creating buckets}
        {--force : Allow running in local environment}
    ';

    protected $description = 'Ensure S3 buckets exist for all tenants (staging reconciliation)';

    public function handle(TenantBucketService $service): int
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
            $this->info('Dry run: no buckets will be created.');
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        $rows = [];
        foreach ($tenants as $tenant) {
            if ($dryRun) {
                $result = $this->dryRunCheck($service, $tenant);
            } else {
                $result = $service->ensureBucketExists($tenant);
            }

            $status = $this->statusLabel($result);
            if ($result->wasCreated()) {
                $created++;
            } elseif ($result->wasSkipped()) {
                $skipped++;
            } else {
                $failed++;
            }

            $rows[] = [
                $tenant->id,
                $tenant->slug,
                $result->bucketName,
                $status,
                $result->errorMessage ?? '-',
            ];
        }

        $this->table(
            ['Tenant ID', 'Slug', 'Bucket', 'Status', 'Error'],
            $rows
        );

        $this->newLine();
        $this->info("Summary: {$created} created, {$skipped} already existed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function dryRunCheck(TenantBucketService $service, Tenant $tenant): EnsureBucketResult
    {
        try {
            $bucketName = $service->getBucketName($tenant);
            $exists = $service->bucketExists($tenant);
            if ($exists) {
                return new EnsureBucketResult(EnsureBucketResult::OUTCOME_SKIPPED, $bucketName);
            }

            return new EnsureBucketResult(EnsureBucketResult::OUTCOME_CREATED, $bucketName);
        } catch (\Throwable $e) {
            $bucketName = 'unknown';
            try {
                $bucketName = $service->getBucketName($tenant);
            } catch (\Throwable) {
                // use 'unknown' if getBucketName also fails
            }

            return new EnsureBucketResult(
                EnsureBucketResult::OUTCOME_FAILED,
                $bucketName,
                $e->getMessage()
            );
        }
    }

    protected function statusLabel(EnsureBucketResult $result): string
    {
        return match ($result->outcome) {
            EnsureBucketResult::OUTCOME_CREATED => 'created',
            EnsureBucketResult::OUTCOME_SKIPPED => 'already existed',
            EnsureBucketResult::OUTCOME_FAILED => 'failed',
            default => $result->outcome,
        };
    }
}
