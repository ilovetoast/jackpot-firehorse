import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link, usePage } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import UploadApprovalsPanel from '../../Components/insights/UploadApprovalsPanel'
import InsightAiSuggestionReviewModal from '../../Components/insights/InsightAiSuggestionReviewModal'
import ContextualNavigationReviewTab from '../../Components/insights/ContextualNavigationReviewTab'
import {
    SparklesIcon,
    TagIcon,
    FolderIcon,
    AdjustmentsHorizontalIcon,
    CheckIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
    ListBulletIcon,
    RectangleStackIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    CloudArrowUpIcon,
} from '@heroicons/react/24/outline'
import { usePermission } from '../../hooks/usePermission'
import { InsightsBadge, useInsightsCounts } from '../../contexts/InsightsCountsContext'
import {
    hexToRgba,
    getWorkspaceButtonColor,
    normalizeHexColor,
    getSolidFillButtonForegroundHex,
    hexRgbCommaTriplet,
} from '../../utils/colorUtils'

const VALID_TABS = ['tags', 'categories', 'values', 'fields', 'contextual']

/** Sunken well + segmented pills (matches CollectionFiltersBar / PrimaryFilterToolbarControls). */
const reviewWellClass =
    'inline-flex max-w-full flex-wrap items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-100/90 p-0.5 shadow-inner'

const reviewSegBase =
    'inline-flex items-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--review-accent)] focus-visible:ring-offset-2'

const reviewSegInactive = 'text-slate-600 hover:bg-white/80 hover:text-slate-800'

/** Primary canvas for Review tab controls (contrasts with sidebar + list cards). */
const reviewChromeShellClass =
    'rounded-2xl border border-slate-200/90 bg-white p-4 shadow-sm shadow-slate-900/[0.04] ring-1 ring-slate-900/[0.03] sm:p-5'

/** Nested tier: sub-tabs / upload queue sit visually under the workspace row. */
const reviewNestedInsetClass =
    'rounded-xl border border-slate-200/70 border-l-[3px] border-l-[color:var(--review-accent)] bg-slate-50/85 p-3 shadow-inner sm:p-4'

function activeReviewSegStyle(accent, onAccent) {
    return {
        backgroundColor: accent,
        color: onAccent,
        boxShadow: `0 1px 2px ${hexToRgba('#000000', 0.06)}`,
    }
}

function badgeOnAccent(active) {
    return active
        ? 'shrink-0 bg-white/20 text-white ring-1 ring-inset ring-white/25'
        : 'shrink-0'
}

const AI_REVIEW_SUB_TABS = [
    {
        id: 'tags',
        Icon: TagIcon,
        label: 'Tags',
        countKey: 'tags',
        title: (n) => `${n} pending tag suggestion${n === 1 ? '' : 's'}`,
        aria: (n) => `Tags, ${n} pending`,
        hint: 'New tag labels AI thinks belong on specific assets.',
    },
    {
        id: 'categories',
        Icon: FolderIcon,
        label: 'Categories',
        countKey: 'categories',
        title: (n) => `${n} pending categor${n === 1 ? 'y' : 'ies'}`,
        aria: (n) => `Categories, ${n} pending`,
        hint: 'Suggested folder/category moves for assets.',
    },
    {
        id: 'values',
        Icon: ListBulletIcon,
        label: 'Dropdown options',
        countKey: 'values',
        title: (n) => `${n} pending option suggestion${n === 1 ? '' : 's'}`,
        aria: (n) => `Dropdown options, ${n} pending`,
        hint: 'Add a missing choice to an existing dropdown (select) field—based on repeated tags or metadata.',
    },
    {
        id: 'fields',
        Icon: RectangleStackIcon,
        label: 'New fields',
        countKey: 'fields',
        title: (n) => `${n} pending field suggestion${n === 1 ? '' : 's'}`,
        aria: (n) => `New fields, ${n} pending`,
        hint: 'Propose a whole new field (with starter options) when many assets share a tag or pattern you do not capture yet.',
    },
    {
        id: 'contextual',
        Icon: AdjustmentsHorizontalIcon,
        label: 'Contextual navigation',
        countKey: 'contextual',
        title: (n) => `${n} pending navigation recommendation${n === 1 ? '' : 's'}`,
        aria: (n) => `Contextual navigation, ${n} pending`,
        hint: 'Recommendations to enable, pin, suppress, or warn about quick filters under specific folders.',
    },
]

/** One-line context under the suggestion-type tabs */
const AI_REVIEW_TAB_CONTEXT = {
    tags: 'Per-asset tag ideas—accept to add the tag where it applies.',
    categories: 'Where assets might belong in your folder structure.',
    values:
        'Think apparel (**Product category**) or tackle (**Lure type**): when many assets keep getting labeled the same way—e.g. “jeans” or “crankbait”—but that label is not on the official dropdown yet, we may suggest adding it.',
    fields:
        'When many assets in a category share the same anchor tag (or metadata value) and your schema has no field for it, we suggest a field whose starter dropdown options are other tags that tend to appear on the same assets—not every frequent tag in the category.',
    contextual:
        'Quick-filter recommendations: which filters to surface, pin, or suppress for each folder, plus warnings when metadata is too noisy for sidebar navigation.',
}
const PER_PAGE = 50

function formatInsightsTimestamp(iso) {
    if (!iso || typeof iso !== 'string') return null
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return null
        return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    } catch {
        return null
    }
}

function formatRetryLabel(seconds) {
    if (seconds == null || Number.isNaN(seconds)) return null
    const s = Math.max(0, Math.ceil(seconds))
    if (s < 60) return `${s}s`
    const m = Math.ceil(s / 60)
    return `${m} min`
}

/** Empty state for Values / Fields — graphic card aligned with Insights light UI */
function ReviewInsightStructuralEmptyState({ variant, onLibraryScanQueued }) {
    const isValues = variant === 'values'
    const MainIcon = isValues ? ListBulletIcon : RectangleStackIcon
    const { can } = usePermission()
    const canQueueLibraryScan = can('company_settings.manage_ai_settings')
    const [scanLoading, setScanLoading] = useState(false)
    const [scanMessage, setScanMessage] = useState(null)
    const [scanError, setScanError] = useState(null)
    const [insightsMeta, setInsightsMeta] = useState(null)
    const [insightsStatusLoaded, setInsightsStatusLoaded] = useState(false)
    const [clockTick, setClockTick] = useState(() => Date.now())

    useEffect(() => {
        if (!canQueueLibraryScan) {
            setInsightsStatusLoaded(true)
            return
        }
        setInsightsStatusLoaded(false)
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        fetch('/app/api/companies/ai-settings', {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((r) => r.json().catch(() => ({})))
            .then((data) => {
                if (data.settings) setInsightsMeta(data.settings)
            })
            .catch(() => {})
            .finally(() => setInsightsStatusLoaded(true))
    }, [canQueueLibraryScan, variant])

    useEffect(() => {
        if (!canQueueLibraryScan) return
        const id = window.setInterval(() => setClockTick(Date.now()), 15_000)
        return () => window.clearInterval(id)
    }, [canQueueLibraryScan])

    const cooldownUntilMs = insightsMeta?.insights_manual_run_available_at
        ? new Date(insightsMeta.insights_manual_run_available_at).getTime()
        : null
    const scanCooldownActive = cooldownUntilMs != null && !Number.isNaN(cooldownUntilMs) && cooldownUntilMs > clockTick
    const scanButtonDisabled = scanLoading || scanCooldownActive || !insightsStatusLoaded

    const runLibraryScan = useCallback(async () => {
        setScanLoading(true)
        setScanMessage(null)
        setScanError(null)
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch('/app/api/companies/ai-settings/run-insights', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            })
            const data = await res.json().catch(() => ({}))
            if (data.settings) {
                setInsightsMeta(data.settings)
            }
            if (!res.ok) {
                if (res.status === 429) {
                    const retry = data.retry_after_seconds
                    const extra =
                        retry != null
                            ? ` Try again in about ${formatRetryLabel(retry) || 'a few minutes'}.`
                            : ''
                    setScanError((data.error || 'Please wait before running again.') + extra)
                    return
                }
                const err =
                    data.error ||
                    data.message ||
                    (res.status === 403 ? 'You do not have permission to run a scan.' : 'Could not queue a scan.')
                setScanError(err)
                return
            }
            setScanMessage(data.message || 'Scan queued. New suggestions appear after the job finishes—check back shortly.')
            setClockTick(Date.now())
            onLibraryScanQueued?.()
        } catch {
            setScanError('Could not queue a scan. Try again or use Company → AI settings.')
        } finally {
            setScanLoading(false)
        }
    }, [onLibraryScanQueued])

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-sm">
            <div className="relative">
                <div
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_85%_55%_at_50%_-15%,rgba(var(--review-accent-rgb),0.11),transparent)]"
                    aria-hidden
                />
                <div className="relative grid gap-8 p-8 sm:grid-cols-[minmax(0,7.5rem)_1fr] sm:items-center sm:gap-10 sm:p-10">
                    <div
                        className="mx-auto flex aspect-square w-28 shrink-0 items-center justify-center rounded-2xl border border-[rgba(var(--review-accent-rgb),0.14)] bg-gradient-to-br from-[rgba(var(--review-accent-rgb),0.09)] to-white shadow-[inset_0_1px_0_0_rgba(255,255,255,0.95)] sm:mx-0 sm:w-full"
                        aria-hidden
                    >
                        <MainIcon className="h-12 w-12 text-[color:var(--review-accent)]" />
                    </div>
                    <div className="text-center sm:text-left">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)] opacity-90">
                            {isValues ? 'Dropdown options' : 'New metadata fields'}
                        </p>
                        <h3 className="mt-1 text-xl font-semibold tracking-tight text-gray-900">
                            {isValues ? 'No suggested dropdown options yet' : 'No suggested new fields yet'}
                        </h3>
                        <p className="mt-3 max-w-xl text-sm leading-relaxed text-slate-600">
                            {isValues
                                ? 'We look across your library for labels that keep showing up on the same kind of field—on tags, approved metadata, or AI drafts—but are not on the field’s official dropdown list yet. When enough assets line up, we queue a suggestion here for you to accept or reject. Nothing changes until you say yes.'
                                : 'We look category by category. When a strong share of assets carries the same anchor (usually a tag), we may propose a new field. Starter options are co-tagged values on those same assets that are unusually tied to the anchor compared with the rest of the category—so lists stay short and on-theme. You can always edit before creating.'}
                        </p>
                        <ul className="mx-auto mt-5 max-w-xl space-y-2.5 text-left text-sm text-slate-700 sm:mx-0">
                            <li className="flex gap-2.5">
                                <span
                                    className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[color:var(--review-accent)]"
                                    aria-hidden
                                />
                                <span>
                                    <span className="font-medium text-gray-900">Needs enough real usage.</span>{' '}
                                    We only surface ideas when many assets point the same way, so you are not flooded with one-off noise.
                                </span>
                            </li>
                            <li className="flex gap-2.5">
                                <span
                                    className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[color:var(--review-accent)]"
                                    aria-hidden
                                />
                                <span>
                                    <span className="font-medium text-gray-900">Runs in the background.</span>{' '}
                                    Your library is scanned on a schedule (and can be run sooner if your plan and settings allow). This is not instant as you click each asset.
                                </span>
                            </li>
                            <li className="flex gap-2.5">
                                <span
                                    className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[color:var(--review-accent)]"
                                    aria-hidden
                                />
                                <span>
                                    <span className="font-medium text-gray-900">You stay in control.</span>{' '}
                                    Accept to add an option or create a field; dismiss to teach the system what not to suggest again.
                                </span>
                            </li>
                        </ul>
                        <div className="mx-auto mt-6 max-w-xl border-t border-slate-200/90 pt-5 text-left sm:mx-0">
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Quick example</p>
                            <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                                {isValues ? (
                                    <>
                                        <span className="font-medium text-slate-800">Apparel:</span> you already use a dropdown like{' '}
                                        <span className="font-medium text-slate-800">Product category</span>, but teams keep tagging or describing assets as{' '}
                                        <span className="font-medium text-slate-800">jeans</span> while “jeans” is not on the official list—we may suggest
                                        adding it. <span className="font-medium text-slate-800">Fishing / tackle:</span> same pattern for{' '}
                                        <span className="font-medium text-slate-800">Lure type</span> when many assets show{' '}
                                        <span className="font-medium text-slate-800">crankbait</span> or{' '}
                                        <span className="font-medium text-slate-800">topwater</span> outside the pick list.
                                    </>
                                ) : (
                                    <>
                                        <span className="font-medium text-slate-800">Apparel:</span> a large share of assets in a line or season carry tags
                                        like <span className="font-medium text-slate-800">product</span> or{' '}
                                        <span className="font-medium text-slate-800">new arrival</span>, but you have no field for{' '}
                                        <span className="font-medium text-slate-800">Product type</span> or merchandising breakdown yet—we may suggest one
                                        with starter options. <span className="font-medium text-slate-800">Fishing:</span> many assets cluster around tags
                                        like <span className="font-medium text-slate-800">soft plastics</span> or{' '}
                                        <span className="font-medium text-slate-800">hard baits</span> without a{' '}
                                        <span className="font-medium text-slate-800">Tackle category</span> field—we may suggest that field from how the
                                        library actually groups gear.
                                    </>
                                )}
                            </p>
                        </div>
                        {canQueueLibraryScan ? (
                            <div className="mx-auto mt-5 max-w-xl space-y-3 sm:mx-0">
                                {!insightsStatusLoaded ? (
                                    <p className="text-xs text-slate-500">Loading scan status…</p>
                                ) : insightsMeta ? (
                                    <div className="rounded-lg border border-slate-200/90 bg-slate-50/80 px-3 py-2.5 text-xs text-slate-600">
                                        <p>
                                            <span className="font-semibold text-slate-800">Last finished scan:</span>{' '}
                                            {formatInsightsTimestamp(insightsMeta.last_insights_run_at) ||
                                                'None yet (first run completes in the background after you queue).'}
                                        </p>
                                        <p className="mt-1">
                                            <span className="font-semibold text-slate-800">Pending in Review:</span>{' '}
                                            {typeof insightsMeta.insights_pending_suggestions_count === 'number'
                                                ? `${insightsMeta.insights_pending_suggestions_count} value/field suggestion${
                                                      insightsMeta.insights_pending_suggestions_count === 1 ? '' : 's'
                                                  }`
                                                : '—'}
                                            {typeof insightsMeta.insights_pending_suggestions_count === 'number' &&
                                            insightsMeta.insights_pending_suggestions_count > 0
                                                ? ' waiting on the Dropdown options / New fields tabs.'
                                                : ' (this count updates after a run produces rows).'}
                                        </p>
                                        <p className="mt-1">
                                            <span className="font-semibold text-slate-800">Last queued from here / settings:</span>{' '}
                                            {formatInsightsTimestamp(insightsMeta.last_insights_manual_queued_at) || '—'}
                                        </p>
                                        {scanCooldownActive && insightsMeta.insights_manual_run_available_at ? (
                                            <p className="mt-1 text-amber-800">
                                                Button unlocks after{' '}
                                                {formatInsightsTimestamp(insightsMeta.insights_manual_run_available_at)} (
                                                {insightsMeta.manual_insights_run_cooldown_minutes ?? '—'} min cooldown between
                                                runs).
                                            </p>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="text-xs text-slate-500">Could not load scan status.</p>
                                )}
                                <button
                                    type="button"
                                    disabled={scanButtonDisabled}
                                    onClick={() => void runLibraryScan()}
                                    className="inline-flex items-center justify-center rounded-lg border border-[rgba(var(--review-accent-rgb),0.22)] bg-white px-4 py-2 text-sm font-medium text-[color:var(--review-accent)] shadow-sm hover:bg-[rgba(var(--review-accent-rgb),0.08)] disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {!insightsStatusLoaded
                                        ? 'Loading…'
                                        : scanLoading
                                          ? 'Queuing scan…'
                                          : scanCooldownActive
                                            ? 'Wait for cooldown'
                                            : 'Run library pattern scan now'}
                                </button>
                                <p className="text-xs text-slate-500">
                                    Uses your company’s AI settings (respects plan limits).{' '}
                                    {typeof insightsMeta?.manual_insights_run_cooldown_minutes === 'number' ? (
                                        <>
                                            You can queue a new scan at most once every {insightsMeta.manual_insights_run_cooldown_minutes}{' '}
                                            minutes to avoid stacking duplicate jobs.
                                        </>
                                    ) : (
                                        <>Manual runs are rate-limited to avoid stacking duplicate jobs.</>
                                    )}{' '}
                                    In{' '}
                                    <Link
                                        href="/app/companies/settings#ai-settings"
                                        className="font-medium text-[color:var(--review-accent)] hover:opacity-90 hover:underline"
                                    >
                                        AI settings
                                    </Link>
                                    , turn library insights on or see the same status.
                                </p>
                                {scanMessage ? <p className="text-sm text-emerald-700">{scanMessage}</p> : null}
                                {scanError ? (
                                    <p className="text-sm text-amber-800">
                                        {scanError}{' '}
                                        <Link
                                            href="/app/companies/settings#ai-settings"
                                            className="font-medium text-[color:var(--review-accent)] hover:opacity-90 hover:underline"
                                        >
                                            Company → AI settings
                                        </Link>
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
            <div className="flex items-start gap-3 border-t border-slate-100 bg-slate-50/90 px-6 py-4 sm:px-10">
                <SparklesIcon className="mt-0.5 h-5 w-5 shrink-0 text-[color:var(--review-accent)]" aria-hidden />
                <p className="text-xs leading-relaxed text-slate-600">
                    These ideas come from the same scheduled “library pattern” job that powers this tab. If you never see rows, your categories may still be
                    building up volume, or insights may need to be enabled under Company → AI settings (depending on your plan).
                </p>
            </div>
        </div>
    )
}

function PaginationBar({ pagination, loading, onPageChange }) {
    if (loading) return null
    const { total, last_page, per_page, current_page } = pagination
    if (last_page <= 1 && total <= per_page) return null
    const from = total === 0 ? 0 : (current_page - 1) * per_page + 1
    const to = Math.min(current_page * per_page, total)

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            <span>
                {total > 0 ? (
                    <>
                        Showing <span className="font-medium text-gray-900">{from}</span>–
                        <span className="font-medium text-gray-900">{to}</span> of{' '}
                        <span className="font-medium text-gray-900">{total}</span>
                    </>
                ) : (
                    'No results'
                )}
            </span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={current_page <= 1}
                    onClick={() => onPageChange((p) => Math.max(1, p - 1))}
                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <ChevronLeftIcon className="h-4 w-4" />
                    Previous
                </button>
                <span className="px-2 text-gray-500">
                    Page {current_page} of {last_page}
                </span>
                <button
                    type="button"
                    disabled={current_page >= last_page}
                    onClick={() => onPageChange((p) => p + 1)}
                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Next
                    <ChevronRightIcon className="h-4 w-4" />
                </button>
            </div>
        </div>
    )
}

function SectionBulkBar({
    sectionKeys,
    selected,
    onToggleSelectAll,
    onBulkAccept,
    onBulkReject,
    canAccept,
    canReject,
    processing,
}) {
    const allSelected = sectionKeys.length > 0 && sectionKeys.every((k) => selected.has(k))
    const numSelected = sectionKeys.filter((k) => selected.has(k)).length
    const selectedInSection = sectionKeys.filter((k) => selected.has(k))
    const someBusy = sectionKeys.some((k) => processing.has(k))

    const setIndeterminate = (el) => {
        if (el) el.indeterminate = numSelected > 0 && !allSelected
    }

    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-gray-50 px-4 py-2.5">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                <input
                    ref={setIndeterminate}
                    type="checkbox"
                    checked={sectionKeys.length > 0 && allSelected}
                    onChange={onToggleSelectAll}
                    disabled={sectionKeys.length === 0 || someBusy}
                    className="h-4 w-4 rounded border-gray-300 text-[color:var(--review-accent)] focus:ring-[rgba(var(--review-accent-rgb),0.45)]"
                />
                <span>
                    Select all ({sectionKeys.length})
                    {numSelected > 0 && <span className="text-gray-500"> · {numSelected} selected</span>}
                </span>
            </label>
            <div className="ml-auto flex flex-wrap gap-2">
                {canAccept && (
                    <button
                        type="button"
                        disabled={selectedInSection.length === 0 || someBusy}
                        onClick={() => onBulkAccept(selectedInSection)}
                        className="inline-flex items-center gap-1 rounded-md bg-[color:var(--review-accent)] px-3 py-1.5 text-sm font-medium text-white hover:brightness-[0.92] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <CheckIcon className="h-4 w-4" />
                        Accept selected ({selectedInSection.length})
                    </button>
                )}
                {canReject && (
                    <button
                        type="button"
                        disabled={selectedInSection.length === 0 || someBusy}
                        onClick={() => onBulkReject(selectedInSection)}
                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <XMarkIcon className="h-4 w-4" />
                        Reject selected ({selectedInSection.length})
                    </button>
                )}
            </div>
        </div>
    )
}

export default function AnalyticsReview({
    initialTab = 'tags',
    initialWorkspace = 'ai',
    initialApprovalQueue = 'team',
    canViewAi = false,
    canViewUploadApprovals = false,
    creatorModuleEnabled = false,
    canCreateFieldFromSuggestion = false,
}) {
    const { auth, reviewTabCounts } = usePage().props
    const brandId = auth?.activeBrand?.id

    const [activeTab, setActiveTab] = useState(() =>
        VALID_TABS.includes(initialTab) ? initialTab : 'tags'
    )
    const [workspace, setWorkspace] = useState(() => (initialWorkspace === 'uploads' ? 'uploads' : 'ai'))
    const [approvalQueue, setApprovalQueue] = useState(() =>
        initialApprovalQueue === 'creator' ? 'creator' : 'team'
    )
    const [page, setPage] = useState(1)
    const [items, setItems] = useState([])
    const [pagination, setPagination] = useState({
        total: 0,
        current_page: 1,
        last_page: 1,
        per_page: PER_PAGE,
    })
    const [loading, setLoading] = useState(true)
    /** Bumps after “Run library pattern scan” so the list refetches when suggestions may have landed */
    const [reviewRefreshNonce, setReviewRefreshNonce] = useState(0)
    const [processing, setProcessing] = useState(new Set())
    const [insightPreview, setInsightPreview] = useState({ open: false, index: 0 })
    const [selected, setSelected] = useState(() => new Set())
    const [aiCountsSnapshot, setAiCountsSnapshot] = useState(() => ({
        tags: Number(reviewTabCounts?.tags) || 0,
        categories: Number(reviewTabCounts?.categories) || 0,
        values: Number(reviewTabCounts?.values) || 0,
        fields: Number(reviewTabCounts?.fields) || 0,
        contextual: Number(reviewTabCounts?.contextual) || 0,
    }))
    const [uploadCountsSnapshot, setUploadCountsSnapshot] = useState({ team: 0, creator: 0 })
    const { can } = usePermission()
    const canAccept = can('metadata.suggestions.apply') || can('metadata.edit_post_upload')
    const canReject = can('metadata.suggestions.dismiss') || can('metadata.edit_post_upload')
    const canCreateField =
        canCreateFieldFromSuggestion ||
        can('metadata.tenant.field.create') ||
        can('metadata.tenant.field.manage')
    const insightsCounts = useInsightsCounts()
    const reviewAccent = useMemo(
        () => normalizeHexColor(getWorkspaceButtonColor(auth?.activeBrand)),
        [auth?.activeBrand],
    )
    const onReviewAccent = useMemo(() => getSolidFillButtonForegroundHex(reviewAccent), [reviewAccent])

    const refetchReviewBadges = useCallback(async () => {
        const headers = { Accept: 'application/json' }
        if (canViewAi) {
            try {
                const r = await fetch('/app/api/ai/review/counts', {
                    credentials: 'same-origin',
                    headers,
                })
                if (r.ok) {
                    const j = await r.json()
                    setAiCountsSnapshot({
                        tags: Number(j.tags) || 0,
                        categories: Number(j.categories) || 0,
                        values: Number(j.values) || 0,
                        fields: Number(j.fields) || 0,
                        contextual: Number(j.contextual) || 0,
                    })
                }
            } catch {
                /* keep previous snapshot */
            }
        }
        if (canViewUploadApprovals && brandId) {
            try {
                const r = await fetch(`/app/api/brands/${brandId}/approvals?count_only=1`, {
                    credentials: 'same-origin',
                    headers,
                })
                if (r.ok) {
                    const j = await r.json()
                    setUploadCountsSnapshot({
                        team: Number(j.team) || 0,
                        creator: Number(j.creator) || 0,
                    })
                }
            } catch {
                /* keep previous snapshot */
            }
        }
    }, [canViewAi, canViewUploadApprovals, brandId])

    useEffect(() => {
        void refetchReviewBadges()
    }, [refetchReviewBadges])

    useEffect(() => {
        if (!reviewTabCounts) {
            return
        }
        setAiCountsSnapshot((prev) => ({
            tags: Math.max(prev.tags, Number(reviewTabCounts.tags) || 0),
            categories: Math.max(prev.categories, Number(reviewTabCounts.categories) || 0),
            values: Math.max(prev.values, Number(reviewTabCounts.values) || 0),
            fields: Math.max(prev.fields, Number(reviewTabCounts.fields) || 0),
            contextual: Math.max(prev.contextual ?? 0, Number(reviewTabCounts.contextual) || 0),
        }))
    }, [
        reviewTabCounts?.tags,
        reviewTabCounts?.categories,
        reviewTabCounts?.values,
        reviewTabCounts?.fields,
        reviewTabCounts?.contextual,
    ])

    useEffect(() => {
        if (VALID_TABS.includes(initialTab)) {
            setActiveTab(initialTab)
        }
    }, [initialTab])

    useEffect(() => {
        setWorkspace(initialWorkspace === 'uploads' ? 'uploads' : 'ai')
    }, [initialWorkspace])

    useEffect(() => {
        setApprovalQueue(initialApprovalQueue === 'creator' ? 'creator' : 'team')
    }, [initialApprovalQueue])

    useEffect(() => {
        setPage(1)
    }, [activeTab])

    useEffect(() => {
        setSelected(new Set())
    }, [activeTab, page])

    useEffect(() => {
        if (!canViewAi || workspace !== 'ai') {
            setLoading(false)
            return
        }
        // Phase 6 — the Contextual Navigation tab owns its own fetch via
        // ContextualNavigationReviewTab. Skip the items pipeline when it
        // is active so we don't double-fetch (its endpoint shape differs).
        if (activeTab === 'contextual') {
            setLoading(false)
            setItems([])
            setPagination({ total: 0, current_page: 1, last_page: 1, per_page: PER_PAGE })
            return
        }
        setLoading(true)
        const params = new URLSearchParams({
            type: activeTab,
            page: String(page),
            per_page: String(PER_PAGE),
        })
        fetch(`/app/api/ai/review?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setItems(data.items || [])
                setPagination({
                    total: data.total ?? 0,
                    current_page: data.current_page ?? 1,
                    last_page: data.last_page ?? 1,
                    per_page: data.per_page ?? PER_PAGE,
                })
                setLoading(false)
            })
            .catch(() => setLoading(false))
    }, [activeTab, page, canViewAi, workspace, reviewRefreshNonce])

    useEffect(() => {
        if (typeof window === 'undefined') return
        const url = new URL(window.location.href)
        if (workspace === 'uploads') {
            url.searchParams.set('workspace', 'uploads')
            url.searchParams.delete('tab')
            if (creatorModuleEnabled && canViewUploadApprovals) {
                if (approvalQueue === 'creator') {
                    url.searchParams.set('approval_queue', 'creator')
                } else {
                    url.searchParams.delete('approval_queue')
                }
            } else {
                url.searchParams.delete('approval_queue')
            }
        } else {
            url.searchParams.delete('workspace')
            url.searchParams.delete('approval_queue')
            url.searchParams.set('tab', activeTab)
        }
        window.history.replaceState(null, '', url.pathname + url.search)
    }, [workspace, activeTab, approvalQueue, creatorModuleEnabled, canViewUploadApprovals])

    /** Group metadata candidates by field; section_header matches Insights metadata “Type” naming. */
    const categorySections = useMemo(() => {
        if (activeTab !== 'categories') {
            return []
        }
        const byFieldKey = new Map()
        for (const item of items) {
            const key = item.field_key || `unknown-${item.id}`
            if (!byFieldKey.has(key)) {
                byFieldKey.set(key, {
                    fieldKey: key,
                    sectionHeader: item.section_header || item.field_label || key,
                    rows: [],
                })
            }
            byFieldKey.get(key).rows.push(item)
        }
        return Array.from(byFieldKey.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const valueSections = useMemo(() => {
        if (activeTab !== 'values') return []
        const byField = new Map()
        for (const item of items) {
            const key = item.field_key || `unknown-${item.id}`
            if (!byField.has(key)) {
                byField.set(key, {
                    fieldKey: key,
                    sectionHeader: item.field_label || key,
                    rows: [],
                })
            }
            byField.get(key).rows.push(item)
        }
        return Array.from(byField.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const fieldSections = useMemo(() => {
        if (activeTab !== 'fields') return []
        const byCat = new Map()
        for (const item of items) {
            const key = item.category_slug || `unknown-${item.id}`
            if (!byCat.has(key)) {
                byCat.set(key, {
                    categorySlug: key,
                    sectionHeader: item.category_name || key,
                    rows: [],
                })
            }
            byCat.get(key).rows.push(item)
        }
        return Array.from(byCat.values()).sort((a, b) =>
            a.sectionHeader.localeCompare(b.sectionHeader, undefined, { sensitivity: 'base' })
        )
    }, [items, activeTab])

    const processingKey = useCallback((item) => {
        if (item.type === 'value_suggestion') return `vs-${item.id}`
        if (item.type === 'field_suggestion') return `fs-${item.id}`
        return String(item.id)
    }, [])

    const tagKeysOnPage = useMemo(() => items.map((i) => processingKey(i)), [items, processingKey])

    const toggleSelected = useCallback((pk) => {
        setSelected((prev) => {
            const next = new Set(prev)
            if (next.has(pk)) next.delete(pk)
            else next.add(pk)
            return next
        })
    }, [])

    const mergedAiTabCounts = useMemo(() => {
        // For contextual we deliberately exclude pagination.total: the
        // ContextualNavigationReviewTab owns its own fetch and `pagination`
        // is reset to zeroes when activeTab === 'contextual' (see the items
        // useEffect short-circuit), so leaning on it would underreport.
        const mergeKey = (key, includePagination = true) =>
            Math.max(
                Number(insightsCounts[key]) || 0,
                Number(aiCountsSnapshot[key]) || 0,
                includePagination && activeTab === key && !loading
                    ? Number(pagination.total) || 0
                    : 0
            )
        return {
            tags: mergeKey('tags'),
            categories: mergeKey('categories'),
            values: mergeKey('values'),
            fields: mergeKey('fields'),
            contextual: mergeKey('contextual', false),
        }
    }, [
        insightsCounts.tags,
        insightsCounts.categories,
        insightsCounts.values,
        insightsCounts.fields,
        insightsCounts.contextual,
        aiCountsSnapshot.tags,
        aiCountsSnapshot.categories,
        aiCountsSnapshot.values,
        aiCountsSnapshot.fields,
        aiCountsSnapshot.contextual,
        activeTab,
        loading,
        pagination.total,
    ])

    const aiSuggestionsGrandTotal = useMemo(
        () =>
            mergedAiTabCounts.tags +
            mergedAiTabCounts.categories +
            mergedAiTabCounts.values +
            mergedAiTabCounts.fields +
            mergedAiTabCounts.contextual,
        [mergedAiTabCounts]
    )

    const mergedUploadTeam = useMemo(
        () => Math.max(insightsCounts.uploadTeam ?? 0, uploadCountsSnapshot.team ?? 0),
        [insightsCounts.uploadTeam, uploadCountsSnapshot.team]
    )
    const mergedUploadCreator = useMemo(
        () => Math.max(insightsCounts.uploadCreator ?? 0, uploadCountsSnapshot.creator ?? 0),
        [insightsCounts.uploadCreator, uploadCountsSnapshot.creator]
    )
    const mergedUploadTotal = useMemo(
        () => mergedUploadTeam + mergedUploadCreator,
        [mergedUploadTeam, mergedUploadCreator]
    )

    const handleApprove = async (item, options = {}) => {
        const { skipBadgeSync = false } = options
        const pk = processingKey(item)
        if (processing.has(pk)) return
        if (item.type === 'field_suggestion') {
            if (!canCreateField || !canAccept) return
        } else if (!canAccept) {
            return
        }
        setProcessing((p) => new Set(p).add(pk))
        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/accept`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/approve`
            } else if (item.type === 'value_suggestion') {
                url = `/app/api/ai/review/value-suggestions/${item.id}/accept`
            } else if (item.type === 'field_suggestion') {
                url = `/app/api/ai/review/field-suggestions/${item.id}/accept`
            } else {
                return
            }
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) {
                setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
                if (!skipBadgeSync) {
                    await Promise.all([
                        insightsCounts.reload?.() ?? Promise.resolve(),
                        refetchReviewBadges(),
                    ])
                }
            } else if (res.status === 403 || res.status === 422) {
                const body = await res.json().catch(() => ({}))
                const msg = body.message || body.errors?.[Object.keys(body.errors || {})[0]]?.[0] || 'Action failed'
                window.alert(msg)
            }
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(pk)
                return next
            })
        }
    }

    const handleReject = async (item, options = {}) => {
        const { skipBadgeSync = false } = options
        const pk = processingKey(item)
        if (processing.has(pk) || !canReject) return
        setProcessing((p) => new Set(p).add(pk))
        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/dismiss`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/reject`
            } else if (item.type === 'value_suggestion') {
                url = `/app/api/ai/review/value-suggestions/${item.id}/reject`
            } else if (item.type === 'field_suggestion') {
                url = `/app/api/ai/review/field-suggestions/${item.id}/reject`
            } else {
                return
            }
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (res.ok) {
                setItems((prev) => prev.filter((i) => processingKey(i) !== pk))
                if (!skipBadgeSync) {
                    await Promise.all([
                        insightsCounts.reload?.() ?? Promise.resolve(),
                        refetchReviewBadges(),
                    ])
                }
            }
        } finally {
            setProcessing((p) => {
                const next = new Set(p)
                next.delete(pk)
                return next
            })
        }
    }

    const bulkApproveKeys = async (keys) => {
        const list = keys.map((pk) => items.find((i) => processingKey(i) === pk)).filter(Boolean)
        for (const item of list) {
            if (item.type === 'field_suggestion' && (!canCreateField || !canAccept)) continue
            if (item.type !== 'field_suggestion' && !canAccept) continue
            await handleApprove(item, { skipBadgeSync: true })
        }
        await Promise.all([insightsCounts.reload?.() ?? Promise.resolve(), refetchReviewBadges()])
    }

    const bulkRejectKeys = async (keys) => {
        if (!canReject) return
        const list = keys.map((pk) => items.find((i) => processingKey(i) === pk)).filter(Boolean)
        for (const item of list) {
            await handleReject(item, { skipBadgeSync: true })
        }
        await Promise.all([insightsCounts.reload?.() ?? Promise.resolve(), refetchReviewBadges()])
    }

    const toggleSelectAll = (sectionKeys) => {
        setSelected((prev) => {
            const next = new Set(prev)
            const allOn = sectionKeys.length > 0 && sectionKeys.every((k) => next.has(k))
            if (allOn) {
                sectionKeys.forEach((k) => next.delete(k))
            } else {
                sectionKeys.forEach((k) => next.add(k))
            }
            return next
        })
    }

    const emptyLabel = () => {
        if (activeTab === 'values') return 'dropdown option'
        if (activeTab === 'fields') return 'new field'
        return activeTab
    }

    const openInsightPreviewAt = useCallback((idx) => {
        setInsightPreview({ open: true, index: Math.max(0, idx) })
    }, [])

    const renderSuggestionRow = (item, showCheckbox = true, itemIndex = 0) => {
        const pk = processingKey(item)
        const isTag = item.type === 'tag'
        const isCandidate = item.type === 'metadata_candidate'
        const fieldContext =
            isCandidate &&
            (item.section_header || item.field_display_label || item.field_label || item.field_key)
        return (
            <li key={pk} className="flex items-start gap-4 p-4 hover:bg-gray-50">
                {showCheckbox && (canAccept || canReject) && (
                    <input
                        type="checkbox"
                        checked={selected.has(pk)}
                        onChange={() => toggleSelected(pk)}
                        disabled={processing.has(pk)}
                        className="mt-2 h-4 w-4 shrink-0 rounded border-gray-300 text-[color:var(--review-accent)] focus:ring-[rgba(var(--review-accent-rgb),0.45)]"
                    />
                )}
                <button
                    type="button"
                    onClick={() => openInsightPreviewAt(itemIndex)}
                    className="group relative h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-gray-100 shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[rgba(var(--review-accent-rgb),0.45)] focus-visible:ring-offset-2"
                    title="Larger preview & quick review"
                >
                    {item.thumbnail_url ? (
                        <img src={item.thumbnail_url} alt="" className="h-full w-full object-cover transition group-hover:opacity-95" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center text-gray-400">
                            <SparklesIcon className="h-6 w-6" />
                        </div>
                    )}
                    <span className="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/55 to-transparent py-1 text-center text-[9px] font-semibold uppercase tracking-wide text-white opacity-0 transition group-hover:opacity-100">
                        Review
                    </span>
                </button>
                <div className="min-w-0 flex-1">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">
                        {isTag ? 'Suggested tag' : isCandidate ? 'Suggested field value' : 'Suggestion'}
                    </p>
                    <p className="mt-0.5 text-lg font-semibold leading-snug text-gray-900">{item.suggestion}</p>
                    {fieldContext ? (
                        <p className="mt-1 text-xs font-medium text-slate-600">
                            For metadata field:{' '}
                            <span className="text-slate-900">{item.section_header || item.field_display_label || item.field_label}</span>
                            {item.field_key && item.field_label !== item.field_key ? (
                                <span className="font-normal text-slate-400"> ({item.field_key})</span>
                            ) : null}
                        </p>
                    ) : null}
                    <p className="mt-1.5 text-sm text-gray-600">
                        <span className="font-medium text-gray-800">Asset</span>{' '}
                        <span className="text-gray-600">
                            {item.asset_title || item.asset_filename || 'Untitled'}
                        </span>
                        {item.asset_category ? (
                            <span className="text-gray-500"> · Folder: {item.asset_category}</span>
                        ) : null}
                    </p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                    {item.confidence != null && (
                        <span className="whitespace-nowrap rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                            ~{Math.round(item.confidence * 100)}% confidence
                        </span>
                    )}
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {(canAccept || canReject) && (
                            <>
                                {canAccept && (
                                    <button
                                        type="button"
                                        onClick={() => handleApprove(item)}
                                        disabled={processing.has(pk)}
                                        className="inline-flex items-center gap-1 rounded-md bg-[color:var(--review-accent)] px-3 py-1.5 text-sm font-medium text-white hover:brightness-[0.92] disabled:opacity-50"
                                    >
                                        <CheckIcon className="h-4 w-4" />
                                        Accept
                                    </button>
                                )}
                                {canReject && (
                                    <button
                                        type="button"
                                        onClick={() => handleReject(item)}
                                        disabled={processing.has(pk)}
                                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                    >
                                        <XMarkIcon className="h-4 w-4" />
                                        Reject
                                    </button>
                                )}
                            </>
                        )}
                        <Link
                            href={`/app/assets?q=${encodeURIComponent(item.asset_id)}&asset=${encodeURIComponent(item.asset_id)}`}
                            className="text-gray-400 hover:text-[color:var(--review-accent)]"
                            title="Open in grid"
                        >
                            <ArrowTopRightOnSquareIcon className="h-5 w-5" />
                        </Link>
                    </div>
                </div>
            </li>
        )
    }

    if (!canViewAi && !canViewUploadApprovals) {
        return (
            <InsightsLayout title="Review" activeSection="review">
                <div className="rounded-lg bg-amber-50 p-4 text-amber-800">
                    You do not have permission to view this page.
                </div>
            </InsightsLayout>
        )
    }

    const showWorkspaceToggle = canViewAi && canViewUploadApprovals
    const showReviewChrome =
        showWorkspaceToggle ||
        (canViewAi && workspace === 'ai') ||
        (workspace === 'uploads' && canViewUploadApprovals && creatorModuleEnabled)

    return (
        <InsightsLayout title="Review" activeSection="review">
            <div
                className="space-y-6"
                style={{
                    ['--review-accent']: reviewAccent,
                    ['--review-accent-rgb']: hexRgbCommaTriplet(reviewAccent),
                }}
            >
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Review</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Triage AI ideas for tags, categories, dropdowns, and new fields—and teammate uploads that need approval—before anything changes in
                        your library.
                    </p>
                </div>

                {showReviewChrome ? (
                    <div className={reviewChromeShellClass}>
                        {showWorkspaceToggle ? (
                            <>
                                <div>
                                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                        What to triage
                                    </p>
                                    <p className="mt-1 max-w-2xl text-xs leading-snug text-slate-500">
                                        Pick the source of work first. A second row appears when that source has more
                                        than one queue—lists always load in the main column below this card.
                                    </p>
                                </div>
                                <div
                                    className={`mt-3 ${reviewWellClass}`}
                                    style={{ ['--review-accent']: reviewAccent }}
                                    role="tablist"
                                    aria-label="Review workspace"
                                >
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={workspace === 'ai'}
                                        onClick={() => setWorkspace('ai')}
                                        style={workspace === 'ai' ? activeReviewSegStyle(reviewAccent, onReviewAccent) : undefined}
                                        className={`${reviewSegBase} ${workspace === 'ai' ? 'font-semibold' : reviewSegInactive}`}
                                    >
                                        <SparklesIcon className="h-5 w-5 shrink-0 opacity-90" />
                                        <span className="whitespace-nowrap">AI suggestions</span>
                                        {aiSuggestionsGrandTotal > 0 && (
                                            <InsightsBadge
                                                count={aiSuggestionsGrandTotal}
                                                className={badgeOnAccent(workspace === 'ai')}
                                            />
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={workspace === 'uploads'}
                                        onClick={() => setWorkspace('uploads')}
                                        style={
                                            workspace === 'uploads'
                                                ? activeReviewSegStyle(reviewAccent, onReviewAccent)
                                                : undefined
                                        }
                                        className={`${reviewSegBase} ${workspace === 'uploads' ? 'font-semibold' : reviewSegInactive}`}
                                    >
                                        <CloudArrowUpIcon className="h-5 w-5 shrink-0 opacity-90" />
                                        <span className="whitespace-nowrap">Upload approvals</span>
                                        {mergedUploadTotal > 0 && (
                                            <InsightsBadge
                                                count={mergedUploadTotal}
                                                className={badgeOnAccent(workspace === 'uploads')}
                                            />
                                        )}
                                    </button>
                                </div>
                            </>
                        ) : canViewAi && workspace === 'ai' ? (
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                    AI suggestions
                                </p>
                                <p className="mt-1 max-w-2xl text-xs leading-snug text-slate-500">
                                    Choose what kind of metadata idea you are triaging; the list updates below this
                                    card.
                                </p>
                            </div>
                        ) : workspace === 'uploads' && canViewUploadApprovals && creatorModuleEnabled ? (
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                    Upload approvals
                                </p>
                                <p className="mt-1 max-w-2xl text-xs leading-snug text-slate-500">
                                    Choose which ingest queue to open—the panel below follows your selection.
                                </p>
                            </div>
                        ) : null}

                        {workspace === 'uploads' && canViewUploadApprovals && creatorModuleEnabled ? (
                            <div
                                className={`${reviewNestedInsetClass} ${showWorkspaceToggle ? 'mt-4' : 'mt-3'}`}
                            >
                                {showWorkspaceToggle ? (
                                    <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                        Within upload approvals
                                    </p>
                                ) : null}
                                <div
                                    className={reviewWellClass}
                                    style={{ ['--review-accent']: reviewAccent }}
                                    role="tablist"
                                    aria-label="Upload approval queue"
                                >
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={approvalQueue === 'team'}
                                        onClick={() => setApprovalQueue('team')}
                                        style={
                                            approvalQueue === 'team'
                                                ? activeReviewSegStyle(reviewAccent, onReviewAccent)
                                                : undefined
                                        }
                                        className={`${reviewSegBase} ${approvalQueue === 'team' ? 'font-semibold' : reviewSegInactive}`}
                                    >
                                        <CloudArrowUpIcon className="h-5 w-5 shrink-0 opacity-90" />
                                        <span className="whitespace-nowrap">Team uploads</span>
                                        {mergedUploadTeam > 0 && (
                                            <InsightsBadge
                                                count={mergedUploadTeam}
                                                className={badgeOnAccent(approvalQueue === 'team')}
                                            />
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={approvalQueue === 'creator'}
                                        onClick={() => setApprovalQueue('creator')}
                                        style={
                                            approvalQueue === 'creator'
                                                ? activeReviewSegStyle(reviewAccent, onReviewAccent)
                                                : undefined
                                        }
                                        className={`${reviewSegBase} ${approvalQueue === 'creator' ? 'font-semibold' : reviewSegInactive}`}
                                    >
                                        <CloudArrowUpIcon className="h-5 w-5 shrink-0 opacity-90" />
                                        <span className="whitespace-nowrap">Creator uploads</span>
                                        {mergedUploadCreator > 0 && (
                                            <InsightsBadge
                                                count={mergedUploadCreator}
                                                className={badgeOnAccent(approvalQueue === 'creator')}
                                            />
                                        )}
                                    </button>
                                </div>
                            </div>
                        ) : null}

                        {workspace === 'ai' && canViewAi ? (
                            <div className={`${reviewNestedInsetClass} ${showWorkspaceToggle ? 'mt-4' : 'mt-3'}`}>
                                {showWorkspaceToggle ? (
                                    <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                        Within AI suggestions
                                    </p>
                                ) : null}
                                <p className="mb-3 max-w-2xl text-xs leading-snug text-slate-500">
                                    Tags, categories, dropdown options, and new fields each have their own queue.
                                </p>
                                <div
                                    className={reviewWellClass}
                                    style={{ ['--review-accent']: reviewAccent }}
                                    role="tablist"
                                    aria-label="Suggestion categories"
                                >
                                    {AI_REVIEW_SUB_TABS.map(({ id, Icon, label, countKey, title, aria, hint }) => {
                                        const isActive = activeTab === id
                                        const count = mergedAiTabCounts[countKey] ?? 0
                                        return (
                                            <button
                                                key={id}
                                                type="button"
                                                role="tab"
                                                aria-selected={isActive}
                                                onClick={() => setActiveTab(id)}
                                                title={count > 0 ? title(count) : hint}
                                                aria-label={count > 0 ? aria(count) : `${label}. ${hint}`}
                                                style={isActive ? activeReviewSegStyle(reviewAccent, onReviewAccent) : undefined}
                                                className={`${reviewSegBase} ${isActive ? 'font-semibold' : reviewSegInactive}`}
                                            >
                                                <Icon className="h-5 w-5 shrink-0 opacity-90" />
                                                <span className="whitespace-nowrap">{label}</span>
                                                {count > 0 && (
                                                    <InsightsBadge count={count} className={badgeOnAccent(isActive)} />
                                                )}
                                            </button>
                                        )
                                    })}
                                </div>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {workspace === 'uploads' && canViewUploadApprovals ? (
                    <div className="space-y-4">
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">Upload approvals</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                {creatorModuleEnabled && approvalQueue === 'creator'
                                    ? 'Deliverables from your creator program pending brand review and publish.'
                                    : 'Assets from teammates pending approval (requires_approval workflow).'}
                            </p>
                        </div>
                        <UploadApprovalsPanel
                            queue={creatorModuleEnabled && approvalQueue === 'creator' ? 'creator' : 'team'}
                            onQueueChanged={() => {
                                void Promise.all([
                                    insightsCounts.reload?.() ?? Promise.resolve(),
                                    refetchReviewBadges(),
                                ])
                            }}
                        />
                    </div>
                ) : null}

                {workspace === 'ai' && canViewAi ? (
                    <>
                        <p className="max-w-3xl text-sm leading-relaxed text-slate-600">{AI_REVIEW_TAB_CONTEXT[activeTab]}</p>

                {activeTab === 'contextual' ? (
                    <ContextualNavigationReviewTab
                        canManage={canAccept || canCreateField}
                        accentColor={reviewAccent}
                        onCountsChanged={() => {
                            void refetchReviewBadges()
                        }}
                    />
                ) : loading ? (
                    <div className="rounded-lg bg-white p-8 text-center text-gray-500">Loading...</div>
                ) : items.length === 0 ? (
                    pagination.total > 0 && page > 1 ? (
                        <div className="rounded-lg bg-white p-8 text-center">
                            <SparklesIcon className="mx-auto h-12 w-12 text-gray-300" />
                            <p className="mt-2 text-gray-600">
                                No items on this page.{' '}
                                <button
                                    type="button"
                                    className="font-medium text-[color:var(--review-accent)] hover:opacity-90 hover:underline"
                                    onClick={() => setPage(1)}
                                >
                                    Go to first page
                                </button>
                            </p>
                        </div>
                    ) : activeTab === 'values' || activeTab === 'fields' ? (
                        <ReviewInsightStructuralEmptyState
                            variant={activeTab}
                            onLibraryScanQueued={() => {
                                void refetchReviewBadges()
                                setReviewRefreshNonce((n) => n + 1)
                            }}
                        />
                    ) : (
                        <div className="rounded-lg bg-white p-8 text-center">
                            <SparklesIcon className="mx-auto h-12 w-12 text-gray-300" />
                            <p className="mt-2 text-gray-500">No pending {emptyLabel()} suggestions.</p>
                        </div>
                    )
                ) : activeTab === 'tags' ? (
                    <div className="space-y-3">
                        <div className="overflow-hidden rounded-lg bg-white shadow">
                            <div className="sticky top-0 z-20 border-b border-[rgba(var(--review-accent-rgb),0.14)] bg-white/95 px-4 py-3 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/90">
                                <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">Tag suggestions</p>
                                <p className="mt-0.5 text-sm text-slate-600">
                                    The highlighted label is the tag we want to add—click a thumbnail for a larger preview and fast Accept / Reject / Skip.
                                </p>
                            </div>
                            <SectionBulkBar
                                sectionKeys={tagKeysOnPage}
                                selected={selected}
                                onToggleSelectAll={() => toggleSelectAll(tagKeysOnPage)}
                                onBulkAccept={bulkApproveKeys}
                                onBulkReject={bulkRejectKeys}
                                canAccept={canAccept}
                                canReject={canReject}
                                processing={processing}
                            />
                            <ul className="divide-y divide-gray-200">
                                {items.map((item, idx) => renderSuggestionRow(item, true, idx))}
                            </ul>
                        </div>
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : activeTab === 'categories' ? (
                    <div className="space-y-6">
                        {categorySections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.fieldKey}
                                    className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                >
                                    <div className="sticky top-0 z-20 border-b-2 border-[rgba(var(--review-accent-rgb),0.14)] bg-gradient-to-b from-white via-white to-slate-50/95 px-4 py-3 shadow-md backdrop-blur supports-[backdrop-filter]:bg-white/90">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">
                                            Metadata field group
                                        </p>
                                        <h3 className="mt-0.5 text-base font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 text-sm font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                        <p className="mt-1 text-xs text-slate-600">
                                            Each row is one asset. The large text is the value we recommend for this field—thumbnail opens quick review.
                                        </p>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-gray-200">
                                        {section.rows.map((item) =>
                                            renderSuggestionRow(
                                                item,
                                                true,
                                                items.findIndex((i) => processingKey(i) === processingKey(item))
                                            )
                                        )}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : activeTab === 'values' ? (
                    <div className="space-y-6">
                        {valueSections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.fieldKey}
                                    className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                                >
                                    <div className="sticky top-0 z-20 border-b-2 border-[rgba(var(--review-accent-rgb),0.14)] bg-gradient-to-b from-white via-white to-slate-50/95 px-4 py-3 shadow-md backdrop-blur supports-[backdrop-filter]:bg-white/90">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">
                                            Dropdown field
                                        </p>
                                        <h3 className="mt-0.5 text-base font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 text-sm font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-gray-200">
                                        {section.rows.map((item) => (
                                            <li
                                                key={processingKey(item)}
                                                className="flex flex-col gap-4 p-5 hover:bg-gray-50 sm:flex-row sm:items-start sm:justify-between"
                                            >
                                                <div className="flex min-w-0 flex-1 gap-4">
                                                    {(canAccept || canReject) && (
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.has(processingKey(item))}
                                                            onChange={() => toggleSelected(processingKey(item))}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-[color:var(--review-accent)] focus:ring-[rgba(var(--review-accent-rgb),0.45)]"
                                                        />
                                                    )}
                                                    <div className="min-w-0 space-y-1">
                                                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                                            Suggested dropdown option
                                                        </p>
                                                        <p className="text-lg font-semibold text-gray-900">
                                                            {item.suggested_value}
                                                        </p>
                                                        <p className="text-sm text-gray-600">
                                                            For field:{' '}
                                                            <span className="font-medium text-gray-900">{item.field_label}</span>
                                                            <span className="text-gray-400"> ({item.field_key})</span>
                                                        </p>
                                                        <p className="text-sm text-gray-500">
                                                            Seen on{' '}
                                                            <span className="font-medium text-gray-800">
                                                                {item.supporting_asset_count}{' '}
                                                                {item.supporting_asset_count === 1 ? 'asset' : 'assets'}
                                                            </span>
                                                            {item.confidence != null && (
                                                                <span className="ml-2">
                                                                    · pattern strength ~{Math.round(item.confidence * 100)}%
                                                                </span>
                                                            )}
                                                        </p>
                                                        {item.reason && (
                                                            <p className="mt-2 border-l-2 border-[rgba(var(--review-accent-rgb),0.22)] pl-3 text-sm text-gray-600">
                                                                {item.reason}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex flex-shrink-0 gap-2">
                                                    {canAccept && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleApprove(item)}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="inline-flex items-center gap-1 rounded-md bg-[color:var(--review-accent)] px-3 py-2 text-sm font-medium text-white hover:brightness-[0.92] disabled:opacity-50"
                                                        >
                                                            <CheckIcon className="h-4 w-4" />
                                                            Add to dropdown
                                                        </button>
                                                    )}
                                                    {canReject && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleReject(item)}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                        >
                                                            <XMarkIcon className="h-4 w-4" />
                                                            Reject
                                                        </button>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                ) : (
                    <div className="space-y-6">
                        {fieldSections.map((section) => {
                            const sectionKeys = section.rows.map((r) => processingKey(r))
                            return (
                                <div
                                    key={section.categorySlug}
                                    className="overflow-hidden rounded-lg border border-[rgba(var(--review-accent-rgb),0.14)] bg-gradient-to-br from-white to-[rgba(var(--review-accent-rgb),0.05)] shadow-sm"
                                >
                                    <div className="sticky top-0 z-20 border-b-2 border-[rgba(var(--review-accent-rgb),0.22)]/80 bg-white/95 px-4 py-3 shadow-md backdrop-blur supports-[backdrop-filter]:bg-white/90">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">
                                            Category · new field ideas
                                        </p>
                                        <h3 className="mt-0.5 text-base font-semibold text-gray-900">
                                            {section.sectionHeader}
                                            <span className="ml-2 text-sm font-normal text-gray-500">({section.rows.length})</span>
                                        </h3>
                                    </div>
                                    <SectionBulkBar
                                        sectionKeys={sectionKeys}
                                        selected={selected}
                                        onToggleSelectAll={() => toggleSelectAll(sectionKeys)}
                                        onBulkAccept={bulkApproveKeys}
                                        onBulkReject={bulkRejectKeys}
                                        canAccept={canAccept && canCreateField}
                                        canReject={canReject}
                                        processing={processing}
                                    />
                                    <ul className="divide-y divide-[rgba(var(--review-accent-rgb),0.12)]">
                                        {section.rows.map((item) => (
                                            <li key={processingKey(item)} className="p-5">
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                                                    {(canAccept || canReject) && (
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.has(processingKey(item))}
                                                            onChange={() => toggleSelected(processingKey(item))}
                                                            disabled={processing.has(processingKey(item))}
                                                            className="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-[color:var(--review-accent)] focus:ring-[rgba(var(--review-accent-rgb),0.45)]"
                                                        />
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-xs font-semibold uppercase tracking-wide text-[color:var(--review-accent)]">
                                                            Suggested new field
                                                        </p>
                                                        <p className="mt-2 text-sm text-gray-700">
                                                            Pattern centered on{' '}
                                                            <span className="font-medium text-gray-900">{item.source_cluster}</span>
                                                            {' — '}
                                                            {item.supporting_asset_count}{' '}
                                                            {item.supporting_asset_count === 1 ? 'asset' : 'assets'} showed a similar tag or metadata
                                                            pattern
                                                            {item.category_name && (
                                                                <>
                                                                    {' '}
                                                                    in <span className="font-medium">{item.category_name}</span>
                                                                </>
                                                            )}
                                                            {item.confidence != null && (
                                                                <span className="text-gray-500">
                                                                    {' '}
                                                                    · pattern strength ~{Math.round(item.confidence * 100)}%
                                                                </span>
                                                            )}
                                                        </p>
                                                        {item.reason && (
                                                            <p className="mt-2 border-l-2 border-[rgba(var(--review-accent-rgb),0.22)] pl-3 text-sm text-gray-600">
                                                                {item.reason}
                                                            </p>
                                                        )}
                                                        <p className="mt-3 text-sm text-gray-500">Proposed name (you can edit when creating)</p>
                                                        <p className="text-lg font-semibold text-gray-900">{item.field_name}</p>
                                                        <p className="text-xs text-gray-400">Internal key: {item.field_key}</p>
                                                        <div className="mt-4">
                                                            <p className="text-sm font-medium text-gray-700">Starter dropdown options</p>
                                                            <ul className="mt-2 list-inside list-disc space-y-1 text-sm text-gray-800">
                                                                {(item.suggested_options || []).map((opt) => (
                                                                    <li key={opt}>{opt}</li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                        <div className="mt-5 flex flex-wrap gap-2">
                                                            {canAccept && canCreateField && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleApprove(item)}
                                                                    disabled={processing.has(processingKey(item))}
                                                                    className="inline-flex items-center gap-1 rounded-md bg-[color:var(--review-accent)] px-3 py-2 text-sm font-medium text-white hover:brightness-[0.92] disabled:opacity-50"
                                                                >
                                                                    <CheckIcon className="h-4 w-4" />
                                                                    Create field
                                                                </button>
                                                            )}
                                                            {canAccept && !canCreateField && (
                                                                <p className="text-sm text-amber-700">
                                                                    Your role cannot create metadata fields—ask a company admin if this suggestion looks
                                                                    useful.
                                                                </p>
                                                            )}
                                                            {canReject && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleReject(item)}
                                                                    disabled={processing.has(processingKey(item))}
                                                                    className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                                >
                                                                    <XMarkIcon className="h-4 w-4" />
                                                                    Reject
                                                                </button>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )
                        })}
                        <PaginationBar pagination={pagination} loading={loading} onPageChange={setPage} />
                    </div>
                )}

                {(activeTab === 'tags' || activeTab === 'categories') && (
                    <InsightAiSuggestionReviewModal
                        open={insightPreview.open}
                        onClose={() => setInsightPreview({ open: false, index: 0 })}
                        items={items}
                        initialIndex={insightPreview.index}
                        processing={processing}
                        canAccept={canAccept}
                        canReject={canReject}
                        onApprove={handleApprove}
                        onReject={handleReject}
                        accentHex={reviewAccent}
                    />
                )}
                    </>
                ) : null}

            </div>
        </InsightsLayout>
    )
}
