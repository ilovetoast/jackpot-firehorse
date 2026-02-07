# S3 Bucket Strategy

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

## Summary

| Rule | Description |
|------|-------------|
| No auto-create on tenant creation | Buckets are provisioned lazily, not when tenants are created. |
| Idempotent provisioning | Provisioning can be retried safely; existing buckets are reused. |
| Tenants may lack buckets | Existing tenants may have no bucket; code must provision or handle missing buckets. |
