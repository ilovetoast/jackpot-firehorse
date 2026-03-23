# Automated Metadata & AI Tagging — Design Document

**Status:** Design & Bootstrap (Not Yet Implemented)  
**Phase:** Post-Phase H (Filter UX Locked)  
**Last Updated:** January 2025

---

## Overview

This document defines the architecture for automated / AI-derived metadata fields (e.g., Color Palette, Scene Classification) that:

- Populate automatically from asset analysis
- Appear in filters using existing Phase H logic (no special cases)
- Respect category enablement and primary/secondary placement
- Flow through MetadataSchemaResolver without modification

**Key Principle:** Automated fields are **first-class metadata fields** that happen to be populated automatically. They are treated identically to manual fields in all filter and visibility logic.

---

## Part 1: Automated Metadata Field Contract

### Field Declaration Requirements

Automated metadata fields must declare the following properties:

#### Required Properties

```php
[
    'population_mode' => 'automatic',  // Required: 'automatic' | 'hybrid' | 'manual'
    'readonly' => true,                // Required: Automated fields are read-only in UI
    'source' => 'vision',              // Required: 'vision' | 'exif' | 'model_name' | 'computed'
    'show_in_filters' => true,         // Optional: Default true (can be filtered)
]
```

#### Optional Properties

```php
[
    'confidence' => 0.85,              // Optional: Confidence score (0.0-1.0)
    'value_shape' => 'multi',          // Optional: 'single' | 'multi' | 'weighted'
    'computation_method' => 'extract_colors', // Optional: Method identifier
]
```

### Population Mode Semantics

| Mode | Description | UI Behavior | Override Behavior |
|------|-------------|-------------|------------------|
| `automatic` | Fully automated, no manual input | Hidden from tenant category UI | Cannot be overridden |
| `hybrid` | Auto-populated, can be manually adjusted | Visible in tenant category UI | Can be manually overridden |
| `manual` | User-entered only | Visible in tenant category UI | Always manual |

**Note:** Automated fields (`population_mode = 'automatic'` AND `readonly = true`) are excluded from tenant Metadata Management UI but continue to function in filters and asset details.

### Source Types

| Source | Description | Example Fields |
|-------|-------------|----------------|
| `vision` | AI/ML image analysis | Color Palette, Scene Classification, Object Detection |
| `exif` | EXIF metadata extraction | Camera Model, ISO, Aperture, Shutter Speed |
| `model_name` | Derived from filename/model | Model Name, Product Type |
| `computed` | Calculated from other fields | Aspect Ratio, File Size Category |

### Value Shape

| Shape | Description | Storage Format | Filter Behavior |
|-------|-------------|----------------|-----------------|
| `single` | Single value | `string` or `number` | Standard single-select |
| `multi` | Multiple values | `array` | Standard multiselect |
| `weighted` | Values with confidence/weight | `[{value, weight}]` | Filtered by primary values only |

### Integration with MetadataSchemaResolver

Automated fields flow through `MetadataSchemaResolver` **without modification**:

1. Fields are loaded from `metadata_fields` table (same as manual fields)
2. Visibility rules apply identically (category enablement, tenant overrides)
3. Filter tier resolution uses same logic (primary vs secondary)
4. `available_values` computation includes automated field values

**No special cases.** Automated fields are metadata fields that happen to be auto-populated.

---

## Part 2: Color Palette Field (First Concrete Target)

### Field Definition

```php
[
    'key' => 'color_palette',
    'system_label' => 'Color Palette',
    'field_type' => 'multiselect',
    'population_mode' => 'automatic',
    'readonly' => true,
    'source' => 'vision',
    'value_shape' => 'multi',
    'show_in_filters' => true,
    'applies_to' => 'image',  // Images only
]
```

### Value Normalization

Color Palette values are normalized to **hex color codes** (e.g., `#FF5733`):

1. **Extraction:** AI/vision service extracts dominant colors from image
2. **Normalization:** Convert to hex format (6-digit, uppercase)
3. **Storage:** Stored as array of hex strings: `["#FF5733", "#33C3F0", "#90EE90"]`
4. **Display:** Hex codes shown in filters (can be enhanced with color swatches in UI)

### Available Values Computation

Color Palette `available_values` are computed from `asset_metadata` table:

```php
// Pseudocode
$colorValues = DB::table('asset_metadata')
    ->where('metadata_field_id', $colorPaletteFieldId)
    ->where('is_approved', true)
    ->whereIn('asset_id', $currentAssetGridResultSet)
    ->get()
    ->pluck('value')
    ->flatten()  // Flatten arrays
    ->unique()
    ->sort()
    ->values()
    ->toArray();
```

**Result:** Array of unique hex color codes present in current asset grid result set.

### Empty Results Behavior

If Color Palette has zero `available_values`:

- Filter is **hidden** (standard Phase H visibility rule)
- No special handling required
- Filter reappears when assets with color palette values are added

### Filter Rendering

Color Palette uses **standard multiselect filter** (Phase H):

- Rendered by `AssetGridSecondaryFilters` or `AssetGridMetadataPrimaryFilters` (if `is_primary = true`)
- Uses existing `FilterValueInput` component with `type: 'multiselect'`
- Options populated from `available_values` (hex codes)
- **Future enhancement:** Add color swatches to option display (UI-only, no logic change)

---

## Part 3: AI Tagging Pipeline Entry Point

### Job Architecture

AI tagging jobs are queued **per asset** after initial processing:

```
ProcessAssetJob
  → GenerateThumbnailsJob
  → ComputedMetadataJob
  → PopulateAutomaticMetadataJob  ← Entry point for AI tagging
```

### PopulateAutomaticMetadataJob Enhancement

**Current State:** Stub implementation with deterministic placeholder values.

**Future State:** Calls specialized AI tagging services:

```php
protected function computeMetadataValues(Asset $asset, array $fields): array
{
    $values = [];
    
    foreach ($fields as $fieldId => $field) {
        $fieldKey = $field['key'] ?? null;
        $source = $field['source'] ?? null;
        
        if (!$fieldKey || !$source) {
            continue;
        }
        
        // Route to appropriate service based on source
        switch ($source) {
            case 'vision':
                $values[$fieldId] = $this->computeVisionMetadata($asset, $field);
                break;
            case 'exif':
                $values[$fieldId] = $this->computeExifMetadata($asset, $field);
                break;
            case 'model_name':
                $values[$fieldId] = $this->computeModelNameMetadata($asset, $field);
                break;
            case 'computed':
                $values[$fieldId] = $this->computeDerivedMetadata($asset, $field);
                break;
        }
    }
    
    return $values;
}
```

### Vision Service (Color Palette Example)

```php
protected function computeVisionMetadata(Asset $asset, array $field): mixed
{
    $fieldKey = $field['key'] ?? null;
    
    if ($fieldKey === 'color_palette') {
        return app(ColorPaletteExtractionService::class)
            ->extractColors($asset);
    }
    
    // Future: Scene classification, object detection, etc.
    return null;
}
```

### ColorPaletteExtractionService

**Location:** `app/Services/Automation/ColorPaletteExtractionService.php`

**Responsibilities:**
- Load image from S3
- Extract dominant colors (AI/vision API or local library)
- Normalize to hex codes
- Return array of hex strings

**Error Handling:**
- Failures are logged but do not block asset processing
- Missing values result in empty array (filter hidden until values exist)

### Reprocessing

**Manual Reprocessing:**
- Admin can trigger reprocessing via asset detail page (future)
- Re-enqueues `PopulateAutomaticMetadataJob` for specific asset
- Respects manual overrides (hybrid fields only)

**Automatic Reprocessing:**
- Not implemented in initial phase
- Future: Reprocess on schema changes (new automated fields added)

### Result Merging

Results are merged into `asset_metadata` via `AutomaticMetadataWriter`:

1. **Check for manual overrides:** Hybrid fields may have manual values
2. **Write approved values:** Automated values are auto-approved
3. **Audit trail:** All writes logged to `asset_metadata_history`

**No conflicts:** Automatic fields never conflict with manual fields (different `metadata_field_id`).

---

## Part 4: Filter Integration (Phase H Compliance)

### No Special Cases

Automated fields are treated **identically** to manual fields:

1. **MetadataSchemaResolver:** No changes required
2. **MetadataFilterService:** No changes required
3. **filterTierResolver:** No changes required
4. **filterVisibilityRules:** No changes required
5. **Asset Grid Components:** No changes required

### Available Values Computation

`available_values` computation in `AssetController` already handles automated fields:

- Queries `asset_metadata` table (includes automated values)
- Filters by `is_approved = true` (automated values are auto-approved)
- Scopes to current asset grid result set
- Works for both single and multi-value fields

**No changes required.**

### Primary vs Secondary Placement

Automated fields can be marked as primary/secondary **per category**:

- Configured in Metadata Management → By Category (same as manual fields)
- Stored in `metadata_field_visibility.is_primary` (category-scoped)
- Resolved by `MetadataSchemaResolver` (same logic)

**Example:** Color Palette can be primary in Photography, secondary in Logos.

---

## Part 5: Saved Filters (Future Phase)

**Status:** PAUSED until admin configuration UX is locked and stable.

**Design Note:** Saved Filters will consume resolved metadata from Phase H:

- Saved filters store filter definitions (field keys, operators, values)
- Work with both manual and automated fields (no distinction)
- Filter definitions are field-agnostic (don't care about population mode)

**No code scaffolding.** Will be implemented in future phase.

---

## Implementation Checklist

### Phase 1: Field Contract (Documentation Only)

- [x] Document automated field contract
- [x] Document population mode semantics
- [x] Document source types
- [x] Document value shape
- [x] Confirm MetadataSchemaResolver integration (no changes)

### Phase 2: Color Palette Field (Design)

- [x] Define Color Palette field structure
- [x] Define value normalization (hex codes)
- [x] Define available_values computation
- [x] Define empty results behavior
- [x] Confirm filter rendering (standard multiselect)

### Phase 3: AI Tagging Pipeline (Design)

- [x] Document job architecture
- [x] Document PopulateAutomaticMetadataJob enhancement
- [x] Document ColorPaletteExtractionService interface
- [x] Document reprocessing approach
- [x] Document result merging

### Phase 4: Implementation (Future)

- [ ] Implement ColorPaletteExtractionService
- [ ] Enhance PopulateAutomaticMetadataJob
- [ ] Add Color Palette field to database (seeder)
- [ ] Test filter rendering with Color Palette
- [ ] Test available_values computation
- [ ] Test primary/secondary placement

### Phase 4.5: System Field Configuration (Completed: January 2025)

**Automatic Field Seeder:**
- `MetadataFieldPopulationSeeder` configures system fields that are automatically populated
- Fields configured: `orientation`, `color_space`, `resolution_class`, `dimensions`
- Configuration:
  - `population_mode = 'automatic'`
  - `show_on_upload = false` (hidden from upload form)
  - `show_on_edit = true` (visible in edit, but readonly)
  - `show_in_filters = true` (available in grid filters)
  - `readonly = true` (users cannot edit, system can populate)
- Seeder is included in `DatabaseSeeder` to run automatically

**Upload Form Behavior:**
- Fields with `population_mode = 'automatic'` are automatically excluded from upload form
- Fields with `show_on_upload = false` are also excluded
- These fields are populated automatically by the system during asset processing
- Users can view these fields in the edit drawer (readonly) and use them in filters

**Permission System:**
- Owners and admins have full access to manage metadata fields (except system-locked fields)
- System-locked fields (`automatic` + `readonly`) are never editable, even by owners/admins
- Permission resolver automatically grants access to owners/admins for all other fields

---

## Hard Rules (Do Not Break)

❌ **Do not modify Phase H filter logic**  
❌ **Do not add filter persistence**  
❌ **Do not add navigation selectors**  
❌ **Do not add AI chat or prompt UX**  
❌ **Do not change resolver semantics**  
❌ **Do not create special cases for automated fields**  
❌ **Do not scaffold Saved Filters**

✅ **Automated fields are first-class metadata fields**  
✅ **Use existing Phase H filter infrastructure**  
✅ **Respect category enablement and primary/secondary placement**  
✅ **Flow through MetadataSchemaResolver without modification**

---

## Success Criteria

1. ✅ Automated metadata field contract is clearly defined
2. ✅ Color Palette is fully spec'd and compatible with existing filters
3. ✅ AI tagging pipeline entry point is documented
4. ✅ No Phase H logic changes required
5. ✅ Saved Filters remain paused and unaffected
6. ✅ Clear separation between manual and automated fields (in documentation, not logic)

---

**End of Design Document**


---

# Merged phase reference: metadata governance and filters

The following sections preserve the former `PHASE_C_*` and `PHASE_H_*` documents in full.


## Source: PHASE_C_METADATA_GOVERNANCE.md


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

AI metadata generation for `ai_eligible` fields is planned in **Phase I**. See [AI_USAGE_LIMITS_AND_SUGGESTIONS.md](AI_USAGE_LIMITS_AND_SUGGESTIONS.md) (merged Phase I design appendix) for the full specification.

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


## Source: PHASE_H_LOCK.md

Where Primary Can Be Set

✅ By Category view only

❌ Not global

❌ Not per-asset

❌ Not in the Asset Grid UI

Toggle Rules

Label: “Primary (for this category)”

Toggle only enabled when:

Field is enabled

Field is filterable

Toggle persists to:

metadata_field_visibility.is_primary

🧩 Asset Grid Rendering Rules (Locked)
Primary Filters

Rendered by:

AssetGridMetadataPrimaryFilters


Criteria:

field.is_primary === true

Passes visibility rules:

Category compatibility

Asset type compatibility

Has available values

Secondary Filters

Rendered by:

AssetGridSecondaryFilters


Criteria:

field.is_primary !== true (false, null, undefined)

Passes visibility rules

Defensive default:
Missing is_primary → Secondary

Explicit Exclusions

The Asset Grid filter UI must never include:

Category selectors

Asset type selectors

Brand selectors

Navigation controls of any kind

Navigation is handled only by:

Sidebar

Route

Page context

👁 Visibility Rules (Locked)

A filter is hidden only if:

Not enabled for the category

Not compatible with asset type

Has zero available values in the current grid

Filters are never hidden due to:

Primary/secondary placement

Missing overrides

UI state

🛡 Defensive Guarantees

Secondary filters always render when valid

Missing data never crashes rendering

Legacy data continues to work

Phase H helpers must not be re-implemented elsewhere

🚫 Forbidden Changes Without New Phase

The following require a new phase:

Changing where is_primary is stored

Adding global primary behavior

Letting the Asset Grid infer placement

Mixing navigation controls into filter UI

Re-introducing modal-based filter panels

🔗 Future Phases That May Build on Phase H

Allowed extensions (non-breaking):

Phase J — Saved Filters

Role-based defaults

User-level filter presets

Filter ordering within primary bar

Max primary count per category

All must consume, not modify, Phase H behavior.

✅ Final Statement

Phase H is complete, validated, and locked.

All future work must respect this architecture.
Any refactor that violates these rules is considered a regression.
