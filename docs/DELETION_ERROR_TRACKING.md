# Deletion Error Tracking System

## Overview

The Deletion Error Tracking System provides comprehensive error recording, presentation, and management for asset deletion failures. This ensures that **every deletion error is recorded AND presented** to administrators for resolution.

## Problem Solved

Previously, when asset deletion failed:
- Errors were only logged to files
- Users had no visibility into deletion failures
- Failed deletions could go unnoticed
- Manual intervention required diving into log files

## New System Features

### 1. Structured Error Recording
- **Categorized Error Types**: Permission denied, storage failure, timeout, network error, etc.
- **Detailed Context**: Exception details, AWS error codes, retry attempts
- **User-Friendly Messages**: Technical errors translated to actionable descriptions
- **Automatic Retries**: Built-in retry logic with exponential backoff

### 2. Error Presentation Dashboard
- **Admin Error Console**: Dedicated interface for viewing and managing deletion errors
- **Real-time Statistics**: Dashboard widget showing error counts and severity
- **Filtering & Search**: Filter by error type, resolution status, or filename
- **Detailed Error View**: Full technical details and resolution workflow

### 3. Error Resolution Workflow
- **Manual Resolution**: Mark errors as resolved with notes
- **Retry Mechanism**: Re-queue failed deletions for another attempt
- **Status Tracking**: Track resolution progress and responsible party
- **Automatic Cleanup**: Resolved errors are marked when deletion succeeds

## Technical Implementation

### Database Schema

**Table: `deletion_errors`**
```sql
- id (primary key)
- tenant_id (foreign key)
- asset_id (UUID of failed asset)
- original_filename (user-friendly identifier)
- deletion_type ('soft' | 'hard')
- error_type (categorized error type)
- error_message (original error message)
- error_details (JSON with technical details)
- attempts (number of retry attempts)
- resolved_at (when error was resolved)
- resolved_by (user who resolved)
- resolution_notes (resolution description)
- created_at / updated_at
```

### Error Categories

| Category | Description | Severity |
|----------|-------------|----------|
| `permission_denied` | AWS access denied, credentials issue | Critical |
| `storage_deletion_failed` | S3 deletion operation failed | Error |
| `storage_verification_failed` | File not found before deletion | Warning |
| `network_error` | Connection or timeout issues | Warning |
| `timeout` | Operation exceeded time limit | Warning |
| `database_deletion_failed` | Database record deletion failed | Error |
| `unknown_error` | Unclassified errors | Error |

### Enhanced DeleteAssetJob

**New Features:**
- Structured error recording with `DeletionError` model
- Automatic error categorization based on exception type
- Detailed technical context preservation
- Automatic cleanup on successful retry

**Error Flow:**
1. Exception occurs during deletion
2. Error is categorized and recorded in `deletion_errors` table
3. Job fails and enters retry queue
4. On successful retry, error is marked as resolved
5. On final failure, error remains for admin review

## User Interfaces

### 1. Admin Dashboard Widget

**Location:** Admin dashboard
**Features:**
- Unresolved error count
- Critical error indicator
- Recent errors (7 days)
- Quick link to error management

**Code:** `DeletionErrorWidget.jsx`

### 2. Error Management Console

**Location:** `/admin/deletion-errors`
**Features:**
- Paginated error listing
- Filter by status (resolved/unresolved)
- Filter by error type
- Search by filename
- Bulk operations

**Code:** `Pages/Admin/DeletionErrors.jsx`

### 3. Detailed Error View

**Location:** `/admin/deletion-errors/{id}`
**Features:**
- Complete error information
- Technical details (collapsible)
- Resolution workflow
- Retry functionality
- Resolution notes

**Code:** `Pages/Admin/DeletionErrors/Show.jsx`

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/deletion-errors` | List deletion errors |
| GET | `/admin/deletion-errors/{id}` | View error details |
| POST | `/admin/deletion-errors/{id}/resolve` | Mark error as resolved |
| POST | `/admin/deletion-errors/{id}/retry` | Retry deletion operation |
| DELETE | `/admin/deletion-errors/{id}` | Remove error record |
| GET | `/admin/deletion-errors/api/stats` | Get error statistics |

## Usage Examples

### 1. Handling Permission Errors
```php
// In DeleteAssetJob - automatic categorization
catch (S3Exception $e) {
    if ($e->getAwsErrorCode() === 'AccessDenied') {
        // Recorded as 'permission_denied' - Critical severity
        // Admin sees: "Permission denied while accessing storage"
        // Technical details preserved for troubleshooting
    }
}
```

### 2. Admin Resolution Workflow
1. Admin sees critical error indicator on dashboard
2. Navigates to error management console
3. Filters by "permission_denied" errors
4. Reviews error details and resolves credential issue
5. Marks error as resolved with notes: "Updated S3 credentials"
6. System automatically retries remaining queued deletions

### 3. Automatic Cleanup
```php
// When deletion succeeds after retry
DeletionError::where('asset_id', $asset->id)
    ->whereNull('resolved_at')
    ->update([
        'resolved_at' => now(),
        'resolution_notes' => 'Asset successfully deleted',
    ]);
```

## Error Prevention

### 1. Proactive Monitoring
- Dashboard widgets alert to error accumulation
- Critical errors (permission) highlighted separately
- Recent error trends tracked

### 2. Automatic Recovery
- Built-in retry logic with exponential backoff
- Errors automatically resolved on successful retry
- Idempotent deletion operations

### 3. Better Diagnostics
- Structured error information
- AWS-specific error codes preserved
- Full exception context maintained

## Configuration

### Permission Requirements
- **View Errors**: `manage assets` permission or admin/owner role
- **Resolve Errors**: `manage assets` permission or admin/owner role
- **Delete Error Records**: admin or owner role only

### Job Configuration
```php
class DeleteAssetJob {
    public $tries = 3;  // Maximum retry attempts
    public $backoff = [60, 300, 900];  // Backoff intervals
}
```

## Troubleshooting

### Common Issues

**High Permission Denied Errors**
- Check AWS credentials validity
- Verify S3 bucket permissions
- Review tenant-specific storage configurations

**Network Timeout Clusters**
- Check AWS service status
- Review network connectivity
- Consider retry backoff adjustments

**Persistent Storage Verification Failures**
- May indicate orphaned database records
- Consider running asset cleanup commands
- Review storage bucket consistency

### Debugging Commands

```bash
# View recent deletion errors
php artisan tinker --execute="
DeletionError::with('tenant')
    ->unresolved()
    ->latest()
    ->take(10)
    ->get()
    ->each(fn(\$e) => dump(\$e->toArray()));
"

# Check error patterns
php artisan tinker --execute="
DeletionError::selectRaw('error_type, count(*) as count')
    ->unresolved()
    ->groupBy('error_type')
    ->get()
    ->each(fn(\$row) => echo \$row->error_type . ': ' . \$row->count . PHP_EOL);
"

# Test deletion error recording (development only)
php artisan tinker --execute="
DeletionError::create([
    'tenant_id' => 1,
    'asset_id' => 'test-asset-id',
    'original_filename' => 'test-file.jpg',
    'deletion_type' => 'hard',
    'error_type' => 'storage_deletion_failed',
    'error_message' => 'Test error for UI verification',
    'attempts' => 1,
]);
"
```

## Performance Considerations

- **Index Usage**: Queries optimized with proper database indexes
- **Pagination**: Large error lists are paginated to maintain performance
- **Cleanup**: Resolved errors can be archived/deleted periodically
- **Monitoring**: Dashboard widgets cached to reduce load

## Future Enhancements

1. **Email Notifications**: Alert admins to critical deletion errors
2. **Bulk Resolution**: Resolve multiple similar errors at once
3. **Error Analytics**: Trends and patterns analysis
4. **Automatic Remediation**: Self-healing for common error types
5. **Webhook Integration**: External system notifications
6. **Error Archival**: Long-term storage of resolved errors

## Related Documentation

- `THUMBNAIL_STATUS_SYNC_ISSUE.md` - Similar error handling approach
- `app/Jobs/DeleteAssetJob.php` - Core deletion logic
- `app/Models/DeletionError.php` - Error model definition
- `app/Http/Controllers/DeletionErrorController.php` - Admin interface