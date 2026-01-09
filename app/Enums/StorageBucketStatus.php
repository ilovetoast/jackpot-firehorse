<?php

namespace App\Enums;

/**
 * S3 storage bucket status.
 *
 * Tracks the lifecycle state of storage buckets.
 * In production, one bucket exists per company/tenant.
 * In local/staging, a shared bucket is used.
 */
enum StorageBucketStatus: string
{
    /**
     * Bucket is active and available for use.
     * Bucket is fully provisioned and ready to receive uploads.
     * All CRUD operations are allowed.
     */
    case ACTIVE = 'active';

    /**
     * Bucket is being provisioned.
     * Initial state when bucket creation is requested.
     * Bucket is not yet available for operations.
     * Used primarily in production when creating tenant-specific buckets.
     */
    case PROVISIONING = 'provisioning';

    /**
     * Bucket is temporarily suspended.
     * Bucket exists but operations are blocked.
     * May be due to billing issues, policy violations, or maintenance.
     * Assets remain in storage but are not accessible.
     */
    case SUSPENDED = 'suspended';

    /**
     * Bucket is being deleted.
     * Bucket and its contents are being removed.
     * Final state before bucket removal.
     * All assets should be deleted or migrated before reaching this state.
     */
    case DELETING = 'deleting';
}
