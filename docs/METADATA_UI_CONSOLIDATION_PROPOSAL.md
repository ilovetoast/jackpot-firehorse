# Metadata Management UI Consolidation — Analysis & Proposal

**Status:** Proposal (no code changes yet)  
**Scope:** Metadata Management — By Category, All Metadata, Filters tabs only  
**Constraints:** UI-only. No Phase H changes. No backend API changes. No saved filters work.

---

## 1. Executive Summary

Metadata Management currently exposes **filter visibility** and **filter placement** (primary vs secondary) in multiple places. That duplication creates confusion about where to change “why does this field appear in the asset grid?” This proposal consolidates ownership, removes redundant controls, and reduces cognitive load via clearer tab roles and copy.

---

## 2. Current State (Before)

### 2.1 Tab Structure

| Tab | Purpose | Controls |
|-----|---------|----------|
| **By Category** | Category-first enablement & placement | Category list → select category → per-field: Enable toggle, Upload / Edit / **Filter** checkboxes, **Primary** toggle (when Filter on) |
| **All Metadata** | System-wide field overview | Category lens (view-only), Table: Field, **Appears On** (Upload / Edit / **Filter**), Category Scope, Status, **Advanced** |
| **Filters** | Filter surface control | Header + helper text, Table: Field, Type, Population, **Available in Filters** (Shown/Hidden) |
| **Custom Fields** | Tenant-defined fields only | Same table as All Metadata (Category lens, Appears On including **Filter**, etc.) |

### 2.2 Duplication & Ambiguity

1. **Filter visibility (`show_in_filters`)**  
   - **By Category:** “Filter” checkbox (global visibility; shown when configuring a category’s enabled fields).  
   - **All Metadata (and Custom):** “Filter” in “Appears On” column.  
   - **Filters tab:** “Available in Filters” (Shown/Hidden).  
   → **Three** places can change the same setting.

2. **Primary placement (`is_primary`, category-scoped)**  
   - **By Category:** “Primary” toggle, only when Filter is on. Correctly uses `category_id` in API.  
   - **Filters tab:** `handlePrimaryToggle` exists but is **not wired to UI**; helper text correctly says “use By Category” for primary.  
   → No duplicate *UI* for primary, but Filters tab still suggests “filter control” ownership.

3. **Cognitive load**  
   - “Why does this field appear in the asset grid?” is answered differently depending on tab.  
   - Filter visibility can be changed in three locations; primary only in By Category.  
   - Filters tab name implies it’s the place for filter configuration, yet primary is explicitly deferred to By Category.

### 2.3 What Stays Unchanged (Constraints)

- Phase H filter logic, visibility rules, tier resolution, MetadataSchemaResolver, MetadataFilterService.  
- Backend APIs (visibility, category overrides, etc.).  
- Asset Grid filter UX (primary/secondary components, URL ownership, etc.).  
- Category lens in All Metadata (view-only filter).

---

## 3. Proposed State (After)

### 3.1 Ownership (Canonical Answers)

| Question | Single answer |
|----------|----------------|
| “Why does this field appear in the asset grid?” | **By Category** — enable for category, check Filter, optionally set Primary. |
| “Where do I change filter visibility or primary placement?” | **By Category** only. |
| “What’s the system-wide list of metadata fields?” | **All Metadata** — overview only, no filter placement controls. |
| “What’s the Filters tab for?” | **Deprecated** or **read-only explainer** linking to By Category. |

### 3.2 Tab-by-Tab Changes

#### **By Category**

- **Role:** Canonical control surface for **behavior & placement** (including asset grid filters).  
- **Keep:** Category list, per-category enable/disable, Upload / Edit / **Filter** checkboxes, **Primary** toggle (when Filter on), drag-and-drop order.  
- **Copy updates:**  
  - Primary: use **“Primary (for this category)”** as label or tooltip (PHASE_H_LOCK).  
  - Add short inline help: e.g. “Primary = inline in asset grid bar; others = ‘More filters’.”  
  - Subheader: clarify that Filter + Primary here control asset grid behavior.

#### **All Metadata**

- **Role:** System overview only. No filter placement, no primary/secondary concepts.  
- **Remove:** **Filter** toggle from “Appears On” column.  
- **Keep:** Upload, Edit, Category Scope, Status, **Advanced** drill-down, Category lens (view-only).  
- **Copy updates:**  
  - Info banner: “Filter visibility and primary placement are configured in **By Category**.”  
  - “Appears On” limited to Upload / Edit only; column header or help tooltip can say “Filter: see By Category.”

#### **Filters Tab — Option A: Remove**

- Remove tab and `FilterView` route/usage.  
- Update tab nav: only By Category, All Metadata, Custom Fields.  
- No Filters-specific UI left.

#### **Filters Tab — Option B: Read-only Explainer**

- **Role:** Informational only. No toggles, no persistence.  
- **Remove:** Entire table, “Available in Filters” toggle, `handleFilterVisibilityToggle`, `handlePrimaryToggle` (dead code today).  
- **Replace with:** Short explainer:  
  - What asset grid filters are (primary vs “More filters”).  
  - That filter visibility and primary placement are **category-scoped** and configured only in **By Category**.  
  - Clear link/CTA: “Configure in By Category →” (e.g. switch tab to `by-category`).  
- **Keep:** Optional link to By Category only.

#### **Custom Fields**

- Uses same table as All Metadata.  
- **Remove:** Filter from “Appears On” (same as All Metadata).  
- **Keep:** Category lens, Upload, Edit, Category Scope, Advanced.  
- **Copy:** Same as All Metadata regarding “Filter: see By Category.”

### 3.3 Controls Summary

| Control | By Category | All Metadata | Custom Fields | Filters tab |
|--------|-------------|--------------|---------------|------------|
| **Upload** | ✅ Keep | ✅ Keep | ✅ Keep | N/A |
| **Edit** | ✅ Keep | ✅ Keep | ✅ Keep | N/A |
| **Filter** (`show_in_filters`) | ✅ **Canonical** | ❌ **Remove** | ❌ **Remove** | ❌ **Remove** (or tab removed) |
| **Primary** (`is_primary`) | ✅ **Canonical** | None today | None today | None (remove dead code) |
| **Category enable/disable** | ✅ Keep | N/A | N/A | N/A |
| **Category lens** | N/A | ✅ Keep (view-only) | ✅ Keep | N/A |
| **Advanced** | N/A | ✅ Keep | ✅ Keep | N/A |

---

## 4. Which Controls Move / Disappear

- **Filter toggle**  
  - **Disappears from:** All Metadata table, Custom Fields table, Filters tab (if retained as explainer).  
  - **Stays only in:** By Category (per enabled field, when configuring a category).

- **Primary toggle**  
  - **Already only in:** By Category.  
  - **No** new UI elsewhere.  
  - **Remove:** `handlePrimaryToggle` (and any leftover primary wiring) from `FilterView` if Filters tab becomes explainer-only.

- **“Available in Filters” / Filter Surface Control table**  
  - **Disappears** if Filters tab is removed or converted to explainer.  
  - **No** replacement; By Category is the single control surface.

- **No new controls** added; only removal or relocation of existing ones.

---

## 5. Copy Updates (Summary)

| Location | Change |
|----------|--------|
| **By Category** | Primary: “Primary (for this category)” (label/tooltip). Add 1–2 line explanation: Primary = asset grid bar; others = “More filters.” Clarify that Filter + Primary here drive asset grid. |
| **All Metadata** | Info banner: “Filter visibility and primary placement are set in **By Category**.” Remove Filter from Appears On; add “Filter: see By Category” in help/tooltip if useful. |
| **Custom Fields** | Same as All Metadata (shared table + banner). |
| **Filters tab (explainer option)** | Replace Filter Surface Control with short explainer + “Configure in By Category →” link. No tables, no toggles. |
| **Metadata Management** | Subtitle can stay “Control where metadata fields appear in your workflow”; optional tweak to mention “including asset grid filters” and point to By Category. |

---

## 6. Regression Checklist

Use this **after** implementation to verify behavior and that Phase H / backend are untouched.

### 6.1 Phase H & Backend (Must Not Regress)

- [ ] No changes to `MetadataSchemaResolver`, `MetadataFilterService`, or Phase H helpers.  
- [ ] No changes to visibility rules, tier resolution, or Asset Grid filter components.  
- [ ] No changes to visibility or category-override APIs.  
- [ ] `metadata_field_visibility.is_primary` still category-scoped; only By Category sends `category_id` with `is_primary`.

### 6.2 By Category

- [ ] Filter checkbox still toggles `show_in_filters` correctly.  
- [ ] Primary toggle still appears only when Filter is on; still sends `category_id` with `is_primary`.  
- [ ] Enable/disable per category, Upload, Edit, drag-and-drop unchanged.  
- [ ] Copy updates in place; no functional change to Phase H behavior.

### 6.3 All Metadata & Custom Fields

- [ ] Filter removed from “Appears On”; Upload and Edit still work.  
- [ ] Category lens still filters view only; no new category-scoped *controls*.  
- [ ] Advanced drill-down unchanged.  
- [ ] Info banner / copy reference By Category for filter and primary.

### 6.4 Filters Tab

- [ ] **If removed:** Tab gone; no `FilterView`; no filter toggles anywhere except By Category.  
- [ ] **If explainer:** No toggles, no table; explainer + “Configure in By Category” link only; dead `handlePrimaryToggle` / filter toggle code removed.

### 6.5 General

- [ ] No modal-based filters, no navigation selectors in filter UI, no global primary controls.  
- [ ] Asset grid filter behavior unchanged: primary/secondary rendering, URL ownership, visibility rules.  
- [ ] Manual smoke: set Filter + Primary in By Category → verify asset grid; change in All Metadata/Filters (if any) no longer possible.

---

## 7. Recommendation

- **Filters tab:** Prefer **Option B (read-only explainer)** over removal. It preserves a discoverable “Filters” entry point, explains primary vs “More filters,” and clearly directs users to By Category. If you prefer fewer tabs, **Option A (remove)** is fine.  
- **Rollout:** Implement copy updates and control removal (Filter from All Metadata/Custom, Filters tab → explainer or removed) in one UI-only pass; keep all backend and Phase H behavior unchanged.

---

## 8. Files to Touch (Implementation Phase)

- `resources/js/Pages/Tenant/MetadataRegistry/Index.jsx` — tab nav, optional removal of Filters tab; shared table (remove Filter from Appears On); info banner copy; Category lens unchanged.  
- `resources/js/Pages/Tenant/MetadataRegistry/ByCategory.jsx` — copy only (Primary label, short explainer).  
- `resources/js/Pages/Tenant/MetadataRegistry/FilterView.jsx` — either remove usage and delete, or replace with read-only explainer and remove toggle/handler code.  
- No backend, Phase H, or API changes.

---

**End of proposal.**
