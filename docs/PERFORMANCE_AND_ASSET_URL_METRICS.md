# Performance Dashboard & Asset URL Metrics

## Why the Performance page shows no data

### Server Response (avg 0ms, no slow requests)

- **Source:** `performance_logs` table, filled by `ResponseTimingMiddleware`.
- **When rows are written:**
  - **Option A:** Only requests **slower than** `PERFORMANCE_SLOW_THRESHOLD_MS` (default 1000ms), and only if `PERFORMANCE_PERSIST_SLOW_LOGS=true`.
  - **Option B:** **Every** request if `PERFORMANCE_PERSIST_ALL_REQUESTS=true` (needed for averages).
- **So:** With `PERFORMANCE_PERSIST_ALL_REQUESTS` **disabled** and no requests over the threshold, nothing is stored → avg and 95th stay empty. Enable `PERFORMANCE_PERSIST_ALL_REQUESTS=true` to get average response time and 95th percentile.

### Client (Page Load) — “No client metrics received yet”

- **Source:** `client_performance_metrics` table, filled when the frontend POSTs to `/app/performance/client-metric` (legacy `/app/admin/performance/client-metric` still works).
- **When it runs:** `initPerformanceTracking()` in `app.jsx` runs on app load; it sends **once per session** (after the first full page load, with a 2s delay), and only if `window.__performanceMetricsEnabled` is true (from `PERFORMANCE_CLIENT_METRICS_ENABLED`).
- **Cookie consent (main reason for zeros):** The tracker **returns immediately** unless `allowsAnalyticsCookies()` is true (`resources/js/utils/performanceTracking.js`). That requires the user to have accepted **analytics** for the **current** cookie policy version in the banner. If analytics is off or consent is outdated, **no POST is sent** — the Client section stays empty even when config flags are green.
- **So:** If the table is empty, check consent first, then whether a full page load completed, then Network for `client-metric` (403 = `PERFORMANCE_CLIENT_METRICS_ENABLED` false). The route uses `web` middleware only (no auth).

### Asset URL Service Metrics (ASSET_URL_METRICS)

- **What it is:** In-request counters from `AssetUrlService` (calls, admin_thumbnail_calls, public_download_calls, total_time_ms, cache hits, etc.).
- **Where it’s collected:** `CollectAssetUrlMetrics` middleware runs only on routes under the **`app`** prefix (authenticated app).
- **Important:** These metrics are **per request** and **not persisted**. The Performance page shows the metrics for **the request that loaded the Performance page itself**. That request usually does almost no AssetUrlService work, so you typically see zeros.
- **To see non-zero values:** Enable `ASSET_URL_METRICS=true`, then open a page that does a lot of URL generation (e.g. Admin Assets grid) in another tab; the numbers you see on the Performance page are still only for the **Performance page request**, not for the grid. There is no aggregation across requests unless you add it (e.g. persist to a table).

---

## Practical recommendations

### If you want to keep the Performance dashboard

1. **Confirm data exists (e.g. staging DB):**
   ```sql
   SELECT COUNT(*) FROM performance_logs;
   SELECT COUNT(*) FROM client_performance_metrics;
   ```
   Run migrations if either table is missing (`database/migrations/*performance*`).

2. **Server metrics still 0 after browsing with persist-all on?**
   - Ensure `PERFORMANCE_MONITORING_ENABLED=true` and `PERFORMANCE_PERSIST_ALL_REQUESTS=true` (or slow + `PERFORMANCE_PERSIST_SLOW_LOGS` and requests above the threshold).
   - The dashboard aggregates the **last 24 hours** — you need rows with `created_at` in that window.
   - Check application logs for `[PerformanceLog] Failed to persist` (DB errors, permissions).

3. **Client metrics still 0?**
   - **Accept analytics** in the cookie banner (or update consent after a policy change).
   - **Optional product change:** treat perf beacons as **functional** (not analytics) so they send without marketing/analytics consent — small, intentional privacy review; see `allowsAnalyticsCookies()` vs `allowsFunctionalCookies()` in `resources/js/utils/cookieConsent.js` and `performanceTracking.js`.

### If you want to drop the dashboard

- Remove or hide **Admin → Performance** in nav only after you rely on an external APM (Sentry Performance, Datadog, etc.).
- Then remove or disable **`ResponseTimingMiddleware`** persistence (or the middleware entirely) so you do not leave code writing to `performance_logs` without a UI. Keep or prune routes/migrations according to whether you still want historical rows.

### Mental model

The page is **not necessarily broken**: client metrics are easy to show as **all zeros** because of the **analytics consent gate**. Server metrics need a **working DB**, successful writes, and activity in the **rolling 24h** window.

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
| Server avg / 95th | Performance page → Server Response | Need `PERFORMANCE_PERSIST_ALL_REQUESTS=true` (or slow requests + `PERFORMANCE_PERSIST_SLOW_LOGS=true`); rows in last **24h** |
| Client TTFB / load | Performance page → Client | **`PERFORMANCE_CLIENT_METRICS_ENABLED`**, then **analytics cookie consent**; POST once per session to `client-metric` |
| Asset URL call counts | Performance page → Asset URL Service Metrics | Request-scoped; only for the request that loaded the page. Enable `ASSET_URL_METRICS=true`. Public download routes are not included. |
| Download counts (zip/single) | Download analytics (asset_metrics) | Separate from Performance page; recorded when file is delivered. |
