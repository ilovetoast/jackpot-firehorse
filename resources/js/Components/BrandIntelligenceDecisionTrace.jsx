/**
 * Admin-only "Why did it score this way?" panel for Brand Intelligence.
 *
 * Renders a per-pillar trace (status, reason code, evidence list, blockers) and
 * the deriver's signal-family coverage + confidence dampeners. Includes a
 * "Copy JSON" action so support can paste the full breakdown into a ticket.
 *
 * Visibility is gated by role (brand admin / brand manager / tenant admin/owner).
 * Data comes from breakdown_json which is already shipped to the client for the
 * main breakdown UI -- this component just reorganizes it for triage.
 */

import { useState } from 'react'
import { ChevronDownIcon, ChevronUpIcon, ClipboardDocumentIcon } from '@heroicons/react/24/outline'

const DIMENSION_ORDER = ['identity', 'color', 'typography', 'visual_style', 'copy_voice', 'context_fit']

const DIMENSION_LABELS = {
    identity: 'Identity',
    color: 'Color',
    typography: 'Typography',
    visual_style: 'Visual Style',
    copy_voice: 'Copy & Voice',
    context_fit: 'Context Fit',
}

const STATUS_COLORS = {
    aligned: 'bg-emerald-100 text-emerald-900',
    partial: 'bg-amber-100 text-amber-900',
    weak: 'bg-orange-100 text-orange-900',
    fail: 'bg-red-100 text-red-900',
    not_evaluable: 'bg-slate-100 text-slate-700',
    missing_reference: 'bg-slate-100 text-slate-700',
}

const FAMILY_LABELS = {
    text_derived: 'Text',
    pixel_visual: 'Pixel/Visual',
    reference_similarity: 'Reference match',
    metadata_config: 'Metadata',
}

function fmtNumber(v) {
    if (typeof v !== 'number' || !Number.isFinite(v)) return '—'
    return v.toFixed(2)
}

/**
 * Short "time ago" for last-scored timestamps. Returns null for invalid input.
 * Format matches the drawer footer ("just now", "3m ago", "2h ago", "5d ago", or a short date).
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

function formatAbsolute(iso) {
    if (!iso) return null
    const then = new Date(iso)
    if (Number.isNaN(then.getTime())) return null
    try {
        return then.toLocaleString()
    } catch {
        return then.toISOString()
    }
}

function Pill({ children, className = '' }) {
    return (
        <span
            className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ${className}`}
        >
            {children}
        </span>
    )
}

function EvidenceRow({ e }) {
    const weightClass =
        e.weight === 'hard'
            ? 'bg-emerald-50 text-emerald-900 border-emerald-200'
            : e.weight === 'soft'
                ? 'bg-sky-50 text-sky-900 border-sky-200'
                : 'bg-slate-50 text-slate-700 border-slate-200'
    return (
        <li className={`rounded border px-2 py-1 text-xs ${weightClass}`}>
            <div className="flex items-center gap-1.5">
                <Pill className="bg-white/60 border border-current/20">
                    {String(e.weight || '?')}
                </Pill>
                <span className="text-[10px] text-slate-600">{e.type || 'unknown'}</span>
            </div>
            <div className="mt-0.5 text-slate-800">{e.detail || e.message || '(no detail)'}</div>
        </li>
    )
}

function DimensionCard({ dimKey, dim }) {
    const label = DIMENSION_LABELS[dimKey] || dimKey
    const statusColor = STATUS_COLORS[dim.status] || 'bg-slate-100 text-slate-700'
    const signalFamilies = Array.isArray(dim.signal_families) ? dim.signal_families : []
    return (
        <div className="rounded-md border border-slate-200 bg-white p-2.5">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-slate-800">{label}</span>
                        <Pill className={statusColor}>{dim.status || 'unknown'}</Pill>
                    </div>
                    <div className="mt-0.5 text-[11px] text-slate-500">
                        score {fmtNumber(dim.score)} · confidence {fmtNumber(dim.confidence)} · primary: {dim.primary_evidence_source || '—'}
                    </div>
                    {dim.reason_code && (
                        <div className="mt-1 text-[11px] text-slate-700">
                            <span className="font-medium">reason</span>{' '}
                            <code className="rounded bg-slate-100 px-1 py-0.5">{dim.reason_code}</code>
                        </div>
                    )}
                    {dim.status_reason && (
                        <div className="mt-1 text-[11px] text-slate-600 italic">{dim.status_reason}</div>
                    )}
                </div>
            </div>
            {signalFamilies.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {signalFamilies.map((fam) => (
                        <Pill key={fam} className="bg-slate-100 text-slate-700">
                            {FAMILY_LABELS[fam] || fam}
                        </Pill>
                    ))}
                </div>
            )}
            {Array.isArray(dim.evidence) && dim.evidence.length > 0 && (
                <div className="mt-2">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Evidence</div>
                    <ul className="mt-1 space-y-1">
                        {dim.evidence.map((e, idx) => (
                            <EvidenceRow key={idx} e={e} />
                        ))}
                    </ul>
                </div>
            )}
            {Array.isArray(dim.blockers) && dim.blockers.length > 0 && (
                <div className="mt-2">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Blockers</div>
                    <ul className="mt-1 list-disc space-y-0.5 pl-5 text-[11px] text-amber-900">
                        {dim.blockers.map((b, idx) => (
                            <li key={idx}>{b}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}

/**
 * @param {object} props
 * @param {object} props.breakdown  breakdown_json
 * @param {string|null} [props.scoredAt]  ISO timestamp of the last scoring run (bi.scored_at)
 * @param {string|null} [props.engineVersion]  Optional engine version string for context
 * @param {boolean} [props.defaultExpanded=false]
 */
export default function BrandIntelligenceDecisionTrace({
    breakdown,
    scoredAt = null,
    engineVersion = null,
    defaultExpanded = false,
}) {
    const [expanded, setExpanded] = useState(defaultExpanded)
    const [copied, setCopied] = useState(false)

    if (!breakdown || typeof breakdown !== 'object') return null
    const dimensions = breakdown.dimensions && typeof breakdown.dimensions === 'object' ? breakdown.dimensions : null
    if (!dimensions) return null

    const orderedKeys = [
        ...DIMENSION_ORDER.filter((k) => dimensions[k]),
        ...Object.keys(dimensions).filter((k) => !DIMENSION_ORDER.includes(k)),
    ]

    const families = Array.isArray(breakdown.signal_family_coverage) ? breakdown.signal_family_coverage : []
    const dampeners = Array.isArray(breakdown.confidence_dampeners) ? breakdown.confidence_dampeners : []

    const copyJson = async () => {
        try {
            await navigator.clipboard.writeText(JSON.stringify(breakdown, null, 2))
            setCopied(true)
            setTimeout(() => setCopied(false), 1500)
        } catch {
            setCopied(false)
        }
    }

    return (
        <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50/60">
            <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:text-slate-900"
                aria-expanded={expanded}
            >
                <span className="flex items-center gap-1.5">
                    Why did it score this way?
                    <span className="text-[10px] font-normal uppercase tracking-wide text-slate-400">
                        admin
                    </span>
                </span>
                {expanded ? <ChevronUpIcon className="h-4 w-4" aria-hidden /> : <ChevronDownIcon className="h-4 w-4" aria-hidden />}
            </button>
            {expanded && (
                <div className="border-t border-slate-200 bg-white px-3 py-3">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div className="text-[11px] text-slate-600">
                            <span className="font-medium">overall:</span> score{' '}
                            <code className="rounded bg-slate-100 px-1 py-0.5">{fmtNumber(breakdown.weighted_score)}</code>{' '}
                            · confidence{' '}
                            <code className="rounded bg-slate-100 px-1 py-0.5">{fmtNumber(breakdown.overall_confidence)}</code>{' '}
                            · evaluable{' '}
                            <code className="rounded bg-slate-100 px-1 py-0.5">{fmtNumber(breakdown.evaluable_proportion)}</code>
                        </div>
                        <button
                            type="button"
                            onClick={copyJson}
                            className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100"
                        >
                            <ClipboardDocumentIcon className="h-3.5 w-3.5" aria-hidden />
                            {copied ? 'Copied' : 'Copy JSON'}
                        </button>
                    </div>
                    {(scoredAt || engineVersion) && (
                        <div className="mb-2 text-[10px] text-slate-500">
                            {scoredAt && (
                                <span title={formatAbsolute(scoredAt) ?? ''}>
                                    Last ran {formatScoredAgo(scoredAt) ?? '—'}
                                </span>
                            )}
                            {scoredAt && engineVersion && <span> · </span>}
                            {engineVersion && <span>engine {engineVersion}</span>}
                        </div>
                    )}
                    {families.length > 0 && (
                        <div className="mb-2 text-[11px] text-slate-600">
                            <span className="font-medium">signal families:</span>{' '}
                            {families.map((f) => (
                                <Pill key={f} className="ml-1 bg-slate-100 text-slate-700">
                                    {FAMILY_LABELS[f] || f}
                                </Pill>
                            ))}
                        </div>
                    )}
                    {dampeners.length > 0 && (
                        <div className="mb-2 rounded border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-900">
                            <div className="font-semibold">Confidence dampeners</div>
                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
                                {dampeners.map((d, idx) => (
                                    <li key={idx}>{d}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {typeof breakdown.rating_derivation === 'string' && breakdown.rating_derivation.length > 0 && (
                        <div className="mb-2 rounded bg-slate-100 px-2 py-1 text-[11px] font-mono text-slate-700">
                            {breakdown.rating_derivation}
                        </div>
                    )}
                    <div className="flex flex-col gap-2">
                        {orderedKeys.map((key) => (
                            <DimensionCard key={key} dimKey={key} dim={dimensions[key]} />
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}
