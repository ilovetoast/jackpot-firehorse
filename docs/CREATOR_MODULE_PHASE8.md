# Creator module — Phase 8: Billing + feature gating

Phase 8 introduces **`tenant_modules`**, a tenant-scoped registry for **paid feature add-ons** (starting with the Creator / Prostaff module). This is separate from **storage add-ons**, which stay on the `tenants` table (`storage_addon_mb`, Stripe subscription item IDs) — that path is unchanged.

Future monthly Shopify-style add-ons can use the same `tenant_modules` pattern with distinct `module_key` values.

## Table: `tenant_modules`

| Column | Type | Notes |
|--------|------|--------|
| `id` | bigint | |
| `tenant_id` | FK → tenants | cascade on delete |
| `module_key` | string | e.g. `creator_module` ({@see TenantModule::KEY_CREATOR}) |
| `status` | string | `active`, `trial`, `expired`, `cancelled` |
| `expires_at` | timestamp nullable | When set, access ends after this instant (see gate below) |
| `granted_by_admin` | boolean default false | Manual override; **must** set `expires_at` |
| timestamps | | |

**Unique:** `(tenant_id, module_key)`.

## Module lifecycle

- **No row:** Creator / Prostaff features are **off** (assign, prostaff uploads, dashboards, DAM prostaff filter options).
- **`active` / `trial`:** On if `expires_at` is null **or** `expires_at` is strictly in the future.
- **`expired` / `cancelled`:** Off regardless of `expires_at`.
- **Admin override:** `granted_by_admin = true` requires a non-null `expires_at` (enforced in {@see TenantModule} `saving`). The feature gate also treats admin rows with missing expiry as **off** if data were inserted outside Eloquent.

When access ends:

- **`prostaff_memberships` and assets are not deleted** (per product rules).
- **Uploads** that would be treated as prostaff (uploader is active prostaff for the brand) are **blocked** with a clear error.
- **Dashboard** and **`/prostaff/options`** APIs are blocked or empty.
- **DAM query params** `submitted_by_prostaff` / `prostaff_user_id` are **ignored** when the module is off (filters do not apply).

**Approval queue** behavior is unchanged (no approval-logic edits in this phase).

## Feature gate

`FeatureGate::creatorModuleEnabled(Tenant $tenant): bool`

- Loads the row for `module_key = creator_module`.
- Rejects invalid admin rows (`granted_by_admin` + null `expires_at`).
- Then requires a matching row that passes {@see TenantModule::scopeActive()} (`status` in `active`/`trial`, and `expires_at` null or in the future).

### Centralized throw helper

`App\Services\Prostaff\EnsureCreatorModuleEnabled::assertEnabled(Tenant $tenant)` throws `DomainException` with message `Creator module is not active for this tenant.` when the gate is false. Use this in services and HTTP layers that should fail closed (assign, upload/replace, dashboard JSON). Read-only branches (e.g. asset grid filter params) still use `creatorModuleEnabled()` for a boolean.

## Enforcement points

| Area | Behavior |
|------|----------|
| {@see AssignProstaffMember} | {@see EnsureCreatorModuleEnabled::assertEnabled()} → `DomainException` |
| {@see UploadCompletionService} | Same guard on new completes and prostaff replace paths when uploader is prostaff |
| {@see ProstaffDashboardController} | `index` / `me`: 403 JSON (same message); `filterOptions`: `[]` |
| {@see GetProstaffDamFilterOptions} | Guard → returns `[]` when disabled |
| {@see AssetController} | Prostaff filter query params only apply when `creatorModuleEnabled()` |

## Billing / Shopify (future)

- **No Stripe integration in this phase** — structure only.
- Intended flow: subscription webhooks or admin jobs **upsert** `tenant_modules` (status, `expires_at`) when an add-on is purchased, renewed, or cancelled.
- Aligns conceptually with monthly plan add-ons; implementation can mirror storage add-on **billing ops** while keeping **entitlement** in `tenant_modules`.

## Tests

`tests/Feature/CreatorModuleGateTest.php` — active, trial, expired, admin grant, invalid admin row, assign/upload/dashboard/options behavior.

Shared tests use `TestCase::enableCreatorModuleForTenant()` so existing Prostaff feature tests keep passing when a module row is required.

## Model

- `App\Models\TenantModule` — `scopeActive()` for entitled rows (status + expiry).
- `Tenant::modules()` — `hasMany(TenantModule::class)`

### Prostaff tier (reserved)

`prostaff_memberships.tier` — nullable string, indexed. No application logic yet; reserved for future targets, perks, and segmentation.
