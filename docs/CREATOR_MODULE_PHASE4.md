# Creator / Prostaff — Phase 4 (Performance tracking)

Phase 4 records **how many prostaff uploads** occurred in each **calendar** month, quarter, and year, compared to optional **targets** stored on `prostaff_memberships`. Counts are **incremented at upload completion**; the system does **not** scan the `assets` table for totals.

## Data model

### `prostaff_period_stats`

Each row is one bucket:

| Column | Role |
|--------|------|
| `prostaff_membership_id` | Which prostaff assignment |
| `period_type` | `month`, `quarter`, or `year` |
| `period_start` / `period_end` | Inclusive calendar boundaries (dates) |
| `target_uploads` | Target **snapshot** when the row was first created (nullable) |
| `actual_uploads` | Running count of completed new prostaff uploads in that bucket |
| `approved_uploads` | Reserved for future approval-flow increments (default `0`) |
| `rejected_uploads` | Reserved for future rejection tracking (default `0`) |
| `completion_percentage` | `min(100, round(actual / target * 100, 2))` when `target_uploads > 0`, else `0` |
| `last_calculated_at` | Updated whenever `actual_uploads` / completion are updated |

**Unique key:** `(prostaff_membership_id, period_type, period_start)` so each membership has at most one row per period bucket.

### Target vs actual

- **`prostaff_memberships.target_uploads`** and **`period_type`** describe the *configured* goal (e.g. 10 uploads per **month**).
- When a **new** `prostaff_period_stats` row is inserted, `target_uploads` is copied from the membership **only** for rows whose `period_type` matches the membership’s `period_type`. For other granularities (e.g. quarter row when the membership is monthly-only), `target_uploads` on that row is `null`; `actual_uploads` still increments so you can see volume, but completion % stays `0` unless you align targets in product later.
- **Changing** `prostaff_memberships.target_uploads` does **not** update existing stat rows. New buckets (e.g. next month) pick up the **current** target at **first insert** only.

## Period logic

`App\Services\Prostaff\ResolveProstaffPeriod::resolve(ProstaffMembership $membership, Carbon $date)` returns the three buckets that contain `$date`:

- **month:** `startOfMonth` … `endOfMonth`
- **quarter:** `startOfQuarter` … `endOfQuarter` (calendar quarters)
- **year:** `startOfYear` … `endOfYear`

The membership argument is reserved for future rules (e.g. fiscal calendar); boundaries today are pure Gregorian calendar.

## When counts increment

`App\Services\UploadCompletionService` runs inside a DB transaction. After a **new** asset row is created (not the duplicate-session path and not the early “asset already exists” return), if the upload is prostaff, it calls `App\Services\Prostaff\RecordProstaffPerformanceIncrement::record()`.

That service:

1. Locks the active `prostaff_membership` for `(user, brand)`.
2. For `month`, `quarter`, and `year`, resolves bounds and creates the stat row if missing.
3. Reloads that row with `WHERE id = ? FOR UPDATE`, then increments `actual_uploads` in PHP, recomputes `completion_percentage`, and sets `last_calculated_at` (avoids lost updates under concurrent uploads).

No approval changes, no jobs, no aggregation queries over `assets`.

## User helper

`User::getProstaffStatsForBrand(Brand $brand)` returns:

- `membership`: active prostaff row or `null`
- `periods`: for `month`, `quarter`, `year`, the **current** bucket’s bounds plus `actual_uploads`, `target_uploads`, `completion_percentage`, and `stat_id` (if a row exists)

## Related code

- `database/migrations/2026_04_06_120000_create_prostaff_period_stats_table.php`
- `database/migrations/2026_04_06_130000_add_approval_counters_to_prostaff_period_stats_table.php`
- `App\Models\ProstaffPeriodStat`
- `App\Services\Prostaff\ResolveProstaffPeriod`
- `App\Services\Prostaff\RecordProstaffPerformanceIncrement`
- `App\Services\UploadCompletionService`
- `App\Models\User::getProstaffStatsForBrand()`

## Tests

`tests/Feature/ProstaffPerformanceTest.php`
