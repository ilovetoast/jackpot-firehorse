# Storage and S3

Buckets, upload strategy, limits, and storage call chain.

---


This document defines the S3 bucket strategy for the DAM application across local, staging, and production environments.

---

## Bucket Naming Conventions

**Approved structure:** `{app-prefix}-{environment}-{tenant-slug}`

Examples: `jackpot-staging-acme`, `jackpot-staging-velvet-hammer`, `jackpot-staging-st-croix`

This matches IAM resource patterns such as `arn:aws:s3:::jackpot-staging-*`.

| Environment | Strategy | Naming Convention | Example |
|-------------|----------|-------------------|---------|
| **Local** | Shared | Single bucket via `AWS_BUCKET` | `dam-local-shared` |
| **Staging** | Per-tenant (recommended) or Shared | Pattern: `jackpot-{env}-{company_slug}` or `AWS_BUCKET` | `jackpot-staging-acme`, `jackpot-staging-velvet-hammer` |
| **Production** | Per-tenant | Pattern: `jackpot-{env}-{company_slug}` | `jackpot-production-velvethammerbranding` |

### Local
- One shared bucket for all tenants.
- Configured via `AWS_BUCKET` in `.env` (e.g., `dam-local-shared`).
- Typically used with MinIO or localstack for development.

### Staging
- **Per-tenant (recommended):** Set `STORAGE_PROVISION_STRATEGY=per_company` and `STORAGE_BUCKET_NAME_PATTERN=jackpot-{env}-{company_slug}` so bucket names match IAM `jackpot-staging-*`. One bucket per company; provisioned on first use.
- **Shared:** Set `STORAGE_PROVISION_STRATEGY=shared` and `AWS_BUCKET` to a single bucket name.
- Set `APP_URL` to your staging domain (e.g. `https://staging-jackpot.velvetysoft.com`). The app uses this to set S3 CORS allowed origins for browser uploads.

### Production
- One dedicated bucket per company (tenant).
- Bucket name from `STORAGE_BUCKET_NAME_PATTERN` (default: `jackpot-{env}-{company_slug}`).
- Placeholders: `{env}`, `{company_id}`, `{company_slug}`.
- S3 rules: lowercase, 3–63 characters, alphanumeric and hyphens only.

---

## Shared vs Per-Tenant Buckets

| Environment | Strategy | Shared or Per-Tenant | Env Variable |
|-------------|----------|----------------------|--------------|
| **Local** | `shared` | Shared (one bucket for all) | `STORAGE_PROVISION_STRATEGY=shared` |
| **Staging** | `per_company` or `shared` | Per-tenant (recommended) or shared | `STORAGE_PROVISION_STRATEGY=per_company`, `STORAGE_BUCKET_NAME_PATTERN=jackpot-{env}-{company_slug}` |
| **Production** | `per_company` | Per-tenant (one bucket per company) | `STORAGE_PROVISION_STRATEGY=per_company` |

### Staging environment variables (per-tenant buckets)

Use these in staging `.env` when using per-tenant buckets and IAM pattern `jackpot-staging-*`:

- `APP_ENV=staging`
- `APP_URL=https://your-staging-domain.com` (e.g. `https://staging-jackpot.velvetysoft.com`) — used for CORS allowed origin
- `STORAGE_PROVISION_STRATEGY=per_company`
- `STORAGE_BUCKET_NAME_PATTERN=jackpot-{env}-{company_slug}` (optional; this is the default)

Optional: `STORAGE_CORS_ORIGINS` — comma-separated origins for S3 CORS. If unset, the app derives the origin from `APP_URL`.

---

## Versioning Rules Per Environment

| Environment | Versioning | Notes |
|-------------|------------|-------|
| **Local** | Optional | Shared bucket is pre-created; versioning not applied by the app. |
| **Staging** | Optional | Same as local; shared bucket is pre-created externally. |
| **Production** | Enabled | Per-company buckets get versioning, encryption, and lifecycle rules from config. |

For **per_company** strategy, the provisioner applies:
- Versioning (if `storage.bucket_config.versioning` is true)
- Encryption (AES256 or aws:kms)
- Lifecycle rules (e.g., noncurrent version expiration, abort incomplete multipart uploads)
- **CORS** — allowed origins default to the origin derived from `APP_URL` (scheme + host). Required for browser presigned uploads. The IAM role that creates/updates buckets must have `s3:PutBucketCORS`.

For **shared** strategy, the bucket is assumed to exist; the provisioner does not create it or modify versioning/encryption/lifecycle/CORS.

---

## Explicit Rules

### 1. Buckets Are Never Auto-Created on Tenant Creation

- Tenant creation does **not** create or provision S3 buckets.
- Bucket provisioning is **lazy** and happens on first use (e.g., first upload).
- No automatic dispatch of `ProvisionCompanyStorageJob` on tenant creation.

### 2. Bucket Creation Must Be Idempotent

- Calling provision multiple times for the same tenant must be safe.
- If a bucket (or bucket record) already exists, the provisioner returns it without creating a duplicate.
- Shared strategy: returns existing `StorageBucket` record if one exists for that tenant.
- Per-company strategy: verifies configuration and returns existing bucket; creates the S3 bucket only if it does not exist.

### 3. Existing Tenants May Not Yet Have Buckets

- Tenants created before bucket provisioning was introduced may have no `StorageBucket` record.
- Code that requires a bucket must handle the “no bucket” case and provision on demand or fail with a clear error.
- Upload and other asset operations trigger provisioning when a bucket is needed.

---

## Future Lifecycle Rules

Planned lifecycle policy for per-tenant and shared buckets (not yet implemented).

### Non-Current Version Expiration

When S3 versioning is enabled, overwrites and deletes create non-current (previous) versions. These accumulate and incur storage costs. A **non-current version expiration** lifecycle rule permanently deletes previous object versions after a retention period.

| Policy | NonCurrentDays | Description |
|--------|----------------|-------------|
| **Production** | 90 (proposed) | Retain previous versions for 90 days. Balances recovery (accidental overwrites, restores) with storage cost. |
| **Staging** | 30 (proposed) | Shorter retention. Staging is non-critical; faster cleanup reduces cost. |

### Staging vs Production Retention

| Environment | Retention | Rationale |
|-------------|-----------|-----------|
| **Staging** | Shorter (e.g., 30 days) | Non-production data. Lower compliance and restore requirements. Cost reduction. |
| **Production** | Longer (e.g., 90 days) | Customer data. Higher recovery expectations. Longer retention for accidental overwrites and audit trails. |

### Cost Control Rationale

- **Version bloat**: Every overwrite creates a new version. Without expiration, non-current versions grow unbounded and can exceed current object storage cost.
- **Staging budgets**: Staging often shares a single bucket; shorter retention limits storage growth across many tenants.
- **Production isolation**: Per-tenant production buckets isolate cost; each tenant pays for their retained versions.
- **Abort incomplete uploads**: Lifecycle rule to abort multipart uploads after 7 days prevents orphaned parts that incur cost without usable objects.

---

## Tenant UUID Requirement

Canonical shared storage requires every tenant to have a UUID. The path format depends on it:

**Path format:** `tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/...`

- **UUID is auto-generated** at creation (model-level enforcement).
- **`tenants:ensure-buckets`** automatically repairs tenants with missing UUIDs (self-healing).
- **`uuid` column** is NOT NULL and unique in the database.
- **`tenant_id`** is no longer used in canonical storage paths — UUID provides stable, non-sequential isolation.

---

## Canonical Shared Bucket Structure (Post Refactor)

**Phase 5 + 6:** All shared bucket assets MUST follow this canonical structure:

### Original files
```
tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
```

### Thumbnails
```
tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/thumbnails/{style}/{filename}
```

### Key points
- **Tenant UUID** used for isolation (not `tenant_id`). Provides stable, non-sequential path prefix.
- **Version directory** (`v{version}`) prevents CloudFront stale caching; each version is immutable.
- **No `tenant_id`** in path — deprecated in favor of UUID.
- **No `brand_id`** in path.
- **All assets immutable per version** — new versions get new `v{n}` directory.

### Migration note
Legacy paths (`assets/{tenant_id}/...`, `assets/{tenant_id}/{brand_id}/...`, `uuid_filename` pattern) are **deprecated** and **no longer written**. Existing assets in legacy paths remain readable for backwards compatibility but new uploads, promotions, restores, and thumbnail writes use the canonical structure only.

---

## Tenant-Scoped CDN Authorization

CloudFront signed cookies are **tenant-scoped** for defense-in-depth isolation.

### Policy scope
- **Resource:** `https://{cdn-domain}/tenants/{tenant_uuid}/*`
- **Not wildcard:** No `https://{cdn-domain}/*` — access is limited to the active tenant's path only.

### Behavior
- Cookies regenerate automatically when the user switches tenants.
- Session stores `cdn_tenant_uuid`; mismatch triggers regeneration.
- No wildcard CDN access — even if the app leaks a path, CloudFront blocks cross-tenant requests.

### Defense-in-depth
- CDN-level enforcement: CloudFront validates the signed policy before serving content.
- Tenant isolation at the edge, independent of application logic.

### Local environment
- Signing is skipped entirely when `APP_ENV=local`.
- `cdn_url()` returns S3/MinIO URLs directly (no CloudFront).

### CloudFront custom error (403)
When CloudFront returns 403 (expired signed URL, invalid policy, etc.), serve a branded page instead of the AWS default:

- **Response page path:** `/cdn-access-denied`
- **HTTP response code:** 403
- **TTL:** 0

Configure in CloudFront distribution → Error pages → Create custom error response:
- HTTP error code: 403
- Customize error response: Yes
- Response page path: `/cdn-access-denied`
- HTTP response code: 403
- Error caching TTL: 0

Do not expose S3 or AWS error details to users.

---

## Admin routes: signed URLs (no cookies)

Admin asset grid and operation-style previews use **CloudFront signed URLs** (canned policy), not signed cookies. This avoids cookie race conditions and header overflow when loading many tenant thumbnails at once.

### Behavior
- **No cookies:** Admin routes do not set CloudFront signed cookies; `EnsureCloudFrontSignedCookies` skips attachment when in admin multi-tenant context.
- **Per-URL signing:** Each thumbnail (or preview) gets a signed URL with `Expires`, `Signature`, and `Key-Pair-Id` query params. Generated by `AssetUrlService::getSignedCloudFrontUrl()` using `Aws\CloudFront\UrlSigner`.
- **Shorter TTL:** Admin signed URL lifetime is configurable; default **300 seconds** (5 min). Set via `config('cloudfront.admin_signed_url_ttl')` or env `CLOUDFRONT_ADMIN_SIGNED_URL_TTL`.

### Redis cache
- Signed URLs are cached to avoid regenerating for large grids.
- **Cache key:** `admin:signed_url:{asset_id}:{asset.updated_at.timestamp}` so cache invalidates when the asset changes.
- **Cache TTL:** 240 seconds. Must be **shorter** than the signed URL TTL so URLs are never served after expiry.

### 403 observability
- Middleware **`LogCloudFront403`** is registered for admin routes only (`app/admin*`).
- When the app returns **403** and the request Host matches `config('cloudfront.domain')`, a warning is logged with:
  - full URL
  - `Expires` query param
  - current timestamp
  - user id (if authenticated)
  - request id (e.g. `X-Request-ID`)

Use logs to detect CloudFront rejections (expired URL, invalid signature, or misconfiguration) without changing public cookie-based flow.

### Config (admin only)
- `config/cloudfront.php`: `admin_signed_url_ttl` (default 300).
- Public/tenant flows are unchanged: signed cookies and `authenticated_cookie_ttl` / cookie expiry apply as before.

---

## CDN-Only Asset Delivery

**Phase 7:** All asset previews (thumbnails, originals) are delivered via CloudFront. The app-level proxy has been removed.

### Architecture
- **No backend streaming:** Laravel no longer streams thumbnails or originals from S3.
- **CDN URLs only:** All preview URLs use `cdn_url()` → CloudFront (staging/prod) or S3 (local).
- **Tenant isolation:** Signed cookies scope access to `https://{cdn-domain}/tenants/{tenant_uuid}/*`.
- **Cross-tenant blocked:** CloudFront returns 403 for paths outside the active tenant.

### Asset model
- `original_url` — CDN URL for the original file.
- `thumbnail_url` — CDN URL for medium/thumb thumbnail.
- `thumbnailUrl($style)` — CDN URL for a specific style (thumb, medium, large, preview).

### Deprecated routes
- `/app/assets/{uuid}/thumbnail/*` — **410 Gone.** Use CDN URLs from asset payload.
- `/app/assets/{uuid}/original` — Not used; originals use `original_url`.

### Local development
- `cdn_url()` returns S3/MinIO URLs directly (no CloudFront, no signing).
- Thumbnails and originals load from local storage.

---

## Summary

| Rule | Description |
|------|-------------|
| No auto-create on tenant creation | Buckets are provisioned lazily, not when tenants are created. |
| Idempotent provisioning | Provisioning can be retried safely; existing buckets are reused. |
| Tenants may lack buckets | Existing tenants may have no bucket; code must provision or handle missing buckets. |


---

## Asset upload strategy


## Problem
When users upload assets via the Builder (Brand Materials, Visual References), those assets need a clear home. They should not "float in space."

## Recommended Approach: Builder-Staged + Optional Add-to-Library

### 1. Upload Flow
- **On upload**: Create asset with `builder_staged: true`, `builder_context: <context>` (brand_material, visual_reference, etc.)
- **Pivot**: Attach to draft via `brand_model_version_assets` with same `builder_context`
- **Category**: Leave `category_id` null (uncategorized) OR assign to a "Builder Uploads" / "Uncategorized" category
- **Publication**: Leave unpublished (`published_at` null) until user explicitly publishes

### 2. Where They Appear
- **Builder modal/selector**: Always show builder-staged assets for the brand (filter by brand_id + builder_staged OR by pivot)
- **Main asset grid**: Option A: Exclude builder-staged from default view. Option B: Show in a "From Builder" or "Uncategorized" section
- **Assets library**: Add a filter/tab "Builder uploads" that shows assets with `builder_staged = true` and/or `category_id` null

### 3. Lifecycle Options

| Option | Pros | Cons |
|--------|------|------|
| **A) Unpublished until manual publish** | Explicit control, no accidental exposure | Extra step; user must go to Assets to publish |
| **B) Auto-publish on Guidelines publish** | Seamless | May expose unfinished assets |
| **C) "Add to library" on Publish** | User chooses; clear intent | Extra UI in publish flow |

**Recommendation: Option A** — Keep unpublished. When user goes to Assets, they see builder uploads in "Uncategorized" or a "Builder" filter. They can then categorize, publish, or discard. No automatic exposure.

### 4. Implementation Notes
- `builder_staged` already exists on upload session
- Ensure finalize flow sets `metadata.builder_staged = true` on asset
- Asset grid: add `?lifecycle=unpublished` or `?source=builder` filter
- Consider a "Builder uploads" category (slug: `builder-uploads`) — auto-assign on upload, user can move later


---

## Upload storage limits


## Overview

This implementation adds comprehensive storage limit checking and user warnings before file uploads. It prevents users from exceeding their plan's storage limits and provides clear feedback about usage and restrictions.

## Features

### 🛡️ **Plan-Based Storage Limits**
- Different storage limits per plan (Free: 100MB, Starter: 1GB, Pro: ~10GB, Enterprise: ~1TB)
- Real-time usage calculation based on visible assets
- Automatic enforcement during upload initiation

### ⚠️ **Pre-Upload Validation**
- Check files before upload starts
- Validate both individual file size limits and total storage limits
- Batch validation for multiple files
- Clear error messages for different limit types

### 🎨 **User-Friendly Warnings**
- Visual storage usage indicators
- Progressive warning levels (info → warning → error)
- Detailed breakdown of storage usage
- Upgrade prompts when limits are reached

### 🔄 **Real-Time Feedback**
- Live validation as users select files
- Immediate feedback without server round-trips for basic checks
- Server validation for accurate storage calculations

## Backend Implementation

### 1. **PlanService Extensions**

Added new methods to `app/Services/PlanService.php`:

```php
// Get current storage usage in bytes
public function getCurrentStorageUsage(Tenant $tenant): int

// Get storage usage as percentage of plan limit  
public function getStorageUsagePercentage(Tenant $tenant): float

// Check if adding a file would exceed limits
public function canAddFile(Tenant $tenant, int $fileSizeBytes): bool

// Get comprehensive storage information
public function getStorageInfo(Tenant $tenant): array

// Enforce storage limits (throws exception if exceeded)
public function enforceStorageLimit(Tenant $tenant, int $additionalBytes): void
```

### 2. **Upload Validation Integration**

Enhanced `app/Services/UploadInitiationService.php`:

```php
protected function validatePlanLimits(Tenant $tenant, int $fileSize): void
{
    // Check individual file size limit
    $maxUploadSize = $this->planService->getMaxUploadSize($tenant);
    if ($fileSize > $maxUploadSize) {
        throw new PlanLimitExceededException(/*...*/);
    }

    // Check total storage limit
    $this->planService->enforceStorageLimit($tenant, $fileSize);
}
```

### 3. **New API Endpoints**

Added to `app/Http/Controllers/UploadController.php`:

#### `GET /app/uploads/storage-check`
Returns current storage information:
```json
{
  "storage": {
    "current_usage_mb": 245.47,
    "max_storage_mb": 1024,
    "usage_percentage": 23.97,
    "remaining_mb": 778.53,
    "is_unlimited": false,
    "is_near_limit": false,
    "is_at_limit": false
  },
  "limits": {
    "max_upload_size_mb": 50
  },
  "plan": {
    "name": "pro"
  }
}
```

#### `POST /app/uploads/validate`
Validates specific files before upload:
```json
{
  "files": [
    {
      "file_name": "large-image.jpg",
      "file_size": 52428800,
      "can_upload": false,
      "errors": [
        {
          "type": "file_size_limit",
          "message": "File size (50 MB) exceeds maximum upload size (10 MB) for your plan."
        }
      ]
    }
  ],
  "batch_summary": {
    "total_files": 1,
    "total_size_mb": 50,
    "can_upload_batch": false,
    "storage_exceeded": true
  }
}
```

## Frontend Implementation

### 1. **React Hook: `useStorageLimits`**

Located at `resources/js/hooks/useStorageLimits.js`:

```jsx
const {
  storageInfo,
  isLoading,
  validateFiles,
  canUploadFile,
  canUploadFiles,
  isNearStorageLimit,
  isAtStorageLimit
} = useStorageLimits()
```

### 2. **Storage Warning Component**

`resources/js/Components/StorageWarning.jsx` displays:
- Current storage usage with visual progress bar
- Projected usage when files are selected
- Warning levels (info, warning, error)
- Upgrade prompts when needed

### 3. **Upload Gate Component**

`resources/js/Components/UploadGate.jsx` provides:
- Automatic file validation
- Error/warning display
- Upload prevention when limits exceeded
- Integration with existing upload dialogs

### 4. **Integration Example**

See `resources/js/Components/Examples/UploadDialogWithGate.jsx` for a complete example of how to integrate the upload gate into existing upload dialogs.

## Plan Configuration

Storage limits are configured in `config/plans.php`:

```php
'free' => [
    'limits' => [
        'max_storage_mb' => 100,        // 100 MB
        'max_upload_size_mb' => 10,     // 10 MB per file
    ],
],
'starter' => [
    'limits' => [
        'max_storage_mb' => 1024,       // 1 GB  
        'max_upload_size_mb' => 50,     // 50 MB per file
    ],
],
'pro' => [
    'limits' => [
        'max_storage_mb' => 999999,     // ~1 TB (unlimited)
        'max_upload_size_mb' => 999999, // ~1 TB (unlimited)
    ],
],
```

## Usage Examples

### Basic Integration

```jsx
import UploadGate from '../Components/UploadGate'

function MyUploadDialog({ selectedFiles, onUpload }) {
  const [validationResults, setValidationResults] = useState(null)
  
  return (
    <div>
      {/* File selection UI */}
      
      <UploadGate
        selectedFiles={selectedFiles}
        onValidationChange={setValidationResults}
        autoValidate={true}
        showStorageDetails={true}
      />
      
      <button 
        disabled={!validationResults?.canProceed}
        onClick={onUpload}
      >
        Upload
      </button>
    </div>
  )
}
```

### Manual Validation

```jsx
import { useStorageLimits } from '../hooks/useStorageLimits'

function FileValidator() {
  const { validateFiles, storageInfo } = useStorageLimits()
  
  const handleValidation = async (files) => {
    const results = await validateFiles(files)
    
    if (results.batch_summary.can_upload_batch) {
      // Proceed with upload
    } else {
      // Show errors to user
    }
  }
}
```

### Storage Information Display

```jsx
import StorageWarning from '../Components/StorageWarning'

function StorageUsageWidget() {
  const { storageInfo } = useStorageLimits()
  
  return (
    <StorageWarning
      storageInfo={storageInfo?.storage}
      showDetails={true}
    />
  )
}
```

## Error Handling

The system provides different types of errors:

### File Size Limit Exceeded
- **Type**: `file_size_limit`
- **Cause**: Individual file exceeds plan's max upload size
- **Solution**: Reduce file size or upgrade plan

### Storage Limit Exceeded
- **Type**: `storage_limit`  
- **Cause**: Adding files would exceed total storage limit
- **Solution**: Delete existing assets or upgrade plan

### Batch Storage Exceeded
- **Cause**: Multiple files together exceed available storage
- **Solution**: Upload fewer files at once or upgrade plan

## Testing

### Backend Testing

```php
// Test storage calculation
$planService = new PlanService();
$storageInfo = $planService->getStorageInfo($tenant);

// Test limit enforcement
try {
    $planService->enforceStorageLimit($tenant, $fileSize);
} catch (PlanLimitExceededException $e) {
    // Handle limit exceeded
}
```

### Frontend Testing

```javascript
// Test validation hook
const { validateFiles } = useStorageLimits()
const results = await validateFiles(mockFiles)

// Test storage checking
const { canUploadFile } = useStorageLimits()
const canUpload = canUploadFile(mockFile)
```

## Integration Checklist

To integrate into existing upload dialogs:

- [ ] Import `UploadGate` component
- [ ] Add `selectedFiles` state tracking  
- [ ] Add `validationResults` state handling
- [ ] Disable upload button when `!validationResults?.canProceed`
- [ ] Show storage warnings in dialog
- [ ] Handle validation errors appropriately
- [ ] Test with different plan limits
- [ ] Test with files that exceed limits
- [ ] Test batch uploads
- [ ] Test upgrade flow from warnings

## Future Enhancements

Potential improvements:
- [ ] Real-time storage usage updates via WebSockets
- [ ] File compression suggestions for large files
- [ ] Smart batching recommendations
- [ ] Storage cleanup suggestions
- [ ] Usage analytics and insights
- [ ] Predictive warnings ("at current usage, you'll hit limit in X days")
- [ ] Integration with file optimization services

---

## Upload storage call chain


## Call Chain Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 1. UPLOAD INITIATION                                                                  │
└─────────────────────────────────────────────────────────────────────────────────────┘

  UploadController::initiate() / initiateBatch()
       │
       ▼
  UploadInitiationService::initiate() / initiateBatch()
       │
       ├── PlanService::validatePlanLimits()
       │
       ├── TenantBucketService::getOrProvisionBucket(tenant)  ◄── BUCKET SELECTION
       │         │
       │         ├── Local/Testing: resolveActiveBucketOrFailIfExists() OR provisionBucket()
       │         │         └── CompanyStorageProvisioner::provision() [SYNC in local]
       │         │
       │         └── Staging/Production: resolveActiveBucketOrFail()
       │                   └── StorageBucket::where(tenant_id, name, ACTIVE)->first()
       │                   └── Bucket name from getExpectedBucketName(tenant)
       │
       ├── UploadSession::create([storage_bucket_id => $bucket->id, ...])
       │
       ├── generateTempUploadPath() → "temp/uploads/{upload_session_id}/original"
       │
       └── Direct: generateDirectUploadUrl() → presigned PUT
           Chunked: return chunk_size, frontend calls /multipart/init

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 2. MULTIPART INIT (chunked uploads only)                                             │
└─────────────────────────────────────────────────────────────────────────────────────┘

  UploadController::initMultipart()
       │
       ▼
  MultipartUploadService::initiateMultipartUpload(session)
       │
       ├── session->storageBucket (from UploadSession)
       ├── path = "temp/uploads/{session_id}/original"
       └── S3 createMultipartUpload(Bucket, Key)  ◄── S3 WRITE (init)

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 3. PART UPLOADS (chunked)                                                             │
└─────────────────────────────────────────────────────────────────────────────────────┘

  UploadController::getPartUploadUrl()
       │
       ▼
  MultipartUploadUrlService::generatePartUploadUrl(session, partNumber)
       │
       ├── session->storageBucket
       ├── path = "temp/uploads/{session_id}/original"
       └── Return presigned UploadPart URL

  [Frontend uploads bytes directly to S3 via presigned URL]  ◄── S3 WRITE (parts)

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 4. UPLOAD COMPLETION (finalize)                                                        │
└─────────────────────────────────────────────────────────────────────────────────────┘

  UploadController::finalize()
       │
       ▼
  UploadCompletionService::complete(session, ...)
       │
       ├── Chunked: finalizeMultipartUpload() → S3 CompleteMultipartUpload  ◄── S3 WRITE (assemble)
       │
       ├── storagePath = "temp/uploads/{session_id}/original" (stored on Asset)
       │
       ├── Asset::create([storage_root_path => storagePath, storage_bucket_id => session->storage_bucket_id])
       │
       ├── AssetVersionService::createVersion() with path "assets/{asset_id}/v1/original.{ext}"
       │   └── copyTempToVersionedPath() → S3 copyObject  ◄── S3 WRITE (copy to versioned)
       │
       └── event(AssetUploaded) → ProcessAssetJob chain → FinalizeAssetJob → PromoteAssetJob

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 5. PROMOTION (PromoteAssetJob)                                                         │
└─────────────────────────────────────────────────────────────────────────────────────┘

  PromoteAssetJob::handle()
       │
       ├── sourcePath = asset->storage_root_path (temp/... or assets/{id}/v1/...)
       ├── canonicalPath = "assets/{tenant_id}/{asset_id}/original.{ext}"  ◄── KEY WITH TENANT PREFIX
       │
       └── S3 copyObject + deleteObject  ◄── S3 WRITE (move to canonical)

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 6. CLEANUP                                                                             │
└─────────────────────────────────────────────────────────────────────────────────────┘

  UploadCleanupService::cleanupExpiredAndTerminal()
       │
       ├── UploadSession::whereIn(FAILED, CANCELLED) + where(expires_at < now)
       │
       └── For each session:
             ├── bucket = session->storageBucket  ◄── Uses session's bucket (NOT tenant lookup)
             ├── path = "temp/uploads/{session_id}/original"
             ├── deleteObject(Bucket, Key)
             └── abortMultipartUpload() if multipart_upload_id
```

---

## Answers to Key Questions

### 1. Where is bucket name selected?

| Location | Service | Method |
|----------|---------|--------|
| **Primary** | `TenantBucketService` | `getExpectedBucketName()` / `getBucketName()` |
| **Local** | Returns `config('storage.shared_bucket')` (AWS_BUCKET) |
| **Staging/Prod** | Returns `generateBucketName(tenant)` → `jackpot-{env}-{company_slug}` |
| **Provisioning** | `CompanyStorageProvisioner` | `provision()` → `provisionPerCompany()` or `provisionShared()` |

### 2. Does key include tenant prefix?

| Path Type | Format | Tenant Prefix? |
|-----------|--------|----------------|
| **Temp upload** | `temp/uploads/{upload_session_id}/original` | **No** |
| **Versioned (new flow)** | `assets/{asset_id}/v{version}/original.{ext}` | **No** |
| **Canonical (PromoteAssetJob)** | `assets/{tenant_id}/{asset_id}/original.{ext}` | **Yes** |
| **Legacy permanent** | `assets/{tenant_id}/{brand_id}/{uuid}_{filename}` | **Yes** |

### 3. Is bucket provisioning synchronous?

| Environment | From Web Request | From Console/Queue |
|-------------|------------------|---------------------|
| **Local/Testing** | Yes (getOrProvisionBucket can provision) | Yes |
| **Staging/Production** | **No** — resolve only; throws if missing | Yes (TenantsEnsureBucketsCommand, ProvisionCompanyStorageJob) |

Guard: `TenantBucketService::provisionBucket()` throws `BucketProvisioningNotAllowedException` if called from web in staging/production.

### 4. Does UploadCleanupService assume bucket per tenant?

**No.** It uses `$uploadSession->storageBucket` — the bucket linked to each session. It works with:
- **Per-tenant buckets**: Each session has its tenant's bucket
- **Shared bucket**: All sessions point to the same shared bucket

Cleanup iterates by `UploadSession`, not by tenant. No tenant→bucket resolution.

---

## Shared Bucket Migration: Impact Summary

**Goal:** Shared S3 bucket for all plans except Enterprise. Enterprise keeps per-tenant buckets (current staging behavior).

### Components That Must Change

| Component | Current Behavior | Change Required |
|-----------|------------------|-----------------|
| **TenantBucketService** | `getExpectedBucketName()`: local=shared, staging/prod=per-tenant | Add plan check: Enterprise → per-tenant; else → shared |
| **CompanyStorageProvisioner** | `provision()`: shared or per_company by config | Add plan-aware logic: Enterprise → provisionPerCompany; else → provisionShared |
| **TenantsEnsureBucketsCommand** | Provisions per tenant | Enterprise tenants: per-tenant; others: ensure shared bucket record exists |
| **Storage config** | `provision_strategy`, `shared_bucket` | May need plan-based override or new config keys |
| **PlanService** | N/A | Add `isEnterprisePlan(tenant)` or equivalent |

### Components That Need Review (Likely OK)

| Component | Notes |
|-----------|-------|
| **UploadInitiationService** | Uses `getOrProvisionBucket(tenant)` — no change if TenantBucketService returns correct bucket |
| **UploadCompletionService** | Uses session's bucket — no change |
| **MultipartUploadService** | Uses session's bucket — no change |
| **MultipartUploadUrlService** | Uses session's bucket — no change |
| **UploadCleanupService** | Uses session's bucket — no change |
| **PromoteAssetJob** | Uses asset's bucket — no change |
| **TenantBucketService (read ops)** | getPresignedGetUrl, headObject, etc. — use bucket from Asset/context |

### Key Path Change for Shared Bucket

**Current (per-tenant):** Isolation via bucket. Keys can be `assets/{tenant_id}/{asset_id}/...` or `assets/{asset_id}/v1/...`.

**Shared bucket:** Isolation must be via **key prefix**. The canonical path `assets/{tenant_id}/{asset_id}/original.{ext}` already includes tenant_id — **no key format change needed**. Tenant isolation is preserved by the key prefix.

### Migration Considerations

1. **Existing per-tenant buckets:** Enterprise tenants keep them. Non-enterprise: migrate objects to shared bucket with same key structure, or keep per-tenant buckets for existing data and use shared for new uploads (dual-write during transition).
2. **StorageBucket table:** Each tenant has a row. For shared: multiple tenants can share the same `name` (bucket name) with different `tenant_id` — `provisionShared()` already creates one row per tenant pointing to the same bucket name.
3. **IAM:** Shared bucket needs policy allowing all tenants' access patterns. Per-tenant IAM can remain for Enterprise.
4. **CORS:** Shared bucket CORS must allow all tenant app origins (APP_URL per tenant or wildcard).
5. **Lifecycle rules:** Shared bucket gets one set of rules for all tenants; per-tenant rules stay for Enterprise.

---

## Files to Modify for Shared-Bucket Migration

| File | Change |
|------|--------|
| `app/Services/TenantBucketService.php` | Plan check in `getExpectedBucketName()` / `getBucketName()` |
| `app/Services/CompanyStorageProvisioner.php` | Plan check in `provision()` |
| `app/Console/Commands/TenantsEnsureBucketsCommand.php` | Enterprise vs non-enterprise provisioning logic |
| `config/storage.php` | Optional: plan-based strategy config |
| `app/Services/PlanService.php` | Add `isEnterprisePlan(Tenant)` if not present |
| `docs/STORAGE.md` | Update with shared-bucket-by-plan behavior |

---

## UploadSignalService Note

`UploadSignalService` only emits structured log signals for upload errors. It does not touch storage, buckets, or keys. No changes needed for the migration.
