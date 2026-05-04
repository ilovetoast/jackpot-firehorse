# Permissions and roles

Roles, company settings permissions, audit notes, and future tenant-level permission work.

---


## Overview

The Jackpot DAM uses a **canonical role system** with three completely separate role layers:
1. **Site-wide roles** (site_owner, site_admin, site_support, site_engineering, site_compliance)
2. **Tenant/Company Roles**: Control what a user can do at the company level (Spatie roles)
3. **Brand Roles**: Control what a user can do for a specific brand's assets (stored as strings)

These layers **MUST NOT leak into each other**. Tenant and brand role keys use `RoleRegistry`; site-wide Spatie role keys use `RoleRegistry::siteRoles()` (canonical name `site_engineering`, not `site_engineer`).

**Download links:** The permission `downloads.share_public_link` controls who may set a download to **public** (unauthenticated) access. It is included for standard tenant roles (owner, admin, member, agency roles) and for brand roles admin / brand_manager / contributor—not for brand **viewer**. External collection guests are always blocked from public links in code regardless of role. See [SECURITY_DOWNLOADS_AND_EXTERNAL_ACCESS.md](./SECURITY_DOWNLOADS_AND_EXTERNAL_ACCESS.md).

---

## Tenant/Company Roles

**What is this person allowed to do at the company level?**

Tenant roles are Spatie roles assigned via the `tenant_user` pivot table.

### Available Tenant Roles

| Role | Description | Use Case |
|------|-------------|----------|
| **Owner** 👑 | Full company access | Company founder, primary administrator |
| **Admin** | Company administration | Can manage company settings, users, and brands |
| **Member** | Basic company membership | Default role for new users |
| **Agency admin** | Client-granted company access for a **linked agency** | Agency staff working in a client company’s workspace—treated like **admin** for most company permissions (see `PermissionMap`) |
| **Agency partner** | Limited company access after certain agency↔client flows | Asset-oriented access: **no** company settings, billing, or team management (narrower than admin) |

### Agency roles and relationships (short)

These are **tenant (company) roles** on a **client** company (`tenants` row), not roles on the agency’s own tenant.

- **Agency tenant vs client tenant:** An **agency** is a company whose tenant has `is_agency = true`. **Client** companies link to that agency through the **agency partnership** (configured under **Company settings → Agencies**). The same person can switch workspace between the agency tenant and linked clients (e.g. agency nav / brand switcher).
- **Why they exist:** Clients can grant people from a partner agency access to their **company** without making them a full **Admin**. **Agency admin** is the strong grant (broadly aligned with **admin** in `PermissionMap`). **Agency partner** is a lighter, asset-focused footprint (no `team.manage` / company settings stack).
- **Agency-managed membership:** Users added **by the agency** to a client may be flagged on `tenant_user` (e.g. `is_agency_managed`, `agency_tenant_id`) so the **Team** page and policies can treat them as “under the partnership” rather than a one-off direct hire. **Brand roles** (`brand_user.role`) still define what they can do **per brand** (upload, approve, etc.).
- **Effective permissions:** Always resolve from **`RoleRegistry`** + **`PermissionMap`** via **`TenantPermissionResolver`** / **`AuthPermissionService`**—do not infer from the word “agency” alone.

### Where tenant roles are managed (strict split)

| Surface | What you manage | Tenant role keys involved |
|--------|------------------|---------------------------|
| **Company → Team** | **Direct** company members only | **Admin**, **Member** on the client (or **Admin**, **Member** on the agency workspace when the active tenant is an agency). **Owner** is never assignable here. |
| **Company settings → Agencies** | **Client ↔ agency partnership** (`TenantAgency`) | **`agency_admin`**, **`agency_partner`** as *relationship grants* on the **client** tenant for users provisioned through the link. Only **client owner** or **client admin** may create/update/remove partnerships (not client-side `agency_admin`). |
| **Agency Dashboard → Team** | Read/copy for agency context; **Manage team** goes to the same Team page in the **agency** tenant | Agency **workspace** roles are **Admin** / **Member** (and **Owner** via normal rules). This is **not** the same as client-side **`agency_admin`** / **`agency_partner`**, which exist only on **client** tenants. |

- **`agency_admin`** / **`agency_partner`** are **partnership roles**, not generic “invite from Team” roles. The generic `TeamController::invite` and `TeamController::updateTenantRole` routes reject them with **422**; partnership flows use `TenantAgencyService` / `TenantAgencyController` (and related APIs).
- **UI labels:** The internal key **`agency_admin`** is labeled **Agency manager** (or **Agency admin**) in product copy; never show raw strings like `Agency_admin`.
- **Context-aware checks:** Prefer **`$user->canForContext($permission, $tenant, $brand)`** / **`AuthPermissionService::can()`** when tenant or brand context matters.

For product flows (incubation, transfers, rewards), see also [AGENCY_INCUBATION_ROADMAP.md](./AGENCY_INCUBATION_ROADMAP.md).

### Important Notes

- **Owner** is tenant-level only - cannot be assigned as a brand role
- **Owner** cannot be assigned during invitation or via UI - ownership must be transferred via the ownership transfer process in Company Settings
- **Owner** can only be assigned via ownership transfer flow or by platform super-owner (user ID 1) for initial setup
- **Member** is tenant-level only - cannot be assigned as a brand role
- Tenant roles control company-wide permissions (billing, team management, brand settings, etc.)
- Tenant roles are managed via Spatie Permission package
- All role validation uses `RoleRegistry` - no hardcoded arrays

### Tenant Role Permissions

Each tenant role has different permission sets:

- **Owner**: All permissions (full access to everything)
- **Admin**: All manager permissions + governance permissions
- **Member**: Basic company membership (minimal permissions, typically needs brand roles to access assets)
- **Agency admin** / **Agency partner**: See [Agency roles and relationships (short)](#agency-roles-and-relationships-short); exact permission sets are in `app/Support/Roles/PermissionMap.php` under `tenantPermissions()`.

**Note:** Tenant-level roles include **Owner**, **Admin**, **Member**, **Agency admin**, and **Agency partner** (see `RoleRegistry`). **Brand-scoped** titles (Brand Manager, Contributor, Viewer, etc.) are **not** tenant roles—they are **brand roles** in `brand_user.role`.

---

## Brand Roles

**What can this person do for this brand's assets?**

Brand roles are stored as strings in the `brand_user.role` column (NOT Spatie roles).

### Available Brand Roles

| Role | Description | Use Case |
|------|-------------|----------|
| **Admin** | Manage brand config | Full control over brand settings, categories, and assets |
| **Brand Manager** | Manage brand settings | Can manage brand settings and categories (Pro/Enterprise plans only) |
| **Contributor** | Upload/edit assets | Can upload, edit, and manage assets |
| **Viewer** | Read-only access | Can view and download assets only |

### Important Notes

- Brand roles are **NOT** Spatie roles - they're stored as strings in `brand_user.role`
- Brand roles control access to assets, categories, and brand-specific settings
- A user can have different brand roles for different brands
- **Member** is NOT a valid brand role (it's tenant-level only)
- **Owner** is NOT a valid brand role (it's tenant-level only)
- All role validation uses `RoleRegistry` - no hardcoded arrays
- Invalid role assignments return 422 errors - NO automatic conversion

### Brand Role Permissions

Brand roles control access to:
- Asset viewing, downloading, uploading, publishing
- Metadata editing and management
- Category management (for Admin/Brand Manager)
- Brand settings (for Admin/Brand Manager)

### Library categories (system templates vs custom)

- **Who manages folders:** Users with **`brand_categories.manage`** (typically brand Admin; Brand Manager where the plan allows) can reorder categories, add custom folders, add catalog templates to a brand, and edit **name**, **icon**, and **hidden** visibility for **system-backed** categories. **Slug** and **lock** status are not tenant-editable.
- **Who can see a folder:** `CategoryPolicy::view` enforces **private** categories using `category_access` rules, with bypass for tenant/brand admins and users with **`view.restricted.categories`**. See `App\Models\Category::userHasAccess()` (uses eager-loaded or explicit `accessRules` queries — no lazy-loaded relation access).
- **Private categories:** Creating **private** (role-restricted) custom categories requires a **paid** plan tier that allows them; Starter/Free get an explicit upgrade message — this is separate from the per-brand **visible folder count** cap in `config/categories.php`.

---

## Role Combinations

### Examples

**Example 1: Tenant Member + Brand Viewer**
- Company level: Member (minimal permissions)
- Brand level: Viewer (read-only access to brand assets)
- **Result**: User can view/download assets for that brand, but has no company-wide permissions

**Example 2: Tenant Member + Brand Contributor**
- Company level: Member (minimal permissions)
- Brand level: Contributor (upload/edit access)
- **Result**: User can upload and edit assets for that brand, but has no company-wide permissions

**Example 3: Tenant Member + Brand Admin**
- Company level: Member (minimal permissions)
- Brand level: Admin (full brand control)
- **Result**: User can manage brand config and assets, but has no company-wide permissions

**Example 4: Tenant Admin + Brand Viewer**
- Company level: Admin (company administration)
- Brand level: Viewer (read-only access)
- **Result**: Tenant permissions cascade—user can manage brands, create collections, and perform brand operations. For asset-level actions (upload, edit) brand role applies. See Permission Cascade.

**Example 5: Tenant Owner + Brand Admin**
- Company level: Owner (full company access)
- Brand level: Admin (full brand control)
- **Result**: User has full access at both company and brand levels

---

## Site-Level Roles

**Note**: There are also site-level roles for system administrators (not tenant roles):

- `site_owner`: Full system access
- `site_admin`: System administration
- `site_support`: Support staff
- `site_engineering`: Engineering staff
- `site_compliance`: Compliance staff (view-only access to system-wide compliance data)

These are separate from tenant roles and control access to the admin dashboard and system-wide settings.

---

## Implementation Details

### Seeders

- **`RoleSeeder.php`**: Creates all Spatie roles (tenant-level and legacy roles)
- **`TenantRoleSeeder.php`**: Ensures tenant roles are properly seeded (including owner, admin, member, and agency-related roles per `RoleRegistry`)
- **`PermissionSeeder.php`**: Creates and assigns permissions to roles

### Database Structure

**Tenant Roles** (Spatie roles):
- Stored in `roles` table (Spatie Permission package)
- Assigned via `model_has_roles` table
- Scoped to tenant via `tenant_user` pivot

**Brand Roles** (strings):
- Stored in `brand_user.role` column (string, not Spatie role)
- Direct assignment in `brand_user` pivot table

### Validation Rules

**All role validation uses RoleRegistry** - no hardcoded arrays:

**Tenant Role Validation**:
```php
use App\Support\Roles\RoleRegistry;

'role' => [
    'required',
    'string',
    function ($attribute, $value, $fail) {
        try {
            RoleRegistry::validateTenantRoleAssignment($value);
        } catch (\InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    },
]
```

**Brand Role Validation**:
```php
use App\Support\Roles\RoleRegistry;

'role' => [
    'required',
    'string',
    function ($attribute, $value, $fail) {
        try {
            RoleRegistry::validateBrandRoleAssignment($value);
        } catch (\InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    },
]
```

### NO Automatic Conversions

**Invalid role assignments return 422 errors** - NO silent conversion.

- Attempting to assign 'owner' as a brand role returns a 422 error
- Attempting to assign 'member' as a brand role returns a 422 error
- Attempting to assign 'owner' via UI/invite returns a 422 error

All validation is centralized in `RoleRegistry`.

---

## Frontend Components

### Role Loading

**Frontend must load roles from API endpoints** - no hardcoded lists:

- `GET /api/roles/tenant` - Returns assignable tenant roles (excludes owner)
- `GET /api/roles/brand` - Returns all brand roles
- `GET /api/roles/brand/approvers` - Returns brand approver roles

Owner is never included in frontend responses.

### BrandRoleSelector Component

Located in `resources/js/Components/BrandRoleSelector.jsx`

- Must load roles from `/api/roles/brand` endpoint
- Only shows valid brand roles: Viewer, Contributor, Brand Manager, Admin
- Default role: 'viewer'
- NO automatic conversion - invalid roles return 422 errors

### Team Management Pages

- **`resources/js/Pages/Companies/Team.jsx`**: Team management with tenant and brand role selectors
- **`resources/js/Pages/Brands/Index.jsx`**: Brand-specific user invitation

---

## Common Patterns

### Checking User Permissions

**Tenant-level permission check**:
```php
$user->hasPermissionForTenant($tenant, 'asset.publish')
```

**Brand-level role check**:
```php
$user->getRoleForBrand($brand) // Returns: 'admin', 'brand_manager', 'contributor', 'viewer', or null
```

**Tenant-level role check**:
```php
$user->getRoleForTenant($tenant) // Returns: 'owner', 'admin', 'member', 'agency_admin', 'agency_partner', or null
```

### Default Behavior

- **New users**: Default to 'member' at tenant level
- **Brand assignments**: Default to 'viewer' at brand level
- **No brand role**: User cannot access brand assets (unless tenant role grants access)
- **Tenant creator**: Automatically assigned as 'owner' (seeder/initial setup only)

---

## Migration Notes

### Deprecated Roles

- **`manager`**: Legacy role (use 'admin' or 'brand_manager' instead)
- **`uploader`**: Legacy role (use 'contributor' instead)
- **`support`**: Removed - consolidated into `compliance` role

These roles are kept in the seeder for backward compatibility but should not be used for new assignments.

### Role Simplification

Tenant-level roles have been simplified for **direct** membership:

- **Removed**: `support` and `compliance` tenant-level roles
- **Kept for direct client/agency workspace membership**: **Owner**, **Admin**, **Member** (see `RoleRegistry::directCompanyTenantRoles()`)
- **Kept for client↔agency partnership grants on the client tenant**: **`agency_admin`**, **`agency_partner`** (see `RoleRegistry::agencyRelationshipRoles()`). These are **not** selectable on **Company → Team** invite; they are set via **Company settings → Agencies** / `TenantAgency`.
- **Rationale**: Brand-scoped complexity stays in **brand roles**; partnership grants stay in explicit agency-link flows.
- **Migration**: Any users with `support` or `compliance` tenant roles should be migrated to appropriate brand-scoped roles or removed
- **Note**: Site-level roles (`site_support`, `site_compliance`) remain unchanged (separate from tenant roles)

### Owner Role Protection

The `owner` role is **strictly protected**:

- **Never selectable in UI**: Owner does not appear in dropdowns
- **Cannot be assigned via API**: Returns 422 error if attempted
- **Cannot be assigned during invitation**: Returns 422 error if attempted
- **Only assignable via**: 
  - Ownership transfer flow (requires email confirmation from both parties)
  - Platform super-owner (user ID 1) for initial setup only
  - Seeder/initial setup (bypassOwnerCheck=true)

### Approval-Required Uploads

**Approval-required uploads are NOT a role** - they are a capability flag:

- **Capability**: `brand_user.requires_approval` (boolean)
- **Not a role**: This is a separate flag, not a role assignment
- **Approval rules**: 
  - `admin` and `brand_manager` roles can approve assets
  - `contributor` and `viewer` roles cannot approve
  - Use `RoleRegistry::brandApproverRoles()` to check if a role can approve

### Member Role (Tenant-Level Only)

The 'member' role is **tenant-level only**:

- **Valid at**: Tenant/company level only
- **Invalid at**: Brand level (returns 422 error if attempted)
- **Brand default**: 'viewer' is used for brand assignments
- **No migration needed**: Existing invalid assignments will return errors and must be fixed manually

---

## Best Practices

1. **Always assign brand roles**: Users need brand roles to access brand assets
2. **Use appropriate tenant roles**: Don't over-assign tenant permissions
3. **Separate concerns**: Tenant roles for company management, brand roles for asset access
4. **Plan-based restrictions**: Some roles (like Brand Manager) are plan-gated
5. **Validate on both levels**: Check both tenant and brand permissions when needed

---

## Canonical Role Registry

**All role lists come from a single registry** - `App\Support\Roles\RoleRegistry`:

- `RoleRegistry::tenantRoles()` - All tenant roles (including owner)
- `RoleRegistry::assignableTenantRoles()` - Assignable tenant roles (excludes owner; includes partnership keys for seeders/general assignability)
- `RoleRegistry::directCompanyTenantRoles()` - **Admin**, **Member** only (direct Team management)
- `RoleRegistry::agencyRelationshipRoles()` - **`agency_partner`**, **`agency_admin`** (partnership grants only)
- `RoleRegistry::directAssignableTenantRolesForInviter($user, $tenant)` - Direct invite / team role dropdown subset for the inviter
- `RoleRegistry::assignableAgencyRelationshipRolesForInviter($user, $clientTenant, $agencyTenant?)` - Partnership role options for client owner/admin
- `RoleRegistry::assignableAgencyWorkspaceRolesForInviter($user, $agencyTenant)` - Agency workspace Team when `is_agency`
- `RoleRegistry::tenantRoleDisplayLabel($role)` - Canonical UI label (e.g. **Agency manager** for `agency_admin`)
- `RoleRegistry::brandRoles()` - All brand roles
- `RoleRegistry::brandApproverRoles()` - Brand roles that can approve assets

**Validation methods**:
- `RoleRegistry::validateTenantRoleAssignment($role)` - Validates general assignable tenant role (excludes owner)
- `RoleRegistry::validateDirectCompanyTenantRoleAssignment($role)` - **Admin** / **Member** only (generic Team routes)
- `RoleRegistry::validateAgencyRelationshipRoleAssignment($role)` - Partnership roles only (`TenantAgency` flows)
- `RoleRegistry::validateBrandRoleAssignment($role)` - Validates brand role

**NO hardcoded role arrays in controllers** - use `RoleRegistry` (front-end loads brand lists from `GET /app/api/roles/brand` where applicable).

## Why Site Roles Are Not in PermissionMap

Site roles operate at the platform level and are not tenant- or brand-scoped. Their **keys** are canonical in `RoleRegistry::siteRoles()` (for validation and admin UI). They remain excluded from `PermissionMap` so tenant/brand permission maps never mix with platform staff roles.

This prevents:
- Future "helpful" refactors that accidentally include site roles in tenant contexts
- Cursor/AI hallucinating site roles into tenant/brand APIs
- Human confusion six months from now about why site roles appear in tenant dropdowns

Site roles (`site_owner`, `site_admin`, `site_support`, `site_engineering`, `site_compliance`) are created in `PermissionSeeder` and assigned via the site admin dashboard.

## Related Files

- **Role Registry**:
  - `app/Support/Roles/RoleRegistry.php` - Tenant/brand roles plus `siteRoles()` for platform staff keys

- **Seeders**:
  - `database/seeders/RoleSeeder.php`
  - `database/seeders/TenantRoleSeeder.php`
  - `database/seeders/PermissionSeeder.php`

- **Controllers**:
  - `app/Http/Controllers/TeamController.php`
  - `app/Http/Controllers/BrandController.php`
  - `app/Http/Controllers/RoleController.php` - API endpoints for frontend role loading

- **API Endpoints**:
  - `GET /api/roles/tenant` - Assignable tenant roles (excludes owner)
  - `GET /api/roles/brand` - All brand roles
  - `GET /api/roles/brand/approvers` - Brand approver roles

- **Frontend Components**:
  - `resources/js/Components/BrandRoleSelector.jsx` - Must load from API
  - `resources/js/Pages/Companies/Team.jsx` - Must load from API
  - `resources/js/Pages/Brands/Index.jsx` - Must load from API

- **Models**:
  - `app/Models/User.php` - Uses RoleRegistry for validation
  - `app/Models/Tenant.php`
  - `app/Models/Brand.php`


---

## Company settings section permissions


This document lists all Company Settings sections and their backend permissions. Use the **Company Permissions** page (`/app/companies/permissions`) to toggle these permissions per role.

## Permission Reference

| Section | Permission | Admin UI Label | Owner | Admin | Notes |
|--------|-----------|----------------|-------|-------|------|
| **Company Information** | `company_settings.edit` | Company Information | ✓ | ✓ | Edit name, slug, timezone, metadata approval, download template |
| **Plan & Billing** | `billing.view` | Plan & Billing (View) | ✓ | ✓ | View plan |
| **Plan & Billing** | `billing.manage` | Plan & Billing (Manage) | ✓ | ✓ | Manage subscription |
| **Team Members** | `team.manage` | Team Members | ✓ | ✓ | Manage team members |
| **Brands Settings** | `brand_settings.manage` | Brands Settings | ✓ | ✓ | Manage brands |
| **Enterprise Download Policy** | `company_settings.manage_download_policy` | Enterprise Download Policy | ✓ | ✓ | Edit download policy (Enterprise plan only) |
| **Categories & Fields** | `metadata.registry.view` or `metadata.tenant.visibility.manage` | (in Metadata Governance) | ✓ | ✓ | Link to registry; field governance permissions |
| **AI Settings** | `company_settings.manage_ai_settings` | AI Settings | ✓ | ✓ | Configure AI tagging behavior |
| **Tag Quality** | `company_settings.view_tag_quality` | Tag Quality | ✓ | ✓ | View tag quality metrics |
| **AI Usage** | `ai.usage.view` | AI Usage | ✓ | ✓ | View AI usage stats |
| **Ownership Transfer** | `company_settings.ownership_transfer` | Ownership Transfer | ✓ | ✗ | **Owner only** - never assign to admin |
| **Danger Zone (Delete Company)** | `company_settings.delete_company` | Delete Company | ✓ | ✗ | **Owner only** - never assign to admin |

## Owner-Only Sections

These sections are **only available to the company owner** (the user who created the tenant). They cannot be granted to admins via permissions:

- **Ownership Transfer** – Transfer company ownership to another team member
- **Danger Zone** – Delete the company

When an admin views the Company Settings page, they will see these sections with:
- Section title visible
- Content blurred
- Hint: "Owner only - Only the company owner can [action]. Contact your owner..."

## Admin-Restricted Sections

These sections can be toggled per role. If an admin does not have the permission, they see:
- Section title visible
- Content blurred
- Hint: "You don't have permission to [action]. Ask an owner or admin to grant you access."

- AI Settings
- Tag Quality

## Backend Checks

- **CompanyController::settings** – Requires `company_settings.view`
- **CompanyController::updateSettings** – Requires `company_settings.view` and `company_settings.edit`
- **CompanyController::destroy** – Requires user to be owner (role check)
- **CompanyController::updateDownloadPolicy** – Requires `company_settings.manage_download_policy`

## Running the Seeder

After adding or changing permissions, run:

```bash
php artisan db:seed --class=PermissionSeeder
```

This will:
1. Create any new permissions in the database
2. Sync owner role with all permissions (including owner-only)
3. Sync admin role with company permissions (excluding owner-only)


---

## Permission system audit


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


---

## Future: tenant-level permission overrides


**Status:** Not yet implemented

**Context:** The Company Team Management refactor (Parts 1–7) does not include tenant-level permission overrides. Site-wide permissions are different from tenant-level permissions.

**Scope:** Individual permissions on a tenant level (e.g., per-tenant role customization, permission overrides) do not exist yet. Add when needed.

**Related:** Roles section above in this document; `companies.permissions` route
