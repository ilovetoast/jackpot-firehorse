<?php

namespace App\Enums;

/**
 * S3 storage bucket status.
 *
 * Valid statuses: PROVISIONING, ACTIVE, SUSPENDED, DEPRECATED, DELETING.
 * resolveActiveBucketOrFail() only accepts ACTIVE; legacy shared buckets should be DEPRECATED.
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
     * Bucket is deprecated (e.g. legacy shared bucket).
     * Not used for new uploads; resolveActiveBucketOrFail() only accepts ACTIVE.
     * Do not delete or reuse; kept for audit/history.
     */
    case DEPRECATED = 'deprecated';

    /**
     * Bucket is being deleted.
     * Bucket and its contents are being removed.
     * Final state before bucket removal.
     * All assets should be deleted or migrated before reaching this state.
     */
    case DELETING = 'deleting';
}
