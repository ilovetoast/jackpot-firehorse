# Upload → S3 Write: Full Call Chain & Storage Change Impact

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
| `docs/s3-bucket-strategy.md` | Update with shared-bucket-by-plan behavior |

---

## UploadSignalService Note

`UploadSignalService` only emits structured log signals for upload errors. It does not touch storage, buckets, or keys. No changes needed for the migration.
