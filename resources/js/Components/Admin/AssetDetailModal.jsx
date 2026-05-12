/**
 * AssetDetailModal Component
 *
 * Shared modal for asset operations (Admin Assets, Operations Center).
 * Vertical tabs on the left, optional compact thumbnail strip above the main pane, scrollable content.
 *
 * @param {Object} props
 * @param {Object} props.data - { asset, incidents, pipeline_flags, embedded_metadata_debug, ... } from /app/admin/assets/{id}
 * @param {Function} props.onClose - Callback when modal should close
 * @param {Function} props.onAction - (assetId, action) for repair, retry-pipeline, restore
 * @param {Function} props.onRefresh - Callback after action that refreshes parent
 * @param {Function} [props.onDetailDataReplace] - After classification save, merge full modal payload without closing
 * @param {boolean} props.showThumbnail - When true, show a compact thumbnail above the tab content
 */
import { useState, useEffect } from 'react'
import JsonView from '@uiw/react-json-view'
import { Link } from '@inertiajs/react'
import {
    XMarkIcon,
    ArrowPathIcon,
    ArrowUturnLeftIcon,
    WrenchScrewdriverIcon,
    ArrowDownTrayIcon,
    InformationCircleIcon,
    SparklesIcon,
    PlayCircleIcon,
    PhotoIcon,
    CheckCircleIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline'
import { getPipelineStageTooltip } from '../../utils/pipelineStatusUtils'

/** Standard derivative sizes (original thumbnail pipeline). */
const ADMIN_THUMB_SIZE_LINKS = [
    ['preview', 'Preview'],
    ['thumb', 'Thumb'],
    ['medium', 'Medium'],
    ['large', 'Large'],
]

/** Per-pipeline medium renditions when present. */
const ADMIN_THUMB_MODE_LINKS = [
    ['preferred_medium', 'Preferred · medium'],
    ['enhanced_medium', 'Enhanced · medium'],
    ['presentation_medium', 'Presentation · medium'],
]

// Pipeline flags: 'good' = green when value matches expected, 'bad' = red when value is problematic, 'invert' = good when false
const PIPELINE_FLAG_SEMANTICS = {
    visible_in_grid: 'good',         // true = good
    processing_failed: 'invert',     // false = good
    pipeline_completed: 'good',      // true = good
    metadata_extracted: 'good',     // true = good
    thumbnails_generated: 'good',    // true = good
    thumbnail_timeout: 'invert',     // false = good (no timeout)
    stuck_state_detected: 'invert',  // false = good (not stuck)
    auto_recover_attempted: 'warn', // true = amber (recovery was attempted)
}

const COMP_REF_STATE_BADGES = {
    active: 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200/85',
    stale: 'bg-amber-50 text-amber-900 ring-1 ring-amber-200/85',
    orphaned: 'bg-rose-50 text-rose-900 ring-1 ring-rose-200/85',
}

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

const ADMIN_ASSET_TYPE_OPTIONS = [
    { value: 'asset', label: 'Library asset' },
    { value: 'deliverable', label: 'Execution (deliverable)' },
    { value: 'ai_generated', label: 'Generative (AI-generated)' },
    { value: 'reference', label: 'Reference' },
]

const CATEGORY_SHELF_LABEL = {
    asset: 'Library categories',
    deliverable: 'Execution categories',
    ai_generated: 'Generative categories',
    reference: 'Reference categories',
}

export default function AssetDetailModal({ data, onClose, onAction, onRefresh, onDetailDataReplace, showThumbnail = false }) {
    const [tab, setTab] = useState('overview')
    const [restoreVersionLoading, setRestoreVersionLoading] = useState(false)
    const [classificationSaving, setClassificationSaving] = useState(false)
    const [classificationError, setClassificationError] = useState(null)
    const [publishLoading, setPublishLoading] = useState(false)
    const [unpublishLoading, setUnpublishLoading] = useState(false)
    const {
        asset,
        incidents,
        pipeline_flags,
        failed_jobs,
        versions = [],
        plan_allows_versions = false,
        embedded_metadata_debug = null,
        brand_categories_for_admin = [],
    } = data || {}

    const [typeDraft, setTypeDraft] = useState(() => asset?.asset_type?.value ?? '')
    const [categoryDraft, setCategoryDraft] = useState(() =>
        asset?.category_id != null && asset?.category_id !== '' ? String(asset.category_id) : ''
    )

    useEffect(() => {
        setTypeDraft(asset?.asset_type?.value ?? '')
        setCategoryDraft(
            asset?.category_id != null && asset?.category_id !== '' ? String(asset.category_id) : ''
        )
        setClassificationError(null)
    }, [asset?.id, asset?.asset_type?.value, asset?.category_id])

    const formatDurationMs = (ms) => {
        if (ms == null || ms === '') return '—'
        const n = Number(ms)
        if (!Number.isFinite(n)) return '—'
        if (n < 1000) return `${n.toLocaleString()} ms`
        return `${(n / 1000).toFixed(2)} s (${n.toLocaleString()} ms)`
    }

    const TABS = [
        { id: 'overview', label: 'Overview' },
        ...(versions?.length ? [{ id: 'versions', label: `Versions (${versions.length})` }] : []),
        { id: 'metadata', label: 'Metadata JSON' },
        { id: 'embedded', label: 'Embedded meta' },
        { id: 'pipeline', label: 'Pipeline State' },
        { id: 'incidents', label: 'Incidents' },
        { id: 'thumbnails', label: 'Thumbnail Paths' },
        { id: 'failed_jobs', label: 'Failed Jobs' },
        { id: 'tickets', label: 'Support Tickets' },
    ]

    const thumbnailUrl = asset?.admin_thumbnail_url ?? asset?.thumbnail_url
    const hasThumbnail = showThumbnail && thumbnailUrl

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="flex shrink-0 items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                <h2 className="min-w-0 flex-1 pr-4 text-lg font-semibold text-slate-900 truncate">
                    {asset?.original_filename || asset?.title || asset?.id_short}
                </h2>
                <button type="button" onClick={onClose} className="shrink-0 rounded p-1 hover:bg-slate-100" aria-label="Close">
                    <XMarkIcon className="h-5 w-5" />
                </button>
            </div>
            <div className="flex min-h-0 flex-1 flex-row overflow-hidden">
                <aside className="w-44 shrink-0 overflow-y-auto border-r border-slate-200 bg-slate-50 py-2">
                    <nav className="flex flex-col gap-0.5 px-2" aria-label="Asset detail sections">
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => setTab(t.id)}
                                className={`rounded-md px-3 py-2 text-left text-sm font-medium transition ${
                                    tab === t.id
                                        ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-indigo-200/80'
                                        : 'text-slate-600 hover:bg-slate-100/90'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </nav>
                </aside>
                <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                    {hasThumbnail && (
                        <div className="flex max-h-28 shrink-0 items-center justify-center border-b border-slate-200 bg-slate-50/90 px-4 py-2">
                            <img
                                src={thumbnailUrl}
                                alt={asset?.original_filename || asset?.title || 'Asset'}
                                className="max-h-24 max-w-full object-contain rounded border border-slate-200/80"
                            />
                        </div>
                    )}
                    <div className="min-h-0 flex-1 overflow-y-auto p-6">
                    {tab === 'overview' && (
                        <div className="space-y-4">
                            {asset?.storage_missing && (
                                <div className="rounded-lg border-2 border-red-500 bg-red-50 p-4">
                                    <div className="flex items-center gap-2">
                                        <span className="rounded px-2 py-1 text-sm font-bold uppercase tracking-wide bg-red-600 text-white">
                                            Dead
                                        </span>
                                        <span className="text-sm text-red-800 font-medium">
                                            Source file missing from storage. Cannot be recovered. Delete and re-upload.
                                        </span>
                                    </div>
                                </div>
                            )}
                            {asset?.visibility && !asset.visibility.visible && (
                                <div className="rounded-lg border-2 border-amber-500 bg-amber-50 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="flex items-start gap-2 min-w-0 flex-1">
                                            <span className="rounded px-2 py-1 text-sm font-bold uppercase tracking-wide bg-amber-600 text-white shrink-0">
                                                Not visible
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-amber-900">{asset.visibility.reason}</p>
                                                {asset.visibility.recommended_action && (
                                                    <p className="mt-1 text-sm text-amber-800">{asset.visibility.recommended_action}</p>
                                                )}
                                            </div>
                                        </div>
                                        {!asset?.deleted_at &&
                                            asset?.status !== 'failed' &&
                                            (asset.visibility.action_key === 'publish' || !asset.published_at) && (
                                                <button
                                                    type="button"
                                                    disabled={publishLoading}
                                                    onClick={async () => {
                                                        setPublishLoading(true)
                                                        try {
                                                            await onAction(asset.id, 'publish')
                                                        } finally {
                                                            setPublishLoading(false)
                                                        }
                                                    }}
                                                    className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
                                                >
                                                    <CheckCircleIcon className="h-4 w-4" aria-hidden />
                                                    {publishLoading ? 'Publishing…' : 'Publish'}
                                                </button>
                                            )}
                                    </div>
                                </div>
                            )}
                            {asset?.is_video && asset?.admin_source_stream_url && !asset?.storage_missing && (
                                <div className="rounded-lg border border-slate-200 bg-slate-900 p-4 shadow-sm">
                                    <p className="text-sm font-semibold text-white">Source video (full file)</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        Streams the original upload for this asset (same as Download source file). Use this to confirm true orientation vs the hover clip.
                                    </p>
                                    <video
                                        key={asset.admin_source_stream_url}
                                        src={asset.admin_source_stream_url}
                                        controls
                                        playsInline
                                        className="mt-3 max-h-[min(55vh,520px)] w-full rounded-md bg-black object-contain"
                                        preload="metadata"
                                    />
                                </div>
                            )}
                            <div className="grid grid-cols-2 gap-4 text-sm">
                                <div><span className="text-slate-500">ID</span><br />{asset?.id}</div>
                                <div>
                                    <span className="text-slate-500">Tenant / brand</span>
                                    <br />
                                    <span className="text-slate-800">{asset?.tenant?.name ?? '—'}</span>
                                    <span className="text-slate-400"> · </span>
                                    <span className="text-slate-800">{asset?.brand?.name ?? '—'}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500">Brand Builder</span>
                                    <br />
                                    {asset?.builder_staged ? (
                                        <span className="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800" title={asset?.builder_context || 'Staged for Brand Builder'}>
                                            {asset?.builder_context || 'Staged'}
                                        </span>
                                    ) : (
                                        <span className="text-slate-600">—</span>
                                    )}
                                </div>
                                <div>
                                    <span className="text-slate-500">Composition</span>
                                    <br />
                                    {asset?.composition_name ? (
                                        <span className="text-slate-800">{asset.composition_name}</span>
                                    ) : (
                                        <span className="text-slate-600">—</span>
                                    )}
                                    {asset?.metadata?.composition_id != null && String(asset.metadata.composition_id) !== '' && (
                                        <span className="ml-1 font-mono text-[10px] text-slate-500" title="metadata.composition_id">
                                            #{String(asset.metadata.composition_id)}
                                        </span>
                                    )}
                                </div>
                                <div>
                                    <span className="text-slate-500">Composition ref</span>
                                    <span className="sr-only"> — editor membership for generative / canvas rows</span>
                                    <br />
                                    {asset?.composition_ref_state ? (
                                        <span
                                            className={`inline-flex rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide ${COMP_REF_STATE_BADGES[asset.composition_ref_state] || 'bg-slate-100 text-slate-700'}`}
                                            title="active = in current doc or thumbnail; stale = version history only; orphaned = unreferenced"
                                        >
                                            {asset.composition_ref_state}
                                        </span>
                                    ) : (
                                        <span className="text-slate-600">—</span>
                                    )}
                                </div>
                                <div><span className="text-slate-500">Asset type</span><br />{asset?.asset_type?.label ?? '—'}</div>
                                <div><span className="text-slate-500">Category</span><br />{asset?.category?.name ?? '—'}</div>
                                {asset?.is_video && (
                                    <div className="col-span-2">
                                        <span className="text-slate-500">Video display size</span>
                                        <span className="sr-only"> — width and height after rotation metadata (not raw coded frame size)</span>
                                        <br />
                                        {asset.video_width != null && asset.video_height != null ? (
                                            <span className="tabular-nums text-slate-800">
                                                {asset.video_width} × {asset.video_height} px
                                            </span>
                                        ) : (
                                            <span className="text-amber-800 text-xs">
                                                Not set — run <strong className="font-medium">Re-run Analysis</strong> or regenerate the hover preview to refresh from the file.
                                            </span>
                                        )}
                                    </div>
                                )}
                                <div><span className="text-slate-500">Created by</span><br />{asset?.created_by?.name ?? '—'}</div>
                                <div><span className="text-slate-500">Visible in grid</span><br />
                                    {asset?.visibility?.visible ? (
                                        <span className="rounded px-2 py-0.5 text-xs bg-emerald-100 text-emerald-800">Yes</span>
                                    ) : (
                                        <span className="rounded px-2 py-0.5 text-xs bg-red-100 text-red-800">No</span>
                                    )}
                                </div>
                                <div><span className="text-slate-500">Analysis (pipeline stage)</span><br />
                                    <span
                                        className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs ${STATUS_COLORS[asset?.analysis_status] || ''}`}
                                        title={getPipelineStageTooltip(asset?.analysis_status)}
                                    >
                                        {asset?.analysis_status}
                                        {getPipelineStageTooltip(asset?.analysis_status) && (
                                            <InformationCircleIcon className="h-3.5 w-3.5 opacity-70" />
                                        )}
                                    </span>
                                </div>
                                <div><span className="text-slate-500">Thumb status (pipeline)</span><br />
                                    <span className={`rounded px-2 py-0.5 text-xs ${STATUS_COLORS[asset?.thumbnail_status] || ''}`}>
                                        {asset?.thumbnail_status}
                                    </span>
                                </div>
                                <div><span className="text-slate-500">Deleted</span><br />
                                    {asset?.deleted_at ? (
                                        <span className="rounded px-2 py-0.5 text-xs bg-red-100 text-red-800">
                                            Yes — {new Date(asset.deleted_at).toLocaleString()}
                                        </span>
                                    ) : (
                                        <span className="text-slate-600">No</span>
                                    )}
                                </div>
                                {plan_allows_versions && (
                                    <div><span className="text-slate-500">Versions</span><br />
                                        <span className="text-slate-600">{versions?.length ?? 0} version(s)</span>
                                    </div>
                                )}
                            </div>
                            <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50/80 p-4">
                                <h3 className="text-sm font-semibold text-slate-900">Classification override</h3>
                                <p className="mt-1 text-xs text-slate-600">
                                    Fix library vs execution (deliverable) vs generative type and DAM shelf category when automation mis-files a row or blocks the grid. Clearing category removes{' '}
                                    <code className="rounded bg-slate-200/80 px-1">metadata.category_id</code> until a category is set again.
                                </p>
                                {classificationError && (
                                    <p className="mt-2 text-xs text-red-700">{classificationError}</p>
                                )}
                                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label className="block text-xs font-medium text-slate-700">
                                        Row type
                                        <select
                                            className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 shadow-sm"
                                            value={typeDraft}
                                            onChange={(e) => setTypeDraft(e.target.value)}
                                        >
                                            {ADMIN_ASSET_TYPE_OPTIONS.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="block text-xs font-medium text-slate-700">
                                        DAM category (shelf)
                                        <select
                                            className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 shadow-sm"
                                            value={categoryDraft}
                                            onChange={(e) => setCategoryDraft(e.target.value)}
                                        >
                                            <option value="">— None (not in default grid) —</option>
                                            {['asset', 'deliverable', 'ai_generated', 'reference']
                                                .map((shelf) => ({
                                                    shelf,
                                                    rows: brand_categories_for_admin.filter((c) => c.asset_type === shelf),
                                                }))
                                                .filter((x) => x.rows.length > 0)
                                                .map(({ shelf, rows }) => (
                                                    <optgroup key={shelf} label={CATEGORY_SHELF_LABEL[shelf] || shelf}>
                                                        {rows.map((c) => (
                                                            <option key={c.id} value={String(c.id)}>
                                                                {c.name}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                ))}
                                        </select>
                                    </label>
                                </div>
                                {(!brand_categories_for_admin || brand_categories_for_admin.length === 0) && (
                                    <p className="mt-2 text-xs text-amber-800">No categories returned for this brand — check tenant/brand linkage.</p>
                                )}
                                <div className="mt-3">
                                    <button
                                        type="button"
                                        disabled={classificationSaving}
                                        onClick={async () => {
                                            if (!asset?.id) return
                                            setClassificationError(null)
                                            const body = {}
                                            const curType = asset?.asset_type?.value ?? ''
                                            if (typeDraft && typeDraft !== curType) {
                                                body.type = typeDraft
                                            }
                                            const curCat =
                                                asset?.category_id != null && asset?.category_id !== ''
                                                    ? String(asset.category_id)
                                                    : ''
                                            if (categoryDraft !== curCat) {
                                                body.category_id =
                                                    categoryDraft === '' ? null : parseInt(categoryDraft, 10)
                                            }
                                            if (Object.keys(body).length === 0) {
                                                setClassificationError('Change type or category before saving.')
                                                return
                                            }
                                            setClassificationSaving(true)
                                            try {
                                                const res = await window.axios.post(
                                                    `/app/admin/assets/${asset.id}/update-classification`,
                                                    body
                                                )
                                                if (onDetailDataReplace) {
                                                    onDetailDataReplace(res.data)
                                                } else {
                                                    onRefresh?.()
                                                }
                                            } catch (e) {
                                                setClassificationError(
                                                    e?.response?.data?.message ||
                                                        e?.response?.data?.error ||
                                                        e?.message ||
                                                        'Save failed'
                                                )
                                            } finally {
                                                setClassificationSaving(false)
                                            }
                                        }}
                                        className="inline-flex items-center rounded-md bg-slate-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                                    >
                                        {classificationSaving ? 'Saving…' : 'Save classification'}
                                    </button>
                                </div>
                            </div>
                            <div className="pt-2">
                                <Link
                                    href={`/app/admin/brand-intelligence/assets/${asset?.id}`}
                                    className="inline-flex items-center gap-1.5 rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1.5 text-xs font-medium text-violet-900 hover:bg-violet-100"
                                >
                                    <SparklesIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                    Brand Intelligence
                                </Link>
                            </div>
                            <div className="flex flex-wrap gap-2 pt-4">
                                {!asset?.deleted_at && asset?.status !== 'failed' && !asset?.published_at && (
                                    <button
                                        type="button"
                                        disabled={publishLoading}
                                        onClick={async () => {
                                            setPublishLoading(true)
                                            try {
                                                await onAction(asset.id, 'publish')
                                            } finally {
                                                setPublishLoading(false)
                                            }
                                        }}
                                        className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
                                    >
                                        <CheckCircleIcon className="h-4 w-4" aria-hidden />
                                        {publishLoading ? 'Publishing…' : 'Publish to library'}
                                    </button>
                                )}
                                {asset?.published_at && !asset?.deleted_at && (
                                    <button
                                        type="button"
                                        disabled={unpublishLoading}
                                        onClick={async () => {
                                            if (
                                                !window.confirm(
                                                    'Unpublish this asset? It will be hidden from the default library grid until published again.'
                                                )
                                            ) {
                                                return
                                            }
                                            setUnpublishLoading(true)
                                            try {
                                                await onAction(asset.id, 'unpublish')
                                            } finally {
                                                setUnpublishLoading(false)
                                            }
                                        }}
                                        className="inline-flex items-center gap-1 rounded-lg border border-slate-400 bg-white px-3 py-2 text-sm text-slate-800 hover:bg-slate-50 disabled:opacity-50"
                                    >
                                        <XCircleIcon className="h-4 w-4" aria-hidden />
                                        {unpublishLoading ? 'Unpublishing…' : 'Unpublish'}
                                    </button>
                                )}
                                <button
                                    type="button"
                                    title="Reconciles stuck pipeline/analysis flags and may resolve an open incident. It does not re-run thumbnail generation. After fixing workers (e.g. LibreOffice/FFmpeg), use Re-run Analysis for thumbnails + metadata, or Retry Pipeline for the full upload pipeline."
                                    onClick={() => onAction(asset.id, 'repair')}
                                    className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-500"
                                >
                                    <WrenchScrewdriverIcon className="h-4 w-4" />
                                    Attempt Repair
                                </button>
                                <button
                                    onClick={() => onAction(asset.id, 'reanalyze')}
                                    className="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-2 text-sm text-white hover:bg-amber-500"
                                    title="Re-runs GenerateThumbnailsJob, then metadata and embedding. Use this after fixing worker software (LibreOffice, FFmpeg) when thumbnails failed but the file is already in storage."
                                >
                                    <ArrowPathIcon className="h-4 w-4" />
                                    Re-run Analysis
                                </button>
                                <button
                                    type="button"
                                    onClick={() => onAction(asset.id, 'retry-pipeline')}
                                    className={`inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm ${
                                        asset?.visibility?.action_key === 'retry-pipeline'
                                            ? 'border-2 border-amber-500 bg-amber-50 text-amber-900 hover:bg-amber-100 font-medium'
                                            : 'border border-slate-300 hover:bg-slate-50'
                                    }`}
                                    title={
                                        asset?.visibility?.action_key === 'retry-pipeline' && asset?.visibility?.recommended_action
                                            ? asset.visibility.recommended_action
                                            : 'Clears processing flags and runs the full ProcessAssetJob chain from the start. Use when the asset never finished processing or promotion; heavier than Re-run Analysis.'
                                    }
                                >
                                    <ArrowPathIcon className="h-4 w-4" />
                                    Retry Pipeline
                                </button>
                                <a
                                    href={`/app/admin/assets/${asset.id}/download-source`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                                    title="Download the original uploaded file"
                                >
                                    <ArrowDownTrayIcon className="h-4 w-4" />
                                    Download source file
                                </a>
                                {asset?.deleted_at && (
                                    <button
                                        onClick={() => onAction(asset.id, 'restore')}
                                        className={`inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm ${
                                            asset?.visibility?.action_key === 'restore'
                                                ? 'border-2 border-amber-500 bg-amber-50 text-amber-900 hover:bg-amber-100 font-medium'
                                                : 'border border-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        <ArrowUturnLeftIcon className="h-4 w-4" />
                                        Restore
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                    {tab === 'versions' && (
                        <div className="space-y-4">
                            <p className="text-sm text-slate-500">Version history for this asset. Restore makes a version the new current.</p>
                            <div className="overflow-x-auto rounded border border-slate-200">
                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead>
                                        <tr>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Version</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Version ID</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Size</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Uploaded</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">User</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Current</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-200">
                                        {versions.map((v) => {
                                            const status = (v.pipeline_status || 'pending').toLowerCase()
                                            const statusClass = status === 'complete' ? 'bg-emerald-100 text-emerald-800' : status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'
                                            const isArchived = ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'].includes(v.storage_class || '')
                                            const fmtSize = (b) => (!b ? '—' : b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`)
                                            const fmtDate = (d) => (!d ? '—' : new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }))
                                            return (
                                                <tr key={v.id} className={isArchived ? 'bg-slate-50' : ''}>
                                                    <td className="px-4 py-3 font-medium">v{v.version_number}</td>
                                                    <td className="px-4 py-3">
                                                        <code
                                                            className="break-all rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-800"
                                                            title="Use as --version-id for artisan (asset_versions.id)"
                                                        >
                                                            {v.id}
                                                        </code>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className={`rounded px-2 py-0.5 text-xs ${statusClass}`}>{status}</span>
                                                        {isArchived && <span className="ml-1 rounded px-2 py-0.5 text-xs bg-slate-200 text-slate-700">Archived</span>}
                                                    </td>
                                                    <td className="px-4 py-3">{fmtSize(v.file_size)}</td>
                                                    <td className="px-4 py-3">{fmtDate(v.created_at)}</td>
                                                    <td className="px-4 py-3">{v.uploaded_by?.name ?? '—'}</td>
                                                    <td className="px-4 py-3">{v.is_current ? <span className="rounded px-2 py-0.5 text-xs bg-indigo-100 text-indigo-800">Current</span> : '—'}</td>
                                                    <td className="px-4 py-3">
                                                        {!v.is_current && !isArchived && plan_allows_versions && (
                                                            <button
                                                                type="button"
                                                                onClick={async () => {
                                                                    if (restoreVersionLoading) return
                                                                    setRestoreVersionLoading(true)
                                                                    try {
                                                                        await window.axios.post(`/app/admin/assets/${asset.id}/versions/${v.id}/restore`)
                                                                        onRefresh?.()
                                                                    } catch (err) {
                                                                        alert(err.response?.data?.error || 'Restore failed')
                                                                    } finally {
                                                                        setRestoreVersionLoading(false)
                                                                    }
                                                                }}
                                                                disabled={restoreVersionLoading}
                                                                className="text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50"
                                                            >
                                                                Restore
                                                            </button>
                                                        )}
                                                        {isArchived && <span className="text-slate-400 text-xs" title="Archived in Glacier">—</span>}
                                                    </td>
                                                </tr>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                    {tab === 'metadata' && (
                        <div className="overflow-auto rounded border border-slate-200 bg-slate-50 p-4 max-h-96 [&_.w-rjv]:text-xs">
                            <JsonView value={asset?.metadata ?? {}} collapsed={2} enableClipboard />
                        </div>
                    )}
                    {tab === 'embedded' && (
                        <div className="space-y-3 text-sm">
                            <p className="text-slate-500">
                                Raw namespaces, index rows, extractor warnings (<code className="text-xs bg-slate-100 px-1 rounded">other</code>), and
                                canonical mapping hints. Best-effort extraction; empty state after reprocess means extractors returned nothing.
                            </p>
                            <div className="overflow-auto rounded border border-slate-200 bg-slate-50 p-4 max-h-[28rem] [&_.w-rjv]:text-xs">
                                <JsonView value={embedded_metadata_debug ?? {}} collapsed={2} enableClipboard />
                            </div>
                        </div>
                    )}
                    {tab === 'pipeline' && (
                        <div className="space-y-4 text-sm">
                            <div className="rounded-lg border border-slate-200 bg-slate-50/80 p-3">
                                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Processing timing (ops)</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    Admin-only. Thumbnail = time until preview thumbs ready; full pipeline = through finalize. Cleared
                                    in bulk with{' '}
                                    <code className="rounded bg-slate-200/80 px-1 py-0.5 text-[10px]">php artisan assets:clear-processing-metrics</code>.
                                </p>
                                <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-slate-500">Thumbnail job → ready</dt>
                                        <dd className="font-mono text-slate-800">{formatDurationMs(asset?.thumbnail_ready_duration_ms)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-slate-500">Full pipeline (finalize)</dt>
                                        <dd className="font-mono text-slate-800">{formatDurationMs(asset?.processing_duration_ms)}</dd>
                                    </div>
                                </dl>
                            </div>
                            {pipeline_flags && Object.entries(pipeline_flags).map(([k, v]) => {
                                const semantic = PIPELINE_FLAG_SEMANTICS[k]
                                const val = Boolean(v)
                                let badgeClass = 'bg-slate-100 text-slate-700'
                                if (semantic === 'good') {
                                    badgeClass = val ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'
                                } else if (semantic === 'invert') {
                                    badgeClass = !val ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'
                                } else if (semantic === 'warn' && val) {
                                    badgeClass = 'bg-amber-100 text-amber-800'
                                }
                                return (
                                    <div key={k} className="flex justify-between items-center">
                                        <span className="text-slate-500">{k}</span>
                                        <span className={`rounded px-2 py-0.5 text-xs font-medium ${badgeClass}`}>{String(v)}</span>
                                    </div>
                                )
                            })}
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
                        <div className="space-y-6">
                            {asset?.is_video && (
                                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                        <PlayCircleIcon className="h-5 w-5 text-indigo-600" aria-hidden />
                                        Hover / quick preview (short MP4)
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Same clip used on the main asset grid on desktop hover. URL updates when the asset row changes so you are not stuck on a cached file after regeneration.
                                    </p>
                                    {asset.video_preview_view_url ? (
                                        <div className="mt-3 flex w-full justify-center px-1">
                                            <div
                                                className="relative mx-auto max-w-lg overflow-visible rounded-md border border-slate-200 bg-black shadow-inner"
                                                style={
                                                    asset.video_width && asset.video_height
                                                        ? {
                                                              aspectRatio: `${Number(asset.video_width)} / ${Number(asset.video_height)}`,
                                                              height: 'min(24rem, 72vh)',
                                                              width: 'auto',
                                                              maxWidth: '100%',
                                                          }
                                                        : {
                                                              height: 'min(24rem, 72vh)',
                                                              width: 'min(100%, 32rem)',
                                                          }
                                                }
                                            >
                                                <video
                                                    key={asset.video_preview_view_url}
                                                    className="absolute inset-0 m-auto h-full w-full object-contain"
                                                    src={asset.video_preview_view_url}
                                                    controls
                                                    muted
                                                    playsInline
                                                    preload="metadata"
                                                />
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="mt-3 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-3 py-2">
                                            No hover preview file yet. Use <span className="font-medium">Regenerate hover video previews</span> in Asset Operations bulk actions (or the library grid bulk action <span className="font-medium">Generate video previews</span>) after thumbnails exist.
                                        </p>
                                    )}
                                    {(asset.video_width || asset.video_height) ? (
                                        <p className="mt-2 text-xs text-slate-500 tabular-nums">
                                            Asset display dimensions (rotation-aware): {asset.video_width ?? '—'} ×{' '}
                                            {asset.video_height ?? '—'} px — used for eligibility and preview layout; regenerate
                                            hover preview to re-encode the clip if these were wrong.
                                        </p>
                                    ) : null}
                                </div>
                            )}

                            {asset?.thumbnail_view_urls && Object.keys(asset.thumbnail_view_urls).length > 0 && (
                                <div className="space-y-4">
                                    <p className="text-sm font-medium text-slate-800">Still-image derivatives</p>
                                    <p className="text-xs text-slate-500 -mt-2">Open each size in a new tab to compare sharpness and crop.</p>

                                    <div>
                                        <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <PhotoIcon className="h-4 w-4" aria-hidden />
                                            Standard sizes
                                        </div>
                                        <ul className="flex flex-wrap gap-2">
                                            {ADMIN_THUMB_SIZE_LINKS.map(([key, label]) =>
                                                asset.thumbnail_view_urls[key] ? (
                                                    <li key={key}>
                                                        <a
                                                            href={asset.thumbnail_view_urls[key]}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-indigo-700 hover:border-indigo-200 hover:bg-indigo-50"
                                                        >
                                                            {label}
                                                        </a>
                                                    </li>
                                                ) : null
                                            )}
                                        </ul>
                                    </div>

                                    <div>
                                        <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Pipeline modes (medium)
                                        </div>
                                        <ul className="flex flex-wrap gap-2">
                                            {ADMIN_THUMB_MODE_LINKS.map(([key, label]) =>
                                                asset.thumbnail_view_urls[key] ? (
                                                    <li key={key}>
                                                        <a
                                                            href={asset.thumbnail_view_urls[key]}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-indigo-700 hover:border-indigo-200 hover:bg-indigo-50"
                                                        >
                                                            {label}
                                                        </a>
                                                    </li>
                                                ) : null
                                            )}
                                        </ul>
                                    </div>
                                </div>
                            )}
                            <div>
                                <p className="mb-2 text-sm font-medium text-slate-800">Raw thumbnail metadata</p>
                                <div className="overflow-auto rounded border border-slate-200 bg-slate-50 p-4 max-h-96 [&_.w-rjv]:text-xs">
                                    <JsonView value={asset?.metadata?.thumbnails ?? {}} collapsed={1} enableClipboard />
                                </div>
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
        </div>
    )
}
