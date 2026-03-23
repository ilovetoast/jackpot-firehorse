/**
 * Brand Intelligence — compact signal summary with progressive disclosure.
 */

import { useEffect, useMemo, useState } from 'react'
import { Link } from '@inertiajs/react'
import {
    ChevronDownIcon,
    ChevronUpIcon,
    LanguageIcon,
    MinusSmallIcon,
    PhotoIcon,
    Squares2X2Icon,
    SwatchIcon,
} from '@heroicons/react/24/outline'

const SIGNAL_KEYS = ['has_logo', 'has_brand_colors', 'has_typography', 'has_reference_similarity']

/** Short labels for inline row */
const SIGNAL_SHORT = {
    has_logo: 'Logo',
    has_brand_colors: 'Colors',
    has_typography: 'Type',
    has_reference_similarity: 'Style',
}

/** Phrases for “Missing: …” line */
const MISSING_PHRASE = {
    has_logo: 'Logo signal',
    has_brand_colors: 'Brand color match',
    has_typography: 'Typography',
    has_reference_similarity: 'Style references',
}

const SIGNAL_DEFS = [
    { key: 'has_logo', label: 'Logo', Icon: PhotoIcon },
    { key: 'has_brand_colors', label: 'Colors', Icon: SwatchIcon },
    { key: 'has_typography', label: 'Typography', Icon: LanguageIcon },
    { key: 'has_reference_similarity', label: 'Visual style', Icon: Squares2X2Icon },
]

const SIGNAL_POSITIVE_LABEL = {
    has_logo: 'Detected',
    has_brand_colors: 'On palette',
    has_typography: 'Detected',
    has_reference_similarity: 'Ready',
}

const SIGNAL_NEGATIVE_LABEL = {
    has_logo: 'Missing',
    has_brand_colors: 'No match',
    has_typography: 'Missing',
    has_reference_similarity: 'Not ready',
}

function normalizeAlignmentState(raw) {
    const s = (raw || '').toString().trim().toUpperCase().replace(/-/g, '_')
    if (['ON_BRAND', 'PARTIAL_ALIGNMENT', 'OFF_BRAND', 'INSUFFICIENT_EVIDENCE'].includes(s)) {
        return s
    }
    return null
}

function alignmentStateFromLegacyLevel(level) {
    const l = (level || '').toString().toLowerCase()
    if (l === 'high') return 'ON_BRAND'
    if (l === 'medium') return 'PARTIAL_ALIGNMENT'
    if (l === 'low') return 'OFF_BRAND'
    if (l === 'unknown') return 'INSUFFICIENT_EVIDENCE'
    return null
}

function numericConfidenceToBand(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return null
    if (n > 0.8) return 'high'
    if (n >= 0.5) return 'moderate'
    return 'low'
}

const CONFIDENCE_SEGMENTS = 10

const CONFIDENCE_FILL = { low: 0.3, moderate: 0.6, high: 0.9 }

function normalizeConfidenceBand(bi, explicit) {
    if (explicit === 'low' || explicit === 'moderate' || explicit === 'high') {
        return explicit
    }
    return numericConfidenceToBand(bi?.confidence)
}

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
    if (value === true) return 'ok'
    if (value === false) return 'bad'
    return 'unknown'
}

function countTruthySignals(signals) {
    if (!signals || typeof signals !== 'object') return null
    const n = SIGNAL_KEYS.filter((k) => signals[k] === true).length
    const hasAnyKey = SIGNAL_KEYS.some((k) => Object.prototype.hasOwnProperty.call(signals, k))
    return hasAnyKey ? n : null
}

function buildMissingSummary(signals) {
    if (!signals || typeof signals !== 'object') return null
    const missing = SIGNAL_KEYS.filter((k) => signals[k] === false).map((k) => MISSING_PHRASE[k])
    if (missing.length === 0) return null
    return `Missing: ${missing.join(', ')}`
}

function referenceSummaryLine(tier) {
    if (!tier) return null
    const parts = []
    if (tier.guideline > 0) parts.push(`${tier.guideline} guideline${tier.guideline === 1 ? '' : 's'}`)
    if (tier.promoted > 0) parts.push(`${tier.promoted} promoted`)
    if (tier.system > 0) parts.push(`${tier.system} system`)
    if (parts.length === 0) return 'References: none'
    return `References: ${parts.join(', ')}`
}

/** Overall tone for score dots: green | amber | slate */
function overallTone(alignment_state, signalScore) {
    if (alignment_state === 'ON_BRAND') return 'green'
    if (alignment_state === 'INSUFFICIENT_EVIDENCE') return 'slate'
    if (typeof signalScore === 'number' && signalScore >= 3) return 'amber'
    if (alignment_state === 'PARTIAL_ALIGNMENT') return 'amber'
    return 'amber'
}

function ScoreDots({ count, tone }) {
    const fill = {
        green: 'bg-emerald-500',
        amber: 'bg-amber-400',
        slate: 'bg-slate-400',
    }[tone] || 'bg-slate-400'
    const empty = 'bg-slate-200'
    return (
        <div className="flex items-center gap-0.5" aria-hidden>
            {[0, 1, 2, 3].map((i) => (
                <span
                    key={i}
                    className={`h-1.5 w-5 rounded-sm ${i < count ? fill : empty} transition-colors duration-300`}
                />
            ))}
        </div>
    )
}

/**
 * @param {object} props
 * @param {object} [props.brandIntelligence]
 * @param {object} [props.data]
 * @param {string|number} [props.brandId] — for “Fix issues” navigation
 */
export default function BrandSignalBreakdown({ brandIntelligence = null, data = null, brandId = null }) {
    const [expanded, setExpanded] = useState(false)
    const [refDetailOpen, setRefDetailOpen] = useState(false)
    const [confSeg, setConfSeg] = useState(0)

    const normalized = useMemo(() => {
        const flat = data && typeof data === 'object' ? data : null
        const bi = flat ?? brandIntelligence
        const breakdown = bi?.breakdown_json ?? {}
        const signals =
            flat?.signals ??
            bi?.signal_breakdown ??
            breakdown?.consumer_signal_breakdown ??
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
            confidence: typeof bi?.confidence === 'number' ? bi.confidence : null,
        }
    }, [brandIntelligence, data])

    const { alignment_state, confidenceBand, signals, reference_tier_usage, signal_score, breakdown, confidence } =
        normalized

    const missingSummary = useMemo(() => buildMissingSummary(signals), [signals])

    const tone = overallTone(alignment_state, typeof signal_score === 'number' ? signal_score : 0)

    const filledDots =
        typeof signal_score === 'number' && !Number.isNaN(signal_score) ? Math.min(4, Math.max(0, signal_score)) : 0

    const confFrac = confidenceBand ? CONFIDENCE_FILL[confidenceBand] ?? 0 : typeof confidence === 'number' ? confidence : 0
    const filledConfSegments = Math.round(confFrac * CONFIDENCE_SEGMENTS)

    useEffect(() => {
        setConfSeg(0)
        const id = requestAnimationFrame(() => setConfSeg(filledConfSegments))
        return () => cancelAnimationFrame(id)
    }, [filledConfSegments, expanded])

    const fixHref =
        brandId != null && brandId !== ''
            ? `/app/brands/${brandId}/dna`
            : null

    const recommendationShort = useMemo(() => {
        if (!alignment_state || alignment_state === 'INSUFFICIENT_EVIDENCE') {
            const persisted = breakdown?.recommendations
            if (Array.isArray(persisted) && typeof persisted[0] === 'string' && persisted[0].trim() !== '') {
                return persisted[0]
            }
            return null
        }
        if (alignment_state === 'PARTIAL_ALIGNMENT' && breakdown?.style_deviation_reason) {
            return breakdown.style_deviation_reason
        }
        return null
    }, [alignment_state, breakdown?.recommendations, breakdown?.style_deviation_reason])

    return (
        <div className="rounded-lg border border-slate-200/90 bg-white/95 shadow-sm ring-1 ring-slate-100/80">
            <div className="max-h-[100px] overflow-hidden px-2.5 pt-2 pb-1.5">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <h3 className="text-xs font-semibold text-slate-900">Brand Alignment</h3>
                            <ScoreDots count={filledDots} tone={tone} />
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-600">
                            {SIGNAL_KEYS.map((key, idx) => {
                                const st = signalStatus(signals[key])
                                const short = SIGNAL_SHORT[key]
                                const sym =
                                    st === 'ok' ? (
                                        <span className="text-emerald-600" aria-label={`${short} ok`}>
                                            ✓
                                        </span>
                                    ) : st === 'bad' ? (
                                        <span className="text-slate-500" aria-label={`${short} missing`}>
                                            ✗
                                        </span>
                                    ) : (
                                        <span className="text-slate-400" aria-label={`${short} unknown`}>
                                            —
                                        </span>
                                    )
                                return (
                                    <span key={key} className="inline-flex items-center gap-0.5 whitespace-nowrap">
                                        {idx > 0 ? <span className="text-slate-300">|</span> : null}
                                        <span className="text-slate-500">{short}</span>
                                        {sym}
                                    </span>
                                )
                            })}
                        </div>
                        {missingSummary ? (
                            <p className="mt-0.5 line-clamp-1 text-[10px] leading-snug text-slate-600">{missingSummary}</p>
                        ) : (
                            <p className="mt-0.5 line-clamp-1 text-[10px] leading-snug text-slate-500">
                                Core signals look complete.
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setExpanded((e) => !e)}
                        className="flex shrink-0 items-center gap-0.5 rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                        aria-expanded={expanded}
                        aria-label={expanded ? 'Collapse details' : 'Expand details'}
                    >
                        {expanded ? <ChevronUpIcon className="h-4 w-4" /> : <ChevronDownIcon className="h-4 w-4" />}
                    </button>
                </div>
            </div>

            <div
                className={`grid transition-[grid-template-rows] duration-300 ease-out ${
                    expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
                }`}
            >
                <div className="overflow-hidden min-h-0">
                    <div className="border-t border-slate-100 px-2.5 pb-2.5 pt-1.5 transition-opacity duration-300 ease-out">
                        <div className="grid grid-cols-2 gap-x-2 gap-y-1.5">
                            {SIGNAL_DEFS.map(({ key, label, Icon }) => {
                                const st = signalStatus(signals[key])
                                const ok = SIGNAL_POSITIVE_LABEL[key]
                                const bad = SIGNAL_NEGATIVE_LABEL[key]
                                return (
                                    <div key={key} className="flex items-start gap-1.5 rounded border border-slate-100/80 bg-slate-50/50 px-1.5 py-1">
                                        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" aria-hidden />
                                        <div className="min-w-0">
                                            <div className="text-[10px] font-medium leading-tight text-slate-700">{label}</div>
                                            <div className="mt-0.5 text-[10px] leading-tight">
                                                {st === 'ok' && <span className="text-emerald-700">{ok}</span>}
                                                {st === 'bad' && <span className="text-amber-800/90">{bad}</span>}
                                                {st === 'unknown' && (
                                                    <span className="inline-flex items-center gap-0.5 text-slate-500">
                                                        <MinusSmallIcon className="h-3 w-3" aria-hidden />
                                                        Unknown
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )
                            })}
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-x-1.5 text-[10px] text-slate-600">
                            <span className="font-medium text-slate-700">Confidence</span>
                            <span className="inline-flex items-center gap-px font-mono leading-none" aria-hidden>
                                {Array.from({ length: CONFIDENCE_SEGMENTS }).map((_, i) => (
                                    <span
                                        key={i}
                                        className={i < confSeg ? 'text-emerald-600' : 'text-slate-300'}
                                    >
                                        ▬
                                    </span>
                                ))}
                            </span>
                            {confidenceBand ? (
                                <span className="capitalize text-slate-600">{confidenceBand}</span>
                            ) : (
                                <span className="text-slate-400">—</span>
                            )}
                        </div>

                        {reference_tier_usage && (
                            <div className="mt-2">
                                <button
                                    type="button"
                                    onClick={() => setRefDetailOpen((o) => !o)}
                                    className="w-full rounded border border-transparent px-0 text-left text-[10px] text-slate-600 hover:border-slate-100 hover:bg-slate-50"
                                >
                                    <span className="font-medium text-slate-700">{referenceSummaryLine(reference_tier_usage)}</span>
                                    <span className="ml-1 text-slate-400">{refDetailOpen ? '▴' : '▾'}</span>
                                </button>
                                {refDetailOpen && (
                                    <ul className="mt-1 space-y-0.5 pl-1 text-[10px] text-slate-600">
                                        <li>Guideline: {reference_tier_usage.guideline}</li>
                                        <li>Promoted: {reference_tier_usage.promoted}</li>
                                        <li>System: {reference_tier_usage.system}</li>
                                    </ul>
                                )}
                            </div>
                        )}

                        {recommendationShort && (
                            <p className="mt-2 line-clamp-3 text-[10px] leading-snug text-slate-600">{recommendationShort}</p>
                        )}

                        {fixHref && (
                            <div className="mt-2">
                                <Link
                                    href={fixHref}
                                    className="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-800 shadow-sm hover:bg-slate-50"
                                >
                                    Fix issues
                                </Link>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}
