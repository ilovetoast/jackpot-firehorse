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
