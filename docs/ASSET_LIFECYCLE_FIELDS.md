# Asset lifecycle fields

Overview of fields that control asset visibility, publication, archival, approval, and processing. The **default asset grid** only shows assets that pass all lifecycle checks and have a category.

---

## 1. Visibility (status)

**Field:** `status` (enum `AssetStatus`)  
**Meaning:** Visibility in the system only — **not** processing state.

| Value    | Meaning |
|----------|--------|
| **VISIBLE** | Shown in grid/dashboard (default for uploaded assets). |
| **HIDDEN**  | Hidden from normal views (e.g. archived, manually hidden, pending approval). Can be made visible again. |
| **FAILED**  | Processing failed. Asset stays in storage; visibility is still controlled (can be VISIBLE or HIDDEN). |

- Processing completion is tracked by `thumbnail_status`, `metadata` flags, and `pipeline_completed_at`, not by `status`.
- Only a few callers are allowed to change `status` (e.g. `AssetProcessingFailureService` for FAILED); jobs must not mutate it.

---

## 2. Publication

**Fields:** `published_at`, `published_by_id`

- **published_at** (datetime, nullable): When the asset was published. `null` = unpublished.
- **published_by_id** (FK → users): User who published it.

**Helpers:** `$asset->isPublished()` → `published_at !== null`.

**Grid rule:** Unpublished assets (`published_at === null`) are excluded from the default grid. They appear only when the user applies an explicit lifecycle filter (e.g. “Unpublished”).

---

## 3. Archive

**Fields:** `archived_at`, `archived_by_id`

- **archived_at** (datetime, nullable): When the asset was archived. `null` = not archived.
- **archived_by_id** (FK → users): User who archived it.

**Helpers:** `$asset->isArchived()` → `archived_at !== null`.

**Grid rule:** Archived assets are excluded from the default grid. They appear only when the user filters by “Archived” (and has `asset.archive` permission).

---

## 4. Expiration (Phase M)

**Field:** `expires_at` (datetime, nullable)

- Optional “use by” date. When set and in the past, the asset is **expired**.
- **Helper:** `$asset->isExpired()` → `expires_at !== null && expires_at->isPast()`.

**Grid rule:** Expired assets are excluded by default. They appear only when filtering by “Expired” (with `asset.archive` permission).

---

## 5. Approval (Phase AF-1)

**Fields:** `approval_status`, `approved_at`, `approved_by_user_id`, `rejected_at`, `rejection_reason`, `approval_summary`, `approval_summary_generated_at`

**approval_status** (enum `ApprovalStatus`):

| Value          | Meaning |
|----------------|--------|
| **NOT_REQUIRED** | No approval workflow (default for most uploads). |
| **PENDING**      | Awaiting approval (e.g. contributor upload when brand requires approval). |
| **APPROVED**     | Approved. |
| **REJECTED**     | Rejected. |

**Grid rule:** In the default view, assets that are both **unpublished** and (pending or rejected) are excluded. Published assets can appear even if approval is pending/rejected. The “Pending publication” lifecycle filter shows pending/rejected assets (with role-based visibility).

---

## 6. Processing / pipeline (not visibility)

These describe **processing state**, not lifecycle visibility. They are used for completion checks and UI (e.g. “Processing…”, “Failed”).

**thumbnail_status** (enum `ThumbnailStatus`):  
`pending` | `processing` | `completed` | `failed` | `skipped`

**thumbnail_error**, **thumbnail_started_at**, **thumbnail_retry_count**, **thumbnail_last_retry_at**: Thumbnail job state and error.

**metadata flags (examples):**  
`processing_started`, `metadata_extracted`, `ai_tagging_completed`, `pipeline_completed_at`, etc.

**Grid rule:** Visibility does **not** depend on these. FAILED thumbnail or pipeline does not by itself hide the asset; `status` and the lifecycle fields above control visibility.

---

## 7. Category (required for grid)

**Field:** `metadata->category_id`

The default grid filters by `metadata->category_id`. If that is null or empty, the asset is **not** shown in the grid even if all lifecycle checks pass.

---

## 8. Soft delete

**Field:** `deleted_at` (datetime, nullable)

Soft-deleted assets are excluded from normal queries and from the grid.

---

## When is an asset visible in the default grid?

An asset is **visible in the default brand asset grid** only when **all** of the following hold (see `Asset::isVisibleInGrid()` and `scopeVisibleInGrid()`):

1. **Not deleted** — `deleted_at === null`
2. **Not archived** — `archived_at === null`
3. **Published** — `published_at !== null`
4. **Visibility status** — `status` is not `FAILED` or `HIDDEN` (i.e. `VISIBLE` for normal visibility)
5. **Category set** — `metadata->category_id` is set and non-empty

Expired assets are excluded by default (handled in `LifecycleResolver`); pending/rejected approval interacts with publication as above.

---

## Lifecycle filter states (UI)

When the user picks a lifecycle filter, the backend applies different rules (see `LifecycleResolver`):

| Filter                  | What’s shown |
|-------------------------|--------------|
| **(default)**           | Published, not archived, not expired, approval not blocking (and category set). |
| **pending_approval**    | Hidden, unpublished (pending approval). |
| **pending_publication** | Approval status pending or rejected (with role-based scope). |
| **unpublished**        | All unpublished assets. |
| **archived**            | All archived assets. |
| **expired**             | All expired assets (`expires_at <= now()`). |

Permissions (`asset.publish`, `metadata.bypass_approval`, `asset.archive`) control who can use each filter.

---

## Quick reference: main lifecycle columns

| Column               | Type     | Meaning |
|----------------------|----------|--------|
| `status`             | enum     | Visibility: visible / hidden / failed |
| `published_at`       | datetime | When published; null = unpublished |
| `published_by_id`    | FK       | User who published |
| `archived_at`        | datetime | When archived; null = not archived |
| `archived_by_id`     | FK       | User who archived |
| `expires_at`         | datetime | Optional expiry; past = expired |
| `approval_status`    | enum     | not_required / pending / approved / rejected |
| `approved_at`        | datetime | When approved |
| `approved_by_user_id`| FK       | Approver |
| `rejected_at`        | datetime | When rejected |
| `rejection_reason`   | string   | Reason if rejected |
| `deleted_at`         | datetime | Soft delete |
| `metadata->category_id` | mixed  | Required for grid visibility |
