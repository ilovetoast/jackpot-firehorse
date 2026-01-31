# Roles and Permissions System

## Overview

The Jackpot DAM uses a **canonical role system** with three completely separate role layers:
1. **Site-wide roles** (site_owner, site_admin, site_support, site_engineering, site_compliance)
2. **Tenant/Company Roles**: Control what a user can do at the company level (Spatie roles)
3. **Brand Roles**: Control what a user can do for a specific brand's assets (stored as strings)

These layers **MUST NOT leak into each other**. All role lists come from a single registry (`RoleRegistry`).

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

**Note:** Only Owner, Admin, and Member are tenant-level roles. All other roles (Brand Manager, Manager, Contributor, Uploader, Viewer) are brand-scoped and assigned per brand.

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
- **`TenantRoleSeeder.php`**: Ensures tenant roles are properly seeded (Owner, Admin, Member)
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
$user->getRoleForTenant($tenant) // Returns: 'owner', 'admin', 'member', or null
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

Tenant-level roles have been simplified to keep only essential roles:

- **Removed**: `support` and `compliance` tenant-level roles
- **Kept**: Only Owner, Admin, and Member for tenant-level
- **Rationale**: All other roles should be brand-scoped where complexity actually matters
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
- `RoleRegistry::assignableTenantRoles()` - Assignable tenant roles (excludes owner)
- `RoleRegistry::brandRoles()` - All brand roles
- `RoleRegistry::brandApproverRoles()` - Brand roles that can approve assets

**Validation methods**:
- `RoleRegistry::validateTenantRoleAssignment($role)` - Validates and throws if invalid/not assignable
- `RoleRegistry::validateBrandRoleAssignment($role)` - Validates and throws if invalid

**NO hardcoded role arrays** - all validation must use RoleRegistry.

## Why Site Roles Are Not in PermissionMap

Site roles operate at the platform level and are not tenant- or brand-scoped. They are intentionally excluded from `RoleRegistry` and `PermissionMap` to prevent scope leakage into tenant UIs, seeders, and APIs.

This prevents:
- Future "helpful" refactors that accidentally include site roles in tenant contexts
- Cursor/AI hallucinating site roles into tenant/brand APIs
- Human confusion six months from now about why site roles appear in tenant dropdowns

Site roles (`site_owner`, `site_admin`, `site_support`, `site_engineering`, `site_compliance`) are managed separately in `PermissionSeeder` and are only accessible via the site admin dashboard.

## Related Files

- **Role Registry**:
  - `app/Support/Roles/RoleRegistry.php` - Single source of truth for all roles

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
