# System metadata: categories, field bundles, and propagation

This note complements [FEATURES.md](FEATURES.md) with implementation-oriented behavior for platform admins and tenants.

## Field bundles (system categories)

- **Storage:** `system_category_field_defaults` links each **latest** `system_categories` row to `metadata_fields` (system scope) with default visibility flags and optional `is_primary`.
- **Seeding:** When a brand adds a folder from the catalog (`addTemplateToBrand` → `applySeededDefaultsForCategory`), defaults are read from the DB bundle first, then fall back to `config/metadata_category_defaults.php` if no row exists for that field.
- **Global suppression:** `metadata_field_category_visibility` (family-wide by slug + asset type) still **wins** over bundle rows: suppressed fields stay off regardless of defaults.
- **Existing tenants:** Changing the platform bundle does **not** rewrite `metadata_field_visibility` on existing brand categories. Tenants must use **Reset to system default** (or equivalent) to pick up new defaults.

## Select options (hybrid propagation)

- **New system options:** When a new `metadata_options` row is created with `is_system`, existing tenants get a tenant-level hide in `metadata_option_visibility` with `provision_source = system_seed`, so pickers stay stable until opt-in.
- **New tenants / new brands:** They do not receive those seed hides for options that did not exist at creation time in the same way; effectively they see current system options without the backlog of `system_seed` rows.
- **Opt-in:** Tenants with `metadata.tenant.field.manage` or `metadata.tenant.visibility.manage` can use **Show new platform values** on Metadata → By category, which deletes tenant-level `system_seed` hides (global rows only). API: `POST /app/api/tenant/metadata/system-options/reveal-pending`. Pending count: `GET /app/api/tenant/metadata/system-options/pending-count`.

## Visible category cap (tenant catalog)

- **API:** `GET /app/api/tenant/metadata/brands/{brand}/available-system-categories` returns `visible_category_limits` (`visible`, `max`, `at_cap` per `asset` and `deliverable`) and, per template, `visible_slots_remaining` and `visible_cap_blocks_add` (true when adding would create a **visible** folder but the brand is at cap).
- **UI:** Metadata → By category uses a compact filter + select when the catalog list is long, and disables **Add** when `visible_cap_blocks_add` is true (hidden-by-default templates can still be added at cap).

## Admin entry points

- **Primary:** Admin → **System categories** — edit templates and **Edit bundle** for default fields.
- **Secondary:** **System Metadata Registry** (flat table) remains for metrics and **global suppression** per field and category.

## Operations

- After deploy, run migrations, then backfill bundles if needed:

```bash
php artisan metadata:backfill-system-category-field-defaults
```

Use `--force` only when replacing existing `system_category_field_defaults` rows from config.
