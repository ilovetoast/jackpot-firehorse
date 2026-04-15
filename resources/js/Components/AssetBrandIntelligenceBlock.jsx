/**
 * Brand Intelligence (EBI) — asset drawer summary.
 * Human-readable labels only; no raw 0–100 score, weights, or compliance breakdown.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { ArrowPathIcon, SparklesIcon } from '@heroicons/react/24/outline'
import { Link, usePage } from '@inertiajs/react'
import BrandSignalBreakdown from './BrandSignalBreakdown'

const POLL_INTERVAL_MS = 1100
const POLL_MAX_ATTEMPTS = 45

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

    const [localBi, setLocalBi] = useState(null)
    const [rescoreLoading, setRescoreLoading] = useState(false)
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

    const rawBi = hasPublishedGuidelines ? (localBi ?? asset?.brand_intelligence) : null
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
        if (autoEnsureLoading) {
            onActivityBannerChange('Running brand analysis…')
        } else if (deepScanLoading) {
            onActivityBannerChange('Deep PDF scan in progress…')
        } else {
            onActivityBannerChange(null)
        }
        return () => onActivityBannerChange(null)
    }, [autoEnsureLoading, deepScanLoading, onActivityBannerChange])

    /** When EBI is enabled and analysis is ready but no score exists yet, queue scoring and poll (or show a gate message). */
    useEffect(() => {
        if (!asset?.id) return
        if (!hasPublishedGuidelines) return
        if (!asset?.category?.ebi_enabled) return
        if (asset.brand_intelligence) return

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
                    const polled = await pollForBrandIntelligence(asset.id, { signal: ac.signal })
                    if (cancelled || ac.signal.aborted) return
                    if (polled != null) {
                        applyBrandIntelligenceRef.current(polled.brand_intelligence, {
                            reference_promotion: polled.reference_promotion ?? data.reference_promotion,
                            pdf_brand_intelligence: polled.pdf_brand_intelligence ?? data.pdf_brand_intelligence ?? null,
                        })
                    } else {
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
    }, [asset?.id, asset?.brand_intelligence, asset?.category?.ebi_enabled, asset?.analysis_status, hasPublishedGuidelines])

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

    const handleRescore = async () => {
        if (!asset?.id || rescoreLoading || !hasPublishedGuidelines) return
        ensureAbortRef.current?.abort()
        abortRef.current?.abort()
        const controller = new AbortController()
        abortRef.current = controller

        flushSync(() => {
            setRescoreLoading(true)
            setPollTimedOut(false)
        })
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
                return
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
                setRescoreLoading(false)
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
                        <p className="text-sm text-slate-600" role="status" aria-live="polite">
                            Analyzing brand alignment…
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
                    Analyzing brand alignment…
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
                    />
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
            {canRequestEbi && !rescoreLoading && !deepScanLoading && (
                <button
                    type="button"
                    onClick={handleRescore}
                    disabled={rescoreLoading || deepScanLoading}
                    className="mt-3 text-xs font-medium hover:opacity-90 disabled:opacity-50"
                    style={{ color: brandColor }}
                >
                    Re-score
                </button>
            )}
        </div>,
    )
}
