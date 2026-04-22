import { useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AppHead from '../../../Components/AppHead'
import { FilmIcon, SparklesIcon, PhotoIcon } from '@heroicons/react/24/outline'
import VideoInsightRunDetailPanel from './VideoInsightRunDetailPanel'

const TILES = [
    {
        name: 'Brand Intelligence',
        description: 'EBI scores, breakdown, simulate (dev)',
        href: '/app/admin/brand-intelligence',
        icon: SparklesIcon,
    },
    {
        name: 'Editor / Studio video audit',
        description: 'Canvas, presentation preview, Studio composition video — prompts, credits',
        href: '/app/admin/ai/editor-image-audit',
        icon: PhotoIcon,
    },
    {
        name: 'AI video analysis',
        description: 'Library video insights — status, agent runs, troubleshooting',
        href: '/app/admin/ai/analyzed-content',
        icon: FilmIcon,
        self: true,
    },
]

function formatRunDuration(s) {
    if (s == null || Number.isNaN(Number(s))) {
        return '—'
    }
    const n = Math.floor(Number(s))
    if (n < 120) {
        return `${n}s`
    }
    const m = Math.floor(n / 60)
    const r = n % 60
    return `${m}m ${r}s`
}

function CountPill({ label, value }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
        </div>
    )
}

export default function AdminAnalyzedContentIndex({
    video_ai_enabled,
    video_insight_counts,
    recent_library_videos = [],
    video_insights_worker_queue = 'ai-low',
    queue_workers_enabled = true,
    recent_video_runs,
    admin_asset_url,
    video_insight_run_detail_base,
    video_insight_frames_base,
}) {
    const { auth } = usePage().props
    const c = video_insight_counts || {}
    const [detailRunId, setDetailRunId] = useState(null)

    return (
        <div className="min-h-full">
            <AppHead title="AI content & analysis" suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-slate-900">AI content &amp; analysis</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-600">
                            Control panel for analyzed and AI-generated outputs. Use the tiles to open Brand Intelligence,
                            editor image audit, or stay on this page for video insights monitoring.
                        </p>
                    </div>

                    <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {TILES.map((item) => {
                            const Icon = item.icon
                            const inner = (
                                <>
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                                        <Icon className="h-5 w-5 text-slate-600" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-slate-900">{item.name}</p>
                                        <p className="text-sm text-slate-500">{item.description}</p>
                                    </div>
                                </>
                            )
                            if (item.self) {
                                return (
                                    <div
                                        key={item.href}
                                        className="flex items-center gap-3 rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-4"
                                    >
                                        {inner}
                                    </div>
                                )
                            }
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/30"
                                >
                                    {inner}
                                </Link>
                            )
                        })}
                    </div>

                    <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Video analysis &amp; Brand Intelligence order</h2>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
                            <li>
                                <strong>Library videos</strong> ({'type = asset'}): async video insights run first when{' '}
                                <code className="rounded bg-slate-100 px-1 text-xs">ASSET_VIDEO_AI_ENABLED</code> is on.
                                Brand Intelligence (EBI) queues only after insights finish, skip, or fail — if the category
                                has EBI enabled.
                            </li>
                            <li>
                                <strong>Deliverables / executions</strong> ({'type = deliverable'}): EBI is not deferred for
                                video; behavior matches your existing execution defaults.
                            </li>
                            <li>
                                Video AI flag:{' '}
                                <span className={video_ai_enabled ? 'font-medium text-emerald-700' : 'font-medium text-amber-800'}>
                                    {video_ai_enabled ? 'enabled' : 'disabled'}
                                </span>
                                {video_ai_enabled ? '' : ' — batch jobs mark queued videos as skipped with reason video_ai_disabled; no rows in ai_agent_runs. Set ASSET_VIDEO_AI_ENABLED=true in .env and reload config.'}
                            </li>
                        </ul>
                    </div>

                    <div className="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-amber-950">Why the agent-run table can look empty</h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-950/90">
                            <li>
                                <strong>Video AI off</strong> — see flag above. Nothing enqueueing real insight work.
                            </li>
                            <li>
                                <strong>Workers</strong> — insights use Redis queue{' '}
                                <code className="rounded bg-white/80 px-1 text-xs">{video_insights_worker_queue}</code> (Horizon
                                supervisor-ai: <code className="rounded bg-white/80 px-1 text-xs">ai</code>,{' '}
                                <code className="rounded bg-white/80 px-1 text-xs">ai-low</code>).{' '}
                                {queue_workers_enabled
                                    ? 'QUEUE_WORKERS_ENABLED is true for this app — ensure Horizon (or queue:work) is actually running in Docker.'
                                    : 'QUEUE_WORKERS_ENABLED is false — no workers will drain queues until that env flag is enabled.'}
                            </li>
                            <li>
                                <strong>Runs only after the job starts analysis</strong> —{' '}
                                <code className="rounded bg-white/80 px-1 text-xs">ai_agent_runs</code> rows are created after
                                policy/usage checks pass. Skips (policy, plan limits) never create a row; use the library video
                                table below for those assets.
                            </li>
                        </ul>
                    </div>

                    <div className="mb-4">
                        <h2 className="text-lg font-semibold text-slate-900">Library video insight status</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Counts are <strong>non-deliverable</strong> video assets only (
                            <code className="rounded bg-slate-100 px-1 text-xs">mime_type</code> video/*,{' '}
                            <code className="rounded bg-slate-100 px-1 text-xs">type = asset</code>).
                        </p>
                    </div>
                    <div className="mb-10 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
                        <CountPill label="Total" value={c.library_video_total ?? 0} />
                        <CountPill label="Queued" value={c.queued ?? 0} />
                        <CountPill label="Processing" value={c.processing ?? 0} />
                        <CountPill label="Completed" value={c.completed ?? 0} />
                        <CountPill label="Skipped" value={c.skipped ?? 0} />
                        <CountPill label="Failed" value={c.failed ?? 0} />
                        <CountPill label="Other / unset" value={c.other_or_unset ?? 0} />
                    </div>

                    <div className="mb-10 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4">
                            <h2 className="text-lg font-semibold text-slate-900">Recent library videos (by updated_at)</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Same scope as counts (library <code className="rounded bg-slate-100 px-1 text-xs">type = asset</code>,{' '}
                                video). Shows insight status on the asset even when no{' '}
                                <code className="rounded bg-slate-100 px-1 text-xs">ai_agent_runs</code> row exists.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Asset</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Tenant</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Insight status</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Skip / error</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Updated</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {(recent_library_videos || []).length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-8 text-center text-slate-500">
                                                No library video assets found.
                                            </td>
                                        </tr>
                                    ) : (
                                        recent_library_videos.map((row) => (
                                            <tr key={row.id} className="hover:bg-slate-50/80">
                                                <td className="px-4 py-2">
                                                    <Link
                                                        href={`${admin_asset_url}?asset_id=${encodeURIComponent(row.id)}`}
                                                        className="font-mono text-xs text-indigo-600 hover:text-indigo-800 break-all"
                                                    >
                                                        {row.id}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-2 text-slate-600">{row.tenant_name ?? '—'}</td>
                                                <td className="px-4 py-2 font-mono text-xs text-slate-800">
                                                    {row.ai_video_status ?? '—'}
                                                </td>
                                                <td className="max-w-[16rem] px-4 py-2 text-xs text-slate-600">
                                                    {row.skip_reason && (
                                                        <span className="text-amber-800">skip: {row.skip_reason}</span>
                                                    )}
                                                    {row.error && (
                                                        <span className="text-red-700">{row.skip_reason ? ' · ' : ''}{row.error}</span>
                                                    )}
                                                    {!row.skip_reason && !row.error ? '—' : null}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2 text-slate-500">
                                                    {row.updated_at ? new Date(row.updated_at).toLocaleString() : '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4">
                            <h2 className="text-lg font-semibold text-slate-900">Recent video insight agent runs</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Last 25 rows from <code className="rounded bg-slate-100 px-1 text-xs">ai_agent_runs</code>{' '}
                                (task_type = video_insights). Open Asset Operations for a specific asset.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">ID</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Asset</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Tenant</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Status</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Step</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Run time</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Cost</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Tokens</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Started</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Detail</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {(recent_video_runs || []).length === 0 ? (
                                        <tr>
                                            <td colSpan={10} className="px-4 py-8 text-center text-slate-500">
                                                No video insight runs yet — usually video AI is off, workers are not consuming{' '}
                                                <code className="rounded bg-slate-100 px-1 text-xs">{video_insights_worker_queue}</code>, or
                                                assets are skipping before a run is created. Check the amber box above and the library
                                                video table.
                                            </td>
                                        </tr>
                                    ) : (
                                        recent_video_runs.map((row) => (
                                            <tr key={row.id} className="hover:bg-slate-50/80">
                                                <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-600">
                                                    {row.id}
                                                </td>
                                                <td className="max-w-[14rem] px-4 py-2">
                                                    {row.entity_id ? (
                                                        <Link
                                                            href={`${admin_asset_url}?asset_id=${encodeURIComponent(row.entity_id)}`}
                                                            className="font-mono text-xs text-indigo-600 hover:text-indigo-800 break-all"
                                                        >
                                                            {row.entity_id}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                    {row.summary && (
                                                        <p className="mt-1 text-xs text-slate-500 line-clamp-2">{row.summary}</p>
                                                    )}
                                                    {row.error_message && (
                                                        <p className="mt-1 text-xs text-red-600 line-clamp-2">{row.error_message}</p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2 text-slate-600">{row.tenant_name ?? '—'}</td>
                                                <td className="px-4 py-2">
                                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-800">
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="max-w-[8rem] truncate px-4 py-2 font-mono text-xs text-slate-600" title={row.step ?? ''}>
                                                    {row.step ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2 text-slate-600">
                                                    {formatRunDuration(row.duration_seconds)}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2 text-slate-600">
                                                    {row.estimated_cost != null ? row.estimated_cost.toFixed(4) : '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-slate-600">
                                                    {row.tokens_in ?? '—'} / {row.tokens_out ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2 text-slate-500">
                                                    {row.started_at ? new Date(row.started_at).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => setDetailRunId(row.id)}
                                                        className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-800 hover:bg-indigo-100"
                                                    >
                                                        Open
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />

            {detailRunId != null && video_insight_run_detail_base && video_insight_frames_base && (
                <VideoInsightRunDetailPanel
                    runId={detailRunId}
                    onClose={() => setDetailRunId(null)}
                    detailBase={video_insight_run_detail_base}
                    framesBase={video_insight_frames_base}
                />
            )}
        </div>
    )
}
