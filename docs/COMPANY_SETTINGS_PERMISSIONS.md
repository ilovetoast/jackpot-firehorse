# Company Settings Page - Section Permissions

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
| **Metadata** | `metadata.registry.view` or `metadata.tenant.visibility.manage` | (in Metadata Governance) | ✓ | ✓ | View/manage metadata fields |
| **Dashboard Widgets** | `company_settings.manage_dashboard_widgets` | Dashboard Widgets | ✓ | ✓ | Configure dashboard widget visibility per role |
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

- Dashboard Widgets
- AI Settings
- Tag Quality

## Backend Checks

- **CompanyController::settings** – Requires `company_settings.view`
- **CompanyController::updateSettings** – Requires `company_settings.view` and `company_settings.edit`
- **CompanyController::destroy** – Requires user to be owner (role check)
- **CompanyController::updateDownloadPolicy** – Requires `company_settings.manage_download_policy`
- **CompanyController::updateWidgetSettings** – Requires `company_settings.manage_dashboard_widgets`

## Running the Seeder

After adding or changing permissions, run:

```bash
php artisan db:seed --class=PermissionSeeder
```

This will:
1. Create any new permissions in the database
2. Sync owner role with all permissions (including owner-only)
3. Sync admin role with company permissions (excluding owner-only)
