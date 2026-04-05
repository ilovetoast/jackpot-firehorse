# Creator module — Phase 6: Prostaff dashboards (API)

Phase 6 exposes prostaff performance summaries for brand managers and self-service data for prostaff users. Totals come from **precomputed** `prostaff_period_stats` rows (joined to active `prostaff_memberships`), not from aggregating the assets table.

## Routes (app prefix)

All routes live under the authenticated `app` prefix (same middleware as other tenant APIs):

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/app/api/brands/{brand}/prostaff/dashboard` | Manager dashboard: all active prostaff for the brand |
| GET | `/app/api/prostaff/me?brand_id={id}` | Current user’s prostaff stats and recent prostaff-tagged uploads for that brand |

`brand_id` on `/prostaff/me` is required so the endpoint is unambiguous when a user belongs to multiple brands.

## Authorization

- **Manager dashboard:** User must have an active brand membership for `{brand}`, and must **not** be a plain **contributor** unless they are also a **tenant owner/admin** or **brand_manager** on that brand (aligned with `GET /app/api/brands/{brand}/pending-assets`).
- **`/prostaff/me`:** User must pass `BrandPolicy::view` for the brand and be an **active prostaff** member (`User::isProstaffForBrand`).
- **Tenant scope:** `{brand}` must belong to the resolved tenant (`app('tenant')`).

## GET `/app/api/brands/{brand}/prostaff/dashboard`

JSON array, one object per **active** prostaff membership.

**Order:** Rows are sorted by `completion_percentage` **descending**, then `user_id` ascending for ties. Each row includes **`rank`** (`1` = highest completion in the brand).

### Row shape

| Field | Type | Notes |
|-------|------|--------|
| `user_id` | int | |
| `name` | string | Display name |
| `target_uploads` | int \| null | From current period stat if present, else membership default |
| `actual_uploads` | int | From `prostaff_period_stats` for the resolved period; **`0` if no stat row** |
| `completion_percentage` | float | From stat; **`0` if no stat row** |
| `is_on_track` | bool | `true` when completion **≥ 100%**; **`false` if no stat row** |
| `status` | string | Derived from completion — see **Performance status** below |
| `period_type` | string | `month`, `quarter`, or `year` from membership (default `month`) |
| `period_start` | string (date) | Calendar period start for that type |
| `period_end` | string (date) | Calendar period end for that type |
| `rank` | int | Leaderboard position for this brand (1 = top completion %) |

### Current period

For each membership, the service uses `ResolveProstaffPeriod` calendar bounds for the membership’s `period_type` and loads the matching `prostaff_period_stats` row by `(prostaff_membership_id, period_type, period_start)`.

**Query shape:** `user` is eager-loaded with minimal columns (`id`, `first_name`, `last_name`, `email`). Stats for all memberships are loaded in **one** batched query (each row can use a different `period_type` / `period_start`, so this is an OR of composite keys—not a single shared `period_start` on the relation).

## GET `/app/api/prostaff/me?brand_id=`

JSON object.

### Fields

| Field | Type | Notes |
|-------|------|--------|
| `target_uploads` | int \| null | Same resolution as manager row |
| `actual_uploads` | int | **`0` if no stat row** for the current period |
| `completion_percentage` | float | **`0` if no stat row** |
| `is_on_track` | bool | ≥ 100% completion; **`false` if no stat row** |
| `status` | string | **Performance status** (below) |
| `period_type` | string | |
| `period_start` | string (date) | |
| `period_end` | string (date) | |
| `uploads` | array | Up to 50 recent assets where `submitted_by_prostaff` and `prostaff_user_id` = current user |

### Upload item shape

| Field | Type | Notes |
|-------|------|--------|
| `asset_id` | string | |
| `status` | string | `approval_status` value (e.g. `pending`, `approved`) |
| `created_at` | string | ISO-8601 |

## Performance status (`status`)

UI-oriented banding from **completion percentage** (independent of `is_on_track`):

| Condition | `status` |
|-----------|----------|
| &lt; 50% | `behind` |
| 50%–99% (inclusive of 50, exclusive of 100) | `on_track` |
| ≥ 100% | `complete` |

## Asset grid filters

The asset index query accepts:

- `submitted_by_prostaff=1` (or `true`) — only assets with `submitted_by_prostaff = true`
- `prostaff_user_id={int}` — only assets attributed to that prostaff user

These keys are **reserved** query parameters (not treated as metadata field filters). They apply to the main grid query and filter-visibility base query, consistent with `uploaded_by`.

## Implementation notes

- **Service:** `App\Services\Prostaff\GetProstaffDashboardData`
- **Controller:** `App\Http\Controllers\Prostaff\ProstaffDashboardController`
- **Tests:** `tests/Feature/ProstaffDashboardTest.php`
