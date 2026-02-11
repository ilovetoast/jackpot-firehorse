# Starred field: canonical value and single source of truth

## Summary

The **starred** system field is represented as a **boolean** everywhere for grid, sort, filter, and display. One source of truth avoids inconsistent UI (e.g. star icon not showing) and sort/filter bugs.

## Single source of truth

- **Grid, sort, filter:** `assets.metadata` JSON column, key `starred`, value **strict boolean** (`true` or `false`).
- **Editable store:** `asset_metadata` table holds the value users set; it is **synced** into `assets.metadata.starred` on every save so sort/filter/grid read from one place.

## Where it’s used

| Layer | What we use | Normalization |
|-------|-------------|---------------|
| **Write (user sets starred)** | `AssetMetadataController::editMetadata` → `syncSortFieldToAsset` | Always writes **boolean** to `assets.metadata.starred`. |
| **Sort** | `AssetSortService` | Reads `metadata->starred`; accepts true/'true'/1 for backward compatibility; we now write only boolean. |
| **Filter** | `MetadataFilterService::applyStarredFilter` | Same: reads from `assets.metadata`; we write only boolean. |
| **Grid payload** | `AssetController` / `DeliverableController` | Read **only** from `assets.metadata.starred`; `assetIsStarred()` normalizes to bool; send `starred: true|false` to frontend. |
| **Editable API** | `AssetMetadataController::getEditableMetadata` | `current_value` for starred is normalized to **boolean** so drawer toggle and filters see Yes/No consistently. |
| **Frontend** | `AssetCard`, `AssetMetadataDisplay`, filter UIs | Use **only** `asset.starred === true` (boolean). No fallback to `asset.metadata.starred`. |

## Removed: dual source / fallback

Previously the grid payload could set `starred` from either `assets.metadata.starred` or an `asset_metadata` query (so the card star showed even when sync hadn’t run). That was removed so there is **one** source:

- **Correct source:** `assets.metadata.starred` (boolean).
- **Keeping in sync:** Every save of starred (edit, approve, etc.) calls `syncSortFieldToAsset`, which writes a boolean to `assets.metadata`.
- **Legacy data:** If some assets have starred only in `asset_metadata` and not in `assets.metadata`, run the backfill:
  ```bash
  php artisan metadata:sync-sort-to-assets
  php artisan metadata:sync-sort-to-assets --dry-run  # preview
  ```
  The command writes **boolean** for `starred` into `assets.metadata`.

## Reading: `assetIsStarred($value)`

Defined on `Controller` (base). Used when building grid payload and in editable normalization. Treats as “starred” only: `true`, `'true'`, `1`, `'1'`. Everything else (including `false`, `null`, `''`) is not starred. This covers legacy JSON that might still have strings or integers until backfilled.

## Summary

- **Store (assets.metadata):** boolean only.
- **Grid payload:** `starred` key is boolean; source is `assets.metadata.starred` only.
- **Frontend:** Use `asset.starred === true` only; no fallback.
- **Backfill:** `metadata:sync-sort-to-assets` writes boolean for starred from `asset_metadata` into `assets.metadata`.

## Seeders

When creating assets with metadata (e.g. `DevelopmentDataSeeder`), **always sync sort fields to the asset root** so the grid shows the star icon. The grid reads only `assets.metadata.starred` (root), not `metadata.fields.starred`. If you only write to `asset_metadata` or to `metadata.fields`, the drawer will show "Starred: Yes" but the tile will not show the star.

- **DevelopmentDataSeeder** calls `syncSortFieldsToAssetRoot($asset, $metadataFieldsData)` after `ensureApprovedMetadataForAsset` so `starred` and `quality_rating` are written to `assets.metadata` root.
- Any other seeder that sets `starred` or `quality_rating` should do the same (sync those keys to `asset->metadata` root and save), or run `php artisan metadata:sync-sort-to-assets` after seeding.
