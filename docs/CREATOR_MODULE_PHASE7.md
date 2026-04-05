# Creator module — Phase 7: DAM prostaff filters & API integration

Phase 7 wires prostaff into DAM **query params**, **asset JSON**, **additive filter config props** (no change to metadata `filterable_schema` structure), and the **pending-assets** API.

## Query parameters (asset grid)

Same pattern as `uploaded_by`: reserved keys, applied to **`$assetsQuery`** and **`$baseQueryForFilterVisibility`** in `AssetController::index`.

| Key | Type | Effect |
|-----|------|--------|
| `submitted_by_prostaff` | boolean (`1`, `true`, etc.) | `WHERE assets.submitted_by_prostaff = true` |
| `prostaff_user_id` | positive integer | `WHERE assets.prostaff_user_id = ?` |

Typical combinations:

- Prostaff-only: `?submitted_by_prostaff=1`
- One member: `?submitted_by_prostaff=1&prostaff_user_id=42`

## GET `/app/api/brands/{brand}/prostaff/options`

Returns **active** prostaff memberships for the brand (tenant must match resolved tenant).

**Auth:** `BrandPolicy::view` for `{brand}`.

**Response:** JSON array:

```json
[
  { "user_id": 12, "name": "Alex Creator" },
  { "user_id": 34, "name": "Jamie Smith" }
]
```

- `name` uses the user’s display name, falling back to email if the name is empty.
- Users without a `users` row are skipped.
- Status **`active`** only (`paused` / `removed` excluded).

## Inertia props (additive)

On full `Assets/Index` responses (when tenant + brand are resolved):

| Prop | Description |
|------|-------------|
| `prostaff_filter_options` | Same shape as the options endpoint: `list<{ user_id, name }>` |
| `dam_prostaff_filter_config` | Static definitions for a future UI (boolean + select); does **not** append to `filterable_schema` |

`dam_prostaff_filter_config` shape:

```json
{
  "filters": [
    {
      "key": "submitted_by_prostaff",
      "type": "boolean",
      "query_param": "submitted_by_prostaff",
      "label": "Prostaff uploads"
    },
    {
      "key": "prostaff_user_id",
      "type": "select",
      "query_param": "prostaff_user_id",
      "label": "Prostaff member",
      "options_prop": "prostaff_filter_options"
    }
  ]
}
```

When tenant/brand are missing, `prostaff_filter_options` is `[]` and `dam_prostaff_filter_config` is still the static object.

## Asset JSON (grid / `format=json` / `load_more`)

Each mapped asset includes:

| Field | Type | Notes |
|-------|------|--------|
| `submitted_by_prostaff` | bool | From `assets.submitted_by_prostaff` |
| `prostaff_user_id` | int \| null | |
| `prostaff_user_name` | string \| null | From eager-loaded `prostaffUser` (name or email) |
| `is_prostaff_asset` | bool | Alias of `submitted_by_prostaff` (UI flag) |

Virtual Google Font rows include the same keys with `false` / `null` as appropriate.

## Pending assets API

`GET /app/api/brands/{brand}/pending-assets` — each asset in `assets[]` now includes:

- `is_prostaff_asset` — same meaning as `submitted_by_prostaff` on the model.

## Implementation reference

- Filter query: `App\Http\Controllers\AssetController`
- Options + filter config builder: `App\Services\Prostaff\GetProstaffDamFilterOptions`
- Route: `api.brands.prostaff.options` → `ProstaffDashboardController::filterOptions`
- Tests: `tests/Feature/ProstaffFilterTest.php`

## Usage examples

**Options (fetch dropdown data):**

```http
GET /app/api/brands/5/prostaff/options
Cookie: laravel_session=...
```

**Filter grid (JSON):**

```http
GET /app/assets?category=logos&format=json&submitted_by_prostaff=1&prostaff_user_id=12
```

**Inertia page:** read `page.props.prostaff_filter_options` and `page.props.dam_prostaff_filter_config` when building filter UI (Phase 7+ frontend).
