<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CompanyStorageProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionCompanyStorageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $tenantId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CompanyStorageProvisioner $provisioner): void
    {
        try {
            $tenant = Tenant::findOrFail($this->tenantId);

            Log::info('Provisioning storage for company', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
            ]);

            $bucket = $provisioner->provision($tenant);

            Log::info('Storage provisioned successfully', [
                'tenant_id' => $tenant->id,
                'bucket_id' => $bucket->id,
                'bucket_name' => $bucket->name,
                'status' => $bucket->status->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to provision storage for company', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Storage provisioning job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Optionally, update bucket status to indicate failure
        // This allows manual intervention or retry
        try {
            $tenant = Tenant::find($this->tenantId);
            if ($tenant) {
                $bucket = \App\Models\StorageBucket::where('tenant_id', $tenant->id)
                    ->where('status', \App\Enums\StorageBucketStatus::PROVISIONING)
                    ->first();

                if ($bucket) {
                    // Keep in PROVISIONING state for manual retry
                    // Do not change status on failure - allows retry via job dispatch
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to update bucket status after job failure', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
