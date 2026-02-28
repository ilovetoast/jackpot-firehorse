import { Link } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { ChartBarIcon, ServerIcon } from '@heroicons/react/24/outline'

export default function AdminPerformanceIndex({ auth, metrics, asset_url_metrics = null }) {
    const server = metrics?.server ?? {}
    const client = metrics?.client ?? {}
    const period = metrics?.period_hours ?? 24
    const config = metrics?.config ?? {}
    const assetUrlMetrics = asset_url_metrics ?? null
    const assetUrlAvgMs = assetUrlMetrics?.calls
        ? Math.round((assetUrlMetrics.total_time_ms / assetUrlMetrics.calls) * 100) / 100
        : null

    return (
        <div className="min-h-full bg-slate-50">
            <AppHead title="Performance" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Performance</h1>
                        <p className="mt-1 text-sm text-slate-600">Response timing, slow routes, client metrics (last {period}h)</p>
                    </div>
                    <Link href="/app/admin" className="text-sm text-slate-500 hover:text-slate-700">
                        ← Command Center
                    </Link>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Server metrics */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <ServerIcon className="h-5 w-5 text-slate-500" />
                            Server Response
                        </h2>
                        <div className="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">Avg response time</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {server.avg_duration_ms != null ? `${server.avg_duration_ms}ms` : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">95th percentile</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {server.p95_duration_ms != null ? `${server.p95_duration_ms}ms` : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">Slow requests</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {server.total_slow_requests ?? 0}
                                </p>
                            </div>
                        </div>
                        {server.slowest_routes?.length > 0 && (
                            <div className="mt-6">
                                <p className="text-xs font-medium text-slate-500 uppercase">Slowest routes</p>
                                <ul className="mt-2 space-y-2">
                                    {server.slowest_routes.map((r, i) => (
                                        <li key={i} className="flex justify-between text-sm">
                                            <span className="truncate max-w-[60%]" title={r.url}>
                                                {r.method} {r.url}
                                            </span>
                                            <span className="text-slate-600">{r.avg_ms}ms ({r.count})</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {(!server.avg_duration_ms && !server.slowest_routes?.length) && (
                            <p className="mt-4 text-sm text-slate-500">
                                {config.persist_all_requests
                                    ? 'No requests recorded yet. Persist all is enabled; data will appear after page loads.'
                                    : config.persist_slow_logs
                                    ? `No slow requests in the last 24h. Only requests exceeding ${config.slow_threshold_ms ?? 1000}ms are logged. Set PERFORMANCE_PERSIST_ALL_REQUESTS=true for avg metrics.`
                                    : 'Enable PERFORMANCE_PERSIST_SLOW_LOGS to record slow requests, or PERFORMANCE_PERSIST_ALL_REQUESTS=true for all requests.'}
                            </p>
                        )}
                    </div>

                    {/* Client metrics */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900">
                            <ChartBarIcon className="h-5 w-5 text-slate-500" />
                            Client (Page Load)
                        </h2>
                        <div className="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">Avg TTFB</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {client.avg_ttfb_ms != null ? `${client.avg_ttfb_ms}ms` : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">Avg load time</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {client.avg_load_ms != null ? `${client.avg_load_ms}ms` : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase">Avg image load</p>
                                <p className="mt-1 text-2xl font-bold text-slate-900">
                                    {client.avg_image_load_ms != null ? `${client.avg_image_load_ms}ms` : '—'}
                                </p>
                            </div>
                        </div>
                        {client.slowest_pages?.length > 0 && (
                            <div className="mt-6">
                                <p className="text-xs font-medium text-slate-500 uppercase">Slowest pages</p>
                                <ul className="mt-2 space-y-2">
                                    {client.slowest_pages.map((r, i) => (
                                        <li key={i} className="flex justify-between text-sm">
                                            <span className="truncate max-w-[60%]" title={r.path}>
                                                {r.path}
                                            </span>
                                            <span className="text-slate-600">{r.avg_ms}ms ({r.count})</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {(!client.avg_ttfb_ms && !client.slowest_pages?.length) && (
                            <p className="mt-4 text-sm text-slate-500">
                                {config.client_metrics_enabled
                                    ? 'No client metrics received yet. Metrics are sent once per session when pages load.'
                                    : 'Enable PERFORMANCE_CLIENT_METRICS_ENABLED to collect client metrics.'}
                            </p>
                        )}
                    </div>
                </div>

                <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    {assetUrlMetrics && (
                        <div className="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <h3 className="text-sm font-semibold text-slate-900">Asset URL Service Metrics</h3>
                            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Total Calls</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{assetUrlMetrics.calls ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Total Time</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{Math.round(assetUrlMetrics.total_time_ms ?? 0)}ms</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Avg Time / Call</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{assetUrlAvgMs != null ? `${assetUrlAvgMs}ms` : '—'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Existence Checks</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{assetUrlMetrics.existence_checks ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Tenant Cache</p>
                                    <p className="mt-1 text-sm text-slate-700">Hits: {assetUrlMetrics.tenant_cache_hits ?? 0} / Misses: {assetUrlMetrics.tenant_cache_misses ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Bucket Cache</p>
                                    <p className="mt-1 text-sm text-slate-700">Hits: {assetUrlMetrics.bucket_cache_hits ?? 0} / Misses: {assetUrlMetrics.bucket_cache_misses ?? 0}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase text-slate-500">Existence Cache Hits</p>
                                    <p className="mt-1 text-sm text-slate-700">{assetUrlMetrics.existence_cache_hits ?? 0}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    <h3 className="text-sm font-semibold text-slate-900">Configuration (runtime values)</h3>
                    <ul className="mt-2 space-y-1 text-sm text-slate-600">
                        <li>PERFORMANCE_MONITORING_ENABLED — <span className={config.enabled ? 'text-green-600 font-medium' : 'text-amber-600'}>{config.enabled ? 'enabled' : 'disabled'}</span></li>
                        <li>PERFORMANCE_PERSIST_SLOW_LOGS — <span className={config.persist_slow_logs ? 'text-green-600 font-medium' : 'text-amber-600'}>{config.persist_slow_logs ? 'enabled' : 'disabled'}</span></li>
                        <li>PERFORMANCE_PERSIST_ALL_REQUESTS — <span className={config.persist_all_requests ? 'text-green-600 font-medium' : 'text-amber-600'}>{config.persist_all_requests ? 'enabled' : 'disabled'}</span> (persist every request for avg)</li>
                        <li>PERFORMANCE_CLIENT_METRICS_ENABLED — <span className={config.client_metrics_enabled ? 'text-green-600 font-medium' : 'text-amber-600'}>{config.client_metrics_enabled ? 'enabled' : 'disabled'}</span></li>
                        <li>PERFORMANCE_SLOW_THRESHOLD_MS — {config.slow_threshold_ms ?? 1000}ms</li>
                        <li>ASSET_URL_METRICS — <span className={config.asset_url_metrics_enabled ? 'text-green-600 font-medium' : 'text-amber-600'}>{config.asset_url_metrics_enabled ? 'enabled' : 'disabled'}</span> (request-scoped URL generation counts; public download routes not included)</li>
                    </ul>
                    {assetUrlMetrics === null && config.asset_url_metrics_enabled && (
                        <p className="mt-2 text-sm text-slate-500">
                            Asset URL metrics show only for the request that loaded this page (usually zero). Download delivery uses public routes and is not included. See docs/PERFORMANCE_AND_ASSET_URL_METRICS.md.
                        </p>
                    )}
                    <p className="mt-3 text-xs text-slate-500">
                        If values are wrong, run <code className="bg-slate-100 px-1 rounded">php artisan config:clear</code> and restart the server.
                    </p>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
