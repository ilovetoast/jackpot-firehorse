import { useEffect, useState } from 'react'

const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'frames', label: 'Frames' },
    { id: 'transcript', label: 'Transcript' },
    { id: 'prompt', label: 'Prompt' },
    { id: 'raw', label: 'Raw response' },
    { id: 'parsed', label: 'Parsed results' },
]

function formatDurationSeconds(s) {
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

export default function VideoInsightRunDetailPanel({ runId, onClose, detailBase, framesBase }) {
    const stopCardClick = (e) => e.stopPropagation()
    const [data, setData] = useState(null)
    const [loadError, setLoadError] = useState(null)
    const [loading, setLoading] = useState(true)
    const [tab, setTab] = useState('overview')
    const [framesPayload, setFramesPayload] = useState(null)
    const [framesError, setFramesError] = useState(null)
    const [framesLoading, setFramesLoading] = useState(false)
    const [frameIdx, setFrameIdx] = useState(0)

    useEffect(() => {
        if (!runId) {
            return
        }
        let cancelled = false
        setLoading(true)
        setLoadError(null)
        setData(null)
        setFramesPayload(null)
        setFramesError(null)
        setFrameIdx(0)
        setTab('overview')

        fetch(`${detailBase}/${runId}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(res.status === 404 ? 'Run not found' : `HTTP ${res.status}`)
                }
                return res.json()
            })
            .then((json) => {
                if (!cancelled) {
                    setData(json)
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setLoadError(e.message || 'Failed to load')
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false)
                }
            })

        return () => {
            cancelled = true
        }
    }, [runId, detailBase])

    useEffect(() => {
        if (tab !== 'frames' || !data?.asset?.id) {
            return
        }
        if (framesError) {
            return
        }
        if (framesPayload !== null || framesLoading) {
            return
        }
        const assetId = data.asset.id
        let cancelled = false
        setFramesLoading(true)
        setFramesError(null)
        fetch(`${framesBase}/${encodeURIComponent(assetId)}/frames`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(res.status === 404 ? 'Asset not found' : `HTTP ${res.status}`)
                }
                return res.json()
            })
            .then((json) => {
                if (!cancelled) {
                    setFramesPayload(json)
                    setFrameIdx(0)
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setFramesError(e.message || 'Failed to load frames')
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setFramesLoading(false)
                }
            })
        return () => {
            cancelled = true
        }
    }, [tab, data?.asset?.id, framesBase, framesPayload, framesLoading, framesError])

    const run = data?.run
    const asset = data?.asset
    const tenant = data?.tenant
    const frames = framesPayload?.frames ?? []
    const transcript = asset?.ai_video_insights?.transcript ?? ''

    return (
        <div
            className="fixed inset-0 z-50 flex items-stretch justify-center bg-black/40 p-4 sm:p-6"
            onClick={onClose}
            onKeyDown={(e) => e.key === 'Escape' && onClose()}
            role="presentation"
        >
            <div
                className="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="video-insight-detail-title"
                onClick={stopCardClick}
            >
                <div className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="video-insight-detail-title" className="text-lg font-semibold text-slate-900">
                            Video insight run #{runId}
                        </h2>
                        <p className="mt-1 text-xs text-slate-500">Troubleshooting detail — prompt, model output, and sampled frames.</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                    >
                        Close
                    </button>
                </div>

                {loading && <div className="px-5 py-10 text-center text-slate-600">Loading…</div>}
                {loadError && (
                    <div className="px-5 py-10 text-center text-red-600">
                        {loadError}
                    </div>
                )}

                {!loading && !loadError && data && (
                    <>
                        <div className="flex shrink-0 gap-1 overflow-x-auto border-b border-slate-200 px-2 pt-2">
                            {TABS.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    onClick={() => setTab(t.id)}
                                    className={`whitespace-nowrap rounded-t-lg px-3 py-2 text-sm font-medium ${
                                        tab === t.id
                                            ? 'bg-indigo-50 text-indigo-800'
                                            : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                            {tab === 'overview' && (
                                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-slate-500">Status</dt>
                                        <dd className="font-medium text-slate-900">{run?.status ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Step (last reported)</dt>
                                        <dd className="font-mono text-xs text-slate-900">{run?.step ?? asset?.ai_video_insights_step ?? '—'}</dd>
                                    </div>
                                    {run?.failed_at_step && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-slate-500">Failed during</dt>
                                            <dd className="font-mono text-xs text-amber-800">{run.failed_at_step}</dd>
                                        </div>
                                    )}
                                    <div>
                                        <dt className="text-slate-500">Tenant</dt>
                                        <dd className="text-slate-900">{tenant?.name ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Model</dt>
                                        <dd className="font-mono text-xs text-slate-900">{run?.model_used ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Cost (USD)</dt>
                                        <dd className="tabular-nums text-slate-900">
                                            {run?.estimated_cost != null ? Number(run.estimated_cost).toFixed(6) : '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Tokens in / out</dt>
                                        <dd className="tabular-nums text-slate-900">
                                            {run?.tokens_in ?? '—'} / {run?.tokens_out ?? '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Run time</dt>
                                        <dd className="text-slate-900">{formatDurationSeconds(run?.duration_seconds)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Asset video status</dt>
                                        <dd className="font-mono text-xs text-slate-900">{asset?.ai_video_status ?? '—'}</dd>
                                    </div>
                                    <div className="sm:col-span-2">
                                        <dt className="text-slate-500">Asset Operations</dt>
                                        <dd>
                                            {data.admin_asset_operations_url ? (
                                                <a
                                                    href={data.admin_asset_operations_url}
                                                    className="text-indigo-600 hover:text-indigo-800 break-all"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {data.admin_asset_operations_url}
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            )}

                            {tab === 'frames' && (
                                <div>
                                    {framesLoading && <p className="text-sm text-slate-600">Extracting frames (FFmpeg)…</p>}
                                    {framesError && (
                                        <div className="mb-3 flex flex-wrap items-center gap-2">
                                            <p className="text-sm text-red-600">{framesError}</p>
                                            <button
                                                type="button"
                                                className="rounded border border-slate-200 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50"
                                                onClick={() => setFramesError(null)}
                                            >
                                                Retry
                                            </button>
                                        </div>
                                    )}
                                    {!framesLoading && !framesError && frames.length === 0 && (
                                        <p className="text-sm text-slate-600">No frames returned.</p>
                                    )}
                                    {frames.length > 0 && (
                                        <div>
                                            <p className="mb-3 text-xs text-slate-500">
                                                {framesPayload.frame_count} frames · interval {framesPayload.frame_interval_seconds}s — same sampling as production.
                                            </p>
                                            <div className="flex flex-wrap items-center gap-3">
                                                <img
                                                    src={frames[frameIdx]?.data_url}
                                                    alt={frames[frameIdx]?.label ?? 'frame'}
                                                    className="max-h-72 rounded-lg border border-slate-200 bg-slate-900 object-contain"
                                                />
                                                <div className="flex flex-col gap-2">
                                                    <p className="text-sm font-medium text-slate-800">{frames[frameIdx]?.label}</p>
                                                    <div className="flex gap-2">
                                                        <button
                                                            type="button"
                                                            disabled={frameIdx <= 0}
                                                            onClick={() => setFrameIdx((i) => Math.max(0, i - 1))}
                                                            className="rounded border border-slate-200 px-2 py-1 text-sm disabled:opacity-40"
                                                        >
                                                            Prev
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={frameIdx >= frames.length - 1}
                                                            onClick={() => setFrameIdx((i) => Math.min(frames.length - 1, i + 1))}
                                                            className="rounded border border-slate-200 px-2 py-1 text-sm disabled:opacity-40"
                                                        >
                                                            Next
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {tab === 'transcript' && (
                                <pre className="whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-4 text-xs text-slate-800">
                                    {transcript || '— (no transcript — silent, skipped, or not yet run)'}
                                </pre>
                            )}

                            {tab === 'prompt' && (
                                <pre className="max-h-[480px] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-4 text-xs text-slate-800">
                                    {run?.vision_prompt || '— (not stored for this run — only captured on successful completions from new job versions)'}
                                </pre>
                            )}

                            {tab === 'raw' && (
                                <pre className="max-h-[480px] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-4 text-xs text-slate-800">
                                    {run?.raw_llm_response || '— (not stored for this run)'}
                                </pre>
                            )}

                            {tab === 'parsed' && (
                                <pre className="max-h-[480px] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-4 text-xs text-slate-800">
                                    {asset?.ai_video_insights
                                        ? JSON.stringify(asset.ai_video_insights, null, 2)
                                        : '—'}
                                </pre>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    )
}
