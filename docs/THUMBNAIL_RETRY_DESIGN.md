# Thumbnail Retry Feature - Architecture Design

## Overview

This document outlines the design for a safe, additive feature that allows users to manually retry thumbnail generation from the asset drawer UI. The design prioritizes safety, auditability, and respect for existing system guarantees.

## Design Principles

1. **Additive Only**: No refactoring of existing thumbnail jobs or pipeline
2. **Status Immutability**: Asset.status must never be mutated by retry operations
3. **Non-Realtime**: Grid thumbnails only update on page refresh (existing behavior)
4. **Auditable**: All retry attempts are logged and tracked
5. **Safe Limits**: Retry limits prevent abuse and infinite loops
6. **File Type Validation**: Only supported file types can be retried

---

## 1. High-Level Architecture

### Flow Diagram

```
User clicks "Retry Thumbnails" in AssetDrawer
    ↓
Frontend validates: file type supported, retry limit not exceeded
    ↓
POST /app/assets/{id}/thumbnails/retry
    ↓
ThumbnailRetryService validates request
    ↓
ThumbnailRetryService checks retry limits
    ↓
ThumbnailRetryService dispatches GenerateThumbnailsJob
    ↓
GenerateThumbnailsJob processes (existing job, unchanged)
    ↓
Drawer polling detects completion (existing mechanism)
    ↓
User sees updated thumbnail on next poll (or page refresh)
```

### Key Components

1. **Frontend**: AssetDrawer component with retry button
2. **API Endpoint**: `POST /app/assets/{id}/thumbnails/retry`
3. **Service Layer**: `ThumbnailRetryService` (new)
4. **Job**: `GenerateThumbnailsJob` (existing, unchanged)
5. **Database**: New fields on `assets` table for retry tracking

---

## 2. New Services, Jobs, and Actions

### Services

- **`ThumbnailRetryService`** (`app/Services/ThumbnailRetryService.php`)
  - Validates retry eligibility
  - Enforces retry limits
  - Validates file type support
  - Dispatches `GenerateThumbnailsJob`
  - Records retry attempts in metadata
  - Logs retry events for audit trail

### Controllers

- **`AssetThumbnailController::retry()`** (new method)
  - Handles `POST /app/assets/{id}/thumbnails/retry`
  - Validates user permissions
  - Calls `ThumbnailRetryService`
  - Returns JSON response with retry status

### Actions (No new actions needed)

- Uses existing `GenerateThumbnailsJob` (no changes)
- Uses existing `ThumbnailGenerationService` (no changes)

---

## 3. Retry Limit Enforcement

### Limit Strategy

**Per-Asset Retry Limit**: Maximum 3 manual retries per asset (configurable via config)

**Enforcement Points**:

1. **Frontend (First Line of Defense)**
   - Button disabled if `thumbnail_retry_count >= 3`
   - Button disabled if `thumbnail_status === 'skipped'` (unsupported file type)
   - Button disabled if `thumbnail_status === 'processing'` (already in progress)

2. **Backend Service (Authoritative)**
   - `ThumbnailRetryService::canRetry()` checks:
     - `thumbnail_retry_count < max_retries` (default: 3)
     - `thumbnail_status !== 'skipped'` (unsupported file type)
     - `thumbnail_status !== 'processing'` (already in progress)
     - File type is supported (uses same logic as `GenerateThumbnailsJob::supportsThumbnailGeneration()`)

3. **Database Constraint (Safety Net)**
   - Check constraint or application-level validation prevents exceeding limit
   - Metadata field `thumbnail_retry_count` tracks attempts

### Retry Count Tracking

- **Field**: `assets.thumbnail_retry_count` (integer, default: 0)
- **Increment**: Only on successful retry dispatch (not on job failure)
- **Reset**: Never (permanent audit trail)
- **Metadata**: Also stored in `metadata['thumbnail_retries']` array with timestamps

### Rate Limiting (Optional Future Enhancement)

- Consider per-user rate limiting (e.g., max 10 retries per hour)
- Consider per-tenant rate limiting (e.g., max 100 retries per hour)
- **Not in initial implementation** - can be added later if abuse occurs

---

## 4. Supported File Type Validation

### Validation Logic

**Reuse Existing Logic**: Use the same validation as `GenerateThumbnailsJob::supportsThumbnailGeneration()`

**Supported Types**:
- JPEG/JPG (`image/jpeg`, `image/jpg`)
- PNG (`image/png`)
- GIF (`image/gif`)
- WEBP (`image/webp`)

**Excluded Types** (will be rejected):
- TIFF/TIF (`image/tiff`, `image/tif`) - GD library limitation
- AVIF (`image/avif`) - Backend pipeline limitation
- BMP (`image/bmp`) - GD library limitation
- SVG (`image/svg+xml`) - GD library limitation

### Validation Points

1. **Frontend**: Button hidden/disabled for unsupported file types
2. **Backend Service**: `ThumbnailRetryService::isFileTypeSupported()` validates before dispatch
3. **Job**: Existing `GenerateThumbnailsJob` already validates (defensive check)

### Error Response

If file type is unsupported:
- HTTP 422 Unprocessable Entity
- Error message: "Thumbnail generation is not supported for this file type"
- Frontend shows error toast/alert

---

## 5. Metadata to Store on Asset

### New Database Fields

**Migration**: `add_thumbnail_retry_tracking_to_assets_table`

```php
Schema::table('assets', function (Blueprint $table) {
    $table->unsignedInteger('thumbnail_retry_count')->default(0)->after('thumbnail_started_at');
    $table->timestamp('thumbnail_last_retry_at')->nullable()->after('thumbnail_retry_count');
});
```

**Fields**:
- `thumbnail_retry_count` (integer, default: 0)
  - Tracks total number of manual retry attempts
  - Incremented only when retry is successfully dispatched
  - Never reset (permanent audit trail)

- `thumbnail_last_retry_at` (timestamp, nullable)
  - Timestamp of most recent retry attempt
  - Updated on each retry dispatch
  - Used for rate limiting (future) and audit queries

### Metadata JSON Structure

**Existing metadata field** (`assets.metadata` JSON column):

```json
{
  "thumbnail_retries": [
    {
      "attempted_at": "2024-01-15T10:30:00Z",
      "triggered_by_user_id": "uuid-here",
      "previous_status": "failed",
      "job_dispatched": true,
      "job_id": "job-uuid-here"
    },
    {
      "attempted_at": "2024-01-15T11:00:00Z",
      "triggered_by_user_id": "uuid-here",
      "previous_status": "failed",
      "job_dispatched": true,
      "job_id": "job-uuid-here"
    }
  ],
  "thumbnail_retry_limit_reached": false
}
```

**Metadata Updates**:
- Append to `thumbnail_retries` array on each retry
- Set `thumbnail_retry_limit_reached` to `true` when limit exceeded
- Never remove entries (append-only audit log)

---

## 6. Failure Modes and UI Surface

### Failure Scenarios

#### 1. Retry Limit Exceeded
- **HTTP Status**: 429 Too Many Requests (or 422 Unprocessable Entity)
- **Response**: `{ "error": "Maximum retry attempts (3) exceeded for this asset" }`
- **UI**: Button disabled, tooltip shows "Retry limit reached (3/3 attempts used)"
- **User Action**: None (limit is permanent per asset)

#### 2. Unsupported File Type
- **HTTP Status**: 422 Unprocessable Entity
- **Response**: `{ "error": "Thumbnail generation is not supported for this file type" }`
- **UI**: Button hidden or disabled with tooltip "Unsupported file type"
- **User Action**: None (file type cannot be changed)

#### 3. Already Processing
- **HTTP Status**: 409 Conflict
- **Response**: `{ "error": "Thumbnail generation is already in progress" }`
- **UI**: Button disabled, shows "Processing..." state
- **User Action**: Wait for current job to complete

#### 4. Asset Not Found
- **HTTP Status**: 404 Not Found
- **Response**: `{ "error": "Asset not found" }`
- **UI**: Error toast, drawer may close
- **User Action**: Refresh page

#### 5. Permission Denied
- **HTTP Status**: 403 Forbidden
- **Response**: `{ "error": "You do not have permission to retry thumbnails for this asset" }`
- **UI**: Button hidden or disabled
- **User Action**: Contact admin

#### 6. Job Dispatch Failure
- **HTTP Status**: 500 Internal Server Error
- **Response**: `{ "error": "Failed to dispatch thumbnail generation job" }`
- **UI**: Error toast with retry option
- **User Action**: Retry request (does not count toward limit if dispatch fails)

### UI States

#### Button States in AssetDrawer

1. **Available** (enabled, clickable)
   - Condition: `thumbnail_status === 'failed'` AND `thumbnail_retry_count < 3` AND file type supported
   - Label: "Retry Thumbnail Generation"
   - Icon: ArrowPathIcon (refresh icon)

2. **Disabled - Limit Reached**
   - Condition: `thumbnail_retry_count >= 3`
   - Label: "Retry Limit Reached"
   - Tooltip: "Maximum retry attempts (3/3) exceeded for this asset"

3. **Disabled - Unsupported Type**
   - Condition: `thumbnail_status === 'skipped'` OR file type not supported
   - Label: "Unsupported File Type"
   - Tooltip: "Thumbnail generation is not supported for this file type"

4. **Disabled - Processing**
   - Condition: `thumbnail_status === 'processing'`
   - Label: "Processing..."
   - Tooltip: "Thumbnail generation is already in progress"

5. **Hidden**
   - Condition: `thumbnail_status === 'completed'` (thumbnails already exist)
   - No button shown (success state)

### Error Display

- **Toast Notifications**: For transient errors (500, network failures)
- **Inline Error**: For permanent errors (422, 429) shown in drawer
- **Activity Timeline**: Retry attempts logged as events (existing mechanism)

---

## 7. Explicit Callouts: What NOT to Change

### ❌ DO NOT Modify

1. **`GenerateThumbnailsJob`**
   - Do not add retry-specific logic
   - Do not change job behavior based on retry context
   - Job should be agnostic to whether it's initial generation or retry

2. **`ThumbnailGenerationService`**
   - Do not modify thumbnail generation logic
   - Do not add retry-specific parameters

3. **`Asset.status` Mutations**
   - Never mutate `Asset.status` in retry flow
   - `Asset.status` represents visibility only (VISIBLE/HIDDEN/FAILED)
   - Retry operations must not change visibility

4. **Grid Thumbnail Updates**
   - Do not add live updates to grid thumbnails
   - Grid thumbnails only update on page refresh (by design)
   - Drawer polling is isolated and does not affect grid

5. **Queue Orchestration**
   - Do not change how jobs are dispatched in normal upload flow
   - Do not modify `ProcessAssetJob` or job chaining
   - Retry is a separate, independent dispatch

6. **Thumbnail Status Enum**
   - Do not add new status values (PENDING, PROCESSING, COMPLETED, FAILED, SKIPPED are sufficient)
   - Retry uses existing status values

7. **Existing Validation Logic**
   - Do not duplicate file type validation
   - Reuse `GenerateThumbnailsJob::supportsThumbnailGeneration()`

### ✅ Safe to Add

1. New database fields (`thumbnail_retry_count`, `thumbnail_last_retry_at`)
2. New service (`ThumbnailRetryService`)
3. New controller method (`AssetThumbnailController::retry()`)
4. New route (`POST /app/assets/{id}/thumbnails/retry`)
5. Frontend retry button in `AssetDrawer`
6. Metadata tracking in `assets.metadata` JSON field
7. Activity event logging (using existing `ActivityRecorder`)

---

## 8. Audit Trail and Observability

### Activity Events

**New Event Type**: `EventType::ASSET_THUMBNAIL_RETRY_REQUESTED`

**Event Metadata**:
```json
{
  "retry_count": 1,
  "previous_status": "failed",
  "triggered_by_user_id": "uuid-here",
  "file_type": "image/jpeg",
  "job_id": "job-uuid-here"
}
```

**Logging**:
- Log retry request in `ActivityRecorder::logAsset()`
- Log retry dispatch in `ThumbnailRetryService`
- Log retry limit enforcement in service

### Database Queries

**Find assets with retry attempts**:
```sql
SELECT * FROM assets WHERE thumbnail_retry_count > 0;
```

**Find assets at retry limit**:
```sql
SELECT * FROM assets WHERE thumbnail_retry_count >= 3;
```

**Audit retry history**:
```sql
SELECT id, thumbnail_retry_count, thumbnail_last_retry_at, metadata->'$.thumbnail_retries' 
FROM assets 
WHERE JSON_LENGTH(metadata->'$.thumbnail_retries') > 0;
```

---

## 9. Permissions and Authorization

### Permission Check

**Required Permission**: `assets.retry_thumbnails` (new permission)

**Default Assignment**:
- Tenant owners: ✅ Allowed
- Tenant admins: ✅ Allowed
- Brand managers: ✅ Allowed
- Brand editors: ✅ Allowed (if they can view asset)
- Brand viewers: ❌ Not allowed (read-only)

**Policy**: `AssetPolicy::retryThumbnails(Asset $asset)`

**Authorization Flow**:
1. Check user has `assets.retry_thumbnails` permission for tenant
2. Check user can view asset (existing `AssetPolicy::view()`)
3. Check asset belongs to user's active tenant/brand

---

## 10. Configuration

### Config File: `config/assets.php`

**New Settings**:
```php
'thumbnail_retry' => [
    'max_attempts' => env('THUMBNAIL_MAX_RETRIES', 3),
    'rate_limit_per_user' => env('THUMBNAIL_RETRY_RATE_LIMIT_USER', null), // Optional
    'rate_limit_per_tenant' => env('THUMBNAIL_RETRY_RATE_LIMIT_TENANT', null), // Optional
],
```

---

## 11. Testing Considerations

### Unit Tests

- `ThumbnailRetryService::canRetry()` - limit enforcement
- `ThumbnailRetryService::isFileTypeSupported()` - file type validation
- `ThumbnailRetryService::dispatchRetry()` - job dispatch

### Integration Tests

- Retry endpoint returns 422 for unsupported file types
- Retry endpoint returns 429 when limit exceeded
- Retry endpoint dispatches job successfully
- Retry count increments correctly
- Metadata updates correctly

### Manual Testing

- Test retry button appears for failed thumbnails
- Test retry button disabled at limit
- Test retry button hidden for unsupported types
- Test retry works end-to-end
- Test drawer polling detects completion
- Test grid does NOT update until page refresh

---

## 12. Implementation Checklist

### Backend

- [ ] Create migration: `add_thumbnail_retry_tracking_to_assets_table`
- [ ] Create `ThumbnailRetryService`
- [ ] Add `AssetThumbnailController::retry()` method
- [ ] Add route: `POST /app/assets/{id}/thumbnails/retry`
- [ ] Add permission: `assets.retry_thumbnails`
- [ ] Add policy method: `AssetPolicy::retryThumbnails()`
- [ ] Add event type: `EventType::ASSET_THUMBNAIL_RETRY_REQUESTED`
- [ ] Add config: `config/assets.php` retry settings
- [ ] Add tests for service
- [ ] Add tests for controller

### Frontend

- [ ] Add retry button to `AssetDrawer` component
- [ ] Add retry API call in `AssetDrawer`
- [ ] Add button state logic (enabled/disabled/hidden)
- [ ] Add error handling and toast notifications
- [ ] Add loading state during retry dispatch
- [ ] Update `AssetTimeline` to show retry events (if needed)
- [ ] Test all button states
- [ ] Test error scenarios

### Documentation

- [ ] Update `THUMBNAIL_PIPELINE.md` with retry feature
- [ ] Add API documentation for retry endpoint
- [ ] Update user-facing docs (if applicable)

---

## 13. Future Enhancements (Deferred)

These features are explicitly **NOT** in the initial implementation:

1. **Bulk Retry**: Retry multiple assets at once
2. **Admin Override**: Admin can bypass retry limits
3. **Retry Scheduling**: Schedule retries for later
4. **Retry Analytics**: Dashboard showing retry patterns
5. **Auto-Retry**: Automatic retry on failure (with exponential backoff)
6. **Webhook Notifications**: Notify external systems on retry

---

## Summary

This design provides a safe, additive feature that:
- ✅ Respects existing system constraints
- ✅ Does not modify locked thumbnail pipeline
- ✅ Enforces retry limits to prevent abuse
- ✅ Validates file types before retry
- ✅ Provides comprehensive audit trail
- ✅ Handles all failure modes gracefully
- ✅ Maintains non-realtime grid behavior
- ✅ Never mutates `Asset.status`

The implementation is straightforward, testable, and maintains the stability guarantees of the existing system.
