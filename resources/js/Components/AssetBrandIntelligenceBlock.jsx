/**
 * Brand Intelligence (EBI) — asset drawer summary.
 * Human-readable labels only; no raw 0–100 score, weights, or compliance breakdown.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { ArrowPathIcon, SparklesIcon } from '@heroicons/react/24/outline'
import { Link, usePage } from '@inertiajs/react'
import BrandSignalBreakdown from './BrandSignalBreakdown'
import BrandIntelligenceDecisionTrace from './BrandIntelligenceDecisionTrace'

const POLL_INTERVAL_MS = 1100
const POLL_MAX_ATTEMPTS = 45

/**
 * Session-storage marker used to remember a rescore/deep-scan is in flight for a given asset.
 *
 * When the user clicks Re-score we delete the old BI row server-side and queue a new job; if they
 * navigate away and return before the job finishes, we want to show an "Analyzing…" state (not
 * the stale "Not analyzed yet" message, and definitely not the pre-rescore score).
 *
 * Stored value is an ISO timestamp; anything older than REANALYSIS_STALE_MS is treated as
 * expired and ignored (covers the case where a job silently failed or the tab was left open).
 */
const REANALYSIS_STORAGE_KEY = (assetId) => `ebi:rescore:${assetId}`
const REANALYSIS_STALE_MS = 10 * 60 * 1000 // 10 minutes

function markReanalysisStarted(assetId) {
    if (!assetId || typeof window === 'undefined') return
    try {
        window.sessionStorage.setItem(REANALYSIS_STORAGE_KEY(assetId), new Date().toISOString())
    } catch {
        // storage disabled / quota — non-fatal
    }
}

function clearReanalysisMarker(assetId) {
    if (!assetId || typeof window === 'undefined') return
    try {
        window.sessionStorage.removeItem(REANALYSIS_STORAGE_KEY(assetId))
    } catch {
        // ignore
    }
}

function readReanalysisMarker(assetId) {
    if (!assetId || typeof window === 'undefined') return false
    try {
        const raw = window.sessionStorage.getItem(REANALYSIS_STORAGE_KEY(assetId))
        if (!raw) return false
        const started = new Date(raw).getTime()
        if (!Number.isFinite(started)) {
            window.sessionStorage.removeItem(REANALYSIS_STORAGE_KEY(assetId))
            return false
        }
        if (Date.now() - started > REANALYSIS_STALE_MS) {
            window.sessionStorage.removeItem(REANALYSIS_STORAGE_KEY(assetId))
            return false
        }
        return true
    } catch {
        return false
    }
}

/**
 * Poll until Brand Intelligence row exists (queue may finish after POST).
 * @param {string} assetId
 * @param {{ signal: AbortSignal }} opts
 * @returns {Promise<{ brand_intelligence: object, reference_promotion?: object }|null>}
 */
async function pollForBrandIntelligence(assetId, { signal } = {}) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    for (let attempt = 0; attempt < POLL_MAX_ATTEMPTS; attempt++) {
        if (signal?.aborted) {
            return null
        }
        const res = await fetch(`/app/assets/${assetId}/brand-intelligence`, {
            headers: {
                Accept: 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            credentials: 'same-origin',
            signal,
        })
        if (!res.ok) {
            return null
        }
        const data = await res.json()
        if (data.brand_intelligence != null) {
            return {
                brand_intelligence: data.brand_intelligence,
                reference_promotion: data.reference_promotion,
                pdf_brand_intelligence: data.pdf_brand_intelligence ?? null,
            }
        }
        await new Promise((resolve) => {
            const t = setTimeout(resolve, POLL_INTERVAL_MS)
            signal?.addEventListener('abort', () => clearTimeout(t), { once: true })
        })
        if (signal?.aborted) {
            return null
        }
    }
    return null
}

/**
 * Short "time ago" for scored_at timestamps. Falls back to a readable date if old.
 * @param {string|null|undefined} iso
 */
function formatScoredAgo(iso) {
    if (!iso) return null
    const then = new Date(iso)
    if (Number.isNaN(then.getTime())) return null
    const diffMs = Date.now() - then.getTime()
    const diffMin = Math.round(diffMs / 60000)
    if (diffMin < 1) return 'just now'
    if (diffMin < 60) return `${diffMin}m ago`
    const diffH = Math.round(diffMin / 60)
    if (diffH < 24) return `${diffH}h ago`
    const diffD = Math.round(diffH / 24)
    if (diffD < 14) return `${diffD}d ago`
    try {
        return then.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
    } catch {
        return then.toISOString().slice(0, 10)
    }
}

/**
 * Preserve last BI payload in memory while the server row is cleared for a deep re-scan (avoid empty drawer flash).
 * @param {object|null|undefined} src
 */
function cloneBrandIntelPayload(src) {
    if (src == null || typeof src !== 'object') {
        return null
    }
    try {
        return typeof structuredClone === 'function' ? structuredClone(src) : JSON.parse(JSON.stringify(src))
    } catch {
        return null
    }
}

export default function AssetBrandIntelligenceBlock({
    asset,
    onAssetUpdate = null,
    primaryColor,
    drawerInsightGroup = false,
    /** When set, called with a short status line while auto-ensure is running (drawer banner), or null when idle. */
    onActivityBannerChange = null,
    /** Optional collection ID for campaign context. When present, campaign alignment is fetched. */
    collectionId = null,
}) {
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const brandColorTint = brandColor.startsWith('#') ? `${brandColor}18` : `#${brandColor}18`
    const hasPublishedGuidelines = auth?.activeBrand?.has_published_guidelines === true
    const brandRole = auth?.brand_role?.toLowerCase?.() ?? ''
    const tenantRole = auth?.tenant_role?.toLowerCase?.() ?? ''
    // Gate the admin "why did it score this way?" panel by role (brand admin/manager or tenant owner/admin).
    const canViewDecisionTrace =
        brandRole === 'admin' ||
        brandRole === 'brand_manager' ||
        tenantRole === 'owner' ||
        tenantRole === 'admin'

    const [localBi, setLocalBi] = useState(null)
    const [rescoreLoading, setRescoreLoading] = useState(false)
    const [rescoreMode, setRescoreMode] = useState(null)
    const [pollTimedOut, setPollTimedOut] = useState(false)
    const [feedbackSent, setFeedbackSent] = useState(false)
    const [feedbackLoading, setFeedbackLoading] = useState(false)
    const [autoEnsureLoading, setAutoEnsureLoading] = useState(false)
    const [deepScanLoading, setDeepScanLoading] = useState(false)
    const [deepScanBaselineBi, setDeepScanBaselineBi] = useState(null)
    const [pdfBrandIntelligenceMeta, setPdfBrandIntelligenceMeta] = useState(null)
    const [analysisGateNote, setAnalysisGateNote] = useState(null)
    const [campaignAlignment, setCampaignAlignment] = useState(null)
    const [campaignAlignmentFetchSettled, setCampaignAlignmentFetchSettled] = useState(true)
    const [ocrRerunState, setOcrRerunState] = useState({ loading: false, queued: false, error: null })
    // Persisted across component re-mounts (session-storage backed). True when a rescore/deep-scan
    // is in flight but the new score hasn't arrived yet — used to suppress stale scores on return.
    const [reanalysisInFlight, setReanalysisInFlight] = useState(() => readReanalysisMarker(asset?.id))
    const abortRef = useRef(null)
    const ensureAbortRef = useRef(null)
    const campaignAbortRef = useRef(null)

    useEffect(() => {
        setLocalBi(null)
        setPollTimedOut(false)
        setFeedbackSent(false)
        setAutoEnsureLoading(false)
        setAnalysisGateNote(null)
        setCampaignAlignment(null)
        setPdfBrandIntelligenceMeta(null)
        setDeepScanBaselineBi(null)
        setReanalysisInFlight(readReanalysisMarker(asset?.id))
    }, [asset?.id])

    useEffect(() => {
        if (!asset?.id || !collectionId) {
            setCampaignAlignment(null)
            setCampaignAlignmentFetchSettled(true)
            return
        }
        campaignAbortRef.current?.abort()
        const ac = new AbortController()
        campaignAbortRef.current = ac
        setCampaignAlignment(null)
        setCampaignAlignmentFetchSettled(false)
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        fetch(`/app/assets/${asset.id}/campaign-alignment/${collectionId}`, {
            headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
            credentials: 'same-origin',
            signal: ac.signal,
        })
            .then(async (r) => {
                if (!r.ok) return { ok: false }
                try {
                    const data = await r.json()
                    return {
                        ok: true,
                        data: data && typeof data === 'object' ? data : {},
                    }
                } catch {
                    return { ok: true, data: {} }
                }
            })
            .then((result) => {
                if (ac.signal.aborted) return
                if (result.ok) {
                    setCampaignAlignment(result.data)
                }
            })
            .catch(() => {
                if (!ac.signal.aborted) setCampaignAlignment(null)
            })
            .finally(() => {
                if (!ac.signal.aborted) setCampaignAlignmentFetchSettled(true)
            })
        return () => ac.abort()
    }, [asset?.id, collectionId])

    useEffect(() => {
        if (asset?.brand_intelligence) {
            setLocalBi(null)
        }
    }, [asset?.brand_intelligence])

    useEffect(() => {
        setPdfBrandIntelligenceMeta(asset?.pdf_brand_intelligence ?? null)
    }, [asset?.id, asset?.pdf_brand_intelligence])

    useEffect(() => {
        return () => {
            abortRef.current?.abort()
        }
    }, [])

    // While a rescore is in flight we deliberately hide the previous score so the UI doesn't
    // misrepresent a stale number as current. Deep-scan intentionally keeps the baseline visible
    // (see deepScanBaselineBi) because deep scan is an additive refinement of an existing score.
    const rawBi = hasPublishedGuidelines
        ? reanalysisInFlight
            ? null
            : (localBi ?? asset?.brand_intelligence)
        : null
    const bi = deepScanLoading && deepScanBaselineBi != null ? deepScanBaselineBi : rawBi
    const breakdown = bi?.breakdown_json
    const pdfDeep = breakdown?.pdf_deep_scan
    const pdfMulti = breakdown?.ebi_ai_trace?.pdf_multi_page
    const pdfScanModeUsed = pdfDeep?.pdf_scan_mode_used ?? pdfMulti?.pdf_scan_mode ?? breakdown?.ebi_ai_trace?.pdf_scan_mode
    const isPdfAsset = String(asset?.mime_type || '')
        .toLowerCase()
        .includes('pdf')
    const deepScanEligible =
        (pdfDeep?.deep_scan_eligible === true || pdfBrandIntelligenceMeta?.deep_scan_eligible === true) &&
        isPdfAsset
    const deepScanAlreadyUsed = pdfScanModeUsed === 'deep'
    const evaluatedPdfCount = Array.isArray(pdfMulti?.evaluated_pdf_pages) ? pdfMulti.evaluated_pdf_pages.length : null

    const analysisStatus = asset?.analysis_status ?? ''
    const canRequestEbi = ['complete', 'scoring'].includes(analysisStatus)

    const applyBrandIntelligence = useCallback(
        (payload, opts = {}) => {
            if (opts.pdf_brand_intelligence !== undefined) {
                setPdfBrandIntelligenceMeta(opts.pdf_brand_intelligence)
            }
            if (onAssetUpdate && asset?.id) {
                // Merge with current asset so parents never receive a sparse object (preserves thumbnail URLs, etc.).
                onAssetUpdate({
                    ...asset,
                    id: asset.id,
                    brand_intelligence: payload,
                    ...(opts.reference_promotion !== undefined
                        ? { reference_promotion: opts.reference_promotion }
                        : {}),
                    ...(opts.pdf_brand_intelligence !== undefined
                        ? { pdf_brand_intelligence: opts.pdf_brand_intelligence }
                        : {}),
                })
            } else {
                setLocalBi(payload)
            }
        },
        [onAssetUpdate, asset],
    )

    const applyBrandIntelligenceRef = useRef(applyBrandIntelligence)
    applyBrandIntelligenceRef.current = applyBrandIntelligence

    useEffect(() => {
        if (typeof onActivityBannerChange !== 'function') return
        if (rescoreLoading || (reanalysisInFlight && !rawBi)) {
            onActivityBannerChange('Re-analyzing brand alignment…')
        } else if (autoEnsureLoading && !rawBi) {
            onActivityBannerChange('Running brand analysis…')
        } else if (deepScanLoading && !rawBi) {
            onActivityBannerChange('Deep PDF scan in progress…')
        } else {
            onActivityBannerChange(null)
        }
        return () => onActivityBannerChange(null)
    }, [autoEnsureLoading, deepScanLoading, rawBi, onActivityBannerChange, rescoreLoading, reanalysisInFlight])

    /** When EBI is enabled and analysis is ready but no score exists yet, queue scoring and poll (or show a gate message). */
    useEffect(() => {
        if (!asset?.id) return
        if (!hasPublishedGuidelines) return
        if (!asset?.category?.ebi_enabled) return
        // Skip the ensure/poll dance when a score already exists — unless a rescore is in flight,
        // in which case the "existing" score on the asset prop is the old, pre-rescore one and we
        // need ensure/poll to pick up the new result.
        if (asset.brand_intelligence && !reanalysisInFlight) return

        const st = asset?.analysis_status ?? ''
        if (!['complete', 'scoring'].includes(st)) {
            setAnalysisGateNote('Brand analysis will start once processing finishes.')
            setAutoEnsureLoading(false)
            return
        }

        setAnalysisGateNote(null)

        let cancelled = false
        const ac = new AbortController()
        ensureAbortRef.current = ac

        ;(async () => {
            setAutoEnsureLoading(true)
            try {
                const res = await fetch(`/app/assets/${asset.id}/brand-intelligence/ensure`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: ac.signal,
                })
                const data = await res.json().catch(() => ({}))
                if (cancelled || ac.signal.aborted) return

                if (!res.ok) {
                    if (res.status === 422 && data.status === 'ebi_disabled') {
                        setAutoEnsureLoading(false)
                        return
                    }
                    setAutoEnsureLoading(false)
                    return
                }

                if (data.status === 'ready' && data.brand_intelligence) {
                    clearReanalysisMarker(asset.id)
                    setReanalysisInFlight(false)
                    applyBrandIntelligenceRef.current(data.brand_intelligence, {
                        reference_promotion: data.reference_promotion,
                        pdf_brand_intelligence: data.pdf_brand_intelligence ?? null,
                    })
                    setAutoEnsureLoading(false)
                    return
                }
                if (data.status === 'analysis_not_ready') {
                    setAnalysisGateNote('Brand analysis will start once processing finishes.')
                    setAutoEnsureLoading(false)
                    return
                }
                if (data.status === 'queued') {
                    // A scoring job is queued (either a fresh one or a rescore we kicked off on a
                    // previous drawer visit). Reflect that in the in-flight state so the UI shows
                    // "Analyzing…" instead of a stale score until the new one lands.
                    markReanalysisStarted(asset.id)
                    setReanalysisInFlight(true)
                    const polled = await pollForBrandIntelligence(asset.id, { signal: ac.signal })
                    if (cancelled || ac.signal.aborted) return
                    if (polled != null) {
                        clearReanalysisMarker(asset.id)
                        setReanalysisInFlight(false)
                        applyBrandIntelligenceRef.current(polled.brand_intelligence, {
                            reference_promotion: polled.reference_promotion ?? data.reference_promotion,
                            pdf_brand_intelligence: polled.pdf_brand_intelligence ?? data.pdf_brand_intelligence ?? null,
                        })
                    } else {
                        // Keep the marker set — job is still running; next drawer open will resume polling.
                        setPollTimedOut(true)
                    }
                    setAutoEnsureLoading(false)
                    return
                }
                setAutoEnsureLoading(false)
            } catch {
                if (!cancelled && !ac.signal.aborted) {
                    setAutoEnsureLoading(false)
                }
            }
        })()

        return () => {
            cancelled = true
            ac.abort()
            if (ensureAbortRef.current === ac) {
                ensureAbortRef.current = null
            }
        }
    }, [asset?.id, asset?.brand_intelligence, asset?.category?.ebi_enabled, asset?.analysis_status, hasPublishedGuidelines, reanalysisInFlight])

    const handleDeepPdfScan = async () => {
        if (!asset?.id || deepScanLoading || rescoreLoading || !hasPublishedGuidelines) return
        ensureAbortRef.current?.abort()
        abortRef.current?.abort()
        const controller = new AbortController()
        abortRef.current = controller
        flushSync(() => {
            setDeepScanLoading(true)
            setPollTimedOut(false)
        })
        try {
            const res = await fetch(`/app/assets/${asset.id}/brand-intelligence/deep-scan`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
            if (!res.ok) {
                return
            }
            const baseline = cloneBrandIntelPayload(localBi ?? asset?.brand_intelligence)
            if (baseline != null) {
                setDeepScanBaselineBi(baseline)
            }
            const payload = await pollForBrandIntelligence(asset.id, { signal: controller.signal })
            if (controller.signal.aborted) {
                return
            }
            if (payload != null) {
                applyBrandIntelligence(payload.brand_intelligence, {
                    reference_promotion: payload.reference_promotion,
                    pdf_brand_intelligence: payload.pdf_brand_intelligence ?? null,
                })
            } else {
                setPollTimedOut(true)
            }
        } finally {
            if (!controller.signal.aborted) {
                setDeepScanLoading(false)
            }
            setDeepScanBaselineBi(null)
        }
    }

    const handleOcrRerun = async () => {
        if (!asset?.id || ocrRerunState.loading) return
        setOcrRerunState({ loading: true, queued: false, error: null })
        try {
            const res = await fetch(`/app/assets/${asset.id}/ocr/rerun`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            if (!res.ok) {
                let message = 'Could not queue OCR'
                try {
                    const data = await res.json()
                    if (data?.message) message = data.message
                } catch {}
                setOcrRerunState({ loading: false, queued: false, error: message })
                return
            }
            setOcrRerunState({ loading: false, queued: true, error: null })
        } catch (err) {
            setOcrRerunState({
                loading: false,
                queued: false,
                error: err?.message || 'Could not queue OCR',
            })
        }
    }

    const handleRescore = async () => {
        if (!asset?.id || rescoreLoading || !hasPublishedGuidelines) return
        ensureAbortRef.current?.abort()
        abortRef.current?.abort()
        const controller = new AbortController()
        abortRef.current = controller

        // Drop the stale score immediately — both the local copy and the parent's cached asset
        // so the drawer stops showing a pre-rescore score the moment the user clicks Re-score.
        // Mark reanalysis as in-flight so that a refresh / drawer re-open during scoring also
        // suppresses the old score and renders the loading state (backed by sessionStorage).
        markReanalysisStarted(asset.id)
        flushSync(() => {
            setLocalBi(null)
            setReanalysisInFlight(true)
            setRescoreLoading(true)
            setRescoreMode(null)
            setPollTimedOut(false)
        })
        if (onAssetUpdate) {
            onAssetUpdate({ ...asset, id: asset.id, brand_intelligence: null })
        }

        try {
            const res = await fetch(`/app/assets/${asset.id}/rescore`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
            if (!res.ok) {
                // Rescore was rejected (permissions, validation, etc.) — clear the in-flight marker
                // so the user isn't left with a permanent "Analyzing…" state.
                clearReanalysisMarker(asset.id)
                setReanalysisInFlight(false)
                return
            }

            try {
                const body = await res.clone().json()
                if (body?.mode) setRescoreMode(body.mode)
            } catch {
                // Response body already consumed or not JSON; ignore.
            }

            const payload = await pollForBrandIntelligence(asset.id, { signal: controller.signal })
            if (controller.signal.aborted) {
                return
            }
            if (payload != null) {
                clearReanalysisMarker(asset.id)
                setReanalysisInFlight(false)
                applyBrandIntelligence(payload.brand_intelligence, {
                    reference_promotion: payload.reference_promotion,
                    pdf_brand_intelligence: payload.pdf_brand_intelligence ?? null,
                })
            } else {
                // Polling timed out but the job is still running in the background. Keep the
                // in-flight marker so subsequent drawer opens continue to show "Analyzing…" until
                // the new score lands (the auto-ensure effect will resume polling on remount).
                setPollTimedOut(true)
            }
        } finally {
            if (!controller.signal.aborted) {
                setRescoreLoading(false)
                setRescoreMode(null)
            }
        }
    }

    const sendInsightFeedback = async (rating) => {
        if (!asset?.id || feedbackSent || feedbackLoading) return
        setFeedbackLoading(true)
        try {
            const res = await fetch(`/app/assets/${asset.id}/brand-intelligence/feedback`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ rating }),
            })
            if (res.ok) {
                setFeedbackSent(true)
            }
        } finally {
            setFeedbackLoading(false)
        }
    }

    // Hide entire block when category has Brand Intelligence disabled (guard after hooks — Rules of Hooks)
    const category = asset?.category
    if (!category?.ebi_enabled) {
        return null
    }

    const wrapCard = (card) =>
        drawerInsightGroup ? (
            card
        ) : (
            <div className="px-4 py-3 border-t border-gray-200">{card}</div>
        )

    /** Drawer: section title is CollapsibleSection; no purple/brand tint shell (aligns with Metadata Review). */
    const insightShellClass = drawerInsightGroup
        ? 'space-y-3'
        : `rounded-md p-2.5 border shadow-sm`
    const insightShellStyle = drawerInsightGroup
        ? undefined
        : { borderColor: `${brandColor}40`, backgroundColor: brandColorTint }

    if (!hasPublishedGuidelines) {
        return wrapCard(
            <div
                className={`rounded-md border border-amber-200/90 bg-amber-50/95 p-3 ${!drawerInsightGroup ? 'shadow-sm' : ''}`}
            >
                {!drawerInsightGroup && (
                    <div className="flex items-center gap-1.5 mb-2">
                        <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0 text-amber-800" aria-hidden />
                        <h3 className="text-xs font-semibold text-amber-950">Brand Intelligence</h3>
                    </div>
                )}
                <p className="text-sm text-amber-950/90">
                    Scoring is available only after brand guidelines are <strong>published</strong>. We don&apos;t run
                    Brand Intelligence until then.
                </p>
                <p className="mt-2 text-sm text-amber-900/90">
                    Complete your guidelines workflow (research → review → build), publish a version, then alignment
                    scoring can start for assets in categories where Brand Intelligence is enabled.
                </p>
                <Link
                    href="/app/brand-guidelines"
                    className="mt-3 inline-block text-sm font-semibold text-amber-950 underline decoration-amber-700/60 underline-offset-2 hover:text-amber-900"
                >
                    Open Brand Guidelines
                </Link>
            </div>,
        )
    }

    if (!bi) {
        return wrapCard(
            <div className={insightShellClass} style={insightShellStyle}>
                    {!drawerInsightGroup && (
                        <div className="flex items-center gap-1.5 mb-2">
                            <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                            <h3 className="text-xs font-semibold text-gray-900">Brand Intelligence</h3>
                        </div>
                    )}
                    {deepScanLoading && (
                        <p
                            className="mb-2 text-xs font-medium text-sky-950/90 bg-sky-50 border border-sky-200/90 rounded px-2 py-1.5"
                            role="status"
                            aria-live="polite"
                        >
                            Deep PDF scan in progress — results will refresh when the queue finishes.
                        </p>
                    )}
                    {rescoreLoading ? (
                        <p className="text-sm text-slate-600 flex items-center gap-2" role="status" aria-live="polite">
                            <ArrowPathIcon className="h-4 w-4 flex-shrink-0 animate-spin" aria-hidden />
                            Re-analyzing brand alignment…
                        </p>
                    ) : reanalysisInFlight ? (
                        <p className="text-sm text-slate-600 flex items-center gap-2" role="status" aria-live="polite">
                            <ArrowPathIcon className="h-4 w-4 flex-shrink-0 animate-spin" aria-hidden />
                            Re-analysis in progress — the new score will appear here automatically.
                        </p>
                    ) : autoEnsureLoading ? (
                        <p
                            className="text-sm text-slate-600 flex items-center gap-2"
                            role="status"
                            aria-live="polite"
                        >
                            <ArrowPathIcon className="h-4 w-4 flex-shrink-0 animate-spin" aria-hidden />
                            Running brand analysis…
                        </p>
                    ) : analysisGateNote ? (
                        <p className="text-sm text-amber-800">{analysisGateNote}</p>
                    ) : (
                        <>
                            <p className="text-sm text-slate-600">Not analyzed yet</p>
                            {canRequestEbi && (
                                <button
                                    type="button"
                                    onClick={handleRescore}
                                    disabled={rescoreLoading || autoEnsureLoading}
                                    className="mt-2 inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50"
                                    style={{ backgroundColor: brandColor, ['--tw-ring-color']: brandColor }}
                                >
                                    Analyze now
                                </button>
                            )}
                            {pollTimedOut && (
                                <p className="mt-2 text-xs text-amber-700">
                                    Scoring is still processing. Try again in a moment or use Re-score after it finishes.
                                </p>
                            )}
                        </>
                    )}
            </div>,
        )
    }

    return wrapCard(
        <div className={insightShellClass} style={insightShellStyle}>
            {!drawerInsightGroup && (
                <div className="flex items-center gap-1.5 mb-2">
                    <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                    <h3 className="text-xs font-semibold text-gray-900">Brand Intelligence</h3>
                </div>
            )}
            {rescoreLoading ? (
                <p className="text-lg font-semibold text-slate-700" role="status" aria-live="polite">
                    {rescoreMode === 'pdf_render_then_score'
                        ? 'Rendering PDF pages, then running deep brand scan…'
                        : rescoreMode === 'full_pipeline'
                            ? 'Re-running full analysis pipeline…'
                            : 'Analyzing brand alignment…'}
                </p>
            ) : (
                <div className="mt-2">
                    {deepScanLoading && (
                        <p
                            className="mb-2 text-xs font-medium text-sky-950/90 bg-sky-50 border border-sky-200/90 rounded px-2 py-1.5 flex items-center gap-2"
                            role="status"
                            aria-live="polite"
                        >
                            <ArrowPathIcon className="h-3.5 w-3.5 flex-shrink-0 animate-spin" aria-hidden />
                            Deep PDF scan in progress — your last score stays visible until the new pass finishes.
                        </p>
                    )}
                    <BrandSignalBreakdown
                        brandIntelligence={bi}
                        brandId={asset?.brand_id ?? asset?.brand?.id}
                        asset={asset}
                        campaignAlignment={campaignAlignment}
                        campaignAlignmentFetchSettled={campaignAlignmentFetchSettled}
                        collectionId={collectionId}
                        isRefreshing={autoEnsureLoading || deepScanLoading || rescoreLoading}
                    />
                    {canViewDecisionTrace && breakdown && (
                        <BrandIntelligenceDecisionTrace
                            breakdown={breakdown}
                            scoredAt={bi?.scored_at ?? null}
                            engineVersion={bi?.engine_version ?? null}
                        />
                    )}
                </div>
            )}
            {!rescoreLoading &&
                !deepScanLoading &&
                isPdfAsset &&
                pdfScanModeUsed &&
                evaluatedPdfCount != null &&
                evaluatedPdfCount > 0 && (
                    <p className="mt-2 text-xs text-slate-600">
                        {deepScanAlreadyUsed
                            ? `Deep PDF scan analyzed ${evaluatedPdfCount} page${evaluatedPdfCount === 1 ? '' : 's'}.`
                            : `Standard PDF scan analyzed ${evaluatedPdfCount} page${evaluatedPdfCount === 1 ? '' : 's'}.`}
                    </p>
                )}
            {!rescoreLoading &&
                !deepScanLoading &&
                pdfDeep?.deep_scan_recommended === true &&
                typeof pdfDeep?.deep_scan_recommendation_reason === 'string' && (
                    <p className="mt-2 text-xs text-amber-900/90 bg-amber-50/90 border border-amber-200/80 rounded px-2 py-1.5">
                        {pdfDeep.deep_scan_recommendation_reason}
                    </p>
                )}
            {!rescoreLoading &&
                !deepScanLoading &&
                breakdown?.recommend_ocr_rerun === true && (
                    <div className="mt-2 text-xs text-sky-950/90 bg-sky-50 border border-sky-200/90 rounded px-2 py-1.5 flex items-center justify-between gap-2">
                        <span>
                            {ocrRerunState.queued
                                ? 'OCR queued. Results will populate shortly.'
                                : ocrRerunState.error
                                    ? `OCR could not start: ${ocrRerunState.error}`
                                    : 'Little readable text found on this asset. Running OCR may uncover copy, voice, and identity signals.'}
                        </span>
                        {!ocrRerunState.queued && (
                            <button
                                type="button"
                                onClick={handleOcrRerun}
                                disabled={ocrRerunState.loading}
                                className="text-xs font-medium text-sky-900 underline-offset-2 hover:underline disabled:opacity-50"
                            >
                                {ocrRerunState.loading ? 'Queuing…' : 'Run OCR'}
                            </button>
                        )}
                    </div>
                )}
            {!rescoreLoading &&
                breakdown?.ai_insight?.text &&
                breakdown.ai_used === true && (
                    <div className="mt-3 text-sm text-slate-700">
                        <div>
                            <span aria-hidden>💡 </span>
                            <span className="font-medium text-slate-600">Suggestion</span>
                        </div>
                        <p className="mt-1 text-slate-800">{breakdown.ai_insight.text}</p>
                        {typeof breakdown.ai_insight.confidence === 'number' &&
                            breakdown.ai_insight.confidence < 0.7 && (
                                <p className="mt-1 text-xs text-slate-500">Low confidence</p>
                            )}
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <span className="text-xs text-slate-500">Was this helpful?</span>
                            <button
                                type="button"
                                disabled={feedbackSent || feedbackLoading}
                                onClick={() => sendInsightFeedback('up')}
                                className="text-xs font-medium text-slate-700 hover:text-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                👍 Helpful
                            </button>
                            <button
                                type="button"
                                disabled={feedbackSent || feedbackLoading}
                                onClick={() => sendInsightFeedback('down')}
                                className="text-xs font-medium text-slate-700 hover:text-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                👎 Not helpful
                            </button>
                            {feedbackSent && (
                                <span className="text-xs text-emerald-700" role="status">
                                    Thanks for the feedback.
                                </span>
                            )}
                        </div>
                    </div>
                )}
            {pollTimedOut && !rescoreLoading && !deepScanLoading && (
                <p className="mt-2 text-xs text-amber-700">Could not refresh the score yet — try Re-score in a few seconds.</p>
            )}
            {canRequestEbi && deepScanEligible && !deepScanAlreadyUsed && !rescoreLoading && !deepScanLoading && (
                <button
                    type="button"
                    onClick={handleDeepPdfScan}
                    disabled={deepScanLoading || rescoreLoading}
                    className="mt-3 mr-4 inline-flex items-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50"
                >
                    Run deep PDF scan
                </button>
            )}
            {!rescoreLoading && !deepScanLoading && bi && (() => {
                const scoredAgo = formatScoredAgo(bi?.scored_at)
                const engine = bi?.engine_version
                const scanMode = bi?.pdf_scan_mode
                const categoryName = asset?.category?.name
                const parts = []
                if (scoredAgo) parts.push(`Scored ${scoredAgo}`)
                if (categoryName) parts.push(categoryName)
                if (isPdfAsset && scanMode) parts.push(`${scanMode === 'deep' ? 'Deep' : 'Standard'} PDF scan`)
                if (engine) parts.push(`engine ${engine}`)
                if (parts.length === 0) return null
                return (
                    <p className="mt-3 text-[11px] text-slate-500">
                        {parts.join(' · ')}
                    </p>
                )
            })()}
            {canRequestEbi && !rescoreLoading && !deepScanLoading && (
                <button
                    type="button"
                    onClick={handleRescore}
                    disabled={rescoreLoading || deepScanLoading}
                    className="mt-2 text-xs font-medium hover:opacity-90 disabled:opacity-50"
                    style={{ color: brandColor }}
                    title={isPdfAsset && deepScanEligible ? 'Runs a deep multi-page PDF scan against your current category' : 'Runs all available checks against your current category'}
                >
                    Re-score
                </button>
            )}
        </div>,
    )
}
