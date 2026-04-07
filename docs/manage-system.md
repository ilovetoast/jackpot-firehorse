# Manage, Insights, and Settings

Quick mental model for where library and brand behavior live in the app. Read this once; you should know where to add features in under five minutes.

---

## Purpose of **Manage**

**Manage** is the **operational workspace for the brand library**: how folders (categories) are organized, which metadata fields apply where, tag hygiene workflows, and controlled vocabulary—things that **change how assets are classified, filtered, and edited** across the library.

- **Routes** (authenticated app, active tenant + brand): **`manage.categories`** → `/app/manage/categories` (folders + fields in one hub). Legacy **`manage.structure`** and **`manage.fields`** redirect here with query params preserved. **`manage.tags`**, **`manage.values`** → `/app/manage/tags`, `/app/manage/values`.
- **Controller**: `App\Http\Controllers\ManageController`.
- **Audience**: people who **run** the DAM (librarians, admins), not only executives viewing charts.

Insights may **point into** Manage (e.g. “fix this” deep links with query params); Manage is where the **work** happens.

---

## Three areas: read vs operate vs configure

| | **Insights** | **Manage** | **Settings** |
|---|--------------|------------|----------------|
| **Verb** | Read, explore, prioritize | Operate, apply, bulk-change | Configure, prefer, brand/company identity |
| **Risk** | Low (mostly GETs, aggregates) | Can be **high** (structure, visibility, many assets) | Should stay **low** (reversible preferences, safe defaults) |
| **Examples** | Metadata coverage, usage, activity, review queues (signals) | Category tree, field visibility per folder, missing-tags workflows, values lists | **Company** settings (`companies.settings`), **Brand** portal (`brands.edit`): identity, workspace chrome, public portal, team—**not** bulk library surgery |

**One line each**

- **Insights** — “What’s going on?” (metrics, gaps, links to fix).
- **Manage** — “Change how the library works at scale.”
- **Settings** — “How this company/brand behaves in the product” without turning Settings into a second DAM admin.

---

## Developer notes (please follow)

### Bulk and destructive actions → **Manage**

- Purges, bulk visibility changes, reordering structure, field suppression across categories, and similar **multi-entity or hard-to-undo** operations belong in **Manage** (or domain-specific admin tools), with clear affordances and permissions.
- If Insights suggests an action, the **destructive or bulk** implementation should live under Manage (or the asset library with explicit bulk UX), not hidden inside Brand or Company Settings.

### **Settings** must stay safe (non-destructive)

- **Company Settings** and **Brand Settings** should favor **configuration**: toggles, branding, billing-related UI, team invites, **non-bulk** preferences.
- Avoid adding **bulk tag removal**, **category deletion**, or **mass metadata field changes** to Settings screens. That keeps “configure my brand” psychologically safe and reduces accidental damage.
- When in doubt: **read-only or single-entity** in Settings; **library-wide or bulk** in Manage.

### Cross-linking

- Deep links from Insights → Manage (or Assets with query params) are good UX; keep **routes** for Manage stable when adding filters.
- Do not duplicate Manage workflows inside Settings just to save a click; link out with a short explanation instead.

---

## Related code (starting points)

- Manage routes: `routes/web.php` (`prefix('manage')->name('manage.')`).
- Insights: `MetadataAnalyticsController`, `AnalyticsOverviewController`, routes named `insights.*`.
- Company settings: `CompanyController@settings`.
- Brand settings: `BrandController@edit` → Inertia `Brands/Edit`.

---

*Last updated: product convention for jackpot app; adjust routes if the app prefix changes.*
