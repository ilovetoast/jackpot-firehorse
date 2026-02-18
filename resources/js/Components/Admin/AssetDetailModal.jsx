/**
 * AssetDetailModal Component
 *
 * Shared modal for asset operations (Admin Assets, Operations Center).
 * Shows asset details with tabs, actions (Attempt Repair, Retry Pipeline), and optional thumbnail on left.
 *
 * @param {Object} props
 * @param {Object} props.data - { asset, incidents, pipeline_flags } from /app/admin/assets/{id}
 * @param {Function} props.onClose - Callback when modal should close
 * @param {Function} props.onAction - (assetId, action) for repair, retry-pipeline, restore
 * @param {Function} props.onRefresh - Callback after action that refreshes parent
 * @param {boolean} props.showThumbnail - When true, show thumbnail on the left of content
 */
import { useState } from 'react'
import JsonView from '@uiw/react-json-view'
import {
    XMarkIcon,
    ArrowPathIcon,
    ArrowUturnLeftIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline'

const STATUS_COLORS = {
    complete: 'bg-emerald-100 text-emerald-800',
    completed: 'bg-emerald-100 text-emerald-800',
    uploading: 'bg-amber-100 text-amber-800',
    generating_thumbnails: 'bg-blue-100 text-blue-800',
    extracting_metadata: 'bg-blue-100 text-blue-800',
    generating_embedding: 'bg-blue-100 text-blue-800',
    scoring: 'bg-blue-100 text-blue-800',
    failed: 'bg-red-100 text-red-800',
    unknown: 'bg-slate-100 text-slate-800',
}

export default function AssetDetailModal({ data, onClose, onAction, onRefresh, showThumbnail = false }) {
    const [tab, setTab] = useState('overview')
    const { asset, incidents, pipeline_flags, failed_jobs } = data || {}

    const TABS = [
        { id: 'overview', label: 'Overview' },
        { id: 'metadata', label: 'Metadata JSON' },
        { id: 'pipeline', label: 'Pipeline State' },
        { id: 'incidents', label: 'Incidents' },
        { id: 'thumbnails', label: 'Thumbnail Paths' },
        { id: 'failed_jobs', label: 'Failed Jobs' },
        { id: 'tickets', label: 'Support Tickets' },
    ]

    const hasThumbnail = showThumbnail && asset?.thumbnail_url

    return (
        <div className="flex">
            {/* Thumbnail on left (when available) */}
            {hasThumbnail && (
                <div className="flex-shrink-0 w-32 border-r border-slate-200 bg-slate-50 p-4 flex items-center justify-center">
                    <img
                        src={asset.thumbnail_url}
                        alt={asset?.original_filename || asset?.title || 'Asset'}
                        className="max-w-full max-h-32 object-contain rounded border border-slate-200"
                    />
                </div>
            )}

            <div className="flex-1 min-w-0">
                <div className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 className="text-lg font-semibold text-slate-900 truncate">
                        {asset?.original_filename || asset?.title || asset?.id_short}
                    </h2>
                    <button type="button" onClick={onClose} className="rounded p-1 hover:bg-slate-100">
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <div className="border-b border-slate-200">
                    <nav className="flex gap-4 px-6">
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setTab(t.id)}
                                className={`whitespace-nowrap border-b-2 py-3 text-sm font-medium ${
                                    tab === t.id
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </nav>
                </div>
                <div className="p-6">
                    {tab === 'overview' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4 text-sm">
                                <div><span className="text-slate-500">ID</span><br />{asset?.id}</div>
                                <div><span className="text-slate-500">Tenant</span><br />{asset?.tenant?.name ?? '—'}</div>
                                <div><span className="text-slate-500">Brand</span><br />{asset?.brand?.name ?? '—'}</div>
                                <div><span className="text-slate-500">Created by</span><br />{asset?.created_by?.name ?? '—'}</div>
                                <div><span className="text-slate-500">Analysis</span><br />
                                    <span className={`rounded px-2 py-0.5 text-xs ${STATUS_COLORS[asset?.analysis_status] || ''}`}>
                                        {asset?.analysis_status}
                                    </span>
                                </div>
                                <div><span className="text-slate-500">Thumbnail</span><br />
                                    <span className={`rounded px-2 py-0.5 text-xs ${STATUS_COLORS[asset?.thumbnail_status] || ''}`}>
                                        {asset?.thumbnail_status}
                                    </span>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2 pt-4">
                                <button
                                    onClick={() => onAction(asset.id, 'repair')}
                                    className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-500"
                                >
                                    <WrenchScrewdriverIcon className="h-4 w-4" />
                                    Attempt Repair
                                </button>
                                <button
                                    onClick={() => onAction(asset.id, 'reanalyze')}
                                    className="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-2 text-sm text-white hover:bg-amber-500"
                                    title="Re-run thumbnails, metadata, and embedding to fix incomplete brand data"
                                >
                                    <ArrowPathIcon className="h-4 w-4" />
                                    Re-run Analysis
                                </button>
                                <button
                                    onClick={() => onAction(asset.id, 'retry-pipeline')}
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                >
                                    <ArrowPathIcon className="h-4 w-4" />
                                    Retry Pipeline
                                </button>
                                {asset?.deleted_at && (
                                    <button
                                        onClick={() => onAction(asset.id, 'restore')}
                                        className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                    >
                                        <ArrowUturnLeftIcon className="h-4 w-4" />
                                        Restore
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                    {tab === 'metadata' && (
                        <div className="overflow-auto rounded border border-slate-200 bg-slate-50 p-4 max-h-96 [&_.w-rjv]:text-xs">
                            <JsonView value={asset?.metadata ?? {}} collapsed={2} enableClipboard />
                        </div>
                    )}
                    {tab === 'pipeline' && (
                        <div className="space-y-2 text-sm">
                            {pipeline_flags && Object.entries(pipeline_flags).map(([k, v]) => (
                                <div key={k} className="flex justify-between">
                                    <span className="text-slate-500">{k}</span>
                                    <span>{String(v)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    {tab === 'incidents' && (
                        <div className="space-y-2">
                            {incidents?.length ? incidents.map((i) => (
                                <div key={i.id} className="rounded border border-slate-200 p-3 text-sm">
                                    <span className={`rounded px-2 py-0.5 text-xs ${i.severity === 'critical' ? 'bg-red-100' : 'bg-amber-100'}`}>
                                        {i.severity}
                                    </span>
                                    <p className="mt-2 font-medium">{i.title}</p>
                                    <p className="text-slate-500">{new Date(i.detected_at).toLocaleString()}</p>
                                </div>
                            )) : (
                                <p className="text-slate-500">No incidents</p>
                            )}
                        </div>
                    )}
                    {tab === 'thumbnails' && (
                        <div className="space-y-4">
                            {asset?.thumbnail_view_urls && Object.keys(asset.thumbnail_view_urls).length > 0 && (
                                <div className="flex flex-wrap gap-3 text-sm">
                                    <span className="text-slate-500">View in new window:</span>
                                    {['thumb', 'medium', 'large'].map((style) =>
                                        asset.thumbnail_view_urls[style] ? (
                                            <a
                                                key={style}
                                                href={asset.thumbnail_view_urls[style]}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-indigo-600 hover:text-indigo-800 hover:underline capitalize"
                                            >
                                                {style}
                                            </a>
                                        ) : null
                                    )}
                                </div>
                            )}
                            <div className="overflow-auto rounded border border-slate-200 bg-slate-50 p-4 max-h-96 [&_.w-rjv]:text-xs">
                                <JsonView value={asset?.metadata?.thumbnails ?? {}} collapsed={1} enableClipboard />
                            </div>
                        </div>
                    )}
                    {tab === 'failed_jobs' && (
                        <div className="space-y-3">
                            {failed_jobs?.length ? (
                                failed_jobs.map((j) => (
                                    <div key={j.id} className="rounded border border-red-200 bg-red-50/50 p-4 text-sm">
                                        <div className="flex justify-between text-slate-600">
                                            <span className="font-mono text-xs">{j.queue}</span>
                                            <span>{j.failed_at ? new Date(j.failed_at).toLocaleString() : '—'}</span>
                                        </div>
                                        <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-white p-2 text-xs text-red-800">
                                            {j.exception_preview}
                                        </pre>
                                    </div>
                                ))
                            ) : (
                                <p className="text-slate-500">No failed jobs for this asset.</p>
                            )}
                        </div>
                    )}
                    {tab === 'tickets' && (
                        <p className="text-slate-500">Support tickets would be listed here (placeholder)</p>
                    )}
                </div>
            </div>
        </div>
    )
}
