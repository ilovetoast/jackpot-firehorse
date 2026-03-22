/**
 * Brand Intelligence — signal-level alignment visualization.
 * Accepts API payloads from brandIntelligencePayloadForFrontend() and backward-compatible shapes.
 */

import { useEffect, useMemo, useState } from 'react'
import {
    CheckCircleIcon,
    LanguageIcon,
    MinusSmallIcon,
    PhotoIcon,
    Squares2X2Icon,
    SwatchIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

const ALIGNMENT_SUBTITLES = {
    ON_BRAND: 'Strong alignment with brand',
    PARTIAL_ALIGNMENT: 'Partial alignment detected',
    OFF_BRAND: 'Diverges from brand',
    INSUFFICIENT_EVIDENCE: 'Not enough data to evaluate',
}

const RECOMMENDATIONS = {
    INSUFFICIENT_EVIDENCE: 'Add brand photography or reference assets to improve evaluation.',
    PARTIAL_ALIGNMENT: 'This asset matches some brand signals but lacks full consistency.',
    OFF_BRAND: 'This asset does not align with key brand signals.',
}

const SIGNAL_DEFS = [
    { key: 'has_logo', label: 'Logo', Icon: PhotoIcon },
    { key: 'has_brand_colors', label: 'Brand Colors', Icon: SwatchIcon },
    { key: 'has_typography', label: 'Typography', Icon: LanguageIcon },
    { key: 'has_reference_similarity', label: 'Visual Style', Icon: Squares2X2Icon },
]

function normalizeAlignmentState(raw) {
    const s = (raw || '').toString().trim().toUpperCase().replace(/-/g, '_')
    if (['ON_BRAND', 'PARTIAL_ALIGNMENT', 'OFF_BRAND', 'INSUFFICIENT_EVIDENCE'].includes(s)) {
        return s
    }
    return null
}

/** When alignment_state is absent (older rows), infer from legacy level. */
function alignmentStateFromLegacyLevel(level) {
    const l = (level || '').toString().toLowerCase()
    if (l === 'high') return 'ON_BRAND'
    if (l === 'medium') return 'PARTIAL_ALIGNMENT'
    if (l === 'low') return 'OFF_BRAND'
    if (l === 'unknown') return 'INSUFFICIENT_EVIDENCE'
    return null
}

/** Map numeric 0–1 confidence to low | moderate | high */
function numericConfidenceToBand(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return null
    if (n > 0.8) return 'high'
    if (n >= 0.5) return 'moderate'
    return 'low'
}

const CONFIDENCE_FILL = { low: 30, moderate: 60, high: 90 }

const CONFIDENCE_HELPER = {
    low: 'Limited signals available',
    moderate: 'Some signals detected',
    high: 'Strong signal agreement',
}

function normalizeConfidenceBand(bi, explicit) {
    if (explicit === 'low' || explicit === 'moderate' || explicit === 'high') {
        return explicit
    }
    return numericConfidenceToBand(bi?.confidence)
}

/**
 * Accept tier1/tier2/tier3 (spec) or system/promoted/guideline (API).
 * Tier 1 = system, Tier 2 = promoted, Tier 3 = guideline (strongest).
 */
function normalizeReferenceTierUsage(raw) {
    if (!raw || typeof raw !== 'object') return null
    const t1 = raw.tier1 ?? raw.system
    const t2 = raw.tier2 ?? raw.promoted
    const t3 = raw.tier3 ?? raw.guideline
    if ([t1, t2, t3].every((v) => v === undefined || v === null)) {
        return null
    }
    return {
        system: Number(t1 ?? 0) || 0,
        promoted: Number(t2 ?? 0) || 0,
        guideline: Number(t3 ?? 0) || 0,
    }
}

function signalStatus(value) {
    if (value === true) return 'detected'
    if (value === false) return 'not_detected'
    return 'unknown'
}

function countTruthySignals(signals) {
    if (!signals || typeof signals !== 'object') return null
    const keys = ['has_logo', 'has_brand_colors', 'has_typography', 'has_reference_similarity']
    const n = keys.filter((k) => signals[k] === true).length
    const hasAnyKey = keys.some((k) => Object.prototype.hasOwnProperty.call(signals, k))
    return hasAnyKey ? n : null
}

/**
 * @param {object} props
 * @param {object} [props.brandIntelligence] — payload from Asset::brandIntelligencePayloadForFrontend()
 * @param {object} [props.data] — optional flat override (spec shape)
 */
export default function BrandSignalBreakdown({ brandIntelligence = null, data = null }) {
    const [confBarWidth, setConfBarWidth] = useState(0)

    const normalized = useMemo(() => {
        const flat = data && typeof data === 'object' ? data : null
        const bi = flat ?? brandIntelligence
        const breakdown = bi?.breakdown_json ?? {}
        const signals =
            flat?.signals ??
            bi?.signal_breakdown ??
            breakdown?.signal_breakdown ??
            breakdown?.signals ??
            {}

        let alignment_state =
            normalizeAlignmentState(flat?.alignment_state) ??
            normalizeAlignmentState(bi?.alignment_state) ??
            normalizeAlignmentState(breakdown?.alignment_state)
        if (!alignment_state) {
            alignment_state = alignmentStateFromLegacyLevel(bi?.level ?? breakdown?.level)
        }

        const confidenceBand = normalizeConfidenceBand(bi, flat?.confidence)

        const reference_tier_usage = normalizeReferenceTierUsage(
            flat?.reference_tier_usage ?? bi?.reference_tier_usage ?? breakdown?.reference_tier_usage,
        )

        const signal_score =
            flat?.signal_score ??
            bi?.signal_count ??
            breakdown?.signal_count ??
            countTruthySignals(signals)

        return {
            alignment_state,
            confidenceBand,
            signals,
            reference_tier_usage,
            signal_score,
            breakdown,
        }
    }, [brandIntelligence, data])

    const { alignment_state, confidenceBand, signals, reference_tier_usage, signal_score, breakdown } = normalized

    const subtitle =
        (alignment_state && ALIGNMENT_SUBTITLES[alignment_state]) ||
        'Brand alignment evaluation'

    const badgeClass = useMemo(() => {
        switch (alignment_state) {
            case 'ON_BRAND':
                return 'bg-emerald-100 text-emerald-800 ring-emerald-200'
            case 'PARTIAL_ALIGNMENT':
                return 'bg-amber-100 text-amber-900 ring-amber-200'
            case 'OFF_BRAND':
                return 'bg-red-100 text-red-800 ring-red-200'
            case 'INSUFFICIENT_EVIDENCE':
                return 'bg-slate-100 text-slate-700 ring-slate-200'
            default:
                return 'bg-slate-100 text-slate-600 ring-slate-200'
        }
    }, [alignment_state])

    const fillPct = confidenceBand ? CONFIDENCE_FILL[confidenceBand] ?? 0 : 0

    useEffect(() => {
        setConfBarWidth(0)
        const id = requestAnimationFrame(() => {
            setConfBarWidth(fillPct)
        })
        return () => cancelAnimationFrame(id)
    }, [fillPct, alignment_state])

    const recommendation = useMemo(() => {
        if (!alignment_state) return null
        if (alignment_state === 'INSUFFICIENT_EVIDENCE') {
            const persisted = breakdown?.recommendations
            if (Array.isArray(persisted) && typeof persisted[0] === 'string' && persisted[0].trim() !== '') {
                return persisted[0]
            }
            return RECOMMENDATIONS.INSUFFICIENT_EVIDENCE
        }
        return RECOMMENDATIONS[alignment_state] ?? null
    }, [alignment_state, breakdown?.recommendations])

    const tierMax = reference_tier_usage
        ? Math.max(
              reference_tier_usage.guideline,
              reference_tier_usage.promoted,
              reference_tier_usage.system,
              1,
          )
        : 0

    return (
        <div className="rounded-2xl border border-slate-200/90 bg-white/90 p-4 shadow-sm shadow-slate-200/60 ring-1 ring-slate-100/80">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold tracking-tight text-slate-900">Brand Alignment</h3>
                    <p className="mt-1 text-xs leading-relaxed text-slate-600">{subtitle}</p>
                </div>
                {alignment_state && (
                    <span
                        className={`inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ${badgeClass}`}
                    >
                        {alignment_state.replace(/_/g, ' ')}
                    </span>
                )}
            </div>

            {typeof signal_score === 'number' && !Number.isNaN(signal_score) && (
                <p className="mt-3 text-xs text-slate-500">
                    Signal strength: <span className="font-medium text-slate-700">{signal_score}</span>
                    <span className="text-slate-400"> / 4</span>
                </p>
            )}

            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                {SIGNAL_DEFS.map(({ key, label, Icon }) => {
                    const st = signalStatus(signals[key])
                    return (
                        <div
                            key={key}
                            className="group flex gap-3 rounded-xl border border-slate-100 bg-slate-50/80 p-3 transition-all duration-200 hover:border-slate-200 hover:bg-white hover:shadow-md"
                        >
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-slate-500 shadow-sm ring-1 ring-slate-100 transition group-hover:text-slate-700">
                                <Icon className="h-5 w-5" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="text-xs font-semibold text-slate-800">{label}</div>
                                <div className="mt-1 flex items-center gap-1.5">
                                    {st === 'detected' && (
                                        <>
                                            <CheckCircleIcon className="h-4 w-4 shrink-0 text-emerald-500" aria-hidden />
                                            <span className="text-xs font-medium text-emerald-700">Detected</span>
                                        </>
                                    )}
                                    {st === 'not_detected' && (
                                        <>
                                            <span className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                                                <XMarkIcon className="h-3 w-3" aria-hidden />
                                            </span>
                                            <span className="text-xs font-medium text-red-700">Not Detected</span>
                                        </>
                                    )}
                                    {st === 'unknown' && (
                                        <>
                                            <MinusSmallIcon className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                                            <span className="text-xs font-medium text-slate-500">Not Available</span>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    )
                })}
            </div>

            <div className="mt-5">
                <div className="flex items-center justify-between gap-2">
                    <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Confidence</span>
                    {confidenceBand && (
                        <span className="text-[11px] font-medium capitalize text-slate-600">{confidenceBand}</span>
                    )}
                </div>
                <div className="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                        className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-[width] duration-700 ease-out"
                        style={{ width: `${confBarWidth}%` }}
                    />
                </div>
                {confidenceBand && CONFIDENCE_HELPER[confidenceBand] && (
                    <p className="mt-2 text-xs text-slate-500">{CONFIDENCE_HELPER[confidenceBand]}</p>
                )}
                {!confidenceBand && (
                    <p className="mt-2 text-xs text-slate-400">Confidence not available for this score.</p>
                )}
            </div>

            {reference_tier_usage && (
                <div className="mt-5 rounded-xl border border-slate-100 bg-slate-50/50 p-3">
                    <div className="text-xs font-semibold text-slate-800">Reference Influence</div>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                        Tier 3 (Guidelines) strongest · Tier 2 (Promoted) · Tier 1 (System)
                    </p>
                    <ul className="mt-3 space-y-2">
                        {[
                            { label: 'Guidelines', n: reference_tier_usage.guideline, bar: 'bg-emerald-500' },
                            { label: 'Promoted', n: reference_tier_usage.promoted, bar: 'bg-amber-500' },
                            { label: 'System', n: reference_tier_usage.system, bar: 'bg-slate-400' },
                        ].map(({ label, n, bar }) => (
                            <li key={label} className="flex items-center gap-2 text-xs">
                                <span className="w-20 shrink-0 text-slate-600">{label}</span>
                                <div className="min-w-0 flex-1">
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-200/90">
                                        <div
                                            className={`h-full rounded-full ${bar} transition-all duration-500`}
                                            style={{ width: `${Math.min(100, tierMax ? (n / tierMax) * 100 : 0)}%` }}
                                        />
                                    </div>
                                </div>
                                <span className="w-6 shrink-0 text-right font-mono text-slate-500">{n}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {recommendation && (
                <div className="mt-4 rounded-xl border border-slate-100 bg-slate-50/90 p-3 text-sm leading-relaxed text-slate-700 transition hover:border-slate-200 hover:bg-slate-50">
                    {recommendation}
                </div>
            )}
        </div>
    )
}
