# Operations: public & guest collection ZIP downloads

Jackpot builds **collection ZIP archives** on the app server (PHP), then uploads the finished ZIP to **tenant S3** and returns a **presigned URL** (public collection flow) or streams/downloads as configured elsewhere. This document explains **limits**, **disk and memory**, **timeouts**, and how to **vary behavior by environment** without filling small app disks.

Related product/security context: [SECURITY_DOWNLOADS_AND_EXTERNAL_ACCESS.md](./SECURITY_DOWNLOADS_AND_EXTERNAL_ACCESS.md).

---

## What enforces “how big” a download may be?

| Layer | What it does |
|--------|----------------|
| **Plan** (`config/plans.php`) | `max_download_zip_mb` and `max_download_assets` per plan — `PlanService::getMaxDownloadZipBytes` / `getMaxDownloadAssets`. Estimated ZIP size uses asset `metadata.file_size` / `metadata.size` / `size_bytes`. |
| **Server ceiling (optional)** | `COLLECTION_ZIP_SERVER_MAX_ESTIMATED_ZIP_MB` in `config/collection_zip.php` — when set &gt; 0, the effective limit is `min(plan_limit, server_ceiling)`. Use on **staging** or small app instances so a large tenant plan cannot exhaust `/tmp`. |
| **Throttling** | `routes/web.php` — `throttle` on public collection POST/GET as already configured; adjust if abuse is observed. |

Guests still **cannot** exceed the tenant plan; the server ceiling only **tightens** the cap.

---

## Disk and “don’t fill the server”

Build path (simplified):

1. For each asset: **stream** object from S3 to a **temp file** (chunked; bounded RAM).
2. **`ZipArchive::addFile`** reads those files into a **second temp file** (the `.zip`).
3. **`putObject`** uploads the ZIP from disk to S3, then local temps are deleted.

**Peak disk** (worst case, before ZIP upload completes) is roughly:

- Sum of sizes of members still on disk as temp parts **plus** the growing ZIP file (STORE compression avoids extra CPU but ZIP is still ~sum of raw sizes for already-compressed media).

**Mitigations:**

1. **`COLLECTION_ZIP_TEMP_DIRECTORY`** — Point to a dedicated mount (e.g. `/var/jackpot/tmp-zips`) with **hundreds of GB** free if you allow multi‑hundred‑GB plans. Avoid using root `/` or tiny root partitions for PHP temp.
2. **`COLLECTION_ZIP_SERVER_MAX_ESTIMATED_ZIP_MB`** — Cap staging (e.g. `2048`) independently of production.
3. **Monitoring** — Alert on disk use % on the volume backing `COLLECTION_ZIP_TEMP_DIRECTORY` / `sys_get_temp_dir()`.
4. **Long-term** — For routine **10+ GB** archives, move to an **async job** (queue worker builds ZIP, writes to S3, notifies user / poll URL). The synchronous POST remains vulnerable to **proxy timeouts** regardless of PHP memory.

---

## HTTP / PHP timeouts and nginx

The **POST** that triggers a full collection ZIP build may run **minutes** on large sets.

| Component | Guidance |
|-----------|----------|
| **nginx** | Raise `proxy_read_timeout`, `proxy_send_timeout`, `fastcgi_read_timeout` (or equivalent) above the worst-case build time, or users see **502/504**. |
| **PHP-FPM** | `request_terminate_timeout` must exceed the same; `max_execution_time` is set to **unlimited** during ZIP build in code, but FPM can still kill the worker if its pool timeout is lower. |
| **Load balancer** | Idle timeout must allow long POST bodies / responses. |

**Environment split:** staging can use **lower** `COLLECTION_ZIP_SERVER_MAX_ESTIMATED_ZIP_MB` and **stricter** nginx timeouts to fail fast; production uses larger disks and higher timeouts.

---

## Memory

ZIP assembly **does not** load each whole object into a PHP string; streaming keeps **RAM** bounded by chunk size plus ZIP/library overhead. Still set a realistic **`memory_limit`** (e.g. 512M–1G) for PHP — extreme concurrency of multiple ZIP builds can add pressure.

---

## Client / product side (UX)

- Public UI already warns on **large** “download all” actions; users should **stay on the step** until the browser receives the signed URL.
- **Partial downloads** (selected `asset_ids`) rebuild a one-off ZIP — same disk rules; smaller selections reduce peak temp use.
- **Cached full-collection ZIP** (`getOrBuildCachedZip`) reuses S3 object until collection invalidates — fewer rebuilds after the first success.

---

## Environment variables (reference)

| Variable | Purpose |
|----------|---------|
| `COLLECTION_ZIP_TEMP_DIRECTORY` | Optional absolute path; must exist and be writable. Defaults to PHP `sys_get_temp_dir()`. |
| `COLLECTION_ZIP_SERVER_MAX_ESTIMATED_ZIP_MB` | Optional `min()` with plan cap; `0` = disabled. |

See `.env.example` in the repo for commented samples.

---

## Tests

From the `jackpot/` directory (requires Docker for Sail):

```bash
./vendor/bin/sail test tests/Feature/PublicCollectionDownloadD6Test.php
```

If the host’s CLI PHP is older than the project requires (e.g. 8.0 while PHPUnit expects ≥ 8.2), use **Sail** or the same PHP version as production so dependency checks and extensions match.

Or run the full suite as appropriate for your pipeline.

---

## Change log

- **2026-05:** Documented ops model; added `config/collection_zip.php` + env-driven temp dir and optional server ZIP ceiling.
