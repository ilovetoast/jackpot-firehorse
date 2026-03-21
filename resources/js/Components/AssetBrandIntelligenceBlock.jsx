/**
 * Brand Intelligence (EBI) — asset drawer summary.
 * Human-readable labels only; no raw 0–100 score, weights, or compliance breakdown.
 */

import { useEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { SparklesIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'

const POLL_INTERVAL_MS = 1100
const POLL_MAX_ATTEMPTS = 45

function levelToBrandLabel(level) {
    const l = (level || '').toLowerCase()
    if (l === 'low') return 'Off Brand'
    if (l === 'medium') return 'Somewhat On Brand'
    if (l === 'high') return 'On Brand'
    if (l === 'unknown') return 'Not enough data'
    return null
}

/** Subtle headline tint by alignment level */
function levelToHeadlineColorClass(level) {
    const l = (level || '').toLowerCase()
    if (l === 'high') return 'text-emerald-700'
    if (l === 'medium') return 'text-amber-600'
    if (l === 'low') return 'text-red-600'
    if (l === 'unknown') return 'text-slate-600'
    return 'text-slate-900'
}

function confidenceToLabel(confidence) {
    if (typeof confidence !== 'number' || Number.isNaN(confidence)) return null
    if (confidence > 0.8) return 'High confidence'
    if (confidence >= 0.6) return 'Moderate confidence'
    return 'Low confidence'
}

/** Muted label tint aligned with level */
function levelToConfidenceLabelClass(level) {
    const l = (level || '').toLowerCase()
    if (l === 'high') return 'text-emerald-600/90'
    if (l === 'medium') return 'text-amber-600/90'
    if (l === 'low') return 'text-red-600/90'
    if (l === 'unknown') return 'text-slate-500'
    return 'text-slate-500'
}

/**
 * Max 2 bullets; reference-related first, then signal applicability.
 * Prefer persisted `breakdown_json.recommendations` when present.
 *
 * @param {object|null} breakdown
 * @returns {string[]}
 */
function buildInsights(breakdown) {
    if (!breakdown || typeof breakdown !== 'object') return []
    if ((breakdown.level || '').toLowerCase() === 'high') {
        return []
    }
    const persisted = breakdown.recommendations
    if (Array.isArray(persisted) && persisted.length > 0) {
        return persisted.slice(0, 2)
    }

    const out = []
    const ref = breakdown.reference_similarity

    if (ref?.used) {
        const s = ref.score
        if (typeof s === 'number' && s > 80) {
            out.push('Strong visual alignment with brand style')
        } else if (typeof s === 'number' && s < 50) {
            out.push('Visual style differs from brand references')
        }
    } else {
        out.push('No brand references available for comparison')
    }

    if (out.length < 2 && breakdown.applicability?.tone === false) {
        out.push('No text detected — tone not evaluated')
    }
    if (out.length < 2 && breakdown.applicability?.typography === false) {
        out.push('No typography detected')
    }

    return out.slice(0, 2)
}

/**
 * Poll until Brand Intelligence row exists (queue may finish after POST).
 * @param {string} assetId
 * @param {{ signal: AbortSignal }} opts
 * @returns {Promise<object|null>} brand_intelligence payload or null
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
            return data.brand_intelligence
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

export default function AssetBrandIntelligenceBlock({ asset, onAssetUpdate = null, primaryColor }) {
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const brandColorTint = brandColor.startsWith('#') ? `${brandColor}18` : `#${brandColor}18`

    const [localBi, setLocalBi] = useState(null)
    const [rescoreLoading, setRescoreLoading] = useState(false)
    const [pollTimedOut, setPollTimedOut] = useState(false)
    const [feedbackSent, setFeedbackSent] = useState(false)
    const [feedbackLoading, setFeedbackLoading] = useState(false)
    const abortRef = useRef(null)

    useEffect(() => {
        setLocalBi(null)
        setPollTimedOut(false)
        setFeedbackSent(false)
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

    const applyBrandIntelligence = (payload) => {
        if (onAssetUpdate && asset?.id) {
            onAssetUpdate({
                id: asset.id,
                brand_intelligence: payload,
            })
        } else {
            setLocalBi(payload)
        }
    }

    const handleRescore = async () => {
        if (!asset?.id || rescoreLoading) return
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
                applyBrandIntelligence(payload)
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

    if (!bi) {
        return (
            <div className="px-4 py-3 border-t border-gray-200">
                <div
                    className="rounded-md p-2.5 border"
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
                    ) : (
                        <>
                            <p className="text-sm text-slate-600">Not analyzed yet</p>
                            {canRequestEbi && (
                                <button
                                    type="button"
                                    onClick={handleRescore}
                                    disabled={rescoreLoading}
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
                </div>
            </div>
        )
    }

    const headline = levelToBrandLabel(bi.level) || 'Brand alignment'
    const headlineColor = levelToHeadlineColorClass(bi.level)
    const confLabel = confidenceToLabel(bi.confidence)
    const confLabelColor = levelToConfidenceLabelClass(bi.level)
    const refSim = breakdown?.reference_similarity
    const showConfidenceLine =
        confLabel &&
        !(typeof bi.confidence === 'number' && bi.confidence < 0.6 && refSim && refSim.used === false)
    const insights = buildInsights(breakdown)

    return (
        <div className="px-4 py-3 border-t border-gray-200">
            <div
                className="rounded-md p-2.5 border"
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
                <p className={`text-lg font-semibold leading-tight ${headlineColor}`}>{headline}</p>
            )}
            {!rescoreLoading && showConfidenceLine && (
                <p className={`mt-1 text-xs ${confLabelColor}`}>{confLabel}</p>
            )}
            {!rescoreLoading && insights.length > 0 && (
                <ul className="mt-3 space-y-1.5 text-sm text-slate-700">
                    {insights.map((line, i) => (
                        <li key={i} className="flex gap-2">
                            <span className="text-slate-400 select-none" aria-hidden>
                                •
                            </span>
                            <span>{line}</span>
                        </li>
                    ))}
                </ul>
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
            </div>
        </div>
    )
}
