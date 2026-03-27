# Fonts library (DAM + Brand Guidelines)

## Overview

- **Uploaded font files** (WOFF, TTF, etc.) are normal `Asset` rows in the **Fonts** system category (`slug`: `fonts`). They are synced from Brand Guidelines when `model_payload.typography.fonts[].file_urls` reference DAM assets (see `BrandFontLibrarySyncService`).
- **Google Fonts** (no file in the DAM) are declared in Brand DNA JSON with `source: "google"` and appear as **virtual grid rows** on the **Fonts** category view (page 1 only). They are not `Asset` records.

## Brand DNA: Google Fonts shape

Per entry under `model_payload.typography.fonts[]`:

| Field | Required | Description |
|--------|----------|-------------|
| `name` | Yes | Google family name (must match CSS `font-family` / Google Fonts API). |
| `source` | Yes | Must be `"google"` for hosted Google Fonts. |
| `role` | Optional | Used with the same role → `font_role` mapping as licensed uploads (`primary`, `secondary`, `body`, … → `headline` or `body_copy`). |
| `stylesheet_url` | Optional | If set, must be `https://…` — overrides the default `css2?family=…` URL (custom weights/subsets). |

Default stylesheet URLs are built with `App\Support\Typography\GoogleFontStylesheetHelper` (shared with `EditorBrandContextController` for the editor).

## Virtual rows (grid)

- **Service**: `App\Services\BrandDNA\GoogleFontLibraryEntriesService::virtualAssetsForFontsCategory`
- **Controller**: `AssetController` prepends virtual rows when `category=fonts`, page 1, not staged/reference/trash, not `load_more`.
- **Dedupe**: If a real asset already exists in the Fonts category with the same **title** as the Google `name` (case-insensitive), the virtual row is omitted so you do not see duplicates.

## Frontend

- Virtual items include `is_virtual_google_font`, `google_font_stylesheet_url`, and `google_font_family`.
- `AssetCard` injects the stylesheet `<link>` once and renders the **Aa** preview with the loaded family.
- Clicks do not open `AssetDrawer` (there is no asset id).

## Tests

- `tests/Unit/Support/Typography/GoogleFontStylesheetHelperTest.php` — URL building and custom `stylesheet_url`.
- `tests/Unit/Services/BrandDNA/GoogleFontLibraryEntriesServiceTest.php` — early exits without a database.

End-to-end tests that run `migrate:fresh` depend on your MySQL/migration setup; verify virtual rows manually with `?category=fonts` when Brand DNA includes `source: "google"` fonts.
