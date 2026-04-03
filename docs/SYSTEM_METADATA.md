# System metadata: categories, field bundles, and propagation

This note complements [FEATURES.md](FEATURES.md) with implementation-oriented behavior for platform admins and tenants.

## Field bundles (system categories)

- **Storage:** `system_category_field_defaults` links each **latest** `system_categories` row to `metadata_fields` (system scope) with default visibility flags and optional `is_primary`.
- **Seeding:** When a brand adds a folder from the catalog (`addTemplateToBrand` → `applySeededDefaultsForCategory`), defaults are read from the DB bundle first, then fall back to `config/metadata_category_defaults.php` if no row exists for that field.
- **Global suppression:** `metadata_field_category_visibility` (family-wide by slug + asset type) still **wins** over bundle rows: suppressed fields stay off regardless of defaults.
- **Existing tenants:** Changing the platform bundle does **not** rewrite `metadata_field_visibility` on existing brand categories. Tenants must use **Reset to system default** (or equivalent) to pick up new defaults.
- **Presets:** Admins can seed bundle rows from **System categories** (create form: optional preset; each row: **Seed preset** dropdown) via `POST /app/admin/system-categories/{template}/seed-bundle-preset` (`minimal`, `photography_like`, or `by_field_types` with `field_types[]`).

## Select options (hybrid propagation)

- **New system options:** When a new `metadata_options` row is created with `is_system`, existing tenants get a tenant-level hide in `metadata_option_visibility` with `provision_source = system_seed`, so pickers stay stable until opt-in.
- **New tenants / new brands:** They do not receive those seed hides for options that did not exist at creation time in the same way; effectively they see current system options without the backlog of `system_seed` rows.
- **Opt-in:** Tenants with `metadata.tenant.field.manage` or `metadata.tenant.visibility.manage` can use **Show new platform values** on Metadata → By category, which deletes tenant-level `system_seed` hides (global rows only). API: `POST /app/api/tenant/metadata/system-options/reveal-pending`. Pending count: `GET /app/api/tenant/metadata/system-options/pending-count`.

## System fields (hybrid propagation)

- **When:** Creating a system field from **System Metadata Registry** with at least one template in **Add to template bundles** dispatches `BackfillHybridVisibilityForMetadataFieldJob`, which inserts **category-scoped** `metadata_field_visibility` rows for existing brand folders that use a bundle containing that field. Rows use `provision_source = system_seed` and keep surfaces off until the tenant opts in.
- **New folders:** `applySeededDefaultsForCategory` continues to write normal rows with `provision_source` null (no seed hold) for newly added catalog folders.
- **Opt-in:** Metadata → By category shows **Enable new platform fields** when pending seeds exist. API: `POST /app/api/tenant/metadata/system-fields/reveal-pending`; count: `GET /app/api/tenant/metadata/system-fields/pending-count`. Reveal sets surfaces visible (`is_hidden`, `is_upload_hidden`, `is_edit_hidden`, `is_filter_hidden` cleared) and clears `provision_source`.
- **Schema resolution:** `MetadataSchemaResolver` treats `provision_source = system_seed` as fully hidden surfaces even if flags were inconsistent.

## System Metadata Registry (admin)

- **View:** `metadata.registry.view` — grouped by field type, filters, metrics, **default bundle** chips (open System categories bundle editor via `?openBundle={templateId}`).
- **Create system fields:** `metadata.system.fields.manage` — `POST /app/admin/metadata/fields` (label, key, type, options, optional template attachments). Site owner gets new permission via `PermissionSeeder`; run seeder or assign manually after deploy.
- **Suppression:** `metadata.system.visibility.manage` — per-field category suppression (unchanged).

## Visible category cap (tenant catalog)

- **API:** `GET /app/api/tenant/metadata/brands/{brand}/available-system-categories` returns `visible_category_limits` (`visible`, `max`, `at_cap` per `asset` and `deliverable`) and, per template, `visible_slots_remaining` and `visible_cap_blocks_add` (true when adding would create a **visible** folder but the brand is at cap).
- **UI:** Metadata → By category uses a compact filter + select when the catalog list is long, and disables **Add** when `visible_cap_blocks_add` is true (hidden-by-default templates can still be added at cap).

## Admin entry points

- **Primary:** Admin → **System categories** — templates, **Edit bundle**, **Seed preset** per row, optional presets on create.
- **Secondary:** **System Metadata Registry** — metrics, global suppression, **create system fields**, links into bundle editor.

## Operations

- After deploy, run migrations, then backfill bundles if needed:

```bash
php artisan metadata:backfill-system-category-field-defaults
```

Use `--force` only when replacing existing `system_category_field_defaults` rows from config.

- After deploy, assign `metadata.system.fields.manage` to site roles if you rely on the seeder (or re-run `PermissionSeeder` in non-production with care).
