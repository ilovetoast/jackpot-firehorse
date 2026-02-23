# Performance Dashboard & Asset URL Metrics

## Why the Performance page shows no data

### Server Response (avg 0ms, no slow requests)

- **Source:** `performance_logs` table, filled by `ResponseTimingMiddleware`.
- **When rows are written:**
  - **Option A:** Only requests **slower than** `PERFORMANCE_SLOW_THRESHOLD_MS` (default 1000ms), and only if `PERFORMANCE_PERSIST_SLOW_LOGS=true`.
  - **Option B:** **Every** request if `PERFORMANCE_PERSIST_ALL_REQUESTS=true` (needed for averages).
- **So:** With `PERFORMANCE_PERSIST_ALL_REQUESTS` **disabled** and no requests over the threshold, nothing is stored → avg and 95th stay empty. Enable `PERFORMANCE_PERSIST_ALL_REQUESTS=true` to get average response time and 95th percentile.

### Client (Page Load) — “No client metrics received yet”

- **Source:** `client_performance_metrics` table, filled when the frontend POSTs to `/app/admin/performance/client-metric`.
- **When it runs:** `initPerformanceTracking()` in `app.jsx` runs on app load; it sends **once per session** (after the first full page load, with a 2s delay), and only if `window.__performanceMetricsEnabled` is true (from `PERFORMANCE_CLIENT_METRICS_ENABLED`).
- **So:** If the table is empty, either no page under the main app has finished loading since enabling, or the POST is failing (check Network tab for `/app/admin/performance/client-metric`). The route uses `web` middleware only (no auth), so 403 from auth is unlikely.

### Asset URL Service Metrics (ASSET_URL_METRICS)

- **What it is:** In-request counters from `AssetUrlService` (calls, admin_thumbnail_calls, public_download_calls, total_time_ms, cache hits, etc.).
- **Where it’s collected:** `CollectAssetUrlMetrics` middleware runs only on routes under the **`app`** prefix (authenticated app).
- **Important:** These metrics are **per request** and **not persisted**. The Performance page shows the metrics for **the request that loaded the Performance page itself**. That request usually does almost no AssetUrlService work, so you typically see zeros.
- **To see non-zero values:** Enable `ASSET_URL_METRICS=true`, then open a page that does a lot of URL generation (e.g. Admin Assets grid) in another tab; the numbers you see on the Performance page are still only for the **Performance page request**, not for the grid. There is no aggregation across requests unless you add it (e.g. persist to a table).

---

## Where “download zip” / download metrics live

- **Business download metrics** (who downloaded what, counts per asset) are **not** on the Performance page. They are:
  - **Recorded by:** `AssetDownloadMetricService::recordFromDownload()` when a file is delivered (single-asset or ZIP) at `DownloadController::deliverFile` (and similar paths).
  - **Stored in:** `asset_metrics` table (`metric_type = download`). Used for download analytics (e.g. asset breakdown, counts), not for performance timing.
- **Performance-related** download behavior:
  - **Public download delivery** (`/d/{download}/file`, `/public/download/{asset}`) does **not** go through the `app` middleware stack, so **no** `CollectAssetUrlMetrics` and **no** AssetUrlService metrics for that request on the Performance page.
  - If you want **server timing** for download delivery (e.g. slow redirects), you’d need to either enable `PERFORMANCE_PERSIST_ALL_REQUESTS` (so those requests are logged by `ResponseTimingMiddleware` if that middleware runs on public routes) or add a dedicated log for download delivery. Currently `ResponseTimingMiddleware` is in the global `web` stack, so public download requests **are** timed and can be persisted if `persist_all_requests` or `persist_slow_logs` (and request &gt; threshold) is on.

---

## Summary

| What you want | Where it is | Why it might be empty |
|---------------|-------------|------------------------|
| Server avg / 95th | Performance page → Server Response | Need `PERFORMANCE_PERSIST_ALL_REQUESTS=true` (or slow requests + `PERFORMANCE_PERSIST_SLOW_LOGS=true`) |
| Client TTFB / load | Performance page → Client | Client must POST once per session; check `PERFORMANCE_CLIENT_METRICS_ENABLED` and Network for `client-metric` |
| Asset URL call counts | Performance page → Asset URL Service Metrics | Request-scoped; only for the request that loaded the page. Enable `ASSET_URL_METRICS=true`. Public download routes are not included. |
| Download counts (zip/single) | Download analytics (asset_metrics) | Separate from Performance page; recorded when file is delivered. |
