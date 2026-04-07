import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AppHead from '../../../Components/AppHead'
import {
    CheckCircleIcon,
    XCircleIcon,
    XMarkIcon,
    EyeIcon,
    PhotoIcon,
} from '@heroicons/react/24/outline'

const AUDIT_PAGE_PATH = '/app/admin/ai/editor-image-audit'

const METADATA_KEYS_HIDDEN_FROM_RAW = ['prompt', 'response', 'generative_audit', 'editor_admin_request', 'image_ref']

function formatUsd6(value) {
    const n = Number(value)
    return Number.isFinite(n) ? n.toFixed(6) : '0.000000'
}

/**
 * Brand context digest may include stringified JSON (e.g. `preview` as an escaped string).
 * Parse those inner strings so the UI can show one pretty-printed JSON tree.
 */
function expandJsonLikeStrings(value) {
    if (value === null || value === undefined) {
        return value
    }
    if (Array.isArray(value)) {
        return value.map((item) => expandJsonLikeStrings(item))
    }
    if (typeof value === 'object') {
        const out = {}
        for (const [k, v] of Object.entries(value)) {
            out[k] = expandJsonLikeStrings(v)
        }
        return out
    }
    if (typeof value === 'string') {
        const t = value.trim()
        if (
            (t.startsWith('{') && t.endsWith('}')) ||
            (t.startsWith('[') && t.endsWith(']'))
        ) {
            try {
                return expandJsonLikeStrings(JSON.parse(t))
            } catch {
                return value
            }
        }
    }
    return value
}

function formatBrandContextDigestJson(digest) {
    if (digest == null) {
        return ''
    }
    let obj = digest
    if (typeof obj === 'string') {
        try {
            obj = JSON.parse(obj)
        } catch {
            return digest
        }
    }
    if (typeof obj !== 'object' || obj === null) {
        return JSON.stringify(obj, null, 2)
    }
    return JSON.stringify(expandJsonLikeStrings(obj), null, 2)
}

/** Default cap for table “Request / image” dialog (avoid huge attribute strings). */
const MAX_INLINE_IMAGE_SRC_CHARS = 2_400_000
/** Run details JSON already contains `image_ref`; allow larger data URLs for admin troubleshooting. */
const MAX_DETAILS_MODAL_IMAGE_REF_CHARS = 6_000_000

function imageSrcForPreview(ref, maxChars = MAX_INLINE_IMAGE_SRC_CHARS) {
    if (typeof ref !== 'string' || ref === '') {
        return null
    }
    if (ref.length > maxChars) {
        return null
    }
    if (ref.startsWith('data:image') || ref.startsWith('http://') || ref.startsWith('https://')) {
        return ref
    }
    return null
}

function buildRequestResponsePayload(run) {
    const m = run?.metadata || {}
    const request =
        m.editor_admin_request ||
        (m.generative_audit || m.options
            ? {
                  prompt: m.prompt || m.generative_audit?.prompt_preview,
                  generative_audit: m.generative_audit,
                  options: m.options,
                  resolved_model_key: m.resolved_model_key,
              }
            : { note: 'No editor_admin_request on this run (older execution or failed before save).' })

    const ref = m.image_ref
    const refLen = typeof ref === 'string' ? ref.length : 0
    let refForJson = ref
    if (typeof ref === 'string' && refLen > 50000) {
        refForJson = `${ref.slice(0, 4000)}… [truncated for JSON; ${refLen} chars total]`
    }

    const response = {
        image_ref_kind: m.generative_audit?.output_image_ref_kind,
        image_ref_length: refLen,
        image_ref: refForJson,
        response_metadata: m.response_metadata ?? null,
    }

    return { request, response }
}

export default function EditorImageAudit({ runs, filters, filterOptions }) {
    const { auth } = usePage().props
    const [localFilters, setLocalFilters] = useState(filters || {})
    const [selectedRun, setSelectedRun] = useState(null)
    const [runDetails, setRunDetails] = useState(null)
    const [loadingDetails, setLoadingDetails] = useState(false)
    const [previewRun, setPreviewRun] = useState(null)
    const [loadingPreview, setLoadingPreview] = useState(false)
    /** Tabs inside Generative audit: brand digest | prompt preview | full prompt */
    const [auditContentTab, setAuditContentTab] = useState('preview')
    /** Tabs for raw execution metadata */
    const [rawMetaTab, setRawMetaTab] = useState('additional')

    useEffect(() => {
        if (!runDetails?.metadata?.generative_audit) {
            return
        }
        const ga = runDetails.metadata.generative_audit
        const visible = []
        if (ga.brand_context_digest) {
            visible.push('brand')
        }
        if (ga.prompt_preview) {
            visible.push('preview')
        }
        if (ga.prompt) {
            visible.push('full')
        }
        if (visible.length === 0) {
            return
        }
        if (!visible.includes(auditContentTab)) {
            setAuditContentTab(visible[0])
        }
    }, [runDetails, auditContentTab])

    useEffect(() => {
        if (!runDetails?.metadata) {
            return
        }
        const m = runDetails.metadata
        const visible = []
        if (m.prompt) {
            visible.push('prompt')
        }
        if (m.response) {
            visible.push('response')
        }
        if (Object.keys(m).filter((key) => !METADATA_KEYS_HIDDEN_FROM_RAW.includes(key)).length > 0) {
            visible.push('additional')
        }
        if (visible.length === 0) {
            return
        }
        if (!visible.includes(rawMetaTab)) {
            setRawMetaTab(visible[0])
        }
    }, [runDetails, rawMetaTab])

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get(AUDIT_PAGE_PATH, updatedFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const clearFilters = () => {
        setLocalFilters({})
        router.get(AUDIT_PAGE_PATH, {}, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== null && v !== '' && v !== undefined)

    const getStatusBadge = (status) => {
        if (status === 'success') {
            return (
                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-100 text-green-800">
                    <CheckCircleIcon className="h-3 w-3 mr-1" />
                    Success
                </span>
            )
        }
        return (
            <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-100 text-red-800">
                <XCircleIcon className="h-3 w-3 mr-1" />
                Failed
            </span>
        )
    }

    const fetchRunDetails = async (runId) => {
        setLoadingDetails(true)
        setSelectedRun(runId)
        try {
            const response = await fetch(`/app/admin/ai/runs/${runId}`)
            if (response.ok) {
                const data = await response.json()
                setRunDetails(data)
            } else {
                setRunDetails(null)
            }
        } catch (error) {
            console.error('Failed to fetch run details:', error)
            setRunDetails(null)
        } finally {
            setLoadingDetails(false)
        }
    }

    const closeDetails = () => {
        setSelectedRun(null)
        setRunDetails(null)
    }

    const openRequestResponsePreview = async (runId) => {
        setLoadingPreview(true)
        setPreviewRun(null)
        try {
            const response = await fetch(`/app/admin/ai/runs/${runId}`)
            if (response.ok) {
                setPreviewRun(await response.json())
            }
        } catch (e) {
            console.error(e)
            setPreviewRun(null)
        } finally {
            setLoadingPreview(false)
        }
    }

    const closePreview = () => {
        setPreviewRun(null)
        setLoadingPreview(false)
    }

    return (
        <div className="min-h-full">
            <AppHead title="Editor image AI audit" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm mb-4">
                            <Link href="/app/admin/ai" className="font-medium text-gray-500 hover:text-gray-700">
                                ← AI Dashboard
                            </Link>
                            <Link href="/app/admin/ai/activity" className="font-medium text-gray-500 hover:text-gray-700">
                                All AI activity
                            </Link>
                        </div>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Editor image AI audit</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            Canvas text-to-image, canvas image edit, and DAM <strong>presentation preview</strong> runs. Structured
                            fields live in <code className="text-xs bg-gray-100 px-1 rounded">generative_audit</code>. Raw source
                            bytes are not stored on the run; when an <code className="text-xs bg-gray-100 px-1 rounded">asset_id</code>{' '}
                            is recorded, the preview dialog loads that asset&apos;s current thumbnail as the best stand-in for
                            &quot;what went in.&quot;
                        </p>
                    </div>

                    {/* Filters */}
                    <div className="mb-6 bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Agent Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.agent_id || ''}
                                    onChange={(e) => applyFilters({ agent_id: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Agents</option>
                                    {filterOptions?.agents?.map((agent) => (
                                        <option key={agent.value} value={agent.value}>
                                            {agent.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Model Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.model_used || ''}
                                    onChange={(e) => applyFilters({ model_used: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Models</option>
                                    {filterOptions?.models?.map((model) => (
                                        <option key={model.value} value={model.value}>
                                            {model.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Task Type Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.task_type || ''}
                                    onChange={(e) => applyFilters({ task_type: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Task Types</option>
                                    {filterOptions?.task_types?.map((task) => (
                                        <option key={task.value} value={task.value}>
                                            {task.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Status Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.status || ''}
                                    onChange={(e) => applyFilters({ status: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Statuses</option>
                                    <option value="success">Success</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>

                            {/* Environment Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.environment || ''}
                                    onChange={(e) => applyFilters({ environment: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Environments</option>
                                    {filterOptions?.environments?.map((env) => (
                                        <option key={env.value} value={env.value}>
                                            {env.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Date From */}
                            <div className="flex-shrink-0">
                                <input
                                    type="date"
                                    value={localFilters.date_from || ''}
                                    onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                                    className="block w-full min-w-[150px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                    placeholder="Date From"
                                />
                            </div>

                            {/* Date To */}
                            <div className="flex-shrink-0">
                                <input
                                    type="date"
                                    value={localFilters.date_to || ''}
                                    onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                                    className="block w-full min-w-[150px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                    placeholder="Date To"
                                />
                            </div>

                            {/* Tenant ID */}
                            <div className="flex-shrink-0">
                                <input
                                    type="number"
                                    min="1"
                                    step="1"
                                    value={localFilters.tenant_id ?? ''}
                                    onChange={(e) => {
                                        const v = e.target.value.trim()
                                        applyFilters({ tenant_id: v === '' ? null : v })
                                    }}
                                    className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                    placeholder="Tenant ID"
                                    title="Filter by company (tenant) id"
                                />
                            </div>

                            {/* Clear Filters */}
                            {hasActiveFilters && (
                                <div className="flex-shrink-0 ml-auto">
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="inline-flex items-center rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        <XMarkIcon className="h-4 w-4 mr-1.5" />
                                        Clear
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    <p className="mb-2 text-xs text-gray-500">
                        Table is wide — scroll horizontally if needed. The Actions column stays pinned on the right.
                    </p>
                    {/* Table: overflow-x-auto so Status / Related / Actions are not clipped */}
                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Timestamp
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Agent
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Task Type
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Model
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Provider
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider max-w-xs">
                                        Prompt preview
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tokens
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Cost
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Related
                                    </th>
                                    <th
                                        scope="col"
                                        className="sticky right-0 z-20 bg-gray-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-700 shadow-[-6px_0_12px_-6px_rgba(0,0,0,0.12)]"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {runs.data && runs.data.length > 0 ? (
                                    runs.data.map((run) => (
                                        <tr key={run.id} className="group hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {run.timestamp}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="text-sm font-medium text-gray-900">{run.agent_name}</div>
                                                <div className="text-xs text-gray-500">{run.agent_id}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {run.task_type}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {run.model_used}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {run.audit_summary?.has_audit && (run.audit_summary.provider || run.audit_summary.registry_model_key) ? (
                                                    <div>
                                                        <div className="text-gray-900">{run.audit_summary.provider ?? '—'}</div>
                                                        <div className="text-xs text-gray-500 truncate max-w-[140px]" title={run.audit_summary.registry_model_key ?? ''}>
                                                            {run.audit_summary.registry_model_key ?? ''}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600 max-w-xs">
                                                {run.audit_summary?.has_audit && run.audit_summary.prompt_preview ? (
                                                    <span className="line-clamp-3" title={run.audit_summary.prompt_preview}>
                                                        {run.audit_summary.prompt_preview}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div>In: {run.tokens_in.toLocaleString()}</div>
                                                <div>Out: {run.tokens_out.toLocaleString()}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                ${formatUsd6(run.estimated_cost)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {getStatusBadge(run.status)}
                                                {run.error_message && (
                                                    <div className="mt-1 text-xs text-red-600 truncate max-w-xs" title={run.error_message}>
                                                        {run.error_message}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {run.related_tickets && run.related_tickets.length > 0 ? (
                                                    <div className="space-y-1">
                                                        {run.related_tickets.map((ticket) => (
                                                            <div key={ticket.id} className="text-xs">
                                                                <Link
                                                                    href={`/app/admin/support/tickets/${ticket.id}`}
                                                                    className="text-indigo-600 hover:text-indigo-900"
                                                                >
                                                                    {ticket.ticket_number}
                                                                </Link>
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="sticky right-0 z-10 whitespace-nowrap border-l border-gray-200 bg-white px-4 py-4 text-sm shadow-[-6px_0_12px_-6px_rgba(0,0,0,0.08)] group-hover:bg-gray-50">
                                                <div className="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap">
                                                    <button
                                                        type="button"
                                                        onClick={() => fetchRunDetails(run.id)}
                                                        className="inline-flex items-center justify-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        <EyeIcon className="h-3 w-3 mr-1 shrink-0" />
                                                        Details
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => openRequestResponsePreview(run.id)}
                                                        className="inline-flex items-center justify-center rounded-md bg-indigo-50 px-2.5 py-1.5 text-xs font-semibold text-indigo-800 shadow-sm ring-1 ring-inset ring-indigo-200 hover:bg-indigo-100"
                                                    >
                                                        <PhotoIcon className="h-3 w-3 mr-1 shrink-0" />
                                                        Request / image
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="11" className="px-6 py-4 text-center text-sm text-gray-500">
                                            No editor image AI runs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {runs.links && runs.links.length > 3 && (
                        <div className="mt-4 flex items-center justify-between">
                            <div className="text-sm text-gray-700">
                                Showing {runs.from} to {runs.to} of {runs.total} results
                            </div>
                            <div className="flex space-x-2">
                                {runs.links.map((link, index) => (
                                    <button
                                        key={index}
                                        onClick={() => {
                                            if (link.url) {
                                                const url = new URL(link.url)
                                                const params = Object.fromEntries(url.searchParams.entries())
                                                router.get(AUDIT_PAGE_PATH, { ...localFilters, ...params }, {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                })
                                            }
                                        }}
                                        disabled={!link.url}
                                        className={`
                                            px-3 py-2 text-sm font-medium rounded-md
                                            ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white'
                                                    : link.url
                                                    ? 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50'
                                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            }
                                        `}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Request + returned image (JSON) — separate lightweight dialog */}
                    {(previewRun || loadingPreview) && (
                        <div className="fixed inset-0 z-[60] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="preview-modal-title">
                            <div className="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                                <button
                                    type="button"
                                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                                    aria-label="Close"
                                    onClick={closePreview}
                                />
                                <span className="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden>
                                    &#8203;
                                </span>
                                <div className="inline-block w-full max-w-5xl transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:align-middle">
                                    <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:px-6">
                                        <h3 id="preview-modal-title" className="text-lg font-medium text-gray-900">
                                            Input vs model output
                                        </h3>
                                        <button
                                            type="button"
                                            onClick={closePreview}
                                            className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                        >
                                            <XMarkIcon className="h-6 w-6" />
                                        </button>
                                    </div>
                                    <div className="max-h-[85vh] overflow-y-auto px-4 py-4 sm:px-6">
                                        {loadingPreview ? (
                                            <p className="text-sm text-gray-500">Loading…</p>
                                        ) : previewRun ? (
                                            <div className="space-y-6">
                                                <div className="grid gap-6 lg:grid-cols-2">
                                                    <div>
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">
                                                            Input (sent to the model)
                                                        </p>
                                                        {previewRun.source_asset?.thumbnail_url ? (
                                                            <div className="space-y-2">
                                                                <img
                                                                    src={previewRun.source_asset.thumbnail_url}
                                                                    alt=""
                                                                    className="max-h-80 w-full rounded border border-gray-200 object-contain bg-gray-50"
                                                                />
                                                                <p className="text-xs text-gray-600">
                                                                    Linked asset{' '}
                                                                    <span className="font-mono text-gray-800">{previewRun.source_asset.id}</span>
                                                                    {previewRun.source_asset.title ? (
                                                                        <>
                                                                            {' '}
                                                                            — {previewRun.source_asset.title}
                                                                        </>
                                                                    ) : null}
                                                                    . Thumbnail is the asset&apos;s current delivery URL (may differ
                                                                    slightly from the exact raster sent if the file changed since the
                                                                    run).
                                                                </p>
                                                                {previewRun.source_asset.admin_url ? (
                                                                    <Link
                                                                        href={previewRun.source_asset.admin_url}
                                                                        className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                                                                    >
                                                                        Open in admin asset console →
                                                                    </Link>
                                                                ) : null}
                                                            </div>
                                                        ) : previewRun.source_asset?.missing ? (
                                                            <div className="space-y-2">
                                                                <p className="text-sm text-amber-800">
                                                                    Recorded asset id{' '}
                                                                    <span className="font-mono">{previewRun.source_asset.id}</span>{' '}
                                                                    was not found (deleted or wrong tenant).
                                                                </p>
                                                                {previewRun.source_asset.admin_url ? (
                                                                    <Link
                                                                        href={previewRun.source_asset.admin_url}
                                                                        className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                                                                    >
                                                                        Try admin asset console →
                                                                    </Link>
                                                                ) : null}
                                                            </div>
                                                        ) : previewRun.task_type === 'editor_generative_image' ? (
                                                            <p className="text-sm text-gray-600">
                                                                Text-to-image: there is no source raster.{' '}
                                                                {previewRun.metadata?.editor_admin_request?.reference_asset_count !=
                                                                null ? (
                                                                    <>
                                                                        Reference assets in request:{' '}
                                                                        <span className="font-mono">
                                                                            {previewRun.metadata.editor_admin_request.reference_asset_count}
                                                                        </span>
                                                                        .
                                                                    </>
                                                                ) : null}
                                                            </p>
                                                        ) : (
                                                            <p className="text-sm text-gray-600">
                                                                Raw image bytes are not stored on AI runs. No{' '}
                                                                <code className="text-xs">asset_id</code> / entity link was recorded,
                                                                so we can&apos;t show a stand-in thumbnail. Use{' '}
                                                                <strong>Details</strong> for prompts and audit fields, or enable asset
                                                                attribution on the client when calling edit endpoints.
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">
                                                            Output (model response)
                                                        </p>
                                                        {(() => {
                                                            const src = imageSrcForPreview(
                                                                previewRun.metadata?.image_ref,
                                                                MAX_DETAILS_MODAL_IMAGE_REF_CHARS,
                                                            )
                                                            if (src) {
                                                                return (
                                                                    <img
                                                                        src={src}
                                                                        alt="Model output"
                                                                        className="max-h-80 w-full rounded border border-gray-200 object-contain bg-gray-50"
                                                                    />
                                                                )
                                                            }
                                                            return (
                                                                <p className="text-sm text-gray-600">
                                                                    No inline preview (missing{' '}
                                                                    <code className="text-xs">image_ref</code>, non-HTTP/data URL, or
                                                                    reference too large). See JSON for length and kind.
                                                                </p>
                                                            )
                                                        })()}
                                                    </div>
                                                </div>
                                                <div className="grid gap-4 lg:grid-cols-2">
                                                    <div>
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">
                                                            Request (JSON)
                                                        </p>
                                                        <pre className="max-h-64 overflow-auto rounded border border-gray-200 bg-slate-50 p-3 text-xs">
                                                            {JSON.stringify(buildRequestResponsePayload(previewRun).request, null, 2)}
                                                        </pre>
                                                    </div>
                                                    <div>
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">
                                                            Response summary (JSON)
                                                        </p>
                                                        <pre className="max-h-64 overflow-auto rounded border border-gray-200 bg-slate-50 p-3 text-xs">
                                                            {JSON.stringify(buildRequestResponsePayload(previewRun).response, null, 2)}
                                                        </pre>
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <p className="text-sm text-red-600">Could not load run.</p>
                                        )}
                                    </div>
                                    <div className="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                                        <button
                                            type="button"
                                            onClick={closePreview}
                                            className="inline-flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 sm:w-auto"
                                        >
                                            Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Details Modal */}
                    {selectedRun && (
                        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                            <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={closeDetails}></div>
                                <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-5xl sm:w-full">
                                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 max-h-[90vh] overflow-y-auto">
                                        <div className="flex items-start justify-between gap-4 border-b border-gray-200 pb-4 mb-4">
                                            <div>
                                                <h3 className="text-lg font-semibold leading-6 text-gray-900" id="modal-title">
                                                    Editor image run details
                                                </h3>
                                                <p className="mt-1 text-xs text-gray-500 font-mono">Run #{runDetails?.id ?? selectedRun}</p>
                                                <p className="mt-2 text-sm text-gray-600">
                                                    Summary of the agent run, structured audit fields, and prompt text (tabs).
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={closeDetails}
                                                className="shrink-0 rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                                aria-label="Close"
                                            >
                                                <XMarkIcon className="h-6 w-6" />
                                            </button>
                                        </div>
                                        {loadingDetails ? (
                                            <div className="text-center py-8">
                                                <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                                                <p className="mt-2 text-sm text-gray-500">Loading details...</p>
                                            </div>
                                        ) : runDetails ? (
                                            <div className="space-y-5">
                                                {/* Run summary */}
                                                <section className="rounded-lg border border-gray-200 bg-slate-50/80 p-4">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Run summary</h4>
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Agent</label>
                                                            <p className="text-sm text-gray-900">{runDetails.agent_name}</p>
                                                            <p className="text-xs text-gray-500">{runDetails.agent_id}</p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Task type</label>
                                                            <p className="text-sm text-gray-900">{runDetails.task_type}</p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Status</label>
                                                            <div className="mt-0.5">{getStatusBadge(runDetails.status)}</div>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Model used</label>
                                                            <p className="text-sm text-gray-900">{runDetails.model_used}</p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Timestamp</label>
                                                            <p className="text-sm text-gray-900">{runDetails.timestamp}</p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Duration</label>
                                                            <p className="text-sm text-gray-900">{runDetails.duration || 'N/A'}</p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Tokens</label>
                                                            <p className="text-sm text-gray-900">
                                                                In: {runDetails.tokens_in.toLocaleString()} | Out: {runDetails.tokens_out.toLocaleString()}
                                                                {runDetails.total_tokens != null && (
                                                                    <> | Total: {runDetails.total_tokens.toLocaleString()}</>
                                                                )}
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <label className="text-xs font-medium text-gray-500">Cost (USD)</label>
                                                            <p className="text-sm text-gray-900">${formatUsd6(runDetails.estimated_cost)}</p>
                                                        </div>
                                                    </div>
                                                </section>

                                                {/* Source vs model output — same data as Request / image, visible without a second click */}
                                                <section className="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-indigo-800 mb-1">
                                                        Visual previews
                                                    </h4>
                                                    <p className="text-xs text-gray-600 mb-4">
                                                        <span className="font-medium text-gray-800">Original</span> uses the linked
                                                        asset&apos;s current thumbnail when available.{' '}
                                                        <span className="font-medium text-gray-800">New</span> is the stored model
                                                        output (<code className="text-[10px] bg-white/80 px-1 rounded">image_ref</code>
                                                        ). Very large data URLs may still be skipped for browser safety.
                                                    </p>
                                                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                                        <div>
                                                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">
                                                                Original (source asset)
                                                            </p>
                                                            {runDetails.source_asset?.thumbnail_url ? (
                                                                <div className="space-y-2">
                                                                    <img
                                                                        src={runDetails.source_asset.thumbnail_url}
                                                                        alt=""
                                                                        className="max-h-72 w-full rounded-lg border border-gray-200 bg-white object-contain shadow-sm"
                                                                    />
                                                                    <p className="text-xs text-gray-600">
                                                                        Asset{' '}
                                                                        <span className="font-mono text-gray-800">
                                                                            {runDetails.source_asset.id}
                                                                        </span>
                                                                        {runDetails.source_asset.title
                                                                            ? ` — ${runDetails.source_asset.title}`
                                                                            : ''}
                                                                    </p>
                                                                    {runDetails.source_asset.admin_url ? (
                                                                        <Link
                                                                            href={runDetails.source_asset.admin_url}
                                                                            className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                                                                        >
                                                                            Open in admin asset console →
                                                                        </Link>
                                                                    ) : null}
                                                                </div>
                                                            ) : runDetails.source_asset?.missing ? (
                                                                <p className="text-sm text-amber-800">
                                                                    Source asset id recorded but asset not found (deleted?).{' '}
                                                                    {runDetails.source_asset.admin_url ? (
                                                                        <Link
                                                                            href={runDetails.source_asset.admin_url}
                                                                            className="font-medium text-indigo-600 hover:text-indigo-500"
                                                                        >
                                                                            Try admin link
                                                                        </Link>
                                                                    ) : null}
                                                                </p>
                                                            ) : runDetails.task_type === 'editor_generative_image' ? (
                                                                <p className="text-sm text-gray-600">
                                                                    Text-to-image run — no source raster.{' '}
                                                                    {runDetails.metadata?.editor_admin_request?.reference_asset_count !=
                                                                    null ? (
                                                                        <>
                                                                            Reference assets:{' '}
                                                                            <span className="font-mono">
                                                                                {
                                                                                    runDetails.metadata.editor_admin_request
                                                                                        .reference_asset_count
                                                                                }
                                                                            </span>
                                                                        </>
                                                                    ) : null}
                                                                </p>
                                                            ) : (
                                                                <p className="text-sm text-gray-600">
                                                                    No linked asset on this run — raw input bytes are not stored. Check{' '}
                                                                    <code className="text-xs">Source asset id</code> in Generative audit
                                                                    if the client omitted <code className="text-xs">asset_id</code>.
                                                                </p>
                                                            )}
                                                        </div>
                                                        <div>
                                                            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">
                                                                New (model output)
                                                            </p>
                                                            {(() => {
                                                                const outSrc = imageSrcForPreview(
                                                                    runDetails.metadata?.image_ref,
                                                                    MAX_DETAILS_MODAL_IMAGE_REF_CHARS,
                                                                )
                                                                if (outSrc) {
                                                                    return (
                                                                        <img
                                                                            src={outSrc}
                                                                            alt="Model output"
                                                                            className="max-h-72 w-full rounded-lg border border-gray-200 bg-white object-contain shadow-sm"
                                                                        />
                                                                    )
                                                                }
                                                                const len = runDetails.metadata?.image_ref
                                                                    ? String(runDetails.metadata.image_ref).length
                                                                    : 0
                                                                if (len > 0) {
                                                                    return (
                                                                        <p className="text-sm text-gray-600">
                                                                            Output is present ({len.toLocaleString()} chars) but too
                                                                            large or not a displayable URL. See Output ref kind /
                                                                            length in Generative audit below, or use Request / image
                                                                            from the table.
                                                                        </p>
                                                                    )
                                                                }
                                                                return (
                                                                    <p className="text-sm text-gray-600">
                                                                        No <code className="text-xs">image_ref</code> on this run
                                                                        (failed before save, or older record).
                                                                    </p>
                                                                )
                                                            })()}
                                                        </div>
                                                    </div>
                                                </section>

                                                {/* Context */}
                                                {(runDetails.tenant || runDetails.user) && (
                                                    <section className="rounded-lg border border-gray-200 p-4">
                                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Who</h4>
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                            {runDetails.tenant && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">Tenant</label>
                                                                    <p className="text-sm text-gray-900">{runDetails.tenant.name}</p>
                                                                </div>
                                                            )}
                                                            {runDetails.user && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">User</label>
                                                                    <p className="text-sm text-gray-900">{runDetails.user.name}</p>
                                                                    <p className="text-xs text-gray-500">{runDetails.user.email}</p>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </section>
                                                )}

                                                {runDetails.error_message && (
                                                    <section className="rounded-lg border border-red-200 bg-red-50 p-4">
                                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-red-800 mb-1">Error</h4>
                                                        <p className="text-sm text-red-800">{runDetails.error_message}</p>
                                                    </section>
                                                )}

                                                {/* Generative audit: fields + tabbed long content */}
                                                {runDetails.metadata?.generative_audit && (
                                                    <section className="rounded-lg border border-gray-200 p-4">
                                                        <h4 className="text-sm font-semibold text-gray-900">Generative audit</h4>
                                                        <p className="mt-1 text-xs text-gray-500 mb-3">
                                                            Fields recorded for this run. Long text is split into tabs below.
                                                        </p>
                                                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                                            {[
                                                                ['Registry model key', runDetails.metadata.generative_audit.registry_model_key],
                                                                ['Resolved API model', runDetails.metadata.generative_audit.resolved_api_model],
                                                                ['Provider', runDetails.metadata.generative_audit.provider],
                                                                ['Brand context included', runDetails.metadata.generative_audit.brand_context_included === true ? 'Yes' : runDetails.metadata.generative_audit.brand_context_included === false ? 'No' : '—'],
                                                                ['Composition id', runDetails.metadata.generative_audit.composition_id],
                                                                ['Generative layer uuid', runDetails.metadata.generative_audit.generative_layer_uuid],
                                                                ['Source asset id', runDetails.metadata.generative_audit.source_asset_id],
                                                                ['Prompt SHA-256', runDetails.metadata.generative_audit.prompt_sha256],
                                                                ['Prompt length', runDetails.metadata.generative_audit.prompt_length],
                                                                ['Output ref kind', runDetails.metadata.generative_audit.output_image_ref_kind],
                                                                ['Output ref length', runDetails.metadata.generative_audit.output_image_ref_length],
                                                            ].map(([label, val]) => (
                                                                <div key={label} className="min-w-0">
                                                                    <dt className="text-xs font-medium text-gray-500">{label}</dt>
                                                                    <dd className="text-gray-900 break-all">{val != null && val !== '' ? String(val) : '—'}</dd>
                                                                </div>
                                                            ))}
                                                        </dl>

                                                        {(runDetails.metadata.generative_audit.brand_context_digest ||
                                                            runDetails.metadata.generative_audit.prompt_preview ||
                                                            runDetails.metadata.generative_audit.prompt) && (
                                                            <div className="mt-4 border-t border-gray-200 pt-4">
                                                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Prompt &amp; brand</p>
                                                                <div className="flex flex-wrap gap-1 border-b border-gray-200" role="tablist">
                                                                    {[
                                                                        { id: 'brand', label: 'Brand context', show: !!runDetails.metadata.generative_audit.brand_context_digest },
                                                                        { id: 'preview', label: 'Prompt preview', show: !!runDetails.metadata.generative_audit.prompt_preview },
                                                                        { id: 'full', label: 'Full prompt', show: !!runDetails.metadata.generative_audit.prompt },
                                                                    ]
                                                                        .filter((t) => t.show)
                                                                        .map((t) => (
                                                                            <button
                                                                                key={t.id}
                                                                                type="button"
                                                                                role="tab"
                                                                                aria-selected={auditContentTab === t.id}
                                                                                onClick={() => setAuditContentTab(t.id)}
                                                                                className={`rounded-t-md px-3 py-2 text-sm font-medium transition-colors ${
                                                                                    auditContentTab === t.id
                                                                                        ? 'border border-b-0 border-gray-200 bg-white text-indigo-700'
                                                                                        : 'border border-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                                                                }`}
                                                                            >
                                                                                {t.label}
                                                                            </button>
                                                                        ))}
                                                                </div>
                                                                <div
                                                                    className="rounded-b-md border border-t-0 border-gray-200 bg-white p-3"
                                                                    role="tabpanel"
                                                                >
                                                                    {auditContentTab === 'brand' && runDetails.metadata.generative_audit.brand_context_digest && (
                                                                        <pre className="max-h-64 overflow-auto text-xs font-mono leading-relaxed bg-slate-50 p-3 rounded border whitespace-pre-wrap text-gray-900 dark:bg-slate-900/40 dark:text-slate-100 dark:border-slate-600">
                                                                            {formatBrandContextDigestJson(
                                                                                runDetails.metadata.generative_audit.brand_context_digest
                                                                            )}
                                                                        </pre>
                                                                    )}
                                                                    {auditContentTab === 'preview' && runDetails.metadata.generative_audit.prompt_preview && (
                                                                        <pre className="max-h-64 overflow-auto text-xs bg-gray-50 p-3 rounded border whitespace-pre-wrap">
                                                                            {runDetails.metadata.generative_audit.prompt_preview}
                                                                        </pre>
                                                                    )}
                                                                    {auditContentTab === 'full' && runDetails.metadata.generative_audit.prompt && (
                                                                        <pre className="max-h-72 overflow-auto text-xs bg-gray-50 p-3 rounded border whitespace-pre-wrap">
                                                                            {typeof runDetails.metadata.generative_audit.prompt === 'string'
                                                                                ? runDetails.metadata.generative_audit.prompt
                                                                                : JSON.stringify(runDetails.metadata.generative_audit.prompt, null, 2)}
                                                                        </pre>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )}
                                                    </section>
                                                )}

                                                {runDetails.metadata && Object.keys(runDetails.metadata).length > 0 && (
                                                    <section className="rounded-lg border border-gray-200 p-4">
                                                        <h4 className="text-sm font-semibold text-gray-900">Raw execution metadata</h4>
                                                        <p className="mt-1 text-xs text-gray-500 mb-3">
                                                            Legacy / duplicate fields from storage. Large keys (e.g. image) are omitted from the JSON tab.
                                                        </p>
                                                        <div className="flex flex-wrap gap-1 border-b border-gray-200" role="tablist">
                                                            {[
                                                                { id: 'prompt', label: 'Prompt', show: !!runDetails.metadata.prompt },
                                                                { id: 'response', label: 'Response', show: !!runDetails.metadata.response },
                                                                {
                                                                    id: 'additional',
                                                                    label: 'Additional JSON',
                                                                    show:
                                                                        Object.keys(runDetails.metadata).filter((key) => !METADATA_KEYS_HIDDEN_FROM_RAW.includes(key)).length > 0,
                                                                },
                                                            ]
                                                                .filter((t) => t.show)
                                                                .map((t) => (
                                                                    <button
                                                                        key={t.id}
                                                                        type="button"
                                                                        role="tab"
                                                                        aria-selected={rawMetaTab === t.id}
                                                                        onClick={() => setRawMetaTab(t.id)}
                                                                        className={`rounded-t-md px-3 py-2 text-sm font-medium transition-colors ${
                                                                            rawMetaTab === t.id
                                                                                ? 'border border-b-0 border-gray-200 bg-white text-indigo-700'
                                                                                : 'border border-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                                                        }`}
                                                                    >
                                                                        {t.label}
                                                                    </button>
                                                                ))}
                                                        </div>
                                                        <div className="rounded-b-md border border-t-0 border-gray-200 bg-white p-3" role="tabpanel">
                                                            {rawMetaTab === 'prompt' && runDetails.metadata.prompt && (
                                                                <pre className="max-h-64 overflow-auto text-xs bg-gray-50 p-3 rounded border">
                                                                    {typeof runDetails.metadata.prompt === 'string'
                                                                        ? runDetails.metadata.prompt
                                                                        : JSON.stringify(runDetails.metadata.prompt, null, 2)}
                                                                </pre>
                                                            )}
                                                            {rawMetaTab === 'response' && runDetails.metadata.response && (
                                                                <pre className="max-h-64 overflow-auto text-xs bg-gray-50 p-3 rounded border">
                                                                    {typeof runDetails.metadata.response === 'string'
                                                                        ? runDetails.metadata.response
                                                                        : JSON.stringify(runDetails.metadata.response, null, 2)}
                                                                </pre>
                                                            )}
                                                            {rawMetaTab === 'additional' &&
                                                                Object.keys(runDetails.metadata).filter((key) => !METADATA_KEYS_HIDDEN_FROM_RAW.includes(key)).length > 0 && (
                                                                    <pre className="max-h-72 overflow-auto text-xs bg-gray-50 p-3 rounded border">
                                                                        {JSON.stringify(
                                                                            Object.fromEntries(
                                                                                Object.entries(runDetails.metadata).filter(
                                                                                    ([key]) => !METADATA_KEYS_HIDDEN_FROM_RAW.includes(key)
                                                                                )
                                                                            ),
                                                                            null,
                                                                            2
                                                                        )}
                                                                    </pre>
                                                                )}
                                                        </div>
                                                    </section>
                                                )}

                                                {runDetails.related_tickets && runDetails.related_tickets.length > 0 && (
                                                    <section className="rounded-lg border border-gray-200 p-4">
                                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Related tickets</h4>
                                                        <div className="space-y-1">
                                                            {runDetails.related_tickets.map((ticket) => (
                                                                <Link
                                                                    key={ticket.id}
                                                                    href={`/app/admin/support/tickets/${ticket.id}`}
                                                                    className="block text-sm text-indigo-600 hover:text-indigo-900"
                                                                >
                                                                    {ticket.ticket_number}: {ticket.subject}
                                                                </Link>
                                                            ))}
                                                        </div>
                                                    </section>
                                                )}

                                                {!runDetails.metadata && (
                                                    <div className="border-t pt-4">
                                                        <p className="text-xs text-gray-500 italic">
                                                            No execution details available. Prompt/response logging may be disabled (AI_STORE_PROMPTS=false).
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="text-center py-8">
                                                <p className="text-sm text-red-600">Failed to load details</p>
                                            </div>
                                        )}
                                    </div>
                                    <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                        <button
                                            type="button"
                                            onClick={closeDetails}
                                            className="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm"
                                        >
                                            Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
