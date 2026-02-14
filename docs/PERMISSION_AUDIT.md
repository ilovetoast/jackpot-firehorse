# Permission System Audit (Post Step 1–6)

## A. Tenant Scoping ✅

**Status: Correct**

- **Tenant roles** come from `getRoleForTenant($tenant)` which queries `tenant_user` with `tenant_id`. Tenant roles are **not** stored in Spatie; they live in the `tenant_user` pivot.
- **Spatie roles** (`$user->roles`) are **site roles only** (site_admin, site_owner, etc.). Spatie `teams` is `false`, so these are global by design.
- **No cross-tenant leakage**: Admin in Tenant A vs Member in Tenant B is handled by `getRoleForTenant($tenant)` — we always use the role for the **current** tenant.

## B. Brand Role Retrieval ✅

**Status: Fixed**

- `getRoleForBrand($brand)` uses `activeBrandMembership()` which:
  - Verifies tenant membership first (`$this->tenants()->where('tenants.id', $tenant->id)`)
  - Queries `brand_user` with `user_id` and `brand_id`
  - Checks `removed_at IS NULL` for active membership
- **Hardening added**: `AuthPermissionService` now verifies `$brand->tenant_id === $tenant->id` before applying brand permissions. This prevents cross-tenant brand context injection.

## C. Site Roles ✅

**Status: Consistent**

- **Site role permissions** are included in `effective_permissions` via `$user->roles` (Spatie) in `AuthPermissionService::effectivePermissions()`.
- **Nav visibility** uses `auth.user.site_roles` for Admin Dashboard (site_admin/site_owner) — intentional separation for that specific UI element.
- **Both paths work**: Site admins have permissions in `effective_permissions` AND `site_roles` for nav.

## D. Unified Backend Gate ✅

**Status: Implemented**

- **`AuthPermissionService::can($user, $permission, $tenant, $brand)`** — single entry point for backend permission checks.
- **Usage**: Controllers, policies, jobs, and API endpoints should use:
  ```php
  app(AuthPermissionService::class)->can($user, 'team.manage', $tenant, $brand)
  ```
- **Migration note**: Some code still uses `$user->can()`, `$user->hasPermissionTo()`, or `$user->hasPermissionForTenant()`. These may bypass brand/tenant context. Prefer `AuthPermissionService::can()` for consistency.

## E. Migration Status

### Completed
- **User::canForContext($permission, $tenant, $brand)** — Clean helper for controllers/policies
- **TeamController** — All `hasPermissionForTenant('team.manage')` replaced with `canForContext('team.manage', $tenant, null)`
- **End-to-end test** — `TeamManagementPermissionTest` hits `/app/companies/team`, asserts 403 for member and 200 for admin/owner

### Remaining (use `$user->canForContext($permission, $tenant, $brand)` as you touch code)
- Controllers: CompanyController, BrandController, AssetMetadataController, etc. (many `hasPermissionForTenant` calls)
- Policies: BrandPolicy, CategoryPolicy, CompanyPolicy (use `canForContext` where tenant/brand context matters)
