# Storage and S3

Buckets, upload strategy, limits, and storage call chain.

---

This document describes how the app resolves S3 buckets and object keys across environments. **Source of truth in code:** `TenantBucketService`, `CompanyStorageProvisioner`, and `Tenant::hasDedicatedInfrastructure()`.

---

## Bucket strategy (actual implementation)

### Default: one shared bucket per environment + tenant-prefixed keys

For **local**, **staging**, and **production**, a normal tenant uses a **single shared bucket** whose name comes from **`AWS_BUCKET`** (also exposed as `config('storage.shared_bucket')`). **Tenant isolation is not by bucket name**; it is by **object key prefix**, primarily:

`tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/...`

See **Canonical Shared Bucket Structure** below. Staging and production are intended to follow the same pattern: **one AWS bucket per app environment** (e.g. staging bucket vs production bucket), **not** one bucket per customer, unless the tenant is on dedicated infrastructure.

Each tenant still has a row in **`storage_buckets`** pointing at that shared bucket name (`name` = `AWS_BUCKET`) so uploads and assets resolve a bucket record per tenant without creating a new S3 bucket per company.

### Exception: dedicated bucket (Enterprise path — started, not plan-gated yet)

A tenant may use a **dedicated S3 bucket** when **`tenants.infrastructure_tier === 'dedicated'`** (`Tenant::hasDedicatedInfrastructure()`). In that case:

- Expected bucket name is generated from **`STORAGE_BUCKET_NAME_PATTERN`** (default `jackpot-{env}-{company_slug}`).
- **`TenantBucketService::getOrProvisionBucket()`** routes to **`getOrProvisionBucketDedicated()`** / **`provisionPerCompany()`** instead of the shared bucket.
- **`local`** always behaves as shared for dedicated checks (`hasDedicatedInfrastructure()` is forced false on local).

There is **no** Stripe/plan check in code yet tying “Enterprise” to `infrastructure_tier`; that is the intended direction. Until then, dedicated buckets are **manual / ops** (column set per tenant).

The **`STORAGE_PROVISION_STRATEGY`** value in `config/storage.php` defaults to `shared` and is **not** the primary router for provisioning—**`infrastructure_tier` + `TenantBucketService`** are. It may still appear in admin/status UIs for visibility.

---

## Bucket naming (when dedicated)

**Pattern (dedicated only):** `STORAGE_BUCKET_NAME_PATTERN`, default **`jackpot-{env}-{company_slug}`**

Placeholders: `{env}`, `{company_id}`, `{company_slug}`. S3 rules: lowercase, 3–63 characters, alphanumeric and hyphens only.

Examples: `jackpot-staging-acme`, `jackpot-production-velvet-hammer`

This matches IAM resource patterns such as `arn:aws:s3:::jackpot-staging-*` **when** those buckets exist for dedicated tenants.

### Local
- Shared bucket: **`AWS_BUCKET`** (e.g. `dam-local-shared`).
- MinIO or LocalStack as needed.

### Staging and production (typical / default)
- **`AWS_BUCKET`** = one shared bucket for that environment (e.g. staging app → one staging bucket).
- **`APP_URL`** = app origin; included in CORS defaults (`config/storage.php` `cors_allowed_origins` also lists known hosts; override with **`STORAGE_CORS_ORIGINS`** comma-separated).
- Optional: **`STORAGE_CORS_ORIGINS`** for extra browser upload origins.

### Dedicated (Enterprise-style)
- Bucket name from pattern above; provisioned via **`CompanyStorageProvisioner::provisionPerCompany()`** (console/queue only in staging/production).

---

## Shared vs dedicated (quick reference)

| Mode | Condition | Bucket name source | Object isolation |
|------|-----------|--------------------|------------------|
| **Shared** (default) | `infrastructure_tier` not `dedicated` (or local) | `AWS_BUCKET` / `storage.shared_bucket` | Key prefix `tenants/{tenant_uuid}/...` |
| **Dedicated** | `infrastructure_tier === 'dedicated'` (non-local) | `STORAGE_BUCKET_NAME_PATTERN` → `TenantBucketService::generateBucketName()` | Same key layout; separate S3 bucket |

---

## Versioning and bucket configuration

| Case | Versioning / encryption / lifecycle / CORS |
|------|------------------------------------------|
| **Shared bucket** | The bucket is **assumed to exist** in AWS. **`CompanyStorageProvisioner::provisionShared()`** creates only the **`storage_buckets` row** (local/testing) or expects the row in staging/production. **S3 CreateBucket** for the shared bucket is an **ops** concern unless you run **`tenants:ensure-buckets`** / provision flows that call into provisioner helpers that touch S3. In practice, **CORS and lifecycle** for the shared bucket are applied when reconciliation/provisioner runs **`ensureBucketCors`** / **`provision`** paths—treat shared bucket policy as **environment-wide**. |
| **Dedicated bucket** | **`provisionPerCompany()`** creates the bucket if missing and applies versioning, encryption, lifecycle, and CORS from **`config/storage.php`** (`bucket_config`). |

Defaults in **`storage.bucket_config`**: versioning on, AES256 encryption, lifecycle (noncurrent version expiration, abort incomplete multipart). **`ensureBucketExists()`** and dedicated provisioning use these when creating or updating buckets.

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
| Default bucket | **`AWS_BUCKET`** (shared): one bucket per app environment; isolation via **`tenants/{tenant_uuid}/...`** keys. |
| Dedicated bucket | Only when **`infrastructure_tier = dedicated`** (Enterprise path; plan automation not wired yet). |
| No auto-create on tenant creation | Tenant creation does not create S3 buckets; reconciliation/provision on demand or via **`tenants:ensure-buckets`**. |
| Idempotent provisioning | Safe to retry; existing buckets and rows are reused. |
| Tenants may lack bucket rows | Missing **`storage_buckets`** → `BucketNotProvisionedException`; run **`php artisan tenants:ensure-buckets`** on worker. |


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
       │         ├── Shared tenant: resolveSharedBucketOrFail() → row for tenant + name = AWS_BUCKET
       │         ├── Dedicated tenant: getOrProvisionBucketDedicated() → resolveActiveBucketOrFail() / provision (console)
       │         │
       │         ├── Local/Testing: may provision synchronously via CompanyStorageProvisioner::provision()
       │         │
       │         └── Staging/Production web: resolve only (no CreateBucket on web tier)
       │                   └── Expected name from getExpectedBucketName() (shared or generated)
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
       ├── AssetVersionService::createVersion() + pipeline copies/moves as implemented
       │   └── Intermediate keys may exist until promotion
       │
       └── event(AssetUploaded) → ProcessAssetJob chain → FinalizeAssetJob → PromoteAssetJob

┌─────────────────────────────────────────────────────────────────────────────────────┐
│ 5. PROMOTION (PromoteAssetJob)                                                         │
└─────────────────────────────────────────────────────────────────────────────────────┘

  PromoteAssetJob::handle()
       │
       ├── sourcePath = asset->storage_root_path (temp/... or prior layout)
       ├── canonicalPath = "tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}"  ◄── CANONICAL PREFIX
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
| **Shared (default)** | Same | Returns **`config('storage.shared_bucket')`** (`AWS_BUCKET`) |
| **Dedicated** | Same | Returns **`generateBucketName(tenant)`** from **`STORAGE_BUCKET_NAME_PATTERN`** |
| **Provisioning** | `CompanyStorageProvisioner` | `provision()` → **`provisionShared()`** or **`provisionPerCompany()`** based on **`Tenant::hasDedicatedInfrastructure()`** |

### 2. Does key include tenant prefix?

| Path Type | Format | Tenant isolation |
|-----------|--------|------------------|
| **Temp upload** | `temp/uploads/{upload_session_id}/original` | Session-scoped (no tenant UUID in path) |
| **Canonical (current)** | `tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}` | **UUID prefix** |
| **Thumbnails** | `tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/thumbnails/...` | **UUID prefix** |
| **Legacy** | Older `assets/{tenant_id}/...` or brand-prefixed layouts | **Deprecated** for new writes; may still exist for old assets |

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

## Enterprise dedicated buckets (roadmap vs code)

**Implemented today**

- **`tenants.infrastructure_tier`**: `shared` (default) vs `dedicated`.
- **`Tenant::hasDedicatedInfrastructure()`**: non-local tenants with `dedicated` use **`generateBucketName()`** and **`provisionPerCompany()`**; others use **`AWS_BUCKET`** and **`provisionShared()`**.
- **`tenants.storage_mode`**: updated to `shared` or `dedicated` when resolving buckets (`getOrProvisionBucket`).

**Not implemented yet (intended)**

- Automatic promotion to **`infrastructure_tier = dedicated`** from Stripe plan / “Enterprise” product.
- Plan-based branching in **`PlanService`** instead of manual DB column.

**Operational**

- **Staging and production** default deployments should use **one shared bucket per environment** + **`tenants:ensure-buckets`** so every tenant has an ACTIVE **`storage_buckets`** row for that bucket name.
- **Dedicated** customers: set **`infrastructure_tier`** to **`dedicated`**, ensure IAM can create/manage `jackpot-{env}-{slug}` buckets, run provisioning from **console/queue** (not web).

---

## Legacy doc: “shared migration” checklist (historical)

The following was written when the code still assumed per-environment per-tenant buckets. **Runtime behavior now matches shared-by-default + optional dedicated.** Remaining work is **plan gating** and any **data migration** for tenants that still have old bucket rows or legacy key layouts—not switching the whole app from shared to per-tenant.

| Area | Status |
|------|--------|
| Shared bucket + tenant UUID prefix | **Canonical for new objects** |
| `StorageBucket` row per tenant, same `name` for shared | **Supported** (`provisionShared`) |
| Enterprise = automatic dedicated tier | **Pending** (use `infrastructure_tier` manually until then) |

---

## UploadSignalService Note

`UploadSignalService` only emits structured log signals for upload errors. It does not touch storage, buckets, or keys. No changes needed for the migration.
