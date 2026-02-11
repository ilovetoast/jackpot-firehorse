# display_widget: Consistent UI for metadata fields

## Purpose

The **display_widget** column on `metadata_fields` controls how a field is rendered across the app so the same field doesn’t appear as a checkbox in one place and a dropdown in another. Set it when you want a field to always use a specific control (e.g. toggle, select).

## Supported values

- **`toggle`** – Boolean shown as an on/off switch. Used in:
  - **Edit modal (drawer):** Click "Edit" on the field → modal opens with a **toggle** using the **active brand primary color** (track and Save button). Applies to any boolean field with `display_widget = 'toggle'` or the system field `starred`.
  - **Filters (grid):** Primary/secondary filters render it as a toggle.
  - **Upload:** Rendered as a toggle in the upload metadata section.
- **`select`** – Explicit dropdown (schema can still define options).
- **`color`** – Used for fields like `dominant_color_bucket` (swatch UI).

Other values may be added (e.g. `rating`, `date`) as needed.

## Where it’s enforced

| Place | Behavior |
|-------|----------|
| **MetadataFilterService** (getFilterableFields) | Sets `display_widget: 'toggle'` for `starred` if not already set; passes through from schema for other fields. |
| **AssetMetadataEditModal** | If `field.type === 'boolean'` and `field.display_widget === 'toggle'` (or `field.key === 'starred'`), renders a **toggle in the modal** with **primaryColor** (brand primary). |
| **AssetMetadataDisplay** (drawer) | Starred and other booleans show as "Yes/No" with **Edit** → modal; the modal shows the toggle. No inline toggle in the drawer. |
| **AssetGridMetadataPrimaryFilters / AssetGridSecondaryFilters** | Boolean with `display_widget === 'toggle'` (or key `starred`) rendered as toggle in filter UI. |
| **Upload (MetadataFieldInput)** | When `field.display_widget === 'toggle'`, renders as toggle. |

## Adding a new custom boolean as a toggle

1. Create or edit the metadata field (e.g. in Metadata Management or seeder).
2. Set **display_widget** to `'toggle'`.
3. The edit modal (drawer), filters, and upload will show it as a toggle. The drawer uses **Edit** → **modal** with the toggle styled with the **active brand primary color**.

## Starred

The system field **starred** is configured with `display_widget = 'toggle'` (see `MetadataFieldsSeeder`). It is always edited via **Edit** → **modal** with a brand-colored toggle, not inline in the drawer.
