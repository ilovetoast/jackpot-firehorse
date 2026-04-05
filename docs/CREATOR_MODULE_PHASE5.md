# Creator / Prostaff — Phase 5 (Batched approver notifications)

Prostaff **new uploads** no longer trigger **per-asset** approver alerts (`notifyOnSubmitted` / pending-approval email). Instead, uploads are grouped into **`prostaff_upload_batches`** and a delayed queue job sends **one in-app notification** per batch window.

## Batching rules

### Batch key

Format:

` t{tenant_id}_b{brand_id}_u{prostaff_user_id}_{UTC slot} `

Example: `t1_b5_u22_2026-04-06-10:00`

The **slot** is the start of a fixed **UTC** window whose length comes from `config('prostaff.batch_window_minutes')` (default **5**, env `PROSTAFF_BATCH_WINDOW_MINUTES`). All uploads from the same prostaff member to the same brand in the same window share **one** batch row.

### Row updates (`RecordProstaffUploadBatch::record`)

On each qualifying upload (after DB commit):

1. Compute `batch_key`.
2. `findOrCreate` the batch (row lock).
3. Increment `upload_count`, set `last_asset_id` and `last_activity_at`, set `first_asset_id` on first insert.
4. Dispatch `ProcessProstaffUploadBatchJob` with the `batch_key` (may be dispatched multiple times per batch; the job debounces itself).

### Job timing (`ProcessProstaffUploadBatchJob`)

The job loads the batch with **`FOR UPDATE`**.

- **`notifications_sent_at`** — If set, the job exits immediately (**idempotent**: no duplicate in-app notifications after a successful send).
- **Quiet window** — The job waits until `now() >= last_activity_at + batch_window_minutes` (from `config/prostaff.php`), unless…
- **Hard cap** — If `now() >= started_at + max_batch_duration_minutes` (default **30**, `PROSTAFF_MAX_BATCH_DURATION_MINUTES`), it **sends anyway** so trickle uploads cannot defer forever.
- **Wake time** — When not ready, it **`release()`s** until `min(quiet_until, cap_until)` so the next run is aligned with whichever bound comes first.

**Claim + finalize:** A row-level update sets **`processed_at`** as a short-lived claim before calling `notifyProstaffUploadBatch`. After a successful send, **`notifications_sent_at`** and **`processed_at`** are set together. On failure, the claim is cleared. Stale claims (`processed_at` set but **`notifications_sent_at` still null** for longer than `max_batch_duration + batch_window`) are released so a dead worker cannot block the batch forever.

## Notification flow

1. **Recipients** — `ResolveProstaffBatchNotificationRecipients`:
   - Prefer users with active brand membership who have `config('prostaff.batch_notification_permission')` (default `brand.prostaff.approve`) on the brand or tenant.
   - If none, fall back to **`ApprovalNotificationService::approvalCapableRecipientsForBrand()`** (same resolver as normal approvals: brand approver roles + tenant admin/owner).
   - The prostaff uploader is excluded.

2. **Delivery** — `ApprovalNotificationService::notifyProstaffUploadBatch()` uses **`NotificationGroupService`** with type **`prostaff.upload.batch`** and payload including `message` like `3 uploads from {name}`.

3. **Plan gate** — Same as other approval notifications: `FeatureGate::notificationsEnabled($tenant)` must be true.

## What stays unchanged

- Approve / reject / resubmit flows and **`AssetApprovalController`** are untouched.
- Non-prostaff uploads still use existing per-asset / email paths.
- **`SendAssetPendingApprovalNotification`** returns early for **`isProstaffAsset()`** so category-based **emails** are not sent for prostaff.

## Configuration

- `config/prostaff.php` — `batch_window_minutes`, `batch_notification_permission`.

## Related code

- `database/migrations/2026_04_06_140000_create_prostaff_upload_batches_table.php`
- `database/migrations/2026_04_06_150000_add_notifications_sent_at_to_prostaff_upload_batches_table.php`
- `App\Models\ProstaffUploadBatch`
- `App\Services\Prostaff\BuildProstaffUploadBatchKey`
- `App\Services\Prostaff\RecordProstaffUploadBatch`
- `App\Services\Prostaff\ResolveProstaffBatchNotificationRecipients`
- `App\Jobs\ProcessProstaffUploadBatchJob`
- `App\Services\UploadCompletionService` (`DB::afterCommit` batch recording)
- `App\Services\ApprovalNotificationService::notifyProstaffUploadBatch()`

## Tests

`tests/Feature/ProstaffBatchNotificationTest.php`
