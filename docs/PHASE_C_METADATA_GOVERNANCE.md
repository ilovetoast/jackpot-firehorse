# Phase C — Metadata Governance & Visibility

Phase C introduces administrative and tenant-level governance
for metadata fields without modifying the underlying metadata engine.

---

## Scope Overview

This phase provides:
- Observability into system-provided metadata fields
- Controlled visibility and applicability management
- Clear separation between system fields and tenant fields
- Preparation for enterprise governance and monetization

This phase does NOT modify:
- MetadataSchemaResolver
- Field keys, types, or options
- Candidate resolution logic
- Approval workflows

---

## Phase Breakdown

### C1 — System Metadata Governance UI (Admin Only)
Status: ⏳ Pending

Goals:
- View all system metadata fields
- Inspect field definitions (read-only)
- Identify AI vs system vs manual fields
- Configure visibility (upload/edit/filter)
- Configure category suppression
- Observe usage & override statistics

No schema mutation allowed.

---

### C2 — System Visibility Enforcement
Status: ⏳ Pending

Goals:
- Enforce category exclusions in:
  - Upload UI
  - Edit UI
  - Filters
- Ensure consistent behavior across all contexts
- No ownership or duplication of fields

---

### C3 — Tenant Custom Metadata Fields
Status: ⏳ Pending

Goals:
- Allow tenants to define custom fields
- Enforce namespacing (e.g. custom__*)
- Enforce plan-based limits
- Prevent deletion once in use
- Visibility configurable per tenant

System fields remain immutable.

---

### C4 — Tenant Metadata Visibility UI
Status: ⏳ Pending

Goals:
- Tenant UI to manage visibility of:
  - System fields (visibility only)
  - Tenant-created fields
- Configure show/hide per context
- Configure category suppression (visibility only)

---

## Locked Principles

- Categories do not own metadata schemas
- Fields are global, visibility is contextual
- No versioning of metadata fields
- Overrides and candidates replace versioning
- All changes are additive and auditable

---

## Related Phases

### Phase I: AI Metadata Generation

**Status:** 📋 PLANNING

AI metadata generation for `ai_eligible` fields is planned in **Phase I**. See `PHASE_I_AI_METADATA_GENERATION.md` for complete design specification.

**Key Points:**
- Only processes fields with `ai_eligible = true`
- Uses OpenAI Vision API (GPT-4o/GPT-4o-mini)
- Respects plan limits (`max_ai_tagging_per_month`)
- Creates candidates in `asset_metadata_candidates` table
- Flows into existing `AiMetadataSuggestionJob` for suggestion generation

**Current State:** AI metadata generation is NOT yet implemented. The `ai_eligible` flag is configured, but no AI analysis occurs. This phase will implement the missing AI generation layer.

---

## Implementation Notes

### Permission System (Updated: January 2025)

**Owner/Admin Bypass:**
- Owners and admins automatically have full access to manage all metadata fields
- Permission checks in `TenantMetadataRegistryController` and `TenantMetadataFieldController` bypass for owner/admin roles
- `MetadataPermissionResolver` returns `true` for owners/admins by default (except system-locked fields)
- System-locked fields (`population_mode = 'automatic'` AND `readonly = true`) remain non-editable even for owners/admins

**System-Locked Fields:**
- Fields with `population_mode = 'automatic'` AND `readonly = true` are never editable by users
- These fields are automatically populated by the system and cannot be manually overridden
- Examples: `orientation`, `color_space`, `resolution_class`, `dimensions`

### Automatic Field Configuration

**Seeder: `MetadataFieldPopulationSeeder`**
- Configures system fields that are automatically populated
- Sets `population_mode = 'automatic'` for fields like orientation, color_space, resolution_class, dimensions
- Configures visibility: `show_on_upload = false`, `show_on_edit = true`, `show_in_filters = true`
- Sets `readonly = true` to prevent manual editing
- Included in `DatabaseSeeder` to run automatically on fresh installs

**Upload Form Exclusion:**
- `UploadMetadataSchemaResolver` automatically excludes fields with `population_mode = 'automatic'`
- Also excludes fields with `show_on_upload = false`
- These fields never appear in the upload form UI

---

## Exit Criteria

Phase C is complete when:
- Admins can fully observe system metadata
- Tenants can safely extend metadata
- No schema drift occurs
- Governance rules are explicit and enforceable

---

## Known Issues

### Metadata Drawer Permission Checks

**Issue:** Approved metadata was not visible in the Asset Drawer for contributors/viewers, even when the metadata had been approved.

**Root Cause:** The permission check in `AssetMetadataController::getEditableMetadata()` was filtering out fields where the user lacked edit permission, even when approved metadata existed. This caused approved metadata (e.g., `photo_type`, `scene_classification`) to be invisible to contributors/viewers who should be able to view (but not edit) approved metadata.

**Fix Applied (January 2026):**
- Modified permission check logic to verify if a field has approved metadata before filtering
- Fields with approved metadata are now shown read-only to contributors/viewers, even without edit permission
- Only fields without approved metadata are filtered out when the user lacks edit permission
- Edit buttons are hidden in the UI when `can_edit` is false

**Location:** 
- Backend: `app/Http/Controllers/AssetMetadataController.php` (method: `getEditableMetadata`)
- Frontend: `resources/js/Components/AssetMetadataDisplay.jsx`

**Important Notes:**
- Permission checks use **brand-level permissions** (`brand->id`), not tenant-level
- Approved metadata visibility depends only on `approved_at`, not on source (user/system/AI)
- Pending metadata remains hidden from contributors/viewers (as intended)
- Automatic/readonly fields are always shown (they don't require approval)

**Watch Out For:**
- When modifying drawer metadata queries, ensure approved metadata is visible regardless of edit permissions
- Do not add source-based filtering for contributors/viewers - only check `approved_at`
- Always verify brand-level permissions are used, not tenant-level

---

## Adding a new metadata field (drawer + primary filter) — Checklist

When adding a new metadata field that must appear in the **Asset Drawer (Quick View)** and/or **Primary Filter**, use this checklist so brand/permission and UI issues don’t recur.

### Drawer (Quick View)

1. **Metadata schema (edit context)**  
   Drawer uses `GET /app/uploads/metadata-schema?category_id=&asset_type=&context=edit`.  
   - `UploadController::getMetadataSchema`: support `context=edit` and call `resolveForEdit()` (C9.2).  
   - If `app('brand')` can be null (e.g. fetch from drawer), **resolve brand from category** so the request doesn’t return 422.  
   - Touchpoint: `app/Http/Controllers/UploadController.php` — `getMetadataSchema()`.

2. **Edit schema resolver**  
   - `UploadMetadataSchemaResolver::resolveForEdit()` and `filterForEdit()` must include the new field when `show_on_edit` is true for the category.  
   - Touchpoint: `app/Services/UploadMetadataSchemaResolver.php`.

3. **Frontend: context=edit**  
   - AssetDrawer, BulkMetadataEditModal, PendingAssetReviewModal must request metadata-schema with **`context=edit`** when deciding whether to show the field.  
   - Touchpoints: `AssetDrawer.jsx`, `BulkMetadataEditModal.jsx`, `PendingAssetReviewModal.jsx`.

### Primary filter

4. **Filterable schema**  
   - Field must be in `filterable_schema` with correct `is_primary` (category override from `metadata_field_visibility`).  
   - Handled by `MetadataSchemaResolver` + `MetadataFilterService` when the field is filterable and visible.

5. **available_values**  
   - Primary filters only show when `available_values[field_key]` is non-empty.  
   - If the field’s values come from a **pivot or other table** (not `asset_metadata`), **harvest them in AssetController** and set `available_values['field_key']`.  
   - Touchpoint: `app/Http/Controllers/AssetController.php` (index) — e.g. “Source 3” for collection.

6. **Options with labels**  
   - For a dropdown, attach `field['options']` with `value` and `display_label` (or `label`) so the primary filter shows labels, not raw values.  
   - Touchpoint: same AssetController loop that builds `filterable_schema` (e.g. collection options from `Collection::whereIn(...)`).

7. **Filter UI (dropdown with label)**  
   - If the field should always be a **dropdown with label** (e.g. collection), add explicit handling in `FilterValueInput` (e.g. `if (fieldKey === 'collection')`) so it doesn’t fall back to text/number.  
   - Touchpoint: `resources/js/Components/AssetGridMetadataPrimaryFilters.jsx` — `FilterValueInput`, and option label: `option.display_label ?? option.label ?? option.value`.

### Brand / permission gotchas

- **Brand context:** Endpoints that require `app('brand')` (e.g. metadata-schema, setVisibility) should resolve brand from the **category** when brand is not bound, so fetch from the drawer still works.  
- **Permission:** Drawer editable fields are filtered in `AssetMetadataController::getEditableMetadata()`; approved metadata must remain visible read-only (see “Known Issues” above).
