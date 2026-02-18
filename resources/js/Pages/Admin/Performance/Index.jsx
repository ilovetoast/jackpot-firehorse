import { Head, Link } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { ChartBarIcon, ServerIcon } from '@heroicons/react/24/outline'

export default function AdminPerformanceIndex({ auth, metrics }) {
    const server = metrics?.server ?? {}
    const client = metrics?.client ?? {}
    const period = metrics?.period_hours ?? 24

    return (
        <div className="min-h-full bg-slate-50">
            <Head title="Performance - Admin" />
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
                                Enable PERFORMANCE_PERSIST_SLOW_LOGS to record slow requests.
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
                                Enable PERFORMANCE_CLIENT_METRICS_ENABLED to collect client metrics.
                            </p>
                        )}
                    </div>
                </div>

                <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 className="text-sm font-semibold text-slate-900">Configuration</h3>
                    <ul className="mt-2 space-y-1 text-sm text-slate-600">
                        <li>PERFORMANCE_MONITORING_ENABLED — response timing middleware</li>
                        <li>PERFORMANCE_PERSIST_SLOW_LOGS — store slow requests in DB</li>
                        <li>PERFORMANCE_CLIENT_METRICS_ENABLED — accept client metrics</li>
                        <li>PERFORMANCE_SLOW_THRESHOLD_MS — slow request threshold (default 1000)</li>
                    </ul>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
