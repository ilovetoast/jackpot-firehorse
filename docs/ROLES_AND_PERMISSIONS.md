# Roles and Permissions System

## Overview

The Jackpot DAM uses a two-tier role system:
- **Tenant/Company Roles**: Control what a user can do at the company level (Spatie roles)
- **Brand Roles**: Control what a user can do for a specific brand's assets (stored as strings)

These roles are **separate and independent**. A user can have different roles at different levels.

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
- **Owner** cannot be assigned during invitation - ownership must be transferred via the ownership transfer process in Company Settings
- **Member** is tenant-level only - cannot be assigned as a brand role
- Tenant roles control company-wide permissions (billing, team management, brand settings, etc.)
- Tenant roles are managed via Spatie Permission package

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
- **Result**: User has company-wide admin access, but only read-only access to this specific brand

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

**Tenant Role Validation**:
```php
'role' => 'required|string|in:owner,admin,member'
```

**Brand Role Validation**:
```php
'role' => 'required|string|in:admin,brand_manager,contributor,viewer'
```

### Automatic Conversions

The system automatically converts invalid role assignments:

- **Owner → Admin**: If 'owner' is attempted as a brand role, it's converted to 'admin'
- **Member → Viewer**: If 'member' is attempted as a brand role, it's converted to 'viewer'

These conversions happen in:
- `BrandController.php`
- `TeamController.php`
- `SiteAdminController.php`
- Frontend components (BrandRoleSelector, UserInviteForm, etc.)

---

## Frontend Components

### BrandRoleSelector Component

Located in `resources/js/Components/BrandRoleSelector.jsx`

- Provides dropdown for selecting brand roles
- Only shows valid brand roles: Viewer, Contributor, Brand Manager, Admin
- Automatically converts 'member' to 'viewer' if somehow passed
- Default role: 'viewer'

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

### Owner Role Assignment

The `owner` role cannot be assigned during team member invitation:

- **Invitation**: Only `admin` and `member` roles can be assigned when inviting new team members
- **Ownership Transfer**: Ownership must be transferred via the ownership transfer process in Company Settings
- **Security**: This ensures ownership changes require explicit confirmation from the current owner
- **Process**: The ownership transfer process requires email confirmation from both the current owner and the new owner

### Member Role Migration

The 'member' role was previously available as both a tenant and brand role. This has been corrected:

- **Before**: 'member' could be assigned at both tenant and brand levels
- **After**: 'member' is tenant-level only; brand roles use 'viewer' instead

Existing brand assignments with 'member' should be migrated to 'viewer'.

---

## Best Practices

1. **Always assign brand roles**: Users need brand roles to access brand assets
2. **Use appropriate tenant roles**: Don't over-assign tenant permissions
3. **Separate concerns**: Tenant roles for company management, brand roles for asset access
4. **Plan-based restrictions**: Some roles (like Brand Manager) are plan-gated
5. **Validate on both levels**: Check both tenant and brand permissions when needed

---

## Related Files

- **Seeders**:
  - `database/seeders/RoleSeeder.php`
  - `database/seeders/TenantRoleSeeder.php`
  - `database/seeders/PermissionSeeder.php`

- **Controllers**:
  - `app/Http/Controllers/TeamController.php`
  - `app/Http/Controllers/BrandController.php`
  - `app/Http/Controllers/SiteAdminController.php`

- **Frontend Components**:
  - `resources/js/Components/BrandRoleSelector.jsx`
  - `resources/js/Pages/Companies/Team.jsx`
  - `resources/js/Pages/Brands/Index.jsx`

- **Models**:
  - `app/Models/User.php` (HasRoles trait)
  - `app/Models/Tenant.php`
  - `app/Models/Brand.php`
