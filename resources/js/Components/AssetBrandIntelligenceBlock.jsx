/**
 * Brand Intelligence (EBI) — asset drawer summary.
 * Human-readable labels only; no raw 0–100 score, weights, or compliance breakdown.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { ArrowPathIcon, SparklesIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'
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

export default function AssetBrandIntelligenceBlock({
    asset,
    onAssetUpdate = null,
    primaryColor,
    drawerInsightGroup = false,
    /** When set, called with a short status line while auto-ensure is running (drawer banner), or null when idle. */
    onActivityBannerChange = null,
}) {
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const brandColorTint = brandColor.startsWith('#') ? `${brandColor}18` : `#${brandColor}18`

    const [localBi, setLocalBi] = useState(null)
    const [rescoreLoading, setRescoreLoading] = useState(false)
    const [pollTimedOut, setPollTimedOut] = useState(false)
    const [feedbackSent, setFeedbackSent] = useState(false)
    const [feedbackLoading, setFeedbackLoading] = useState(false)
    const [autoEnsureLoading, setAutoEnsureLoading] = useState(false)
    const [analysisGateNote, setAnalysisGateNote] = useState(null)
    const abortRef = useRef(null)
    const ensureAbortRef = useRef(null)

    useEffect(() => {
        setLocalBi(null)
        setPollTimedOut(false)
        setFeedbackSent(false)
        setAutoEnsureLoading(false)
        setAnalysisGateNote(null)
    }, [asset?.id])

    useEffect(() => {
        if (asset?.brand_intelligence) {
            setLocalBi(null)
        }
    }, [asset?.brand_intelligence])

    useEffect(() => {
        return () => {
            abortRef.current?.abort()
        }
    }, [])

    const bi = localBi ?? asset?.brand_intelligence
    const breakdown = bi?.breakdown_json

    const analysisStatus = asset?.analysis_status ?? ''
    const canRequestEbi = ['complete', 'scoring'].includes(analysisStatus)

    const applyBrandIntelligence = useCallback(
        (payload, opts = {}) => {
            if (onAssetUpdate && asset?.id) {
                // Merge with current asset so parents never receive a sparse object (preserves thumbnail URLs, etc.).
                onAssetUpdate({
                    ...asset,
                    id: asset.id,
                    brand_intelligence: payload,
                    ...(opts.reference_promotion !== undefined
                        ? { reference_promotion: opts.reference_promotion }
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
        onActivityBannerChange(autoEnsureLoading ? 'Running brand analysis…' : null)
        return () => onActivityBannerChange(null)
    }, [autoEnsureLoading, onActivityBannerChange])

    /** When EBI is enabled and analysis is ready but no score exists yet, queue scoring and poll (or show a gate message). */
    useEffect(() => {
        if (!asset?.id) return
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
    }, [asset?.id, asset?.brand_intelligence, asset?.category?.ebi_enabled, asset?.analysis_status])

    const handleRescore = async () => {
        if (!asset?.id || rescoreLoading) return
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

    if (!bi) {
        return wrapCard(
            <div
                className={`rounded-md p-2.5 border ${drawerInsightGroup ? 'shadow-sm' : ''}`}
                style={{ borderColor: `${brandColor}40`, backgroundColor: brandColorTint }}
            >
                    <div className="flex items-center gap-1.5 mb-2">
                        <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                        <h3 className="text-xs font-semibold text-gray-900">Brand Intelligence</h3>
                    </div>
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
        <div
            className={`rounded-md p-2.5 border ${drawerInsightGroup ? 'shadow-sm' : ''}`}
            style={{ borderColor: `${brandColor}40`, backgroundColor: brandColorTint }}
        >
            <div className="flex items-center gap-1.5 mb-2">
                <SparklesIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                <h3 className="text-xs font-semibold text-gray-900">Brand Intelligence</h3>
            </div>
            {rescoreLoading ? (
                <p className="text-lg font-semibold text-slate-700" role="status" aria-live="polite">
                    Analyzing brand alignment…
                </p>
            ) : (
                <div className="mt-2">
                    <BrandSignalBreakdown brandIntelligence={bi} brandId={asset?.brand_id ?? asset?.brand?.id} />
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
            {pollTimedOut && !rescoreLoading && (
                <p className="mt-2 text-xs text-amber-700">Could not refresh the score yet — try Re-score in a few seconds.</p>
            )}
            {canRequestEbi && !rescoreLoading && (
                <button
                    type="button"
                    onClick={handleRescore}
                    disabled={rescoreLoading}
                    className="mt-3 text-xs font-medium hover:opacity-90 disabled:opacity-50"
                    style={{ color: brandColor }}
                >
                    Re-score
                </button>
            )}
        </div>,
    )
}
